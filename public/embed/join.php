<?php

declare(strict_types=1);

use Luster\Core\Captcha;
use Luster\Core\Countries;
use Luster\Core\View;
use Luster\Repositories\FieldRepo;
use Luster\Repositories\ListingRepo;
use Luster\Repositories\ListingSettingsRepo;
use Luster\Repositories\SiteSettingsRepo;
use Luster\Support\FormFieldBuilder;
use Luster\Support\FormTemplateRenderer;
use Luster\Support\ListingTemplateResolver;

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
 * The form self-posts to the host page URL. After every outcome (success or
 * error) the user is redirected back to this same page, where the embed
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
$luster_attribution = \Luster\Core\View::attribution();
$_lusterApp->getSession()->start();
$_lusterRedirectTo = (string) ($luster_redirect_url ?? '');
if ($_lusterRedirectTo === '') {
    $_lusterProto      = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $_lusterRedirectTo = $_lusterProto . '://' . ($_SERVER['HTTP_HOST'] ?? '') . ($_SERVER['REQUEST_URI'] ?? '');
}
$_lusterApp->getSession()->set('lstr_embed_return_to', $_lusterRedirectTo);
$_lusterMarker = 'join:' . $_lusterListingId;

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
    $response    = (new \Luster\Controllers\Public\JoinController($_lusterApp))
        ->submit(new \Luster\Core\Request(), ['id' => $_lusterListingId]);
    $_lusterUrl  = $response->getRedirectUrl() ?? $_lusterRedirectTo;
    if (!headers_sent()) { header('Location: ' . $_lusterUrl, true, 302); exit; }
    echo '<script>window.location.replace(' . json_encode($_lusterUrl) . ');</script>';
    return;
}

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
$_lusterFormAction = $_lusterRedirectTo;
$_lusterWrapperTpl = $_lusterSettingsRepo->getWrapperTemplate($_lusterListingId) ?? '';

$_lusterCaptchaQ      = Captcha::getQuestion('join');
$_lusterCountrySel    = Countries::buildSelect((string) ($_lusterOld['country'] ?? ''));
$_lusterCountryEnabled = (bool) ($_lusterEmbedSettings['country_field_enabled'] ?? true);
$_lusterFb         = $_lusterApp->getFormFieldBuilder();
$_lusterDescriptors = [
    ['type' => 'text',     'name' => 'name',             'id' => 'join-name',             'label' => 'Name',             'required' => true,  'value' => (string) ($_lusterOld['name']  ?? ''), 'attrs' => ['maxlength' => '100', 'autocomplete' => 'name']],
    ['type' => 'email',    'name' => 'email',            'id' => 'join-email',            'label' => 'Email',            'required' => true,  'value' => (string) ($_lusterOld['email'] ?? ''), 'attrs' => ['maxlength' => '255', 'autocomplete' => 'email']],
    ['type' => 'url',      'name' => 'url',              'id' => 'join-url',              'label' => 'Website URL',      'required' => false, 'value' => (string) ($_lusterOld['url']   ?? ''), 'attrs' => ['maxlength' => '500', 'placeholder' => 'https://', 'autocomplete' => 'url']],
    ['type' => 'text',     'name' => 'country',          'id' => '',                      'label' => 'Country',          'required' => $_lusterCountryEnabled, 'raw_input' => $_lusterCountrySel],
    ...$_lusterFb->descriptorsFromAdditionalFields($_lusterFields, $_lusterOld),
    ['type' => 'password', 'name' => 'password',         'id' => 'join-password',         'label' => 'Password',         'required' => false, 'attrs' => ['maxlength' => '255', 'autocomplete' => 'new-password'], 'hint' => 'Minimum 12 characters. If left blank, one will be generated for you.'],
    ['type' => 'password', 'name' => 'password_confirm', 'id' => 'join-password-confirm', 'label' => 'Confirm Password', 'required' => false, 'attrs' => ['maxlength' => '255', 'autocomplete' => 'new-password']],
    ['type' => 'text',     'name' => 'captcha_answer',   'id' => 'join-captcha',          'label' => 'Captcha: ' . $_lusterCaptchaQ, 'required' => true, 'attrs' => ['maxlength' => '10', 'autocomplete' => 'off']],
];
$_lusterF = $_lusterFb->build($_lusterDescriptors, $_lusterWrapperTpl);

