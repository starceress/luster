<?php

declare(strict_types=1);

namespace Luster\Addons\Adoption\Controllers;

use Luster\Addons\Adoption\AdoptionRepo;
use Luster\Core\Application;
use Luster\Core\Captcha;
use Luster\Core\RateLimit;
use Luster\Core\Request;
use Luster\Core\Response;
use Luster\Core\Validator;
use Luster\Repositories\BlacklistRepo;
use Luster\Repositories\ListingRepo;
use Luster\Repositories\ListingSettingsRepo;

/**
 * Public adoption application form.
 *
 * Routes (registered by AdoptionAddon::registerRoutes()):
 *   GET  /adopt/{id}      — renders the application form.
 *   POST /adopt/{id}      — validates input, inserts application, redirects to /sent.
 *   GET  /adopt/{id}/sent — confirmation page.
 *
 * Only available when the listing has for_adoption=1 in lstr_fanlisting_settings
 * and the listing is not inactive.
 */
final class AdoptPublicController
{
    public function __construct(private readonly Application $app) {}

    // -----------------------------------------------------------------------
    // GET /adopt/{id}
    // -----------------------------------------------------------------------

    public function showForm(Request $request, array $params): Response
    {
        $listingId = (int) ($params['id'] ?? 0);
        $listing   = $this->requireAdoptableListing($listingId);

        if ($listing instanceof Response) {
            return $listing;
        }

        $session = $this->app->getSession();
        $error   = (string) $session->get('lstr_flash_error', '');
        $old     = (array)  $session->get('lstr_flash_old',   []);
        $session->remove('lstr_flash_error');
        $session->remove('lstr_flash_old');

        return $this->renderForm($listingId, $listing, $old, $error);
    }

    // -----------------------------------------------------------------------
    // POST /adopt/{id}
    // -----------------------------------------------------------------------

    public function submit(Request $request, array $params): Response
    {
        $listingId = (int) ($params['id'] ?? 0);
        $baseUrl   = $this->app->getBaseUrl();
        $session   = $this->app->getSession();
        $ip        = $request->ip();

        $listing = $this->requireAdoptableListing($listingId);
        if ($listing instanceof Response) {
            return $listing;
        }

        // Rate limiting
        $rateLimit = $this->app->getRateLimit();
        if ($rateLimit->isBlocked($ip, RateLimit::ACTION_PUBLIC_ADOPT)) {
            $session->set('lstr_flash_error', 'Too many requests. Please try again later.');
            return Response::redirect($baseUrl . '/adopt/' . $listingId);
        }

        // Honeypot
        if ((string) $request->post('website_confirm', '') !== '') {
            return Response::redirect($baseUrl . '/adopt/' . $listingId . '/sent');
        }

        // Captcha
        if (!Captcha::validate((string) $request->post('captcha_answer', ''), 'adopt')) {
            $old = [
                'name'    => trim((string) $request->post('name',    '')),
                'email'   => trim((string) $request->post('email',   '')),
                'url'     => trim((string) $request->post('url',     '')),
                'message' => trim((string) $request->post('message', '')),
            ];
            return $this->renderForm($listingId, $listing, $old, '', 'Incorrect answer — please try again.');
        }

        $name    = trim((string) $request->post('name',    ''));
        $email   = trim((string) $request->post('email',   ''));
        $url     = trim((string) $request->post('url',     ''));
        $message = trim((string) $request->post('message', ''));

        $v = new Validator(['name' => $name, 'email' => $email, 'url' => $url, 'message' => $message]);
        $v->field('name')->required()->maxLength(100);
        $v->field('email')->required()->email()->maxLength(255);
        $v->field('url')->url()->maxLength(500);
        $v->field('message')->maxLength(2000);

        if ($v->fails()) {
            $rateLimit->hitDefault($ip, RateLimit::ACTION_PUBLIC_ADOPT);
            $session->set('lstr_flash_error', implode(' ', $v->allErrors()));
            $session->set('lstr_flash_old', [
                'name'    => $name,
                'email'   => $email,
                'url'     => $url,
                'message' => $message,
            ]);
            return Response::redirect($baseUrl . '/adopt/' . $listingId);
        }

        $db           = $this->app->getDatabase();
        $adoptionRepo = new AdoptionRepo($db);

        // Blacklist check
        if ((new BlacklistRepo($db))->isBlocked($email)) {
            $session->set('lstr_flash_error', 'That email address cannot be used to submit an application.');
            $session->set('lstr_flash_old', [
                'name'    => $name,
                'email'   => $email,
                'url'     => $url,
                'message' => $message,
            ]);
            return Response::redirect($baseUrl . '/adopt/' . $listingId);
        }

        // Duplicate check — one active application per email per listing
        if ($adoptionRepo->hasPendingApplication($listingId, $email)) {
            $session->set('lstr_flash_error', 'An application for this listing from that email address is already pending review.');
            $session->set('lstr_flash_old', [
                'name'    => $name,
                'email'   => $email,
                'url'     => $url,
                'message' => $message,
            ]);
            return Response::redirect($baseUrl . '/adopt/' . $listingId);
        }

        $adoptionRepo->insert($listingId, [
            'name'    => $name,
            'email'   => $email,
            'url'     => $url,
            'message' => $message !== '' ? $message : null,
        ]);

        $rateLimit->clear($ip, RateLimit::ACTION_PUBLIC_ADOPT);

        return Response::redirect($baseUrl . '/adopt/' . $listingId . '/sent');
    }

