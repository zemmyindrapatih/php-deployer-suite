<?php

namespace Deployer\Sender;

use RuntimeException;
use ZipArchive;

class ZipPackager
{
    private GitDiffer $differ;

    public function __construct(GitDiffer $differ)
    {
        $this->differ = $differ;
    }

    public function package(array $manifest, string $outputPath, array $pathMap = []): void
    {
        $zip = new ZipArchive();
        $result = $zip->open($outputPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        if ($result !== true) {
            throw new RuntimeException("Failed to create zip at {$outputPath} (code {$result})");
        }

        $zip->addFromString('manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        $toRef = $manifest['to_ref'];

        foreach (array_merge($manifest['add'], $manifest['replace']) as $entry) {
            $srcPath = $pathMap[$entry['path']] ?? $entry['path'];
            $content = $this->differ->readFileAtRef($toRef, $srcPath);
            $zip->addFromString('files/' . $entry['path'], $content);
        }

        $zip->close();
    }
}
