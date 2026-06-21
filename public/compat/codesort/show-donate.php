<?php
/**
 * Luster compatibility wrapper — CodeSort show-donate.php
 *
 * Drop this + luster-bridge.php into your CodeSort folder.
 * The listing is auto-detected from CodeSort's $listing variable.
 */

require_once __DIR__ . '/luster-bridge.php';

if (!isset($luster_fanlisting_id) || (int) $luster_fanlisting_id === 0) {
    echo '<!-- Luster compat: listing not found. Set $listing or $luster_listing_id. -->';
    return;
}

$luster_return = false;
require LUSTER_PATH . '/public/embed/codes-submit.php';
