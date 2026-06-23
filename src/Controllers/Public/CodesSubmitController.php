<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Handles public fan code image submissions for both per-listing and collective
 contexts. On GET, renders the submission form with CAPTCHA and submitter info
 fields. On POST, validates the uploaded image (MIME type via finfo, max 500 KB,
 JPEG/PNG/GIF/WebP only), stores it with a randomized filename, and inserts a
 pending code record for admin review. Includes rate limiting, honeypot, and
 blacklist checks.
*/

declare(strict_types=1);

namespace Luster\Controllers\Public;

use Luster\Core\Application;
use Luster\Core\Captcha;
use Luster\Core\RateLimit;
use Luster\Core\Request;
use Luster\Core\Response;
use Luster\Core\View;
use Luster\Repositories\BlacklistRepo;
use Luster\Repositories\CodesRepo;
use Luster\Repositories\ListingRepo;
use Luster\Repositories\ListingSettingsRepo;
use Luster\Repositories\SiteSettingsRepo;
use Luster\Support\CollectiveFormUrlResolver;
use Luster\Support\FormFieldBuilder;
use Luster\Support\FormTemplateRenderer;
use Luster\Support\ListingTemplateResolver;
use Luster\Support\SuccessRenderer;

/**
 * Public fan code submission — allows fans to submit code images for review.
 *
 * Routes:
 *   GET  /codes/submit/{id}  — show submission form
 *   POST /codes/submit/{id}  — process submission (stores as pending), redirects to host page
 */
final class CodesSubmitController
{
    /** Allowed MIME types for submitted code images. */
    private const ALLOWED_MIME = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

    /** Maximum upload size per file (500 KB). */
    private const MAX_SIZE = 512_000;

    public function __construct(private readonly Application $app) {}

    // -----------------------------------------------------------------------
    // GET /codes/submit/{id}
    // -----------------------------------------------------------------------

