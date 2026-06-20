<?php

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
use Luster\Core\View;
use Luster\Repositories\BlacklistRepo;
use Luster\Repositories\EmailTemplateRepo;
use Luster\Repositories\ListingRepo;
use Luster\Repositories\ListingSettingsRepo;
use Luster\Repositories\SiteSettingsRepo;
use Luster\Support\CollectiveFormUrlResolver;
use Luster\Support\FormTemplateRenderer;
use Luster\Support\SuccessRenderer;

/**
 * Public adoption application form.
 *
 * Routes (registered by AdoptionAddon::registerRoutes()):
 *   GET  /adopt/{id}  — renders the application form.
 *   POST /adopt/{id}  — validates input, inserts application, redirects to host page.
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

        $session     = $this->app->getSession();
        $successHtml = (string) $session->get('lstr_flash_success_html', '');
        $error       = (string) $session->get('lstr_flash_error', '');
        $old         = (array)  $session->get('lstr_flash_old',   []);
        $session->remove('lstr_flash_success_html');
        $session->remove('lstr_flash_error');
        $session->remove('lstr_flash_old');

        return $this->renderForm($listingId, $listing, $old, $error, successHtml: $successHtml);
    }

    // -----------------------------------------------------------------------
    // POST /adopt/{id}
    // -----------------------------------------------------------------------

    public function submit(Request $request, array $params): Response
    {
        $listingId     = (int) ($params['id'] ?? 0);
        $baseUrl       = $this->app->getBaseUrl();
        $session       = $this->app->getSession();
        $ip            = $request->ip();
        $embedReturnTo = (string) $session->get('lstr_embed_return_to', '');
        $fallbackUrl   = $baseUrl . '/adopt/' . $listingId;

        $listing = $this->requireAdoptableListing($listingId);
        if ($listing instanceof Response) {
            return $listing;
        }

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
                );
                [$subject, $body] = $emailRepo->resolveForListing(
                    $listingId,
                    EmailTemplateRepo::TYPE_ADOPTION_APPLIED,
                    $tokens,
                );
                (new Mailer($config))->send(
                    $adminTo,
                    (string) $config->get('owner_name', ''),
                    $subject,
                    $body,
                );
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
    // GET /adopt-collective
    // -----------------------------------------------------------------------

    public function collectiveForm(Request $request, array $params): Response
    {
        $session     = $this->app->getSession();
        $successHtml = (string) $session->get('lstr_flash_success_html', '');
        $error       = (string) $session->get('lstr_flash_error', '');
        $old         = (array)  $session->get('lstr_flash_old',   []);
        $session->remove('lstr_flash_success_html');
        $session->remove('lstr_flash_error');
        $session->remove('lstr_flash_old');

        if ($successHtml !== '') {
            $baseUrl = $this->app->getBaseUrl();
            $html    = $this->app->getView()->renderPublic('adopt.php', [
                'listing'      => null,
                'listing_title' => (string) $this->app->getConfig()->get('site_name', ''),
                'listing_url'  => $baseUrl,
                'page_title'   => 'Adopt a Listing',
                'success_html' => $successHtml,
                'base_url'     => $baseUrl,
                'theme_path'   => $baseUrl . '/assets/css/default/',
                'form_action'  => '',
                'csrf_token'   => '',
                'errors'       => '',
                'f'            => [],
            ]);
            return Response::html($html);
        }

        return $this->renderCollectiveForm($old, $error);
    }

    // -----------------------------------------------------------------------
    // POST /adopt-collective
    // -----------------------------------------------------------------------

    public function collectiveSubmit(Request $request, array $params): Response
    {
        $baseUrl       = $this->app->getBaseUrl();
        $config        = $this->app->getConfig();
        $session       = $this->app->getSession();
        $ip            = $request->ip();
        $embedReturnTo = (string) $session->get('lstr_embed_return_to', '');
        $siteUrl       = (string) ($config->get('site_url', '') ?: $baseUrl);
        $adoptOverride = (new SiteSettingsRepo($this->app->getDatabase()))->get('collective_form_url_adopt');
        $fallbackUrl   = CollectiveFormUrlResolver::resolve($adoptOverride, $siteUrl, $baseUrl . '/adopt-collective');

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
                );
                [$subject, $body] = $emailRepo->resolveForListing(
                    $listingId,
                    EmailTemplateRepo::TYPE_ADOPTION_APPLIED,
                    $tokens,
                );
                (new Mailer($config))->send(
                    $adminTo,
                    (string) $config->get('owner_name', ''),
                    $subject,
                    $body,
                );
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

    /**
     * @param array<string,string> $old
     */
    private function renderForm(
        int $listingId,
        \Luster\Models\Listing $listing,
        array $old = [],
        string $error = '',
        string $captchaError = '',
        string $successHtml = '',
    ): Response {
        $baseUrl = $this->app->getBaseUrl();

        if ($successHtml !== '') {
            $html = $this->app->getView()->renderPublic('adopt.php', [
                'listing'      => $listing,
                'listing_title' => $listing->title,
                'listing_url'  => $listing->url,
                'page_title'   => 'Adopt ' . $listing->title,
                'success_html' => $successHtml,
                'base_url'     => $baseUrl,
                'theme_path'   => $baseUrl . '/assets/css/default/',
                'form_action'  => '',
                'csrf_token'   => '',
                'errors'       => '',
                'f'            => [],
            ]);
            return Response::html($html);
        }

        $captchaQuestion = Captcha::getQuestion('adopt');
        $settingsRepo    = new ListingSettingsRepo($this->app->getDatabase());
        $wrapperTpl      = $settingsRepo->getWrapperTemplate($listingId) ?? '';

        $f = $this->app->getFormFieldBuilder()->build([
            ['type' => 'text',     'name' => 'name',           'id' => 'adopt-name',    'label' => 'Name',    'required' => true,  'value' => (string) ($old['name']    ?? ''), 'attrs' => ['maxlength' => '100', 'autocomplete' => 'name']],
            ['type' => 'email',    'name' => 'email',          'id' => 'adopt-email',   'label' => 'Email',   'required' => true,  'value' => (string) ($old['email']   ?? ''), 'attrs' => ['maxlength' => '255', 'autocomplete' => 'email']],
            ['type' => 'url',      'name' => 'url',            'id' => 'adopt-url',     'label' => 'Website URL', 'required' => false, 'value' => (string) ($old['url']  ?? ''), 'attrs' => ['maxlength' => '500', 'placeholder' => 'https://', 'autocomplete' => 'url'], 'hint' => 'Optional'],
            ['type' => 'textarea', 'name' => 'message',        'id' => 'adopt-message', 'label' => 'Message', 'required' => false, 'value' => (string) ($old['message'] ?? ''), 'attrs' => ['maxlength' => '2000', 'rows' => '5'], 'hint' => 'Optional'],
            ['type' => 'text',     'name' => 'captcha_answer', 'id' => 'adopt-captcha', 'label' => 'Captcha: ' . $captchaQuestion, 'required' => true, 'error' => $captchaError, 'attrs' => ['maxlength' => '10', 'autocomplete' => 'off']],
        ], $wrapperTpl);

        $formHtml = null;

        $html = $this->app->getView()->renderPublic('adopt.php', [
            'listing'             => $listing,
            'listing_title'        => $listing->title,
            'listing_url'         => $listing->url,
            'page_title'          => 'Adopt ' . $listing->title,
            'form_action'         => $baseUrl . '/adopt/' . $listingId,
            'csrf_token'          => $this->app->getCSRF()->token(),
            'errors'              => $error !== ''
                ? '<p>' . htmlspecialchars($error, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>'
                : '',
            'f'                   => $f,
            'form_html'           => $formHtml,
            'base_url'            => $baseUrl,
            'theme_path'          => $baseUrl . '/assets/css/default/',
        ]);

        return Response::html($html);
    }

    /**
     * @param array<string,string> $old
     */
    private function renderCollectiveForm(array $old = [], string $error = ''): Response
    {
        $baseUrl      = $this->app->getBaseUrl();
        $config       = $this->app->getConfig();
        $db           = $this->app->getDatabase();
        $siteName     = (string) $config->get('site_name', '');
        $siteUrl      = (string) ($config->get('site_url', '') ?: $baseUrl);
        $formAction   = $baseUrl . '/adopt-collective';

        $settingsRepo = new ListingSettingsRepo($db);
        $adoptable    = $settingsRepo->getAdoptableListings(new ListingRepo($db, $config));

        $captchaQuestion = Captcha::getQuestion('adopt');
        $descriptors     = [];

        if ($adoptable !== []) {
            $oldListingId   = (string) ($old['listing_id'] ?? '');
            $listingOptions = '<option value="">&#8212; Select a Listing &#8212;</option>';
            foreach ($adoptable as $row) {
                $value           = (string) $row['id'];
                $selected        = $value === $oldListingId ? ' selected' : '';
                $listingOptions .= '<option value="' . View::e($value) . '"' . $selected . '>' . View::e($row['title']) . '</option>';
            }
            $listingSelectHtml = '<select class="fl-select" id="adopt-listing" name="listing_id" required>' . $listingOptions . '</select>';
            $descriptors[]     = ['type' => 'select', 'name' => 'listing_id', 'id' => 'adopt-listing', 'label' => 'Listing', 'required' => true, 'raw_input' => $listingSelectHtml];
        }

        $descriptors = array_merge($descriptors, [
            ['type' => 'text',     'name' => 'name',           'id' => 'adopt-name',    'label' => 'Name',        'required' => true,  'value' => (string) ($old['name']    ?? ''), 'attrs' => ['maxlength' => '100', 'autocomplete' => 'name']],
            ['type' => 'email',    'name' => 'email',          'id' => 'adopt-email',   'label' => 'Email',       'required' => true,  'value' => (string) ($old['email']   ?? ''), 'attrs' => ['maxlength' => '255', 'autocomplete' => 'email']],
            ['type' => 'url',      'name' => 'url',            'id' => 'adopt-url',     'label' => 'Website URL', 'required' => false, 'value' => (string) ($old['url']    ?? ''), 'attrs' => ['maxlength' => '500', 'placeholder' => 'https://', 'autocomplete' => 'url'], 'hint' => 'Optional'],
            ['type' => 'textarea', 'name' => 'message',        'id' => 'adopt-message', 'label' => 'Message',     'required' => false, 'value' => (string) ($old['message'] ?? ''), 'attrs' => ['maxlength' => '2000', 'rows' => '5'], 'hint' => 'Optional'],
            ['type' => 'text',     'name' => 'captcha_answer', 'id' => 'adopt-captcha', 'label' => 'Captcha: ' . $captchaQuestion, 'required' => true, 'attrs' => ['maxlength' => '10', 'autocomplete' => 'off']],
        ]);

        $f = $this->app->getFormFieldBuilder()->build($descriptors, '');

        $tpl = (new SiteSettingsRepo($db))->get('collective_adoption_form_template')
             ?: SiteSettingsRepo::defaultCollectiveAdoptionFormTemplate();

        if ($adoptable !== []) {
            $fieldListingId      = $f['listing_id'];
            $fieldListingIdInput = $f['listing_id_input'];
            $submitButton        = '<button type="submit" class="fl-submit">Submit Application</button>';
        } else {
            $fieldListingId      = '<p>No listings are currently available for adoption.</p>';
            $fieldListingIdInput = '';
            $submitButton        = '';
        }

        $tokens = [
            '{site_name}'              => View::e($siteName),
            '{field_listing_id}'       => $fieldListingId,
            '{field_listing_id_input}' => $fieldListingIdInput,
            '{field_name}'             => $f['name'],
            '{field_name_input}'       => $f['name_input'],
            '{field_email}'            => $f['email'],
            '{field_email_input}'      => $f['email_input'],
            '{field_url}'              => $f['url'],
            '{field_url_input}'        => $f['url_input'],
            '{field_message}'          => $f['message'],
            '{field_message_input}'    => $f['message_input'],
            '{captcha_block}'          => $f['captcha_answer'],
            '{captcha_block_input}'    => $f['captcha_answer_input'],
            '{captcha_question}'       => View::e($captchaQuestion),
            '{submit_button}'          => $submitButton,
            '{clear_button}'           => '<button type="reset" class="fl-btn-secondary">Clear</button>',
        ];

        $innerHtml = FormTemplateRenderer::expandTokens($tpl, $tokens);
        $formHtml  = FormTemplateRenderer::wrapForm($innerHtml, $formAction, $this->app->getCSRF()->token(), false);

        $html = $this->app->getView()->renderPublic('adopt.php', [
            'listing'      => null,
            'listing_title' => $siteName,
            'listing_url'  => $siteUrl,
            'page_title'   => 'Adopt a Listing',
            'form_action'  => $formAction,
            'csrf_token'   => $this->app->getCSRF()->token(),
            'errors'       => $error !== ''
                ? '<p>' . htmlspecialchars($error, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>'
                : '',
            'f'            => $f,
            'form_html'    => $formHtml,
            'base_url'     => $baseUrl,
            'theme_path'   => $baseUrl . '/assets/css/default/',
        ]);

        return Response::html($html);
    }
}
