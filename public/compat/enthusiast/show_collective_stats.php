<?php
/**
 * Luster compatibility wrapper — Enthusiast show_collective_stats.php
 *
 * Drop this + luster-bridge.php into your Enthusiast folder.
 * No listing ID needed — this shows collective stats.
 */

require_once __DIR__ . '/luster-bridge.php';

$luster_return = false;
require LUSTER_PATH . '/public/embed/collective-stats.php';
