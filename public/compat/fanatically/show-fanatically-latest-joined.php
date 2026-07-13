<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Compatibility shim for Fanatically's show-fanatically-latest-joined.php. Drop
 this file and luster-bridge.php into your Fanatically folder to replace the
 original most-recently-added joined listing display. No listing ID is
 needed. The output is rendered by Luster's latest-joined-listing embed.
*/

require_once __DIR__ . '/luster-bridge.php';

$luster_return = false;
require LUSTER_PATH . '/public/embed/latest-joined-listing.php';
