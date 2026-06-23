<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Public front controller. All visitor-facing page requests are routed
 here by .htaccess — join forms, member lists, updates, resets, and
 stats. This file bootstraps the app, matches the URL to a controller,
 and sends back the response.
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

// If config/app.php does not exist yet, send the user to the installer
if (!is_file(LUSTER_BASE . '/config/app.php')) {
    header('Location: ../install/');
    exit;
}

/** @var \Luster\Core\Application $app */
$app = require LUSTER_BASE . '/bootstrap.php';

// Luster has no public home page — redirect the bare root URL to the admin panel.
if ((new \Luster\Core\Request())->path() === '/') {
    header('Location: ' . $app->getBaseUrl() . '/admin', true, 302);
    exit;
}

$app->run();
// No exit() here — PHP ends naturally; see Response::send() for redirect handling.
