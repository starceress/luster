<?php

declare(strict_types=1);

use Luster\Core\TemplateEngine;
use Luster\Core\View;
use Luster\Repositories\SiteSettingsRepo;
use Luster\Support\FormTemplateRenderer;

/**
 * Luster embed — KIM List password reset form fragment.
 *
 * Handles both the email-entry step and the confirm-new-password step.
 * The confirm step is triggered automatically when ?token= is present in the URL.
 *
 * Usage:
 *   $luster_return = false;  // optional: true = return string, false = echo
 *   require '/absolute/path/to/luster/public/embed/kim-reset.php';
 *
 * The form self-posts to the host page URL. After every outcome (success or
 * validation error) the user is redirected back to this same host page, where
 * the embed re-renders with a flash success or error message inline.
 *
 * Reset emails link back to this host page with ?token= appended so the confirm
 * step is rendered inline here, not on the backend /kim/reset/confirm route.
 *
 * To override the redirect target, set $luster_redirect_url before require.
 */

// ---------------------------------------------------------------------------
// 1. Bootstrap Luster
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
// Strip ?token= from the stored return-to URL so clicking a reset link doesn't
// store the token-bearing URL as the post-confirm redirect target.
$_lusterRedirectToClean = (string) preg_replace('/([?&])token=[^&]*&?/', '$1', $_lusterRedirectTo);
$_lusterRedirectToClean = rtrim($_lusterRedirectToClean, '?&');
$_lusterApp->getSession()->set('lstr_embed_return_to', $_lusterRedirectToClean);

// Determine step: confirm (token in GET) or email-entry
$_lusterKimResetStep = trim((string) ($_GET['token'] ?? '')) !== '' ? 'confirm' : 'request';

// ---------------------------------------------------------------------------
// POST handler — process form submissions inline (self-post)
// ---------------------------------------------------------------------------

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (($_POST['_luster_embed_form'] ?? '') !== 'kim_reset') {
        if ($luster_return ?? false) { return ''; }
        return;
    }
    if (!$_lusterApp->getCSRF()->validate((string) ($_POST['_csrf'] ?? ''))) {
        $_lusterApp->getSession()->set('lstr_flash_error', 'Security check failed. Please reload the page and try again.');
        if (!headers_sent()) { header('Location: ' . $_lusterRedirectToClean, true, 302); exit; }
        echo '<script>window.location.replace(' . json_encode($_lusterRedirectToClean) . ');</script>';
        return;
    }
    if (($_POST['token'] ?? '') !== '') {
        $response = (new \Luster\Addons\Kim\Controllers\KimResetController($_lusterApp))
            ->submitConfirm(new \Luster\Core\Request(), []);
    } else {
        $response = (new \Luster\Addons\Kim\Controllers\KimResetController($_lusterApp))
            ->submit(new \Luster\Core\Request(), []);
    }
    $_lusterUrl = $response->getRedirectUrl() ?? $_lusterRedirectToClean;
    if (!headers_sent()) { header('Location: ' . $_lusterUrl, true, 302); exit; }
    echo '<script>window.location.replace(' . json_encode($_lusterUrl) . ');</script>';
    return;
}

// ---------------------------------------------------------------------------
// 2. Check KIM addon is installed
// ---------------------------------------------------------------------------

if (!$_lusterApp->isAddonInstalled('Kim')) {
    $_lusterHtml = '<p class="fl-errors">Luster embed error: KIM List add-on is not installed.</p>';
    if ($luster_return ?? false) {
        return $_lusterHtml;
    }
    echo $_lusterHtml;
    return;
}

// ---------------------------------------------------------------------------
// 3. Consume flash data
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
// Load KIM page URLs from settings (allow host-page override)
// ---------------------------------------------------------------------------

$_lusterSiteSettings = new SiteSettingsRepo($_lusterApp->getDatabase());
$_lusterKimUrls = $_lusterSiteSettings->getMany([
    "kim_url_signup", "kim_url_update", "kim_url_reset", "kim_url_list"
]);

