<?php

namespace Deployer\Receiver;

use RuntimeException;
use ZipArchive;

class Extractor
{
    public function extract(string $zipPath, string $extractTo): array
    {
        if (!is_dir($extractTo)) {
            mkdir($extractTo, 0755, true);
        }

        $zip = new ZipArchive();
        $result = $zip->open($zipPath);
        if ($result !== true) {
            throw new RuntimeException("Failed to open zip (code {$result})");
        }

        $this->assertNoPathTraversal($zip);

        $zip->extractTo($extractTo);
        $zip->close();

        $manifestPath = $extractTo . '/manifest.json';
        if (!is_file($manifestPath)) {
            throw new RuntimeException('manifest.json not found in zip');
        }

        $manifest = json_decode(file_get_contents($manifestPath), true);
        if (!is_array($manifest) || json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException('manifest.json is malformed');
        }

        foreach (['add', 'replace', 'delete'] as $key) {
            if (!isset($manifest[$key]) || !is_array($manifest[$key])) {
                throw new RuntimeException("manifest.json missing '{$key}' list");
            }
        }

        return $manifest;
    }

    private function assertNoPathTraversal(ZipArchive $zip): void
    {
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if ($this->isUnsafePath($name)) {
                throw new RuntimeException("Unsafe path in zip entry: {$name}");
            }
        }
    }

    public function isUnsafePath(string $path): bool
    {
        $normalized = str_replace('\\', '/', $path);

        if ($normalized === '' || $normalized[0] === '/') {
            return true;
        }

        if (preg_match('#(^|/)\.\.(/|$)#', $normalized)) {
            return true;
        }

        if (preg_match('/^[A-Za-z]:/', $normalized)) {
            return true;
        }

        return false;
    }
}
