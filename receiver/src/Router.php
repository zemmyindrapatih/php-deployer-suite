<?php

namespace Deployer\Receiver;

class Router
{
    private Auth $auth;
    private ChunkUploader $uploader;
    private Extractor $extractor;
    private BackupManager $backupManager;
    private Applier $applier;
    private DeployLog $log;
    private Rollback $rollback;
    private string $workDir;

    public function __construct(
        Auth $auth,
        ChunkUploader $uploader,
        Extractor $extractor,
        BackupManager $backupManager,
        Applier $applier,
        DeployLog $log,
        Rollback $rollback,
        string $workDir
    ) {
        $this->auth = $auth;
        $this->uploader = $uploader;
        $this->extractor = $extractor;
        $this->backupManager = $backupManager;
        $this->applier = $applier;
        $this->log = $log;
        $this->rollback = $rollback;
        $this->workDir = rtrim($workDir, '/\\');
    }

    /**
     * Dispatches one request. $input is the merged request payload
     * (query + post + json body), $session is a reference to $_SESSION-like array,
     * $tokenHeader is the X-Deploy-Token header value (or null).
     *
     * @return array{status:int, body:array}
     */
    public function dispatch(string $action, array $input, array &$session, ?string $tokenHeader, callable $rawBodyReader = null): array
    {
        if ($action === 'login') {
            if ($this->auth->checkPassword($input['password'] ?? null)) {
                $this->auth->markSessionAuthenticated($session);
                return $this->ok([]);
            }
            return $this->error(403, 'invalid_password');
        }

        if (!$this->auth->isAuthorized($tokenHeader, $session)) {
            return $this->error(403, 'unauthorized');
        }

        switch ($action) {
            case 'init_upload':
                return $this->handleInitUpload($input);
            case 'upload_chunk':
                return $this->handleUploadChunk($input, $rawBodyReader);
            case 'finalize_upload':
                return $this->handleFinalizeUpload($input);
            case 'extract':
                return $this->handleExtract($input);
            case 'backup_and_apply_step':
                return $this->handleBackupAndApplyStep($input);
            case 'finish':
                return $this->handleFinish($input);
            case 'rollback':
                return $this->handleRollback($input);
            case 'history':
                return $this->ok(['entries' => $this->log->readAll()]);
            default:
                return $this->error(400, 'unknown_action');
        }
    }

    private function handleInitUpload(array $input): array
    {
        foreach (['filename', 'total_size', 'chunk_size', 'total_chunks'] as $field) {
            if (!isset($input[$field])) {
                return $this->error(400, "missing_field:{$field}");
            }
        }

        $result = $this->uploader->initUpload(
            (string) $input['filename'],
            (int) $input['total_size'],
            (int) $input['chunk_size'],
            (int) $input['total_chunks']
        );

        return $this->ok($result);
    }

    private function handleUploadChunk(array $input, ?callable $rawBodyReader): array
    {
        if (!isset($input['deploy_id'], $input['index'])) {
            return $this->error(400, 'missing_field');
        }

        $data = $rawBodyReader ? $rawBodyReader() : '';

        $result = $this->uploader->appendChunk((string) $input['deploy_id'], (int) $input['index'], $data);

        return $this->ok($result);
    }

    private function handleFinalizeUpload(array $input): array
    {
        if (!isset($input['deploy_id'])) {
            return $this->error(400, 'missing_field:deploy_id');
        }

        $finalPath = $this->uploader->finalizeUpload((string) $input['deploy_id']);

        return $this->ok(['zip_path' => $finalPath]);
    }

    private function handleExtract(array $input): array
    {
        if (!isset($input['deploy_id'])) {
            return $this->error(400, 'missing_field:deploy_id');
        }

        $deployId = (string) $input['deploy_id'];
        $zipPath = $this->uploader->deployDir($deployId) . '/upload.zip';
        $extractDir = $this->uploader->deployDir($deployId) . '/extracted';

        $manifest = $this->extractor->extract($zipPath, $extractDir);

        $this->stashManifest($deployId, $manifest);

        return $this->ok([
            'add' => count($manifest['add']),
            'replace' => count($manifest['replace']),
            'delete' => count($manifest['delete']),
            'total' => count($manifest['add']) + count($manifest['replace']) + count($manifest['delete']),
        ]);
    }

