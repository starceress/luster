<?php
/**
 * Luster compatibility wrapper for Enthusiast's show_collective_affiliates.php
 *
 * Drop this file into your Enthusiast installation directory.
 * No listing ID is needed — renders site-wide collective affiliates.
 *
 * Optional: $luster_path (string) — absolute path to Luster install root
 *           (not needed when LUSTER_BASE is already defined)
 */

$_luster_root = defined('LUSTER_BASE') ? LUSTER_BASE : ($luster_path ?? '');

if ($_luster_root === '') {
    echo '<!-- Luster compat: set $luster_path or define LUSTER_BASE -->';
    return;
}

$luster_return = false;
require $_luster_root . '/public/embed/affiliates.php';
