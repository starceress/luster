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
use Luster\Support\CollectiveFormUrlResolver;
use Luster\Support\FormTemplateRenderer;
use Luster\Support\ListingTemplateResolver;
use Luster\Support\SuccessRenderer;

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
                'listing_title' => $listing->title,
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
        $listingId     = (int) ($params['id'] ?? 0);
        $baseUrl       = $this->app->getBaseUrl();
        $session       = $this->app->getSession();
        $rateLimit     = $this->app->getRateLimit();
        $ip            = $request->ip();
        $embedReturnTo = (string) $session->get('lstr_embed_return_to', '');

        $listingRepo  = new ListingRepo($this->app->getDatabase(), $this->app->getConfig());
        $listing      = $listingRepo->getById($listingId);

        if ($listing === null) {
            return Response::notFound('That listing does not exist.');
        }

        $settingsRepo    = new ListingSettingsRepo($this->app->getDatabase());
        $contactSettings = $settingsRepo->getContactSettings($listingId);

        $formUrlContact = (string) $contactSettings['form_url_contact'];
        $fallbackUrl    = $formUrlContact !== ''
            ? rtrim((string) $listing->url, '/') . '/' . ltrim($formUrlContact, '/')
            : $baseUrl . '/contact/' . $listingId;

        if (!$contactSettings['enabled']) {
            return Response::redirect($embedReturnTo !== '' ? $embedReturnTo : $fallbackUrl);
        }

        if ($rateLimit->isBlocked($ip, RateLimit::ACTION_PUBLIC_CONTACT)) {
            $session->set('lstr_flash_error', 'Too many messages sent. Please try again later.');
            return Response::redirect($embedReturnTo !== '' ? $embedReturnTo : $fallbackUrl);
        }

        // Honeypot — silent reject; fake success so bots see no difference
        if ((string) $request->post('website_confirm', '') !== '') {
            $session->remove('lstr_embed_return_to');
            $hpPerListing = $settingsRepo->getContactSentTemplate($listingId);
            $hpGlobal     = (new SiteSettingsRepo($this->app->getDatabase()))->get('listing_default_contact_sent_template');
            $hpTpl        = ($hpPerListing !== null && $hpPerListing !== '') ? $hpPerListing
                          : (($hpGlobal !== null && $hpGlobal !== '') ? $hpGlobal : '');
            $session->set('lstr_flash_success_html', SuccessRenderer::render($hpTpl, 'contact-sent', [
                'sender_name' => trim((string) $request->post('name', '')) ?: 'there',
            ]));
            return Response::redirect($embedReturnTo !== '' ? $embedReturnTo : $fallbackUrl);
        }

        // Captcha — redirect with flash on failure so the form re-renders with a fresh question
        if (!Captcha::validate((string) $request->post('captcha_answer', ''), 'contact_listing')) {
            $rateLimit->hitDefault($ip, RateLimit::ACTION_PUBLIC_CONTACT);
            $session->set('lstr_flash_error', 'Incorrect answer — please try again.');
            $session->set('lstr_flash_old', $this->collectOld($request));
            return Response::redirect($embedReturnTo !== '' ? $embedReturnTo : $fallbackUrl);
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
            return Response::redirect($embedReturnTo !== '' ? $embedReturnTo : $fallbackUrl);
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
            return Response::redirect($embedReturnTo !== '' ? $embedReturnTo : $fallbackUrl);
        }

        if ($v->fails()) {
            $rateLimit->hitDefault($ip, RateLimit::ACTION_PUBLIC_CONTACT);
            $session->set('lstr_flash_error', implode(' ', $v->allErrors()));
            $session->set('lstr_flash_old', $this->collectOld($request));
            return Response::redirect($embedReturnTo !== '' ? $embedReturnTo : $fallbackUrl);
        }

        $recipient = $contactSettings['recipient'] ?? '';
        if ($recipient === '') {
            $recipient = $listing->email;
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
        } catch (\RuntimeException) {
            $session->set('lstr_flash_error', 'Your message could not be sent. Please try again later.');
            $session->set('lstr_flash_old', $this->collectOld($request));
            return Response::redirect($embedReturnTo !== '' ? $embedReturnTo : $fallbackUrl);
        }

        $session->remove('lstr_embed_return_to');
        $perListingSentTpl = $settingsRepo->getContactSentTemplate($listingId);
        $globalSentTpl     = (new SiteSettingsRepo($this->app->getDatabase()))
            ->get('listing_default_contact_sent_template');
        $contactSentTpl    = ($perListingSentTpl !== null && $perListingSentTpl !== '')
            ? $perListingSentTpl
            : (($globalSentTpl !== null && $globalSentTpl !== '') ? $globalSentTpl : '');
        $session->set('lstr_flash_success_html', SuccessRenderer::render($contactSentTpl, 'contact-sent', [
            'sender_name'   => $name,
            'sender_email'  => $email,
            'listing_title' => $listing->title,
            'listing_url'   => $listing->url,
            'site_name'     => (string) ($this->app->getConfig()->get('site_name', '') ?: ''),
        ]));
        return Response::redirect($embedReturnTo !== '' ? $embedReturnTo : $fallbackUrl);
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
                'listing_title' => (string) $config->get('site_name', 'Collective'),
                'listing_url'  => (string) ($config->get('site_url', '')),
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
        $baseUrl       = $this->app->getBaseUrl();
        $config        = $this->app->getConfig();
        $session       = $this->app->getSession();
        $rateLimit     = $this->app->getRateLimit();
        $ip            = $request->ip();
        $embedReturnTo = (string) $session->get('lstr_embed_return_to', '');

        $siteRepo      = new SiteSettingsRepo($this->app->getDatabase());
        $siteUrl       = (string) ($config->get('site_url', '') ?: $baseUrl);
        $contactOverride = $siteRepo->get('collective_form_url_contact');
        $fallbackUrl   = CollectiveFormUrlResolver::resolve($contactOverride, $siteUrl, $baseUrl . '/contact');
        $enabled  = (bool) ((int) ($siteRepo->get('collective_contact_enabled') ?? '1'));

        if (!$enabled) {
            return Response::redirect($embedReturnTo !== '' ? $embedReturnTo : $fallbackUrl);
        }

        if ($rateLimit->isBlocked($ip, RateLimit::ACTION_PUBLIC_CONTACT)) {
            $session->set('lstr_flash_error', 'Too many messages sent. Please try again later.');
            return Response::redirect($embedReturnTo !== '' ? $embedReturnTo : $fallbackUrl);
        }

        // Honeypot — silent reject; fake success so bots see no difference
        if ((string) $request->post('website_confirm', '') !== '') {
            $session->remove('lstr_embed_return_to');
            $collectiveContactSentTpl = $siteRepo->get('collective_contact_sent_template') ?? '';
            $session->set('lstr_flash_success_html', SuccessRenderer::render($collectiveContactSentTpl, 'contact-sent', [
                'sender_name' => trim((string) $request->post('name', '')) ?: 'there',
            ]));
            return Response::redirect($embedReturnTo !== '' ? $embedReturnTo : $fallbackUrl);
        }

        // Captcha — redirect with flash on failure so the form re-renders with a fresh question
        if (!Captcha::validate((string) $request->post('captcha_answer', ''), 'contact_collective')) {
            $rateLimit->hitDefault($ip, RateLimit::ACTION_PUBLIC_CONTACT);
            $session->set('lstr_flash_error', 'Incorrect answer — please try again.');
            $session->set('lstr_flash_old', $this->collectOld($request));
            return Response::redirect($embedReturnTo !== '' ? $embedReturnTo : $fallbackUrl);
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
            return Response::redirect($embedReturnTo !== '' ? $embedReturnTo : $fallbackUrl);
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
            return Response::redirect($embedReturnTo !== '' ? $embedReturnTo : $fallbackUrl);
        }

        if ($v->fails()) {
            $rateLimit->hitDefault($ip, RateLimit::ACTION_PUBLIC_CONTACT);
            $session->set('lstr_flash_error', implode(' ', $v->allErrors()));
            $session->set('lstr_flash_old', $this->collectOld($request));
            return Response::redirect($embedReturnTo !== '' ? $embedReturnTo : $fallbackUrl);
        }

        $siteName  = (string) $config->get('site_name', 'Collective');
        $recipient = (string) ($siteRepo->get('collective_contact_recipient') ?? '');
        if ($recipient === '') {
            $recipient = (string) $config->get('owner_email', '');
        }

        if ($recipient === '') {
            $session->set('lstr_flash_error', 'No contact email is configured. Please try again later.');
            $session->set('lstr_flash_old', $this->collectOld($request));
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
        } catch (\RuntimeException) {
            $session->set('lstr_flash_error', 'Your message could not be sent. Please try again later.');
            $session->set('lstr_flash_old', $this->collectOld($request));
            return Response::redirect($embedReturnTo !== '' ? $embedReturnTo : $fallbackUrl);
        }

        $session->remove('lstr_embed_return_to');
        $collectiveContactSentTpl = $siteRepo->get('collective_contact_sent_template') ?? '';
        $session->set('lstr_flash_success_html', SuccessRenderer::render($collectiveContactSentTpl, 'contact-sent', [
            'sender_name'  => $name,
            'sender_email' => $email,
            'site_name'    => $siteName,
        ]));
        return Response::redirect($embedReturnTo !== '' ? $embedReturnTo : $fallbackUrl);
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
        $baseUrl    = $this->app->getBaseUrl();

        $captchaQuestion = Captcha::getQuestion($captchaKey);
        $formAction      = $listing !== null
            ? $baseUrl . '/contact/' . (int) $listing->id
            : $baseUrl . '/contact';

        // Subject select as raw_input
        $subjOld     = (string) ($old['subject'] ?? '');
        $subjectHtml = '<select id="contact-subject" name="subject" required>'
                     . '<option value="" disabled' . ($subjOld === '' ? ' selected' : '') . '>Choose &mdash;</option>';
        foreach (['Comments', 'Contact', 'Questions & Concerns'] as $_subjectOpt) {
            $optEsc       = htmlspecialchars($_subjectOpt, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $subjectHtml .= '<option value="' . $optEsc . '"' . ($subjOld === $_subjectOpt ? ' selected' : '') . '>' . $optEsc . '</option>';
        }
        $subjectHtml .= '</select>';

        $fb          = $this->app->getFormFieldBuilder();
        $descriptors = [
            ['type' => 'text',     'name' => 'name',           'id' => 'contact-name',    'label' => 'Name',        'required' => true,  'value' => (string) ($old['name']    ?? ''), 'attrs' => ['maxlength' => '100', 'autocomplete' => 'name']],
            ['type' => 'email',    'name' => 'email',          'id' => 'contact-email',   'label' => 'Email',       'required' => true,  'value' => (string) ($old['email']   ?? ''), 'attrs' => ['maxlength' => '255', 'autocomplete' => 'email']],
            ['type' => 'url',      'name' => 'url',            'id' => 'contact-url',     'label' => 'Website URL', 'required' => false, 'value' => (string) ($old['url']     ?? ''), 'attrs' => ['maxlength' => '255', 'autocomplete' => 'url']],
            ['type' => 'select',   'name' => 'subject',        'id' => 'contact-subject', 'label' => 'Subject',     'required' => true,  'raw_input' => $subjectHtml],
            ['type' => 'textarea', 'name' => 'message',        'id' => 'contact-message', 'label' => 'Message',     'required' => true,  'value' => (string) ($old['message'] ?? ''), 'attrs' => ['maxlength' => '5000', 'rows' => '6']],
            ['type' => 'text',     'name' => 'captcha_answer', 'id' => 'contact-captcha', 'label' => 'Captcha: ' . $captchaQuestion, 'required' => true, 'error' => $captchaError, 'attrs' => ['maxlength' => '10', 'autocomplete' => 'off']],
        ];
        $f = $fb->build($descriptors, '');

        $formHtml = null;
        if ($listing !== null) {
            $settingsRepo    = new ListingSettingsRepo($this->app->getDatabase());
            $globalTemplates = (new SiteSettingsRepo($this->app->getDatabase()))->getGlobalTemplates();
            $resolved        = ListingTemplateResolver::resolve(
                $settingsRepo->getContactFormTemplate((int) $listing->id),
                $globalTemplates['listing_default_contact_form_template'] ?? null,
                '',
            );
            $contactTpl = $resolved['should_render_template'] ? $resolved['value'] : '';
        } else {
            $siteRepo   = new SiteSettingsRepo($this->app->getDatabase());
            $contactTpl = $siteRepo->get('collective_contact_form_template') ?? '';
        }

        if ($contactTpl !== '') {
            $tokens = [
                '{listing_title}'        => View::e($listing?->title ?? (string) $this->app->getConfig()->get('site_name', 'Collective')),
                '{listing_subject}'      => View::e($listing?->subject ?? (string) $this->app->getConfig()->get('site_name', 'Collective')),
                '{listing_url}'          => View::e($listing?->url   ?? (string) ($this->app->getConfig()->get('site_url', ''))),
                '{field_name}'           => $f['name'],
                '{field_name_input}'     => $f['name_input'],
                '{field_email}'          => $f['email'],
                '{field_email_input}'    => $f['email_input'],
                '{field_url}'            => $f['url'],
                '{field_url_input}'      => $f['url_input'],
                '{field_subject}'        => $f['subject'],
                '{field_subject_input}'  => $f['subject_input'],
                '{field_message}'        => $f['message'],
                '{field_message_input}'  => $f['message_input'],
                '{captcha_block}'        => $f['captcha_answer'],
                '{captcha_block_input}'  => $f['captcha_answer_input'],
                '{captcha_question}'     => View::e($captchaQuestion),
                '{submit_button}'        => '<button type="submit" class="fl-submit">Send Message</button>',
                '{clear_button}'         => '<button type="reset" class="fl-btn-secondary">Clear</button>',
            ];
            $innerHtml = FormTemplateRenderer::expandTokens($contactTpl, $tokens);
            $formHtml  = FormTemplateRenderer::wrapForm($innerHtml, $formAction, $this->app->getCSRF()->token(), false);
        }

        $data = [
            'listing'      => $listing,
            'listing_title' => $listing?->title ?? (string) $this->app->getConfig()->get('site_name', 'Collective'),
            'listing_url'  => $listing?->url   ?? (string) ($this->app->getConfig()->get('site_url', '')),
            'page_title'   => 'Contact',
            'form_action'  => $formAction,
            'csrf_token'   => $this->app->getCSRF()->token(),
            'errors'       => $flashError !== '' ? '<p>' . htmlspecialchars($flashError, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>' : '',
            'f'            => $f,
            'form_html'    => $formHtml,
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

        $themePath = rtrim($baseUrl, '/') . '/assets/css/default/';

        return Response::html($view->renderPublic($template, array_merge($data, [
            'base_url'   => $baseUrl,
            'theme_path' => $themePath,
        ])));
    }
}
