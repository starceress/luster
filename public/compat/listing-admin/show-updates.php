<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Compatibility shim for Listing Admin's show-updates.php. Drop this file and
 luster-bridge.php into your Listing Admin folder to replace the original
 updates feed. The listing is auto-detected from Listing Admin's $listid
 variable and the updates are rendered by Luster's updates embed.
*/

require_once __DIR__ . '/luster-bridge.php';

if (!isset($luster_fanlisting_id) || (int) $luster_fanlisting_id === 0) {
    echo '<!-- Luster compat: listing not found. Set $listid or $luster_listing_id. -->';
    return;
}

$luster_return = false;
require LUSTER_PATH . '/public/embed/updates.php';
