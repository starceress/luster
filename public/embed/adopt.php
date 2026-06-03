<?php

declare(strict_types=1);

use Luster\Core\Captcha;
use Luster\Repositories\ListingRepo;
use Luster\Repositories\ListingSettingsRepo;

/**
 * Luster embed — adoption application form fragment.
 *
 * Drop this into any PHP page to embed the adoption form without Luster's layout.
 * The listing must have "Available for adoption" enabled in its settings.
 *
 * Usage:
 *   $luster_fanlisting_id = 1;     // required: listing ID (int)
 *   $luster_return        = false;  // optional: true = return string, false = echo
 *   require '/absolute/path/to/luster/public/embed/adopt.php';
 */

// ---------------------------------------------------------------------------
// 1. Validate the required variable
// ---------------------------------------------------------------------------

$_lusterListingId = (int) ($luster_fanlisting_id ?? 0);

if ($_lusterListingId === 0) {
    $_lusterHtml = '<p class="fl-errors">Luster embed error: <code>$luster_fanlisting_id</code> is not set.</p>';
    if ($luster_return ?? false) {
        return $_lusterHtml;
    }
    echo $_lusterHtml;
    return;
}

// ---------------------------------------------------------------------------
// 2. Bootstrap Luster
// ---------------------------------------------------------------------------

$_lusterRoot = dirname(__DIR__, 2);

if (!is_file($_lusterRoot . '/vendor/autoload.php') || !is_file($_lusterRoot . '/config/app.php')) {
    $_lusterHtml = '<p class="fl-errors">Luster embed error: Luster is not installed correctly at <code>'
        . htmlspecialchars($_lusterRoot, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
        . '</code>.</p>';
    if ($luster_return ?? false) {
        return $_lusterHtml;
    }
    echo $_lusterHtml;
    return;
}

defined('LUSTER_BASE') || define('LUSTER_BASE', $_lusterRoot);

/** @var \Luster\Core\Application $_lusterApp */
$_lusterApp = require $_lusterRoot . '/bootstrap.php';
$_lusterApp->getSession()->start();

// ---------------------------------------------------------------------------
// 3. Load listing and check adoption flag
// ---------------------------------------------------------------------------

$_lusterListingRepo = new ListingRepo($_lusterApp->getDatabase(), $_lusterApp->getConfig());
$_lusterListing     = $_lusterListingRepo->getById($_lusterListingId);

if ($_lusterListing === null) {
    $_lusterHtml = '<p class="fl-errors">Luster embed error: listing #' . $_lusterListingId . ' not found.</p>';
    if ($luster_return ?? false) {
        return $_lusterHtml;
    }
    echo $_lusterHtml;
    return;
}

$_lusterSettingsRepo = new ListingSettingsRepo($_lusterApp->getDatabase());
if (!$_lusterSettingsRepo->getForAdoption($_lusterListingId)) {
    $_lusterHtml = '<p class="fl-errors">Luster embed error: listing #' . $_lusterListingId . ' is not accepting adoption applications.</p>';
    if ($luster_return ?? false) {
        return $_lusterHtml;
    }
    echo $_lusterHtml;
    return;
}

// ---------------------------------------------------------------------------
// 4. Consume flash data
// ---------------------------------------------------------------------------

$_lusterSession = $_lusterApp->getSession();
$_lusterSuccess = (string) $_lusterSession->get('lstr_flash_success', '');
$_lusterError   = (string) $_lusterSession->get('lstr_flash_error',   '');
$_lusterOld     = (array)  $_lusterSession->get('lstr_flash_old',     []);
$_lusterSession->remove('lstr_flash_success');
$_lusterSession->remove('lstr_flash_error');
$_lusterSession->remove('lstr_flash_old');

if ($_lusterSuccess !== '') {
    $_lusterHtml = '<div class="fl-success"><p>'
        . htmlspecialchars($_lusterSuccess, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
        . '</p></div>';
    if ($luster_return ?? false) { return $_lusterHtml; }
    echo $_lusterHtml;
    return;
}

// ---------------------------------------------------------------------------
// 5. Render fragment
// ---------------------------------------------------------------------------

$_lusterBaseUrl    = rtrim((string) $_lusterApp->getConfig()->get('embed_base_url', ''), '/')
                   ?: $_lusterApp->getBaseUrl();
$_lusterFormAction = $_lusterBaseUrl . '/adopt/' . $_lusterListingId;

$_lusterHtml = $_lusterApp->getView()->renderFragment('adopt.php', [
    'listing'          => $_lusterListing,
    'listing_name'     => $_lusterListing->name,
    'listing_url'      => $_lusterListing->url,
    'page_title'       => 'Adopt ' . $_lusterListing->name,
    'form_action'      => $_lusterFormAction,
    'csrf_token'       => $_lusterApp->getCSRF()->token(),
    'errors'           => $_lusterError !== ''
        ? '<p>' . htmlspecialchars($_lusterError, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>'
        : '',
    'field_name'       => (string) ($_lusterOld['name']    ?? ''),
    'field_email'      => (string) ($_lusterOld['email']   ?? ''),
    'field_url'        => (string) ($_lusterOld['url']     ?? ''),
    'field_message'    => (string) ($_lusterOld['message'] ?? ''),
    'captcha_question' => Captcha::getQuestion('adopt'),
    'captcha_error'    => '',
    'base_url'         => $_lusterApp->getBaseUrl(),
    'theme_path'       => $_lusterApp->getBaseUrl() . '/assets/default/',
]);

if ($luster_return ?? false) {
    return $_lusterHtml;
}

echo $_lusterHtml;