// Stored values are page filenames; combine with the site origin to build full URLs.
// Host-page $luster_kim_*_url overrides are used as-is (they are already full URLs).
$_lusterKimParsed   = parse_url((string) ($_lusterApp->getConfig()->get('site_url', '')));
$_lusterKimSiteRoot = ($_lusterKimParsed['scheme'] ?? 'https') . '://' . ($_lusterKimParsed['host'] ?? '');
if (!empty($_lusterKimParsed['port'])) {
    $_lusterKimSiteRoot .= ':' . $_lusterKimParsed['port'];
}
$_toKimLink = static fn(?string $v): ?string =>
    ($v !== null && $v !== '') ? $_lusterKimSiteRoot . '/' . ltrim($v, '/') : null;

$_lusterLinkSignup = $luster_kim_signup_url ?? $_toKimLink($_lusterKimUrls["kim_url_signup"] ?: null);
$_lusterLinkUpdate = $luster_kim_update_url ?? $_toKimLink($_lusterKimUrls["kim_url_update"] ?? null) ?? $_lusterKimSiteRoot . '/kim-update.php';
$_lusterLinkReset  = $luster_kim_reset_url  ?? $_toKimLink($_lusterKimUrls["kim_url_reset"]  ?: null);
$_lusterLinkList   = $luster_kim_list_url   ?? $_toKimLink($_lusterKimUrls["kim_url_list"]   ?: null);

// ---------------------------------------------------------------------------
// 5. Render fragment (no layout wrapper)
// ---------------------------------------------------------------------------

$_lusterBaseUrl  = $_lusterApp->getBaseUrl();
$_lusterResetTpl = $_lusterSiteSettings->get('kim_reset_form_template') ?? '';
// Recovery URL for "request a new link" — prefer clean host-page URL (always
// same-domain), then explicit $luster_kim_reset_url override, then saved setting.
$_lusterResetRecoveryUrl = $_lusterRedirectToClean !== ''
    ? $_lusterRedirectToClean
    : (($luster_kim_reset_url ?? null) ?? ($_lusterLinkReset ?? ''));

