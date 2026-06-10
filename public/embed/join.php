<?php

declare(strict_types=1);

use Luster\Core\Captcha;
use Luster\Core\Countries;
use Luster\Core\TemplateEngine;
use Luster\Repositories\ListingRepo;
use Luster\Repositories\ListingSettingsRepo;
use Luster\Repositories\FieldRepo;
use Luster\Support\FormFieldBuilder;

/**
 * Luster embed — join form fragment.
 *
 * Drop this into any PHP page to embed the join form without Luster's layout.
 * The surrounding HTML (header, footer, nav) comes from your own page.
 *
 * Usage:
 *   $luster_fanlisting_id = 1;     // required: listing ID (int)
 *   $luster_return        = false;  // optional: true = return string, false = echo
 *   require '/absolute/path/to/luster/public/embed/join.php';
 *
 * The form POSTs to Luster's join URL. After every outcome (success or error)
 * the user is redirected back to this same host page, where the embed
 * re-renders with a flash success or error message inline.
 *
 * To override the redirect target, set $luster_redirect_url before require.
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
// 3. Load listing data
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

$_lusterFieldRepo     = new FieldRepo($_lusterApp->getDatabase(), $_lusterApp->getConfig());
$_lusterFields        = $_lusterFieldRepo->getFieldsForListing($_lusterListingId);
$_lusterSettingsRepo  = new ListingSettingsRepo($_lusterApp->getDatabase());
$_lusterEmbedSettings = $_lusterSettingsRepo->getEmbedSettings($_lusterListingId);

// ---------------------------------------------------------------------------
// 4. Consume flash data (set by the controller after a failed POST)
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
// 5. Build wrapped fields and render fragment (no layout wrapper)
// ---------------------------------------------------------------------------

$_lusterBaseUrl    = rtrim((string) $_lusterApp->getConfig()->get('embed_base_url', ''), '/')
                   ?: $_lusterApp->getBaseUrl();
$_lusterFormAction = $_lusterBaseUrl . '/join/' . $_lusterListingId;
$_lusterWrapperTpl = $_lusterSettingsRepo->getWrapperTemplate($_lusterListingId) ?? '';

$_lusterRawJoinIntro = $_lusterSettingsRepo->getJoinIntroTemplate($_lusterListingId) ?? '';
$_lusterJoinIntroHtml = $_lusterRawJoinIntro !== ''
    ? TemplateEngine::render(
        $_lusterRawJoinIntro,
        escaped: [
            'listing_title'   => $_lusterListing->title,
            'listing_url'     => $_lusterListing->url,
            'listing_subject' => $_lusterListing->subject ?? '',
        ],
    )
    : '';

$_lusterCaptchaQ   = Captcha::getQuestion('join');
$_lusterCountrySel = Countries::buildSelect((string) ($_lusterOld['country'] ?? ''));
$_lusterFb         = $_lusterApp->getFormFieldBuilder();
$_lusterDescriptors = [
    ['type' => 'text',     'name' => 'name',             'id' => 'join-name',             'label' => 'Name',             'required' => true,  'value' => (string) ($_lusterOld['name']  ?? ''), 'attrs' => ['maxlength' => '100', 'autocomplete' => 'name']],
    ['type' => 'email',    'name' => 'email',            'id' => 'join-email',            'label' => 'Email',            'required' => true,  'value' => (string) ($_lusterOld['email'] ?? ''), 'attrs' => ['maxlength' => '255', 'autocomplete' => 'email']],
    ['type' => 'url',      'name' => 'url',              'id' => 'join-url',              'label' => 'Website URL',      'required' => false, 'value' => (string) ($_lusterOld['url']   ?? ''), 'attrs' => ['maxlength' => '500', 'placeholder' => 'https://', 'autocomplete' => 'url'], 'hint' => 'Optional'],
    ['type' => 'text',     'name' => 'country',          'id' => '',                      'label' => 'Country',          'required' => true,  'raw_input' => $_lusterCountrySel],
    ...$_lusterFb->descriptorsFromAdditionalFields($_lusterFields, $_lusterOld),
    ['type' => 'password', 'name' => 'password',         'id' => 'join-password',         'label' => 'Password',         'required' => false, 'attrs' => ['maxlength' => '255', 'autocomplete' => 'new-password'], 'hint' => 'Optional — one will be emailed to you if left blank'],
    ['type' => 'password', 'name' => 'password_confirm', 'id' => 'join-password-confirm', 'label' => 'Confirm Password', 'required' => false, 'attrs' => ['maxlength' => '255', 'autocomplete' => 'new-password']],
    ['type' => 'text',     'name' => 'captcha_answer',   'id' => 'join-captcha',          'label' => 'Captcha: ' . $_lusterCaptchaQ, 'required' => true, 'attrs' => ['maxlength' => '10', 'autocomplete' => 'off']],
];
$_lusterF = $_lusterFb->build($_lusterDescriptors, $_lusterWrapperTpl);

$_lusterHtml = $_lusterApp->getView()->renderFragment('join.php', [
    'listing'         => $_lusterListing,
    'listing_name'    => $_lusterListing->title,
    'listing_url'     => $_lusterListing->url,
    'page_title'      => 'Join',
    'form_action'     => $_lusterFormAction,
    'csrf_token'      => $_lusterApp->getCSRF()->token(),
    'errors'          => $_lusterError !== ''
        ? '<p>' . htmlspecialchars($_lusterError, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>'
        : '',
    'fields'          => $_lusterFields,
    'f'               => $_lusterF,
    'join_intro_html' => $_lusterJoinIntroHtml,
    'base_url'        => $_lusterApp->getBaseUrl(),
    'theme_path'      => $_lusterApp->getBaseUrl() . '/assets/default/',
]);

if ($luster_return ?? false) {
    return $_lusterHtml;
}

echo $_lusterHtml;
