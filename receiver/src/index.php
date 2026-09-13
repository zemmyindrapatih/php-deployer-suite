<?php

namespace Deployer\Receiver;

require __DIR__ . '/../../vendor/autoload.php';
require __DIR__ . '/bootstrap.php';

$configPath = __DIR__ . '/config.php';
if (!is_file($configPath)) {
    http_response_code(500);
    echo 'Missing config.php - copy config.example.php to config.php and fill in real values.';
    exit(1);
}

$config = require $configPath;
$router = buildRouter($config);
handleRequest($router);
