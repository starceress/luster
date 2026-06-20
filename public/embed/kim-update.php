<?php

declare(strict_types=1);

use Luster\Addons\Kim\KimMemberRepo;
use Luster\Core\Captcha;
use Luster\Core\TemplateEngine;
use Luster\Core\View;
use Luster\Repositories\ListingRepo;
use Luster\Repositories\SiteSettingsRepo;
use Luster\Support\FormTemplateRenderer;

/**
 * Luster embed — KIM List update form fragment.
 *
 * Usage:
 *   $luster_return = false;  // optional: true = return string, false = echo
 *   require '/absolute/path/to/luster/public/embed/kim-update.php';
 *
 * The form POSTs to Luster's /kim/update URL.
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
$_lusterApp->getSession()->start();
$_lusterRedirectTo = (string) ($luster_redirect_url ?? '');
if ($_lusterRedirectTo === '') {
    $_lusterProto      = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $_lusterRedirectTo = $_lusterProto . '://' . ($_SERVER['HTTP_HOST'] ?? '') . ($_SERVER['REQUEST_URI'] ?? '');
}
$_lusterApp->getSession()->set('lstr_embed_return_to', $_lusterRedirectTo);

// ---------------------------------------------------------------------------
// POST handler — process form submissions inline (self-post)
// ---------------------------------------------------------------------------

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (($_POST['_luster_embed_form'] ?? '') !== 'kim_update') {
        if ($luster_return ?? false) { return ''; }
        return;
    }
    if (!$_lusterApp->getCSRF()->validate((string) ($_POST['_csrf'] ?? ''))) {
        $_lusterApp->getSession()->set('lstr_flash_error', 'Security check failed. Please reload the page and try again.');
        if (!headers_sent()) { header('Location: ' . $_lusterRedirectTo, true, 302); exit; }
        echo '<script>window.location.replace(' . json_encode($_lusterRedirectTo) . ');</script>';
        return;
    }
    $response   = (new \Luster\Addons\Kim\Controllers\KimUpdateController($_lusterApp))
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

$_lusterCaptchaQ   = Captcha::getQuestion('kim_update');
$_lusterWrapperTpl = '';

$_lusterFlSelectHtml = '<select class="fl-select" id="kim-fanlisting" name="fanlisting_id" required>'
                     . '<option value="">&#8212; Select the fanlisting your entry is for &#8212;</option>';
foreach ($_lusterFanlistings as $_lusterFl) {
    $_lusterFlId       = htmlspecialchars((string) $_lusterFl->id, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $_lusterFlTitle    = htmlspecialchars((string) $_lusterFl->subject, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $_lusterFlSelected = ((string) ($_lusterOld['fanlisting_id'] ?? '')) === (string) $_lusterFl->id ? ' selected' : '';
    $_lusterFlSelectHtml .= "<option value=\"{$_lusterFlId}\"{$_lusterFlSelected}>{$_lusterFlTitle}</option>";
}
$_lusterFlSelectHtml .= '</select>';

$_lusterF = $_lusterApp->getFormFieldBuilder()->build([
    ['type' => 'text',     'name' => 'name',                 'id' => 'kim-name',                 'label' => 'Name',                                'required' => true,  'value' => (string) ($_lusterOld['name']          ?? ''), 'attrs' => ['maxlength' => '100', 'autocomplete' => 'name']],
    ['type' => 'email',    'name' => 'current_email',        'id' => 'kim-current-email',        'label' => 'Current Email',                       'required' => true,  'value' => (string) ($_lusterOld['current_email'] ?? ''), 'attrs' => ['maxlength' => '255', 'autocomplete' => 'email']],
    ['type' => 'password', 'name' => 'current_password',     'id' => 'kim-current-password',     'label' => 'Current Password',                    'required' => true,  'attrs' => ['maxlength' => '255', 'autocomplete' => 'current-password']],
    ['type' => 'select',   'name' => 'fanlisting_id',        'id' => 'kim-fanlisting',           'label' => 'Fanlisting',                          'required' => true,  'raw_input' => $_lusterFlSelectHtml],
    ['type' => 'email',    'name' => 'new_email',            'id' => 'kim-new-email',            'label' => 'New Email',                           'required' => false, 'value' => (string) ($_lusterOld['new_email']     ?? ''), 'attrs' => ['maxlength' => '255', 'autocomplete' => 'off'], 'hint' => 'Leave blank to keep current'],
    ['type' => 'url',      'name' => 'new_url',              'id' => 'kim-new-url',              'label' => 'New Website URL',         'required' => false, 'value' => (string) ($_lusterOld['new_url']       ?? ''), 'attrs' => ['maxlength' => '500', 'placeholder' => 'https://'], 'hint' => 'Leave blank to keep current'],
    ['type' => 'password', 'name' => 'new_password',         'id' => 'kim-new-password',         'label' => 'New Password',                        'required' => false, 'attrs' => ['maxlength' => '255', 'autocomplete' => 'new-password'], 'hint' => 'Min 8 characters; leave blank to keep current'],
    ['type' => 'password', 'name' => 'new_password_confirm', 'id' => 'kim-new-password-confirm', 'label' => 'Confirm New Password',                'required' => false, 'attrs' => ['maxlength' => '255', 'autocomplete' => 'new-password']],
    ['type' => 'text',     'name' => 'captcha_answer',       'id' => 'kim-captcha',              'label' => 'Captcha: ' . $_lusterCaptchaQ,        'required' => true,  'attrs' => ['maxlength' => '10', 'autocomplete' => 'off']],
], $_lusterWrapperTpl);

$_lusterFormHtml = null;
$_lusterUpdateTpl = $_lusterSiteSettings->get('kim_update_form_template') ?? '';
if ($_lusterUpdateTpl !== '') {
    $_lusterLostPwUrl = $_lusterLinkReset ?? $_lusterBaseUrl . '/kim/reset';
    $_lusterTplOut = TemplateEngine::conditionals($_lusterUpdateTpl, [
        'lost_password_url' => true,
    ]);
    $_lusterTokens = [
        '{field_name}'                       => $_lusterF['name'],
        '{field_name_input}'                 => $_lusterF['name_input'],
        '{field_current_email}'              => $_lusterF['current_email'],
        '{field_current_email_input}'        => $_lusterF['current_email_input'],
        '{field_current_password}'           => $_lusterF['current_password'],
        '{field_current_password_input}'     => $_lusterF['current_password_input'],
        '{field_fanlisting_id}'              => $_lusterF['fanlisting_id'],
        '{field_fanlisting_id_input}'        => $_lusterF['fanlisting_id_input'],
        '{field_new_email}'                  => $_lusterF['new_email'],
        '{field_new_email_input}'            => $_lusterF['new_email_input'],
        '{field_new_url}'                    => $_lusterF['new_url'],
        '{field_new_url_input}'              => $_lusterF['new_url_input'],
        '{field_new_password}'               => $_lusterF['new_password'],
        '{field_new_password_input}'         => $_lusterF['new_password_input'],
        '{field_new_password_confirm}'       => $_lusterF['new_password_confirm'],
        '{field_new_password_confirm_input}' => $_lusterF['new_password_confirm_input'],
        '{captcha_block}'                    => $_lusterF['captcha_answer'],
        '{captcha_block_input}'              => $_lusterF['captcha_answer_input'],
        '{captcha_question}'                 => View::e($_lusterCaptchaQ),
        '{submit_button}'                    => '<button type="submit" class="fl-submit">Update</button>',
        '{clear_button}'                     => '<button type="reset" class="fl-btn-secondary">Clear</button>',
        '{lost_password_url}'                => View::e($_lusterLostPwUrl),
    ];
    $_lusterInner    = FormTemplateRenderer::expandTokens($_lusterTplOut, $_lusterTokens);
    $_lusterFormHtml = FormTemplateRenderer::wrapForm($_lusterInner, $_lusterRedirectTo, $_lusterApp->getCSRF()->token(), false, false, 'kim_update');
}

$_lusterHtml = $_lusterApp->getView()->render('default/addons/kim/update.php', [
    'form_action' => $_lusterRedirectTo,
    'csrf_token'  => $_lusterApp->getCSRF()->token(),
    'errors'      => $_lusterError !== ''
        ? '<p>' . htmlspecialchars($_lusterError, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>'
        : '',
    'old'         => $_lusterOld,
    'f'           => $_lusterF,
    'form_html'   => $_lusterFormHtml,
    'form_marker' => 'kim_update',
    'base_url'    => $_lusterBaseUrl,
    'theme_path'  => $_lusterBaseUrl . '/assets/default/',
    'link_reset'  => $_lusterLinkReset,
    'link_list'   => $_lusterLinkList,
]);

$_lusterHtml .= \Luster\Core\View::attribution();

if ($luster_return ?? false) {
    return $_lusterHtml;
}

echo $_lusterHtml;
