<?php

declare(strict_types=1);

use Luster\Addons\Kim\KimMemberRepo;
use Luster\Core\Captcha;
use Luster\Core\View;
use Luster\Repositories\ListingRepo;
use Luster\Repositories\SiteSettingsRepo;
use Luster\Support\FormTemplateRenderer;

/**
 * Luster embed — KIM List sign-up form fragment.
 *
 * Drop this into any PHP page to embed the KIM sign-up form without Luster's layout.
 * The surrounding HTML (header, footer, nav) comes from your own page.
 *
 * Usage:
 *   $luster_return = false;  // optional: true = return string, false = echo
 *   require '/absolute/path/to/luster/public/embed/kim-signup.php';
 *
 * The form POSTs to Luster's /kim URL.
 */

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
$_lusterApp->getSession()->start();
$_lusterRedirectTo = (string) ($luster_redirect_url ?? '');
if ($_lusterRedirectTo === '') {
    $_lusterProto      = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $_lusterRedirectTo = $_lusterProto . '://' . ($_SERVER['HTTP_HOST'] ?? '') . ($_SERVER['REQUEST_URI'] ?? '');
}
$_lusterApp->getSession()->set('lstr_embed_return_to', $_lusterRedirectTo);

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
// Load KIM page URLs from settings (allow host-page override)
// ---------------------------------------------------------------------------

$_lusterSiteSettings = new SiteSettingsRepo($_lusterApp->getDatabase());
$_lusterKimUrls = $_lusterSiteSettings->getMany([
    "kim_url_signup", "kim_url_update", "kim_url_reset", "kim_url_list"
]);

// Stored values are page filenames; combine with the site origin to build full URLs.
// Host-page $luster_kim_*_url overrides are used as-is (they are already full URLs).
$_lusterKimParsed   = parse_url((string) $_lusterApp->getConfig()->get('app_url', ''));
$_lusterKimSiteRoot = ($_lusterKimParsed['scheme'] ?? 'https') . '://' . ($_lusterKimParsed['host'] ?? '');
if (!empty($_lusterKimParsed['port'])) {
    $_lusterKimSiteRoot .= ':' . $_lusterKimParsed['port'];
}
$_toKimLink = static fn(?string $v): ?string =>
    ($v !== null && $v !== '') ? $_lusterKimSiteRoot . '/' . ltrim($v, '/') : null;

$_lusterLinkSignup = $luster_kim_signup_url ?? $_toKimLink($_lusterKimUrls["kim_url_signup"] ?: null);
$_lusterLinkUpdate = $luster_kim_update_url ?? $_toKimLink($_lusterKimUrls["kim_url_update"] ?: null);
$_lusterLinkReset  = $luster_kim_reset_url  ?? $_toKimLink($_lusterKimUrls["kim_url_reset"]  ?: null);
$_lusterLinkList   = $luster_kim_list_url   ?? $_toKimLink($_lusterKimUrls["kim_url_list"]   ?: null);

// ---------------------------------------------------------------------------
// 5. Render fragment (no layout wrapper)
// ---------------------------------------------------------------------------

$_lusterBaseUrl = $_lusterApp->getBaseUrl();

$_lusterCaptchaQ  = Captcha::getQuestion('kim_signup');
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

$_lusterF = $_lusterApp->getFormFieldBuilder()->build([
    ['type' => 'text',     'name' => 'name',             'id' => 'kim-name',             'label' => 'Name',                     'required' => true,  'value' => (string) ($_lusterOld['name']  ?? ''), 'attrs' => ['maxlength' => '100', 'autocomplete' => 'name']],
    ['type' => 'url',      'name' => 'url',              'id' => 'kim-url',              'label' => 'Website URL',  'required' => true,  'value' => (string) ($_lusterOld['url']   ?? ''), 'attrs' => ['maxlength' => '500', 'placeholder' => 'https://', 'autocomplete' => 'url']],
    ['type' => 'email',    'name' => 'email',            'id' => 'kim-email',            'label' => 'Email',                    'required' => true,  'value' => (string) ($_lusterOld['email'] ?? ''), 'attrs' => ['maxlength' => '255', 'autocomplete' => 'email']],
    ['type' => 'select',   'name' => 'fanlisting_id',   'id' => 'kim-fanlisting',       'label' => 'Fanlisting Interested In', 'required' => true,  'raw_input' => $_lusterFlSelectHtml],
    ['type' => 'password', 'name' => 'password',         'id' => 'kim-password',         'label' => 'Password',                 'required' => false, 'attrs' => ['maxlength' => '255', 'autocomplete' => 'new-password'], 'hint' => 'Optional - If left blank, a password will automatically generated and emailed to you.'],
    ['type' => 'password',     'name' => 'password_confirm', 'id' => 'kim-password-confirm', 'label' => 'Confirm Password',         'required' => false, 'attrs' => ['maxlength' => '255', 'autocomplete' => 'new-password']],
    ['type' => 'checkboxGroup','name' => 'send_copy',        'id' => '',                     'label' => 'Opt-in',                   'required' => false, 'options' => ['1' => 'Email me a copy of my submission'], 'selected' => ((string) ($_lusterOld['send_copy'] ?? '')) === '1' ? ['1'] : []],
    ['type' => 'text',         'name' => 'captcha_answer',   'id' => 'kim-captcha',          'label' => 'Captcha: ' . $_lusterCaptchaQ, 'required' => true, 'attrs' => ['maxlength' => '10', 'autocomplete' => 'off']],
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
        '{field_send_copy}'                   => $_lusterF['send_copy'],
        '{field_send_copy_input}'             => $_lusterF['send_copy_input'],
        '{captcha_block}'                     => $_lusterF['captcha_answer'],
        '{captcha_block_input}'               => $_lusterF['captcha_answer_input'],
        '{captcha_question}'                  => View::e($_lusterCaptchaQ),
        '{submit_button}'                     => '<button type="submit" class="fl-submit">Sign Up</button>',
        '{clear_button}'                      => '<button type="reset" class="fl-btn-secondary">Clear</button>',
    ];
    $_lusterInnerHtml = FormTemplateRenderer::expandTokens($_lusterKimSignupTpl, $_lusterKimTokens);
    $_lusterFormHtml  = FormTemplateRenderer::wrapForm($_lusterInnerHtml, $_lusterBaseUrl . '/kim', $_lusterApp->getCSRF()->token(), false);
}

$_lusterHtml = $_lusterApp->getView()->render('default/addons/kim/signup.php', [
    'form_action' => $_lusterBaseUrl . '/kim',
    'csrf_token'  => $_lusterApp->getCSRF()->token(),
    'errors'      => $_lusterError !== ''
        ? '<p>' . htmlspecialchars($_lusterError, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>'
        : '',
    'old'         => $_lusterOld,
    'f'           => $_lusterF,
    'form_html'   => $_lusterFormHtml,
    'base_url'    => $_lusterBaseUrl,
    'theme_path'  => $_lusterBaseUrl . '/assets/default/',
    'link_update' => $_lusterLinkUpdate,
    'link_reset'  => $_lusterLinkReset,
    'link_list'   => $_lusterLinkList,
]);

$_lusterHtml .= \Luster\Core\View::attribution();

if ($luster_return ?? false) {
    return $_lusterHtml;
}

echo $_lusterHtml;
