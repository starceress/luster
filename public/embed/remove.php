<?php

declare(strict_types=1);

use Luster\Core\Captcha;
use Luster\Repositories\ListingRepo;
use Luster\Repositories\ListingSettingsRepo;

/**
 * Luster embed — remove membership form fragment.
 *
 * Usage:
 *   $luster_fanlisting_id = 1;     // required: listing ID (int)
 *   $luster_return        = false;  // optional: true = return string, false = echo
 *   require '/absolute/path/to/luster/public/embed/remove.php';
 *
 * The form POSTs to Luster's remove URL. After every outcome (success or error)
 * the user is redirected back to this same host page, where the embed
 * re-renders with a flash success or error message inline.
 *
 * To override the redirect target, set $luster_redirect_url before require.
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
$_lusterRedirectTo = (string) ($luster_redirect_url ?? '');
if ($_lusterRedirectTo === '') {
    $_lusterProto      = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $_lusterRedirectTo = $_lusterProto . '://' . ($_SERVER['HTTP_HOST'] ?? '') . ($_SERVER['REQUEST_URI'] ?? '');
}
$_lusterApp->getSession()->set('lstr_embed_return_to', $_lusterRedirectTo);

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

// ---------------------------------------------------------------------------
// 4. Consume flash data
// ---------------------------------------------------------------------------

$_lusterSession  = $_lusterApp->getSession();
$_lusterSuccess  = (string) $_lusterSession->get('lstr_flash_success_html', '');
$_lusterError    = (string) $_lusterSession->get('lstr_flash_error',        '');
$_lusterOldEmail = (string) $_lusterSession->get('lstr_flash_old_email',    '');
$_lusterSession->remove('lstr_flash_success_html');
$_lusterSession->remove('lstr_flash_error');
$_lusterSession->remove('lstr_flash_old_email');

if ($_lusterSuccess !== '') {
    if ($luster_return ?? false) { return $_lusterSuccess; }
    echo $_lusterSuccess;
    return;
}

// ---------------------------------------------------------------------------
// 5. Render fragment
// ---------------------------------------------------------------------------

$_lusterBaseUrl    = rtrim((string) $_lusterApp->getConfig()->get('embed_base_url', ''), '/')
                   ?: $_lusterApp->getBaseUrl();
$_lusterFormAction = $_lusterBaseUrl . '/remove/' . $_lusterListingId;

$_lusterSettingsRepo  = new ListingSettingsRepo($_lusterApp->getDatabase());
$_lusterEmbedSettings = $_lusterSettingsRepo->getEmbedSettings($_lusterListingId);

// $luster_lost_password_url can be set before require to override the DB-configured value.
if (isset($luster_lost_password_url)) {
    $_lusterLostPasswordUrl = (string) $luster_lost_password_url;
} else {
    $_lusterResetPage       = (string) ($_lusterEmbedSettings['form_url_reset'] ?? '');
    $_lusterLostPasswordUrl = $_lusterResetPage !== ''
        ? rtrim((string) $_lusterListing->url, '/') . '/' . ltrim($_lusterResetPage, '/')
        : '';
}

$_lusterCaptchaQ   = Captcha::getQuestion('remove');
$_lusterWrapperTpl = $_lusterSettingsRepo->getWrapperTemplate($_lusterListingId) ?? '';
$_lusterFb         = $_lusterApp->getFormFieldBuilder();
$_lusterF          = $_lusterFb->build([
    ['type' => 'email',    'name' => 'email',          'id' => 'rem-email',    'label' => 'Email',    'required' => true, 'value' => $_lusterOldEmail, 'attrs' => ['maxlength' => '255', 'autocomplete' => 'email']],
    ['type' => 'password', 'name' => 'password',       'id' => 'rem-password', 'label' => 'Password', 'required' => true, 'attrs' => ['maxlength' => '255', 'autocomplete' => 'current-password']],
    ['type' => 'text',     'name' => 'captcha_answer', 'id' => 'rem-captcha',  'label' => 'Captcha: ' . $_lusterCaptchaQ, 'required' => true, 'attrs' => ['maxlength' => '10', 'autocomplete' => 'off']],
], $_lusterWrapperTpl);

$_lusterHtml = $_lusterApp->getView()->renderFragment('remove.php', [
    'listing'           => $_lusterListing,
    'listing_name'      => $_lusterListing->title,
    'listing_url'       => $_lusterListing->url,
    'page_title'        => 'Remove Membership',
    'form_action'       => $_lusterFormAction,
    'csrf_token'        => $_lusterApp->getCSRF()->token(),
    'errors'            => $_lusterError !== ''
        ? '<p>' . htmlspecialchars($_lusterError, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>'
        : '',
    'lost_password_url' => $_lusterLostPasswordUrl,
    'f'                 => $_lusterF,
    'base_url'          => $_lusterApp->getBaseUrl(),
    'theme_path'        => $_lusterApp->getBaseUrl() . '/assets/default/',
]);

if ($luster_return ?? false) {
    return $_lusterHtml;
}

echo $_lusterHtml;
