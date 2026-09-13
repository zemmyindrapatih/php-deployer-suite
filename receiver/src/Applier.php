<?php

namespace Deployer\Receiver;

use RuntimeException;

class Applier
{
    private string $webRoot;

    public function __construct(string $webRoot)
    {
        $this->webRoot = rtrim($webRoot, '/\\');
    }

    /**
     * Applies a single manifest entry.
     *
     * $entry shape: ['type' => 'add'|'replace'|'delete', 'path' => string, 'sha256' => ?string, 'source_dir' => ?string]
     *
     * @return array{path:string,type:string,status:string}
     */
    public function applyEntry(array $entry): array
    {
        $type = $entry['type'];
        $path = $entry['path'];

        if (Extractor::isUnsafePath($path)) {
            throw new RuntimeException("Unsafe manifest path: {$path}");
        }

        if ($type === 'add' || $type === 'replace') {
            $sourceFile = rtrim($entry['source_dir'], '/\\') . '/' . $path;
            $targetFile = $this->targetPath($path);

            $targetDir = dirname($targetFile);
            if (!is_dir($targetDir)) {
                mkdir($targetDir, 0755, true);
            }

            copy($sourceFile, $targetFile);

            $actualHash = hash_file('sha256', $targetFile);
            if ($actualHash !== $entry['sha256']) {
                return ['path' => $path, 'type' => $type, 'status' => 'hash_mismatch'];
            }

            return ['path' => $path, 'type' => $type, 'status' => 'ok'];
        }

        if ($type === 'delete') {
            $targetFile = $this->targetPath($path);
            if (is_file($targetFile)) {
                unlink($targetFile);
            }

            return ['path' => $path, 'type' => $type, 'status' => 'ok'];
        }

        return ['path' => $path, 'type' => $type, 'status' => 'unknown_type'];
    }

    public function targetPath(string $relativePath): string
    {
        return $this->webRoot . '/' . ltrim($relativePath, '/\\');
    }
}
