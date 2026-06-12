<?php

declare(strict_types=1);

use Luster\Core\Captcha;
use Luster\Core\TemplateEngine;
use Luster\Core\View;
use Luster\Repositories\ListingRepo;
use Luster\Repositories\ListingSettingsRepo;
use Luster\Support\FormTemplateRenderer;

/**
 * Luster embed — adoption application form fragment.
 *
 * Drop this into any PHP page to embed the adoption form without Luster's layout.
 * The listing must have "Available for adoption" enabled in its settings.
 *
 * Usage:
 *   $luster_fanlisting_id = 1;     // required: listing ID (int)
 *   $luster_return        = false;  // optional: true = return string, false = echo
 *   require '/absolute/path/to/luster/public/embed/adopt.php';
 */

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

// ---------------------------------------------------------------------------
// 2. Bootstrap Luster
// ---------------------------------------------------------------------------

$_lusterRoot = dirname(__DIR__, 2);

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
// 3. Load listing and check adoption flag
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

$_lusterSettingsRepo = new ListingSettingsRepo($_lusterApp->getDatabase());
if (!$_lusterSettingsRepo->getForAdoption($_lusterListingId)) {
    $_lusterHtml = '<p class="fl-errors">Luster embed error: listing #' . $_lusterListingId . ' is not accepting adoption applications.</p>';
    if ($luster_return ?? false) {
        return $_lusterHtml;
    }
    echo $_lusterHtml;
    return;
}

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
// 5. Render fragment
// ---------------------------------------------------------------------------

$_lusterBaseUrl    = rtrim((string) $_lusterApp->getConfig()->get('embed_base_url', ''), '/')
                   ?: $_lusterApp->getBaseUrl();
$_lusterFormAction = $_lusterBaseUrl . '/adopt/' . $_lusterListingId;

$_lusterCaptchaQ   = Captcha::getQuestion('adopt');
$_lusterWrapperTpl = $_lusterSettingsRepo->getWrapperTemplate($_lusterListingId) ?? '';

$_lusterRawAdoptIntro    = $_lusterSettingsRepo->getAdoptionIntroTemplate($_lusterListingId) ?? '';
$_lusterAdoptionIntroHtml = $_lusterRawAdoptIntro !== ''
    ? TemplateEngine::render(
        $_lusterRawAdoptIntro,
        escaped: [
            'listing_title'   => $_lusterListing->title,
            'listing_url'     => $_lusterListing->url,
            'listing_subject' => $_lusterListing->subject ?? '',
        ],
    )
    : '';
$_lusterF          = $_lusterApp->getFormFieldBuilder()->build([
    ['type' => 'text',     'name' => 'name',           'id' => 'adopt-name',    'label' => 'Name',    'required' => true,  'value' => (string) ($_lusterOld['name']    ?? ''), 'attrs' => ['maxlength' => '100', 'autocomplete' => 'name']],
    ['type' => 'email',    'name' => 'email',          'id' => 'adopt-email',   'label' => 'Email',   'required' => true,  'value' => (string) ($_lusterOld['email']   ?? ''), 'attrs' => ['maxlength' => '255', 'autocomplete' => 'email']],
    ['type' => 'url',      'name' => 'url',            'id' => 'adopt-url',     'label' => 'Website URL', 'required' => false, 'value' => (string) ($_lusterOld['url']  ?? ''), 'attrs' => ['maxlength' => '500', 'placeholder' => 'https://', 'autocomplete' => 'url'], 'hint' => 'Optional'],
    ['type' => 'textarea', 'name' => 'message',        'id' => 'adopt-message', 'label' => 'Message', 'required' => false, 'value' => (string) ($_lusterOld['message'] ?? ''), 'attrs' => ['maxlength' => '2000', 'rows' => '5'], 'hint' => 'Optional'],
    ['type' => 'text',     'name' => 'captcha_answer', 'id' => 'adopt-captcha', 'label' => 'Captcha: ' . $_lusterCaptchaQ, 'required' => true, 'attrs' => ['maxlength' => '10', 'autocomplete' => 'off']],
], $_lusterWrapperTpl);

$_lusterFormHtml   = null;
$_lusterAdoptFormTpl = $_lusterSettingsRepo->getAdoptFormTemplate($_lusterListingId) ?? '';
if ($_lusterAdoptFormTpl !== '') {
    $_lusterAdoptTokens = [
        '{listing_title}'        => View::e($_lusterListing->title),
        '{listing_url}'          => View::e($_lusterListing->url),
        '{field_name}'           => $_lusterF['name'],
        '{field_name_input}'     => $_lusterF['name_input'],
        '{field_email}'          => $_lusterF['email'],
        '{field_email_input}'    => $_lusterF['email_input'],
        '{field_url}'            => $_lusterF['url'],
        '{field_url_input}'      => $_lusterF['url_input'],
        '{field_message}'        => $_lusterF['message'],
        '{field_message_input}'  => $_lusterF['message_input'],
        '{captcha_block}'        => $_lusterF['captcha_answer'],
        '{captcha_block_input}'  => $_lusterF['captcha_answer_input'],
        '{captcha_question}'     => View::e($_lusterCaptchaQ),
        '{submit_button}'        => '<button type="submit" class="fl-submit">Submit Application</button>',
        '{clear_button}'         => '<button type="reset" class="fl-btn-secondary">Clear</button>',
    ];
    $_lusterInnerHtml = FormTemplateRenderer::expandTokens($_lusterAdoptFormTpl, $_lusterAdoptTokens);
    $_lusterFormHtml  = FormTemplateRenderer::wrapForm($_lusterInnerHtml, $_lusterFormAction, $_lusterApp->getCSRF()->token(), false);
}

$_lusterHtml = $_lusterApp->getView()->renderFragment('adopt.php', [
    'listing'             => $_lusterListing,
    'listing_name'        => $_lusterListing->title,
    'listing_url'         => $_lusterListing->url,
    'page_title'          => 'Adopt ' . $_lusterListing->title,
    'form_action'         => $_lusterFormAction,
    'csrf_token'          => $_lusterApp->getCSRF()->token(),
    'errors'              => $_lusterError !== ''
        ? '<p>' . htmlspecialchars($_lusterError, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>'
        : '',
    'f'                   => $_lusterF,
    'form_html'           => $_lusterFormHtml,
    'adoption_intro_html' => $_lusterAdoptionIntroHtml,
    'base_url'            => $_lusterApp->getBaseUrl(),
    'theme_path'          => $_lusterApp->getBaseUrl() . '/assets/default/',
]);

if ($luster_return ?? false) {
    return $_lusterHtml;
}

echo $_lusterHtml;
