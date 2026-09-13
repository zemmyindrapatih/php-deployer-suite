<?php

namespace Deployer\Tests\Receiver;

trait TempDirTrait
{
    private array $tempDirs = [];

    private function makeTempDir(string $prefix = 'deployer-test-'): string
    {
        $dir = sys_get_temp_dir() . '/' . $prefix . uniqid();
        mkdir($dir, 0755, true);
        $this->tempDirs[] = $dir;
        return $dir;
    }

    protected function cleanupTempDirs(): void
    {
        foreach ($this->tempDirs as $dir) {
            $this->rrmdir($dir);
        }
        $this->tempDirs = [];
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            if (is_dir($path) && !is_link($path)) {
                $this->rrmdir($path);
            } else {
                @chmod($path, 0777);
                @unlink($path);
            }
        }
        @rmdir($dir);
    }
}
