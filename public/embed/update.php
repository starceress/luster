<?php

declare(strict_types=1);

use Luster\Core\Captcha;
use Luster\Core\Countries;
use Luster\Core\TemplateEngine;
use Luster\Core\View;
use Luster\Repositories\FieldRepo;
use Luster\Repositories\ListingRepo;
use Luster\Repositories\ListingSettingsRepo;
use Luster\Repositories\MemberRepo;
use Luster\Repositories\SiteSettingsRepo;
use Luster\Support\FormFieldBuilder;
use Luster\Support\FormTemplateRenderer;
use Luster\Support\ListingTemplateResolver;

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
    if ($_lusterResetPage !== '' && preg_match('#^https?://#i', $_lusterResetPage)) {
        $_lusterLostPasswordUrl = $_lusterResetPage;
    } elseif ($_lusterResetPage !== '') {
        $_lusterLostPasswordUrl = rtrim((string) $_lusterListing->url, '/') . '/' . ltrim($_lusterResetPage, '/');
    } else {
        $_lusterLostPasswordUrl = '';
    }
}

// ---------------------------------------------------------------------------
// 7. Render fragment
// ---------------------------------------------------------------------------

$_lusterWrapperTpl    = $_lusterSettingsRepo->getWrapperTemplate($_lusterListingId) ?? '';
$_lusterGlobalTpls    = (new SiteSettingsRepo($_lusterApp->getDatabase()))->getGlobalTemplates();
$_lusterUpdResolved   = ListingTemplateResolver::resolve(
    $_lusterSettingsRepo->getUpdateFormTemplate($_lusterListingId),
    $_lusterGlobalTpls['listing_default_update_form_template'] ?? null,
    ListingSettingsRepo::defaultUpdateFormTemplate(),
);
$_lusterUpdateFormTpl = $_lusterUpdResolved['should_render_template'] ? $_lusterUpdResolved['value'] : '';
$_lusterFb            = $_lusterApp->getFormFieldBuilder();

