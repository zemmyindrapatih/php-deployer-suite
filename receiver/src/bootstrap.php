<?php

namespace Deployer\Receiver;

/**
 * Builds a Router wired up from a config array. Shared by the dev entry point
 * (index.php, autoloaded classes) and the build script's inlined dist output.
 */
function buildRouter(array $config): Router
{
    $auth = new Auth($config['token_hash'], $config['password_hash']);
    $uploader = new ChunkUploader($config['work_dir']);
    $extractor = new Extractor();
    $backupManager = new BackupManager($config['backup_dir'], $config['web_root']);
    $applier = new Applier($config['web_root']);
    $log = new DeployLog($config['log_path']);
    $rollback = new Rollback($config['backup_dir'], $config['web_root']);

    return new Router($auth, $uploader, $extractor, $backupManager, $applier, $log, $rollback, $config['work_dir']);
}

function handleRequest(Router $router): void
{
    session_start();

    $action = $_GET['action'] ?? $_POST['action'] ?? null;

    if ($action === null) {
        readfile(__DIR__ . '/views/ui.php');
        return;
    }

    $input = array_merge($_GET, $_POST);
    $tokenHeader = $_SERVER['HTTP_X_DEPLOY_TOKEN'] ?? null;

    $rawBodyReader = function () {
        return file_get_contents('php://input');
    };

    $result = $router->dispatch($action, $input, $_SESSION, $tokenHeader, $rawBodyReader);

    http_response_code($result['status']);
    header('Content-Type: application/json');
    echo json_encode($result['body']);
}
