<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Embeddable adoption application form. Lists every fanlisting that has
 "Available for Adoption" enabled in a dropdown so visitors can apply to
 adopt any of them from a single embedded form. No $luster_fanlisting_id is
 required. Requires the Adoption add-on to be installed. Includes CAPTCHA
 validation and redirects back to the host page with a flash message after
 submission.
*/

declare(strict_types=1);

use Luster\Core\Captcha;
use Luster\Core\View;
use Luster\Repositories\ListingRepo;
use Luster\Repositories\ListingSettingsRepo;
use Luster\Repositories\SiteSettingsRepo;
use Luster\Support\FormTemplateRenderer;

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
$_lusterApp = $_lusterApp ?? require $_lusterRoot . '/bootstrap.php';
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
    if (($_POST['_luster_embed_form'] ?? '') !== 'adopt_collective') {
        if ($luster_return ?? false) { return ''; }
        return;
    }
    if (!$_lusterApp->getCSRF()->validate((string) ($_POST['_csrf'] ?? ''))) {
        $_lusterApp->getSession()->set('lstr_flash_error', 'Security check failed. Please reload the page and try again.');
        if (!headers_sent()) { header('Location: ' . $_lusterRedirectTo, true, 302); exit; }
        echo '<script>window.location.replace(' . json_encode($_lusterRedirectTo) . ');</script>';
        return;
    }
    $response   = (new \Luster\Addons\Adoption\Controllers\AdoptPublicController($_lusterApp))
        ->collectiveSubmit(new \Luster\Core\Request(), []);
    $_lusterUrl = $response->getRedirectUrl() ?? $_lusterRedirectTo;
    if (!headers_sent()) { header('Location: ' . $_lusterUrl, true, 302); exit; }
    echo '<script>window.location.replace(' . json_encode($_lusterUrl) . ');</script>';
    return;
}

// ---------------------------------------------------------------------------
// 2. Consume flash data
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
// 3. Build the listing picker + form fields
// ---------------------------------------------------------------------------

$_lusterConfig     = $_lusterApp->getConfig();
$_lusterDb         = $_lusterApp->getDatabase();
$_lusterBaseUrl    = rtrim((string) ($_lusterConfig->get('embed_base_url', '') ?: $_lusterApp->getBaseUrl()), '/');
$_lusterFormAction = $_lusterRedirectTo;
$_lusterSiteName   = (string) $_lusterConfig->get('site_name', 'Collective');
$_lusterSiteUrl    = (string) $_lusterConfig->get('site_url', '');

$_lusterListingRepo  = new ListingRepo($_lusterDb, $_lusterConfig);
$_lusterSettingsRepo = new ListingSettingsRepo($_lusterDb);
$_lusterAdoptable    = $_lusterSettingsRepo->getAdoptableListings($_lusterListingRepo);

$_lusterCaptchaQ = Captcha::getQuestion('adopt');

$_lusterDescriptors = [];
if ($_lusterAdoptable !== []) {
    $_lusterOldListingId    = (string) ($_lusterOld['listing_id'] ?? '');
    $_lusterListingOptions  = '<option value="">&#8212; Select a Listing &#8212;</option>';
    foreach ($_lusterAdoptable as $_lusterRow) {
        $_lusterValue           = (string) $_lusterRow['id'];
        $_lusterSelected        = $_lusterValue === $_lusterOldListingId ? ' selected' : '';
        $_lusterListingOptions .= '<option value="' . View::e($_lusterValue) . '"' . $_lusterSelected . '>' . View::e($_lusterRow['title']) . '</option>';
    }
    $_lusterListingSelectHtml = '<select class="fl-select" id="adopt-listing" name="listing_id" required>' . $_lusterListingOptions . '</select>';

    $_lusterDescriptors[] = ['type' => 'select', 'name' => 'listing_id', 'id' => 'adopt-listing', 'label' => 'Listing', 'required' => true, 'raw_input' => $_lusterListingSelectHtml];
}

