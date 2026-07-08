<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Embeddable collective-level contact form. Renders a general-purpose contact
 form that sends messages to the site owner's email address. Unlike the
 per-listing contact embed, this form does not require a $luster_fanlisting_id
 and operates at the collective/site-wide level. Includes CAPTCHA validation.
*/

declare(strict_types=1);

use Luster\Core\Captcha;
use Luster\Repositories\SiteSettingsRepo;
use Luster\Support\FormTemplateRenderer;

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
$_lusterMarker = 'contact_collective';

// ---------------------------------------------------------------------------
// POST handler — process form submissions inline (self-post)
// When $luster_contact_action is set, the form posts to that URL instead.
// ---------------------------------------------------------------------------

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($luster_contact_action)) {
    if (($_POST['_luster_embed_form'] ?? '') !== $_lusterMarker) {
        if ($luster_return ?? false) { return ''; }
        return;
    }
    if (!$_lusterApp->getCSRF()->validate((string) ($_POST['_csrf'] ?? ''))) {
        $_lusterApp->getSession()->set('lstr_flash_error:' . $_lusterMarker, 'Security check failed. Please reload the page and try again.');
        if (!headers_sent()) { header('Location: ' . $_lusterRedirectTo, true, 302); exit; }
        echo '<script>window.location.replace(' . json_encode($_lusterRedirectTo) . ');</script>';
        return;
    }
    $response   = (new \Luster\Controllers\Public\ContactController($_lusterApp))
        ->collectiveSubmit(new \Luster\Core\Request(), []);
    $_lusterUrl = $response->getRedirectUrl() ?? $_lusterRedirectTo;
    if (!headers_sent()) { header('Location: ' . $_lusterUrl, true, 302); exit; }
    echo '<script>window.location.replace(' . json_encode($_lusterUrl) . ');</script>';
    return;
}

// ---------------------------------------------------------------------------
// 2. Check if collective contact is enabled
// ---------------------------------------------------------------------------

$_lusterSiteRepo = new SiteSettingsRepo($_lusterApp->getDatabase());
$_lusterEnabled  = (bool) ((int) ($_lusterSiteRepo->get('collective_contact_enabled') ?? '1'));

if (!$_lusterEnabled) {
    $_lusterHtml = '';
    if ($luster_return ?? false) { return $_lusterHtml; }
    echo $_lusterHtml;
    return;
}

// ---------------------------------------------------------------------------
// 3. Consume flash data
// ---------------------------------------------------------------------------

$_lusterSession = $_lusterApp->getSession();
$_lusterSuccess = (string) $_lusterSession->get('lstr_flash_success_html:' . $_lusterMarker, '');
$_lusterError   = (string) $_lusterSession->get('lstr_flash_error:' . $_lusterMarker,        '');
$_lusterOld     = (array)  $_lusterSession->get('lstr_flash_old:' . $_lusterMarker,          []);
$_lusterSession->remove('lstr_flash_success_html:' . $_lusterMarker);
$_lusterSession->remove('lstr_flash_error:' . $_lusterMarker);
$_lusterSession->remove('lstr_flash_old:' . $_lusterMarker);

if ($_lusterSuccess !== '') {
    if ($luster_return ?? false) { return $_lusterSuccess; }
    echo $_lusterSuccess;
    return;
}

// ---------------------------------------------------------------------------
// 4. Build form data and render
// ---------------------------------------------------------------------------

$_lusterConfig     = $_lusterApp->getConfig();
$_lusterBaseUrl    = $_lusterApp->getBaseUrl();
$_lusterFormAction = isset($luster_contact_action)
    ? (string) $luster_contact_action
    : $_lusterRedirectTo;

$_lusterCaptchaQ   = Captcha::getQuestion('contact_collective');
$_lusterWrapperTpl = '';

$_lusterSubjOld = (string) ($_lusterOld['subject'] ?? '');
$_lusterSubjHtml = '<select class="fl-select" id="contact-subject" name="subject" required>'
                 . '<option value="" disabled' . ($_lusterSubjOld === '' ? ' selected' : '') . '>Choose &mdash;</option>';
