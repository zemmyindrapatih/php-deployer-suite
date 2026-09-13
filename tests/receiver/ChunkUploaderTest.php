<?php

namespace Deployer\Tests\Receiver;

use Deployer\Receiver\ChunkUploader;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ChunkUploaderTest extends TestCase
{
    use TempDirTrait;

    protected function tearDown(): void
    {
        $this->cleanupTempDirs();
    }

    public function testChunksReassembleToOriginalBytes(): void
    {
        $baseDir = $this->makeTempDir();
        $uploader = new ChunkUploader($baseDir);

        $original = random_bytes(1000);
        $chunkSize = 300;
        $chunks = str_split($original, $chunkSize);

        $init = $uploader->initUpload('test.zip', strlen($original), $chunkSize, count($chunks));
        $deployId = $init['deploy_id'];

        foreach ($chunks as $i => $chunk) {
            $uploader->appendChunk($deployId, $i, $chunk);
        }

        $finalPath = $uploader->finalizeUpload($deployId);

        $this->assertSame(hash('sha256', $original), hash_file('sha256', $finalPath));
    }

    public function testOutOfOrderChunksStillReassembleCorrectly(): void
    {
        $baseDir = $this->makeTempDir();
        $uploader = new ChunkUploader($baseDir);

        $original = random_bytes(900);
        $chunkSize = 300;
        $chunks = str_split($original, $chunkSize);

        $init = $uploader->initUpload('test.zip', strlen($original), $chunkSize, count($chunks));
        $deployId = $init['deploy_id'];

        $uploader->appendChunk($deployId, 2, $chunks[2]);
        $uploader->appendChunk($deployId, 0, $chunks[0]);
        $uploader->appendChunk($deployId, 1, $chunks[1]);

        $finalPath = $uploader->finalizeUpload($deployId);

        $this->assertSame(hash('sha256', $original), hash_file('sha256', $finalPath));
    }

    public function testDuplicateChunkIndexOverwritesRatherThanDuplicates(): void
    {
        $baseDir = $this->makeTempDir();
        $uploader = new ChunkUploader($baseDir);

        $init = $uploader->initUpload('test.zip', 6, 3, 2);
        $deployId = $init['deploy_id'];

        $uploader->appendChunk($deployId, 0, 'AAA');
        $uploader->appendChunk($deployId, 0, 'BBB');
        $result = $uploader->appendChunk($deployId, 1, 'CCC');

        $this->assertSame(2, $result['received']);

        $finalPath = $uploader->finalizeUpload($deployId);
        $this->assertSame('BBBCCC', file_get_contents($finalPath));
    }

    public function testChunkIndexOutOfRangeThrows(): void
    {
        $baseDir = $this->makeTempDir();
        $uploader = new ChunkUploader($baseDir);

        $init = $uploader->initUpload('test.zip', 3, 3, 1);
        $deployId = $init['deploy_id'];

        $this->expectException(RuntimeException::class);
        $uploader->appendChunk($deployId, 5, 'AAA');
    }

    public function testFinalizeBeforeAllChunksReceivedThrows(): void
    {
        $baseDir = $this->makeTempDir();
        $uploader = new ChunkUploader($baseDir);

        $init = $uploader->initUpload('test.zip', 6, 3, 2);
        $deployId = $init['deploy_id'];
        $uploader->appendChunk($deployId, 0, 'AAA');

        $this->expectException(RuntimeException::class);
        $uploader->finalizeUpload($deployId);
    }

    public function testFinalizeWithSizeMismatchThrows(): void
    {
        $baseDir = $this->makeTempDir();
        $uploader = new ChunkUploader($baseDir);

        $init = $uploader->initUpload('test.zip', 999, 3, 1);
        $deployId = $init['deploy_id'];
        $uploader->appendChunk($deployId, 0, 'AAA');

        $this->expectException(RuntimeException::class);
        $uploader->finalizeUpload($deployId);
    }

    public function testUnknownDeployIdThrows(): void
    {
        $baseDir = $this->makeTempDir();
        $uploader = new ChunkUploader($baseDir);

        $this->expectException(RuntimeException::class);
        $uploader->appendChunk('does-not-exist', 0, 'data');
    }
}
