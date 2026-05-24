<?php

declare(strict_types=1);

namespace Luster\Controllers\Public;

use Luster\Core\Application;
use Luster\Core\Captcha;
use Luster\Core\Mailer;
use Luster\Core\RateLimit;
use Luster\Core\Request;
use Luster\Core\Response;
use Luster\Core\Validator;
use Luster\Repositories\AffiliateRepo;
use Luster\Repositories\EmailTemplateRepo;
use Luster\Repositories\ListingRepo;
use Luster\Repositories\ListingSettingsRepo;

/**
 * Public affiliate application form.
 *
 * GET  /affiliate-apply/{id} — renders the application form for a listing.
 * POST /affiliate-apply/{id} — validates input, stores a pending application,
 *                              sends admin notification email, redirects.
 *
 * Security properties:
 *  - Only available when affiliate_apply_enabled = 1 for the listing
 *  - CSRF token validated by CsrfMiddleware before this action runs
 *  - Honeypot field silently rejects bot submissions
 *  - Arithmetic CAPTCHA prevents automated submissions
 *  - Rate-limited per IP (5 submissions per hour) via lstr_rate_limits
 *  - All input validated before any DB write
 *  - Duplicate application checked before insert (by URL and email)
 *  - Image validated by finfo MIME type, not file extension
 *  - Image capped at 2 MB
 */