$_lusterDescriptors = array_merge($_lusterDescriptors, [
    ['type' => 'text',     'name' => 'name',           'id' => 'adopt-name',    'label' => 'Name',    'required' => true,  'value' => (string) ($_lusterOld['name']    ?? ''), 'attrs' => ['maxlength' => '100', 'autocomplete' => 'name']],
    ['type' => 'email',    'name' => 'email',          'id' => 'adopt-email',   'label' => 'Email',   'required' => true,  'value' => (string) ($_lusterOld['email']   ?? ''), 'attrs' => ['maxlength' => '255', 'autocomplete' => 'email']],
    ['type' => 'url',      'name' => 'url',            'id' => 'adopt-url',     'label' => 'Website URL', 'required' => false, 'value' => (string) ($_lusterOld['url']  ?? ''), 'attrs' => ['maxlength' => '500', 'placeholder' => 'https://', 'autocomplete' => 'url'], 'hint' => 'Optional'],
    ['type' => 'textarea', 'name' => 'message',        'id' => 'adopt-message', 'label' => 'Message', 'required' => false, 'value' => (string) ($_lusterOld['message'] ?? ''), 'attrs' => ['maxlength' => '2000', 'rows' => '5'], 'hint' => 'Optional'],
    ['type' => 'text',     'name' => 'captcha_answer', 'id' => 'adopt-captcha', 'label' => 'Captcha: ' . $_lusterCaptchaQ, 'required' => true, 'attrs' => ['maxlength' => '10', 'autocomplete' => 'off']],
]);

$_lusterF = $_lusterApp->getFormFieldBuilder()->build($_lusterDescriptors, '');

// ---------------------------------------------------------------------------
// 4. Render fragment
// ---------------------------------------------------------------------------

$_lusterTpl = (new SiteSettingsRepo($_lusterDb))->get('collective_adoption_form_template')
    ?: SiteSettingsRepo::defaultCollectiveAdoptionFormTemplate();

if ($_lusterAdoptable !== []) {
    $_lusterFieldListingId      = $_lusterF['listing_id'];
    $_lusterFieldListingIdInput = $_lusterF['listing_id_input'];
    $_lusterSubmitButton        = '<button type="submit" class="fl-submit">Submit Application</button>';
} else {
    $_lusterFieldListingId      = '<p>No listings are currently available for adoption.</p>';
    $_lusterFieldListingIdInput = '';
    $_lusterSubmitButton        = '';
}

$_lusterTokens = [
    '{site_name}'              => View::e($_lusterSiteName),
    '{field_listing_id}'       => $_lusterFieldListingId,
    '{field_listing_id_input}' => $_lusterFieldListingIdInput,
    '{field_name}'             => $_lusterF['name'],
    '{field_name_input}'       => $_lusterF['name_input'],
    '{field_email}'            => $_lusterF['email'],
    '{field_email_input}'      => $_lusterF['email_input'],
    '{field_url}'              => $_lusterF['url'],
    '{field_url_input}'        => $_lusterF['url_input'],
    '{field_message}'          => $_lusterF['message'],
    '{field_message_input}'    => $_lusterF['message_input'],
    '{captcha_block}'          => $_lusterF['captcha_answer'],
    '{captcha_block_input}'    => $_lusterF['captcha_answer_input'],
    '{captcha_question}'       => View::e($_lusterCaptchaQ),
    '{submit_button}'          => $_lusterSubmitButton,
    '{clear_button}'           => '<button type="reset" class="fl-btn-secondary">Clear</button>',
];

$_lusterInnerHtml = FormTemplateRenderer::expandTokens($_lusterTpl, $_lusterTokens);
$_lusterFormHtml  = FormTemplateRenderer::wrapForm($_lusterInnerHtml, $_lusterFormAction, $_lusterApp->getCSRF()->token(), false, false, 'adopt_collective');

$_lusterHtml = $_lusterApp->getView()->renderFragment('adopt.php', [
    'listing'      => null,
    'listing_title' => $_lusterSiteName,
    'listing_url'  => $_lusterSiteUrl,
    'page_title'   => 'Adopt a Fanlisting',
    'base_url'     => $_lusterApp->getBaseUrl(),
    'theme_path'   => $_lusterApp->getBaseUrl() . '/assets/css/default/',
    'form_action'  => $_lusterFormAction,
    'csrf_token'   => $_lusterApp->getCSRF()->token(),
    'errors'       => $_lusterError !== ''
        ? '<p>' . htmlspecialchars($_lusterError, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>'
        : '',
    'f'            => $_lusterF,
    'form_html'    => $_lusterFormHtml,
    'form_marker'  => 'adopt_collective',
]);


if ($luster_return ?? false) {
    return $_lusterHtml;
}

echo $_lusterHtml;
