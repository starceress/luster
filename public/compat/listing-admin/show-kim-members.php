<?php
/**
 * Luster compatibility wrapper for Listing Admin's show-kim-members.php
 *
 * Drop this file into your Listing Admin installation directory.
 * No listing ID is needed — renders the collective KIM list overview.
 * Requires the Keep In Mind add-on to be installed in Luster.
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
require $_luster_root . '/public/embed/kim-list.php';
