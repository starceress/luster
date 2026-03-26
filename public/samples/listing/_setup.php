<?php
/**
 * _setup.php — shared bootstrap for listing sample pages.
 *
 * Set before requiring this file:
 *   const LISTING_ID = 1;         // your listing's numeric ID
 *   $_lusterRoot = dirname(...);   // absolute path to the Luster root directory
 *
 * Variables available after this file runs:
 *   $_sampleApp, $_sampleL, $_embedPath
 *   $_siteName, $_flSubject, $_flUrl, $_ownerName, $_ownerEmail
 */

defined('LUSTER_BASE') || define('LUSTER_BASE', $_lusterRoot);
require_once $_lusterRoot . '/vendor/autoload.php';

$_embedPath = $_lusterRoot . '/public/embed';
$_sampleApp = require $_lusterRoot . '/bootstrap.php';
$_sampleL   = (new \Luster\Repositories\ListingRepo(
    $_sampleApp->getDatabase(),
    $_sampleApp->getConfig(),
))->getById(LISTING_ID);

$_siteName   = $_sampleL?->name    ?? 'Listing Name';
$_flSubject  = $_sampleL?->subject ?? 'Subject Name';
$_flUrl      = $_sampleL?->url     ?? '#';
$_ownerName  = (string) ($_sampleApp->getConfig()->get('owner_name',  '') ?: 'Your Name');
$_ownerEmail = (string) ($_sampleApp->getConfig()->get('owner_email', '') ?: 'you@example.com');
