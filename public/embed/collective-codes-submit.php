<?php

declare(strict_types=1);

use Luster\Core\Captcha;
use Luster\Core\View;
use Luster\Repositories\SiteSettingsRepo;
use Luster\Support\FormTemplateRenderer;

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
$luster_attribution = \Luster\Core\View::attribution();
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
    if (($_POST['_luster_embed_form'] ?? '') !== 'codes_submit_collective') {
        if ($luster_return ?? false) { return ''; }
        return;
    }
    if (!$_lusterApp->getCSRF()->validate((string) ($_POST['_csrf'] ?? ''))) {
        $_lusterApp->getSession()->set('lstr_flash_error', 'Security check failed. Please reload the page and try again.');
        if (!headers_sent()) { header('Location: ' . $_lusterRedirectTo, true, 302); exit; }
        echo '<script>window.location.replace(' . json_encode($_lusterRedirectTo) . ');</script>';
        return;
    }
    $response   = (new \Luster\Controllers\Public\CodesSubmitController($_lusterApp))
        ->submit(new \Luster\Core\Request(), ['id' => 0]);
    $_lusterUrl = $response->getRedirectUrl() ?? $_lusterRedirectTo;
    if (!headers_sent()) { header('Location: ' . $_lusterUrl, true, 302); exit; }
    echo '<script>window.location.replace(' . json_encode($_lusterUrl) . ');</script>';
    return;
}

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

$_lusterFormAction = $_lusterRedirectTo;
$_lusterCaptchaQ   = Captcha::getQuestion('code_submit');
$_lusterWrapperTpl = '';

$_lusterF = $_lusterApp->getFormFieldBuilder()->build([
    ['type' => 'file',  'name' => 'code_image',      'id' => 'code-image', 'label' => 'Upload Code',       'required' => true,  'attrs' => ['accept' => 'image/jpeg,image/png,image/gif,image/webp'], 'hint' => 'JPEG, PNG, GIF, or WebP. Max 500 KB.'],
    ['type' => 'text',  'name' => 'submitter_name',  'id' => 'sub-name',   'label' => 'Name',    'required' => true, 'value' => (string) ($_lusterOld['submitter_name']  ?? ''), 'attrs' => ['maxlength' => '150', 'autocomplete' => 'name']],
    ['type' => 'email', 'name' => 'submitter_email', 'id' => 'sub-email',  'label' => 'Email',              'required' => true,  'value' => (string) ($_lusterOld['submitter_email'] ?? ''), 'attrs' => ['maxlength' => '255', 'autocomplete' => 'email'], 'hint' => 'Required — used only to notify you when your code is approved.'],
    ['type' => 'url',   'name' => 'submitter_url',   'id' => 'sub-url',    'label' => 'Website URL (optional)', 'required' => false, 'value' => (string) ($_lusterOld['submitter_url']   ?? ''), 'attrs' => ['maxlength' => '255', 'placeholder' => 'https://', 'autocomplete' => 'url'], 'hint' => 'Displayed as a link on your donated code.'],
    ['type' => 'text',  'name' => 'captcha',         'id' => 'captcha',    'label' => 'Captcha: ' . $_lusterCaptchaQ, 'required' => true, 'attrs' => ['maxlength' => '10', 'autocomplete' => 'off']],
], $_lusterWrapperTpl);

$_lusterSiteName = (string) ($_lusterConfig->get('site_name', '') ?: 'Collective');

$_lusterFormHtml = null;
$_lusterCsTpl    = (new SiteSettingsRepo($_lusterDb))->get('collective_codes_submit_form_template');
if ($_lusterCsTpl !== null && trim($_lusterCsTpl) !== '') {
    $_lusterCsTokens = [
        '{site_name}'                    => View::e($_lusterSiteName),
        '{field_code_image}'            => $_lusterF['code_image'],
        '{field_code_image_input}'      => $_lusterF['code_image_input'],
        '{field_submitter_name}'        => $_lusterF['submitter_name'],
        '{field_submitter_name_input}'  => $_lusterF['submitter_name_input'],
        '{field_submitter_email}'       => $_lusterF['submitter_email'],
        '{field_submitter_email_input}' => $_lusterF['submitter_email_input'],
        '{field_submitter_url}'         => $_lusterF['submitter_url'],
        '{field_submitter_url_input}'   => $_lusterF['submitter_url_input'],
        '{captcha_block}'               => $_lusterF['captcha'],
        '{captcha_block_input}'         => $_lusterF['captcha_input'],
        '{captcha_question}'            => View::e($_lusterCaptchaQ),
        '{submit_button}'               => '<button type="submit" class="fl-submit">Submit Code</button>',
        '{clear_button}'                 => '<button type="reset" class="fl-btn-secondary">Clear</button>',
    ];
    $_lusterInnerHtml = FormTemplateRenderer::expandTokens($_lusterCsTpl, $_lusterCsTokens);
    $_lusterFormHtml  = FormTemplateRenderer::wrapForm(
        $_lusterInnerHtml,
        $_lusterFormAction,
        $_lusterApp->getCSRF()->token(),
        true,
        false,
        'codes_submit_collective',
    );
}

$_lusterHtml = $_lusterApp->getView()->renderFragment('codes-submit.php', [
    'listing'      => null,
    'listing_name' => $_lusterSiteName,
    'listing_url'  => '',
    'form_action'  => $_lusterFormAction,
    'csrf_token'   => $_lusterApp->getCSRF()->token(),
    'errors'       => htmlspecialchars($_lusterError, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
    'f'            => $_lusterF,
    'form_html'    => $_lusterFormHtml,
    'form_marker'  => 'codes_submit_collective',
    'base_url'     => $_lusterBaseUrl,
]);


if ($luster_return ?? false) { return $_lusterHtml; }
echo $_lusterHtml;