if ($_lusterKimResetStep === 'confirm') {
    // ── Confirm step: validate token, show new-password form ─────────────────
    // $_lusterError is already populated from flash data consumed above.

    $_lusterRawToken   = trim((string) ($_GET['token'] ?? ''));
    $_lusterTokenValid = false;

    if ($_lusterRawToken === '') {
        $_lusterError = $_lusterError !== '' ? $_lusterError : 'Missing or invalid reset link.';
    } else {
        $_lusterResetRepo = new \Luster\Addons\Kim\KimPasswordResetRepo($_lusterApp->getDatabase());
        $_lusterTokenRow  = $_lusterResetRepo->findValidByToken(hash('sha256', $_lusterRawToken));
        if ($_lusterTokenRow !== null) {
            $_lusterTokenValid = true;
        } else {
            $_lusterError = $_lusterError !== '' ? $_lusterError : 'This reset link has expired or is invalid. Please request a new one.';
        }
    }

    $_lusterFb = $_lusterApp->getFormFieldBuilder();
    $_lusterF  = $_lusterFb->build([
        ['type' => 'password', 'name' => 'new_password',         'id' => 'kim-rc-password', 'label' => 'New Password',         'required' => true, 'attrs' => ['maxlength' => '255', 'autocomplete' => 'new-password'], 'hint' => 'Minimum 12 characters.'],
        ['type' => 'password', 'name' => 'new_password_confirm', 'id' => 'kim-rc-confirm',  'label' => 'Confirm New Password', 'required' => true, 'attrs' => ['maxlength' => '255', 'autocomplete' => 'new-password']],
    ], '');

    $_lusterFormHtml = null;
    if ($_lusterResetTpl !== '') {
        $_lusterTplC = TemplateEngine::conditionals($_lusterResetTpl, [
            'step_email'    => false,
            'step_confirm'  => true,
            'token_valid'   => $_lusterTokenValid,
            'token_invalid' => !$_lusterTokenValid,
        ]);
        $_lusterTokensC = [
            '{submit_button}'                => '<button type="submit" class="fl-submit">Set New Password</button>',
            '{clear_button}'                 => '<button type="reset" class="fl-btn-secondary">Clear</button>',
            '{reset_request_url}'            => View::e($_lusterResetRecoveryUrl),
            '{field_password}'               => $_lusterF['new_password'],
            '{field_password_input}'         => $_lusterF['new_password_input'] ?? '',
            '{field_password_confirm}'       => $_lusterF['new_password_confirm'],
            '{field_password_confirm_input}' => $_lusterF['new_password_confirm_input'] ?? '',
        ];
        $_lusterInnerC   = FormTemplateRenderer::expandTokens($_lusterTplC, $_lusterTokensC);
        $_lusterTokenHidden = $_lusterTokenValid
            ? '<input type="hidden" name="token" value="' . View::e($_lusterRawToken) . '">'
            : '';
        $_lusterFormHtml = FormTemplateRenderer::wrapForm($_lusterInnerC, $_lusterRedirectToClean, $_lusterApp->getCSRF()->token(), false, false, 'kim_reset', $_lusterTokenHidden);
    }

    $_lusterHtml = $_lusterApp->getView()->renderFragment('addons/kim/reset-confirm.php', [
        'form_action' => $_lusterRedirectToClean,
        'csrf_token'  => $_lusterApp->getCSRF()->token(),
        'errors'      => $_lusterError !== ''
            ? '<p>' . htmlspecialchars($_lusterError, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>'
            : '',
        'token'       => $_lusterTokenValid ? $_lusterRawToken : '',
        'token_valid' => $_lusterTokenValid,
        'f'           => $_lusterF,
        'form_html'   => $_lusterFormHtml,
        'form_marker'       => 'kim_reset',
        'reset_request_url' => $_lusterResetRecoveryUrl,
        'base_url'          => $_lusterBaseUrl,
    ]);
} else {
    // ── Request step: email-entry form ────────────────────────────────────────

    $_lusterWrapperTpl = '';

    $_lusterF = $_lusterApp->getFormFieldBuilder()->build([
        ['type' => 'email',  'name' => 'email',          'id' => 'kim-email',      'label' => 'Email',                          'required' => true, 'value' => $_lusterOldEmail, 'attrs' => ['maxlength' => '255', 'autocomplete' => 'email']],
    ], $_lusterWrapperTpl);

    $_lusterFormHtml = null;
    if ($_lusterResetTpl !== '') {
        $_lusterTplOut = TemplateEngine::conditionals($_lusterResetTpl, [
            'step_email'    => true,
            'step_confirm'  => false,
            'token_valid'   => false,
            'token_invalid' => false,
        ]);
        $_lusterTokens = [
            '{field_email}'                  => $_lusterF['email'],
            '{field_email_input}'            => $_lusterF['email_input'],
            '{submit_button}'                => '<button type="submit" class="fl-submit">Send Reset Link</button>',
            '{clear_button}'                 => '<button type="reset" class="fl-btn-secondary">Clear</button>',
            '{reset_request_url}'            => View::e($_lusterResetRecoveryUrl),
        ];
        $_lusterInner    = FormTemplateRenderer::expandTokens($_lusterTplOut, $_lusterTokens);
        $_lusterFormHtml = FormTemplateRenderer::wrapForm($_lusterInner, $_lusterRedirectToClean, $_lusterApp->getCSRF()->token(), false, false, 'kim_reset');
    }

    $_lusterHtml = $_lusterApp->getView()->renderFragment('addons/kim/reset.php', [
        'form_action' => $_lusterRedirectToClean,
        'csrf_token'  => $_lusterApp->getCSRF()->token(),
        'errors'      => $_lusterError !== ''
            ? '<p>' . htmlspecialchars($_lusterError, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>'
            : '',
        'f'           => $_lusterF,
        'form_html'   => $_lusterFormHtml,
        'form_marker' => 'kim_reset',
        'base_url'    => $_lusterBaseUrl,
        'theme_path'  => $_lusterBaseUrl . '/assets/default/',
        'link_update' => $_lusterLinkUpdate,
    ]);
}


if ($luster_return ?? false) {
    return $_lusterHtml;
}

echo $_lusterHtml;
