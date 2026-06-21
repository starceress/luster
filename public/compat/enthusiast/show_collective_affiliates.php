<?php
/**
 * Luster compatibility wrapper — Enthusiast show_collective_affiliates.php
 *
 * Drop this + luster-bridge.php into your Enthusiast folder.
 * No listing ID needed — this shows collective affiliates.
 */

require_once __DIR__ . '/luster-bridge.php';

$luster_fanlisting_id = 0;
$luster_return = false;
require LUSTER_PATH . '/public/embed/affiliates.php';
