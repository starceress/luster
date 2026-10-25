<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Embeddable KIM (Keep In Mind) List sign-up form. Allows visitors to register
 their interest in joining fanlistings that are not yet open. Renders a form
 with a fanlisting selector, name, email, URL, and password fields. Requires
 the KIM add-on to be installed. The form self-posts to the host page and
 handles submission inline.
*/

declare(strict_types=1);

use Luster\Addons\Kim\KimMemberRepo;
use Luster\Core\View;
use Luster\Repositories\ListingRepo;
use Luster\Repositories\SiteSettingsRepo;
use Luster\Support\FormTemplateRenderer;

// ---------------------------------------------------------------------------
// 1. Bootstrap Luster
// ---------------------------------------------------------------------------

$_lusterRoot = dirname(__DIR__, 2); // public/embed/ → public/ → project root

if (!is_file($_lusterRoot . '/vendor/autoload.php') || !is_file($_lusterRoot . '/config/app.php')) {
    $_lusterHtml = '<p class="fl-errors">Luster embed error: Luster is not installed correctly at <code>'
        . htmlspecialchars($_lusterRoot, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
        . '</code>.</p>';
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
$_lusterSessionError = View::startEmbedSession($_lusterApp->getSession());
if ($_lusterSessionError !== null) {
    if ($luster_return ?? false) {
        return $_lusterSessionError;
    }
    echo $_lusterSessionError;
    return;
}
$_lusterRedirectTo = (string) ($luster_redirect_url ?? '');
if ($_lusterRedirectTo === '') {
    // Relative, not scheme://host/path - deliberately never trusts the
    // client-supplied Host header. A relative Location: header and a
    // relative <form action> both resolve against whatever page the
    // browser is actually on, so this works identically for every
    // legitimate embed on any domain without needing to know/validate
    // the host at all.
    $_lusterRedirectTo = (string) ($_SERVER['REQUEST_URI'] ?? '/');
}
$_lusterApp->getSession()->set('lstr_embed_return_to', $_lusterRedirectTo);
$_lusterMarker = 'kim_signup';

// ---------------------------------------------------------------------------
// POST handler — process form submissions inline (self-post)
// ---------------------------------------------------------------------------

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (($_POST['_luster_embed_form'] ?? '') !== $_lusterMarker) {
        if ($luster_return ?? false) { return ''; }
        return;
    }
    if (!$_lusterApp->getCSRF()->validate((string) ($_POST['_csrf'] ?? ''))) {
        $_lusterApp->getSession()->set('lstr_flash_error:' . $_lusterMarker, 'Security check failed. Please reload the page and try again.');
        if (!headers_sent()) { header('Location: ' . $_lusterRedirectTo, true, 302); exit; }
        echo '<script>window.location.replace(' . json_encode($_lusterRedirectTo) . ');</script>';
        return;
    }
    $response   = (new \Luster\Addons\Kim\Controllers\KimSignupController($_lusterApp))
        ->submit(new \Luster\Core\Request(), []);
    $_lusterUrl = $response->getRedirectUrl() ?? $_lusterRedirectTo;
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
// 3. Load fanlistings
// ---------------------------------------------------------------------------

$_lusterListingRepo = new ListingRepo($_lusterApp->getDatabase(), $_lusterApp->getConfig());
$_lusterFanlistings = $_lusterListingRepo->getAll();

// ---------------------------------------------------------------------------
// 4. Consume flash data
// ---------------------------------------------------------------------------

$_lusterSession = $_lusterApp->getSession();
$_lusterSuccess = (string) $_lusterSession->get('lstr_flash_success_html:' . $_lusterMarker, '');
$_lusterError   = (string) $_lusterSession->get('lstr_flash_error:' . $_lusterMarker,        '');
$_lusterOld     = (array)  $_lusterSession->get('lstr_flash_old:' . $_lusterMarker,          []);
$_lusterSession->remove('lstr_flash_success_html:' . $_lusterMarker);
$_lusterSession->remove('lstr_flash_error:' . $_lusterMarker);
$_lusterSession->remove('lstr_flash_old:' . $_lusterMarker);

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
// $_lusterLinkReset is for the visible "Forgot your password?" link on this form only.
// The emailed setup-link URL is resolved by KimSignupController::issuePasswordSetupUrl()
// from the saved kim_url_reset setting — not from this per-page override. Design intent:
// per-page overrides control visible links; the emailed URL must be a single canonical
// address (the configured setting) valid for all users across all host pages.
$_lusterLinkReset  = $luster_kim_reset_url  ?? $_toKimLink($_lusterKimUrls["kim_url_reset"]  ?: null);
$_lusterLinkList   = $luster_kim_list_url   ?? $_toKimLink($_lusterKimUrls["kim_url_list"]   ?: null);

// ---------------------------------------------------------------------------
// 5. Render fragment (no layout wrapper)
// ---------------------------------------------------------------------------

$_lusterBaseUrl = $_lusterApp->getBaseUrl();

$_lusterWrapperTpl = '';

$_lusterFlSelectHtml = '<select class="fl-select" id="kim-fanlisting" name="fanlisting_id" required>'
                     . '<option value="">&#8212; Select a Listing &#8212;</option>';
foreach ($_lusterFanlistings as $_lusterFl) {
    $_lusterFlId       = htmlspecialchars((string) $_lusterFl->id, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $_lusterFlTitle    = htmlspecialchars((string) $_lusterFl->subject, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $_lusterFlSelected = ((string) ($_lusterOld['fanlisting_id'] ?? '')) === (string) $_lusterFl->id ? ' selected' : '';
    $_lusterFlUpcoming = $_lusterFl->upcoming ? ' (upcoming)' : '';
    $_lusterFlSelectHtml .= "<option value=\"{$_lusterFlId}\"{$_lusterFlSelected}>{$_lusterFlTitle}{$_lusterFlUpcoming}</option>";
}
$_lusterFlSelectHtml .= '</select>';

$_lusterCaptchaQ = \Luster\Core\Captcha::getQuestion('kim_signup');

$_lusterF = $_lusterApp->getFormFieldBuilder()->build([
    ['type' => 'text',     'name' => 'name',             'id' => 'kim-name',             'label' => 'Name',                     'required' => true,  'value' => (string) ($_lusterOld['name']  ?? ''), 'attrs' => ['maxlength' => '100', 'autocomplete' => 'name']],
    ['type' => 'url',      'name' => 'url',              'id' => 'kim-url',              'label' => 'Website URL (optional)',  'required' => false, 'value' => (string) ($_lusterOld['url']   ?? ''), 'attrs' => ['maxlength' => '500', 'placeholder' => 'https://', 'autocomplete' => 'url']],
    ['type' => 'email',    'name' => 'email',            'id' => 'kim-email',            'label' => 'Email',                    'required' => true,  'value' => (string) ($_lusterOld['email'] ?? ''), 'attrs' => ['maxlength' => '255', 'autocomplete' => 'email']],
    ['type' => 'select',   'name' => 'fanlisting_id',   'id' => 'kim-fanlisting',       'label' => 'Fanlisting Interested In', 'required' => true,  'raw_input' => $_lusterFlSelectHtml],
    ['type' => 'password', 'name' => 'password',         'id' => 'kim-password',         'label' => 'Password (optional)',       'required' => false, 'attrs' => ['maxlength' => '255', 'autocomplete' => 'new-password'], 'hint' => 'Leave blank to auto-generate. Minimum 12 characters if choosing your own.'],
    ['type' => 'password', 'name' => 'password_confirm', 'id' => 'kim-password-confirm', 'label' => 'Confirm Password',         'required' => false, 'attrs' => ['maxlength' => '255', 'autocomplete' => 'new-password']],
    ['type' => 'text',     'name' => 'captcha_answer',   'id' => 'kim-captcha',          'label' => 'Captcha: ' . $_lusterCaptchaQ, 'required' => true, 'attrs' => ['maxlength' => '10', 'autocomplete' => 'off']],
], $_lusterWrapperTpl);

$_lusterFormHtml     = null;
$_lusterSiteName     = (string) $_lusterApp->getConfig()->get('site_name', 'Luster');
$_lusterKimSignupTpl = $_lusterSiteSettings->get('kim_signup_form_template') ?? '';
if ($_lusterKimSignupTpl !== '') {
    $_lusterKimTokens = [
        '{site_name}'                        => View::e($_lusterSiteName),
        '{field_name}'                        => $_lusterF['name'],
        '{field_name_input}'                  => $_lusterF['name_input'],
        '{field_url}'                         => $_lusterF['url'],
        '{field_url_input}'                   => $_lusterF['url_input'],
        '{field_email}'                       => $_lusterF['email'],
        '{field_email_input}'                 => $_lusterF['email_input'],
        '{field_fanlisting_id}'               => $_lusterF['fanlisting_id'],
        '{field_fanlisting_id_input}'         => $_lusterF['fanlisting_id_input'],
        '{field_password}'                    => $_lusterF['password'],
        '{field_password_input}'              => $_lusterF['password_input'],
        '{field_password_confirm}'            => $_lusterF['password_confirm'],
        '{field_password_confirm_input}'      => $_lusterF['password_confirm_input'],
        '{captcha_block}'                     => $_lusterF['captcha_answer'],
        '{captcha_block_input}'               => $_lusterF['captcha_answer_input'] ?? '',
        '{captcha_question}'                  => htmlspecialchars($_lusterCaptchaQ, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
        '{submit_button}'                     => '<button type="submit" class="fl-submit">Sign Up</button>',
        '{clear_button}'                      => '<button type="reset" class="fl-btn-secondary">Clear</button>',
    ];
    $_lusterInnerHtml = FormTemplateRenderer::expandTokens($_lusterKimSignupTpl, $_lusterKimTokens);
    $_lusterFormHtml  = FormTemplateRenderer::wrapForm($_lusterInnerHtml, $_lusterRedirectTo, $_lusterApp->getCSRF()->token(), false, false, $_lusterMarker);
}

$_lusterHtml = $_lusterApp->getView()->render('default/addons/kim/signup.php', [
    'form_action' => $_lusterRedirectTo,
    'csrf_token'  => $_lusterApp->getCSRF()->token(),
    'errors'      => $_lusterError !== ''
        ? '<p>' . htmlspecialchars($_lusterError, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>'
        : '',
    'old'         => $_lusterOld,
    'f'           => $_lusterF,
    'form_html'   => $_lusterFormHtml,
    'form_marker' => $_lusterMarker,
    'base_url'    => $_lusterBaseUrl,
    'theme_path'  => $_lusterBaseUrl . '/assets/default/',
    'link_update' => $_lusterLinkUpdate,
    'link_reset'  => $_lusterLinkReset,
    'link_list'   => $_lusterLinkList,
]);


$_lusterHtml .= $luster_attribution;

if ($luster_return ?? false) {
    return $_lusterHtml;
}

echo $_lusterHtml;
