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
 * Optional overrides:
 *   $luster_return_url  — URL to redirect to after a successful submission
 *                         (default: same URL with ?submitted=1 appended)
 *
 * The form POSTs to Luster's public /codes/submit/{id} route, which processes
 * the upload and redirects to /codes/submit/{id}/thanks on success.
 * The thank-you state is shown in the embed by passing ?submitted=1.
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
$_lusterApp = require $_lusterRoot . '/bootstrap.php';
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

$_lusterSession = $_lusterApp->getSession();
$_lusterBaseUrl = rtrim($_lusterApp->getBaseUrl(), '/');
$_lusterEmbedBase = !empty($_lusterConfig['embed_base_url'])
    ? rtrim((string) $_lusterConfig['embed_base_url'], '/')
    : $_lusterBaseUrl;

// Build form action — POSTs to Luster's public route
$_lusterFormAction = $_lusterEmbedBase . '/codes/submit/' . $_lusterListingId;

// ---------------------------------------------------------------------------
// 4. Consume flash
// ---------------------------------------------------------------------------

$_lusterError = (string) $_lusterSession->get('lstr_flash_error', '');
$_lusterOld   = (array)  $_lusterSession->get('lstr_flash_old',   []);
$_lusterSession->remove('lstr_flash_error');
$_lusterSession->remove('lstr_flash_old');

// ---------------------------------------------------------------------------
// 5. Detect submitted state (after redirect back with ?submitted=1)
// ---------------------------------------------------------------------------

$_lusterSubmitted = isset($_GET['submitted']) && (string) $_GET['submitted'] === '1';

$_lusterCaptchaQuestion = $_lusterSubmitted ? '' : Captcha::getQuestion('code_submit');

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
    'submitted'        => $_lusterSubmitted,
    'base_url'         => $_lusterBaseUrl,
]);

if ($luster_return ?? false) {
    return $_lusterHtml;
}

echo $_lusterHtml;
