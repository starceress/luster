<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Compatibility shim for Listing Admin's show-real-stats.php. Listing Admin
 had two stats displays (show-stats for simple counts, show-real-stats for
 detailed statistics). Luster unifies both into a single stats embed, so this
 wrapper routes to the same stats embed as show-stats.php.
*/

require_once __DIR__ . '/luster-bridge.php';

if (!isset($luster_fanlisting_id) || (int) $luster_fanlisting_id === 0) {
    echo '<!-- Luster compat: listing not found. Set $listid or $luster_listing_id. -->';
    return;
}

$luster_return = false;
require LUSTER_PATH . '/public/embed/stats.php';
