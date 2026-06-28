<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Public-facing controller for adoption applications. Renders the per-listing
 and collective adoption forms, validates submissions with captcha and rate
 limiting, checks the blacklist and duplicate guard, inserts the application
 into the database, and sends an admin notification email.
*/

declare(strict_types=1);

namespace Luster\Addons\Adoption\Controllers;

use Luster\Addons\Adoption\AdoptionRepo;
use Luster\Core\Application;
use Luster\Core\Captcha;
use Luster\Core\Mailer;
use Luster\Core\RateLimit;
use Luster\Core\Request;
use Luster\Core\Response;
use Luster\Core\Validator;
use Luster\Repositories\BlacklistRepo;
use Luster\Repositories\EmailTemplateRepo;
use Luster\Repositories\ListingRepo;
use Luster\Repositories\ListingSettingsRepo;
use Luster\Repositories\SiteSettingsRepo;
use Luster\Support\CollectiveFormUrlResolver;
use Luster\Support\SuccessRenderer;

/**
 * Public adoption application form.
 *
 * Routes (registered by AdoptionAddon::registerRoutes()):
 *   POST /adopt/{id}          — validates input, inserts application, redirects to host page.
 *   POST /adopt-collective    — validates input for collective form, inserts, redirects.
 *
 * Only available when the listing has for_adoption=1 in lstr_fanlisting_settings
 * and the listing is not inactive.
 */
final class AdoptPublicController
{
    public function __construct(private readonly Application $app) {}

    // -----------------------------------------------------------------------
    // POST /adopt/{id}
    // -----------------------------------------------------------------------

