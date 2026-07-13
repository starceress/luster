<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Compatibility shim for Fanatically's show-fanatically-delete.php. Fanatically
 has this as a separate sign-in -> confirm -> remove-membership flow, but
 Luster's update embed already handles deletion as part of its own sign-in ->
 edit-or-delete flow, so there's no separate Luster embed to point to instead.
 Drop this file and luster-bridge.php into your Fanatically folder; the
 listing is auto-detected from Fanatically's $fanatically_listing_id variable.
*/

require_once __DIR__ . '/luster-bridge.php';

if (!isset($luster_fanlisting_id) || (int) $luster_fanlisting_id === 0) {
    echo '<!-- Luster compat: listing not found. Set $fanatically_listing_id or $luster_listing_id. -->';
    return;
}

$luster_return = false;
require LUSTER_PATH . '/public/embed/update.php';
