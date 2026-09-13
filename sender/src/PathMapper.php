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
}