final class AffiliateApplyController
{
    private const ALLOWED_MIME = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/gif'  => 'gif',
        'image/webp' => 'webp',
    ];

    private const MAX_IMAGE_SIZE = 2_097_152; // 2 MB

    public function __construct(private readonly Application $app) {}

    // -----------------------------------------------------------------------
    // GET /affiliate-apply/{id}
    // -----------------------------------------------------------------------

    public function show(Request $request, array $params): Response
    {
        $listingId = (int) ($params['id'] ?? 0);

        $settingsRepo  = new ListingSettingsRepo($this->app->getDatabase());
        $applySettings = $settingsRepo->getAffiliateApplySettings($listingId);

        if (!$applySettings['enabled']) {
            return Response::notFound('Affiliate applications are not open for this listing.');
        }

        $listingRepo = new ListingRepo($this->app->getDatabase(), $this->app->getConfig());
        $listing     = $listingRepo->getById($listingId);

        if ($listing === null) {
            return Response::notFound('That listing does not exist.');
        }

        $session = $this->app->getSession();
        $error   = (string) $session->get('lstr_flash_error', '');
        $old     = (array)  $session->get('lstr_flash_old',   []);
        $session->remove('lstr_flash_error');
        $session->remove('lstr_flash_old');

        return $this->renderForm($listingId, $listing, $applySettings, $old, $error);
    }

    // -----------------------------------------------------------------------
    // POST /affiliate-apply/{id}
    // -----------------------------------------------------------------------

    public function submit(Request $request, array $params): Response
    {
        $listingId = (int) ($params['id'] ?? 0);
        $baseUrl   = $this->app->getBaseUrl();
        $session   = $this->app->getSession();
        $rateLimit = $this->app->getRateLimit();
        $ip        = $request->ip();

        $settingsRepo  = new ListingSettingsRepo($this->app->getDatabase());
        $applySettings = $settingsRepo->getAffiliateApplySettings($listingId);

        if (!$applySettings['enabled']) {
            return Response::notFound('Affiliate applications are not open for this listing.');
        }

        $listingRepo = new ListingRepo($this->app->getDatabase(), $this->app->getConfig());
        $listing     = $listingRepo->getById($listingId);

        if ($listing === null) {
            return Response::notFound('That listing does not exist.');
        }

        $formUrl = $this->resolveFormUrl($listingId, $listing->url, $applySettings, $baseUrl);

        // Rate limiting
        if ($rateLimit->isBlocked($ip, RateLimit::ACTION_PUBLIC_AFFILIATE_APPLY)) {
            $session->set('lstr_flash_error', 'Too many requests. Please try again later.');
            return Response::redirect($formUrl);
        }

        // Honeypot — silent reject
        if ((string) $request->post('website_confirm', '') !== '') {
            $redirectUrl = $this->resolveRedirectUrl($listingId, $listing->url, $applySettings, $baseUrl);
            return Response::redirect($redirectUrl);
        }

        // Captcha
        if (!Captcha::validate((string) $request->post('captcha_answer', ''), 'affiliate_apply')) {
            $rateLimit->hitDefault($ip, RateLimit::ACTION_PUBLIC_AFFILIATE_APPLY);
            $session->set('lstr_flash_error', 'Incorrect answer — please try again.');
            $session->set('lstr_flash_old', $this->collectOld($request));
            return Response::redirect($formUrl);
        }

        $name    = trim((string) $request->post('name',    ''));
        $url     = trim((string) $request->post('url',     ''));
        $email   = trim((string) $request->post('email',   ''));
        $message = trim((string) $request->post('message', ''));

        $v = new Validator($request->postAll());
        $v->field('name')->required()->maxLength(200);
        $v->field('url')->required()->url()->maxLength(500);
        $v->field('email')->email()->maxLength(255);
        $v->field('message')->maxLength(2000);

        if ($v->fails()) {
            $rateLimit->hitDefault($ip, RateLimit::ACTION_PUBLIC_AFFILIATE_APPLY);
            $session->set('lstr_flash_error', implode(' ', $v->allErrors()));
            $session->set('lstr_flash_old', $this->collectOld($request));
            return Response::redirect($formUrl);
        }

        // Duplicate application check
        $affiliateRepo = new AffiliateRepo($this->app->getDatabase());
        if ($affiliateRepo->isDuplicateApplication($listingId, $url, $email)) {
            $session->set('lstr_flash_error', 'An application from that site is already on file.');
            $session->set('lstr_flash_old', $this->collectOld($request));
            return Response::redirect($formUrl);
        }

        // Optional image upload
        $imagePath = null;
        $uploadError = $this->handleImageUpload($request, $imagePath);
        if ($uploadError !== null) {
            $session->set('lstr_flash_error', $uploadError);
            $session->set('lstr_flash_old', $this->collectOld($request));
            return Response::redirect($formUrl);
        }

        // Insert pending application
        $affiliateRepo->insertPending([
            'fanlisting_id'       => $listingId,
            'name'                => $name,
            'url'                 => $url,
            'email'               => $email !== '' ? $email : null,
            'image'               => $imagePath,
            'application_message' => $message !== '' ? $message : null,
        ]);

        $rateLimit->hitDefault($ip, RateLimit::ACTION_PUBLIC_AFFILIATE_APPLY);

        // Notify admin
        $this->sendAdminNotification($listing, $name, $url, $email, $message);

        $redirectUrl = $this->resolveRedirectUrl($listingId, $listing->url, $applySettings, $baseUrl);
        return Response::redirect($redirectUrl);
    }

    // -----------------------------------------------------------------------
    // GET /affiliate-apply/{id}/success
    // -----------------------------------------------------------------------

    public function success(Request $request, array $params): Response
    {
        $listingId = (int) ($params['id'] ?? 0);

        $listingRepo = new ListingRepo($this->app->getDatabase(), $this->app->getConfig());
        $listing     = $listingRepo->getById($listingId);

        if ($listing === null) {
            return Response::notFound('That listing does not exist.');
        }

        $settingsRepo  = new ListingSettingsRepo($this->app->getDatabase());
        $embedSettings = $settingsRepo->getEmbedSettings($listingId);

        $data = [
            'listing'      => $listing,
            'listing_name' => (string) $listing->name,
            'listing_url'  => (string) $listing->url,
            'page_title'   => 'Application Received — ' . $listing->name,
            'base_url'     => $this->app->getBaseUrl(),
            'theme_path'   => rtrim((string) $listing->url, '/') . '/' . ltrim((string) ($embedSettings['theme_path'] ?? 'theme'), '/'),
        ];

        return Response::html(
            $this->app->getView()->renderPublic('affiliate-apply-success.php', $data)
        );
    }

    // -----------------------------------------------------------------------
    // Private helpers
    // -----------------------------------------------------------------------

    private function renderForm(
        int    $listingId,
        object $listing,
        array  $applySettings,
        array  $old,
        string $error,
    ): Response {
        $captchaQuestion = Captcha::getQuestion('affiliate_apply');
        $config          = $this->app->getConfig();
        $embedSettings   = (new ListingSettingsRepo($this->app->getDatabase()))->getEmbedSettings($listingId);

        $formUrl = $this->resolveFormUrl(
            $listingId,
            (string) $listing->url,
            $applySettings,
            $this->app->getBaseUrl(),
        );

        $data = [
            'listing'          => $listing,
            'listing_name'     => (string) $listing->name,
            'listing_url'      => (string) $listing->url,
            'page_title'       => 'Affiliate Application — ' . $listing->name,
            'base_url'         => $this->app->getBaseUrl(),
            'theme_path'       => rtrim((string) $listing->url, '/') . '/' . ltrim((string) ($embedSettings['theme_path'] ?? 'theme'), '/'),
            'form_action'      => $formUrl,
            'csrf_token'       => $this->app->getCsrf()->getToken(),
            'captcha_question' => $captchaQuestion,
            'captcha_error'    => '',
            'errors'           => $error,
            'old'              => $old,
        ];

        return Response::html(
            $this->app->getView()->renderPublic('affiliate-apply.php', $data)
        );
    }

    private function resolveFormUrl(int $listingId, string $listingUrl, array $applySettings, string $baseUrl): string
    {
        $embedBase = (string) ($this->app->getConfig()->get('embed_base_url', '') ?: $baseUrl);

        if ($applySettings['form_url'] !== '') {
            return rtrim($listingUrl, '/') . '/' . ltrim($applySettings['form_url'], '/');
        }

        return rtrim($embedBase, '/') . '/affiliate-apply/' . $listingId;
    }

    private function resolveRedirectUrl(int $listingId, string $listingUrl, array $applySettings, string $baseUrl): string
    {
        if ($applySettings['redirect_url'] !== '') {
            return rtrim($listingUrl, '/') . '/' . ltrim($applySettings['redirect_url'], '/');
        }

        return $baseUrl . '/affiliate-apply/' . $listingId . '/success';
    }

    /**
     * Attempts to handle an optional image upload.
     * Returns null on success (or when no file was uploaded), or an error string.
     * Sets $imagePath to the stored filename (relative) on success.
     */
    private function handleImageUpload(Request $request, ?string &$imagePath): ?string
    {
        $file = $request->file('image') ?? [];
        if (empty($file['tmp_name'])) {
            return null;
        }

        $uploadError = $file['error'] ?? UPLOAD_ERR_NO_FILE;
        if ($uploadError === UPLOAD_ERR_NO_FILE) {
            return null;
        }

        if ($uploadError !== UPLOAD_ERR_OK) {
            return 'Image upload failed. Please try again.';
        }

        if (($file['size'] ?? 0) > self::MAX_IMAGE_SIZE) {
            return 'Image must be 2 MB or smaller.';
        }

        $tmpName = $file['tmp_name'];
        $finfo   = new \finfo(FILEINFO_MIME_TYPE);
        $mime    = $finfo->file($tmpName);

        if (!isset(self::ALLOWED_MIME[$mime])) {
            return 'Only JPEG, PNG, GIF, and WebP images are accepted.';
        }

        $ext     = self::ALLOWED_MIME[$mime];
        $dest    = dirname(__DIR__, 3) . '/public/uploads/affiliates/';

        if (!is_dir($dest)) {
            mkdir($dest, 0755, true);
            file_put_contents($dest . '.htaccess', "php_flag engine off\nOptions -ExecCGI\n");
        }

        $filename  = uniqid('aapp_', true) . '.' . $ext;
        $fullPath  = $dest . $filename;

        if (!move_uploaded_file($tmpName, $fullPath)) {
            return 'Could not save the uploaded image.';
        }

        $imagePath = $filename;
        return null;
    }

    private function sendAdminNotification(
        object $listing,
        string $affName,
        string $affUrl,
        string $affEmail,
        string $affMessage,
    ): void {
        $config     = $this->app->getConfig();
        $adminEmail = (string) $listing->email;
        if ($adminEmail === '') {
            $adminEmail = (string) $config->get('owner_email', '');
        }
        if ($adminEmail === '') {
            return;
        }

        $emailRepo = new EmailTemplateRepo($this->app->getDatabase());
        $tokens    = EmailTemplateRepo::buildAffiliateTokens(
            listingName: (string) $listing->name,
            listingUrl:  (string) $listing->url,
            siteName:    (string) ($config->get('app_name', '') ?: $config->get('site_url', '')),
            ownerName:   (string) ($config->get('owner_name', '')),
            ownerEmail:  (string) ($config->get('owner_email', '')),
            affName:     $affName,
            affUrl:      $affUrl,
            affEmail:    $affEmail,
            affMessage:  $affMessage,
        );

        [$subject, $body] = $emailRepo->resolveForListing(
            (int) $listing->id,
            EmailTemplateRepo::TYPE_AFFILIATE_APPLIED,
            $tokens,
        );

        try {
            $mailer = new Mailer($config);
            $mailer->send($adminEmail, '', $subject, $body);
        } catch (\Exception) {
            // Email failure is non-fatal — the application was already saved
        }
    }

    private function collectOld(Request $request): array
    {
        return [
            'name'    => trim((string) $request->post('name',    '')),
            'url'     => trim((string) $request->post('url',     '')),
            'email'   => trim((string) $request->post('email',   '')),
            'message' => trim((string) $request->post('message', '')),
        ];
    }
}
