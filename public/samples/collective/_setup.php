<?php
/**
 * _setup.php — shared bootstrap for collective sample pages.
 *
 * Set before requiring this file:
 *   $_lusterRoot = dirname(...);  // absolute path to the Luster root directory
 *
 * Variables available after this file runs:
 *   $_sampleApp, $_embedPath, $_siteName, $_ownerName, $_ownerEmail
 *
 * Note: $_siteTagline is NOT set here — define it per-page to your preferred tagline.
 */

defined('LUSTER_BASE') || define('LUSTER_BASE', $_lusterRoot);
require_once $_lusterRoot . '/vendor/autoload.php';

$_embedPath  = $_lusterRoot . '/public/embed';
$_sampleApp  = require $_lusterRoot . '/bootstrap.php';
$_siteName   = (string) ($_sampleApp->getConfig()->get('app_name',    '') ?: 'Collective Name');
$_ownerName  = (string) ($_sampleApp->getConfig()->get('owner_name',  '') ?: 'Your Name');
$_ownerEmail = (string) ($_sampleApp->getConfig()->get('owner_email', '') ?: 'you@example.com');
