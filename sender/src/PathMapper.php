<?php

namespace Deployer\Sender;

class PathMapper
{
    /**
     * Strips a prefix from all paths in a diff result.
     *
     * Returns ['add' => [destPaths], 'modify' => [destPaths], 'delete' => [destPaths], 'map' => [destPath => srcPath]]
     * The 'map' is used to resolve destination paths back to their original repo paths for reading content.
     *
     * @param array $diff Result from GitDiffer::diff() with 'add', 'modify', 'delete' keys
     * @param string $prefix Prefix to strip (e.g. "Backend") — paths not starting with this are included as-is
     * @return array Classified diff with stripped paths + a 'map' for content resolution
     */
    public static function stripPrefix(array $diff, string $prefix): array
    {
        $prefix = rtrim($prefix, '/\\') . '/';
        $prefixLen = strlen($prefix);
        $map = [];

        $stripPaths = function (array $paths) use ($prefix, $prefixLen, &$map): array {
            return array_map(function (string $path) use ($prefix, $prefixLen, &$map): string {
                if (str_starts_with($path, $prefix)) {
                    $dest = substr($path, $prefixLen);
                    $map[$dest] = $path;
                    return $dest;
                }
                return $path;
            }, $paths);
        };

        return [
            'add' => $stripPaths($diff['add'] ?? []),
            'modify' => $stripPaths($diff['modify'] ?? []),
            'delete' => $stripPaths($diff['delete'] ?? []),
            'map' => $map,
        ];
    }

    /**
     * Prepends a prefix to all paths in a diff result (the inverse of stripPrefix).
     *
     * Returns ['add' => [destPaths], 'modify' => [destPaths], 'delete' => [destPaths], 'map' => [destPath => srcPath]]
     * `$sourceMap`, if given (e.g. from a prior stripPrefix() call), is re-keyed under the
     * new prefixed destination paths so content lookup still resolves to the real repo path.
     *
     * @param array $diff Result from GitDiffer::diff() (or a prior stripPrefix()) with 'add', 'modify', 'delete' keys
     * @param string $prefix Folder to prepend (e.g. "api")
     * @param array $sourceMap Optional existing destPath => srcPath map to re-key under the new prefix
     * @return array Diff with prefixed paths + a 'map' for content resolution
     */
    public static function addPrefix(array $diff, string $prefix, array $sourceMap = []): array
    {
        $prefix = rtrim($prefix, '/\\') . '/';
        $map = [];

        $addPrefixToPaths = function (array $paths) use ($prefix, $sourceMap, &$map): array {
            return array_map(function (string $path) use ($prefix, $sourceMap, &$map): string {
                $dest = $prefix . $path;
                $map[$dest] = $sourceMap[$path] ?? $path;
                return $dest;
            }, $paths);
        };

        return [
            'add' => $addPrefixToPaths($diff['add'] ?? []),
            'modify' => $addPrefixToPaths($diff['modify'] ?? []),
            'delete' => $addPrefixToPaths($diff['delete'] ?? []),
            'map' => $map,
        ];
    }
}
