<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Compatibility shim for Fanatically's show-fanatically-lostpass.php. Drop this
 file and luster-bridge.php into your Fanatically folder to replace the
 original lost-password form. The listing is auto-detected from Fanatically's
 $fanatically_listing_id variable and rendered by Luster's reset embed.

 NOT AN EXACT BEHAVIORAL MATCH — read before relying on this: Fanatically's
 own lostpass flow generates a brand-new password and emails it directly, in
 one step. Luster's reset embed is a token-based two-step flow instead (member
 requests a reset, gets an emailed link, then sets their own new password on a
 second page). Both end with the member able to log in again, but the actual
 experience differs. This is the closest available Luster embed, not a
 like-for-like replacement.
*/

require_once __DIR__ . '/luster-bridge.php';

if (!isset($luster_fanlisting_id) || (int) $luster_fanlisting_id === 0) {
    echo '<!-- Luster compat: listing not found. Set $fanatically_listing_id or $luster_listing_id. -->';
    return;
}

$luster_return = false;
require LUSTER_PATH . '/public/embed/reset.php';
