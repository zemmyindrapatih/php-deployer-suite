<?php

namespace Deployer\Receiver;

class DeployLog
{
    private string $logPath;

    public function __construct(string $logPath)
    {
        $this->logPath = $logPath;
    }

    public function append(array $entry): void
    {
        $entries = $this->readAll();
        $entry['timestamp'] = $entry['timestamp'] ?? gmdate('Y-m-d\TH:i:s\Z');
        $entries[] = $entry;
        file_put_contents($this->logPath, json_encode($entries, JSON_PRETTY_PRINT));
    }

    public function readAll(): array
    {
        if (!is_file($this->logPath)) {
            return [];
        }

        $content = file_get_contents($this->logPath);
        $decoded = json_decode($content, true);

        return is_array($decoded) ? $decoded : [];
    }
}
