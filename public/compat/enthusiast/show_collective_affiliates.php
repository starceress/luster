<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Compatibility shim for Enthusiast's collective affiliates display. Drop this
 file and luster-bridge.php into your Enthusiast folder to replace the original
 collective affiliates page. No listing ID is needed because this shows
 site-wide affiliates. The output is rendered by Luster's affiliates embed
 with a zeroed listing ID.
*/

require_once __DIR__ . '/luster-bridge.php';

if (!is_file(LUSTER_PATH . '/bootstrap.php')) {
    echo '<!-- Luster compat: LUSTER_PATH is not set correctly. -->';
    return;
}

$luster_fanlisting_id = 0;
$luster_return = false;
require LUSTER_PATH . '/public/embed/affiliates.php';
