<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Embeddable affiliate application form for a single fanlisting. Renders a form
 where other site owners can apply to be listed as an affiliate. Submitted
 applications go into the pending queue for admin review. Includes CAPTCHA
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
use Luster\Support\ListingTemplateResolver;

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
$_lusterMarker = 'affiliate_apply:' . ((int) ($luster_fanlisting_id ?? 0));

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
    $_lusterAaLid = (int) ($luster_fanlisting_id ?? -1);
    if ($_lusterAaLid === 0) {
        $response = (new \Luster\Controllers\Public\AffiliateApplyController($_lusterApp))
            ->collectiveSubmit(new \Luster\Core\Request(), []);
    } else {
        $response = (new \Luster\Controllers\Public\AffiliateApplyController($_lusterApp))
            ->submit(new \Luster\Core\Request(), ['id' => $_lusterAaLid]);
    }
    $_lusterUrl = $response->getRedirectUrl() ?? $_lusterRedirectTo;
    if (!headers_sent()) { header('Location: ' . $_lusterUrl, true, 302); exit; }
    echo '<script>window.location.replace(' . json_encode($_lusterUrl) . ');</script>';
    return;
}

// ---------------------------------------------------------------------------
// 2. Load listing + settings
// ---------------------------------------------------------------------------

if (!isset($luster_fanlisting_id)) {
    $_lusterHtml = '<p class="fl-errors">Luster embed error: $luster_fanlisting_id must be set to a listing ID or 0 for collective.</p>';
    if ($luster_return ?? false) {
        return $_lusterHtml;
    }
    echo $_lusterHtml;
    return;
}

$_lusterListingId = (int) $luster_fanlisting_id;

if ($_lusterListingId < 0) {
    $_lusterHtml = '<p class="fl-errors">Luster embed error: $luster_fanlisting_id must be a valid listing ID or 0 for collective.</p>';
    if ($luster_return ?? false) {
        return $_lusterHtml;
    }
    echo $_lusterHtml;
    return;
}

// ---------------------------------------------------------------------------
// 3. Collective mode (fanlisting_id = 0)
// ---------------------------------------------------------------------------

