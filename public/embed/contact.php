<?php

declare(strict_types=1);

use Luster\Core\Captcha;
use Luster\Core\View;
use Luster\Repositories\ListingRepo;
use Luster\Repositories\ListingSettingsRepo;
use Luster\Repositories\SiteSettingsRepo;
use Luster\Support\FormTemplateRenderer;
use Luster\Support\ListingTemplateResolver;

/**
 * Luster embed — per-listing contact form fragment.
 *
 * Usage:
 *   $luster_fanlisting_id = 1;     // required: listing ID (int)
 *   $luster_return        = false;  // optional: true = return string, false = echo
 *   require '/absolute/path/to/luster/public/embed/contact.php';
 *
 * Override variables (set before require):
 *   $luster_contact_action  — override the form POST URL (useful when embedding on an external page)
 */

// ---------------------------------------------------------------------------
// 1. Validate required variable
// ---------------------------------------------------------------------------

$_lusterListingId = (int) ($luster_fanlisting_id ?? 0);

if ($_lusterListingId === 0) {
    $_lusterHtml = '<p class="fl-errors">Luster embed error: <code>$luster_fanlisting_id</code> is not set.</p>';
    if ($luster_return ?? false) { return $_lusterHtml; }
    echo $_lusterHtml;
    return;
}

// ---------------------------------------------------------------------------
// 2. Bootstrap Luster
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
// 3. Load listing and contact settings
// ---------------------------------------------------------------------------

$_lusterListingRepo  = new ListingRepo($_lusterApp->getDatabase(), $_lusterApp->getConfig());
$_lusterListing      = $_lusterListingRepo->getById($_lusterListingId);

if ($_lusterListing === null) {
    $_lusterHtml = '<!-- Luster: listing #' . $_lusterListingId . ' not found -->';
    if ($luster_return ?? false) { return $_lusterHtml; }
    echo $_lusterHtml;
    return;
}

$_lusterSettingsRepo    = new ListingSettingsRepo($_lusterApp->getDatabase());
$_lusterContactSettings = $_lusterSettingsRepo->getContactSettings($_lusterListingId);

