<?php

namespace Deployer\Tests\Receiver;

use Deployer\Receiver\Applier;
use PHPUnit\Framework\TestCase;

final class ApplierTest extends TestCase
{
    use TempDirTrait;

    protected function tearDown(): void
    {
        $this->cleanupTempDirs();
    }

    public function testAddCopiesFileAndVerifiesHash(): void
    {
        $webRoot = $this->makeTempDir();
        $sourceDir = $this->makeTempDir();
        mkdir($sourceDir . '/nested', 0755, true);
        file_put_contents($sourceDir . '/nested/new.txt', 'new-content');

        $applier = new Applier($webRoot);
        $result = $applier->applyEntry([
            'type' => 'add',
            'path' => 'nested/new.txt',
            'sha256' => hash('sha256', 'new-content'),
            'source_dir' => $sourceDir,
        ]);

        $this->assertSame('ok', $result['status']);
        $this->assertFileExists($webRoot . '/nested/new.txt');
        $this->assertSame('new-content', file_get_contents($webRoot . '/nested/new.txt'));
    }

    public function testReplaceOverwritesExistingFile(): void
    {
        $webRoot = $this->makeTempDir();
        $sourceDir = $this->makeTempDir();
        file_put_contents($webRoot . '/existing.txt', 'old');
        file_put_contents($sourceDir . '/existing.txt', 'new');

        $applier = new Applier($webRoot);
        $result = $applier->applyEntry([
            'type' => 'replace',
            'path' => 'existing.txt',
            'sha256' => hash('sha256', 'new'),
            'source_dir' => $sourceDir,
        ]);

        $this->assertSame('ok', $result['status']);
        $this->assertSame('new', file_get_contents($webRoot . '/existing.txt'));
    }

    public function testHashMismatchIsReportedNotThrown(): void
    {
        $webRoot = $this->makeTempDir();
        $sourceDir = $this->makeTempDir();
        file_put_contents($sourceDir . '/a.txt', 'actual-content');

        $applier = new Applier($webRoot);
        $result = $applier->applyEntry([
            'type' => 'add',
            'path' => 'a.txt',
            'sha256' => 'wrong-hash',
            'source_dir' => $sourceDir,
        ]);

        $this->assertSame('hash_mismatch', $result['status']);
        $this->assertFileExists($webRoot . '/a.txt');
    }

    public function testDeleteRemovesExistingFile(): void
    {
        $webRoot = $this->makeTempDir();
        file_put_contents($webRoot . '/gone.txt', 'bye');

        $applier = new Applier($webRoot);
        $result = $applier->applyEntry(['type' => 'delete', 'path' => 'gone.txt']);

        $this->assertSame('ok', $result['status']);
        $this->assertFileDoesNotExist($webRoot . '/gone.txt');
    }

    public function testDeleteOnMissingFileNoOpsSafely(): void
    {
        $webRoot = $this->makeTempDir();

        $applier = new Applier($webRoot);
        $result = $applier->applyEntry(['type' => 'delete', 'path' => 'never-existed.txt']);

        $this->assertSame('ok', $result['status']);
    }

    public function testUnknownTypeIsReported(): void
    {
        $webRoot = $this->makeTempDir();
        $applier = new Applier($webRoot);

        $result = $applier->applyEntry(['type' => 'bogus', 'path' => 'x.txt']);

        $this->assertSame('unknown_type', $result['status']);
    }
}
