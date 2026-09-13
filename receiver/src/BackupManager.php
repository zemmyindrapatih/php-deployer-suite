<?php

namespace Deployer\Receiver;

use RuntimeException;
use ZipArchive;

class BackupManager
{
    private string $backupDir;
    private string $webRoot;

    public function __construct(string $backupDir, string $webRoot)
    {
        $this->backupDir = rtrim($backupDir, '/\\');
        $this->webRoot = rtrim($webRoot, '/\\');
        if (!is_dir($this->backupDir)) {
            mkdir($this->backupDir, 0755, true);
        }
    }

    /**
     * Backs up any currently-existing files among $replacePaths + $deletePaths
     * into a timestamped zip. Returns the backup zip path (or null if nothing to back up).
     *
     * @param string[] $replacePaths
     * @param string[] $deletePaths
     */
    public function backup(array $replacePaths, array $deletePaths): ?string
    {
        $candidates = array_unique(array_merge($replacePaths, $deletePaths));

        foreach ($candidates as $path) {
            if (Extractor::isUnsafePath($path)) {
                throw new RuntimeException("Unsafe manifest path: {$path}");
            }
        }

        $existing = array_values(array_filter($candidates, function (string $path) {
            return is_file($this->targetPath($path));
        }));

        if (empty($existing)) {
            return null;
        }

        $backupPath = $this->backupDir . '/deploy-backup-' . date('Ymd-His') . '-' . substr(bin2hex(random_bytes(4)), 0, 8) . '.zip';

        $zip = new ZipArchive();
        $zip->open($backupPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        foreach ($existing as $path) {
            $zip->addFile($this->targetPath($path), $path);
        }
        $zip->close();

        return $backupPath;
    }

    public function targetPath(string $relativePath): string
    {
        return $this->webRoot . '/' . ltrim($relativePath, '/\\');
    }
}
