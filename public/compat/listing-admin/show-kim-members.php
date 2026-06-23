<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Compatibility shim for Listing Admin's show-kim-members.php. Drop this file
 and luster-bridge.php into your Listing Admin folder to replace the original
 KIM (Keep In Mind) member list. The listing is auto-detected from Listing
 Admin's $listid variable and passed as the subject ID so Luster's kim-list
 embed renders the per-listing KIM member view.
*/

require_once __DIR__ . '/luster-bridge.php';

if (!isset($luster_fanlisting_id) || (int) $luster_fanlisting_id === 0) {
    echo '<!-- Luster compat: listing not found. Set $listid or $luster_listing_id. -->';
    return;
}

$luster_kim_subject_id = (int) $luster_fanlisting_id;
$luster_return = false;
require LUSTER_PATH . '/public/embed/kim-list.php';
