<?php

namespace Deployer\Tests\Sender;

use Deployer\Sender\GitDiffer;
use Deployer\Sender\ZipPackager;
use PHPUnit\Framework\TestCase;
use ZipArchive;

final class ZipPackagerTest extends TestCase
{
    private string $outPath;

    protected function setUp(): void
    {
        $this->outPath = sys_get_temp_dir() . '/deployer-zip-test-' . uniqid() . '.zip';
    }

    protected function tearDown(): void
    {
        if (is_file($this->outPath)) {
            unlink($this->outPath);
        }
    }

    public function testPackageContainsManifestAndFiles(): void
    {
        $differ = $this->createMock(GitDiffer::class);
        $differ->method('readFileAtRef')->willReturnMap([
            ['to-ref', 'a.txt', 'content-a'],
            ['to-ref', 'sub/b.txt', 'content-b'],
        ]);

        $manifest = [
            'to_ref' => 'to-ref',
            'add' => [['path' => 'a.txt', 'sha256' => hash('sha256', 'content-a')]],
            'replace' => [['path' => 'sub/b.txt', 'sha256' => hash('sha256', 'content-b')]],
            'delete' => ['removed.txt'],
        ];

        (new ZipPackager($differ))->package($manifest, $this->outPath);

        $this->assertFileExists($this->outPath);

        $zip = new ZipArchive();
        $zip->open($this->outPath);

        $manifestJson = $zip->getFromName('manifest.json');
        $this->assertSame($manifest, json_decode($manifestJson, true));

        $this->assertSame('content-a', $zip->getFromName('files/a.txt'));
        $this->assertSame('content-b', $zip->getFromName('files/sub/b.txt'));
        $this->assertFalse($zip->locateName('files/removed.txt'));

        $this->assertSame(3, $zip->numFiles);

        $zip->close();
    }
}
