<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Admin front controller. All /admin/* requests are routed here by
 .htaccess. Works just like index.php but for the admin panel —
 bootstraps the app, checks that the user is logged in via
 AuthMiddleware, then hands off to the matching admin controller.
*/

declare(strict_types=1);

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
    header('Location: ../install/');
    exit;
}

/** @var \Luster\Core\Application $app */
$app = require LUSTER_BASE . '/bootstrap.php';
$app->runAdmin();
