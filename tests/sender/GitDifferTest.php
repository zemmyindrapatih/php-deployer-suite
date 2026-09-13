<?php

namespace Deployer\Tests\Sender;

use Deployer\Sender\GitDiffer;
use PHPUnit\Framework\TestCase;

final class GitDifferTest extends TestCase
{
    private string $repoDir;

    protected function setUp(): void
    {
        $this->repoDir = sys_get_temp_dir() . '/deployer-test-' . uniqid();
        mkdir($this->repoDir, 0755, true);
        $this->git(['init', '-q']);
        $this->git(['config', 'user.email', 'test@example.com']);
        $this->git(['config', 'user.name', 'Test']);
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->repoDir);
    }

    public function testAddedFileIsClassifiedAsAdd(): void
    {
        $this->commitFile('a.txt', 'hello');
        $from = $this->currentRef();
        $this->commitFile('b.txt', 'world');
        $to = $this->currentRef();

        $diff = (new GitDiffer($this->repoDir))->diff($from, $to);

        $this->assertSame(['b.txt'], $diff['add']);
        $this->assertSame([], $diff['modify']);
        $this->assertSame([], $diff['delete']);
    }

    public function testModifiedFileIsClassifiedAsModify(): void
    {
        $this->commitFile('a.txt', 'hello');
        $from = $this->currentRef();
        $this->commitFile('a.txt', 'hello world');
        $to = $this->currentRef();

        $diff = (new GitDiffer($this->repoDir))->diff($from, $to);

        $this->assertSame([], $diff['add']);
        $this->assertSame(['a.txt'], $diff['modify']);
        $this->assertSame([], $diff['delete']);
    }

    public function testDeletedFileIsClassifiedAsDelete(): void
    {
        $this->commitFile('a.txt', 'hello');
        $from = $this->currentRef();
        unlink($this->repoDir . '/a.txt');
        $this->git(['add', '-A']);
        $this->git(['commit', '-q', '-m', 'delete a']);
        $to = $this->currentRef();

        $diff = (new GitDiffer($this->repoDir))->diff($from, $to);

        $this->assertSame(['a.txt'], $diff['delete']);
    }

    public function testRenamedFileIsClassifiedAsDeleteAndAdd(): void
    {
        $this->commitFile('old.txt', str_repeat('content ', 50));
        $from = $this->currentRef();
        rename($this->repoDir . '/old.txt', $this->repoDir . '/new.txt');
        $this->git(['add', '-A']);
        $this->git(['commit', '-q', '-m', 'rename']);
        $to = $this->currentRef();

        $diff = (new GitDiffer($this->repoDir))->diff($from, $to);

        $this->assertSame(['old.txt'], $diff['delete']);
        $this->assertSame(['new.txt'], $diff['add']);
    }

    public function testEmptyDiffWhenRefsAreEqual(): void
    {
        $this->commitFile('a.txt', 'hello');
        $ref = $this->currentRef();

        $diff = (new GitDiffer($this->repoDir))->diff($ref, $ref);

        $this->assertSame(['add' => [], 'modify' => [], 'delete' => []], $diff);
    }

    public function testBinaryFileChangeIsClassifiedAsModify(): void
    {
        file_put_contents($this->repoDir . '/bin.dat', "\x00\x01\x02binary");
        $this->git(['add', '-A']);
        $this->git(['commit', '-q', '-m', 'add binary']);
        $from = $this->currentRef();

        file_put_contents($this->repoDir . '/bin.dat', "\x00\x01\x02binary-changed");
        $this->git(['add', '-A']);
        $this->git(['commit', '-q', '-m', 'change binary']);
        $to = $this->currentRef();

        $diff = (new GitDiffer($this->repoDir))->diff($from, $to);

        $this->assertSame(['bin.dat'], $diff['modify']);
    }

    public function testReadFileAtRefReturnsExactContent(): void
    {
        $this->commitFile('a.txt', 'exact-content-123');
        $ref = $this->currentRef();

        $content = (new GitDiffer($this->repoDir))->readFileAtRef($ref, 'a.txt');

        $this->assertSame('exact-content-123', $content);
    }

    public function testPathspecFiltersChangesToSubdirectory(): void
    {
        mkdir($this->repoDir . '/Backend');
        mkdir($this->repoDir . '/Frontend');
        $this->commitFile('Backend/app.php', 'backend');
        $this->commitFile('Frontend/index.html', 'frontend');
        $from = $this->currentRef();

        $this->commitFile('Backend/app.php', 'backend-v2');
        $this->commitFile('Frontend/index.html', 'frontend-v2');
        $to = $this->currentRef();

        $diff = (new GitDiffer($this->repoDir))->diff($from, $to, 'Backend');

        $this->assertSame(['Backend/app.php'], $diff['modify']);
        $this->assertSame([], $diff['add']);
        $this->assertSame([], $diff['delete']);
    }

    public function testPathspecIncludesAddAndDeleteInSubdirectory(): void
    {
        mkdir($this->repoDir . '/Backend', 0755, true);
        $this->commitFile('Backend/keep.php', 'keep');
        $from = $this->currentRef();

        $this->commitFile('Backend/new.php', 'new');
        unlink($this->repoDir . '/Backend/keep.php');
        $this->git(['add', '-A']);
        $this->git(['commit', '-q', '-m', 'add and delete']);
        $to = $this->currentRef();

        $diff = (new GitDiffer($this->repoDir))->diff($from, $to, 'Backend');

        $this->assertSame(['Backend/new.php'], $diff['add']);
        $this->assertSame(['Backend/keep.php'], $diff['delete']);
    }

    private function commitFile(string $name, string $content): void
    {
        file_put_contents($this->repoDir . '/' . $name, $content);
        $this->git(['add', '-A']);
        $this->git(['commit', '-q', '-m', "commit {$name}"]);
    }

    private function currentRef(): string
    {
        return trim($this->git(['rev-parse', 'HEAD']));
    }

    private function git(array $args): string
    {
        $cmd = array_merge(['git', '-C', $this->repoDir], $args);
        $escaped = implode(' ', array_map('escapeshellarg', $cmd));
        return shell_exec($escaped . ' 2>&1') ?? '';
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            if (is_dir($path) && !is_link($path)) {
                $this->rrmdir($path);
            } else {
                @chmod($path, 0777);
                @unlink($path);
            }
        }
        @rmdir($dir);
    }
}
