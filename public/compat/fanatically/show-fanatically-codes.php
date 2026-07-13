<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Compatibility shim for Fanatically's show-fanatically-codes.php. Drop this
 file and luster-bridge.php into your Fanatically folder to replace the
 original fan codes display + donation form. The listing is auto-detected
 from Fanatically's $fanatically_listing_id variable (0 or unset = collective
 codes) and rendered by Luster's codes embed. $luster_append_submit tells the
 codes embed to safely append the donation form after the gallery itself —
 the purpose-built, safe equivalent of Fanatically's combined display, with
 no risk of double-rendering the form or double-consuming its flash state.

 No "listing not found" guard here on purpose: a $luster_fanlisting_id of 0
 is a legitimate, intentional mode for this embed (collective/site-wide
 codes), exactly matching Fanatically's own file — not an error state.
*/

require_once __DIR__ . '/luster-bridge.php';

$luster_return         = false;
$luster_append_submit  = true;
require LUSTER_PATH . '/public/embed/codes.php';
