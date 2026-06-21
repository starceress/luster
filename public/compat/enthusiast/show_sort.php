<?php
/**
 * Luster compatibility wrapper — Enthusiast show_sort.php
 *
 * Drop this + luster-bridge.php into your Enthusiast folder.
 * Luster combines sort controls and member list into one embed,
 * so this wrapper routes to the same members embed.
 */

require_once __DIR__ . '/luster-bridge.php';

if (!isset($luster_fanlisting_id) || (int) $luster_fanlisting_id === 0) {
    echo '<!-- Luster compat: listing not found. Set $listing or $luster_listing_id. -->';
    return;
}

$luster_return = false;
require LUSTER_PATH . '/public/embed/members.php';
