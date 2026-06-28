<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Embeddable password reset form for a single fanlisting. Handles both steps of
 the reset flow inline: the initial email-entry form and the new-password
 confirmation form (triggered automatically when a ?token= query parameter is
 present in the URL). The form self-posts to the host page and redirects back
 after each step, rendering flash messages inline.
*/

declare(strict_types=1);

use Luster\Core\TemplateEngine;
use Luster\Core\View;
use Luster\Repositories\FanPasswordResetRepo;
use Luster\Repositories\ListingRepo;
use Luster\Repositories\ListingSettingsRepo;
use Luster\Repositories\SiteSettingsRepo;
use Luster\Support\FormTemplateRenderer;
use Luster\Support\ListingTemplateResolver;

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

$_lusterStep = (isset($_GET['token']) && $_GET['token'] !== '') ? 'confirm' : 'request';

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
$luster_attribution = \Luster\Core\View::attribution();
$_lusterApp->getSession()->start();
$_lusterRedirectTo = (string) ($luster_redirect_url ?? '');
if ($_lusterRedirectTo === '') {
    $_lusterProto      = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $_lusterRedirectTo = $_lusterProto . '://' . ($_SERVER['HTTP_HOST'] ?? '') . ($_SERVER['REQUEST_URI'] ?? '');
}
// Strip the token param so post-reset redirects don't land on the consumed-token confirm step
$_lusterRedirectTo = (string) preg_replace('/([?&])token=[^&]*&?/', '$1', $_lusterRedirectTo);
$_lusterRedirectTo = rtrim($_lusterRedirectTo, '?&');
$_lusterApp->getSession()->set('lstr_embed_return_to', $_lusterRedirectTo);
$_lusterMarker = 'reset:' . $_lusterListingId;

