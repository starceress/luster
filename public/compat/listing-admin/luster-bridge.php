<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Compatibility bridge for Listing Admin (https://github.com/DudeThatsErin/listingadmin).
 Place this file alongside the Listing Admin show-*.php compatibility wrappers.
 It reads Listing Admin's $listid variable and resolves it to a Luster listing
 ID via the lstr_listing_external_refs table. LUSTER_PATH must be set to the
 absolute server path of your Luster installation.

 LUSTER_LA_SOURCE is only needed if you imported more than one Listing Admin
 install into the same Luster. Set it to this install's Source ID (shown on
 the import's results page) so the bridge only uses this install's listing
 links. Leave it blank otherwise.
*/

if (!defined('LUSTER_PATH')) {
    define('LUSTER_PATH', '%%LUSTER_PATH%%');
}

if (!defined('LUSTER_LA_SOURCE')) {
    define('LUSTER_LA_SOURCE', '');
}

// A listing ID set by hand always wins over auto-detection: $luster_fanlisting_id
// first, then the documented $luster_listing_id override. Checked before the
// lookup below, so a page that picked its listing by hand keeps it even when the
// import has saved a link for its $listid.
if ((!isset($luster_fanlisting_id) || (int) $luster_fanlisting_id === 0) && (int) ($luster_listing_id ?? 0) > 0) {
    $luster_fanlisting_id = (int) $luster_listing_id;
}

// Auto-detect listing ID from Listing Admin's $listid variable
if (!isset($luster_fanlisting_id) || (int) $luster_fanlisting_id === 0) {
    $_lbId = (int) ($listid ?? $listing_id ?? 0);

    if ($_lbId > 0 && is_file(LUSTER_PATH . '/bootstrap.php')) {
        defined('LUSTER_BASE') || define('LUSTER_BASE', LUSTER_PATH);

        /** @var \Luster\Core\Application $_lbApp */
        $_lbApp = require LUSTER_PATH . '/bootstrap.php';

        try {
            $_lbFound = \Luster\Support\ListingAdminBridgeResolver::resolve(
                $_lbApp->getDatabase()->pdo(),
                $_lbId,
                (string) LUSTER_LA_SOURCE,
            );

            if ($_lbFound !== null) {
                $luster_fanlisting_id = $_lbFound;
            }
        } catch (\Throwable) {
        }

        unset($_lbApp, $_lbFound, $_lbId);
    }
}

// Nothing found: the wrappers check for 0 and show "listing not found".
$luster_fanlisting_id = (int) ($luster_fanlisting_id ?? 0);
