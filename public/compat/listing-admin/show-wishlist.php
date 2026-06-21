<?php
/**
 * Luster compatibility wrapper — Listing Admin show-wishlist.php
 *
 * Drop this + luster-bridge.php into your Listing Admin folder.
 * No listing ID needed.
 */

require_once __DIR__ . '/luster-bridge.php';

$luster_return = false;
require LUSTER_PATH . '/public/embed/wishlist.php';
