<?php
/**
 * Luster compatibility wrapper — Listing Admin show-kim-join.php
 *
 * Drop this + luster-bridge.php into your Listing Admin folder.
 * The listing is auto-detected from Listing Admin's $listid variable.
 */

require_once __DIR__ . '/luster-bridge.php';

if (!isset($luster_fanlisting_id) || (int) $luster_fanlisting_id === 0) {
    echo '<!-- Luster compat: listing not found. Set $listid or $luster_listing_id. -->';
    return;
}

$luster_return = false;
require LUSTER_PATH . '/public/embed/kim-signup.php';
