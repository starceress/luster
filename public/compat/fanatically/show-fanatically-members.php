<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Compatibility shim for Fanatically's show-fanatically-members.php. Drop this
 file and luster-bridge.php into your Fanatically folder to replace the
 original member list. The listing is auto-detected from Fanatically's
 $fanatically_listing_id variable and rendered by Luster's members embed.
*/

require_once __DIR__ . '/luster-bridge.php';

if (!isset($luster_fanlisting_id) || (int) $luster_fanlisting_id === 0) {
    echo '<!-- Luster compat: listing not found. Set $fanatically_listing_id or $luster_listing_id. -->';
    return;
}

$luster_return = false;
require LUSTER_PATH . '/public/embed/members.php';
