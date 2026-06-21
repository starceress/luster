<?php
/**
 * Luster compatibility wrapper — Enthusiast show_owned.php
 *
 * Drop this + luster-bridge.php into your Enthusiast folder.
 * No listing ID needed — this shows all owned listings.
 */

require_once __DIR__ . '/luster-bridge.php';

$luster_return = false;
require LUSTER_PATH . '/public/embed/owned.php';
