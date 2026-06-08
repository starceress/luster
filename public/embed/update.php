<?php

declare(strict_types=1);

use Luster\Core\Captcha;
use Luster\Core\Countries;
use Luster\Repositories\FieldRepo;
use Luster\Repositories\ListingRepo;
use Luster\Repositories\ListingSettingsRepo;
use Luster\Repositories\MemberRepo;
use Luster\Support\FormFieldBuilder;

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

$_lusterSettingsRepo  = new ListingSettingsRepo($_lusterApp->getDatabase());
$_lusterEmbedSettings = $_lusterSettingsRepo->getEmbedSettings($_lusterListingId);

// ---------------------------------------------------------------------------
// 4. Consume flash data
// ---------------------------------------------------------------------------

$_lusterSession = $_lusterApp->getSession();
$_lusterSuccess = (string) $_lusterSession->get('lstr_flash_success_html', '');
$_lusterError   = (string) $_lusterSession->get('lstr_flash_error',        '');
$_lusterOld     = (array)  $_lusterSession->get('lstr_flash_old',          []);
$_lusterSession->remove('lstr_flash_success_html');
$_lusterSession->remove('lstr_flash_error');
$_lusterSession->remove('lstr_flash_old');

if ($_lusterSuccess !== '') {
    if ($luster_return ?? false) { return $_lusterSuccess; }
    echo $_lusterSuccess;
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

$_lusterWrapperTpl = $_lusterSettingsRepo->getWrapperTemplate($_lusterListingId) ?? '';
$_lusterFb         = $_lusterApp->getFormFieldBuilder();

if (!$_lusterSignedIn) {

    // ── Step 1: sign-in form ────────────────────────────────────────────────
    $_lusterDescriptors = [
        ['type' => 'email',    'name' => 'email',    'id' => 'upd-email',    'label' => 'Email Address', 'required' => true, 'value' => (string) ($_lusterOld['email'] ?? ''), 'attrs' => ['maxlength' => '255', 'autocomplete' => 'email']],
        ['type' => 'password', 'name' => 'password', 'id' => 'upd-password', 'label' => 'Password',       'required' => true, 'attrs' => ['maxlength' => '255', 'autocomplete' => 'current-password']],
    ];
    $_lusterF = $_lusterFb->build($_lusterDescriptors, $_lusterWrapperTpl);

    $_lusterHtml = $_lusterApp->getView()->renderFragment('update.php', [
        'listing'           => $_lusterListing,
        'listing_name'      => $_lusterListing->title,
        'listing_url'       => $_lusterListing->url,
        'page_title'        => 'Update Membership',
        'signed_in'         => false,
        'sign_in_action'    => $_lusterBaseUrl . '/update/' . $_lusterListingId . '/sign-in',
        'csrf_token'        => $_lusterApp->getCSRF()->token(),
        'errors'            => $_lusterError !== ''
            ? '<p>' . htmlspecialchars($_lusterError, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>'
            : '',
        'lost_password_url' => $_lusterLostPasswordUrl,
        'fields'            => [],
        'f'                 => $_lusterF,
        'base_url'          => $_lusterApp->getBaseUrl(),
        'theme_path'        => $_lusterApp->getBaseUrl() . '/assets/default/',
    ]);

} else {

    // ── Step 2: update form (authenticated) ─────────────────────────────────
    $_lusterFieldRepo    = new FieldRepo($_lusterApp->getDatabase(), $_lusterApp->getConfig());
    $_lusterFields       = $_lusterFieldRepo->getFieldsForListing($_lusterListingId);
    $_lusterCaptchaQ     = Captcha::getQuestion('update');
    $_lusterCountrySel   = Countries::buildSelect((string) ($_lusterOld['country'] ?? ''));

    $_lusterUrlVal = htmlspecialchars(
        (string) (($_lusterOld['url'] ?? '') !== '' ? ($_lusterOld['url'] ?? '') : $_lusterMember->url),
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8',
    );
    $_lusterUrlRaw = '<input type="url" id="upd-url" name="url" value="' . $_lusterUrlVal . '" maxlength="500" placeholder="https://">'
                   . '<label><input type="checkbox" name="delete_url" value="1"> Remove my website URL</label>';

    $_lusterUpdOld = [];
    foreach ($_lusterFields as $_lusterField) {
        $_lusterOldVal = $_lusterOld['field_' . $_lusterField->name] ?? null;
        $_lusterUpdOld['field_' . $_lusterField->name] = $_lusterOldVal !== null
            ? (is_array($_lusterOldVal) ? implode(',', array_map('strval', $_lusterOldVal)) : (string) $_lusterOldVal)
            : (string) ($_lusterMember->additionalFields[$_lusterField->name] ?? '');
    }

    $_lusterDescriptors = [
        ['type' => 'text',     'name' => 'new_name',            'id' => 'upd-name',         'label' => 'New Name',             'required' => false, 'value' => (string) ($_lusterOld['new_name']  ?? ''), 'attrs' => ['maxlength' => '100', 'autocomplete' => 'name',         'placeholder' => $_lusterMember->name]],
        ['type' => 'email',    'name' => 'new_email',           'id' => 'upd-new-email',    'label' => 'New Email',            'required' => false, 'value' => (string) ($_lusterOld['new_email'] ?? ''), 'attrs' => ['maxlength' => '255',                                    'placeholder' => $_lusterMember->email]],
        ['type' => 'url',      'name' => 'url',                 'id' => 'upd-url',          'label' => 'New Website URL',      'required' => false, 'raw_input' => $_lusterUrlRaw],
        ['type' => 'text',     'name' => 'country',             'id' => '',                 'label' => 'New Country',          'required' => false, 'raw_input' => $_lusterCountrySel],
        ...$_lusterFb->descriptorsFromAdditionalFields($_lusterFields, $_lusterUpdOld),
        ['type' => 'password', 'name' => 'new_password',        'id' => 'upd-new-pass',     'label' => 'New Password',         'required' => false, 'attrs' => ['maxlength' => '255', 'autocomplete' => 'new-password', 'placeholder' => 'Leave blank to keep current']],
        ['type' => 'password', 'name' => 'new_password_confirm', 'id' => 'upd-confirm-pass', 'label' => 'Confirm New Password', 'required' => false, 'attrs' => ['maxlength' => '255', 'autocomplete' => 'new-password', 'placeholder' => 'Repeat new password']],
        ['type' => 'text',     'name' => 'captcha_answer',      'id' => 'upd-captcha',      'label' => 'Captcha: ' . $_lusterCaptchaQ, 'required' => true, 'attrs' => ['maxlength' => '10', 'autocomplete' => 'off']],
    ];
    $_lusterF = $_lusterFb->build($_lusterDescriptors, $_lusterWrapperTpl);

    $_lusterHtml = $_lusterApp->getView()->renderFragment('update.php', [
        'listing'       => $_lusterListing,
        'listing_name'  => $_lusterListing->title,
        'listing_url'   => $_lusterListing->url,
        'page_title'    => 'Update Membership',
        'signed_in'     => true,
        'sign_out_url'  => $_lusterBaseUrl . '/update/' . $_lusterListingId . '/sign-out',
        'form_action'   => $_lusterBaseUrl . '/update/' . $_lusterListingId,
        'csrf_token'    => $_lusterApp->getCSRF()->token(),
        'errors'        => $_lusterError !== ''
            ? '<p>' . htmlspecialchars($_lusterError, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>'
            : '',
        'field_email'   => $_lusterMember->email,
        'fields'        => $_lusterFields,
        'f'             => $_lusterF,
        'base_url'      => $_lusterApp->getBaseUrl(),
        'theme_path'    => $_lusterApp->getBaseUrl() . '/assets/default/',
    ]);

}

if ($luster_return ?? false) {
    return $_lusterHtml;
}

echo $_lusterHtml;
