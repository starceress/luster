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
use Luster\Support\FormFieldBuilder;
use Luster\Support\SuccessRenderer;

/**
 * Public adoption application form.
 *
 * Routes (registered by AdoptionAddon::registerRoutes()):
 *   GET  /adopt/{id}  â€” renders the application form.
 *   POST /adopt/{id}  â€” validates input, inserts application, redirects to host page.
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

        // Honeypot â€” silent reject; fake success so bots see no difference
        if ((string) $request->post('website_confirm', '') !== '') {
            $session->remove('lstr_embed_return_to');
            $successTpl = (new ListingSettingsRepo($this->app->getDatabase()))->getSuccessTemplate($listingId) ?? '';
            $session->set('lstr_flash_success_html', SuccessRenderer::render($successTpl, 'adopt-submitted'));
            return Response::redirect($embedReturnTo !== '' ? $embedReturnTo : $fallbackUrl);
        }

        // Captcha â€” redirect with flash on failure so the form re-renders with a fresh question
        if (!Captcha::validate((string) $request->post('captcha_answer', ''), 'adopt')) {
            $rateLimit->hitDefault($ip, RateLimit::ACTION_PUBLIC_ADOPT);
            $session->set('lstr_flash_error', 'Incorrect answer â€” please try again.');
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

        // Duplicate check â€” one active application per email per listing
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

        $session->remove('lstr_embed_return_to');
        $successTpl = (new ListingSettingsRepo($this->app->getDatabase()))->getSuccessTemplate($listingId) ?? '';
        $session->set('lstr_flash_success_html', SuccessRenderer::render($successTpl, 'adopt-submitted', [
            'listing_title' => $listing->title,
            'listing_url'   => $listing->url,
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
    ): Response {
        $baseUrl         = $this->app->getBaseUrl();
        $captchaQuestion = Captcha::getQuestion('adopt');
        $wrapperTpl      = (new ListingSettingsRepo($this->app->getDatabase()))->getWrapperTemplate($listingId) ?? '';

        $f = $this->app->getFormFieldBuilder()->build([
            ['type' => 'text',     'name' => 'name',           'id' => 'adopt-name',    'label' => 'Name',    'required' => true,  'value' => (string) ($old['name']    ?? ''), 'attrs' => ['maxlength' => '100', 'autocomplete' => 'name']],
            ['type' => 'email',    'name' => 'email',          'id' => 'adopt-email',   'label' => 'Email',   'required' => true,  'value' => (string) ($old['email']   ?? ''), 'attrs' => ['maxlength' => '255', 'autocomplete' => 'email']],
            ['type' => 'url',      'name' => 'url',            'id' => 'adopt-url',     'label' => 'Website URL', 'required' => false, 'value' => (string) ($old['url']  ?? ''), 'attrs' => ['maxlength' => '500', 'placeholder' => 'https://', 'autocomplete' => 'url'], 'hint' => 'Optional'],
            ['type' => 'textarea', 'name' => 'message',        'id' => 'adopt-message', 'label' => 'Message', 'required' => false, 'value' => (string) ($old['message'] ?? ''), 'attrs' => ['maxlength' => '2000', 'rows' => '5'], 'hint' => 'Optional'],
            ['type' => 'text',     'name' => 'captcha_answer', 'id' => 'adopt-captcha', 'label' => 'Captcha: ' . $captchaQuestion, 'required' => true, 'error' => $captchaError, 'attrs' => ['maxlength' => '10', 'autocomplete' => 'off']],
        ], $wrapperTpl);

        $html = $this->app->getView()->renderPublic('adopt.php', [
            'listing'      => $listing,
            'listing_name' => $listing->title,
            'listing_url'  => $listing->url,
            'page_title'   => 'Adopt ' . $listing->title,
            'form_action'  => $baseUrl . '/adopt/' . $listingId,
            'csrf_token'   => $this->app->getCSRF()->token(),
            'errors'       => $error !== ''
                ? '<p>' . htmlspecialchars($error, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>'
                : '',
            'f'            => $f,
            'base_url'     => $baseUrl,
            'theme_path'   => $baseUrl . '/assets/css/default/',
        ]);

        return Response::html($html);
    }
}
