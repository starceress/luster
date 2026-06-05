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
use Luster\Core\View;
use Luster\Repositories\BlacklistRepo;
use Luster\Repositories\ListingRepo;
use Luster\Repositories\ListingSettingsRepo;
use Luster\Repositories\SiteSettingsRepo;

/**
 * Public contact forms.
 *
 * GET  /contact/{id} — per-listing contact form
 * POST /contact/{id} — process per-listing contact submission
 * GET  /contact      — collective contact form
 * POST /contact      — process collective contact submission
 *
 * Security properties:
 *  - CSRF token validated by CsrfMiddleware before any action runs
 *  - Arithmetic CAPTCHA prevents automated submissions
 *  - Rate-limited per IP (5 per hour) via lstr_rate_limits
 *  - Reply-To set to the fan's submitted email for easy admin replies
 */
final class ContactController
{
    public function __construct(private readonly Application $app) {}

    // -----------------------------------------------------------------------
    // GET /contact/{id}
    // -----------------------------------------------------------------------

    public function listingForm(Request $request, array $params): Response
    {
        $listingId = (int) ($params['id'] ?? 0);

        $listingRepo  = new ListingRepo($this->app->getDatabase(), $this->app->getConfig());
        $listing      = $listingRepo->getById($listingId);

        if ($listing === null) {
            return Response::notFound('That listing does not exist.');
        }

        $settingsRepo    = new ListingSettingsRepo($this->app->getDatabase());
        $contactSettings = $settingsRepo->getContactSettings($listingId);

        if (!$contactSettings['enabled']) {
            return $this->renderPublic('error.php', [
                'listing'      => $listing,
                'listing_name' => $listing->title,
                'listing_url'  => $listing->url,
                'page_title'   => 'Contact',
                'error'        => 'The contact form for this listing is currently disabled.',
            ]);
        }

        $session = $this->app->getSession();
        $error   = (string) $session->get('lstr_flash_error', '');
        $old     = (array)  $session->get('lstr_flash_old',   []);
        $session->remove('lstr_flash_error');
        $session->remove('lstr_flash_old');

        return $this->renderContactForm($listing, $old, $error);
    }

    // -----------------------------------------------------------------------
    // POST /contact/{id}
    // -----------------------------------------------------------------------

    public function listingSubmit(Request $request, array $params): Response
    {
        $listingId = (int) ($params['id'] ?? 0);
        $baseUrl   = $this->app->getBaseUrl();
        $session   = $this->app->getSession();
        $rateLimit = $this->app->getRateLimit();
        $ip        = $request->ip();

        $listingRepo  = new ListingRepo($this->app->getDatabase(), $this->app->getConfig());
        $listing      = $listingRepo->getById($listingId);

        if ($listing === null) {
            return Response::notFound('That listing does not exist.');
        }

        $settingsRepo    = new ListingSettingsRepo($this->app->getDatabase());
        $contactSettings = $settingsRepo->getContactSettings($listingId);

        if (!$contactSettings['enabled']) {
            return Response::redirect($baseUrl . '/contact/' . $listingId);
        }

        if ($rateLimit->isBlocked($ip, RateLimit::ACTION_PUBLIC_CONTACT)) {
            $session->set('lstr_flash_error', 'Too many messages sent. Please try again later.');
            return Response::redirect($baseUrl . '/contact/' . $listingId);
        }

        // Honeypot — silent reject
        if ((string) $request->post('website_confirm', '') !== '') {
            return Response::redirect($baseUrl . '/contact/' . $listingId . '/sent');
        }

        if (!Captcha::validate((string) $request->post('captcha_answer', ''), 'contact_listing')) {
            $old = $this->collectOld($request);
            return $this->renderContactForm($listing, $old, '', 'Incorrect answer — please try again.');
        }

        $name    = trim((string) $request->post('name',    ''));
        $email   = trim((string) $request->post('email',   ''));
        $url     = trim((string) $request->post('url',     ''));
        $subject = trim((string) $request->post('subject', ''));
        $message = trim((string) $request->post('message', ''));

        // Blacklist check on submitted email
        if ($email !== '' && (new BlacklistRepo($this->app->getDatabase()))->isBlocked($email)) {
            $rateLimit->hitDefault($ip, RateLimit::ACTION_PUBLIC_CONTACT);
            $session->set('lstr_flash_error', 'This email address cannot be used to send messages.');
            $session->set('lstr_flash_old', $this->collectOld($request));
            return Response::redirect($baseUrl . '/contact/' . $listingId);
        }

        $v = new Validator($request->postAll());
        $v->field('name')->required()->maxLength(100);
        $v->field('email')->required()->email()->maxLength(255);
        $v->field('subject')->required()->maxLength(200);
        $v->field('message')->required()->maxLength(5000);

        if ($url !== '' && (filter_var($url, FILTER_VALIDATE_URL) === false || !in_array(parse_url($url, PHP_URL_SCHEME), ['http', 'https'], true))) {
            $rateLimit->hitDefault($ip, RateLimit::ACTION_PUBLIC_CONTACT);
            $session->set('lstr_flash_error', 'Website URL must be a valid http:// or https:// address.');
            $session->set('lstr_flash_old', $this->collectOld($request));
            return Response::redirect($baseUrl . '/contact/' . $listingId);
        }

        if ($v->fails()) {
            $rateLimit->hitDefault($ip, RateLimit::ACTION_PUBLIC_CONTACT);
            $session->set('lstr_flash_error', implode(' ', $v->allErrors()));
            $session->set('lstr_flash_old', $this->collectOld($request));
            return Response::redirect($baseUrl . '/contact/' . $listingId);
        }

        $recipient = $contactSettings['recipient'] ?? '';
        if ($recipient === '') {
            $recipient = $listing->email;
        }

        $body = "Message from: {$name} <{$email}>"
              . ($url !== '' ? "\nWebsite: {$url}" : '') . "\n"
              . "Listing: {$listing->title} ({$listing->url})\n"
              . str_repeat('-', 50) . "\n\n"
              . $message;

        try {
            (new Mailer($this->app->getConfig()))->sendWithReplyTo($recipient, $listing->title, $subject, $body, $email, $name);
        } catch (\RuntimeException) {
            $session->set('lstr_flash_error', 'Your message could not be sent. Please try again later.');
            $session->set('lstr_flash_old', $this->collectOld($request));
            return Response::redirect($baseUrl . '/contact/' . $listingId);
        }

        return Response::redirect($baseUrl . '/contact/' . $listingId . '/sent');
    }

