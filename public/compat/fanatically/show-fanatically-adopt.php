<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Compatibility shim for Fanatically's show-fanatically-adopt.php. Drop this
 file and luster-bridge.php into your Fanatically folder to replace the
 original adoption listings + application form display. No listing ID is
 needed — this is a collective-level page showing every listing available for
 adoption, matching Fanatically's own file (which never filters by a single
 listing either). The output is rendered by Luster's adopt embed.
*/

require_once __DIR__ . '/luster-bridge.php';

if (!is_file(LUSTER_PATH . '/bootstrap.php')) {
    echo '<!-- Luster compat: LUSTER_PATH is not set correctly. -->';
    return;
}

$luster_return = false;
require LUSTER_PATH . '/public/embed/adopt.php';
