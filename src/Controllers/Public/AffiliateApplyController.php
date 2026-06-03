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
use Luster\Repositories\BlacklistRepo;
use Luster\Repositories\EmailTemplateRepo;
use Luster\Repositories\ListingRepo;
use Luster\Repositories\ListingSettingsRepo;
use Luster\Repositories\SiteSettingsRepo;

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

        // Blacklist check on optional email
        if ($email !== '' && (new BlacklistRepo($this->app->getDatabase()))->isBlocked($email)) {
            $rateLimit->hitDefault($ip, RateLimit::ACTION_PUBLIC_AFFILIATE_APPLY);
            $session->set('lstr_flash_error', 'This email address cannot be used to submit an application.');
            $session->set('lstr_flash_old', $this->collectOld($request));
            return Response::redirect($formUrl);
        }

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
    // GET /affiliate-apply/collective
    // -----------------------------------------------------------------------

    public function collectiveShow(Request $request, array $params): Response
    {
        $siteRepo = new SiteSettingsRepo($this->app->getDatabase());

        if ($siteRepo->get('collective_affiliate_apply_enabled') !== '1') {
            return Response::notFound('Collective affiliate applications are not open.');
        }

        $session = $this->app->getSession();
        $error   = (string) $session->get('lstr_flash_error', '');
        $old     = (array)  $session->get('lstr_flash_old',   []);
        $session->remove('lstr_flash_error');
        $session->remove('lstr_flash_old');

        return $this->renderCollectiveForm($error, $old);
    }

    // -----------------------------------------------------------------------
    // POST /affiliate-apply/collective
    // -----------------------------------------------------------------------

    public function collectiveSubmit(Request $request, array $params): Response
    {
        $db        = $this->app->getDatabase();
        $baseUrl   = $this->app->getBaseUrl();
        $session   = $this->app->getSession();
        $rateLimit = $this->app->getRateLimit();
        $ip        = $request->ip();

        $siteRepo = new SiteSettingsRepo($db);

        if ($siteRepo->get('collective_affiliate_apply_enabled') !== '1') {
            return Response::notFound('Collective affiliate applications are not open.');
        }

        $formUrl = $this->collectiveFormUrl($this->app->getConfig(), $baseUrl);

        // Rate limiting
        if ($rateLimit->isBlocked($ip, RateLimit::ACTION_PUBLIC_AFFILIATE_APPLY)) {
            $session->set('lstr_flash_error', 'Too many requests. Please try again later.');
            return Response::redirect($formUrl);
        }

        // Honeypot — silent reject
        if ((string) $request->post('website_confirm', '') !== '') {
            return Response::redirect($baseUrl . '/affiliate-apply/collective/success');
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

        // Blacklist check on optional email
        if ($email !== '' && (new BlacklistRepo($db))->isBlocked($email)) {
            $rateLimit->hitDefault($ip, RateLimit::ACTION_PUBLIC_AFFILIATE_APPLY);
            $session->set('lstr_flash_error', 'This email address cannot be used to submit an application.');
            $session->set('lstr_flash_old', $this->collectOld($request));
            return Response::redirect($formUrl);
        }

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

        // Duplicate check (null = collective scope)
        $affiliateRepo = new AffiliateRepo($db);
        if ($affiliateRepo->isDuplicateApplication(null, $url, $email)) {
            $session->set('lstr_flash_error', 'An application from that site is already on file.');
            $session->set('lstr_flash_old', $this->collectOld($request));
            return Response::redirect($formUrl);
        }

        // Optional image upload
        $imagePath   = null;
        $uploadError = $this->handleImageUpload($request, $imagePath);
        if ($uploadError !== null) {
            $session->set('lstr_flash_error', $uploadError);
            $session->set('lstr_flash_old', $this->collectOld($request));
            return Response::redirect($formUrl);
        }

        // Insert collective pending application (fanlisting_id = null)
        $affiliateRepo->insertPending([
            'fanlisting_id'       => null,
            'name'                => $name,
            'url'                 => $url,
            'email'               => $email !== '' ? $email : null,
            'image'               => $imagePath,
            'application_message' => $message !== '' ? $message : null,
        ]);

        $rateLimit->hitDefault($ip, RateLimit::ACTION_PUBLIC_AFFILIATE_APPLY);

        // Notify admin
        $this->sendAdminNotification(null, $name, $url, $email, $message);

        return Response::redirect($baseUrl . '/affiliate-apply/collective/success');
    }

    // -----------------------------------------------------------------------
    // GET /affiliate-apply/collective/success
    // -----------------------------------------------------------------------

    public function collectiveSuccess(Request $request, array $params): Response
    {
        $config   = $this->app->getConfig();
        $siteName = (string) $config->get('site_name', 'Collective');
        $siteUrl  = (string) ($config->get('site_url', '') ?: $this->app->getBaseUrl());

        $data = [
            'listing'      => null,
            'listing_name' => $siteName,
            'listing_url'  => $siteUrl,
            'page_title'   => 'Application Received',
            'base_url'     => $this->app->getBaseUrl(),
            'theme_path'   => $this->app->getBaseUrl() . '/assets/default/',
        ];

        return Response::html(
            $this->app->getView()->renderPublic('affiliate-apply-success.php', $data)
        );
    }

    // -----------------------------------------------------------------------
    // Private helpers
    // -----------------------------------------------------------------------

    private function renderCollectiveForm(string $error, array $old): Response
    {
        $captchaQuestion = Captcha::getQuestion('affiliate_apply');
        $config          = $this->app->getConfig();
        $baseUrl         = $this->app->getBaseUrl();
        $siteName        = (string) $config->get('site_name', 'Collective');
        $siteUrl         = (string) ($config->get('site_url', '') ?: $baseUrl);

        $data = [
            'listing'          => null,
            'listing_name'     => $siteName,
            'listing_url'      => $siteUrl,
            'page_title'       => 'Affiliate Application',
            'base_url'         => $baseUrl,
            'theme_path'       => $baseUrl . '/assets/default/',
            'form_action'      => $this->collectiveFormUrl($config, $baseUrl),
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

    private function collectiveFormUrl(object $config, string $baseUrl): string
    {
        $embedBase = (string) ($config->get('embed_base_url', '') ?: $baseUrl);
        return rtrim($embedBase, '/') . '/affiliate-apply/collective';
    }

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

        $tmpName = (string) ($file['tmp_name'] ?? '');

        if (!is_uploaded_file($tmpName)) {
            return 'Invalid upload. Please try again.';
        }

        if (($file['size'] ?? 0) > self::MAX_IMAGE_SIZE) {
            return 'Image must be 2 MB or smaller.';
        }

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime  = $finfo->file($tmpName);

        if (!isset(self::ALLOWED_MIME[$mime])) {
            return 'Only JPEG, PNG, GIF, and WebP images are accepted.';
        }

        if (getimagesize($tmpName) === false) {
            return 'The uploaded file is not a valid image.';
        }

        $ext  = self::ALLOWED_MIME[$mime];
        $dest = dirname(__DIR__, 3) . '/public/uploads/affiliates/';

        if (!is_dir($dest)) {
            mkdir($dest, 0755, true);
            file_put_contents($dest . '.htaccess',
                "<FilesMatch \"\\.(?i:jpg|jpeg|png|gif|webp)$\">\n    Require all granted\n</FilesMatch>\n<FilesMatch \".\">\n    Require all denied\n</FilesMatch>\n"
            );
        }

        $filename = 'aapp_' . bin2hex(random_bytes(8)) . '.' . $ext;
        $fullPath = $dest . $filename;

        if (!move_uploaded_file($tmpName, $fullPath)) {
            return 'Could not save the uploaded image.';
        }

        $imagePath = $filename;
        return null;
    }

    private function sendAdminNotification(
        ?object $listing,
        string  $affName,
        string  $affUrl,
        string  $affEmail,
        string  $affMessage,
    ): void {
        $config = $this->app->getConfig();

        if ($listing !== null) {
            $adminEmail  = (string) $listing->email;
            if ($adminEmail === '') {
                $adminEmail = (string) $config->get('owner_email', '');
            }
            $listingId   = (int) $listing->id;
            $listingName = (string) $listing->name;
            $listingUrl  = (string) $listing->url;
        } else {
            $adminEmail  = (string) $config->get('owner_email', '');
            $listingId   = null;
            $listingName = (string) $config->get('site_name', '');
            $listingUrl  = (string) ($config->get('site_url', '') ?: $this->app->getBaseUrl() . '/affiliates');
        }

        if ($adminEmail === '') {
            return;
        }

        $emailRepo = new EmailTemplateRepo($this->app->getDatabase());
        $tokens    = EmailTemplateRepo::buildAffiliateTokens(
            listingName: $listingName,
            listingUrl:  $listingUrl,
            siteName:    (string) $config->get('site_name', ''),
            ownerName:   (string) $config->get('owner_name', ''),
            ownerEmail:  (string) $config->get('owner_email', ''),
            affName:     $affName,
            affUrl:      $affUrl,
            affEmail:    $affEmail,
            affMessage:  $affMessage,
        );

        [$subject, $body] = $emailRepo->resolveForListing(
            $listingId,
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