    /**
     * Processes a per-listing adoption application.
     *
     * Applies rate limiting, honeypot, captcha, Validator, blacklist, and
     * duplicate checks. On success inserts the application, sends an admin
     * notification email, and redirects with a flash success message.
     */
    public function submit(Request $request, array $params): Response
    {
        $listingId     = (int) ($params['id'] ?? 0);
        $baseUrl       = $this->app->getBaseUrl();
        $session       = $this->app->getSession();
        $ip            = $request->ip();
        $embedReturnTo = (string) $session->get('lstr_embed_return_to', '');
        $listing = $this->requireAdoptableListing($listingId);
        if ($listing instanceof Response) {
            return $listing;
        }
        $fallbackUrl = $listing->url;

        // Rate limiting
        $rateLimit = $this->app->getRateLimit();
        if ($rateLimit->isBlocked($ip, RateLimit::ACTION_PUBLIC_ADOPT)) {
            $session->set('lstr_flash_error', 'Too many requests. Please try again later.');
            return Response::redirect($embedReturnTo !== '' ? $embedReturnTo : $fallbackUrl);
        }

        // Honeypot — silent reject; fake success so bots see no difference
        if ((string) $request->post('website_confirm', '') !== '') {
            $session->remove('lstr_embed_return_to');
            $successTpl = (new ListingSettingsRepo($this->app->getDatabase()))->getSuccessTemplate($listingId) ?? '';
            $session->set('lstr_flash_success_html', SuccessRenderer::render($successTpl, 'adopt-submitted'));
            return Response::redirect($embedReturnTo !== '' ? $embedReturnTo : $fallbackUrl);
        }

        // Captcha — redirect with flash on failure so the form re-renders with a fresh question
        if (!Captcha::validate((string) $request->post('captcha_answer', ''), 'adopt')) {
            $rateLimit->hitDefault($ip, RateLimit::ACTION_PUBLIC_ADOPT);
            $session->set('lstr_flash_error', 'Incorrect answer — please try again.');
            $session->set('lstr_flash_old', [
                'name'    => trim((string) $request->post('name',    '')),
                'email'   => trim((string) $request->post('email',   '')),
                'url'     => trim((string) $request->post('url',     '')),
                'message' => trim((string) $request->post('message', '')),
            ]);
            return Response::redirect($embedReturnTo !== '' ? $embedReturnTo : $fallbackUrl);
        }

        $name    = trim((string) $request->post('name',    ''));
        $email   = trim((string) $request->post('email',   ''));
        $url     = trim((string) $request->post('url',     ''));
        $message = trim((string) $request->post('message', ''));

        $v = new Validator(['name' => $name, 'email' => $email, 'url' => $url, 'message' => $message]);
        $v->field('name')->required()->maxLength(100);
        $v->field('email')->required()->email()->maxLength(255);
        $v->field('url')->httpUrl()->maxLength(500);
        $v->field('message')->maxLength(2000);

        if ($v->fails()) {
            $rateLimit->hitDefault($ip, RateLimit::ACTION_PUBLIC_ADOPT);
            $session->set('lstr_flash_error', implode(' ', $v->allErrors()));
            $session->set('lstr_flash_old', ['name' => $name, 'email' => $email, 'url' => $url, 'message' => $message]);
            return Response::redirect($embedReturnTo !== '' ? $embedReturnTo : $fallbackUrl);
        }

        $db           = $this->app->getDatabase();
        $adoptionRepo = new AdoptionRepo($db);

        // Blacklist check
        if ((new BlacklistRepo($db))->isBlocked($email)) {
            $session->set('lstr_flash_error', 'That email address cannot be used to submit an application.');
            $session->set('lstr_flash_old', ['name' => $name, 'email' => $email, 'url' => $url, 'message' => $message]);
            return Response::redirect($embedReturnTo !== '' ? $embedReturnTo : $fallbackUrl);
        }

        // Duplicate check — one active application per email per listing
        if ($adoptionRepo->hasPendingApplication($listingId, $email)) {
            $session->set('lstr_flash_error', 'An application for this listing from that email address is already pending review.');
            $session->set('lstr_flash_old', ['name' => $name, 'email' => $email, 'url' => $url, 'message' => $message]);
            return Response::redirect($embedReturnTo !== '' ? $embedReturnTo : $fallbackUrl);
        }

        $adoptionRepo->insert($listingId, [
            'name'    => $name,
            'email'   => $email,
            'url'     => $url,
            'message' => $message !== '' ? $message : null,
        ]);

        $rateLimit->clear($ip, RateLimit::ACTION_PUBLIC_ADOPT);

        // Admin notification email
        try {
            $config    = $this->app->getConfig();
            $adminTo   = ($listing->email !== '') ? $listing->email
                       : (string) $config->get('owner_email', '');
            if ($adminTo !== '') {
                $emailRepo = new EmailTemplateRepo($db);
                $tokens    = EmailTemplateRepo::buildAdoptionTokens(
                    listingName      : $listing->title,
                    listingUrl       : $listing->url,
                    siteName         : (string) $config->get('site_name',   ''),
                    ownerName        : (string) $config->get('owner_name',  ''),
                    ownerEmail       : (string) $config->get('owner_email', ''),
                    applicantName    : $name,
                    applicantEmail   : $email,
                    applicantUrl     : $url,
                    applicantMessage : $message,
                    listingSubject   : $listing->subject,
                    listingType      : (new ListingSettingsRepo($this->app->getDatabase()))->getListingType($listingId),
                );
                [$subject, $body] = $emailRepo->resolveForListing(
                    $listingId,
                    EmailTemplateRepo::TYPE_ADOPTION_APPLIED,
                    $tokens,
                );
                if ($subject !== '') {
                    (new Mailer($config))->send(
                        $adminTo,
                        (string) $config->get('owner_name', ''),
                        $subject,
                        $body,
                    );
                }
            }
        } catch (\Throwable) {
            // Email failures are non-fatal
        }

        $session->remove('lstr_embed_return_to');
        $successTpl = (new ListingSettingsRepo($this->app->getDatabase()))->getSuccessTemplate($listingId) ?? '';
        $session->set('lstr_flash_success_html', SuccessRenderer::render($successTpl, 'adopt-submitted', [
            'listing_title' => $listing->title,
            'listing_url'   => $listing->url,
        ]));
        return Response::redirect($embedReturnTo !== '' ? $embedReturnTo : $fallbackUrl);
    }

    // -----------------------------------------------------------------------
    // POST /adopt-collective
    // -----------------------------------------------------------------------

