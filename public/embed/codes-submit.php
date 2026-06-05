<?php

declare(strict_types=1);

use Luster\Core\Captcha;
use Luster\Repositories\CodesRepo;
use Luster\Repositories\ListingRepo;

/**
 * Luster embed — fan code submission form fragment.
 *
 * Drop into any PHP page:
 *   $luster_fanlisting_id = 1;
 *   $luster_return        = true;  // true = return string, false/omit = echo
 *   echo require '/path/to/luster/public/embed/codes-submit.php';
 *
 * The form POSTs to Luster's public /codes/submit/{id} route. On success,
 * Luster redirects back to this page and the embed automatically shows the
 * confirmation message from the session flash.
 */

// ---------------------------------------------------------------------------
// 1. Validate required variable
// ---------------------------------------------------------------------------

$_lusterListingId = (int) ($luster_fanlisting_id ?? 0);

if ($_lusterListingId === 0) {
    $_lusterHtml = '<p class="fl-errors">Luster embed error: <code>$luster_fanlisting_id</code> is not set or invalid.</p>';
    if ($luster_return ?? false) {
        return $_lusterHtml;
    }
    echo $_lusterHtml;
    return;
}

// ---------------------------------------------------------------------------
// 2. Bootstrap Luster
// ---------------------------------------------------------------------------

$_lusterRoot = dirname(__DIR__, 2); // public/embed/ → public/ → project root

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
$_lusterApp = $_lusterApp ?? require $_lusterRoot . '/bootstrap.php';
$_lusterApp->getSession()->start();

// ---------------------------------------------------------------------------
// 3. Load listing
// ---------------------------------------------------------------------------

$_lusterDb          = $_lusterApp->getDatabase();
$_lusterConfig      = $_lusterApp->getConfig();
$_lusterListingRepo = new ListingRepo($_lusterDb, $_lusterConfig);
$_lusterListing     = $_lusterListingRepo->getById($_lusterListingId);

if ($_lusterListing === null) {
    $_lusterHtml = '<p class="fl-errors">Luster embed error: listing #' . $_lusterListingId . ' not found.</p>';
    if ($luster_return ?? false) {
        return $_lusterHtml;
    }
    echo $_lusterHtml;
    return;
}

$_lusterEmbedCfg = (new \Luster\Repositories\ListingSettingsRepo($_lusterDb))->getEmbedSettings($_lusterListingId);
if (!($_lusterEmbedCfg['codes_submit_enabled'] ?? true)) {
    if ($luster_return ?? false) { return ''; }
    return;
}

$_lusterSession = $_lusterApp->getSession();
$_lusterBaseUrl   = rtrim($_lusterApp->getBaseUrl(), '/');
$_lusterEmbedBase = rtrim((string) $_lusterConfig->get('embed_base_url', ''), '/');
if ($_lusterEmbedBase === '') {
    $_lusterEmbedBase = $_lusterBaseUrl;
}

// Build form action — POSTs to Luster's public route
$_lusterFormAction = $_lusterEmbedBase . '/codes/submit/' . $_lusterListingId;

// ---------------------------------------------------------------------------
// 4. Consume flash
// ---------------------------------------------------------------------------

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
// 5. Build captcha for the form
// ---------------------------------------------------------------------------

$_lusterCaptchaQuestion = Captcha::getQuestion('code_submit');

// ---------------------------------------------------------------------------
// 6. Render fragment
// ---------------------------------------------------------------------------

$_lusterHtml = $_lusterApp->getView()->renderFragment('codes-submit.php', [
    'listing'          => $_lusterListing,
    'listing_name'     => $_lusterListing->name,
    'listing_url'      => $_lusterListing->url ?? '',
    'form_action'      => $_lusterFormAction,
    'csrf_token'       => $_lusterApp->getCSRF()->token(),
    'errors'           => htmlspecialchars($_lusterError, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
    'old'              => $_lusterOld,
    'captcha_question' => $_lusterCaptchaQuestion,
    'captcha_error'    => '',
    'submitted'        => false,
    'base_url'         => $_lusterBaseUrl,
]);

if ($luster_return ?? false) {
    return $_lusterHtml;
}

echo $_lusterHtml;
