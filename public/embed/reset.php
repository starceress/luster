<?php

declare(strict_types=1);

use Luster\Core\Captcha;
use Luster\Repositories\ListingRepo;
use Luster\Repositories\FanPasswordResetRepo;

/**
 * Luster embed — password reset fragment.
 *
 * Handles both the email-entry step and the confirm-new-password step,
 * determined by the presence of $luster_reset_step.
 *
 * Usage — email entry form (default):
 *   $luster_fanlisting_id = 1;        // required: listing ID (int)
 *   $luster_return        = false;     // optional: true = return string, false = echo
 *   require '/absolute/path/to/luster/public/embed/reset.php';
 *
 * Usage — new password form (link from reset email):
 *   $luster_fanlisting_id = 1;
 *   $luster_reset_step    = 'confirm'; // drives the confirm form
 *   $luster_return        = false;
 *   require '/absolute/path/to/luster/public/embed/reset.php';
 *
 * In both cases the form POSTs to Luster's URL. After every outcome (success
 * or error) the user is redirected back to this same host page, where the
 * embed re-renders with a flash success or error message inline.
 *
 * To override the redirect target, set $luster_redirect_url before require.
 *
 * The ?token= query parameter is read automatically from $_GET when
 * $luster_reset_step = 'confirm'.
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

$_lusterStep = (string) ($luster_reset_step ?? 'request'); // 'request' or 'confirm'

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
// 4. Render the appropriate step
// ---------------------------------------------------------------------------

$_lusterBaseUrl = rtrim((string) $_lusterApp->getConfig()->get('embed_base_url', ''), '/')
                ?: $_lusterApp->getBaseUrl();

if ($_lusterStep === 'confirm') {
    // ── Confirm step: validate token, show new-password form ──────────────

    $_lusterRawToken = trim((string) ($_GET['token'] ?? ''));
    $_lusterError    = '';
    $_lusterTokenValid = false;

    if ($_lusterRawToken === '') {
        $_lusterError = 'Missing or invalid reset link.';
    } else {
        $_lusterResetRepo = new FanPasswordResetRepo($_lusterApp->getDatabase());
        $_lusterTokenRow  = $_lusterResetRepo->findValidByToken(hash('sha256', $_lusterRawToken));

        if ($_lusterTokenRow === null || (int) $_lusterTokenRow['fanlisting_id'] !== $_lusterListingId) {
            $_lusterError = 'This reset link has expired or is invalid. Please request a new one.';
        } else {
            $_lusterTokenValid = true;
        }
    }

    $_lusterHtml = $_lusterApp->getView()->renderFragment('reset_confirm.php', [
        'listing'      => $_lusterListing,
        'listing_name' => $_lusterListing->title,
        'listing_url'  => $_lusterListing->url,
        'page_title'      => 'Set New Password',
        'form_action'     => $_lusterBaseUrl . '/reset/' . $_lusterListingId . '/confirm',
        'reset_url'       => $_lusterBaseUrl . '/reset/' . $_lusterListingId,
        'csrf_token'      => $_lusterApp->getCSRF()->token(),
        'errors'          => $_lusterError !== ''
            ? '<p>' . htmlspecialchars($_lusterError, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>'
            : '',
        'token'           => $_lusterTokenValid ? $_lusterRawToken : '',
        'token_valid'     => $_lusterTokenValid,
        'base_url'        => $_lusterApp->getBaseUrl(),
        'theme_path'      => $_lusterApp->getBaseUrl() . '/assets/default/',
    ]);
} else {
    // ── Request step: email entry form ─────────────────────────────────────

    $_lusterSession  = $_lusterApp->getSession();
    $_lusterError    = (string) $_lusterSession->get('lstr_flash_error', '');
    $_lusterOldEmail = (string) $_lusterSession->get('lstr_flash_old_email', '');
    $_lusterSession->remove('lstr_flash_error');
    $_lusterSession->remove('lstr_flash_old_email');

    $_lusterHtml = $_lusterApp->getView()->renderFragment('reset.php', [
        'listing'       => $_lusterListing,
        'listing_name'  => $_lusterListing->title,
        'listing_url'   => $_lusterListing->url,
        'page_title'       => 'Reset Password',
        'form_action'      => $_lusterBaseUrl . '/reset/' . $_lusterListingId,
        'csrf_token'       => $_lusterApp->getCSRF()->token(),
        'errors'           => $_lusterError !== ''
            ? '<p>' . htmlspecialchars($_lusterError, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>'
            : '',
        'field_email'      => $_lusterOldEmail,
        'captcha_question' => Captcha::getQuestion('reset'),
        'captcha_error'    => '',
        'base_url'         => $_lusterApp->getBaseUrl(),
        'theme_path'       => $_lusterApp->getBaseUrl() . '/assets/default/',
    ]);
}

if ($luster_return ?? false) {
    return $_lusterHtml;
}

echo $_lusterHtml;
