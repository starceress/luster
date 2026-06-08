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
use Luster\Models\Listing;
use Luster\Repositories\EmailTemplateRepo;
use Luster\Repositories\ListingRepo;
use Luster\Repositories\ListingSettingsRepo;
use Luster\Repositories\MemberRepo;
use Luster\Support\FormFieldBuilder;
use Luster\Support\SuccessRenderer;

/**
 * Fan removal form â€” lets a fan remove themselves from a listing.
 *
 * GET  /remove/{id} â€” shows the confirmation/credential form.
 * POST /remove/{id} â€” verifies email + password, deletes the member row,
 *                     sends a farewell email, redirects to host page.
 *
 * Security properties:
 *  - CSRF token validated by CsrfMiddleware
 *  - Honeypot field silently rejects bot submissions
 *  - Arithmetic CAPTCHA prevents automated submissions
 *  - Password verified via password_verify() (Argon2id)
 *  - Legacy MD5 hash detected and verified before deletion
 */
final class RemoveController
{
    public function __construct(private readonly Application $app) {}

    // -----------------------------------------------------------------------
    // GET /remove/{id}
    // -----------------------------------------------------------------------

    public function showForm(Request $request, array $params): Response
    {
        $listingId = (int) ($params['id'] ?? 0);

        $listingRepo = new ListingRepo($this->app->getDatabase(), $this->app->getConfig());
        $listing     = $listingRepo->getById($listingId);

        if ($listing === null) {
            return Response::notFound('That listing does not exist.');
        }

        $session  = $this->app->getSession();
        $error    = (string) $session->get('lstr_flash_error',    '');
        $oldEmail = (string) $session->get('lstr_flash_old_email', '');
        $session->remove('lstr_flash_error');
        $session->remove('lstr_flash_old_email');

        $settingsRepo    = new ListingSettingsRepo($this->app->getDatabase());
        $embedSettings   = $settingsRepo->getEmbedSettings($listingId);
        $lostPasswordUrl = $this->buildResetUrl($listing, $embedSettings);

        return $this->renderRemoveForm($listingId, $listing, $oldEmail, $error, '', $lostPasswordUrl);
    }

    // -----------------------------------------------------------------------
    // POST /remove/{id}
    // -----------------------------------------------------------------------

    public function submit(Request $request, array $params): Response
    {
        $listingId = (int) ($params['id'] ?? 0);
        $baseUrl      = $this->app->getBaseUrl();
        $session      = $this->app->getSession();
        $rateLimit    = $this->app->getRateLimit();
        $ip           = $request->ip();
        $embedReturnTo = (string) $session->get('lstr_embed_return_to', '');

        // Rate limiting â€” prevents credential stuffing and email enumeration
        if ($rateLimit->isBlocked($ip, RateLimit::ACTION_PUBLIC_REMOVE)) {
            $session->set('lstr_flash_error', 'Too many attempts. Please try again later.');
            return Response::redirect($embedReturnTo !== '' ? $embedReturnTo : $baseUrl . '/remove/' . $listingId);
        }

        // Honeypot â€” silent reject; fake success so bots see no difference
        if ((string) $request->post('website_confirm', '') !== '') {
            $session->remove('lstr_embed_return_to');
            $session->set('lstr_flash_success_html', SuccessRenderer::render('', 'removed'));
            return Response::redirect($embedReturnTo !== '' ? $embedReturnTo : $baseUrl . '/remove/' . $listingId);
        }

        $listingRepo = new ListingRepo($this->app->getDatabase(), $this->app->getConfig());
        $listing     = $listingRepo->getById($listingId);

        if ($listing === null) {
            return Response::notFound('That listing does not exist.');
        }

        $settingsRepo  = new ListingSettingsRepo($this->app->getDatabase());
        $embedSettings = $settingsRepo->getEmbedSettings($listingId);
        $formPageName  = (string) $embedSettings['form_url_remove'];
        $formUrl       = $formPageName !== ''
                       ? rtrim((string) $listing->url, '/') . '/' . ltrim($formPageName, '/')
                       : $baseUrl . '/remove/' . $listingId;

        // Captcha â€” redirect with flash on failure so the form re-renders with a fresh question
        $email           = trim((string) $request->post('email', ''));
        $lostPasswordUrl = $this->buildResetUrl($listing, $embedSettings);
        if (!Captcha::validate((string) $request->post('captcha_answer', ''), 'remove')) {
            $rateLimit->hitDefault($ip, RateLimit::ACTION_PUBLIC_REMOVE);
            $session->set('lstr_flash_error', 'Incorrect answer â€” please try again.');
            $session->set('lstr_flash_old_email', $email);
            return Response::redirect($embedReturnTo !== '' ? $embedReturnTo : $formUrl);
        }

        $password = (string) $request->post('password', '');

        $v = new Validator(['email' => $email, 'password' => $password]);
        $v->field('email')->required()->email()->maxLength(255);
        $v->field('password')->required();

        if ($v->fails()) {
            $rateLimit->hitDefault($ip, RateLimit::ACTION_PUBLIC_REMOVE);
            $session->set('lstr_flash_error', implode(' ', $v->allErrors()));
            $session->set('lstr_flash_old_email', $email);
            return Response::redirect($formUrl);
        }

        $memberRepo = new MemberRepo($this->app->getDatabase(), $this->app->getConfig());
        $member     = $memberRepo->getByEmail($listing->dbprefix, $email);

        if ($member === null) {
            $rateLimit->hitDefault($ip, RateLimit::ACTION_PUBLIC_REMOVE);
            $session->set('lstr_flash_error', 'No membership found for that email address.');
            $session->set('lstr_flash_old_email', $email);
            return Response::redirect($formUrl);
        }

        // Verify password â€” detect and verify legacy MD5 hashes before deletion
        $storedHash = $member->password;
        $verified   = false;

        if (strlen($storedHash) === 32 && ctype_xdigit($storedHash)) {
            if (hash_equals($storedHash, md5($password))) {
                $verified = true;
            }
        } else {
            $verified = password_verify($password, $storedHash);
        }

        if (!$verified) {
            $rateLimit->hitDefault($ip, RateLimit::ACTION_PUBLIC_REMOVE);
            $session->set('lstr_flash_error', 'Incorrect password. Please try again.');
            $session->set('lstr_flash_old_email', $email);
            return Response::redirect($formUrl);
        }

        $memberName = $member->name;

        $memberRepo->deleteByEmail($listing->dbprefix, $email);

        // Farewell email (best-effort)
        try {
            $cfg       = $this->app->getConfig();
            $mailer    = new Mailer($cfg);
            $emailRepo = new EmailTemplateRepo($this->app->getDatabase());
            $tokens    = EmailTemplateRepo::buildTokens(
                listingName:    $listing->title,
                listingUrl:     $listing->url,
                siteName:       (string) $cfg->get('site_name', 'Luster'),
                listingSubject: $listing->subject,
                ownerName:      (string) $cfg->get('owner_name', ''),
                ownerEmail:     (string) $cfg->get('owner_email', ''),
                fanName:        $memberName,
                fanEmail:       $email,
            );
            [$subject, $body] = $emailRepo->resolveForListing($listingId, EmailTemplateRepo::TYPE_REMOVE, $tokens);
            $mailer->send($email, $memberName, $subject, $body);
        } catch (\Throwable $e) {
            error_log('[Luster] Remove email failed: ' . $e->getMessage());
        }


        $session->remove('lstr_embed_return_to');
        $successTpl = $settingsRepo->getSuccessTemplate($listingId) ?? '';
        $session->set('lstr_flash_success_html', SuccessRenderer::render($successTpl, 'removed', [
            'listing_title' => $listing->title,
            'listing_url'   => $listing->url,
        ]));
        return Response::redirect($embedReturnTo !== '' ? $embedReturnTo : $formUrl);
    }

