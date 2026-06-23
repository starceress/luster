<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Shared bootstrap file. Both front controllers (index.php, admin.php) and
 every embed file require this to get a ready-to-use Application instance.
 It wires up the autoloader, loads config/app.php, and returns the app
 object — callers just do `$app = require 'bootstrap.php';`.
*/

declare(strict_types=1);

defined('LUSTER_BASE')  || define('LUSTER_BASE',  __DIR__);
defined('LUSTER_EMBED') || define('LUSTER_EMBED', rtrim(str_replace('\\', '/', LUSTER_BASE), '/') . '/public/embed');

require_once LUSTER_BASE . '/vendor/autoload.php';

return new \Luster\Core\Application(LUSTER_BASE, LUSTER_BASE . '/config/app.php');
