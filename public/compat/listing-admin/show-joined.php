<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Compatibility shim for Listing Admin's show-joined.php. Drop this file and
 luster-bridge.php into your Listing Admin folder to replace the original
 joined listings display. No listing ID is needed because this shows all
 joined listings. The output is rendered by Luster's joined embed.
*/

require_once __DIR__ . '/luster-bridge.php';

if (!is_file(LUSTER_PATH . '/bootstrap.php')) {
    echo '<!-- Luster compat: LUSTER_PATH is not set correctly. -->';
    return;
}

$luster_return = false;
require LUSTER_PATH . '/public/embed/joined.php';
