<?php

namespace Deployer\Receiver;

use RuntimeException;
use ZipArchive;

class Rollback
{
    private string $backupDir;
    private string $webRoot;

    public function __construct(string $backupDir, string $webRoot)
    {
        $this->backupDir = rtrim($backupDir, '/\\');
        $this->webRoot = rtrim($webRoot, '/\\');
    }

    /**
     * Restores every file contained in the given backup zip over the current webRoot.
     *
     * @return string[] restored relative paths
     */
    public function restore(string $backupFilename): array
    {
        $backupPath = $this->backupDir . '/' . basename($backupFilename);
        if (!is_file($backupPath)) {
            throw new RuntimeException("Backup not found: {$backupFilename}");
        }

        $zip = new ZipArchive();
        if ($zip->open($backupPath) !== true) {
            throw new RuntimeException('Failed to open backup zip');
        }

        $restored = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            $targetFile = $this->webRoot . '/' . $name;
            $targetDir = dirname($targetFile);
            if (!is_dir($targetDir)) {
                mkdir($targetDir, 0755, true);
            }
            copy('zip://' . $backupPath . '#' . $name, $targetFile);
            $restored[] = $name;
        }
        $zip->close();

        return $restored;
    }
}
