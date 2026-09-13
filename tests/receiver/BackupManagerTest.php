<?php

namespace Deployer\Tests\Receiver;

use Deployer\Receiver\BackupManager;
use PHPUnit\Framework\TestCase;
use ZipArchive;

final class BackupManagerTest extends TestCase
{
    use TempDirTrait;

    protected function tearDown(): void
    {
        $this->cleanupTempDirs();
    }

    public function testBacksUpExistingFilesOnly(): void
    {
        $webRoot = $this->makeTempDir();
        $backupDir = $this->makeTempDir();

        file_put_contents($webRoot . '/existing.txt', 'old-content');

        $manager = new BackupManager($backupDir, $webRoot);
        $backupPath = $manager->backup(['existing.txt'], ['also-missing.txt']);

        $this->assertNotNull($backupPath);
        $this->assertFileExists($backupPath);

        $zip = new ZipArchive();
        $zip->open($backupPath);
        $this->assertSame('old-content', $zip->getFromName('existing.txt'));
        $this->assertFalse($zip->locateName('also-missing.txt'));
        $this->assertSame(1, $zip->numFiles);
        $zip->close();
    }

    public function testReturnsNullWhenNothingExists(): void
    {
        $webRoot = $this->makeTempDir();
        $backupDir = $this->makeTempDir();

        $manager = new BackupManager($backupDir, $webRoot);
        $backupPath = $manager->backup(['nope.txt'], ['also-nope.txt']);

        $this->assertNull($backupPath);
    }

    public function testDuplicatePathsAcrossReplaceAndDeleteAreOnlyBackedUpOnce(): void
    {
        $webRoot = $this->makeTempDir();
        $backupDir = $this->makeTempDir();
        file_put_contents($webRoot . '/dup.txt', 'content');

        $manager = new BackupManager($backupDir, $webRoot);
        $backupPath = $manager->backup(['dup.txt'], ['dup.txt']);

        $zip = new ZipArchive();
        $zip->open($backupPath);
        $this->assertSame(1, $zip->numFiles);
        $zip->close();
    }
}
