<?php

namespace Deployer\Tests\Receiver;

use Deployer\Receiver\Rollback;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use ZipArchive;

final class RollbackTest extends TestCase
{
    use TempDirTrait;

    protected function tearDown(): void
    {
        $this->cleanupTempDirs();
    }

    public function testRestoreOverwritesCurrentFilesWithBackupContent(): void
    {
        $webRoot = $this->makeTempDir();
        $backupDir = $this->makeTempDir();

        file_put_contents($webRoot . '/a.txt', 'new-broken-content');

        $backupPath = $backupDir . '/deploy-backup-test.zip';
        $zip = new ZipArchive();
        $zip->open($backupPath, ZipArchive::CREATE);
        $zip->addFromString('a.txt', 'old-good-content');
        $zip->close();

        $rollback = new Rollback($backupDir, $webRoot);
        $restored = $rollback->restore('deploy-backup-test.zip');

        $this->assertSame(['a.txt'], $restored);
        $this->assertSame('old-good-content', file_get_contents($webRoot . '/a.txt'));
    }

    public function testRestoreRecreatesDeletedFile(): void
    {
        $webRoot = $this->makeTempDir();
        $backupDir = $this->makeTempDir();

        $backupPath = $backupDir . '/deploy-backup-test.zip';
        $zip = new ZipArchive();
        $zip->open($backupPath, ZipArchive::CREATE);
        $zip->addFromString('deleted.txt', 'brought-back');
        $zip->close();

        $rollback = new Rollback($backupDir, $webRoot);
        $rollback->restore('deploy-backup-test.zip');

        $this->assertFileExists($webRoot . '/deleted.txt');
        $this->assertSame('brought-back', file_get_contents($webRoot . '/deleted.txt'));
    }

    public function testMissingBackupThrows(): void
    {
        $webRoot = $this->makeTempDir();
        $backupDir = $this->makeTempDir();

        $rollback = new Rollback($backupDir, $webRoot);

        $this->expectException(RuntimeException::class);
        $rollback->restore('does-not-exist.zip');
    }

    public function testBasenameIsEnforcedAgainstPathTraversal(): void
    {
        $webRoot = $this->makeTempDir();
        $backupDir = $this->makeTempDir();

        $rollback = new Rollback($backupDir, $webRoot);

        $this->expectException(RuntimeException::class);
        $rollback->restore('../../etc/passwd');
    }
}
