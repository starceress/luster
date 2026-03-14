<?php

declare(strict_types=1);

/**
 * luster_bootstrap.php — Embeddable Luster bootstrap for standalone listing pages.
 *
 * Include this file at the top of an individual listing's PHP page to gain
 * access to live Luster data without going through the full HTTP stack.
 *
 * Usage
 * -----
 *   <?php
 *   $luster_listing = 3;          // numeric ID from lstr_fanlisting_settings / enth_fanlistings
 *   require '/path/to/luster/public/luster_bootstrap.php';
 *
 *   // After include, the following variables are available:
 *   //   $lstr_fl          — Luster\Models\Listing  (the loaded listing)
 *   //   $lstr_db          — Luster\Core\Database
 *   //   $lstr_config      — Luster\Core\Config
 *   //   $lstr_memberRepo  — Luster\Repositories\MemberRepo
 *   //   $lstr_settingsRepo — Luster\Repositories\ListingSettingsRepo
 *
 * If $luster_listing is not a positive integer, or the listing cannot be found,
 * the script emits an HTML comment and stops execution of the calling page.
 */

// ── 1. Validate caller-supplied ID ──────────────────────────────────────────

if (!isset($luster_listing) || !is_int($luster_listing) || $luster_listing <= 0) {
    echo '<!-- Luster bootstrap error: $luster_listing must be set to a positive integer before including this file. -->';
    exit;
}

// ── 2. Autoloader ───────────────────────────────────────────────────────────

require_once __DIR__ . '/../vendor/autoload.php';

// ── 3. Config + Database ─────────────────────────────────────────────────────

use Luster\Core\Config;
use Luster\Core\Database;
use Luster\Repositories\ListingRepo;
use Luster\Repositories\ListingSettingsRepo;
use Luster\Repositories\MemberRepo;

$lstr_config = new Config(__DIR__ . '/../config/app.php');
$lstr_db     = new Database($lstr_config);

// ── 4. Repositories ──────────────────────────────────────────────────────────

$lstr_listingRepo     = new ListingRepo($lstr_db, $lstr_config);
$lstr_memberRepo      = new MemberRepo($lstr_db, $lstr_config);
$lstr_settingsRepo    = new ListingSettingsRepo($lstr_db);

// ── 5. Load listing ───────────────────────────────────────────────────────

$lstr_fl = $lstr_listingRepo->getById($luster_listing);

if ($lstr_fl === null) {
    // Listing not found — exit silently so the calling page stays blank
    // rather than crashing with a PHP error.
    exit;
}
