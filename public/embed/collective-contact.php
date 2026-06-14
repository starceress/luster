<?php

declare(strict_types=1);

use Luster\Core\Captcha;
use Luster\Repositories\SiteSettingsRepo;

/**
 * Luster embed — collective contact form fragment.
 *
 * Usage:
 *   $luster_return = false;  // optional: true = return string, false = echo
 *   require '/absolute/path/to/luster/public/embed/collective-contact.php';
 *
 * Override variables (set before require):
 *   $luster_contact_action  — override the form POST URL
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
// 4. Build form data and render
// ---------------------------------------------------------------------------

$_lusterConfig     = $_lusterApp->getConfig();
$_lusterBaseUrl    = $_lusterApp->getBaseUrl();
$_lusterFormAction = isset($luster_contact_action)
    ? (string) $luster_contact_action
    : $_lusterBaseUrl . '/contact';

$_lusterCaptchaQ   = Captcha::getQuestion('contact_collective');
$_lusterWrapperTpl = (new \Luster\Repositories\SiteSettingsRepo($_lusterApp->getDatabase()))->get('collective_field_wrapper_template', '') ?? '';

$_lusterSubjOld = (string) ($_lusterOld['subject'] ?? '');
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
    ['type' => 'url',      'name' => 'url',            'id' => 'contact-url',     'label' => 'Website URL (optional)', 'required' => false, 'value' => (string) ($_lusterOld['url']     ?? ''), 'attrs' => ['maxlength' => '255', 'autocomplete' => 'url']],
    ['type' => 'select',   'name' => 'subject',        'id' => 'contact-subject', 'label' => 'Subject',     'required' => true,  'raw_input' => $_lusterSubjHtml],
    ['type' => 'textarea', 'name' => 'message',        'id' => 'contact-message', 'label' => 'Message',     'required' => true,  'value' => (string) ($_lusterOld['message'] ?? ''), 'attrs' => ['maxlength' => '5000', 'rows' => '6']],
    ['type' => 'text',     'name' => 'captcha_answer', 'id' => 'contact-captcha', 'label' => 'Captcha: ' . $_lusterCaptchaQ, 'required' => true, 'attrs' => ['maxlength' => '10', 'autocomplete' => 'off']],
], $_lusterWrapperTpl);

$_lusterHtml = $_lusterApp->getView()->renderFragment('contact.php', [
    'listing'      => null,
    'listing_name' => (string) $_lusterConfig->get('app_name', 'Collective'),
    'listing_url'  => (string) $_lusterConfig->get('app_url',  ''),
    'page_title'   => 'Contact',
    'base_url'     => $_lusterBaseUrl,
    'theme_path'   => rtrim($_lusterBaseUrl, '/') . '/assets/default',
    'form_action'  => $_lusterFormAction,
    'csrf_token'   => $_lusterApp->getCSRF()->token(),
    'errors'       => $_lusterError !== ''
        ? '<p>' . htmlspecialchars($_lusterError, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>'
        : '',
    'f'            => $_lusterF,
]);

if ($luster_return ?? false) { return $_lusterHtml; }
echo $_lusterHtml;