if (!$_lusterContactSettings['enabled']) {
    $_lusterHtml = '';
    if ($luster_return ?? false) { return $_lusterHtml; }
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
// 5. Build wrapped fields and render
// ---------------------------------------------------------------------------

$_lusterBaseUrl    = $_lusterApp->getBaseUrl();
$_lusterFormAction = isset($luster_contact_action)
    ? (string) $luster_contact_action
    : $_lusterBaseUrl . '/contact/' . $_lusterListingId;

$_lusterSettingsRepo = new ListingSettingsRepo($_lusterApp->getDatabase());
$_lusterCaptchaQ     = Captcha::getQuestion('contact_listing');

$_lusterSubjOld  = (string) ($_lusterOld['subject'] ?? '');
$_lusterSubjHtml = '<select id="contact-subject" name="subject" required>'
                 . '<option value="" disabled' . ($_lusterSubjOld === '' ? ' selected' : '') . '>Choose &mdash;</option>';
foreach (['Comments', 'Contact', 'Questions/Concerns', 'Affiliation', 'Link Exchange'] as $_lusterSubjOpt) {
    $_lusterOptEsc    = htmlspecialchars($_lusterSubjOpt, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $_lusterSubjHtml .= '<option value="' . $_lusterOptEsc . '"' . ($_lusterSubjOld === $_lusterSubjOpt ? ' selected' : '') . '>' . $_lusterOptEsc . '</option>';
}
$_lusterSubjHtml .= '</select>';

$_lusterF = $_lusterApp->getFormFieldBuilder()->build([
    ['type' => 'text',     'name' => 'name',           'id' => 'contact-name',    'label' => 'Name',        'required' => true,  'value' => (string) ($_lusterOld['name']    ?? ''), 'attrs' => ['maxlength' => '100', 'autocomplete' => 'name']],
    ['type' => 'email',    'name' => 'email',          'id' => 'contact-email',   'label' => 'Email',       'required' => true,  'value' => (string) ($_lusterOld['email']   ?? ''), 'attrs' => ['maxlength' => '255', 'autocomplete' => 'email']],
    ['type' => 'url',      'name' => 'url',            'id' => 'contact-url',     'label' => 'Website URL', 'required' => false, 'value' => (string) ($_lusterOld['url']     ?? ''), 'attrs' => ['maxlength' => '255', 'autocomplete' => 'url']],
    ['type' => 'select',   'name' => 'subject',        'id' => 'contact-subject', 'label' => 'Subject',     'required' => true,  'raw_input' => $_lusterSubjHtml],
    ['type' => 'textarea', 'name' => 'message',        'id' => 'contact-message', 'label' => 'Message',     'required' => true,  'value' => (string) ($_lusterOld['message'] ?? ''), 'attrs' => ['maxlength' => '5000', 'rows' => '6']],
    ['type' => 'text',     'name' => 'captcha_answer', 'id' => 'contact-captcha', 'label' => 'Captcha: ' . $_lusterCaptchaQ, 'required' => true, 'attrs' => ['maxlength' => '10', 'autocomplete' => 'off']],
], '');

$_lusterFormHtml   = null;
$_lusterGlobals    = (new SiteSettingsRepo($_lusterApp->getDatabase()))->getGlobalTemplates();
$_lusterResolved   = ListingTemplateResolver::resolve(
    $_lusterSettingsRepo->getContactFormTemplate($_lusterListingId),
    $_lusterGlobals['listing_default_contact_form_template'] ?? null,
    '',
);
$_lusterContactTpl = $_lusterResolved['should_render_template'] ? $_lusterResolved['value'] : '';
if ($_lusterContactTpl !== '') {
    $_lusterTokens = [
        '{listing_title}'        => View::e($_lusterListing->title),
        '{listing_subject}'      => View::e($_lusterListing->subject),
        '{listing_url}'          => View::e($_lusterListing->url),
        '{field_name}'           => $_lusterF['name'],
        '{field_name_input}'     => $_lusterF['name_input'],
        '{field_email}'          => $_lusterF['email'],
        '{field_email_input}'    => $_lusterF['email_input'],
        '{field_url}'            => $_lusterF['url'],
        '{field_url_input}'      => $_lusterF['url_input'],
        '{field_subject}'        => $_lusterF['subject'],
        '{field_subject_input}'  => $_lusterF['subject_input'],
        '{field_message}'        => $_lusterF['message'],
        '{field_message_input}'  => $_lusterF['message_input'],
        '{captcha_block}'        => $_lusterF['captcha_answer'],
        '{captcha_block_input}'  => $_lusterF['captcha_answer_input'],
        '{captcha_question}'     => View::e($_lusterCaptchaQ),
        '{submit_button}'        => '<button type="submit" class="fl-submit">Send Message</button>',
        '{clear_button}'         => '<button type="reset" class="fl-btn-secondary">Clear</button>',
    ];
    $_lusterInner   = FormTemplateRenderer::expandTokens($_lusterContactTpl, $_lusterTokens);
    $_lusterFormHtml = FormTemplateRenderer::wrapForm($_lusterInner, $_lusterFormAction, $_lusterApp->getCSRF()->token(), false);
}

$_lusterHtml = $_lusterApp->getView()->renderFragment('contact.php', [
    'listing'      => $_lusterListing,
    'listing_name' => $_lusterListing->title,
    'listing_url'  => $_lusterListing->url,
    'page_title'   => 'Contact',
    'base_url'     => $_lusterBaseUrl,
    'theme_path'   => rtrim($_lusterBaseUrl, '/') . '/assets/default',
    'form_action'  => $_lusterFormAction,
    'csrf_token'   => $_lusterApp->getCSRF()->token(),
    'errors'       => $_lusterError !== ''
        ? '<p>' . htmlspecialchars($_lusterError, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>'
        : '',
    'f'            => $_lusterF,
    'form_html'    => $_lusterFormHtml,
]);

if ($luster_return ?? false) { return $_lusterHtml; }
echo $_lusterHtml;
