<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Handles the public affiliate application form for both per-listing and
 collective (site-wide) contexts. Fans submit their site name, URL, optional
 email, optional image, and a message. The controller validates all input,
 checks for duplicate applications, handles image uploads with finfo MIME
 validation, inserts the application as pending, and sends an admin
 notification email. Rate limiting, honeypot, CAPTCHA, and blacklist checks
 are all enforced.
*/

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
use Luster\Support\CollectiveFormUrlResolver;
use Luster\Support\SuccessRenderer;

/**
 * Public affiliate application form (POST-only).
 *
 * POST /affiliate-apply/{id}         — validates input, stores a pending application,
 *                                      sends admin notification email, redirects.
 * POST /affiliate-apply/collective   — same for the collective (site-wide) scope.
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
 *  - Image capped at 500 KB
 */
final class AffiliateApplyController
{
    private const ALLOWED_MIME = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/gif'  => 'gif',
        'image/webp' => 'webp',
    ];

    private const MAX_IMAGE_SIZE = 512_000; // 500 KB

    public function __construct(private readonly Application $app) {}

    // -----------------------------------------------------------------------
    // POST /affiliate-apply/{id}
    // -----------------------------------------------------------------------

    /**
     * Processes the affiliate application form for a specific listing.
     *
     * Runs rate-limit, honeypot, CAPTCHA, blacklist, validation, and duplicate
     * checks in sequence. On success, inserts a pending application, sends an
     * admin notification email, and redirects back to the form URL with a
     * flash success message.
     */
    public function submit(Request $request, array $params): Response
    {
        $listingId     = (int) ($params['id'] ?? 0);
        $baseUrl       = $this->app->getBaseUrl();
        $session       = $this->app->getSession();
        $rateLimit     = $this->app->getRateLimit();
        $ip            = $request->ip();
        $embedReturnTo = (string) $session->get('lstr_embed_return_to', '');
        $marker        = (string) $request->post('_luster_embed_form', '');

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

        $formUrl = $embedReturnTo !== '' ? $embedReturnTo
            : $this->resolveFormUrl($listingId, $listing->url, $applySettings, $baseUrl);

        // Archived listings (Closed/Adopted Out) no longer accept affiliate applications.
        if ((new \Luster\Repositories\ListingLifecycleRepo($this->app->getDatabase()))->getByListingId($listingId) !== null) {
            return Response::notFound('Affiliate applications are not open for this listing.');
        }

        // Rate limiting
        if ($rateLimit->isBlocked($ip, RateLimit::ACTION_PUBLIC_AFFILIATE_APPLY)) {
            $session->set('lstr_flash_error:' . $marker, 'Too many requests. Please try again later.');
            return Response::redirect($formUrl);
        }

        // Honeypot — silent reject; fake success so bots see no difference
        if ((string) $request->post('website_confirm', '') !== '') {
            $session->remove('lstr_embed_return_to');
            $affApplySentTpl = $settingsRepo->getAffiliateApplySentTemplate($listingId) ?? '';
            $session->set('lstr_flash_success_html:' . $marker, SuccessRenderer::render($affApplySentTpl, 'affiliate-apply-sent'));
            return Response::redirect($formUrl);
        }

        // Captcha
        if (!Captcha::validate((string) $request->post('captcha_answer', ''), 'affiliate_apply')) {
            $rateLimit->hitDefault($ip, RateLimit::ACTION_PUBLIC_AFFILIATE_APPLY);
            $session->set('lstr_flash_error:' . $marker, 'Incorrect answer — please try again.');
            $session->set('lstr_flash_old:' . $marker, $this->collectOld($request));
            return Response::redirect($formUrl);
        }

        $name    = trim((string) $request->post('name',    ''));
        $url     = trim((string) $request->post('url',     ''));
        $email   = trim((string) $request->post('email',   ''));
        $message = trim((string) $request->post('message', ''));

        // Blacklist check on optional email
        if ($email !== '' && (new BlacklistRepo($this->app->getDatabase()))->isBlocked($email)) {
            $rateLimit->hitDefault($ip, RateLimit::ACTION_PUBLIC_AFFILIATE_APPLY);
            $session->set('lstr_flash_error:' . $marker, 'This email address cannot be used to submit an application.');
            $session->set('lstr_flash_old:' . $marker, $this->collectOld($request));
            return Response::redirect($formUrl);
        }

        $v = new Validator($request->postAll());
        $v->field('name')->required()->maxLength(200);
        $v->field('url')->required()->httpUrl()->maxLength(500);
        $v->field('email')->email()->maxLength(255);
        $v->field('message')->maxLength(2000);

        if ($v->fails()) {
            $rateLimit->hitDefault($ip, RateLimit::ACTION_PUBLIC_AFFILIATE_APPLY);
            $session->set('lstr_flash_error:' . $marker, implode(' ', $v->allErrors()));
            $session->set('lstr_flash_old:' . $marker, $this->collectOld($request));
            return Response::redirect($formUrl);
        }

        // Duplicate application check
        $affiliateRepo = new AffiliateRepo($this->app->getDatabase());
        if ($affiliateRepo->isDuplicateApplication($listingId, $url, $email)) {
            $session->set('lstr_flash_error:' . $marker, 'An application from that site is already on file.');
            $session->set('lstr_flash_old:' . $marker, $this->collectOld($request));
            return Response::redirect($formUrl);
        }

        // Optional image upload
        $imagePath = null;
        $uploadError = $this->handleImageUpload($request, $imagePath);
        if ($uploadError !== null) {
            $session->set('lstr_flash_error:' . $marker, $uploadError);
            $session->set('lstr_flash_old:' . $marker, $this->collectOld($request));
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

        $session->remove('lstr_embed_return_to');
        $affApplySentTpl = $settingsRepo->getAffiliateApplySentTemplate($listingId) ?? '';
        $session->set('lstr_flash_success_html:' . $marker, SuccessRenderer::render($affApplySentTpl, 'affiliate-apply-sent', [
            'aff_name'      => $name,
            'aff_url'       => $url,
            'aff_email'     => $email,
            'listing_title' => $listing->title,
            'listing_url'   => $listing->url,
        ]));
        return Response::redirect($formUrl);
    }

    // -----------------------------------------------------------------------
    // POST /affiliate-apply/collective
    // -----------------------------------------------------------------------

    /**
     * Processes the collective affiliate application form.
     *
     * Mirrors submit() but scopes the insert to fanlisting_id = null and reads
     * settings from lstr_site_settings rather than lstr_fanlisting_settings.
     */
    public function collectiveSubmit(Request $request, array $params): Response
    {
        $db            = $this->app->getDatabase();
        $baseUrl       = $this->app->getBaseUrl();
        $session       = $this->app->getSession();
        $rateLimit     = $this->app->getRateLimit();
        $ip            = $request->ip();
        $embedReturnTo = (string) $session->get('lstr_embed_return_to', '');
        $marker        = (string) $request->post('_luster_embed_form', '');

        $siteRepo = new SiteSettingsRepo($db);

        if (($siteRepo->get('collective_affiliate_apply_enabled') ?? '1') !== '1') {
            return Response::notFound('Collective affiliate applications are not open.');
        }

        $formUrl = $embedReturnTo !== '' ? $embedReturnTo
            : $this->collectiveFormUrl($this->app->getConfig(), $baseUrl);

        // Rate limiting
        if ($rateLimit->isBlocked($ip, RateLimit::ACTION_PUBLIC_AFFILIATE_APPLY)) {
            $session->set('lstr_flash_error:' . $marker, 'Too many requests. Please try again later.');
            return Response::redirect($formUrl);
        }

        // Honeypot — silent reject; fake success so bots see no difference
        if ((string) $request->post('website_confirm', '') !== '') {
            $session->remove('lstr_embed_return_to');
            $collectiveAffApplySentTpl = $siteRepo->get('collective_affiliate_apply_sent_template') ?? '';
            $session->set('lstr_flash_success_html:' . $marker, SuccessRenderer::render($collectiveAffApplySentTpl, 'affiliate-apply-sent'));
            return Response::redirect($formUrl);
        }

        // Captcha
        if (!Captcha::validate((string) $request->post('captcha_answer', ''), 'affiliate_apply')) {
            $rateLimit->hitDefault($ip, RateLimit::ACTION_PUBLIC_AFFILIATE_APPLY);
            $session->set('lstr_flash_error:' . $marker, 'Incorrect answer — please try again.');
            $session->set('lstr_flash_old:' . $marker, $this->collectOld($request));
            return Response::redirect($formUrl);
        }

        $name    = trim((string) $request->post('name',    ''));
        $url     = trim((string) $request->post('url',     ''));
        $email   = trim((string) $request->post('email',   ''));
        $message = trim((string) $request->post('message', ''));

        // Blacklist check on optional email
        if ($email !== '' && (new BlacklistRepo($db))->isBlocked($email)) {
            $rateLimit->hitDefault($ip, RateLimit::ACTION_PUBLIC_AFFILIATE_APPLY);
            $session->set('lstr_flash_error:' . $marker, 'This email address cannot be used to submit an application.');
            $session->set('lstr_flash_old:' . $marker, $this->collectOld($request));
            return Response::redirect($formUrl);
        }

        $v = new Validator($request->postAll());
        $v->field('name')->required()->maxLength(200);
        $v->field('url')->required()->httpUrl()->maxLength(500);
        $v->field('email')->email()->maxLength(255);
        $v->field('message')->maxLength(2000);

        if ($v->fails()) {
            $rateLimit->hitDefault($ip, RateLimit::ACTION_PUBLIC_AFFILIATE_APPLY);
            $session->set('lstr_flash_error:' . $marker, implode(' ', $v->allErrors()));
            $session->set('lstr_flash_old:' . $marker, $this->collectOld($request));
            return Response::redirect($formUrl);
        }

        // Duplicate check (null = collective scope)
        $affiliateRepo = new AffiliateRepo($db);
        if ($affiliateRepo->isDuplicateApplication(null, $url, $email)) {
            $session->set('lstr_flash_error:' . $marker, 'An application from that site is already on file.');
            $session->set('lstr_flash_old:' . $marker, $this->collectOld($request));
            return Response::redirect($formUrl);
        }

        // Optional image upload
        $imagePath   = null;
        $uploadError = $this->handleImageUpload($request, $imagePath);
        if ($uploadError !== null) {
            $session->set('lstr_flash_error:' . $marker, $uploadError);
            $session->set('lstr_flash_old:' . $marker, $this->collectOld($request));
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

        $session->remove('lstr_embed_return_to');
        $collectiveAffApplySentTpl = $siteRepo->get('collective_affiliate_apply_sent_template') ?? '';
        $session->set('lstr_flash_success_html:' . $marker, SuccessRenderer::render($collectiveAffApplySentTpl, 'affiliate-apply-sent', [
            'aff_name'  => $name,
            'aff_url'   => $url,
            'aff_email' => $email,
        ]));
        return Response::redirect($formUrl);
    }

    // -----------------------------------------------------------------------
    // Private helpers
    // -----------------------------------------------------------------------

    /**
     * Resolves the POST target for the collective affiliate application form.
     *
     * Reads `collective_form_url_affiliate_apply` from lstr_site_settings, then
     * delegates to CollectiveFormUrlResolver which prefers the override if set,
     * otherwise the site URL, otherwise the Luster embed base URL.
     */
    private function collectiveFormUrl(object $config, string $baseUrl): string
    {
        $embedBase = (string) ($config->get('embed_base_url', '') ?: $baseUrl);
        $siteUrl   = (string) ($config->get('site_url', '') ?: $baseUrl);

        $override = (new SiteSettingsRepo($this->app->getDatabase()))->get('collective_form_url_affiliate_apply');

        return CollectiveFormUrlResolver::resolve($override ?: 'affiliates.php', $siteUrl, $siteUrl);
    }

    /**
     * Resolves the POST target URL for the per-listing application form.
     *
     * If `form_url_affiliate_apply` is set in the listing's embed settings it is
     * joined to the listing URL; otherwise falls back to the embed base URL route.
     */
    private function resolveFormUrl(int $listingId, string $listingUrl, array $applySettings, string $baseUrl): string
    {
        $globalDefaults = (new ListingSettingsRepo($this->app->getDatabase()))->getGlobalFormUrlDefaults();
        return \Luster\Support\ListingFormUrlResolver::resolve(
            (string) $applySettings['form_url'],
            $listingUrl,
            $globalDefaults['form_url_affiliate_apply'],
        );
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
            return 'Image must be 500 KB or smaller.';
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

    /**
     * Sends the affiliate_applied email template to the listing owner (or site owner
     * for the collective scope). Silently swallows mailer exceptions so a failed
     * notification never rolls back an already-saved application.
     *
     * @param object|null $listing  Listing model, or null for collective scope.
     */
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
            $listingName = (string) $listing->title;
            $listingUrl  = (string) $listing->url;
        } else {
            $adminEmail  = (string) $config->get('owner_email', '');
            $listingId   = null;
            $listingName = (string) $config->get('site_name', '');
            $listingUrl  = (string) $config->get('site_url', '');
        }

        if ($adminEmail === '') {
            return;
        }

        $emailRepo = new EmailTemplateRepo($this->app->getDatabase());
        $tokens    = EmailTemplateRepo::buildAffiliateTokens(
            listingName:    $listingName,
            listingUrl:     $listingUrl,
            siteName:       (string) $config->get('site_name', ''),
            ownerName:      (string) $config->get('owner_name', ''),
            ownerEmail:     (string) $config->get('owner_email', ''),
            affName:        $affName,
            affUrl:         $affUrl,
            affEmail:       $affEmail,
            affMessage:     $affMessage,
            listingSubject: $listing !== null ? (string) $listing->subject : '',
            listingType:    $listing !== null
                ? (new ListingSettingsRepo($this->app->getDatabase()))->getListingType($listingId)
                : 'listing',
        );

        [$subject, $body] = $emailRepo->resolveForListing(
            $listingId,
            EmailTemplateRepo::TYPE_AFFILIATE_APPLIED,
            $tokens,
        );

        if ($subject === '') {
            return;
        }

        try {
            $mailer = new Mailer($config);
            $mailer->send($adminEmail, '', $subject, $body);
        } catch (\Exception) {
            // Email failure is non-fatal — the application was already saved
        }
    }

    /**
     * Extracts trimmed text fields from the current POST for flash repopulation.
     */
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
