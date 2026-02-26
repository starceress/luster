<?php

declare(strict_types=1);

/**
 * Luster — admin front controller.
 *
 * All /admin/* requests are routed here by public/.htaccess.
 * AuthMiddleware (Phase 2) enforces authentication before any
 * admin controller action is executed.
 *
 * WEB ROOT: Only the public/ directory is web-accessible.
 */

define('LUSTER_BASE', dirname(__DIR__));

// Composer autoloader
$autoloader = LUSTER_BASE . '/vendor/autoload.php';

if (!is_file($autoloader)) {
    http_response_code(503);
    echo '<!DOCTYPE html><html><head><title>Setup Required</title></head><body>'
        . '<h1>Dependencies not installed</h1>'
        . '<p>Run <code>composer install</code> before using Luster.</p>'
        . '</body></html>';
    exit;
}

require_once $autoloader;

use Luster\Core\Application;

// If config/app.php does not exist yet, redirect to the installer
$configPath = LUSTER_BASE . '/config/app.php';

if (!is_file($configPath)) {
    header('Location: /install.php');
    exit;
}

$app = new Application(LUSTER_BASE, $configPath);
$app->runAdmin();
