<?php

declare(strict_types=1);

/**
 * Luster — CodeSort compatibility shim: show-codes.php
 *
 * Drop-in replacement for CodeSort's show-codes.php include.
 * Routes to Luster's codes embed after resolving which listing
 * the CodeSort $listing variable refers to.
 *
 * Usage (identical to CodeSort's own include):
 *   $listing = 3;  // CodeSort listing ID — matched against Luster listing ID
 *   require '/absolute/path/to/luster/public/compat/codesort/show-codes.php';
 *
 * Resolution order (first match wins):
 *   1. $luster_fanlisting_id  — explicit Luster listing ID set by site owner
 *   2. $listing               — treated as a direct Luster listing ID
 *   3. URL match              — Luster listing whose URL starts with the current page URL
 *
 * If no listing can be resolved, an HTML comment is emitted.
 */

$_lusterCodesRoot = dirname(__DIR__, 3);

if (!is_file($_lusterCodesRoot . '/vendor/autoload.php') || !is_file($_lusterCodesRoot . '/config/app.php')) {
    echo '<!-- Luster compat error: Luster is not installed correctly. -->';
    return;
}

defined('LUSTER_BASE') || define('LUSTER_BASE', $_lusterCodesRoot);

/** @var \Luster\Core\Application $_lusterCompatApp */
$_lusterCompatApp = require $_lusterCodesRoot . '/bootstrap.php';
$_lusterCompatDb  = $_lusterCompatApp->getDatabase();

// ── Resolve listing ID ────────────────────────────────────────────────────────

$_lusterCodesListingId = 0;

// 1. Explicit Luster ID set by the site owner
if (isset($luster_fanlisting_id) && (int) $luster_fanlisting_id > 0) {
    $_lusterCodesListingId = (int) $luster_fanlisting_id;

// 2. CodeSort $listing variable — treat as direct Luster listing ID.
//    This is correct only when CodeSort and Luster listing IDs align (e.g. a
//    fresh Luster install). If IDs diverge after import, set $luster_fanlisting_id
//    explicitly to avoid routing to the wrong listing.
} elseif (isset($listing) && (int) $listing > 0) {
    $_lusterCodesListingId = (int) $listing;

// 3. URL-based fallback — match current page URL against lstr_fanlisting_settings
//    and the owned listing URL. Codes pages typically live in the listing directory,
//    so matching against owned.url is more reliable than form_url_join/update.
} else {
    $_lusterCodesHost    = $_SERVER['HTTP_HOST'] ?? '';
    $_lusterCodesIsHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
                        || (int) ($_SERVER['SERVER_PORT'] ?? 80) === 443;
    $_lusterCodesDir     = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/');

    foreach ([
        ($_lusterCodesIsHttps ? 'https' : 'http') . '://' . $_lusterCodesHost . $_lusterCodesDir,
        ($_lusterCodesIsHttps ? 'http'  : 'https') . '://' . $_lusterCodesHost . $_lusterCodesDir,
    ] as $_lusterCodesUrl) {
        try {
            // Try form URLs first (set explicitly by site owner via Luster settings)
            $_lusterCodesStmt = $_lusterCompatDb->prepare(
                'SELECT fanlisting_id FROM lstr_fanlisting_settings
                 WHERE form_url_join LIKE :url OR form_url_update LIKE :url2
                 LIMIT 1'
            );
            $_lusterCodesStmt->execute(['url' => $_lusterCodesUrl . '%', 'url2' => $_lusterCodesUrl . '%']);
            $_lusterCodesRow = $_lusterCodesStmt->fetch();
            if ($_lusterCodesRow !== false) {
                $_lusterCodesListingId = (int) $_lusterCodesRow['fanlisting_id'];
                break;
            }

            // Also try matching against the listing URL in the owned table — more
            // likely to match a codes page that lives in the listing directory.
            // Three-way match avoids the sibling-path ambiguity of a bare LIKE prefix
            // (e.g. /foo matching /foo-bar). Note: JOINs the unprefixed 'owned' table;
            // enth_fanlistings installs will not benefit from this fallback.
            $_lusterCodesStmt2 = $_lusterCompatDb->prepare(
                'SELECT lfs.fanlisting_id
                 FROM lstr_fanlisting_settings lfs
                 JOIN owned o ON o.listingid = lfs.fanlisting_id
                 WHERE o.url = :url OR o.url = :url_slash OR o.url LIKE :url_prefix
                 LIMIT 1'
            );
            $_lusterCodesStmt2->execute([
                'url'        => $_lusterCodesUrl,
                'url_slash'  => $_lusterCodesUrl . '/',
                'url_prefix' => $_lusterCodesUrl . '/%',
            ]);
            $_lusterCodesRow2 = $_lusterCodesStmt2->fetch();
            if ($_lusterCodesRow2 !== false) {
                $_lusterCodesListingId = (int) $_lusterCodesRow2['fanlisting_id'];
                break;
            }
        } catch (\Throwable) {}
    }
}

if ($_lusterCodesListingId <= 0) {
    echo '<!-- Luster compat: could not resolve a listing for show-codes.php. '
       . 'Set $luster_fanlisting_id before including this file. -->';
    return;
}

$luster_fanlisting_id = $_lusterCodesListingId;
require $_lusterCodesRoot . '/public/embed/codes.php';