    // -----------------------------------------------------------------------
    // GET /contact/{id}/sent
    // -----------------------------------------------------------------------

    public function listingSent(Request $request, array $params): Response
    {
        $listingId   = (int) ($params['id'] ?? 0);
        $listingRepo = new ListingRepo($this->app->getDatabase(), $this->app->getConfig());
        $listing     = $listingRepo->getById($listingId);

        if ($listing === null) {
            return Response::notFound('That listing does not exist.');
        }

        return $this->renderPublic('contact-sent.php', [
            'listing'      => $listing,
            'listing_name' => $listing->title,
            'listing_url'  => $listing->url,
            'page_title'   => 'Message Sent',
        ]);
    }

    // -----------------------------------------------------------------------
    // GET /contact
    // -----------------------------------------------------------------------

    public function collectiveForm(Request $request, array $params): Response
    {
        $config   = $this->app->getConfig();
        $siteRepo = new SiteSettingsRepo($this->app->getDatabase());
        $enabled  = (bool) ((int) ($siteRepo->get('collective_contact_enabled') ?? '1'));

        if (!$enabled) {
            return $this->renderPublic('error.php', [
                'listing'      => null,
                'listing_name' => (string) $config->get('app_name', 'Collective'),
                'listing_url'  => (string) $config->get('app_url',  ''),
                'page_title'   => 'Contact',
                'error'        => 'The collective contact form is currently disabled.',
            ]);
        }

        $session = $this->app->getSession();
        $error   = (string) $session->get('lstr_flash_error', '');
        $old     = (array)  $session->get('lstr_flash_old',   []);
        $session->remove('lstr_flash_error');
        $session->remove('lstr_flash_old');

        return $this->renderContactForm(null, $old, $error);
    }

    // -----------------------------------------------------------------------
    // POST /contact
    // -----------------------------------------------------------------------

