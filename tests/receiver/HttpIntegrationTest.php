<?php

namespace Deployer\Tests\Receiver;

use PHPUnit\Framework\TestCase;
use ZipArchive;

/**
 * Black-box HTTP tests against the actual BUILT dist/deploy-receiver.php,
 * to catch build-step/wiring bugs that unit tests against receiver/src/*
 * classes directly cannot see.
 */
final class HttpIntegrationTest extends TestCase
{
    use TempDirTrait;

    private const HOST = '127.0.0.1';

    private int $port;

    private string $webRoot;
    private $serverProcess;
    private ?int $serverPid = null;
    private string $rawToken = 'integration-test-token';
    private string $rawPassword = 'integration-test-password';

    protected function setUp(): void
    {
        $distSource = __DIR__ . '/../../receiver/dist/deploy-receiver.php';
        if (!is_file($distSource)) {
            $this->markTestSkipped('Run `php receiver/build.php` before the integration suite.');
        }

        $this->webRoot = $this->makeTempDir();

        $code = file_get_contents($distSource);
        $code = str_replace("const TOKEN_HASH = 'CHANGE_ME';", "const TOKEN_HASH = '" . hash('sha256', $this->rawToken) . "';", $code);
        $code = str_replace(
            "const PASSWORD_HASH = 'CHANGE_ME';",
            "const PASSWORD_HASH = '" . addslashes(password_hash($this->rawPassword, PASSWORD_DEFAULT)) . "';",
            $code
        );
        file_put_contents($this->webRoot . '/deploy-receiver.php', $code);

        $phpBinary = PHP_BINARY ?: 'php';
        $attempts = 0;
        $started = false;

        while (!$started && $attempts < 3) {
            $attempts++;
            $this->port = random_int(20000, 60000);
            $descriptorSpec = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
            $this->serverProcess = proc_open(
                [$phpBinary, '-S', self::HOST . ':' . $this->port, '-t', $this->webRoot],
                $descriptorSpec,
                $pipes,
                $this->webRoot,
                null,
                ['bypass_shell' => true]
            );

            $status = proc_get_status($this->serverProcess);
            $this->serverPid = $status['pid'];

            $started = $this->waitForServer(2);

            if (!$started) {
                $this->killServer();
            }
        }

        if (!$started) {
            $this->fail('PHP built-in server did not start in time after 3 attempts');
        }
    }

    protected function tearDown(): void
    {
        $this->killServer();
        $this->cleanupTempDirs();
    }

    private function killServer(): void
    {
        if ($this->serverPid !== null) {
            if (stripos(PHP_OS, 'WIN') === 0) {
                exec(sprintf('taskkill /F /T /PID %d 2>NUL', $this->serverPid));
            } else {
                exec(sprintf('kill -9 %d 2>/dev/null', $this->serverPid));
            }
            $this->serverPid = null;
        }
        if (is_resource($this->serverProcess)) {
            proc_close($this->serverProcess);
        }
    }

    private function waitForServer(int $timeoutSeconds = 5): bool
    {
        $deadline = microtime(true) + $timeoutSeconds;
        while (microtime(true) < $deadline) {
            $conn = @fsockopen(self::HOST, $this->port, $errno, $errstr, 0.2);
            if ($conn) {
                fclose($conn);
                return true;
            }
            usleep(100000);
        }
        return false;
    }

    private function url(string $action = null): string
    {
        $base = sprintf('http://%s:%d/deploy-receiver.php', self::HOST, $this->port);
        return $action ? $base . '?action=' . $action : $base;
    }

    /**
     * @return array{status:int, body:array, cookie:?string}
     */
    private function request(string $action, array $params = [], ?string $rawBody = null, ?string $cookie = null, ?string $token = null): array
    {
        $ch = curl_init($this->url($action));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HEADER, true);
        curl_setopt($ch, CURLOPT_POST, true);

        $headers = [];
        if ($token !== null) {
            $headers[] = 'X-Deploy-Token: ' . $token;
        }
        if ($cookie !== null) {
            $headers[] = 'Cookie: ' . $cookie;
        }

