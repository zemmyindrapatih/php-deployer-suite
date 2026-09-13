<?php

namespace Deployer\Sender;

class ManifestBuilder
{
    private GitDiffer $differ;

    public function __construct(GitDiffer $differ)
    {
        $this->differ = $differ;
    }

    /**
     * @return array{
     *   version:int, generated_at:string, from_ref:string, to_ref:string,
     *   chunk_size_hint:int,
     *   add: array<int,array{path:string,sha256:string}>,
     *   replace: array<int,array{path:string,sha256:string}>,
     *   delete: array<int,string>
     * }
     */
    public function build(string $fromRef, string $toRef, array $classifiedDiff, int $chunkSize): array
    {
        $add = [];
        foreach ($classifiedDiff['add'] as $path) {
            $content = $this->differ->readFileAtRef($toRef, $path);
            $add[] = ['path' => $path, 'sha256' => hash('sha256', $content)];
        }

        $replace = [];
        foreach ($classifiedDiff['modify'] as $path) {
            $content = $this->differ->readFileAtRef($toRef, $path);
            $replace[] = ['path' => $path, 'sha256' => hash('sha256', $content)];
        }

        return [
            'version' => 1,
            'generated_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'from_ref' => $fromRef,
            'to_ref' => $toRef,
            'chunk_size_hint' => $chunkSize,
            'add' => $add,
            'replace' => $replace,
            'delete' => array_values($classifiedDiff['delete']),
        ];
    }
}
