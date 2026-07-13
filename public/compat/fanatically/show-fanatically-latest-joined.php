<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Compatibility shim for Fanatically's show-fanatically-latest-joined.php. Drop
 this file and luster-bridge.php into your Fanatically folder to replace the
 original "latest joined listing" spotlight. No listing ID is needed. The
 output is rendered by Luster's latest-joined-listing embed.

 KNOWN PARITY GAP: "latest" doesn't mean quite the same thing in both.
 Fanatically's original file picks the joined listing with the highest
 internal database ID — effectively "most recently added". Luster's
 latest-joined-listing embed instead orders by the listing's `date_joined`
 value (falling back to ID only when that's null), which an admin can edit
 independently of when the row was actually added. These usually agree, but
 can diverge if a listing's joined date is backdated, left blank, or edited
 later.
*/

require_once __DIR__ . '/luster-bridge.php';

$luster_return = false;
require LUSTER_PATH . '/public/embed/latest-joined-listing.php';
