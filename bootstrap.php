<?php

declare(strict_types=1);

/**
 * Luster — shared application bootstrap.
 *
 * Creates and returns a fully initialised Application instance.
 * Used by public/index.php, public/admin.php, and public/embed/*.php.
 *
 * Expects LUSTER_BASE to be defined before this file is required.
 * If not defined, defaults to __DIR__ (the project root).
 *
 * Usage:
 *   defined('LUSTER_BASE') || define('LUSTER_BASE', dirname(__DIR__));
 *   /** @var \Luster\Core\Application $app {@*}
 *   $app = require LUSTER_BASE . '/bootstrap.php';
 */

defined('LUSTER_BASE') || define('LUSTER_BASE', __DIR__);

require_once LUSTER_BASE . '/vendor/autoload.php';

return new \Luster\Core\Application(LUSTER_BASE, LUSTER_BASE . '/config/app.php');
