<?php
/**
 * Luster compatibility wrapper for Listing Admin's show-kim-reset.php
 *
 * Drop this file into your Listing Admin installation directory and set
 * $luster_listing_id in the host page before including it.
 *
 * Required: $luster_listing_id (int) — your Luster listing ID
 * Optional: $luster_path (string)   — absolute path to Luster install root
 *           (not needed when LUSTER_BASE is already defined)
 */

if (!isset($luster_fanlisting_id)) {
    $luster_fanlisting_id = (int) ($luster_listing_id
        ?? $listid
        ?? $listing_id
        ?? 0);
}

if ($luster_fanlisting_id === 0) {
    echo '<!-- Luster compat: $luster_listing_id not set -->';
    return;
}

$_luster_root = defined('LUSTER_BASE') ? LUSTER_BASE : ($luster_path ?? '');

if ($_luster_root === '') {
    echo '<!-- Luster compat: set $luster_path or define LUSTER_BASE -->';
    return;
}

$luster_return = false;
require $_luster_root . '/public/embed/kim-reset.php';
