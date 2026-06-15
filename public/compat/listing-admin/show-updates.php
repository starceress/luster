<?php
/**
 * Luster compatibility wrapper for Listing Admin's show-updates.php
 *
 * Drop this file into your Listing Admin installation directory.
 * Set $luster_listing_id for per-listing updates, or omit it for collective updates.
 *
 * Optional: $luster_listing_id (int) — Luster listing ID for per-listing updates;
 *                                       omit to show collective updates
 *           $luster_path (string)    — absolute path to Luster install root
 *                                      (not needed when LUSTER_BASE is already defined)
 */

if (!isset($luster_fanlisting_id) && isset($luster_listing_id)) {
    $luster_fanlisting_id = (int) ($luster_listing_id
        ?? $listid
        ?? $listing_id
        ?? 0);
}

$_luster_root = defined('LUSTER_BASE') ? LUSTER_BASE : ($luster_path ?? '');

if ($_luster_root === '') {
    echo '<!-- Luster compat: set $luster_path or define LUSTER_BASE -->';
    return;
}

$luster_return = false;
require $_luster_root . '/public/embed/updates.php';
