<?php

namespace Deployer\Tests\Receiver;

use Deployer\Receiver\Applier;
use Deployer\Receiver\Auth;
use Deployer\Receiver\BackupManager;
use Deployer\Receiver\ChunkUploader;
use Deployer\Receiver\DeployLog;
use Deployer\Receiver\Extractor;
use Deployer\Receiver\Rollback;
use Deployer\Receiver\Router;
use PHPUnit\Framework\TestCase;
use ZipArchive;

final class RouterTest extends TestCase
{
    use TempDirTrait;

    private string $webRoot;
    private string $workDir;
    private string $backupDir;
    private Router $router;
    private string $rawToken = 'test-token';

    protected function setUp(): void
    {
        $this->webRoot = $this->makeTempDir();
        $this->workDir = $this->makeTempDir();
        $this->backupDir = $this->makeTempDir();

        $auth = new Auth(hash('sha256', $this->rawToken), password_hash('pw', PASSWORD_DEFAULT));
        $uploader = new ChunkUploader($this->workDir);
        $extractor = new Extractor();
        $backupManager = new BackupManager($this->backupDir, $this->webRoot);
        $applier = new Applier($this->webRoot);
        $log = new DeployLog($this->workDir . '/log.json');
        $rollback = new Rollback($this->backupDir, $this->webRoot);

        $this->router = new Router($auth, $uploader, $extractor, $backupManager, $applier, $log, $rollback, $this->workDir);
    }

    protected function tearDown(): void
    {
        $this->cleanupTempDirs();
    }

    private function makeDeployZip(array $add, array $replace, array $delete, array $fileContents): string
    {
        $manifest = [
            'version' => 1,
            'from_ref' => 'a',
            'to_ref' => 'b',
            'add' => $add,
            'replace' => $replace,
            'delete' => $delete,
        ];

        $path = $this->makeTempDir() . '/deploy.zip';
        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::CREATE);
        $zip->addFromString('manifest.json', json_encode($manifest));
        foreach ($fileContents as $relPath => $content) {
            $zip->addFromString('files/' . $relPath, $content);
        }
        $zip->close();

