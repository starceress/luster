<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Compatibility shim for CodeSort's show-codes.php. Drop this file and
 luster-bridge.php into your CodeSort folder to replace the original fan
 codes gallery. The listing is auto-detected from CodeSort's $listing variable
 and the codes are rendered by Luster's codes embed.
*/

require_once __DIR__ . '/luster-bridge.php';

if (!isset($luster_fanlisting_id) || (int) $luster_fanlisting_id === 0) {
    echo '<!-- Luster compat: listing not found. Set $listing or $luster_listing_id. -->';
    return;
}

$luster_return = false;
require LUSTER_PATH . '/public/embed/codes.php';