// ---------------------------------------------------------------------------
// POST handler — process form submissions inline (self-post)
// ---------------------------------------------------------------------------

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (($_POST['_luster_embed_form'] ?? '') !== $_lusterMarker) {
        if ($luster_return ?? false) { return ''; }
        return;
    }
    if (!$_lusterApp->getCSRF()->validate((string) ($_POST['_csrf'] ?? ''))) {
        $_lusterApp->getSession()->set('lstr_flash_error', 'Security check failed. Please reload the page and try again.');
        if (!headers_sent()) { header('Location: ' . $_lusterRedirectTo, true, 302); exit; }
        echo '<script>window.location.replace(' . json_encode($_lusterRedirectTo) . ');</script>';
        return;
    }
    if (isset($_POST['token']) && $_POST['token'] !== '') {
        $response = (new \Luster\Controllers\Public\ResetController($_lusterApp))
            ->submitConfirm(new \Luster\Core\Request(), ['id' => $_lusterListingId]);
    } else {
        $response = (new \Luster\Controllers\Public\ResetController($_lusterApp))
            ->submit(new \Luster\Core\Request(), ['id' => $_lusterListingId]);
    }
    $_lusterUrl = $response->getRedirectUrl() ?? $_lusterRedirectTo;
    if (!headers_sent()) { header('Location: ' . $_lusterUrl, true, 302); exit; }
    echo '<script>window.location.replace(' . json_encode($_lusterUrl) . ');</script>';
    return;
}

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
    // --- Confirm step: validate token, show new-password form

    $_lusterRawToken = trim((string) ($_GET['token'] ?? ''));

    // Consume flash error from a previous confirm-step POST redirect
    $_lusterConfirmSession = $_lusterApp->getSession();
    $_lusterFlashErr       = (string) $_lusterConfirmSession->get('lstr_flash_error', '');
    $_lusterConfirmSession->remove('lstr_flash_error');

    $_lusterError    = $_lusterFlashErr;
    $_lusterTokenValid = false;

    if ($_lusterRawToken === '') {
        $_lusterError = $_lusterError !== '' ? $_lusterError : 'Missing or invalid reset link.';
    } else {
        $_lusterResetRepo = new FanPasswordResetRepo($_lusterApp->getDatabase());
        $_lusterTokenRow  = $_lusterResetRepo->findValidByToken(hash('sha256', $_lusterRawToken));

        if ($_lusterTokenRow === null || (int) $_lusterTokenRow['fanlisting_id'] !== $_lusterListingId) {
            $_lusterError = $_lusterError !== '' ? $_lusterError : 'This reset link has expired or is invalid. Please request a new one.';
        } else {
            $_lusterTokenValid = true;
        }
    }

    $_lusterSettingsRepo    = new ListingSettingsRepo($_lusterApp->getDatabase());
    $_lusterGlobalDefaults  = $_lusterSettingsRepo->getGlobalFormUrlDefaults();
    $_lusterWrapperTpl      = $_lusterSettingsRepo->getWrapperTemplate($_lusterListingId) ?? '';
    $_lusterGlobalTpls      = (new SiteSettingsRepo($_lusterApp->getDatabase()))->getGlobalTemplates();
    $_lusterRstResolved   = ListingTemplateResolver::resolve(
        $_lusterSettingsRepo->getResetFormTemplate($_lusterListingId),
        $_lusterGlobalTpls['listing_default_reset_form_template'] ?? null,
        ListingSettingsRepo::defaultResetFormTemplate(),
    );
    $_lusterResetFormTpl  = $_lusterRstResolved['should_render_template'] ? $_lusterRstResolved['value'] : '';
    $_lusterFb            = $_lusterApp->getFormFieldBuilder();
    $_lusterF             = $_lusterFb->build([
        ['type' => 'password', 'name' => 'new_password',        'id' => 'rc-password', 'label' => 'New Password',         'required' => true, 'attrs' => ['maxlength' => '255', 'autocomplete' => 'new-password'], 'hint' => 'Minimum 12 characters.'],
        ['type' => 'password', 'name' => 'new_password_confirm', 'id' => 'rc-confirm',  'label' => 'Confirm New Password', 'required' => true, 'attrs' => ['maxlength' => '255', 'autocomplete' => 'new-password']],
    ], $_lusterWrapperTpl);
    $_lusterFormAction2   = $_lusterRedirectTo;
    $_lusterEmbedSettings = $_lusterSettingsRepo->getResolvedEmbedSettings($_lusterListingId);
    $_lusterResetUrl      = \Luster\Support\ListingFormUrlResolver::resolve(
        (string) ($_lusterEmbedSettings['form_url_reset'] ?? ''),
        $_lusterListing->url,
        $_lusterGlobalDefaults['form_url_reset'],
    );

    $_lusterFormHtml = null;
    if ($_lusterResetFormTpl !== '') {
        $_lusterProcessed2 = TemplateEngine::conditionals($_lusterResetFormTpl, [
            'step_email'    => false,
            'step_confirm'  => true,
            'token_valid'   => $_lusterTokenValid,
            'token_invalid' => !$_lusterTokenValid,
        ]);
        $_lusterTokens2 = [
            '{listing_title}'             => View::e((string) $_lusterListing->title),
            '{listing_subject}'           => View::e((string) $_lusterListing->subject),
            '{listing_url}'               => View::e((string) $_lusterListing->url),
            '{field_email}'               => '',
            '{captcha_block}'             => '',
            '{captcha_block_input}'       => '',
            '{captcha_question}'          => '',
            '{field_new_password}'        => $_lusterF['new_password'],
            '{field_new_password_confirm}' => $_lusterF['new_password_confirm'],
            '{reset_request_url}'         => View::e($_lusterResetUrl),
            '{submit_button}'             => '<button type="submit" class="fl-submit">Set New Password</button>',
            '{clear_button}'              => '<button type="reset" class="fl-btn-secondary">Clear</button>',
        ];
        $_lusterInner2 = FormTemplateRenderer::expandTokens($_lusterProcessed2, $_lusterTokens2);
        $_lusterTokenHidden = $_lusterTokenValid
            ? '<input type="hidden" name="token" value="' . View::e($_lusterRawToken) . '">'
            : '';
        $_lusterFormHtml = FormTemplateRenderer::wrapForm($_lusterInner2, $_lusterFormAction2, $_lusterApp->getCSRF()->token(), false, false, $_lusterMarker, $_lusterTokenHidden);
    }

    $_lusterHtml = $_lusterApp->getView()->renderFragment('reset_confirm.php', [
        'listing'      => $_lusterListing,
        'listing_title' => $_lusterListing->title,
        'listing_url'  => $_lusterListing->url,
        'page_title'   => 'Set New Password',
        'form_action'  => $_lusterFormAction2,
        'reset_url'    => $_lusterResetUrl,
        'csrf_token'   => $_lusterApp->getCSRF()->token(),
        'errors'       => $_lusterError !== ''
            ? '<p>' . htmlspecialchars($_lusterError, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>'
            : '',
        'token'        => $_lusterTokenValid ? $_lusterRawToken : '',
        'token_valid'  => $_lusterTokenValid,
        'f'            => $_lusterF,
        'form_html'    => $_lusterFormHtml,
        'form_marker'  => $_lusterMarker,
        'base_url'     => $_lusterApp->getBaseUrl(),
        'theme_path'   => $_lusterApp->getBaseUrl() . '/assets/default/',
    ]);
} else {
    // --- Request step: email entry form

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

    $_lusterSettingsRepo2 = new ListingSettingsRepo($_lusterApp->getDatabase());
    $_lusterWrapperTpl2   = $_lusterSettingsRepo2->getWrapperTemplate($_lusterListingId) ?? '';
    $_lusterGlobalTpls2   = (new SiteSettingsRepo($_lusterApp->getDatabase()))->getGlobalTemplates();
    $_lusterRstResolved2  = ListingTemplateResolver::resolve(
        $_lusterSettingsRepo2->getResetFormTemplate($_lusterListingId),
        $_lusterGlobalTpls2['listing_default_reset_form_template'] ?? null,
        ListingSettingsRepo::defaultResetFormTemplate(),
    );
    $_lusterResetFormTpl2 = $_lusterRstResolved2['should_render_template'] ? $_lusterRstResolved2['value'] : '';
    $_lusterFormAction1   = $_lusterRedirectTo;
    $_lusterF             = $_lusterApp->getFormFieldBuilder()->build([
        ['type' => 'email', 'name' => 'email',          'id' => 'rst-email',   'label' => 'Email',    'required' => true, 'value' => $_lusterOldEmail, 'attrs' => ['maxlength' => '255', 'autocomplete' => 'email']],
    ], $_lusterWrapperTpl2);

    $_lusterFormHtml1 = null;
    if ($_lusterResetFormTpl2 !== '') {
        $_lusterProcessed1 = TemplateEngine::conditionals($_lusterResetFormTpl2, [
            'step_email'    => true,
            'step_confirm'  => false,
            'token_valid'   => false,
            'token_invalid' => false,
        ]);
        $_lusterTokens1 = [
            '{listing_title}'             => View::e((string) $_lusterListing->title),
            '{listing_subject}'           => View::e((string) $_lusterListing->subject),
            '{listing_url}'               => View::e((string) $_lusterListing->url),
            '{field_email}'               => $_lusterF['email'],
            '{captcha_block}'             => '',
            '{captcha_block_input}'       => '',
            '{captcha_question}'          => '',
            '{field_new_password}'        => '',
            '{field_new_password_confirm}' => '',
            '{reset_request_url}'         => View::e($_lusterFormAction1),
            '{submit_button}'             => '<button type="submit" class="fl-submit">Send Reset Link</button>',
            '{clear_button}'              => '<button type="reset" class="fl-btn-secondary">Clear</button>',
        ];
        $_lusterInner1    = FormTemplateRenderer::expandTokens($_lusterProcessed1, $_lusterTokens1);
        $_lusterFormHtml1 = FormTemplateRenderer::wrapForm($_lusterInner1, $_lusterFormAction1, $_lusterApp->getCSRF()->token(), false, false, $_lusterMarker);
    }

    $_lusterHtml = $_lusterApp->getView()->renderFragment('reset.php', [
        'listing'      => $_lusterListing,
        'listing_title' => $_lusterListing->title,
        'listing_url'  => $_lusterListing->url,
        'page_title'   => 'Reset Password',
        'form_action'  => $_lusterFormAction1,
        'csrf_token'   => $_lusterApp->getCSRF()->token(),
        'errors'       => $_lusterError !== ''
            ? '<p>' . htmlspecialchars($_lusterError, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>'
            : '',
        'f'            => $_lusterF,
        'form_html'    => $_lusterFormHtml1,
        'form_marker'  => $_lusterMarker,
        'base_url'     => $_lusterApp->getBaseUrl(),
        'theme_path'   => $_lusterApp->getBaseUrl() . '/assets/default/',
    ]);
}


if ($luster_return ?? false) {
    return $_lusterHtml;
}

echo $_lusterHtml;
