<?php

declare(strict_types=1);

/**
 * Luster — public front controller.
 *
 * Every public request (join, members list, update, remove) is routed
 * through this file by public/.htaccess.
 *
 * WEB ROOT: Only the public/ directory is web-accessible.
 *           No files outside public/ can be requested directly.
 */

define('LUSTER_BASE', dirname(__DIR__));

// Composer autoloader — show a clear error if Composer hasn't been run
if (!is_file(LUSTER_BASE . '/vendor/autoload.php')) {
    http_response_code(503);
    echo '<!DOCTYPE html><html><head><title>Setup Required</title></head><body>'
        . '<h1>Dependencies not installed</h1>'
        . '<p>Run <code>composer install</code> before using Luster.</p>'
        . '</body></html>';
    exit;
}

// If config/app.php does not exist yet, send the user to the installer
if (!is_file(LUSTER_BASE . '/config/app.php')) {
    header('Location: /install.php');
    exit;
}

/** @var \Luster\Core\Application $app */
$app = require LUSTER_BASE . '/bootstrap.php';
$app->run();
// No exit() here — PHP ends naturally; see Response::send() for redirect handling.