    public function collectiveSubmit(Request $request, array $params): Response
    {
        $baseUrl   = $this->app->getBaseUrl();
        $config    = $this->app->getConfig();
        $session   = $this->app->getSession();
        $rateLimit = $this->app->getRateLimit();
        $ip        = $request->ip();

        $siteRepo = new SiteSettingsRepo($this->app->getDatabase());
        $enabled  = (bool) ((int) ($siteRepo->get('collective_contact_enabled') ?? '1'));

        if (!$enabled) {
            return Response::redirect($baseUrl . '/contact');
        }

        if ($rateLimit->isBlocked($ip, RateLimit::ACTION_PUBLIC_CONTACT)) {
            $session->set('lstr_flash_error', 'Too many messages sent. Please try again later.');
            return Response::redirect($baseUrl . '/contact');
        }

        // Honeypot — silent reject
        if ((string) $request->post('website_confirm', '') !== '') {
            return Response::redirect($baseUrl . '/contact/sent');
        }

        if (!Captcha::validate((string) $request->post('captcha_answer', ''), 'contact_collective')) {
            $old = $this->collectOld($request);
            return $this->renderContactForm(null, $old, '', 'Incorrect answer — please try again.');
        }

        $name    = trim((string) $request->post('name',    ''));
        $email   = trim((string) $request->post('email',   ''));
        $url     = trim((string) $request->post('url',     ''));
        $subject = trim((string) $request->post('subject', ''));
        $message = trim((string) $request->post('message', ''));

        // Blacklist check on submitted email
        if ($email !== '' && (new BlacklistRepo($this->app->getDatabase()))->isBlocked($email)) {
            $rateLimit->hitDefault($ip, RateLimit::ACTION_PUBLIC_CONTACT);
            $session->set('lstr_flash_error', 'This email address cannot be used to send messages.');
            $session->set('lstr_flash_old', $this->collectOld($request));
            return Response::redirect($baseUrl . '/contact');
        }

        $v = new Validator($request->postAll());
        $v->field('name')->required()->maxLength(100);
        $v->field('email')->required()->email()->maxLength(255);
        $v->field('subject')->required()->maxLength(200);
        $v->field('message')->required()->maxLength(5000);

        if ($url !== '' && (filter_var($url, FILTER_VALIDATE_URL) === false || !in_array(parse_url($url, PHP_URL_SCHEME), ['http', 'https'], true))) {
            $rateLimit->hitDefault($ip, RateLimit::ACTION_PUBLIC_CONTACT);
            $session->set('lstr_flash_error', 'Website URL must be a valid http:// or https:// address.');
            $session->set('lstr_flash_old', $this->collectOld($request));
            return Response::redirect($baseUrl . '/contact');
        }

        if ($v->fails()) {
            $rateLimit->hitDefault($ip, RateLimit::ACTION_PUBLIC_CONTACT);
            $session->set('lstr_flash_error', implode(' ', $v->allErrors()));
            $session->set('lstr_flash_old', $this->collectOld($request));
            return Response::redirect($baseUrl . '/contact');
        }

        $siteName  = (string) $config->get('app_name', 'Collective');
        $recipient = (string) ($siteRepo->get('collective_contact_recipient') ?? '');
        if ($recipient === '') {
            $recipient = (string) $config->get('owner_email', '');
        }

        if ($recipient === '') {
            $session->set('lstr_flash_error', 'No contact email is configured. Please try again later.');
            $session->set('lstr_flash_old', $this->collectOld($request));
            return Response::redirect($baseUrl . '/contact');
        }

        $body = "Message from: {$name} <{$email}>"
              . ($url !== '' ? "\nWebsite: {$url}" : '') . "\n"
              . "Site: {$siteName}\n"
              . str_repeat('-', 50) . "\n\n"
              . $message;

        try {
            (new Mailer($config))->sendWithReplyTo($recipient, $siteName, $subject, $body, $email, $name);
        } catch (\RuntimeException) {
            $session->set('lstr_flash_error', 'Your message could not be sent. Please try again later.');
            $session->set('lstr_flash_old', $this->collectOld($request));
            return Response::redirect($baseUrl . '/contact');
        }

        return Response::redirect($baseUrl . '/contact/sent');
    }

    // -----------------------------------------------------------------------
    // GET /contact/sent
    // -----------------------------------------------------------------------

    public function collectiveSent(Request $request, array $params): Response
    {
        $config = $this->app->getConfig();

        return $this->renderPublic('contact-sent.php', [
            'listing'      => null,
            'listing_name' => (string) $config->get('app_name', 'Collective'),
            'listing_url'  => (string) $config->get('app_url',  ''),
            'page_title'   => 'Message Sent',
        ]);
    }

    // -----------------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------------

    /**
     * @param \Luster\Models\Listing|null $listing  null = collective form
     */
    private function renderContactForm(
        mixed  $listing,
        array  $old,
        string $flashError,
        string $captchaError = '',
    ): Response {
        $captchaKey = $listing !== null ? 'contact_listing' : 'contact_collective';

        $data = [
            'listing'        => $listing,
            'listing_name'   => $listing?->title ?? (string) $this->app->getConfig()->get('app_name', 'Collective'),
            'listing_url'    => $listing?->url  ?? (string) $this->app->getConfig()->get('app_url',  ''),
            'page_title'     => 'Contact',
            'form_action'    => $listing !== null
                ? $this->app->getBaseUrl() . '/contact/' . (int) $listing->id
                : $this->app->getBaseUrl() . '/contact',
            'csrf_token'     => $this->app->getCSRF()->token(),
            'errors'         => $flashError !== '' ? View::e($flashError) : '',
            'captcha_question' => Captcha::getQuestion($captchaKey),
            'captcha_error'  => $captchaError !== '' ? View::e($captchaError) : '',
            'old'            => $old,
            'field_name'     => View::e($old['name']    ?? ''),
            'field_email'    => View::e($old['email']   ?? ''),
            'field_url'      => View::e($old['url']     ?? ''),
            'field_subject'  => View::e($old['subject'] ?? ''),
            'field_message'  => View::e($old['message'] ?? ''),
        ];

        return $this->renderPublic('contact.php', $data);
    }

    /**
     * @return array<string,string>
     */
    private function collectOld(Request $request): array
    {
        return [
            'name'    => trim((string) $request->post('name',    '')),
            'email'   => trim((string) $request->post('email',   '')),
            'url'     => trim((string) $request->post('url',     '')),
            'subject' => trim((string) $request->post('subject', '')),
            'message' => trim((string) $request->post('message', '')),
        ];
    }

    private function renderPublic(string $template, array $data): Response
    {
        $view    = $this->app->getView();
        $config  = $this->app->getConfig();
        $baseUrl = $this->app->getBaseUrl();

        $themePath = rtrim($baseUrl, '/') . '/assets/default';

        return Response::html($view->renderPublic($template, array_merge($data, [
            'base_url'   => $baseUrl,
            'theme_path' => $themePath,
        ])));
    }
}