if ($_lusterListingId === 0) {
    $_lusterSiteRepo = new SiteSettingsRepo($_lusterApp->getDatabase());

    if (($_lusterSiteRepo->get('collective_affiliate_apply_enabled') ?? '1') !== '1') {
        $_lusterHtml = '<p class="fl-errors">Collective affiliate applications are not open.</p>';
        if ($luster_return ?? false) {
            return $_lusterHtml;
        }
        echo $_lusterHtml;
        return;
    }

    $_lusterConfig  = $_lusterApp->getConfig();
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

    $_lusterBaseUrl    = rtrim((string) ($_lusterConfig->get('embed_base_url', '') ?: $_lusterApp->getBaseUrl()), '/');
    $_lusterFormAction = $_lusterRedirectTo;
    $_lusterSiteName   = (string) $_lusterConfig->get('site_name', 'Collective');
    $_lusterSiteUrl    = (string) $_lusterConfig->get('site_url', '');

    $_lusterCaptchaQ   = Captcha::getQuestion('affiliate_apply');
    $_lusterWrapperTpl = '';
    $_lusterF          = $_lusterApp->getFormFieldBuilder()->build([
        ['type' => 'text',     'name' => 'name',           'id' => 'aff-name',    'label' => 'Name',                      'required' => true,  'value' => (string) ($_lusterOld['name']    ?? ''), 'attrs' => ['maxlength' => '200', 'autocomplete' => 'organization']],
        ['type' => 'url',      'name' => 'url',            'id' => 'aff-url',     'label' => 'Website URL',                                  'required' => true,  'value' => (string) ($_lusterOld['url']     ?? ''), 'attrs' => ['maxlength' => '500', 'placeholder' => 'https://', 'autocomplete' => 'url']],
        ['type' => 'email',    'name' => 'email',          'id' => 'aff-email',   'label' => 'Email',          'required' => true, 'value' => (string) ($_lusterOld['email']   ?? ''), 'attrs' => ['maxlength' => '255', 'autocomplete' => 'email'], 'hint' => 'Used to notify you when your application is approved or rejected.'],
        ['type' => 'file',     'name' => 'image',          'id' => 'aff-image',   'label' => 'Site Button / Image (optional)', 'required' => false, 'attrs' => ['accept' => 'image/jpeg,image/png,image/gif,image/webp'], 'hint' => 'JPEG, PNG, GIF, or WebP. Max 500 KB.'],
        ['type' => 'textarea', 'name' => 'message',        'id' => 'aff-message', 'label' => 'Message (optional)',             'required' => false, 'value' => (string) ($_lusterOld['message'] ?? ''), 'attrs' => ['maxlength' => '2000', 'rows' => '4']],
        ['type' => 'text',     'name' => 'captcha_answer', 'id' => 'aff-captcha', 'label' => 'Captcha: ' . $_lusterCaptchaQ,  'required' => true,  'attrs' => ['maxlength' => '10', 'autocomplete' => 'off']],
    ], $_lusterWrapperTpl);

    $_lusterFormHtml = null;
    $_lusterCollAffTpl = $_lusterSiteRepo->get('collective_affiliate_apply_form_template') ?? '';
    if ($_lusterCollAffTpl !== '') {
        $_lusterAffTokens = [
            '{site_name}'             => View::e($_lusterSiteName),
            '{listing_title}'         => View::e($_lusterSiteName),
            '{listing_subject}'       => View::e($_lusterSiteName),
            '{listing_url}'           => View::e($_lusterSiteUrl),
            '{field_name}'            => $_lusterF['name'],
            '{field_name_input}'      => $_lusterF['name_input'],
            '{field_url}'             => $_lusterF['url'],
            '{field_url_input}'       => $_lusterF['url_input'],
            '{field_email}'           => $_lusterF['email'],
            '{field_email_input}'     => $_lusterF['email_input'],
            '{field_image}'           => $_lusterF['image'],
            '{field_image_input}'     => $_lusterF['image_input'],
            '{field_message}'         => $_lusterF['message'],
            '{field_message_input}'   => $_lusterF['message_input'],
            '{captcha_block}'         => $_lusterF['captcha_answer'],
            '{captcha_block_input}'   => $_lusterF['captcha_answer_input'],
            '{captcha_question}'      => View::e($_lusterCaptchaQ),
            '{submit_button}'         => '<button type="submit" class="fl-submit">Submit Application</button>',
            '{clear_button}'         => '<button type="reset" class="fl-btn-secondary">Clear</button>',
        ];
        $_lusterInnerHtml = FormTemplateRenderer::expandTokens($_lusterCollAffTpl, $_lusterAffTokens);
        $_lusterFormHtml  = FormTemplateRenderer::wrapForm(
            $_lusterInnerHtml, $_lusterFormAction, $_lusterApp->getCsrf()->token(), true, false, $_lusterMarker,
        );
    }

    $_lusterHtml = $_lusterApp->getView()->renderFragment('affiliate-apply.php', [
        'listing'      => null,
        'listing_title' => $_lusterSiteName,
        'listing_url'  => $_lusterSiteUrl,
        'page_title'   => 'Affiliate Application',
        'base_url'     => $_lusterApp->getBaseUrl(),
        'theme_path'   => $_lusterApp->getBaseUrl() . '/assets/default/',
        'form_action'  => $_lusterFormAction,
        'csrf_token'   => $_lusterApp->getCsrf()->token(),
        'errors'       => $_lusterError,
        'f'            => $_lusterF,
        'form_html'    => $_lusterFormHtml,
        'form_marker'  => $_lusterMarker,
    ]);

    if ($luster_return ?? false) {
        return $_lusterHtml;
    }
    echo $_lusterHtml;
    return;
}

// ---------------------------------------------------------------------------
// 3b. Listing mode (fanlisting_id > 0)
// ---------------------------------------------------------------------------

$_lusterSettingsRepo  = new ListingSettingsRepo($_lusterApp->getDatabase());
$_lusterApplySettings = $_lusterSettingsRepo->getAffiliateApplySettings($_lusterListingId);

if (!$_lusterApplySettings['enabled']) {
    $_lusterHtml = '<p class="fl-errors">Affiliate applications are not open for this listing.</p>';
    if ($luster_return ?? false) {
        return $_lusterHtml;
    }
    echo $_lusterHtml;
    return;
}

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
// 4. Consume flash data + build form state
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

$_lusterBaseUrl  = rtrim((string) ($_lusterApp->getConfig()->get('embed_base_url', '') ?: $_lusterApp->getBaseUrl()), '/');
$_lusterEmbedSettings = $_lusterSettingsRepo->getResolvedEmbedSettings($_lusterListingId);

$_lusterFormAction = $_lusterRedirectTo;

// ---------------------------------------------------------------------------
// 5. Render fragment
// ---------------------------------------------------------------------------

