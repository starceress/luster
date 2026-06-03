<?php

declare(strict_types=1);

/**
 * Luster — CodeSort compatibility shim: show-donate.php
 *
 * Drop-in replacement for CodeSort's show-donate.php include.
 * Routes to Luster's code submission (donation) embed after resolving
 * which listing the CodeSort $listing variable refers to.
 *
 * Note: Fanatically's equivalent file incorrectly routes to the codes
 * display page. This shim correctly routes to the submission form.
 *
 * Usage (identical to CodeSort's own include):
 *   $listing = 3;  // CodeSort listing ID — matched against Luster listing ID
 *   require '/absolute/path/to/luster/public/compat/codesort/show-donate.php';
 *
 * Resolution order (first match wins):
 *   1. $luster_fanlisting_id  — explicit Luster listing ID set by site owner
 *   2. $listing               — treated as a direct Luster listing ID
 *   3. URL match              — Luster listing whose URL starts with the current page URL
 *
 * If no listing can be resolved, an HTML comment is emitted.
 */

$_lusterDonateRoot = dirname(__DIR__, 3);

if (!is_file($_lusterDonateRoot . '/vendor/autoload.php') || !is_file($_lusterDonateRoot . '/config/app.php')) {
    echo '<!-- Luster compat error: Luster is not installed correctly. -->';
    return;
}

defined('LUSTER_BASE') || define('LUSTER_BASE', $_lusterDonateRoot);

/** @var \Luster\Core\Application $_lusterDonateApp */
$_lusterDonateApp = require $_lusterDonateRoot . '/bootstrap.php';
$_lusterDonateDb  = $_lusterDonateApp->getDatabase();

// ── Resolve listing ID ────────────────────────────────────────────────────────

$_lusterDonateListingId = 0;

// 1. Explicit Luster ID set by the site owner
if (isset($luster_fanlisting_id) && (int) $luster_fanlisting_id > 0) {
    $_lusterDonateListingId = (int) $luster_fanlisting_id;

// 2. CodeSort $listing variable — treat as direct Luster listing ID.
//    This is correct only when CodeSort and Luster listing IDs align (e.g. a
//    fresh Luster install). If IDs diverge after import, set $luster_fanlisting_id
//    explicitly to avoid routing to the wrong listing.
} elseif (isset($listing) && (int) $listing > 0) {
    $_lusterDonateListingId = (int) $listing;

// 3. URL-based fallback — match current page URL against lstr_fanlisting_settings
//    and the owned listing URL. Codes pages typically live in the listing directory,
//    so matching against owned.url is more reliable than form_url_join/update.
} else {
    $_lusterDonateHost    = $_SERVER['HTTP_HOST'] ?? '';
    $_lusterDonateIsHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
                         || (int) ($_SERVER['SERVER_PORT'] ?? 80) === 443;
    $_lusterDonateDir     = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/');

    foreach ([
        ($_lusterDonateIsHttps ? 'https' : 'http') . '://' . $_lusterDonateHost . $_lusterDonateDir,
        ($_lusterDonateIsHttps ? 'http'  : 'https') . '://' . $_lusterDonateHost . $_lusterDonateDir,
    ] as $_lusterDonateUrl) {
        try {
            // Try form URLs first (set explicitly by site owner via Luster settings)
            $_lusterDonateStmt = $_lusterDonateDb->prepare(
                'SELECT fanlisting_id FROM lstr_fanlisting_settings
                 WHERE form_url_join LIKE :url OR form_url_update LIKE :url2
                 LIMIT 1'
            );
            $_lusterDonateStmt->execute(['url' => $_lusterDonateUrl . '%', 'url2' => $_lusterDonateUrl . '%']);
            $_lusterDonateRow = $_lusterDonateStmt->fetch();
            if ($_lusterDonateRow !== false) {
                $_lusterDonateListingId = (int) $_lusterDonateRow['fanlisting_id'];
                break;
            }

            // Also try matching against the listing URL in the owned table — more
            // likely to match a codes page that lives in the listing directory.
            $_lusterDonateStmt2 = $_lusterDonateDb->prepare(
                'SELECT lfs.fanlisting_id
                 FROM lstr_fanlisting_settings lfs
                 JOIN owned o ON o.listingid = lfs.fanlisting_id
                 WHERE o.url LIKE :url
                 LIMIT 1'
            );
            $_lusterDonateStmt2->execute(['url' => $_lusterDonateUrl . '%']);
            $_lusterDonateRow2 = $_lusterDonateStmt2->fetch();
            if ($_lusterDonateRow2 !== false) {
                $_lusterDonateListingId = (int) $_lusterDonateRow2['fanlisting_id'];
                break;
            }
        } catch (\Throwable) {}
    }
}

if ($_lusterDonateListingId <= 0) {
    echo '<!-- Luster compat: could not resolve a listing for show-donate.php. '
       . 'Set $luster_fanlisting_id before including this file. -->';
    return;
}

$luster_fanlisting_id = $_lusterDonateListingId;
require $_lusterDonateRoot . '/public/embed/codes-submit.php';
