<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Compatibility shim for Enthusiast's show_sort.php. Drop this file and
 luster-bridge.php into your Enthusiast folder to replace the original member
 sort controls. Luster combines sorting and the member list into a single
 embed, so this wrapper routes to the same members embed as show_members.php.
*/

require_once __DIR__ . '/luster-bridge.php';

if (!isset($luster_fanlisting_id) || (int) $luster_fanlisting_id === 0) {
    echo '<!-- Luster compat: listing not found. Set $listing or $luster_listing_id. -->';
    return;
}

$luster_return = false;
require LUSTER_PATH . '/public/embed/members.php';