$_lusterCaptchaQ   = Captcha::getQuestion('affiliate_apply');
$_lusterWrapperTpl = $_lusterSettingsRepo->getWrapperTemplate($_lusterListingId) ?? '';
$_lusterF          = $_lusterApp->getFormFieldBuilder()->build([
    ['type' => 'text',     'name' => 'name',           'id' => 'aff-name',    'label' => 'Name',                      'required' => true,  'value' => (string) ($_lusterOld['name']    ?? ''), 'attrs' => ['maxlength' => '200', 'autocomplete' => 'organization']],
    ['type' => 'url',      'name' => 'url',            'id' => 'aff-url',     'label' => 'Website URL',                       'required' => true,  'value' => (string) ($_lusterOld['url']     ?? ''), 'attrs' => ['maxlength' => '500', 'placeholder' => 'https://', 'autocomplete' => 'url']],
    ['type' => 'email',    'name' => 'email',          'id' => 'aff-email',   'label' => 'Email',          'required' => true,  'value' => (string) ($_lusterOld['email']   ?? ''), 'attrs' => ['maxlength' => '255', 'autocomplete' => 'email'], 'hint' => 'Used to notify you when your application is approved or rejected.'],
    ['type' => 'file',     'name' => 'image',          'id' => 'aff-image',   'label' => 'Site Button / Image (optional)', 'required' => false, 'attrs' => ['accept' => 'image/jpeg,image/png,image/gif,image/webp'], 'hint' => 'JPEG, PNG, GIF, or WebP. Max 500 KB.'],
    ['type' => 'textarea', 'name' => 'message',        'id' => 'aff-message', 'label' => 'Message (optional)',             'required' => false, 'value' => (string) ($_lusterOld['message'] ?? ''), 'attrs' => ['maxlength' => '2000', 'rows' => '4']],
    ['type' => 'text',     'name' => 'captcha_answer', 'id' => 'aff-captcha', 'label' => 'Captcha: ' . $_lusterCaptchaQ,  'required' => true,  'attrs' => ['maxlength' => '10', 'autocomplete' => 'off']],
], $_lusterWrapperTpl);

$_lusterFormHtml        = null;
$_lusterGlobalTpls      = (new SiteSettingsRepo($_lusterApp->getDatabase()))->getGlobalTemplates();
$_lusterAffApplyResolved = ListingTemplateResolver::resolve(
    $_lusterSettingsRepo->getAffiliateApplyFormTemplate($_lusterListingId),
    $_lusterGlobalTpls['listing_default_affiliate_apply_form_template'] ?? null,
    '',
);
$_lusterAffApplyTpl = $_lusterAffApplyResolved['should_render_template'] ? $_lusterAffApplyResolved['value'] : '';
if ($_lusterAffApplyTpl !== '') {
    $_lusterAffTokens = [
        '{listing_title}'         => View::e((string) $_lusterListing->title),
        '{listing_subject}'       => View::e((string) $_lusterListing->subject),
        '{listing_url}'           => View::e((string) $_lusterListing->url),
        '{field_name}'            => $_lusterF['name'],
        '{field_name_input}'      => $_lusterF['name_input'],
        '{field_url}'             => $_lusterF['url'],
        '{field_url_input}'       => $_lusterF['url_input'],
        '{field_email}'           => $_lusterF['email'],
        '{field_email_input}'     => $_lusterF['email_input'],
        '{field_image}'           => $_lusterF['image'],
        '{field_image_input}'     => $_lusterF['image_input'],
        '{field_message}'         => $_lusterF['message'],
        '{field_message_input}'   => $_lusterF['message_input'],
        '{captcha_block}'         => $_lusterF['captcha_answer'],
        '{captcha_block_input}'   => $_lusterF['captcha_answer_input'],
        '{captcha_question}'      => View::e($_lusterCaptchaQ),
        '{submit_button}'         => '<button type="submit" class="fl-submit">Submit Application</button>',
        '{clear_button}'          => '<button type="reset" class="fl-btn-secondary">Clear</button>',
    ];
    $_lusterInnerHtml = FormTemplateRenderer::expandTokens($_lusterAffApplyTpl, $_lusterAffTokens);
    $_lusterFormHtml  = FormTemplateRenderer::wrapForm(
        $_lusterInnerHtml, $_lusterFormAction, $_lusterApp->getCsrf()->token(), true, false, $_lusterMarker,
    );
}

$_lusterHtml = $_lusterApp->getView()->renderFragment('affiliate-apply.php', [
    'listing'      => $_lusterListing,
    'listing_title' => (string) $_lusterListing->title,
    'listing_url'  => (string) $_lusterListing->url,
    'page_title'   => 'Affiliate Application — ' . $_lusterListing->title,
    'base_url'     => $_lusterApp->getBaseUrl(),
    'theme_path'   => rtrim((string) $_lusterListing->url, '/') . '/' . ltrim((string) ($_lusterEmbedSettings['theme_path'] ?? 'theme'), '/'),
    'form_action'  => $_lusterFormAction,
    'csrf_token'   => $_lusterApp->getCsrf()->token(),
    'errors'       => $_lusterError,
    'f'            => $_lusterF,
    'form_html'    => $_lusterFormHtml,
    'form_marker'  => $_lusterMarker,
]);


if ($luster_return ?? false) {
    return $_lusterHtml;
}

echo $_lusterHtml;
