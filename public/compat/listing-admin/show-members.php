<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Compatibility shim for Listing Admin's show-members.php. Drop this file and
 luster-bridge.php into your Listing Admin folder to replace the original
 member list. The listing is auto-detected from Listing Admin's $listid
 variable and the member list is rendered by Luster's members embed.
*/

require_once __DIR__ . '/luster-bridge.php';

if (!isset($luster_fanlisting_id) || (int) $luster_fanlisting_id === 0) {
    echo '<!-- Luster compat: listing not found. Set $listid or $luster_listing_id. -->';
    return;
}

$luster_return = false;
require LUSTER_PATH . '/public/embed/members.php';