    // -----------------------------------------------------------------------
    // GET /adopt/{id}/sent
    // -----------------------------------------------------------------------

    public function sent(Request $request, array $params): Response
    {
        $listingId   = (int) ($params['id'] ?? 0);
        $listingRepo = new ListingRepo($this->app->getDatabase(), $this->app->getConfig());
        $listing     = $listingRepo->getById($listingId);
        $baseUrl     = $this->app->getBaseUrl();

        $html = $this->app->getView()->renderPublic('success.php', [
            'listing'         => $listing,
            'listing_name'    => $listing?->name ?? 'this listing',
            'listing_url'     => $listing?->url  ?? '',
            'page_title'      => 'Application Received',
            'success_heading' => 'Application Received',
            'success_message' => 'Thank you for your interest in adopting this fanlisting. Your application has been received.',
            'base_url'        => $baseUrl,
            'theme_path'      => $baseUrl . '/assets/default/',
        ]);

        return Response::html($html);
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

    /**
     * @param array<string,string> $old
     */
    private function renderForm(
        int $listingId,
        \Luster\Models\Listing $listing,
        array $old = [],
        string $error = '',
        string $captchaError = '',
    ): Response {
        $baseUrl = $this->app->getBaseUrl();

        $html = $this->app->getView()->renderPublic('adopt.php', [
            'listing'          => $listing,
            'listing_name'     => $listing->name,
            'listing_url'      => $listing->url,
            'page_title'       => 'Adopt ' . $listing->name,
            'form_action'      => $baseUrl . '/adopt/' . $listingId,
            'csrf_token'       => $this->app->getCSRF()->token(),
            'errors'           => $error !== ''
                ? '<p>' . htmlspecialchars($error, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>'
                : '',
            'field_name'       => (string) ($old['name']    ?? ''),
            'field_email'      => (string) ($old['email']   ?? ''),
            'field_url'        => (string) ($old['url']     ?? ''),
            'field_message'    => (string) ($old['message'] ?? ''),
            'captcha_question' => Captcha::getQuestion('adopt'),
            'captcha_error'    => $captchaError,
            'base_url'         => $baseUrl,
            'theme_path'       => $baseUrl . '/assets/default/',
        ]);

        return Response::html($html);
    }
}