    // -----------------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------------

    /**
     * Renders the remove form template with all required variables.
     *
     * Used by both showForm() (on GET) and the captcha-failure path in submit(),
     * so the form can be re-rendered in-place without a redirect.
     */
    private function buildResetUrl(Listing $listing, array $embedSettings): string
    {
        $resetPage = (string) ($embedSettings['form_url_reset'] ?? '');
        if ($resetPage !== '') {
            return rtrim((string) $listing->url, '/') . '/' . ltrim($resetPage, '/');
        }
        return '';
    }

    private function renderRemoveForm(
        int $listingId,
        Listing $listing,
        string $oldEmail = '',
        string $error = '',
        string $captchaError = '',
        string $lostPasswordUrl = '',
    ): Response {
        $baseUrl         = $this->app->getBaseUrl();
        $captchaQuestion = Captcha::getQuestion('remove');
        $wrapperTpl      = (new ListingSettingsRepo($this->app->getDatabase()))->getWrapperTemplate($listingId) ?? '';

        $fb          = $this->app->getFormFieldBuilder();
        $descriptors = [
            ['type' => 'email',    'name' => 'email',          'id' => 'rem-email',    'label' => 'Email',    'required' => true, 'value' => $oldEmail,   'attrs' => ['maxlength' => '255', 'autocomplete' => 'email']],
            ['type' => 'password', 'name' => 'password',       'id' => 'rem-password', 'label' => 'Password', 'required' => true, 'attrs' => ['maxlength' => '255', 'autocomplete' => 'current-password']],
            ['type' => 'text',     'name' => 'captcha_answer', 'id' => 'rem-captcha',  'label' => 'Captcha: ' . $captchaQuestion, 'required' => true, 'error' => $captchaError, 'attrs' => ['maxlength' => '10', 'autocomplete' => 'off']],
        ];
        $f = $fb->build($descriptors, $wrapperTpl);

        $html = $this->app->getView()->renderPublic('remove.php', [
            'listing'           => $listing,
            'listing_name'      => $listing->title,
            'listing_url'       => $listing->url,
            'page_title'        => 'Remove Membership',
            'form_action'       => $baseUrl . '/remove/' . $listingId,
            'csrf_token'        => $this->app->getCSRF()->token(),
            'errors'            => $error !== '' ? '<p>' . htmlspecialchars($error, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>' : '',
            'lost_password_url' => $lostPasswordUrl,
            'f'                 => $f,
            'base_url'          => $baseUrl,
            'theme_path'        => $baseUrl . '/assets/css/default/',
        ]);

        return Response::html($html);
    }
}
