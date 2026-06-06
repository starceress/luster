<?php

declare(strict_types=1);

use Luster\Core\Captcha;
use Luster\Core\View;
use Luster\Repositories\ListingRepo;
use Luster\Repositories\ListingSettingsRepo;

/**
 * Luster embed — per-listing contact form fragment.
 *
 * Usage:
 *   $luster_fanlisting_id = 1;     // required: listing ID (int)
 *   $luster_return        = false;  // optional: true = return string, false = echo
 *   require '/absolute/path/to/luster/public/embed/contact.php';
 *
 * Override variables (set before require):
 *   $luster_contact_action  — override the form POST URL (useful when embedding on an external page)
 */

// ---------------------------------------------------------------------------
// 1. Validate required variable
// ---------------------------------------------------------------------------

$_lusterListingId = (int) ($luster_fanlisting_id ?? 0);

if ($_lusterListingId === 0) {
    $_lusterHtml = '<p class="fl-errors">Luster embed error: <code>$luster_fanlisting_id</code> is not set.</p>';
    if ($luster_return ?? false) { return $_lusterHtml; }
    echo $_lusterHtml;
    return;
}

// ---------------------------------------------------------------------------
// 2. Bootstrap Luster
// ---------------------------------------------------------------------------

$_lusterRoot = dirname(__DIR__, 2);

if (!is_file($_lusterRoot . '/vendor/autoload.php') || !is_file($_lusterRoot . '/config/app.php')) {
    $_lusterHtml = '<p class="fl-errors">Luster embed error: Luster is not installed correctly.</p>';
    if ($luster_return ?? false) { return $_lusterHtml; }
    echo $_lusterHtml;
    return;
}

defined('LUSTER_BASE') || define('LUSTER_BASE', $_lusterRoot);

/** @var \Luster\Core\Application $_lusterApp */
$_lusterApp = require $_lusterRoot . '/bootstrap.php';
$_lusterApp->getSession()->start();

// ---------------------------------------------------------------------------
// 3. Load listing and contact settings
// ---------------------------------------------------------------------------

$_lusterListingRepo  = new ListingRepo($_lusterApp->getDatabase(), $_lusterApp->getConfig());
$_lusterListing      = $_lusterListingRepo->getById($_lusterListingId);

if ($_lusterListing === null) {
    $_lusterHtml = '<!-- Luster: listing #' . $_lusterListingId . ' not found -->';
    if ($luster_return ?? false) { return $_lusterHtml; }
    echo $_lusterHtml;
    return;
}

$_lusterSettingsRepo    = new ListingSettingsRepo($_lusterApp->getDatabase());
$_lusterContactSettings = $_lusterSettingsRepo->getContactSettings($_lusterListingId);

if (!$_lusterContactSettings['enabled']) {
    $_lusterHtml = '';
    if ($luster_return ?? false) { return $_lusterHtml; }
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
// 5. Build form data and render
// ---------------------------------------------------------------------------

$_lusterBaseUrl    = $_lusterApp->getBaseUrl();
$_lusterFormAction = isset($luster_contact_action)
    ? (string) $luster_contact_action
    : $_lusterBaseUrl . '/contact/' . $_lusterListingId;

$_lusterData = [
    'listing'          => $_lusterListing,
    'listing_name'     => $_lusterListing->title,
    'listing_url'      => $_lusterListing->url,
    'page_title'       => 'Contact',
    'base_url'         => $_lusterBaseUrl,
    'theme_path'       => rtrim($_lusterBaseUrl, '/') . '/assets/default',
    'form_action'      => $_lusterFormAction,
    'csrf_token'       => $_lusterApp->getCSRF()->token(),
    'errors'           => $_lusterError !== '' ? View::e($_lusterError) : '',
    'captcha_question' => Captcha::getQuestion('contact_listing'),
    'captcha_error'    => '',
    'old'              => $_lusterOld,
    'field_name'       => View::e($_lusterOld['name']    ?? ''),
    'field_email'      => View::e($_lusterOld['email']   ?? ''),
    'field_url'        => View::e($_lusterOld['url']     ?? ''),
    'field_subject'    => View::e($_lusterOld['subject'] ?? ''),
    'field_message'    => View::e($_lusterOld['message'] ?? ''),
];

$_lusterHtml = $_lusterApp->getView()->renderFragment('contact.php', $_lusterData);

if ($luster_return ?? false) { return $_lusterHtml; }
echo $_lusterHtml;
