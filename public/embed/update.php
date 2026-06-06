<?php

declare(strict_types=1);

use Luster\Core\Captcha;
use Luster\Core\Countries;
use Luster\Repositories\FieldRepo;
use Luster\Repositories\ListingRepo;
use Luster\Repositories\ListingSettingsRepo;
use Luster\Repositories\MemberRepo;

/**
 * Luster embed — update membership form fragment.
 *
 * Two-step flow: shows a sign-in form first; after authentication (handled by
 * POST /update/{id}/sign-in in the controller) the session key
 * lstr_update_authed_{id} is set and this embed renders the full update form
 * pre-filled with the member's current values.
 *
 * Usage:
 *   $luster_fanlisting_id = 1;     // required: listing ID (int)
 *   $luster_return        = false;  // optional: true = return string, false = echo
 *   require '/absolute/path/to/luster/public/embed/update.php';
 *
 * Override variables:
 *   $luster_lost_password_url  — override the "Forgot your password?" link URL
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
// 5. Determine auth state
// ---------------------------------------------------------------------------

$_lusterBaseUrl    = rtrim((string) $_lusterApp->getConfig()->get('embed_base_url', ''), '/')
                   ?: $_lusterApp->getBaseUrl();
$_lusterAuthKey    = 'lstr_update_authed_' . $_lusterListingId;
$_lusterAuthedEmail = (string) $_lusterSession->get($_lusterAuthKey, '');
$_lusterSignedIn   = $_lusterAuthedEmail !== '';

// Validate the session email still exists in the DB
if ($_lusterSignedIn) {
    $_lusterMemberRepo = new MemberRepo($_lusterApp->getDatabase(), $_lusterApp->getConfig());
    $_lusterMember     = $_lusterMemberRepo->getByEmail($_lusterListing->dbprefix, $_lusterAuthedEmail);
    if ($_lusterMember === null) {
        $_lusterSession->remove($_lusterAuthKey);
        $_lusterSignedIn = false;
    }
}

// ---------------------------------------------------------------------------
// 6. Build "Forgot your password?" URL (step 1 only)
// ---------------------------------------------------------------------------

if (isset($luster_lost_password_url)) {
    $_lusterLostPasswordUrl = (string) $luster_lost_password_url;
} else {
    $_lusterResetPage       = (string) ($_lusterEmbedSettings['form_url_reset'] ?? '');
    $_lusterLostPasswordUrl = $_lusterResetPage !== ''
        ? rtrim((string) $_lusterListing->url, '/') . '/' . ltrim($_lusterResetPage, '/')
        : '';
}

// ---------------------------------------------------------------------------
// 7. Render fragment
// ---------------------------------------------------------------------------

if (!$_lusterSignedIn) {

    // ── Step 1: sign-in form ────────────────────────────────────────────────
    $_lusterHtml = $_lusterApp->getView()->renderFragment('update.php', [
        'listing'          => $_lusterListing,
        'listing_name'     => $_lusterListing->title,
        'listing_url'      => $_lusterListing->url,
        'page_title'       => 'Update Membership',
        'signed_in'        => false,
        'sign_in_action'   => $_lusterBaseUrl . '/update/' . $_lusterListingId . '/sign-in',
        'sign_out_url'     => '',
        'form_action'      => '',
        'csrf_token'       => $_lusterApp->getCSRF()->token(),
        'errors'           => $_lusterError !== ''
            ? '<p>' . htmlspecialchars($_lusterError, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>'
            : '',
        'update_error'     => '',
        'field_email'      => (string) ($_lusterOld['email'] ?? ''),
        'field_name'       => '',
        'field_new_email'  => '',
        'field_url'        => '',
        'country_select'   => Countries::buildSelect(''),
        'fields'           => [],
        'old'              => [],
        'captcha_question' => '',
        'captcha_error'    => '',
        'lost_password_url' => $_lusterLostPasswordUrl,
        'current_name'     => '',
        'current_url'      => '',
        'current_country'  => '',
        'current_extra'    => [],
        'base_url'         => $_lusterApp->getBaseUrl(),
        'theme_path'       => $_lusterApp->getBaseUrl() . '/assets/default/',
    ]);

} else {

    // ── Step 2: update form (authenticated) ─────────────────────────────────
    $_lusterFieldRepo = new FieldRepo($_lusterApp->getDatabase(), $_lusterApp->getConfig());
    $_lusterFields    = $_lusterFieldRepo->getFieldsForListing($_lusterListingId);

    $_lusterHtml = $_lusterApp->getView()->renderFragment('update.php', [
        'listing'          => $_lusterListing,
        'listing_name'     => $_lusterListing->title,
        'listing_url'      => $_lusterListing->url,
        'page_title'       => 'Update Membership',
        'signed_in'        => true,
        'sign_in_action'   => '',
        'sign_out_url'     => $_lusterBaseUrl . '/update/' . $_lusterListingId . '/sign-out',
        'form_action'      => $_lusterBaseUrl . '/update/' . $_lusterListingId,
        'csrf_token'       => $_lusterApp->getCSRF()->token(),
        'errors'           => $_lusterError !== ''
            ? '<p>' . htmlspecialchars($_lusterError, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>'
            : '',
        'update_error'     => '',
        'field_email'      => $_lusterMember->email,
        'field_name'       => (string) ($_lusterOld['new_name']  ?? ''),
        'field_new_email'  => (string) ($_lusterOld['new_email'] ?? ''),
        'field_url'        => (string) ($_lusterOld['url']       ?? ''),
        'country_select'   => Countries::buildSelect((string) ($_lusterOld['country'] ?? '')),
        'fields'           => $_lusterFields,
        'old'              => $_lusterOld,
        'captcha_question' => Captcha::getQuestion('update'),
        'captcha_error'    => '',
        'lost_password_url' => '',
        'current_name'     => $_lusterMember->name,
        'current_url'      => $_lusterMember->url,
        'current_country'  => $_lusterMember->country,
        'current_extra'    => $_lusterMember->additionalFields,
        'base_url'         => $_lusterApp->getBaseUrl(),
        'theme_path'       => $_lusterApp->getBaseUrl() . '/assets/default/',
    ]);

}

if ($luster_return ?? false) {
    return $_lusterHtml;
}

echo $_lusterHtml;
