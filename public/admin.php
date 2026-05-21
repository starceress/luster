<?php

declare(strict_types=1);

/**
 * Luster — admin front controller.
 *
 * All /admin/* requests are routed here by public/.htaccess.
 * AuthMiddleware enforces authentication before any admin controller action runs.
 *
 * WEB ROOT: Only the public/ directory is web-accessible.
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

// If config/app.php does not exist yet, redirect to the installer
if (!is_file(LUSTER_BASE . '/config/app.php')) {
    header('Location: ./install/');
    exit;
}

/** @var \Luster\Core\Application $app */
$app = require LUSTER_BASE . '/bootstrap.php';
$app->runAdmin();
