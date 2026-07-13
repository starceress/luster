<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Compatibility shim for Fanatically's show-fanatically-latest-owned.php. Drop
 this file and luster-bridge.php into your Fanatically folder to replace the
 original "latest owned listing" spotlight. No listing ID is needed. The
 output is rendered by Luster's latest-listing embed.

 KNOWN PARITY GAP: "latest" doesn't mean quite the same thing in both.
 Fanatically's original file picks the current listing with the highest
 internal database ID — effectively "most recently added to the collective".
 Luster's latest-listing embed instead picks by the listing's `opened` date
 (falling back to ID only if that column is entirely absent) — effectively
 "most recently opened", which an admin can edit independently of when the
 listing was actually added. These usually agree, but can diverge if a
 listing's opened date is backdated, left blank, or edited later.
*/

require_once __DIR__ . '/luster-bridge.php';

$luster_return = false;
require LUSTER_PATH . '/public/embed/latest-listing.php';
