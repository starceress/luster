<?php

declare(strict_types=1);

use Luster\Core\Captcha;
use Luster\Core\View;
use Luster\Repositories\CodesRepo;
use Luster\Repositories\ListingRepo;
use Luster\Repositories\ListingSettingsRepo;
use Luster\Repositories\SiteSettingsRepo;
use Luster\Support\FormTemplateRenderer;
use Luster\Support\ListingTemplateResolver;

/**
 * Luster embed — fan code submission form fragment.
 *
 * Drop into any PHP page:
 *   $luster_fanlisting_id = 1;      // listing ID; omit or set to 0 for collective codes
 *   $luster_return        = true;   // true = return string, false/omit = echo
 *   echo require '/path/to/luster/public/embed/codes-submit.php';
 *
 * The form POSTs to Luster's public /codes/submit/{id} route. On success,
 * Luster redirects back to this page and the embed automatically shows the
 * confirmation message from the session flash.
 */

// ---------------------------------------------------------------------------
// 1. Resolve listing ID / collective mode
// ---------------------------------------------------------------------------

$_lusterRawListingId = isset($luster_fanlisting_id) ? (int) $luster_fanlisting_id : 0;
$_lusterIsCollective = ($_lusterRawListingId === 0);
$_lusterListingId    = $_lusterIsCollective ? null : $_lusterRawListingId;

// ---------------------------------------------------------------------------
// 2. Bootstrap Luster
// ---------------------------------------------------------------------------

$_lusterRoot = dirname(__DIR__, 2); // public/embed/ → public/ → project root

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
$_lusterApp = $_lusterApp ?? require $_lusterRoot . '/bootstrap.php';
$_lusterApp->getSession()->start();
$_lusterRedirectTo = (string) ($luster_redirect_url ?? '');
if ($_lusterRedirectTo === '') {
    $_lusterProto      = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $_lusterRedirectTo = $_lusterProto . '://' . ($_SERVER['HTTP_HOST'] ?? '') . ($_SERVER['REQUEST_URI'] ?? '');
}
$_lusterApp->getSession()->set('lstr_embed_return_to', $_lusterRedirectTo);

// ---------------------------------------------------------------------------
// 3. Load listing (or collective identity)
// ---------------------------------------------------------------------------

$_lusterDb     = $_lusterApp->getDatabase();
$_lusterConfig = $_lusterApp->getConfig();

if ($_lusterIsCollective) {
    $_lusterListing     = null;
    $_lusterListingName = (string) ($_lusterConfig->get('app_name', '') ?: 'Collective');
    $_lusterListingUrl  = '';
} else {
    $_lusterListingRepo = new ListingRepo($_lusterDb, $_lusterConfig);
    $_lusterListing     = $_lusterListingRepo->getById($_lusterListingId);

    if ($_lusterListing === null) {
        $_lusterHtml = '<p class="fl-errors">Luster embed error: listing #' . $_lusterListingId . ' not found.</p>';
        if ($luster_return ?? false) {
            return $_lusterHtml;
        }
        echo $_lusterHtml;
        return;
    }

    $_lusterListingName = $_lusterListing->title;
    $_lusterListingUrl  = $_lusterListing->url ?? '';

    $_lusterEmbedCfg = (new \Luster\Repositories\ListingSettingsRepo($_lusterDb))->getEmbedSettings($_lusterListingId);
    if (!($_lusterEmbedCfg['codes_submit_enabled'] ?? true)) {
        if ($luster_return ?? false) { return ''; }
        return;
    }
}

$_lusterSession   = $_lusterApp->getSession();
$_lusterBaseUrl   = rtrim($_lusterApp->getBaseUrl(), '/');
$_lusterEmbedBase = rtrim((string) $_lusterConfig->get('embed_base_url', ''), '/');
if ($_lusterEmbedBase === '') {
    $_lusterEmbedBase = $_lusterBaseUrl;
}

// Build form action — POSTs to Luster's public route (0 = collective)
$_lusterSubmitId   = $_lusterIsCollective ? 0 : $_lusterListingId;
$_lusterFormAction = $_lusterEmbedBase . '/codes/submit/' . $_lusterSubmitId;

