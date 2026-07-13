<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Compatibility shim for Fanatically's show-fanatically-update.php. Drop this
 file and luster-bridge.php into your Fanatically folder to replace the
 original member sign-in/update form. The listing is auto-detected from
 Fanatically's $fanatically_listing_id variable and rendered by Luster's
 update embed, which also handles membership removal as part of the same flow
 (see show-fanatically-delete.php, which points here too).
*/

require_once __DIR__ . '/luster-bridge.php';

if (!isset($luster_fanlisting_id) || (int) $luster_fanlisting_id === 0) {
    echo '<!-- Luster compat: listing not found. Set $fanatically_listing_id or $luster_listing_id. -->';
    return;
}

$luster_return = false;
require LUSTER_PATH . '/public/embed/update.php';
