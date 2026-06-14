<?php

declare(strict_types=1);

use Luster\Core\Captcha;
use Luster\Repositories\SiteSettingsRepo;

/**
 * Luster embed — collective code donation form fragment.
 *
 * Usage:
 *   $luster_return = false;  // optional: true = return string, false/omit = echo
 *   require '/absolute/path/to/luster/public/embed/collective-codes-submit.php';
 *
 * Override variables (set before require):
 *   $luster_redirect_url  — override the post-submit redirect URL
 */

// ---------------------------------------------------------------------------
// 1. Bootstrap Luster
// ---------------------------------------------------------------------------

$_lusterRoot = dirname(__DIR__, 2);

if (!is_file($_lusterRoot . '/vendor/autoload.php') || !is_file($_lusterRoot . '/config/app.php')) {
    $_lusterHtml = '<p class="fl-errors">Luster embed error: Luster is not installed correctly.</p>';
    if ($luster_return ?? false) { return $_lusterHtml; }
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
// 2. Consume flash
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
// 3. Build form data and render
// ---------------------------------------------------------------------------

$_lusterConfig    = $_lusterApp->getConfig();
$_lusterDb        = $_lusterApp->getDatabase();
$_lusterBaseUrl   = rtrim($_lusterApp->getBaseUrl(), '/');
$_lusterEmbedBase = rtrim((string) $_lusterConfig->get('embed_base_url', ''), '/');
if ($_lusterEmbedBase === '') {
    $_lusterEmbedBase = $_lusterBaseUrl;
}

$_lusterFormAction = $_lusterEmbedBase . '/codes/submit/0';
$_lusterCaptchaQ   = Captcha::getQuestion('code_submit');
$_lusterWrapperTpl = (new SiteSettingsRepo($_lusterDb))->get('collective_field_wrapper_template', '') ?? '';

$_lusterF = $_lusterApp->getFormFieldBuilder()->build([
    ['type' => 'file',  'name' => 'code_image',      'id' => 'code-image', 'label' => 'Upload Code',       'required' => true,  'attrs' => ['accept' => 'image/jpeg,image/png,image/gif,image/webp']],
    ['type' => 'text',  'name' => 'submitter_name',  'id' => 'sub-name',   'label' => 'Name (optional)',    'required' => true, 'value' => (string) ($_lusterOld['submitter_name']  ?? ''), 'attrs' => ['maxlength' => '150', 'autocomplete' => 'name']],
    ['type' => 'email', 'name' => 'submitter_email', 'id' => 'sub-email',  'label' => 'Email',              'required' => true,  'value' => (string) ($_lusterOld['submitter_email'] ?? ''), 'attrs' => ['maxlength' => '255', 'autocomplete' => 'email'], 'hint' => 'Required — used only to notify you when your code is approved.'],
    ['type' => 'url',   'name' => 'submitter_url',   'id' => 'sub-url',    'label' => 'Website URL (optional)', 'required' => false, 'value' => (string) ($_lusterOld['submitter_url']   ?? ''), 'attrs' => ['maxlength' => '255', 'placeholder' => 'https://', 'autocomplete' => 'url'], 'hint' => 'Displayed as a link on your donated code.'],
    ['type' => 'text',  'name' => 'captcha',         'id' => 'captcha',    'label' => 'Captcha: ' . $_lusterCaptchaQ, 'required' => true, 'attrs' => ['maxlength' => '10', 'autocomplete' => 'off']],
], $_lusterWrapperTpl);

$_lusterHtml = $_lusterApp->getView()->renderFragment('codes-submit.php', [
    'listing'      => null,
    'listing_name' => (string) ($_lusterConfig->get('app_name', '') ?: 'Collective'),
    'listing_url'  => '',
    'form_action'  => $_lusterFormAction,
    'csrf_token'   => $_lusterApp->getCSRF()->token(),
    'errors'       => htmlspecialchars($_lusterError, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
    'f'            => $_lusterF,
    'base_url'     => $_lusterBaseUrl,
]);

if ($luster_return ?? false) { return $_lusterHtml; }
echo $_lusterHtml;
