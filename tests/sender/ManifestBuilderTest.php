<?php

namespace Deployer\Tests\Sender;

use Deployer\Sender\GitDiffer;
use Deployer\Sender\ManifestBuilder;
use PHPUnit\Framework\TestCase;

final class ManifestBuilderTest extends TestCase
{
    public function testBuildProducesExpectedStructureAndHashes(): void
    {
        $differ = $this->createMock(GitDiffer::class);
        $differ->method('readFileAtRef')->willReturnMap([
            ['to-ref', 'new.txt', 'new-content'],
            ['to-ref', 'changed.txt', 'changed-content'],
        ]);

        $builder = new ManifestBuilder($differ);
        $classified = [
            'add' => ['new.txt'],
            'modify' => ['changed.txt'],
            'delete' => ['gone.txt'],
        ];

        $manifest = $builder->build('from-ref', 'to-ref', $classified, 1048576);

        $this->assertSame(1, $manifest['version']);
        $this->assertSame('from-ref', $manifest['from_ref']);
        $this->assertSame('to-ref', $manifest['to_ref']);
        $this->assertSame(1048576, $manifest['chunk_size_hint']);
        $this->assertSame([
            ['path' => 'new.txt', 'sha256' => hash('sha256', 'new-content')],
        ], $manifest['add']);
        $this->assertSame([
            ['path' => 'changed.txt', 'sha256' => hash('sha256', 'changed-content')],
        ], $manifest['replace']);
        $this->assertSame(['gone.txt'], $manifest['delete']);
        $this->assertNotEmpty($manifest['generated_at']);
    }

    public function testBuildWithNoChanges(): void
    {
        $differ = $this->createMock(GitDiffer::class);
        $builder = new ManifestBuilder($differ);

        $manifest = $builder->build('a', 'b', ['add' => [], 'modify' => [], 'delete' => []], 2048);

        $this->assertSame([], $manifest['add']);
        $this->assertSame([], $manifest['replace']);
        $this->assertSame([], $manifest['delete']);
    }
}
