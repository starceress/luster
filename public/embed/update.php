<?php

declare(strict_types=1);

use Luster\Core\Captcha;
use Luster\Core\Countries;
use Luster\Repositories\ListingRepo;
use Luster\Repositories\ListingSettingsRepo;
use Luster\Repositories\FieldRepo;

/**
 * Luster embed — update membership form fragment.
 *
 * Usage:
 *   $luster_fanlisting_id = 1;     // required: listing ID (int)
 *   $luster_return        = false;  // optional: true = return string, false = echo
 *   require '/absolute/path/to/luster/public/embed/update.php';
 *
 * The form POSTs to Luster's update URL. On success the user is sent to the
 * post-update redirect URL from listing settings, or Luster's success page.
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
    $_lusterHtml = '<p class="fl-errors">Luster embed error: Luster is not installed correctly.</p>';
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
// 3. Load listing data
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

$_lusterFieldRepo     = new FieldRepo($_lusterApp->getDatabase(), $_lusterApp->getConfig());
$_lusterFields        = $_lusterFieldRepo->getFieldsForListing($_lusterListingId);
$_lusterSettingsRepo  = new ListingSettingsRepo($_lusterApp->getDatabase());
$_lusterEmbedSettings = $_lusterSettingsRepo->getEmbedSettings($_lusterListingId);

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
$_lusterFormAction = $_lusterBaseUrl . '/update/' . $_lusterListingId;

// Build "Forgot your password?" URL.
// $luster_lost_password_url can be set before require to override the DB-configured value.
if (isset($luster_lost_password_url)) {
    $_lusterLostPasswordUrl = (string) $luster_lost_password_url;
} else {
    $_lusterResetPage       = (string) ($_lusterEmbedSettings['form_url_reset'] ?? '');
    $_lusterLostPasswordUrl = $_lusterResetPage !== ''
        ? rtrim((string) $_lusterListing->url, '/') . '/' . ltrim($_lusterResetPage, '/')
        : '';
}

$_lusterHtml = $_lusterApp->getView()->renderFragment('update.php', [
    'listing'            => $_lusterListing,
    'listing_name'       => $_lusterListing->name,
    'listing_url'        => $_lusterListing->url,
    'page_title'            => 'Update Membership',
    'form_action'           => $_lusterFormAction,
    'csrf_token'            => $_lusterApp->getCSRF()->token(),
    'errors'                => $_lusterError !== ''
        ? '<p>' . htmlspecialchars($_lusterError, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>'
        : '',
    'update_error'          => '',
    'field_email'           => (string) ($_lusterOld['email']     ?? ''),
    'field_name'            => (string) ($_lusterOld['new_name']  ?? ''),
    'field_new_email'       => (string) ($_lusterOld['new_email'] ?? ''),
    'field_url'             => (string) ($_lusterOld['url']       ?? ''),
    'country_select'        => Countries::buildSelect((string) ($_lusterOld['country'] ?? '')),
    'country_field_enabled' => true,
    'fields'                => $_lusterFields,
    'old'                   => $_lusterOld,
    'captcha_question'      => Captcha::getQuestion('update'),
    'captcha_error'         => '',
    'lost_password_url'     => $_lusterLostPasswordUrl,
    'base_url'              => $_lusterApp->getBaseUrl(),
    'theme_path'            => $_lusterApp->getBaseUrl() . '/assets/default/',
]);

if ($luster_return ?? false) {
    return $_lusterHtml;
}

echo $_lusterHtml;