// ---------------------------------------------------------------------------
// 4. Consume flash
// ---------------------------------------------------------------------------

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
// 5. Build wrapped fields and render
// ---------------------------------------------------------------------------

$_lusterCaptchaQ = Captcha::getQuestion('code_submit');

if ($_lusterIsCollective) {
    $_lusterWrapperTpl = (new \Luster\Repositories\SiteSettingsRepo($_lusterDb))->get('collective_field_wrapper_template', '') ?? '';
} else {
    $_lusterCsSettingsRepo = new \Luster\Repositories\ListingSettingsRepo($_lusterDb);
    $_lusterWrapperTpl     = $_lusterCsSettingsRepo->getWrapperTemplate($_lusterListingId) ?? '';
}

$_lusterF = $_lusterApp->getFormFieldBuilder()->build([
    ['type' => 'file',  'name' => 'code_image',      'id' => 'code-image',  'label' => 'Upload Code',         'required' => true,  'attrs' => ['accept' => 'image/jpeg,image/png,image/gif,image/webp']],
    ['type' => 'text',  'name' => 'submitter_name',  'id' => 'sub-name',    'label' => 'Name (optional)',      'required' => false, 'value' => (string) ($_lusterOld['submitter_name']  ?? ''), 'attrs' => ['maxlength' => '150', 'autocomplete' => 'name']],
    ['type' => 'email', 'name' => 'submitter_email', 'id' => 'sub-email',   'label' => 'Email',               'required' => true,  'value' => (string) ($_lusterOld['submitter_email'] ?? ''), 'attrs' => ['maxlength' => '255', 'autocomplete' => 'email'], 'hint' => 'Required — used only to notify you when your code is approved.'],
    ['type' => 'url',   'name' => 'submitter_url',   'id' => 'sub-url',     'label' => 'Website (optional)',   'required' => false, 'value' => (string) ($_lusterOld['submitter_url']   ?? ''), 'attrs' => ['maxlength' => '255', 'placeholder' => 'https://', 'autocomplete' => 'url'], 'hint' => 'Displayed as a link on your donated code.'],
    ['type' => 'text',  'name' => 'captcha',         'id' => 'captcha',     'label' => 'Captcha: ' . $_lusterCaptchaQ, 'required' => true, 'attrs' => ['maxlength' => '10', 'autocomplete' => 'off']],
], $_lusterWrapperTpl);

$_lusterFormHtml = null;
if (!$_lusterIsCollective) {
    $_lusterGlobalTpls = (new SiteSettingsRepo($_lusterDb))->getGlobalTemplates();
    $_lusterCsResolved = ListingTemplateResolver::resolve(
        $_lusterCsSettingsRepo->getCodesSubmitFormTemplate($_lusterListingId),
        $_lusterGlobalTpls['listing_default_codes_submit_form_template'] ?? null,
        '',
    );
    $_lusterCsFormTpl  = $_lusterCsResolved['should_render_template'] ? $_lusterCsResolved['value'] : '';
    if ($_lusterCsFormTpl !== '') {
        $_lusterCsTokens = [
            '{listing_title}'               => View::e($_lusterListingName),
            '{listing_url}'                 => View::e($_lusterListingUrl),
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
        ];
        $_lusterInnerHtml = FormTemplateRenderer::expandTokens($_lusterCsFormTpl, $_lusterCsTokens);
        $_lusterFormHtml  = FormTemplateRenderer::wrapForm(
            $_lusterInnerHtml,
            $_lusterFormAction,
            $_lusterApp->getCSRF()->token(),
            true,
        );
    }
}

$_lusterHtml = $_lusterApp->getView()->renderFragment('codes-submit.php', [
    'listing'      => $_lusterListing,
    'listing_name' => $_lusterListingName,
    'listing_url'  => $_lusterListingUrl,
    'form_action'  => $_lusterFormAction,
    'csrf_token'   => $_lusterApp->getCSRF()->token(),
    'errors'       => htmlspecialchars($_lusterError, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
    'f'            => $_lusterF,
    'form_html'    => $_lusterFormHtml,
    'base_url'     => $_lusterBaseUrl,
]);

if ($luster_return ?? false) {
    return $_lusterHtml;
}

echo $_lusterHtml;
