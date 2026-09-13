<?php

namespace Deployer\Tests\Receiver;

use Deployer\Receiver\Extractor;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use ZipArchive;

final class ExtractorTest extends TestCase
{
    use TempDirTrait;

    protected function tearDown(): void
    {
        $this->cleanupTempDirs();
    }

    private function makeZip(array $entries): string
    {
        $dir = $this->makeTempDir();
        $path = $dir . '/test.zip';
        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::CREATE);
        foreach ($entries as $name => $content) {
            $zip->addFromString($name, $content);
        }
        $zip->close();
        return $path;
    }

    public function testValidZipExtractsAndManifestParses(): void
    {
        $manifest = ['add' => [], 'replace' => [], 'delete' => []];
        $zipPath = $this->makeZip([
            'manifest.json' => json_encode($manifest),
            'files/a.txt' => 'hello',
        ]);
        $extractTo = $this->makeTempDir();

        $result = (new Extractor())->extract($zipPath, $extractTo);

        $this->assertSame($manifest, $result);
        $this->assertFileExists($extractTo . '/files/a.txt');
    }

    public function testMissingManifestThrows(): void
    {
        $zipPath = $this->makeZip(['files/a.txt' => 'hello']);
        $extractTo = $this->makeTempDir();

        $this->expectException(RuntimeException::class);
        (new Extractor())->extract($zipPath, $extractTo);
    }

    public function testMalformedManifestJsonThrows(): void
    {
        $zipPath = $this->makeZip(['manifest.json' => '{not valid json']);
        $extractTo = $this->makeTempDir();

        $this->expectException(RuntimeException::class);
        (new Extractor())->extract($zipPath, $extractTo);
    }

    public function testManifestMissingRequiredKeyThrows(): void
    {
        $zipPath = $this->makeZip(['manifest.json' => json_encode(['add' => []])]);
        $extractTo = $this->makeTempDir();

        $this->expectException(RuntimeException::class);
        (new Extractor())->extract($zipPath, $extractTo);
    }

    public function testPathTraversalEntryIsRejected(): void
    {
        $zipPath = $this->makeZip([
            'manifest.json' => json_encode(['add' => [], 'replace' => [], 'delete' => []]),
            '../../evil.txt' => 'pwned',
        ]);
        $extractTo = $this->makeTempDir();

        $this->expectException(RuntimeException::class);
        (new Extractor())->extract($zipPath, $extractTo);
    }

    /**
     * @dataProvider unsafePathProvider
     */
    public function testIsUnsafePathDetectsTraversal(string $path, bool $expected): void
    {
        $this->assertSame($expected, (new Extractor())->isUnsafePath($path));
    }

    public function unsafePathProvider(): array
    {
        return [
            'plain relative' => ['files/a.txt', false],
            'parent traversal' => ['../a.txt', true],
            'nested traversal' => ['files/../../a.txt', true],
            'absolute unix' => ['/etc/passwd', true],
            'absolute windows' => ['C:/Windows/system32', true],
            'empty' => ['', true],
            'dotdot as filename substring' => ['files/a..b.txt', false],
        ];
    }
}