    /**
     * Processes a collective adoption application.
     *
     * Validates the submitted listing_id against the adoptable-listing set
     * (IDOR guard), then applies the same rate limit, honeypot, captcha,
     * blacklist, and duplicate guards as the per-listing form before inserting.
     */
    public function collectiveSubmit(Request $request, array $params): Response
    {
        $baseUrl       = $this->app->getBaseUrl();
        $config        = $this->app->getConfig();
        $session       = $this->app->getSession();
        $ip            = $request->ip();
        $embedReturnTo = (string) $session->get('lstr_embed_return_to', '');
        $siteUrl       = (string) ($config->get('site_url', '') ?: $baseUrl);
        $adoptOverride = (new SiteSettingsRepo($this->app->getDatabase()))->get('collective_form_url_adopt');
        $fallbackUrl   = CollectiveFormUrlResolver::resolve($adoptOverride, $siteUrl, $siteUrl);

        // Rate limiting
        $rateLimit = $this->app->getRateLimit();
        if ($rateLimit->isBlocked($ip, RateLimit::ACTION_PUBLIC_ADOPT)) {
            $session->set('lstr_flash_error', 'Too many requests. Please try again later.');
            return Response::redirect($embedReturnTo !== '' ? $embedReturnTo : $fallbackUrl);
        }

        // Honeypot — silent reject; fake success so bots see no difference
        if ((string) $request->post('website_confirm', '') !== '') {
            $session->remove('lstr_embed_return_to');
            $session->set('lstr_flash_success_html', SuccessRenderer::render('', 'adopt-submitted'));
            return Response::redirect($embedReturnTo !== '' ? $embedReturnTo : $fallbackUrl);
        }

        // Captcha
        if (!Captcha::validate((string) $request->post('captcha_answer', ''), 'adopt')) {
            $rateLimit->hitDefault($ip, RateLimit::ACTION_PUBLIC_ADOPT);
            $session->set('lstr_flash_error', 'Incorrect answer — please try again.');
            $session->set('lstr_flash_old', [
                'listing_id' => trim((string) $request->post('listing_id', '')),
                'name'       => trim((string) $request->post('name',       '')),
                'email'      => trim((string) $request->post('email',      '')),
                'url'        => trim((string) $request->post('url',        '')),
                'message'    => trim((string) $request->post('message',    '')),
            ]);
            return Response::redirect($embedReturnTo !== '' ? $embedReturnTo : $fallbackUrl);
        }

        $listingId = (int) $request->post('listing_id', 0);
        $name      = trim((string) $request->post('name',    ''));
        $email     = trim((string) $request->post('email',   ''));
        $url       = trim((string) $request->post('url',     ''));
        $message   = trim((string) $request->post('message', ''));

        $v = new Validator(['listing_id' => (string) $listingId, 'name' => $name, 'email' => $email, 'url' => $url, 'message' => $message]);
        $v->field('listing_id')->required()->integer()->min(1);
        $v->field('name')->required()->maxLength(100);
        $v->field('email')->required()->email()->maxLength(255);
        $v->field('url')->httpUrl()->maxLength(500);
        $v->field('message')->maxLength(2000);

        if ($v->fails()) {
            $rateLimit->hitDefault($ip, RateLimit::ACTION_PUBLIC_ADOPT);
            $session->set('lstr_flash_error', implode(' ', $v->allErrors()));
            $session->set('lstr_flash_old', ['listing_id' => (string) $listingId, 'name' => $name, 'email' => $email, 'url' => $url, 'message' => $message]);
            return Response::redirect($embedReturnTo !== '' ? $embedReturnTo : $fallbackUrl);
        }

        $db           = $this->app->getDatabase();
        $settingsRepo = new ListingSettingsRepo($db);
        $listingRepo  = new ListingRepo($db, $this->app->getConfig());

        // Verify the listing is actually adoptable (prevents IDOR for non-adoptable listings)
        $adoptable = $settingsRepo->getAdoptableListings($listingRepo);
        $validIds  = array_column($adoptable, 'id');
        if (!in_array($listingId, $validIds, true)) {
            $session->set('lstr_flash_error', 'The selected listing is not available for adoption.');
            $session->set('lstr_flash_old', ['listing_id' => (string) $listingId, 'name' => $name, 'email' => $email, 'url' => $url, 'message' => $message]);
            return Response::redirect($embedReturnTo !== '' ? $embedReturnTo : $fallbackUrl);
        }

        $adoptionRepo = new AdoptionRepo($db);

        // Blacklist check
        if ((new BlacklistRepo($db))->isBlocked($email)) {
            $session->set('lstr_flash_error', 'That email address cannot be used to submit an application.');
            $session->set('lstr_flash_old', ['listing_id' => (string) $listingId, 'name' => $name, 'email' => $email, 'url' => $url, 'message' => $message]);
            return Response::redirect($embedReturnTo !== '' ? $embedReturnTo : $fallbackUrl);
        }

        // Duplicate check — one active application per email per listing
        if ($adoptionRepo->hasPendingApplication($listingId, $email)) {
            $session->set('lstr_flash_error', 'An application for this listing from that email address is already pending review.');
            $session->set('lstr_flash_old', ['listing_id' => (string) $listingId, 'name' => $name, 'email' => $email, 'url' => $url, 'message' => $message]);
            return Response::redirect($embedReturnTo !== '' ? $embedReturnTo : $fallbackUrl);
        }

        $adoptionRepo->insert($listingId, [
            'name'    => $name,
            'email'   => $email,
            'url'     => $url,
            'message' => $message !== '' ? $message : null,
        ]);

        $rateLimit->clear($ip, RateLimit::ACTION_PUBLIC_ADOPT);

        // Admin notification email
        $listing = null;
        try {
            $config  = $this->app->getConfig();
            $listing = $listingRepo->getById($listingId);
            $adminTo = (($listing?->email ?? '') !== '') ? $listing->email
                     : (string) $config->get('owner_email', '');
            if ($adminTo !== '') {
                $emailRepo = new EmailTemplateRepo($db);
                $tokens    = EmailTemplateRepo::buildAdoptionTokens(
                    listingName      : $listing?->title ?? '',
                    listingUrl       : $listing?->url   ?? '',
                    siteName         : (string) $config->get('site_name',   ''),
                    ownerName        : (string) $config->get('owner_name',  ''),
                    ownerEmail       : (string) $config->get('owner_email', ''),
                    applicantName    : $name,
                    applicantEmail   : $email,
                    applicantUrl     : $url,
                    applicantMessage : $message,
                    listingSubject   : $listing?->subject ?? '',
                    listingType      : $listing !== null
                        ? (new ListingSettingsRepo($this->app->getDatabase()))->getListingType($listingId)
                        : 'listing',
                );
                [$subject, $body] = $emailRepo->resolveForListing(
                    $listingId,
                    EmailTemplateRepo::TYPE_ADOPTION_APPLIED,
                    $tokens,
                );
                if ($subject !== '') {
                    (new Mailer($config))->send(
                        $adminTo,
                        (string) $config->get('owner_name', ''),
                        $subject,
                        $body,
                    );
                }
            }
        } catch (\Throwable) {
            // Email failures are non-fatal
        }

        $session->remove('lstr_embed_return_to');
        $session->set('lstr_flash_success_html', SuccessRenderer::render('', 'adopt-submitted', [
            'listing_title' => $listing?->title ?? '',
            'listing_url'   => $listing?->url   ?? '',
        ]));
        return Response::redirect($embedReturnTo !== '' ? $embedReturnTo : $fallbackUrl);
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    /**
     * Returns the listing if it exists, is not inactive, and has for_adoption=true.
     * Returns a 404 Response otherwise. Both failure modes return the same generic
     * message so the public route does not leak internal listing state.
     *
     * @return \Luster\Models\Listing|Response
     */
    private function requireAdoptableListing(int $listingId): mixed
    {
        $db          = $this->app->getDatabase();
        $listingRepo = new ListingRepo($db, $this->app->getConfig());
        $listing     = $listingRepo->getById($listingId);

        if ($listing === null || $listing->trouble) {
            return Response::notFound('This listing is not accepting adoption applications.');
        }

        $settingsRepo = new ListingSettingsRepo($db);
        if (!$settingsRepo->getForAdoption($listingId)) {
            return Response::notFound('This listing is not accepting adoption applications.');
        }

        return $listing;
    }

}
