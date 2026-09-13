<?php

namespace Deployer\Tests\Receiver;

use Deployer\Receiver\DeployLog;
use PHPUnit\Framework\TestCase;

final class DeployLogTest extends TestCase
{
    use TempDirTrait;

    protected function tearDown(): void
    {
        $this->cleanupTempDirs();
    }

    public function testReadAllOnMissingFileReturnsEmptyArray(): void
    {
        $dir = $this->makeTempDir();
        $log = new DeployLog($dir . '/log.json');

        $this->assertSame([], $log->readAll());
    }

    public function testAppendIsAdditiveAndPreservesOrder(): void
    {
        $dir = $this->makeTempDir();
        $log = new DeployLog($dir . '/log.json');

        $log->append(['deploy_id' => 'a']);
        $log->append(['deploy_id' => 'b']);
        $log->append(['deploy_id' => 'c']);

        $entries = $log->readAll();

        $this->assertCount(3, $entries);
        $this->assertSame(['a', 'b', 'c'], array_column($entries, 'deploy_id'));
    }

    public function testAppendAddsTimestampWhenMissing(): void
    {
        $dir = $this->makeTempDir();
        $log = new DeployLog($dir . '/log.json');

        $log->append(['deploy_id' => 'a']);

        $entries = $log->readAll();
        $this->assertArrayHasKey('timestamp', $entries[0]);
        $this->assertNotEmpty($entries[0]['timestamp']);
    }
}