    private function handleBackupAndApplyStep(array $input): array
    {
        if (!isset($input['deploy_id'], $input['index'])) {
            return $this->error(400, 'missing_field');
        }

        $deployId = (string) $input['deploy_id'];
        $index = (int) $input['index'];
        $manifest = $this->loadManifest($deployId);

        $entries = $this->flattenEntries($manifest);
        $total = count($entries);

        if ($index === 0) {
            $replacePaths = array_column($manifest['replace'], 'path');
            $backupPath = $this->backupManager->backup($replacePaths, $manifest['delete']);
            if ($backupPath !== null) {
                file_put_contents($this->uploader->deployDir($deployId) . '/backup.stash.txt', basename($backupPath));
            }
        }

        if ($index >= $total) {
            return $this->error(400, 'index_out_of_range');
        }

        $entry = $entries[$index];
        $entry['source_dir'] = $this->uploader->deployDir($deployId) . '/extracted/files';

        $applyResult = $this->applier->applyEntry($entry);

        return $this->ok(array_merge($applyResult, ['index' => $index, 'total' => $total]));
    }

    private function handleFinish(array $input): array
    {
        if (!isset($input['deploy_id'])) {
            return $this->error(400, 'missing_field:deploy_id');
        }

        $deployId = (string) $input['deploy_id'];
        $manifest = $this->loadManifest($deployId);

        $backupStashPath = $this->uploader->deployDir($deployId) . '/backup.stash.txt';
        $backup = is_file($backupStashPath) ? trim(file_get_contents($backupStashPath)) : null;

        $this->log->append([
            'deploy_id' => $deployId,
            'from_ref' => $manifest['from_ref'] ?? null,
            'to_ref' => $manifest['to_ref'] ?? null,
            'add' => count($manifest['add']),
            'replace' => count($manifest['replace']),
            'delete' => count($manifest['delete']),
            'backup' => $backup,
        ]);

        return $this->ok(['summary' => [
            'add' => count($manifest['add']),
            'replace' => count($manifest['replace']),
            'delete' => count($manifest['delete']),
        ]]);
    }

    private function handleRollback(array $input): array
    {
        if (!isset($input['backup'])) {
            return $this->error(400, 'missing_field:backup');
        }

        $restored = $this->rollback->restore((string) $input['backup']);

        return $this->ok(['restored' => $restored]);
    }

    private function flattenEntries(array $manifest): array
    {
        $entries = [];
        foreach ($manifest['add'] as $e) {
            $entries[] = ['type' => 'add', 'path' => $e['path'], 'sha256' => $e['sha256']];
        }
        foreach ($manifest['replace'] as $e) {
            $entries[] = ['type' => 'replace', 'path' => $e['path'], 'sha256' => $e['sha256']];
        }
        foreach ($manifest['delete'] as $path) {
            $entries[] = ['type' => 'delete', 'path' => $path, 'sha256' => null];
        }

        return $entries;
    }

    private function stashManifest(string $deployId, array $manifest): void
    {
        file_put_contents($this->uploader->deployDir($deployId) . '/manifest.stash.json', json_encode($manifest));
    }

    private function loadManifest(string $deployId): array
    {
        $path = $this->uploader->deployDir($deployId) . '/manifest.stash.json';

        return json_decode(file_get_contents($path), true);
    }

    private function ok(array $body): array
    {
        return ['status' => 200, 'body' => array_merge(['ok' => true], $body)];
    }

    private function error(int $status, string $message): array
    {
        return ['status' => $status, 'body' => ['ok' => false, 'error' => $message]];
    }
}