        if ($rawBody !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $rawBody);
            $headers[] = 'Content-Type: application/octet-stream';
            $qs = http_build_query($params);
            curl_setopt($ch, CURLOPT_URL, $this->url($action) . '&' . $qs);
        } else {
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
        }

        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);

        $headerText = substr($response, 0, $headerSize);
        $bodyText = substr($response, $headerSize);

        $newCookie = null;
        if (preg_match('/Set-Cookie:\s*([^;]+)/i', $headerText, $m)) {
            $newCookie = trim($m[1]);
        }

        return ['status' => $httpCode, 'body' => json_decode($bodyText, true) ?? [], 'cookie' => $newCookie];
    }

    public function testGetWithoutActionServesUiHtml(): void
    {
        $ch = curl_init($this->url());
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $body = curl_exec($ch);
        curl_close($ch);

        $this->assertStringContainsString('<title>Deployer</title>', $body);
    }

    public function testMissingTokenIsRejected(): void
    {
        $result = $this->request('init_upload', ['filename' => 'x.zip', 'total_size' => 1, 'chunk_size' => 1, 'total_chunks' => 1]);
        $this->assertSame(403, $result['status']);
    }

    public function testFullDeploySequenceOverHttp(): void
    {
        $zipPath = $this->makeTempDir() . '/deploy.zip';
        $zip = new ZipArchive();
        $zip->open($zipPath, ZipArchive::CREATE);
        $zip->addFromString('manifest.json', json_encode([
            'version' => 1, 'from_ref' => 'a', 'to_ref' => 'b',
            'add' => [['path' => 'hello.txt', 'sha256' => hash('sha256', 'hello-via-http')]],
            'replace' => [], 'delete' => [],
        ]));
        $zip->addFromString('files/hello.txt', 'hello-via-http');
        $zip->close();

        $bytes = file_get_contents($zipPath);
        $chunkSize = 1024;
        $chunks = str_split($bytes, $chunkSize);

        $init = $this->request('init_upload', [
            'filename' => 'deploy.zip',
            'total_size' => strlen($bytes),
            'chunk_size' => $chunkSize,
            'total_chunks' => count($chunks),
        ], null, null, $this->rawToken);
        $this->assertSame(200, $init['status']);
        $deployId = $init['body']['deploy_id'];

        foreach ($chunks as $i => $chunk) {
            $res = $this->request('upload_chunk', ['deploy_id' => $deployId, 'index' => $i], $chunk, null, $this->rawToken);
            $this->assertSame(200, $res['status']);
        }

        $finalize = $this->request('finalize_upload', ['deploy_id' => $deployId], null, null, $this->rawToken);
        $this->assertSame(200, $finalize['status']);

        $extract = $this->request('extract', ['deploy_id' => $deployId], null, null, $this->rawToken);
        $this->assertSame(200, $extract['status']);
        $this->assertSame(1, $extract['body']['total']);

        $step = $this->request('backup_and_apply_step', ['deploy_id' => $deployId, 'index' => 0], null, null, $this->rawToken);
        $this->assertSame('ok', $step['body']['status']);

        $finish = $this->request('finish', ['deploy_id' => $deployId], null, null, $this->rawToken);
        $this->assertSame(200, $finish['status']);

        $this->assertSame('hello-via-http', file_get_contents($this->webRoot . '/hello.txt'));
    }

    public function testLoginGrantsSessionBasedAccess(): void
    {
        $login = $this->request('login', ['password' => $this->rawPassword]);
        $this->assertSame(200, $login['status']);
        $cookie = $login['cookie'];
        $this->assertNotNull($cookie);

        $result = $this->request('history', [], null, $cookie);
        $this->assertSame(200, $result['status']);
    }

    public function testWrongPasswordRejected(): void
    {
        $login = $this->request('login', ['password' => 'wrong']);
        $this->assertSame(403, $login['status']);
    }
}
