<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Compatibility bridge for Enthusiast (https://github.com/DudeThatsErin/enth).
 Place this file alongside the Enthusiast show_*.php compatibility wrappers.
 It reads Enthusiast's $listing variable (a dbtable name or numeric listing ID
 set by the fanlisting page's config.php) and resolves it to a Luster listing ID.
 LUSTER_PATH must be set to the absolute server path of your Luster installation.
*/

if (!defined('LUSTER_PATH')) {
    define('LUSTER_PATH', '%%LUSTER_PATH%%');
}

// Auto-detect listing ID from Enthusiast's $listing variable.
// In unprefixed Enthusiast, $listing is a numeric listingid (e.g. 10).
// In some setups, $listing is a dbtable string (e.g. 'huntrix').
// The bridge handles both.
if (!isset($luster_fanlisting_id) || (int) $luster_fanlisting_id === 0) {
    $_lbRaw = $listing ?? '';

    if ($_lbRaw !== '' && is_file(LUSTER_PATH . '/bootstrap.php')) {
        defined('LUSTER_BASE') || define('LUSTER_BASE', LUSTER_PATH);

        /** @var \Luster\Core\Application $_lbApp */
        $_lbApp = require LUSTER_PATH . '/bootstrap.php';

        if (is_numeric($_lbRaw) && (int) $_lbRaw > 0) {
            // Numeric: treat as a direct listing ID
            $luster_fanlisting_id = (int) $_lbRaw;
        } else {
            // String: look up by dbtable name
            try {
                $_lbDb   = $_lbApp->getDatabase();
                $_lbStmt = $_lbDb->prepare(
                    'SELECT listingid FROM `' . $_lbDb->validatePrefix('owned') . '` WHERE dbtable = :tbl LIMIT 1'
                );
                $_lbStmt->execute(['tbl' => (string) $_lbRaw]);
                $_lbRow = $_lbStmt->fetch(\PDO::FETCH_ASSOC);

                if ($_lbRow !== false) {
                    $luster_fanlisting_id = (int) $_lbRow['listingid'];
                }
            } catch (\Throwable) {
            }
        }

        unset($_lbApp, $_lbDb, $_lbStmt, $_lbRow, $_lbRaw);
    }
}

// Manual override fallbacks (when $listing is not available)
if (!isset($luster_fanlisting_id) || (int) $luster_fanlisting_id === 0) {
    $luster_fanlisting_id = (int) ($luster_listing_id ?? $listing_id_number ?? $listing_id ?? 0);
}