$_lusterFormHtml     = null;
$_lusterGlobalTpls   = (new SiteSettingsRepo($_lusterApp->getDatabase()))->getGlobalTemplates();
$_lusterJoinResolved = ListingTemplateResolver::resolve(
    $_lusterSettingsRepo->getJoinFormTemplate($_lusterListingId),
    $_lusterGlobalTpls['listing_default_join_form_template'] ?? null,
    '',
);
$_lusterJoinFormTpl  = $_lusterJoinResolved['should_render_template'] ? $_lusterJoinResolved['value'] : '';
if ($_lusterJoinFormTpl !== '') {
    $_lusterAdditionalFieldsHtml      = implode("\n", array_map(
        fn($field) => $_lusterF['field_' . $field->name] ?? '', $_lusterFields,
    ));
    $_lusterAdditionalFieldsInputHtml = implode("\n", array_map(
        fn($field) => $_lusterF['field_' . $field->name . '_input'] ?? '', $_lusterFields,
    ));

    $_lusterTokens = [
        '{listing_title}'                => View::e($_lusterListing->title),
        '{listing_subject}'              => View::e($_lusterListing->subject),
        '{listing_url}'                  => View::e($_lusterListing->url),
        '{field_name}'                    => $_lusterF['name'],
        '{field_name_input}'              => $_lusterF['name_input'],
        '{field_email}'                   => $_lusterF['email'],
        '{field_email_input}'             => $_lusterF['email_input'],
        '{field_url}'                     => $_lusterF['url'],
        '{field_url_input}'               => $_lusterF['url_input'],
        '{field_country}'                 => $_lusterCountryEnabled ? $_lusterF['country'] : '',
        '{field_country_input}'           => $_lusterCountryEnabled ? $_lusterF['country_input'] : '',
        '{additional_fields}'             => $_lusterAdditionalFieldsHtml,
        '{additional_fields_input}'       => $_lusterAdditionalFieldsInputHtml,
        '{field_password}'                => $_lusterF['password'],
        '{field_password_input}'          => $_lusterF['password_input'],
        '{field_password_confirm}'        => $_lusterF['password_confirm'],
        '{field_password_confirm_input}'  => $_lusterF['password_confirm_input'],
        '{captcha_block}'                 => $_lusterF['captcha_answer'],
        '{captcha_block_input}'           => $_lusterF['captcha_answer_input'],
        '{captcha_question}'              => View::e($_lusterCaptchaQ),
        '{submit_button}'                 => '<button type="submit" class="fl-submit">Join Fanlisting</button>',
        '{clear_button}'                  => '<button type="reset" class="fl-btn-secondary">Clear</button>',
    ];

    $_lusterInnerHtml = FormTemplateRenderer::expandTokens($_lusterJoinFormTpl, $_lusterTokens);
    $_lusterFormHtml  = FormTemplateRenderer::wrapForm(
        $_lusterInnerHtml,
        $_lusterFormAction,
        $_lusterApp->getCSRF()->token(),
        false,
        false,
        $_lusterMarker,
    );
}

$_lusterHtml = $_lusterApp->getView()->renderFragment('join.php', [
    'listing'               => $_lusterListing,
    'listing_name'          => $_lusterListing->title,
    'listing_url'           => $_lusterListing->url,
    'page_title'            => 'Join',
    'form_action'           => $_lusterFormAction,
    'csrf_token'            => $_lusterApp->getCSRF()->token(),
    'errors'                => $_lusterError !== ''
        ? '<p>' . htmlspecialchars($_lusterError, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>'
        : '',
    'fields'                => $_lusterFields,
    'f'                     => $_lusterF,
    'base_url'              => $_lusterApp->getBaseUrl(),
    'theme_path'            => $_lusterApp->getBaseUrl() . '/assets/default/',
    'country_field_enabled' => $_lusterCountryEnabled,
    'form_html'             => $_lusterFormHtml,
    'form_marker'           => $_lusterMarker,
]);


if ($luster_return ?? false) {
    return $_lusterHtml;
}

echo $_lusterHtml;
