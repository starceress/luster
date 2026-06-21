<?php
/**
 * Luster compatibility wrapper — Enthusiast show_affiliates.php
 *
 * Drop this + luster-bridge.php into your Enthusiast folder.
 * The listing is auto-detected from Enthusiast's $listing variable.
 */

require_once __DIR__ . '/luster-bridge.php';

if (!isset($luster_fanlisting_id) || (int) $luster_fanlisting_id === 0) {
    echo '<!-- Luster compat: listing not found. Set $listing or $luster_listing_id. -->';
    return;
}

$luster_return = false;
require LUSTER_PATH . '/public/embed/affiliates.php';
