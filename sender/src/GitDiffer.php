<?php

namespace Deployer\Sender;

use RuntimeException;

class GitDiffer
{
    /** @var string */
    private $repoPath;

    public function __construct(string $repoPath)
    {
        $this->repoPath = rtrim($repoPath, '/\\');
    }

    /**
     * Returns ['add' => [paths], 'modify' => [paths], 'delete' => [paths]]
     * Renames are treated as delete (old path) + add (new path).
     */
    public function diff(string $fromRef, string $toRef, ?string $pathspec = null): array
    {
        $args = ['diff', '--name-status', '-M', $fromRef, $toRef];
        if ($pathspec !== null) {
            $args[] = '--';
            $args[] = $pathspec;
        }
        $output = $this->runGit($args);

        $result = ['add' => [], 'modify' => [], 'delete' => []];

        foreach (explode("\n", $output) as $line) {
            $line = rtrim($line, "\r\n");
            if ($line === '') {
                continue;
            }

            $parts = preg_split('/\t+/', $line);
            $status = $parts[0];
            $code = $status[0];

            if ($code === 'A') {
                $result['add'][] = $parts[1];
            } elseif ($code === 'M') {
                $result['modify'][] = $parts[1];
            } elseif ($code === 'D') {
                $result['delete'][] = $parts[1];
            } elseif ($code === 'R') {
                // R100 old new
                $result['delete'][] = $parts[1];
                $result['add'][] = $parts[2];
            } elseif ($code === 'C') {
                // copy: treat as add of the new path
                $result['add'][] = $parts[2];
            }
        }

        return $result;
    }

    /**
     * Reads a file's content at a given ref (e.g. `git show <ref>:<path>`).
     */
    public function readFileAtRef(string $ref, string $path): string
    {
        return $this->runGit(['show', "{$ref}:{$path}"], true);
    }

    private function runGit(array $args, bool $binarySafe = false): string
    {
        $cmd = array_merge(['git', '-C', $this->repoPath], $args);
        $escaped = implode(' ', array_map('escapeshellarg', $cmd));

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($escaped, $descriptors, $pipes);
        if (!is_resource($process)) {
            throw new RuntimeException("Failed to start git process: {$escaped}");
        }

        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        if ($exitCode !== 0) {
            throw new RuntimeException("git command failed ({$exitCode}): {$escaped}\n{$stderr}");
        }

        return $stdout;
    }
}
