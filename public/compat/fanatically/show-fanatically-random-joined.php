<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Compatibility shim for Fanatically's show-fanatically-random-joined.php. Drop
 this file and luster-bridge.php into your Fanatically folder to replace the
 original random joined listing display. No listing ID is needed. The output
 is rendered by Luster's random-joined-listing embed.
*/

require_once __DIR__ . '/luster-bridge.php';

if (!is_file(LUSTER_PATH . '/bootstrap.php')) {
    echo '<!-- Luster compat: LUSTER_PATH is not set correctly. -->';
    return;
}

$luster_return = false;
require LUSTER_PATH . '/public/embed/random-joined-listing.php';
