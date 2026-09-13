<?php

require __DIR__ . '/../vendor/autoload.php';

use Deployer\Sender\GitDiffer;
use Deployer\Sender\ManifestBuilder;
use Deployer\Sender\PathMapper;
use Deployer\Sender\ZipPackager;

function parseArgs(array $argv): array
{
    $opts = [
        'repo' => getcwd(),
        'from' => null,
        'to' => null,
        'out' => null,
        'chunk-size' => 2 * 1024 * 1024,
        'path' => null,
        'strip-prefix' => null,
        'dest-prefix' => null,
    ];

    foreach (array_slice($argv, 1) as $arg) {
        if (!preg_match('/^--([a-z-]+)=(.*)$/', $arg, $m)) {
            continue;
        }
        [, $key, $value] = $m;
        if ($key === 'chunk-size') {
            $opts[$key] = (int) $value;
        } else {
            $opts[$key] = $value;
        }
    }

    return $opts;
}

$opts = parseArgs($argv);

if (!$opts['from'] || !$opts['to'] || !$opts['out']) {
    fwrite(STDERR, "Usage: php deploy.php --from=<git-ref> --to=<git-ref> --out=<file.zip> [--chunk-size=<bytes>] [--repo=<path>] [--path=<pathspec>] [--strip-prefix=<prefix>] [--dest-prefix=<folder>]\n");
    exit(1);
}

$differ = new GitDiffer($opts['repo']);
$diff = $differ->diff($opts['from'], $opts['to'], $opts['path']);

$pathMap = [];
if ($opts['strip-prefix']) {
    $stripped = PathMapper::stripPrefix($diff, $opts['strip-prefix']);
    $diff = ['add' => $stripped['add'], 'modify' => $stripped['modify'], 'delete' => $stripped['delete']];
    $pathMap = $stripped['map'];
}

if ($opts['dest-prefix']) {
    $prefixed = PathMapper::addPrefix($diff, $opts['dest-prefix'], $pathMap);
    $diff = ['add' => $prefixed['add'], 'modify' => $prefixed['modify'], 'delete' => $prefixed['delete']];
    $pathMap = $prefixed['map'];
}

$builder = new ManifestBuilder($differ);
$manifest = $builder->build($opts['from'], $opts['to'], $diff, $opts['chunk-size'], $pathMap);

$packager = new ZipPackager($differ);
$packager->package($manifest, $opts['out'], $pathMap);

printf(
    "Packaged %d added, %d replaced, %d deleted -> %s\n",
    count($manifest['add']),
    count($manifest['replace']),
    count($manifest['delete']),
    $opts['out']
);