        return $path;
    }

    private function runFullDeploy(string $zipPath): array
    {
        $session = [];
        $bytes = file_get_contents($zipPath);
        $chunkSize = 1024;
        $chunks = str_split($bytes, $chunkSize);

        $init = $this->router->dispatch('init_upload', [
            'filename' => 'deploy.zip',
            'total_size' => strlen($bytes),
            'chunk_size' => $chunkSize,
            'total_chunks' => count($chunks),
        ], $session, $this->rawToken);
        $deployId = $init['body']['deploy_id'];

        foreach ($chunks as $i => $chunk) {
            $this->router->dispatch('upload_chunk', ['deploy_id' => $deployId, 'index' => $i], $session, $this->rawToken, fn() => $chunk);
        }

        $this->router->dispatch('finalize_upload', ['deploy_id' => $deployId], $session, $this->rawToken);
        $extractResult = $this->router->dispatch('extract', ['deploy_id' => $deployId], $session, $this->rawToken);

        $total = $extractResult['body']['total'];
        $stepResults = [];
        for ($i = 0; $i < $total; $i++) {
            $stepResults[] = $this->router->dispatch('backup_and_apply_step', ['deploy_id' => $deployId, 'index' => $i], $session, $this->rawToken);
        }

        $finish = $this->router->dispatch('finish', ['deploy_id' => $deployId], $session, $this->rawToken);

        return ['deploy_id' => $deployId, 'extract' => $extractResult, 'steps' => $stepResults, 'finish' => $finish];
    }

    public function testFullDeploySequenceAddsReplacesAndDeletes(): void
    {
        file_put_contents($this->webRoot . '/old.txt', 'old-content');
        file_put_contents($this->webRoot . '/to-delete.txt', 'delete-me');

        $zipPath = $this->makeDeployZip(
            [['path' => 'new.txt', 'sha256' => hash('sha256', 'new-content')]],
            [['path' => 'old.txt', 'sha256' => hash('sha256', 'updated-content')]],
            ['to-delete.txt'],
            ['new.txt' => 'new-content', 'old.txt' => 'updated-content']
        );

        $result = $this->runFullDeploy($zipPath);

        $this->assertSame(200, $result['finish']['status']);
        $this->assertSame('new-content', file_get_contents($this->webRoot . '/new.txt'));
        $this->assertSame('updated-content', file_get_contents($this->webRoot . '/old.txt'));
        $this->assertFileDoesNotExist($this->webRoot . '/to-delete.txt');

        foreach ($result['steps'] as $step) {
            $this->assertSame('ok', $step['body']['status']);
        }
    }

    public function testBackupIsCreatedBeforeReplacingOrDeleting(): void
    {
        file_put_contents($this->webRoot . '/old.txt', 'original-content');

        $zipPath = $this->makeDeployZip(
            [],
            [['path' => 'old.txt', 'sha256' => hash('sha256', 'new-content')]],
            [],
            ['old.txt' => 'new-content']
        );

        $this->runFullDeploy($zipPath);

        $backupFiles = glob($this->backupDir . '/*.zip');
        $this->assertCount(1, $backupFiles);

        $zip = new ZipArchive();
        $zip->open($backupFiles[0]);
        $this->assertSame('original-content', $zip->getFromName('old.txt'));
        $zip->close();
    }

    public function testUnauthorizedRequestIsRejected(): void
    {
        $session = [];
        $result = $this->router->dispatch('init_upload', [
            'filename' => 'x.zip', 'total_size' => 1, 'chunk_size' => 1, 'total_chunks' => 1,
        ], $session, 'wrong-token');

        $this->assertSame(403, $result['status']);
        $this->assertFalse($result['body']['ok']);
    }

    public function testSessionLoginGrantsAccessToSubsequentActions(): void
    {
        $session = [];
        $login = $this->router->dispatch('login', ['password' => 'pw'], $session, null);
        $this->assertSame(200, $login['status']);

        $result = $this->router->dispatch('init_upload', [
            'filename' => 'x.zip', 'total_size' => 1, 'chunk_size' => 1, 'total_chunks' => 1,
        ], $session, null);

        $this->assertSame(200, $result['status']);
    }

    public function testWrongPasswordLoginIsRejected(): void
    {
        $session = [];
        $result = $this->router->dispatch('login', ['password' => 'wrong'], $session, null);

        $this->assertSame(403, $result['status']);
    }

    public function testUnknownActionReturns400(): void
    {
        $session = [];
        $result = $this->router->dispatch('bogus_action', [], $session, $this->rawToken);

        $this->assertSame(400, $result['status']);
    }

    public function testMissingFieldReturns400(): void
    {
        $session = [];
        $result = $this->router->dispatch('init_upload', ['filename' => 'x.zip'], $session, $this->rawToken);

        $this->assertSame(400, $result['status']);
    }

    public function testRollbackViaRouterRestoresFiles(): void
    {
        file_put_contents($this->webRoot . '/a.txt', 'original');

        $zipPath = $this->makeDeployZip(
            [],
            [['path' => 'a.txt', 'sha256' => hash('sha256', 'replaced')]],
            [],
            ['a.txt' => 'replaced']
        );
        $this->runFullDeploy($zipPath);
        $this->assertSame('replaced', file_get_contents($this->webRoot . '/a.txt'));

        $backupFiles = glob($this->backupDir . '/*.zip');
        $backupName = basename($backupFiles[0]);

        $session = [];
        $result = $this->router->dispatch('rollback', ['backup' => $backupName], $session, $this->rawToken);

        $this->assertSame(200, $result['status']);
        $this->assertSame('original', file_get_contents($this->webRoot . '/a.txt'));
    }

    public function testHistoryReturnsLoggedDeploys(): void
    {
        $zipPath = $this->makeDeployZip([], [], [], []);
        $this->runFullDeploy($zipPath);

        $session = [];
        $result = $this->router->dispatch('history', [], $session, $this->rawToken);

        $this->assertSame(200, $result['status']);
        $this->assertCount(1, $result['body']['entries']);
    }

    public function testHashMismatchIsReportedButDoesNotAbortDeploy(): void
    {
        $zipPath = $this->makeDeployZip(
            [['path' => 'a.txt', 'sha256' => 'deliberately-wrong-hash']],
            [],
            [],
            ['a.txt' => 'actual-content']
        );

        $result = $this->runFullDeploy($zipPath);

        $this->assertSame('hash_mismatch', $result['steps'][0]['body']['status']);
        $this->assertFileExists($this->webRoot . '/a.txt');
    }
}
