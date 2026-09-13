<?php

namespace Deployer\Tests\Sender;

use Deployer\Sender\PathMapper;
use PHPUnit\Framework\TestCase;

final class PathMapperTest extends TestCase
{
    public function testStripPrefixFromAddedFiles(): void
    {
        $diff = [
            'add' => ['Backend/app.php', 'Backend/config.php'],
            'modify' => [],
            'delete' => [],
        ];

        $result = PathMapper::stripPrefix($diff, 'Backend');

        $this->assertSame(['app.php', 'config.php'], $result['add']);
        $this->assertSame([], $result['modify']);
        $this->assertSame([], $result['delete']);
        $this->assertSame(['app.php' => 'Backend/app.php', 'config.php' => 'Backend/config.php'], $result['map']);
    }

    public function testStripPrefixFromModifiedFiles(): void
    {
        $diff = [
            'add' => [],
            'modify' => ['Backend/api.php', 'Backend/controller.php'],
            'delete' => [],
        ];

        $result = PathMapper::stripPrefix($diff, 'Backend');

        $this->assertSame([], $result['add']);
        $this->assertSame(['api.php', 'controller.php'], $result['modify']);
        $this->assertSame([], $result['delete']);
        $this->assertSame(['api.php' => 'Backend/api.php', 'controller.php' => 'Backend/controller.php'], $result['map']);
    }

    public function testStripPrefixFromDeletedFiles(): void
    {
        $diff = [
            'add' => [],
            'modify' => [],
            'delete' => ['Backend/old.php', 'Backend/legacy.php'],
        ];

        $result = PathMapper::stripPrefix($diff, 'Backend');

        $this->assertSame([], $result['add']);
        $this->assertSame([], $result['modify']);
        $this->assertSame(['old.php', 'legacy.php'], $result['delete']);
    }

    public function testStripPrefixWithNesteddirectories(): void
    {
        $diff = [
            'add' => ['Backend/app/Models/User.php'],
            'modify' => ['Backend/app/Controllers/Auth.php'],
            'delete' => ['Backend/config/old.php'],
        ];

        $result = PathMapper::stripPrefix($diff, 'Backend');

        $this->assertSame(['app/Models/User.php'], $result['add']);
        $this->assertSame(['app/Controllers/Auth.php'], $result['modify']);
        $this->assertSame(['config/old.php'], $result['delete']);
        $this->assertContains('app/Models/User.php', array_keys($result['map']));
        $this->assertContains('app/Controllers/Auth.php', array_keys($result['map']));
        $this->assertContains('config/old.php', array_keys($result['map']));
    }

    public function testStripPrefixHandlesTrailingSlash(): void
    {
        $diff = [
            'add' => ['Backend/app.php'],
            'modify' => [],
            'delete' => [],
        ];

        $result = PathMapper::stripPrefix($diff, 'Backend/');

        $this->assertSame(['app.php'], $result['add']);
        $this->assertSame(['app.php' => 'Backend/app.php'], $result['map']);
    }

    public function testStripPrefixIncludesNonPrefixedPathsAsIs(): void
    {
        $diff = [
            'add' => ['Backend/app.php', 'Frontend/index.html'],
            'modify' => [],
            'delete' => [],
        ];

        $result = PathMapper::stripPrefix($diff, 'Backend');

        $this->assertSame(['app.php', 'Frontend/index.html'], $result['add']);
        $this->assertSame(['app.php' => 'Backend/app.php'], $result['map']);
    }

    public function testStripPrefixMixedAddModifyDelete(): void
    {
        $diff = [
            'add' => ['Backend/new.php'],
            'modify' => ['Backend/updated.php'],
            'delete' => ['Backend/removed.php'],
        ];

        $result = PathMapper::stripPrefix($diff, 'Backend');

        $this->assertSame(['new.php'], $result['add']);
        $this->assertSame(['updated.php'], $result['modify']);
        $this->assertSame(['removed.php'], $result['delete']);
        $this->assertContains('new.php', array_keys($result['map']));
        $this->assertContains('updated.php', array_keys($result['map']));
        $this->assertContains('removed.php', array_keys($result['map']));
    }

    public function testAddPrefixToAddedFiles(): void
    {
        $diff = [
            'add' => ['app.php', 'config.php'],
            'modify' => [],
            'delete' => [],
        ];

        $result = PathMapper::addPrefix($diff, 'api');

        $this->assertSame(['api/app.php', 'api/config.php'], $result['add']);
        $this->assertSame([], $result['modify']);
        $this->assertSame([], $result['delete']);
        $this->assertSame(['api/app.php' => 'app.php', 'api/config.php' => 'config.php'], $result['map']);
    }

    public function testAddPrefixToModifiedAndDeletedFiles(): void
    {
        $diff = [
            'add' => [],
            'modify' => ['api.php'],
            'delete' => ['old.php'],
        ];

        $result = PathMapper::addPrefix($diff, 'api');

        $this->assertSame(['api/api.php'], $result['modify']);
        $this->assertSame(['api/old.php'], $result['delete']);
    }

    public function testAddPrefixHandlesNestedDirectories(): void
    {
        $diff = [
            'add' => ['app/Models/User.php'],
            'modify' => [],
            'delete' => [],
        ];

        $result = PathMapper::addPrefix($diff, 'api');

        $this->assertSame(['api/app/Models/User.php'], $result['add']);
        $this->assertSame(['api/app/Models/User.php' => 'app/Models/User.php'], $result['map']);
    }

    public function testAddPrefixHandlesTrailingSlash(): void
    {
        $diff = [
            'add' => ['app.php'],
            'modify' => [],
            'delete' => [],
        ];

        $result = PathMapper::addPrefix($diff, 'api/');

        $this->assertSame(['api/app.php'], $result['add']);
        $this->assertSame(['api/app.php' => 'app.php'], $result['map']);
    }

    public function testAddPrefixReKeysAnExistingSourceMap(): void
    {
        $diff = [
            'add' => ['app.php'],
            'modify' => [],
            'delete' => [],
        ];
        $sourceMap = ['app.php' => 'Backend/app.php'];

        $result = PathMapper::addPrefix($diff, 'api', $sourceMap);

        $this->assertSame(['api/app.php'], $result['add']);
        $this->assertSame(['api/app.php' => 'Backend/app.php'], $result['map']);
    }
}
