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
use Luster\Repositories\BlacklistRepo;
use Luster\Repositories\CodesRepo;
use Luster\Repositories\ListingRepo;
use Luster\Repositories\ListingSettingsRepo;
use Luster\Repositories\SiteSettingsRepo;
use Luster\Support\CollectiveFormUrlResolver;
use Luster\Support\SuccessRenderer;

/**
 * Public fan code submission — allows fans to submit code images for review.
 *
 * Routes:
 *   POST /codes/submit/{id}  — process submission (stores as pending), redirects to host page
 */
final class CodesSubmitController
{
    private const ALLOWED_MIME = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

    /** Maximum upload size per file (500 KB). */
    private const MAX_SIZE = 512_000;

    public function __construct(private readonly Application $app) {}

    // -----------------------------------------------------------------------
    // POST /codes/submit/{id}
    // -----------------------------------------------------------------------

    /**
     * Processes the code submission POST for a listing (or collective when id = 0).
     *
     * Runs rate-limit, honeypot, CAPTCHA, and blacklist checks; validates the
     * image (MIME via finfo, max 500 KB, JPEG/PNG/GIF/WebP), saves it with a
     * randomised filename under public/uploads/codes/, and inserts a pending
     * record in lstr_codes. Redirects back to the host page on success or error.
     */
    public function submit(Request $request, array $params): Response
    {
        $listingId    = (int) ($params['id'] ?? 0);
        $isCollective = ($listingId === 0);

        $db            = $this->app->getDatabase();
        $config        = $this->app->getConfig();
        $session       = $this->app->getSession();
        $baseUrl       = $this->app->getBaseUrl();
        $embedReturnTo = (string) $session->get('lstr_embed_return_to', '');
        $marker        = (string) $request->post('_luster_embed_form', '');

        if ($isCollective) {
            $siteRepo = new SiteSettingsRepo($db);
            if (($siteRepo->get('collective_codes_submit_enabled') ?? '1') !== '1') {
                return Response::notFound('Collective code donations are not open.');
            }
            $listing  = null;
            $siteUrl  = (string) ($config->get('site_url', '') ?: $baseUrl);
            $override = $siteRepo->get('collective_form_url_codes_submit');
            $submitUrl = CollectiveFormUrlResolver::resolve($override, $siteUrl, $siteUrl);
        } else {
            $listingRepo  = new ListingRepo($db, $config);
            $settingsRepo = new ListingSettingsRepo($db);
            $listing      = $listingRepo->getById($listingId);

            if ($listing === null) {
                return Response::notFound('Listing not found.');
            }

            $embedSettings  = $settingsRepo->getResolvedEmbedSettings($listingId);
            $globalDefaults = $settingsRepo->getGlobalFormUrlDefaults();
            if (!($embedSettings['codes_submit_enabled'] ?? true)) {
                return Response::notFound('Code donations are not enabled for this listing.');
            }

            // Archived listings (Closed/Adopted Out) no longer accept code donations.
            if ((new \Luster\Repositories\ListingLifecycleRepo($db))->getByListingId($listingId) !== null) {
                return Response::notFound('Code donations are not enabled for this listing.');
            }

            $submitUrl = \Luster\Support\ListingFormUrlResolver::resolve(
                (string) ($embedSettings['form_url_codes_submit'] ?? ''),
                $listing->url,
                $globalDefaults['form_url_codes_submit'],
            );
        }

        $formUrl = $embedReturnTo !== '' ? $embedReturnTo : $submitUrl;

        $ip        = $request->ip();
        $rateLimit = new RateLimit($db);

        // Rate limiting
        if ($rateLimit->isBlocked($ip, RateLimit::ACTION_PUBLIC_CODE_SUBMIT)) {
            $session->set('lstr_flash_error:' . $marker, 'Too many submissions. Please wait a while before trying again.');
            return Response::redirect($formUrl);
        }

        // Honeypot — bots fill the hidden field; humans leave it blank
        if ((string) $request->post('website_confirm', '') !== '') {
            $session->remove('lstr_embed_return_to');
            $session->set('lstr_flash_success_html:' . $marker, SuccessRenderer::render('', 'code-submitted'));
            return Response::redirect($formUrl);
        }

        // Validate captcha
        $captchaAnswer = (string) $request->post('captcha', '');
        if (!Captcha::validate($captchaAnswer, 'code_submit')) {
            $rateLimit->hitDefault($ip, RateLimit::ACTION_PUBLIC_CODE_SUBMIT);
            $session->set('lstr_flash_error:' . $marker, 'Incorrect answer to the anti-spam question. Please try again.');
            $session->set('lstr_flash_old:' . $marker, [
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
            $session->set('lstr_flash_error:' . $marker, 'Please enter your email address.');
            $session->set('lstr_flash_old:' . $marker, [
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
            $session->set('lstr_flash_error:' . $marker, 'Please enter a valid email address.');
            $session->set('lstr_flash_old:' . $marker, [
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
                $session->set('lstr_flash_error:' . $marker, 'This email address cannot be used to submit codes.');
                $session->set('lstr_flash_old:' . $marker, [
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
            $session->set('lstr_flash_error:' . $marker, 'Please enter a valid website URL (e.g. https://example.com).');
            $session->set('lstr_flash_old:' . $marker, [
                'submitter_name'  => $submitterName  ?? '',
                'submitter_email' => $submitterEmail ?? '',
                'submitter_url'   => $submitterUrl   ?? '',
            ]);
            return Response::redirect($formUrl);
        }

        // Validate file upload
        $fileData = $request->file('code_image');
        if ($fileData === null || (string) $fileData['name'] === '' || (int) $fileData['error'] === UPLOAD_ERR_NO_FILE) {
            $session->set('lstr_flash_error:' . $marker, 'Please select a code image to submit.');
            $session->set('lstr_flash_old:' . $marker, [
                'submitter_name'  => $submitterName  ?? '',
                'submitter_email' => $submitterEmail ?? '',
                'submitter_url'   => $submitterUrl   ?? '',
            ]);
            return Response::redirect($formUrl);
        }

        if ((int) $fileData['error'] !== UPLOAD_ERR_OK) {
            $session->set('lstr_flash_error:' . $marker, 'File upload failed. Please try again.');
            $session->set('lstr_flash_old:' . $marker, [
                'submitter_name'  => $submitterName  ?? '',
                'submitter_email' => $submitterEmail ?? '',
                'submitter_url'   => $submitterUrl   ?? '',
            ]);
            return Response::redirect($formUrl);
        }

        if (!is_uploaded_file((string) $fileData['tmp_name'])) {
            $session->set('lstr_flash_error:' . $marker, 'Invalid upload. Please try again.');
            $session->set('lstr_flash_old:' . $marker, [
                'submitter_name'  => $submitterName  ?? '',
                'submitter_email' => $submitterEmail ?? '',
                'submitter_url'   => $submitterUrl   ?? '',
            ]);
            return Response::redirect($formUrl);
        }

        if ((int) $fileData['size'] > self::MAX_SIZE) {
            $session->set('lstr_flash_error:' . $marker, 'The file exceeds the 500 KB size limit.');
            $session->set('lstr_flash_old:' . $marker, [
                'submitter_name'  => $submitterName  ?? '',
                'submitter_email' => $submitterEmail ?? '',
                'submitter_url'   => $submitterUrl   ?? '',
            ]);
            return Response::redirect($formUrl);
        }

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime  = $finfo->file((string) $fileData['tmp_name']);

        if (!in_array($mime, self::ALLOWED_MIME, true)) {
            $session->set('lstr_flash_error:' . $marker, 'Only JPEG, PNG, GIF, and WebP images are accepted.');
            $session->set('lstr_flash_old:' . $marker, [
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
            $session->set('lstr_flash_error:' . $marker, 'Server configuration error: uploads directory not found. Please contact the site administrator.');
            $session->set('lstr_flash_old:' . $marker, [
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
            $session->set('lstr_flash_error:' . $marker, 'Could not save the uploaded file. Please try again.');
            $session->set('lstr_flash_old:' . $marker, [
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
        $session->set('lstr_flash_success_html:' . $marker, SuccessRenderer::render($successTpl, 'code-submitted'));
        return Response::redirect($formUrl);
    }

}
