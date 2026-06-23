<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Compatibility shim for Listing Admin's show-owned.php. Drop this file and
 luster-bridge.php into your Listing Admin folder to replace the original
 owned listings display. No listing ID is needed because this shows all owned
 listings. The output is rendered by Luster's owned embed.
*/

require_once __DIR__ . '/luster-bridge.php';

$luster_return = false;
require LUSTER_PATH . '/public/embed/owned.php';