foreach (['Comments', 'Questions/Concerns', 'Contact/Other'] as $_lusterSubjOpt) {
    $_lusterOptEsc    = htmlspecialchars($_lusterSubjOpt, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $_lusterSubjHtml .= '<option value="' . $_lusterOptEsc . '"' . ($_lusterSubjOld === $_lusterSubjOpt ? ' selected' : '') . '>' . $_lusterOptEsc . '</option>';
}
$_lusterSubjHtml .= '</select>';

$_lusterF = $_lusterApp->getFormFieldBuilder()->build([
    ['type' => 'text',     'name' => 'name',           'id' => 'contact-name',    'label' => 'Name',        'required' => true,  'value' => (string) ($_lusterOld['name']    ?? ''), 'attrs' => ['maxlength' => '100', 'autocomplete' => 'name']],
    ['type' => 'email',    'name' => 'email',          'id' => 'contact-email',   'label' => 'Email',       'required' => true,  'value' => (string) ($_lusterOld['email']   ?? ''), 'attrs' => ['maxlength' => '255', 'autocomplete' => 'email']],
    ['type' => 'url',      'name' => 'url',            'id' => 'contact-url',     'label' => 'Website URL (optional)', 'required' => false, 'value' => (string) ($_lusterOld['url']     ?? ''), 'attrs' => ['maxlength' => '255', 'autocomplete' => 'url']],
    ['type' => 'select',   'name' => 'subject',        'id' => 'contact-subject', 'label' => 'Subject',     'required' => true,  'raw_input' => $_lusterSubjHtml],
    ['type' => 'textarea', 'name' => 'message',        'id' => 'contact-message', 'label' => 'Message',     'required' => true,  'value' => (string) ($_lusterOld['message'] ?? ''), 'attrs' => ['maxlength' => '5000', 'rows' => '6']],
    ['type' => 'text',     'name' => 'captcha_answer', 'id' => 'contact-captcha', 'label' => 'Captcha: ' . $_lusterCaptchaQ, 'required' => true, 'attrs' => ['maxlength' => '10', 'autocomplete' => 'off']],
], $_lusterWrapperTpl);

$_lusterContactTpl = (string) ($_lusterSiteRepo->get('collective_contact_form_template') ?? '');
$_lusterFormHtml   = null;
if ($_lusterContactTpl !== '') {
    $_lusterTokens = [
        '{site_name}'            => htmlspecialchars((string) $_lusterConfig->get('site_name', 'Collective'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
        '{listing_title}'        => htmlspecialchars((string) $_lusterConfig->get('site_name', 'Collective'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
        '{listing_subject}'      => htmlspecialchars((string) $_lusterConfig->get('site_name', 'Collective'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
        '{listing_url}'          => htmlspecialchars((string) ($_lusterConfig->get('site_url', '')), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
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
        '{captcha_question}'     => htmlspecialchars($_lusterCaptchaQ, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
        '{submit_button}'        => '<button type="submit" class="fl-submit">Send Message</button>',
        '{clear_button}'         => '<button type="reset" class="fl-btn-secondary">Clear</button>',
    ];
    $_lusterInner  = FormTemplateRenderer::expandTokens($_lusterContactTpl, $_lusterTokens);
    $_lusterFormHtml = FormTemplateRenderer::wrapForm($_lusterInner, $_lusterFormAction, $_lusterApp->getCSRF()->token(), true, false, $_lusterMarker);
}

$_lusterHtml = $_lusterApp->getView()->renderFragment('contact.php', [
    'listing'      => null,
    'listing_title' => (string) $_lusterConfig->get('site_name', 'Collective'),
    'listing_url'  => (string) ($_lusterConfig->get('site_url', '')),
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
    'form_marker'  => $_lusterMarker,
]);


if ($luster_return ?? false) { return $_lusterHtml; }
echo $_lusterHtml;
