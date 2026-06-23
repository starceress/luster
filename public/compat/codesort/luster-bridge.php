<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Compatibility bridge for CodeSort (https://gitlab.com/tfl-php-scripts/codesort).
 Place this file alongside the CodeSort show-codes.php and show-donate.php
 compatibility wrappers. It reads CodeSort's $listing variable and resolves it
 to a Luster listing ID via the lstr_listing_external_refs table. LUSTER_PATH
 must be set to the absolute server path of your Luster installation.
*/

if (!defined('LUSTER_PATH')) {
    define('LUSTER_PATH', '%%LUSTER_PATH%%');
}

// Auto-detect listing ID from CodeSort's $listing variable
if (!isset($luster_fanlisting_id) || (int) $luster_fanlisting_id === 0) {
    $_lbId = (int) ($listing ?? 0);

    if ($_lbId > 0 && is_file(LUSTER_PATH . '/bootstrap.php')) {
        defined('LUSTER_BASE') || define('LUSTER_BASE', LUSTER_PATH);

        /** @var \Luster\Core\Application $_lbApp */
        $_lbApp = require LUSTER_PATH . '/bootstrap.php';

        try {
            $_lbDb   = $_lbApp->getDatabase();
            $_lbStmt = $_lbDb->prepare(
                'SELECT listing_id FROM lstr_listing_external_refs
                 WHERE source = :src AND external_id = :eid LIMIT 1'
            );
            $_lbStmt->execute(['src' => 'codesort', 'eid' => (string) $_lbId]);
            $_lbRow = $_lbStmt->fetch(\PDO::FETCH_ASSOC);

            if ($_lbRow !== false) {
                $luster_fanlisting_id = (int) $_lbRow['listing_id'];
            }
        } catch (\Throwable) {
        }

        unset($_lbApp, $_lbDb, $_lbStmt, $_lbRow, $_lbId);
    }
}

// Manual override fallback
if (!isset($luster_fanlisting_id) || (int) $luster_fanlisting_id === 0) {
    $luster_fanlisting_id = (int) ($luster_listing_id ?? 0);
}
