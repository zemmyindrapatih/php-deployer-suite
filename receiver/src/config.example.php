<?php

// Copy this file to config.php (kept out of the built dist by default; the build
// script instead inlines these as constants — see build.php) and fill in real values.

return [
    // sha256 of the long random API token used for header-based auth (X-Deploy-Token).
    // Generate a token with: php -r "echo bin2hex(random_bytes(32));"
    // Then hash it with:      php -r "echo hash('sha256', 'YOUR_TOKEN_HERE');"
    'token_hash' => 'CHANGE_ME',

    // password_hash() of the web UI login password.
    // Generate with: php -r "echo password_hash('YOUR_PASSWORD', PASSWORD_DEFAULT);"
    'password_hash' => 'CHANGE_ME',

    // Absolute path to the directory that is the deployment target (usually
    // the same directory this file lives in, or its parent).
    'web_root' => __DIR__,

    // Absolute path for temp upload/extraction work files. Recommended: outside web_root.
    'work_dir' => __DIR__ . '/.deployer-work',

    // Absolute path for backup zips. Recommended: outside web_root, or .htaccess-denied.
    'backup_dir' => __DIR__ . '/.deployer-backups',

    // Absolute path for the deploy log JSON file. Recommended: outside web_root.
    'log_path' => __DIR__ . '/.deployer-log.json',
];