if (!$_lusterSignedIn) {

    // ── Step 1: sign-in form ────────────────────────────────────────────────
    $_lusterFormAction1  = $_lusterBaseUrl . '/update/' . $_lusterListingId . '/sign-in';
    $_lusterDescriptors = [
        ['type' => 'email',    'name' => 'email',    'id' => 'upd-email',    'label' => 'Email Address', 'required' => true, 'value' => (string) ($_lusterOld['email'] ?? ''), 'attrs' => ['maxlength' => '255', 'autocomplete' => 'email']],
        ['type' => 'password', 'name' => 'password', 'id' => 'upd-password', 'label' => 'Password',       'required' => true, 'attrs' => ['maxlength' => '255', 'autocomplete' => 'current-password']],
    ];
    $_lusterF = $_lusterFb->build($_lusterDescriptors, $_lusterWrapperTpl);

    $_lusterFormHtml = null;
    if ($_lusterUpdateFormTpl !== '') {
        $_lusterProcessed = TemplateEngine::conditionals($_lusterUpdateFormTpl, [
            'not_signed_in' => true,
            'signed_in'     => false,
        ]);
        $_lusterTokens = [
            '{listing_title}'          => View::e((string) $_lusterListing->title),
            '{listing_subject}'        => View::e((string) $_lusterListing->subject),
            '{listing_url}'            => View::e((string) $_lusterListing->url),
            '{field_signin_email}'     => $_lusterF['email'],
            '{field_signin_password}'  => $_lusterF['password'],
            '{field_new_name}'         => '',
            '{field_new_email}'        => '',
            '{field_new_url}'          => '',
            '{field_new_country}'      => '',
            '{additional_fields}'      => '',
            '{field_password}'         => '',
            '{field_password_confirm}' => '',
            '{captcha_block}'          => '',
            '{captcha_block_input}'    => '',
            '{captcha_question}'       => '',
            '{field_remove}'           => '',
            '{submit_button}'          => '<button type="submit" class="fl-submit">Sign in</button>',
            '{clear_button}'           => '<button type="reset" class="fl-btn-secondary">Clear</button>',
            '{sign_out_url}'           => '#',
            '{field_email}'            => '',
            '{lost_password_url}'      => View::e($_lusterLostPasswordUrl),
        ];
        $_lusterInner    = FormTemplateRenderer::expandTokens($_lusterProcessed, $_lusterTokens);
        $_lusterFormHtml = FormTemplateRenderer::wrapForm($_lusterInner, $_lusterFormAction1, $_lusterApp->getCSRF()->token(), false);
    }

    $_lusterHtml = $_lusterApp->getView()->renderFragment('update.php', [
        'listing'           => $_lusterListing,
        'listing_name'      => $_lusterListing->title,
        'listing_url'       => $_lusterListing->url,
        'page_title'        => 'Update Membership',
        'signed_in'         => false,
        'sign_in_action'    => $_lusterFormAction1,
        'csrf_token'        => $_lusterApp->getCSRF()->token(),
        'errors'            => $_lusterError !== ''
            ? '<p>' . htmlspecialchars($_lusterError, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>'
            : '',
        'lost_password_url' => $_lusterLostPasswordUrl,
        'fields'            => [],
        'f'                 => $_lusterF,
        'form_html'         => $_lusterFormHtml,
        'base_url'          => $_lusterApp->getBaseUrl(),
        'theme_path'        => $_lusterApp->getBaseUrl() . '/assets/default/',
    ]);

} else {

    // ── Step 2: update form (authenticated) ─────────────────────────────────
    $_lusterFieldRepo    = new FieldRepo($_lusterApp->getDatabase(), $_lusterApp->getConfig());
    $_lusterFields       = $_lusterFieldRepo->getFieldsForListing($_lusterListingId);
    $_lusterCaptchaQ     = Captcha::getQuestion('update');
    $_lusterCountrySel   = Countries::buildSelect((string) ($_lusterOld['country'] ?? ''));
    $_lusterFormAction2  = $_lusterBaseUrl . '/update/' . $_lusterListingId;
    $_lusterSignOutUrl   = $_lusterBaseUrl . '/update/' . $_lusterListingId . '/sign-out';

    $_lusterUrlVal = htmlspecialchars(
        (string) (($_lusterOld['url'] ?? '') !== '' ? ($_lusterOld['url'] ?? '') : $_lusterMember->url),
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8',
    );
    $_lusterUrlRaw = '<input class="fl-input" type="url" id="upd-url" name="url" value="' . $_lusterUrlVal . '" maxlength="500" placeholder="https://">'
                   . '<div class="fl-field-hint"><label><input type="checkbox" name="delete_url" value="1"> Remove my website URL</label></div>';

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

    $_lusterFormHtml = null;
    if ($_lusterUpdateFormTpl !== '') {
        $_lusterAddlHtml    = implode("\n", array_map(
            fn(\Luster\Models\AdditionalField $fld) => $_lusterF['field_' . $fld->name] ?? '',
            $_lusterFields,
        ));
        $_lusterRemoveHtml  = '<div class="fl-field fl-field--danger"><label>'
            . '<input type="checkbox" id="upd-delete-membership" name="delete_membership" value="1">'
            . ' Permanently delete my membership instead of updating it</label>'
            . '<p class="fl-field-hint">This immediately and permanently removes your membership from this listing. Cannot be undone.</p></div>';
        $_lusterProcessed = TemplateEngine::conditionals($_lusterUpdateFormTpl, [
            'not_signed_in' => false,
            'signed_in'     => true,
        ]);
        $_lusterTokens = [
            '{listing_title}'          => View::e((string) $_lusterListing->title),
            '{listing_subject}'        => View::e((string) $_lusterListing->subject),
            '{listing_url}'            => View::e((string) $_lusterListing->url),
            '{field_signin_email}'     => '',
            '{field_signin_password}'  => '',
            '{field_new_name}'         => $_lusterF['new_name'],
            '{field_new_email}'        => $_lusterF['new_email'],
            '{field_new_url}'          => $_lusterF['url'],
            '{field_new_country}'      => $_lusterF['country'],
            '{additional_fields}'      => $_lusterAddlHtml,
            '{field_password}'         => $_lusterF['new_password'],
            '{field_password_confirm}' => $_lusterF['new_password_confirm'],
            '{captcha_block}'          => $_lusterF['captcha_answer'],
            '{captcha_block_input}'    => $_lusterF['captcha_answer_input'] ?? '',
            '{captcha_question}'       => View::e($_lusterCaptchaQ),
            '{field_remove}'           => $_lusterRemoveHtml,
            '{submit_button}'          => '<button type="submit" class="fl-submit">Update Information</button>',
            '{clear_button}'           => '<button type="reset" class="fl-btn-secondary">Clear</button>',
            '{sign_out_url}'           => View::e($_lusterSignOutUrl),
            '{field_email}'            => View::e($_lusterMember->email),
            '{lost_password_url}'      => '',
        ];
        $_lusterInner    = FormTemplateRenderer::expandTokens($_lusterProcessed, $_lusterTokens);
        $_lusterFormHtml = FormTemplateRenderer::wrapForm($_lusterInner, $_lusterFormAction2, $_lusterApp->getCSRF()->token(), false);
    }

    $_lusterHtml = $_lusterApp->getView()->renderFragment('update.php', [
        'listing'       => $_lusterListing,
        'listing_name'  => $_lusterListing->title,
        'listing_url'   => $_lusterListing->url,
        'page_title'    => 'Update Membership',
        'signed_in'     => true,
        'sign_out_url'  => $_lusterSignOutUrl,
        'form_action'   => $_lusterFormAction2,
        'csrf_token'    => $_lusterApp->getCSRF()->token(),
        'errors'        => $_lusterError !== ''
            ? '<p>' . htmlspecialchars($_lusterError, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>'
            : '',
        'field_email'   => $_lusterMember->email,
        'fields'        => $_lusterFields,
        'f'             => $_lusterF,
        'form_html'     => $_lusterFormHtml,
        'base_url'      => $_lusterApp->getBaseUrl(),
        'theme_path'    => $_lusterApp->getBaseUrl() . '/assets/default/',
    ]);

}

if ($luster_return ?? false) {
    return $_lusterHtml;
}

echo $_lusterHtml;
