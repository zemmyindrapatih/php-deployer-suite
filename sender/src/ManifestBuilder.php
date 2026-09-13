<?php

namespace Deployer\Sender;

use RuntimeException;

class ManifestBuilder
{
    private GitDiffer $differ;

    public function __construct(GitDiffer $differ)
    {
        $this->differ = $differ;
    }

    /**
     * @return array{
     *   version:int, generated_at:string, from_ref:string, to_ref:string,
     *   chunk_size_hint:int,
     *   add: array<int,array{path:string,sha256:string}>,
     *   replace: array<int,array{path:string,sha256:string}>,
     *   delete: array<int,string>
     * }
     */
    public function build(string $fromRef, string $toRef, array $classifiedDiff, int $chunkSize, array $pathMap = []): array
    {
        foreach (array_merge($classifiedDiff['add'], $classifiedDiff['modify'], $classifiedDiff['delete']) as $path) {
            if (self::isUnsafePath($path)) {
                throw new RuntimeException("Refusing to build manifest: unsafe destination path '{$path}'");
            }
        }

        $add = [];
        foreach ($classifiedDiff['add'] as $path) {
            $srcPath = $pathMap[$path] ?? $path;
            $content = $this->differ->readFileAtRef($toRef, $srcPath);
            $add[] = ['path' => $path, 'sha256' => hash('sha256', $content)];
        }

        $replace = [];
        foreach ($classifiedDiff['modify'] as $path) {
            $srcPath = $pathMap[$path] ?? $path;
            $content = $this->differ->readFileAtRef($toRef, $srcPath);
            $replace[] = ['path' => $path, 'sha256' => hash('sha256', $content)];
        }

        return [
            'version' => 1,
            'generated_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'from_ref' => $fromRef,
            'to_ref' => $toRef,
            'chunk_size_hint' => $chunkSize,
            'add' => $add,
            'replace' => $replace,
            'delete' => array_values($classifiedDiff['delete']),
        ];
    }

    /**
     * Destination path must stay a relative path inside the deploy target;
     * mirrors Deployer\Receiver\Extractor::isUnsafePath so a bad --dest-prefix
     * or path mapping is caught here rather than smuggled into the zip/manifest.
     */
    private static function isUnsafePath(string $path): bool
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
