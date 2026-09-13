<?php

namespace Deployer\Receiver;

use RuntimeException;

class ChunkUploader
{
    private string $baseDir;

    public function __construct(string $baseDir)
    {
        $this->baseDir = rtrim($baseDir, '/\\');
        if (!is_dir($this->baseDir)) {
            mkdir($this->baseDir, 0755, true);
        }
    }

    public function initUpload(string $filename, int $totalSize, int $chunkSize, int $totalChunks): array
    {
        $deployId = bin2hex(random_bytes(16));
        $dir = $this->deployDir($deployId);
        mkdir($dir, 0755, true);

        file_put_contents($this->metaPath($deployId), json_encode([
            'filename' => $filename,
            'total_size' => $totalSize,
            'chunk_size' => $chunkSize,
            'total_chunks' => $totalChunks,
            'received_chunks' => [],
        ]));

        return ['deploy_id' => $deployId];
    }

    public function appendChunk(string $deployId, int $index, string $data): array
    {
        $meta = $this->readMeta($deployId);

        if ($index < 0 || $index >= $meta['total_chunks']) {
            throw new RuntimeException("Chunk index {$index} out of range");
        }

        $partPath = $this->deployDir($deployId) . "/chunk_{$index}.part";
        file_put_contents($partPath, $data);

        if (!in_array($index, $meta['received_chunks'], true)) {
            $meta['received_chunks'][] = $index;
        }
        $this->writeMeta($deployId, $meta);

        return ['received' => count($meta['received_chunks']), 'total' => $meta['total_chunks']];
    }

    public function finalizeUpload(string $deployId): string
    {
        $meta = $this->readMeta($deployId);

        if (count($meta['received_chunks']) !== $meta['total_chunks']) {
            throw new RuntimeException('Not all chunks received');
        }

        $finalPath = $this->deployDir($deployId) . '/upload.zip';
        $out = fopen($finalPath, 'wb');

        for ($i = 0; $i < $meta['total_chunks']; $i++) {
            $partPath = $this->deployDir($deployId) . "/chunk_{$i}.part";
            $in = fopen($partPath, 'rb');
            stream_copy_to_stream($in, $out);
            fclose($in);
        }
        fclose($out);

        $actualSize = filesize($finalPath);
        if ($actualSize !== $meta['total_size']) {
            throw new RuntimeException("Size mismatch: expected {$meta['total_size']}, got {$actualSize}");
        }

        return $finalPath;
    }

    public function deployDir(string $deployId): string
    {
        return $this->baseDir . '/' . $deployId;
    }

    private function metaPath(string $deployId): string
    {
        return $this->deployDir($deployId) . '/meta.json';
    }

    private function readMeta(string $deployId): array
    {
        $path = $this->metaPath($deployId);
        if (!is_file($path)) {
            throw new RuntimeException("Unknown deploy_id: {$deployId}");
        }

        return json_decode(file_get_contents($path), true);
    }

    private function writeMeta(string $deployId, array $meta): void
    {
        file_put_contents($this->metaPath($deployId), json_encode($meta));
    }
}