    public function showForm(Request $request, array $params): Response
    {
        $listingId    = (int) ($params['id'] ?? 0);
        $isCollective = ($listingId === 0);

        $db     = $this->app->getDatabase();
        $config = $this->app->getConfig();

        if ($isCollective) {
            $listing     = null;
            $listingName = (string) ($config->get('site_name', '') ?: 'Collective');
            $listingUrl  = '';
        } else {
            $listingRepo  = new ListingRepo($db, $config);
            $settingsRepo = new ListingSettingsRepo($db);
            $listing      = $listingRepo->getById($listingId);

            if ($listing === null) {
                return Response::notFound('Listing not found.');
            }

            $embedSettings = $settingsRepo->getEmbedSettings($listingId);
            if (!($embedSettings['codes_submit_enabled'] ?? true)) {
                return Response::notFound('Code donations are not enabled for this listing.');
            }

            $listingName = $listing->title;
            $listingUrl  = $listing->url ?? '';
        }

        $session = $this->app->getSession();
        $baseUrl = $this->app->getBaseUrl();

        $error = (string) $session->get('lstr_flash_error', '');
        $old   = (array)  $session->get('lstr_flash_old',   []);
        $session->remove('lstr_flash_error');
        $session->remove('lstr_flash_old');

        $captchaQuestion = Captcha::getQuestion('code_submit');

        if ($isCollective) {
            $wrapperTpl = '';
        } else {
            $settingsRepoRcs = new ListingSettingsRepo($this->app->getDatabase());
            $wrapperTpl      = $settingsRepoRcs->getWrapperTemplate($listingId) ?? '';
        }

        $f = $this->app->getFormFieldBuilder()->build([
            ['type' => 'file',  'name' => 'code_image',      'id' => 'code-image',  'label' => 'Upload Code',         'required' => true,  'attrs' => ['accept' => 'image/jpeg,image/png,image/gif,image/webp'], 'hint' => 'JPEG, PNG, GIF, or WebP. Max 500 KB.'],
            ['type' => 'text',  'name' => 'submitter_name',  'id' => 'sub-name',    'label' => 'Name',      'required' => true, 'value' => (string) ($old['submitter_name']  ?? ''), 'attrs' => ['maxlength' => '150', 'autocomplete' => 'name']],
            ['type' => 'email', 'name' => 'submitter_email', 'id' => 'sub-email',   'label' => 'Email',               'required' => true,  'value' => (string) ($old['submitter_email'] ?? ''), 'attrs' => ['maxlength' => '255', 'autocomplete' => 'email'], 'hint' => 'Required — used only to notify you when your code is approved.'],
            ['type' => 'url',   'name' => 'submitter_url',   'id' => 'sub-url',     'label' => 'Website URL (optional)',   'required' => false, 'value' => (string) ($old['submitter_url']   ?? ''), 'attrs' => ['maxlength' => '255', 'placeholder' => 'https://', 'autocomplete' => 'url'], 'hint' => 'Displayed as a link on your donated code.'],
            ['type' => 'text',  'name' => 'captcha',         'id' => 'captcha',     'label' => 'Captcha: ' . $captchaQuestion, 'required' => true, 'attrs' => ['maxlength' => '10', 'autocomplete' => 'off']],
        ], $wrapperTpl);

        $globalTemplates = (new SiteSettingsRepo($this->app->getDatabase()))->getGlobalTemplates();
        $formHtml = null;
        if (!$isCollective) {
            $csResolved = ListingTemplateResolver::resolve(
                $settingsRepoRcs->getCodesSubmitFormTemplate($listingId),
                $globalTemplates['listing_default_codes_submit_form_template'] ?? null,
                '',
            );
            $csFormTpl = $csResolved['should_render_template'] ? $csResolved['value'] : '';
            if ($csFormTpl !== '') {
                $tokens = [
                    '{listing_title}'               => View::e($listingName),
                    '{listing_subject}'             => View::e((string) ($listing->subject ?? $listingName)),
                    '{listing_url}'                 => View::e($listingUrl),
                    '{field_code_image}'            => $f['code_image'],
                    '{field_code_image_input}'      => $f['code_image_input'],
                    '{field_submitter_name}'        => $f['submitter_name'],
                    '{field_submitter_name_input}'  => $f['submitter_name_input'],
                    '{field_submitter_email}'       => $f['submitter_email'],
                    '{field_submitter_email_input}' => $f['submitter_email_input'],
                    '{field_submitter_url}'         => $f['submitter_url'],
                    '{field_submitter_url_input}'   => $f['submitter_url_input'],
                    '{captcha_block}'               => $f['captcha'],
                    '{captcha_block_input}'         => $f['captcha_input'],
                    '{captcha_question}'            => View::e($captchaQuestion),
                    '{submit_button}'               => '<button type="submit" class="fl-submit">Submit Code</button>',
                    '{clear_button}'                => '<button type="reset" class="fl-btn-secondary">Clear</button>',
                ];
                $innerHtml = FormTemplateRenderer::expandTokens($csFormTpl, $tokens);
                $formHtml  = FormTemplateRenderer::wrapForm(
                    $innerHtml,
                    $baseUrl . '/codes/submit/' . $listingId,
                    $this->app->getCSRF()->token(),
                    true,
                    formMarker: 'codes_submit:' . $listingId,
                );
            }
        } else {
            $csCollectiveTpl = (new SiteSettingsRepo($db))->get('collective_codes_submit_form_template');
            if ($csCollectiveTpl !== null && trim($csCollectiveTpl) !== '') {
                $tokens = [
                    '{site_name}'                    => View::e($listingName),
                    '{field_code_image}'            => $f['code_image'],
                    '{field_code_image_input}'      => $f['code_image_input'],
                    '{field_submitter_name}'        => $f['submitter_name'],
                    '{field_submitter_name_input}'  => $f['submitter_name_input'],
                    '{field_submitter_email}'       => $f['submitter_email'],
                    '{field_submitter_email_input}' => $f['submitter_email_input'],
                    '{field_submitter_url}'         => $f['submitter_url'],
                    '{field_submitter_url_input}'   => $f['submitter_url_input'],
                    '{captcha_block}'               => $f['captcha'],
                    '{captcha_block_input}'         => $f['captcha_input'],
                    '{captcha_question}'            => View::e($captchaQuestion),
                    '{submit_button}'               => '<button type="submit" class="fl-submit">Submit Code</button>',
                    '{clear_button}'                => '<button type="reset" class="fl-btn-secondary">Clear</button>',
                ];
                $innerHtml = FormTemplateRenderer::expandTokens($csCollectiveTpl, $tokens);
                $formHtml  = FormTemplateRenderer::wrapForm(
                    $innerHtml,
                    $baseUrl . '/codes/submit/' . $listingId,
                    $this->app->getCSRF()->token(),
                    true,
                    formMarker: 'codes_submit_collective:' . $listingId,
                );
            }
        }

        return Response::html($this->app->getView()->renderPublic('codes-submit.php', [
            'listing'      => $listing,
            'listing_title' => $listingName,
            'listing_url'  => $listingUrl,
            'page_title'   => 'Submit a Code — ' . $listingName,
            'base_url'     => $baseUrl,
            'theme_path'   => $baseUrl . '/assets/css/default/',
            'form_action'  => $baseUrl . '/codes/submit/' . $listingId,
            'csrf_token'   => $this->app->getCSRF()->token(),
            'errors'       => htmlspecialchars($error, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            'f'            => $f,
            'form_html'    => $formHtml,
        ]));
    }

    // -----------------------------------------------------------------------
    // POST /codes/submit/{id}
    // -----------------------------------------------------------------------

    public function submit(Request $request, array $params): Response
    {
        $listingId    = (int) ($params['id'] ?? 0);
        $isCollective = ($listingId === 0);

        $db            = $this->app->getDatabase();
        $config        = $this->app->getConfig();
        $session       = $this->app->getSession();
        $baseUrl       = $this->app->getBaseUrl();
        $embedReturnTo = (string) $session->get('lstr_embed_return_to', '');

        if ($isCollective) {
            $listing  = null;
            $siteUrl  = (string) ($config->get('site_url', '') ?: $baseUrl);
            $override = (new SiteSettingsRepo($db))->get('collective_form_url_codes_submit');
            $submitUrl = CollectiveFormUrlResolver::resolve($override, $siteUrl, $baseUrl . '/codes/submit/0');
        } else {
            $listingRepo  = new ListingRepo($db, $config);
            $settingsRepo = new ListingSettingsRepo($db);
            $listing      = $listingRepo->getById($listingId);

            if ($listing === null) {
                return Response::notFound('Listing not found.');
            }

            $embedSettings = $settingsRepo->getEmbedSettings($listingId);
            if (!($embedSettings['codes_submit_enabled'] ?? true)) {
                return Response::notFound('Code donations are not enabled for this listing.');
            }

            $codesSubmitPage = (string) ($embedSettings['form_url_codes_submit'] ?? '');
            $submitUrl = $codesSubmitPage !== ''
                ? rtrim((string) $listing->url, '/') . '/' . ltrim($codesSubmitPage, '/')
                : $baseUrl . '/codes/submit/' . $listingId;
        }

        $formUrl = $embedReturnTo !== '' ? $embedReturnTo : $submitUrl;

        $ip        = $request->ip();
        $rateLimit = new RateLimit($db);

        // Rate limiting
        if ($rateLimit->isBlocked($ip, RateLimit::ACTION_PUBLIC_CODE_SUBMIT)) {
            $session->set('lstr_flash_error', 'Too many submissions. Please wait a while before trying again.');
            return Response::redirect($formUrl);
        }

        // Honeypot — bots fill the hidden field; humans leave it blank
        if ((string) $request->post('website_confirm', '') !== '') {
            $session->remove('lstr_embed_return_to');
            $session->set('lstr_flash_success_html', SuccessRenderer::render('', 'code-submitted'));
            return Response::redirect($formUrl);
        }

        // Validate captcha
        $captchaAnswer = (string) $request->post('captcha', '');
        if (!Captcha::validate($captchaAnswer, 'code_submit')) {
            $rateLimit->hitDefault($ip, RateLimit::ACTION_PUBLIC_CODE_SUBMIT);
            $session->set('lstr_flash_error', 'Incorrect answer to the anti-spam question. Please try again.');
            $session->set('lstr_flash_old', [
                'submitter_name'  => $request->post('submitter_name',  ''),
                'submitter_email' => $request->post('submitter_email', ''),
                'submitter_url'   => $request->post('submitter_url',   ''),
            ]);
            return Response::redirect($formUrl);
        }

        // Collect submitter info (email is required)
        $submitterName  = trim((string) $request->post('submitter_name',  ''));
        $submitterEmail = trim((string) $request->post('submitter_email', ''));
        $submitterUrl   = trim((string) $request->post('submitter_url',   ''));

        if ($submitterEmail === '') {
            $session->set('lstr_flash_error', 'Please enter your email address.');
            $session->set('lstr_flash_old', [
                'submitter_name'  => $submitterName,
                'submitter_email' => $submitterEmail,
                'submitter_url'   => $submitterUrl,
            ]);
            return Response::redirect($formUrl);
        }

        $submitterName  = $submitterName  !== '' ? $submitterName  : null;
        $submitterEmail = $submitterEmail !== '' ? $submitterEmail : null;
        $submitterUrl   = $submitterUrl   !== '' ? $submitterUrl   : null;

        // Validate email if provided
        if ($submitterEmail !== null && filter_var($submitterEmail, FILTER_VALIDATE_EMAIL) === false) {
            $session->set('lstr_flash_error', 'Please enter a valid email address.');
            $session->set('lstr_flash_old', [
                'submitter_name'  => $submitterName  ?? '',
                'submitter_email' => $submitterEmail ?? '',
                'submitter_url'   => $submitterUrl   ?? '',
            ]);
            return Response::redirect($formUrl);
        }

        // Blacklist check on optional email
        if ($submitterEmail !== null) {
            $blacklistRepo = new BlacklistRepo($db);
            if ($blacklistRepo->isBlocked($submitterEmail)) {
                $session->set('lstr_flash_error', 'This email address cannot be used to submit codes.');
                $session->set('lstr_flash_old', [
                    'submitter_name'  => $submitterName  ?? '',
                    'submitter_email' => $submitterEmail ?? '',
                    'submitter_url'   => $submitterUrl   ?? '',
                ]);
                return Response::redirect($formUrl);
            }
        }

        // Validate URL if provided (http/https only)
        if ($submitterUrl !== null && (
            filter_var($submitterUrl, FILTER_VALIDATE_URL) === false
            || !in_array(parse_url($submitterUrl, PHP_URL_SCHEME), ['http', 'https'], true)
        )) {
            $session->set('lstr_flash_error', 'Please enter a valid website URL (e.g. https://example.com).');
            $session->set('lstr_flash_old', [
                'submitter_name'  => $submitterName  ?? '',
                'submitter_email' => $submitterEmail ?? '',
                'submitter_url'   => $submitterUrl   ?? '',
            ]);
            return Response::redirect($formUrl);
        }

        // Validate file upload
        $fileData = $request->file('code_image');
        if ($fileData === null || (string) $fileData['name'] === '' || (int) $fileData['error'] === UPLOAD_ERR_NO_FILE) {
            $session->set('lstr_flash_error', 'Please select a code image to submit.');
            $session->set('lstr_flash_old', [
                'submitter_name'  => $submitterName  ?? '',
                'submitter_email' => $submitterEmail ?? '',
                'submitter_url'   => $submitterUrl   ?? '',
            ]);
            return Response::redirect($formUrl);
        }

        if ((int) $fileData['error'] !== UPLOAD_ERR_OK) {
            $session->set('lstr_flash_error', 'File upload failed. Please try again.');
            $session->set('lstr_flash_old', [
                'submitter_name'  => $submitterName  ?? '',
                'submitter_email' => $submitterEmail ?? '',
                'submitter_url'   => $submitterUrl   ?? '',
            ]);
            return Response::redirect($formUrl);
        }

        if (!is_uploaded_file((string) $fileData['tmp_name'])) {
            $session->set('lstr_flash_error', 'Invalid upload. Please try again.');
            $session->set('lstr_flash_old', [
                'submitter_name'  => $submitterName  ?? '',
                'submitter_email' => $submitterEmail ?? '',
                'submitter_url'   => $submitterUrl   ?? '',
            ]);
            return Response::redirect($formUrl);
        }

        if ((int) $fileData['size'] > self::MAX_SIZE) {
            $session->set('lstr_flash_error', 'The file exceeds the 500 KB size limit.');
            $session->set('lstr_flash_old', [
                'submitter_name'  => $submitterName  ?? '',
                'submitter_email' => $submitterEmail ?? '',
                'submitter_url'   => $submitterUrl   ?? '',
            ]);
            return Response::redirect($formUrl);
        }

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime  = $finfo->file((string) $fileData['tmp_name']);

        if (!in_array($mime, self::ALLOWED_MIME, true)) {
            $session->set('lstr_flash_error', 'Only JPEG, PNG, GIF, and WebP images are accepted.');
            $session->set('lstr_flash_old', [
                'submitter_name'  => $submitterName  ?? '',
                'submitter_email' => $submitterEmail ?? '',
                'submitter_url'   => $submitterUrl   ?? '',
            ]);
            return Response::redirect($formUrl);
        }

        $ext = match ($mime) {
            'image/jpeg' => 'jpg',
            'image/png'  => 'png',
            'image/gif'  => 'gif',
            'image/webp' => 'webp',
            default      => 'bin',
        };

        $imgInfo = getimagesize((string) $fileData['tmp_name']);
        $width   = $imgInfo !== false ? (int) $imgInfo[0] : 0;
        $height  = $imgInfo !== false ? (int) $imgInfo[1] : 0;

        // Ensure upload directory exists
        $uploadsBase = realpath(__DIR__ . '/../../../public/uploads');
        if ($uploadsBase === false) {
            $session->set('lstr_flash_error', 'Server configuration error: uploads directory not found. Please contact the site administrator.');
            $session->set('lstr_flash_old', [
                'submitter_name'  => $submitterName  ?? '',
                'submitter_email' => $submitterEmail ?? '',
                'submitter_url'   => $submitterUrl   ?? '',
            ]);
            return Response::redirect($formUrl);
        }
        $uploadDir = $uploadsBase . DIRECTORY_SEPARATOR . 'codes';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }

        $newFilename = 'c_' . uniqid('', true) . bin2hex(random_bytes(4)) . '.' . $ext;
        $dest        = $uploadDir . DIRECTORY_SEPARATOR . $newFilename;

        if (!move_uploaded_file((string) $fileData['tmp_name'], $dest)) {
            $session->set('lstr_flash_error', 'Could not save the uploaded file. Please try again.');
            $session->set('lstr_flash_old', [
                'submitter_name'  => $submitterName  ?? '',
                'submitter_email' => $submitterEmail ?? '',
                'submitter_url'   => $submitterUrl   ?? '',
            ]);
            return Response::redirect($formUrl);
        }

        $codesRepo  = new CodesRepo($db);
        $insertId   = $isCollective ? null : $listingId;
        $sortOrder  = $codesRepo->getMaxSortOrder($insertId);

        $codesRepo->insert(
            $insertId,
            $newFilename,
            $width,
            $height,
            $sortOrder + 1,
            null,
            null,
            'pending',
            null,
            $submitterName,
            $submitterEmail,
            $submitterUrl,
        );

        $rateLimit->hitDefault($ip, RateLimit::ACTION_PUBLIC_CODE_SUBMIT);

        $session->remove('lstr_embed_return_to');
        $successTpl = isset($settingsRepo) ? ($settingsRepo->getSuccessTemplate($listingId) ?? '') : '';
        $session->set('lstr_flash_success_html', SuccessRenderer::render($successTpl, 'code-submitted'));
        return Response::redirect($formUrl);
    }

}
