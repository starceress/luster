<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Handles the public contact form for both per-listing and collective contexts.
 On GET, renders the form with name, email, subject selector, message, and
 CAPTCHA fields. On POST, validates all input, checks the blacklist, and sends
 the message to the listing owner (or collective recipient) via Mailer with a
 Reply-To header set to the fan's email. Rate limiting, honeypot, and CAPTCHA
 are enforced on all submissions.
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
use Luster\Repositories\BlacklistRepo;
use Luster\Repositories\ListingRepo;
use Luster\Repositories\ListingSettingsRepo;
use Luster\Repositories\SiteSettingsRepo;
use Luster\Support\CollectiveFormUrlResolver;
use Luster\Support\OwnerNotice;

/**
 * Public contact forms.
 *
 * POST /contact/{id} — process per-listing contact submission
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
    // POST /contact/{id}
    // -----------------------------------------------------------------------

    /**
     * Processes the contact form POST for a specific listing.
     *
     * Runs rate-limit, honeypot, CAPTCHA, blacklist, and validation checks, then
     * delivers the message via Mailer with Reply-To set to the fan's email.
     * Redirects back to the form URL (or embed host page) on both success and error.
     */
    public function listingSubmit(Request $request, array $params): Response
    {
        $listingId     = (int) ($params['id'] ?? 0);
        $baseUrl       = $this->app->getBaseUrl();
        $session       = $this->app->getSession();
        $rateLimit     = $this->app->getRateLimit();
        $ip            = $request->ip();
        $embedReturnTo = (string) $session->get('lstr_embed_return_to', '');
        $marker        = (string) $request->post('_luster_embed_form', '');

        $listingRepo  = new ListingRepo($this->app->getDatabase(), $this->app->getConfig());
        $listing      = $listingRepo->getById($listingId);

        if ($listing === null) {
            return Response::notFound('That listing does not exist.');
        }

        $settingsRepo    = new ListingSettingsRepo($this->app->getDatabase());
        $contactSettings = $settingsRepo->getContactSettings($listingId);
        $globalDefaults  = $settingsRepo->getGlobalFormUrlDefaults();

        $fallbackUrl = \Luster\Support\ListingFormUrlResolver::resolve(
            (string) $contactSettings['form_url_contact'],
            $listing->url,
            $globalDefaults['form_url_contact'],
        );

        if (!$contactSettings['enabled']) {
            return Response::redirect($embedReturnTo !== '' ? $embedReturnTo : $fallbackUrl);
        }

        // Archived listings (Closed/Adopted Out) no longer accept contact messages.
        if ((new \Luster\Repositories\ListingLifecycleRepo($this->app->getDatabase()))->getByListingId($listingId) !== null) {
            $session->set('lstr_flash_error:' . $marker, 'This fanlisting is no longer accepting messages.');
            return Response::redirect($embedReturnTo !== '' ? $embedReturnTo : $fallbackUrl);
        }

        if ($rateLimit->isBlocked($ip, RateLimit::ACTION_PUBLIC_CONTACT)) {
            $session->set('lstr_flash_error:' . $marker, 'Too many messages sent. Please try again later.');
            return Response::redirect($embedReturnTo !== '' ? $embedReturnTo : $fallbackUrl);
        }

        // Honeypot — silent reject; fake success so bots see no difference
        if ((string) $request->post('website_confirm', '') !== '') {
            $session->remove('lstr_embed_return_to');
            $senderName = trim((string) $request->post('name', '')) ?: 'there';
            $session->set('lstr_flash_success_html:' . $marker, self::contactSentHtml($senderName));
            return Response::redirect($embedReturnTo !== '' ? $embedReturnTo : $fallbackUrl);
        }

        // Count every real submission against the rate limit, not just invalid
        // ones - a rejected captcha/validation attempt is still an attempt.
        $rateLimit->hitDefault($ip, RateLimit::ACTION_PUBLIC_CONTACT);

        // Captcha — redirect with flash on failure so the form re-renders with a fresh question
        if (!Captcha::validate((string) $request->post('captcha_answer', ''), 'contact_listing')) {
            $session->set('lstr_flash_error:' . $marker, 'Incorrect answer — please try again.');
            $session->set('lstr_flash_old:' . $marker, $this->collectOld($request));
            return Response::redirect($embedReturnTo !== '' ? $embedReturnTo : $fallbackUrl);
        }

        $name    = trim((string) $request->post('name',    ''));
        $email   = trim((string) $request->post('email',   ''));
        $url     = trim((string) $request->post('url',     ''));
        $subject = trim((string) $request->post('subject', ''));
        $message = trim((string) $request->post('message', ''));

        // Blacklist check on submitted email
        if ($email !== '' && (new BlacklistRepo($this->app->getDatabase()))->isBlocked($email)) {
            $session->set('lstr_flash_error:' . $marker, 'This email address cannot be used to send messages.');
            $session->set('lstr_flash_old:' . $marker, $this->collectOld($request));
            return Response::redirect($embedReturnTo !== '' ? $embedReturnTo : $fallbackUrl);
        }

        $v = new Validator($request->postAll());
        $v->field('name')->required()->maxLength(100);
        $v->field('email')->required()->email()->maxLength(255);
        $v->field('subject')->required()->maxLength(200);
        $v->field('message')->required()->maxLength(5000);

        if ($url !== '' && (filter_var($url, FILTER_VALIDATE_URL) === false || !in_array(parse_url($url, PHP_URL_SCHEME), ['http', 'https'], true))) {
            $session->set('lstr_flash_error:' . $marker, 'Website URL must be a valid http:// or https:// address.');
            $session->set('lstr_flash_old:' . $marker, $this->collectOld($request));
            return Response::redirect($embedReturnTo !== '' ? $embedReturnTo : $fallbackUrl);
        }

        if ($v->fails()) {
            $session->set('lstr_flash_error:' . $marker, implode(' ', $v->allErrors()));
            $session->set('lstr_flash_old:' . $marker, $this->collectOld($request));
            return Response::redirect($embedReturnTo !== '' ? $embedReturnTo : $fallbackUrl);
        }

        // A recipient set just for this form, then wherever this listing's notices go.
        $notice    = OwnerNotice::for($this->app);
        $recipient = trim((string) ($contactSettings['recipient'] ?? ''));
        if ($recipient === '') {
            $recipient = $notice->addressFor($listing);
        }

        if ($recipient === '') {
            $notice->reportNoAddress("contact message for {$listing->title}");
            $session->set('lstr_flash_error:' . $marker, 'No contact email is configured. Please try again later.');
            $session->set('lstr_flash_old:' . $marker, $this->collectOld($request));
            return Response::redirect($embedReturnTo !== '' ? $embedReturnTo : $fallbackUrl);
        }

        $emailSubject = "[{$listing->title}] Contact Form - {$subject}";

        $body = "Name: {$name}\n"
              . "Email: {$email}\n"
              . "Website URL: " . ($url !== '' ? $url : '(not provided)') . "\n"
              . "Subject: {$subject}\n"
              . "Listing: {$listing->title} ({$listing->url})\n\n"
              . "Message:\n{$message}\n\n"
              . str_repeat('-', 50) . "\n"
              . "IP Address: {$ip}";

        try {
            (new Mailer($this->app->getConfig()))->sendWithReplyTo($recipient, $listing->title, $emailSubject, $body, $email, $name);
        } catch (\RuntimeException $e) {
            $notice->reportFailure("contact message for {$listing->title}", $e);
            $session->set('lstr_flash_error:' . $marker, 'Your message could not be sent. Please try again later.');
            $session->set('lstr_flash_old:' . $marker, $this->collectOld($request));
            return Response::redirect($embedReturnTo !== '' ? $embedReturnTo : $fallbackUrl);
        }

        $session->remove('lstr_embed_return_to');
        $session->set('lstr_flash_success_html:' . $marker, self::contactSentHtml($name));
        return Response::redirect($embedReturnTo !== '' ? $embedReturnTo : $fallbackUrl);
    }

    // -----------------------------------------------------------------------
    // POST /contact
    // -----------------------------------------------------------------------

    /**
     * Processes the collective contact form POST.
     *
     * Mirrors listingSubmit() but sends the message to the collective recipient
     * from lstr_site_settings (falling back to `owner_email` in app config).
     */
    public function collectiveSubmit(Request $request, array $params): Response
    {
        $baseUrl       = $this->app->getBaseUrl();
        $config        = $this->app->getConfig();
        $session       = $this->app->getSession();
        $rateLimit     = $this->app->getRateLimit();
        $ip            = $request->ip();
        $embedReturnTo = (string) $session->get('lstr_embed_return_to', '');
        $marker        = (string) $request->post('_luster_embed_form', '');

        $siteRepo      = new SiteSettingsRepo($this->app->getDatabase());
        $siteUrl       = (string) ($config->get('site_url', '') ?: $baseUrl);
        $contactOverride = $siteRepo->get('collective_form_url_contact');
        $fallbackUrl   = CollectiveFormUrlResolver::resolve($contactOverride, $siteUrl, $siteUrl);
        $enabled  = (bool) ((int) ($siteRepo->get('collective_contact_enabled') ?? '1'));

        if (!$enabled) {
            return Response::redirect($embedReturnTo !== '' ? $embedReturnTo : $fallbackUrl);
        }

        if ($rateLimit->isBlocked($ip, RateLimit::ACTION_PUBLIC_CONTACT)) {
            $session->set('lstr_flash_error:' . $marker, 'Too many messages sent. Please try again later.');
            return Response::redirect($embedReturnTo !== '' ? $embedReturnTo : $fallbackUrl);
        }

        // Honeypot — silent reject; fake success so bots see no difference
        if ((string) $request->post('website_confirm', '') !== '') {
            $session->remove('lstr_embed_return_to');
            $senderName = trim((string) $request->post('name', '')) ?: 'there';
            $session->set('lstr_flash_success_html:' . $marker, self::contactSentHtml($senderName));
            return Response::redirect($embedReturnTo !== '' ? $embedReturnTo : $fallbackUrl);
        }

        // Count every real submission against the rate limit, not just invalid
        // ones - a rejected captcha/validation attempt is still an attempt.
        $rateLimit->hitDefault($ip, RateLimit::ACTION_PUBLIC_CONTACT);

        // Captcha — redirect with flash on failure so the form re-renders with a fresh question
        if (!Captcha::validate((string) $request->post('captcha_answer', ''), 'contact_collective')) {
            $session->set('lstr_flash_error:' . $marker, 'Incorrect answer — please try again.');
            $session->set('lstr_flash_old:' . $marker, $this->collectOld($request));
            return Response::redirect($embedReturnTo !== '' ? $embedReturnTo : $fallbackUrl);
        }

        $name    = trim((string) $request->post('name',    ''));
        $email   = trim((string) $request->post('email',   ''));
        $url     = trim((string) $request->post('url',     ''));
        $subject = trim((string) $request->post('subject', ''));
        $message = trim((string) $request->post('message', ''));

        // Blacklist check on submitted email
        if ($email !== '' && (new BlacklistRepo($this->app->getDatabase()))->isBlocked($email)) {
            $session->set('lstr_flash_error:' . $marker, 'This email address cannot be used to send messages.');
            $session->set('lstr_flash_old:' . $marker, $this->collectOld($request));
            return Response::redirect($embedReturnTo !== '' ? $embedReturnTo : $fallbackUrl);
        }

        $v = new Validator($request->postAll());
        $v->field('name')->required()->maxLength(100);
        $v->field('email')->required()->email()->maxLength(255);
        $v->field('subject')->required()->maxLength(200);
        $v->field('message')->required()->maxLength(5000);

        if ($url !== '' && (filter_var($url, FILTER_VALIDATE_URL) === false || !in_array(parse_url($url, PHP_URL_SCHEME), ['http', 'https'], true))) {
            $session->set('lstr_flash_error:' . $marker, 'Website URL must be a valid http:// or https:// address.');
            $session->set('lstr_flash_old:' . $marker, $this->collectOld($request));
            return Response::redirect($embedReturnTo !== '' ? $embedReturnTo : $fallbackUrl);
        }

        if ($v->fails()) {
            $session->set('lstr_flash_error:' . $marker, implode(' ', $v->allErrors()));
            $session->set('lstr_flash_old:' . $marker, $this->collectOld($request));
            return Response::redirect($embedReturnTo !== '' ? $embedReturnTo : $fallbackUrl);
        }

        $siteName  = (string) $config->get('site_name', 'Collective');
        $recipient = (string) ($siteRepo->get('collective_contact_recipient') ?? '');
        if ($recipient === '') {
            $recipient = (string) $config->get('owner_email', '');
        }

        $notice = OwnerNotice::for($this->app);
        if ($recipient === '') {
            $notice->reportNoAddress('collective contact message');
            $session->set('lstr_flash_error:' . $marker, 'No contact email is configured. Please try again later.');
            $session->set('lstr_flash_old:' . $marker, $this->collectOld($request));
            return Response::redirect($embedReturnTo !== '' ? $embedReturnTo : $fallbackUrl);
        }

        $emailSubject = "[{$siteName}] Contact Form - {$subject}";

        $body = "Name: {$name}\n"
              . "Email: {$email}\n"
              . "Website URL: " . ($url !== '' ? $url : '(not provided)') . "\n"
              . "Subject: {$subject}\n\n"
              . "Message:\n{$message}\n\n"
              . str_repeat('-', 50) . "\n"
              . "IP Address: {$ip}";

        try {
            (new Mailer($config))->sendWithReplyTo($recipient, $siteName, $emailSubject, $body, $email, $name);
        } catch (\RuntimeException $e) {
            $notice->reportFailure('collective contact message', $e);
            $session->set('lstr_flash_error:' . $marker, 'Your message could not be sent. Please try again later.');
            $session->set('lstr_flash_old:' . $marker, $this->collectOld($request));
            return Response::redirect($embedReturnTo !== '' ? $embedReturnTo : $fallbackUrl);
        }

        $session->remove('lstr_embed_return_to');
        $session->set('lstr_flash_success_html:' . $marker, self::contactSentHtml($name));
        return Response::redirect($embedReturnTo !== '' ? $embedReturnTo : $fallbackUrl);
    }

    // -----------------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------------

    /**
     * Returns the inline HTML shown to the fan after a message is sent successfully.
     */
    private static function contactSentHtml(string $senderName): string
    {
        $escaped = htmlspecialchars($senderName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        return '<div class="fl-success"><p>Your message has been sent. Thank you for getting in touch, ' . $escaped . '!</p></div>';
    }

    /**
     * Extracts trimmed text fields from the current POST for flash repopulation.
     *
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

}
