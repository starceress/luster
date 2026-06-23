<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Public controller for KIM sign-up. Renders the sign-up form where fans
 choose a fanlisting and provide their contact details. Validates input
 with captcha and rate limiting, hashes the password with Argon2id, inserts
 a pending KIM member row, and sends a confirmation email to the admin.
*/

declare(strict_types=1);

namespace Luster\Addons\Kim\Controllers;

use Luster\Addons\Kim\KimMemberRepo;
use Luster\Addons\Kim\KimPasswordResetRepo;
use Luster\Core\Application;
use Luster\Core\Captcha;
use Luster\Core\Mailer;
use Luster\Core\PasswordPolicy;
use Luster\Core\Request;
use Luster\Core\Response;
use Luster\Core\Validator;
use Luster\Core\View;
use Luster\Repositories\EmailTemplateRepo;
use Luster\Repositories\ListingRepo;
use Luster\Repositories\SiteSettingsRepo;
use Luster\Support\FormTemplateRenderer;
use Luster\Support\SuccessRenderer;

/**
 * Public KIM sign-up form.
 *
 * GET  /kim  — renders the sign-up form
 * POST /kim  — validates, inserts, sends confirmation, redirects to host page
 */
final class KimSignupController
{
    public function __construct(private readonly Application $app) {}

    // -----------------------------------------------------------------------
    // GET /kim
    // -----------------------------------------------------------------------

    public function showForm(Request $request, array $params): Response
    {
        $session = $this->app->getSession();
        $error   = (string) $session->get('lstr_flash_error', '');
        $old     = (array)  $session->get('lstr_flash_old',   []);
        $session->remove('lstr_flash_error');
        $session->remove('lstr_flash_old');

        $listingRepo = new ListingRepo($this->app->getDatabase(), $this->app->getConfig());
        $fanlistings = $listingRepo->getAll();

        return $this->renderForm($fanlistings, $old, $error);
    }

    // -----------------------------------------------------------------------
    // POST /kim
    // -----------------------------------------------------------------------

    public function submit(Request $request, array $params): Response
    {
        $baseUrl       = $this->app->getBaseUrl();
        $session       = $this->app->getSession();
        $rateLimit     = $this->app->getRateLimit();
        $ip            = $request->ip();
        $embedReturnTo = (string) $session->get('lstr_embed_return_to', '');
        $fallbackUrl   = $baseUrl . '/kim';

        // Rate limit check
        if ($rateLimit->isBlocked($ip, 'kim_signup')) {
            $session->set('lstr_flash_error', 'Too many attempts. Please try again later.');
            return Response::redirect($embedReturnTo !== '' ? $embedReturnTo : $fallbackUrl);
        }

        // Honeypot — silent reject; fake success so bots see no difference
        if ((string) $request->post('website_confirm', '') !== '') {
            $session->remove('lstr_embed_return_to');
            $session->set('lstr_flash_success_html', SuccessRenderer::render('', 'kim-signed-up'));
            return Response::redirect($embedReturnTo !== '' ? $embedReturnTo : $fallbackUrl);
        }

        // CAPTCHA
        if (!Captcha::validate((string) $request->post('captcha_answer', ''), 'kim_signup')) {
            $rateLimit->hit($ip, 'kim_signup', 5, 3600);
            $session->set('lstr_flash_error', 'Incorrect answer — please try again.');
            $session->set('lstr_flash_old', [
                'name'         => trim((string) $request->post('name',         '')),
                'email'        => trim((string) $request->post('email',        '')),
                'url'          => trim((string) $request->post('url',          '')),
                'fanlisting_id' => (string) (int) $request->post('fanlisting_id', 0),
            ]);
            return Response::redirect($embedReturnTo !== '' ? $embedReturnTo : $fallbackUrl);
        }

        $listingRepo = new ListingRepo($this->app->getDatabase(), $this->app->getConfig());
        $fanlistings = $listingRepo->getAll();

        $name        = trim((string) $request->post('name',         ''));
        $email       = trim((string) $request->post('email',        ''));
        $url         = trim((string) $request->post('url',          ''));
        $fanlistingId = (int) $request->post('fanlisting_id',       0);

        // Validate fanlisting exists in our list
        $fanlistingIds = array_map(static fn($fl) => $fl->id, $fanlistings);

        $v = new Validator([
            'name'         => $name,
            'email'        => $email,
            'url'          => $url,
            'fanlisting_id' => (string) $fanlistingId,
        ]);
        $v->field('name')->required()->maxLength(100);
        $v->field('email')->required()->email()->maxLength(255);
        if ($url !== '') {
            $v->field('url')->httpUrl()->maxLength(500);
        }
        $v->field('fanlisting_id')->required();

        $validationFailed = $v->fails();
        if (!$validationFailed && ($fanlistingId <= 0 || !in_array($fanlistingId, $fanlistingIds, true))) {
            $validationFailed = true;
        }

        $oldFields = [
            'name'         => $name,
            'email'        => $email,
            'url'          => $url,
            'fanlisting_id' => (string) $fanlistingId,
        ];

        if ($validationFailed) {
            $rateLimit->hit($ip, 'kim_signup', 5, 3600);
            $session->set('lstr_flash_error', implode(' ', $v->allErrors()) ?: 'Please select a valid fanlisting.');
            $session->set('lstr_flash_old', $oldFields);
            return Response::redirect($embedReturnTo !== '' ? $embedReturnTo : $fallbackUrl);
        }

        // Blacklist check
        if ((new \Luster\Repositories\BlacklistRepo($this->app->getDatabase()))->isBlocked($email)) {
            $session->set('lstr_flash_error', 'That email address cannot be used to sign up.');
            $session->set('lstr_flash_old', $oldFields);
            return Response::redirect($embedReturnTo !== '' ? $embedReturnTo : $fallbackUrl);
        }

        $kimRepo = new KimMemberRepo($this->app->getDatabase());

        // Duplicate check
        if ($kimRepo->findByEmailAndFanlisting($email, $fanlistingId) !== null) {
            $session->set('lstr_flash_error', 'That email is already signed up for this fanlisting.');
            $session->set('lstr_flash_old', $oldFields);
            return Response::redirect($embedReturnTo !== '' ? $embedReturnTo : $fallbackUrl);
        }

        // Password — required
        $rawPassword     = (string) $request->post('password',         '');
        $passwordConfirm = (string) $request->post('password_confirm', '');

        if ($rawPassword === '') {
            $session->set('lstr_flash_error', 'Password is required.');
            $session->set('lstr_flash_old', $oldFields);
            return Response::redirect($embedReturnTo !== '' ? $embedReturnTo : $fallbackUrl);
        }

        if ($rawPassword !== $passwordConfirm) {
            $session->set('lstr_flash_error', 'Passwords do not match. Please try again.');
            $session->set('lstr_flash_old', $oldFields);
            return Response::redirect($embedReturnTo !== '' ? $embedReturnTo : $fallbackUrl);
        }

        {
            $passwordValidator = new Validator(['password' => $rawPassword]);
            PasswordPolicy::apply($passwordValidator->field('password'));
            if ($passwordValidator->fails()) {
                $session->set('lstr_flash_error', implode(' ', $passwordValidator->allErrors()));
                $session->set('lstr_flash_old', $oldFields);
                return Response::redirect($embedReturnTo !== '' ? $embedReturnTo : $fallbackUrl);
            }
        }

        $passwordHash = password_hash($rawPassword, PASSWORD_ARGON2ID);

        $kimRepo->create($fanlistingId, $name, $email, $url, $passwordHash);
        $rateLimit->hit($ip, 'kim_signup', 10, 3600);

        // Find the fanlisting subject for the email
        $selectedListing = null;
        foreach ($fanlistings as $fl) {
            if ($fl->id === $fanlistingId) {
                $selectedListing = $fl;
                break;
            }
        }

        // Always send confirmation email to the member
        try {
            $config     = $this->app->getConfig();
            $siteName   = (string) $config->get('site_name', '');
            $ownerName  = (string) $config->get('owner_name',  '');
            $ownerEmail = (string) $config->get('owner_email', '');

            $listingName = $selectedListing !== null ? ($selectedListing->subject ?: $selectedListing->title) : $siteName;
            $listingUrl  = $selectedListing !== null ? $selectedListing->url : (string) $this->app->getConfig()->get('site_url', '');

            // Always issue a setup URL so the fan can access their account.
            // URL points to the KIM reset page (configured) or the Luster reset route —
            // never to the signup page, even in embed context.
            $setupUrl  = $this->issuePasswordSetupUrl($kimRepo, $email, $fanlistingId);
            $emailRepo = new EmailTemplateRepo($this->app->getDatabase());
            $tokens    = EmailTemplateRepo::buildKimTokens(
                listingName: $listingName,
                listingUrl:  $listingUrl,
                siteName:    $siteName,
                ownerName:   $ownerName,
                ownerEmail:  $ownerEmail,
                kimName:     $name,
                kimEmail:    $email,
                kimUrl:      $url,
                resetUrl:    $setupUrl,
            );
            [$subject, $body] = $emailRepo->resolveForListing(null, EmailTemplateRepo::TYPE_KIM_SIGNUP, $tokens);
            (new Mailer($config))->send($email, $name, $subject, $body);
        } catch (\Throwable $e) {
            error_log('[KIM] Sign-up confirmation email failed: ' . $e->getMessage());
        }

        // Admin notification email
        try {
            $config    = $this->app->getConfig();
            $adminTo   = ($selectedListing !== null && $selectedListing->email !== '')
                       ? $selectedListing->email
                       : (string) $config->get('owner_email', '');
            if ($adminTo !== '') {
                $emailRepo = new EmailTemplateRepo($this->app->getDatabase());
                $tokens    = EmailTemplateRepo::buildKimTokens(
                    listingName : $selectedListing?->title ?? '',
                    listingUrl  : $selectedListing?->url   ?? '',
                    siteName    : (string) $config->get('site_name',   ''),
                    ownerName   : (string) $config->get('owner_name',  ''),
                    ownerEmail  : (string) $config->get('owner_email', ''),
                    kimName     : $name,
                    kimEmail    : $email,
                    kimUrl      : $url,
                );
                [$notifySubject, $notifyBody] = $emailRepo->resolveForListing(
                    $fanlistingId,
                    EmailTemplateRepo::TYPE_KIM_APPLIED,
                    $tokens,
                );
                (new Mailer($config))->send(
                    $adminTo,
                    (string) $config->get('owner_name', ''),
                    $notifySubject,
                    $notifyBody,
                );
            }
        } catch (\Throwable) {
            // Email failures are non-fatal
        }

        $session->remove('lstr_embed_return_to');
        $session->set('lstr_flash_success_html', SuccessRenderer::render('', 'kim-signed-up'));
        return Response::redirect($embedReturnTo !== '' ? $embedReturnTo : $fallbackUrl);
    }

    // -----------------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------------

    private function renderForm(
        array  $fanlistings,
        array  $old = [],
        string $error = '',
    ): Response {
        $baseUrl    = $this->app->getBaseUrl();
        $siteRepo   = new SiteSettingsRepo($this->app->getDatabase());
        $wrapperTpl = '';
        $siteName   = (string) $this->app->getConfig()->get('site_name', 'Luster');

        $flSelectHtml = '<select class="fl-select" id="kim-fanlisting" name="fanlisting_id" required>'
                      . '<option value="">&#8212; Select a Listing &#8212;</option>';
        foreach ($fanlistings as $_fl) {
            $_flId       = htmlspecialchars((string) $_fl->id, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $_flTitle    = htmlspecialchars((string) $_fl->subject, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $_flSelected = ((string) ($old['fanlisting_id'] ?? '')) === (string) $_fl->id ? ' selected' : '';
            $_flUpcoming = $_fl->upcoming ? ' (upcoming)' : '';
            $flSelectHtml .= "<option value=\"{$_flId}\"{$_flSelected}>{$_flTitle}{$_flUpcoming}</option>";
        }
        $flSelectHtml .= '</select>';

        $f = $this->app->getFormFieldBuilder()->build([
            ['type' => 'text',         'name' => 'name',             'id' => 'kim-name',             'label' => 'Name',                     'required' => true,  'value' => (string) ($old['name']  ?? ''), 'attrs' => ['maxlength' => '100', 'autocomplete' => 'name']],
            ['type' => 'url',          'name' => 'url',              'id' => 'kim-url',              'label' => 'Website URL (optional)',  'required' => false, 'value' => (string) ($old['url']   ?? ''), 'attrs' => ['maxlength' => '500', 'placeholder' => 'https://', 'autocomplete' => 'url']],
            ['type' => 'email',        'name' => 'email',            'id' => 'kim-email',            'label' => 'Email',                    'required' => true,  'value' => (string) ($old['email'] ?? ''), 'attrs' => ['maxlength' => '255', 'autocomplete' => 'email']],
            ['type' => 'select',       'name' => 'fanlisting_id',    'id' => 'kim-fanlisting',       'label' => 'Fanlisting Interested In', 'required' => true,  'raw_input' => $flSelectHtml],
            ['type' => 'password',     'name' => 'password',         'id' => 'kim-password',         'label' => 'Password',                 'required' => true,  'attrs' => ['maxlength' => '255', 'autocomplete' => 'new-password'], 'hint' => 'Minimum 12 characters.'],
            ['type' => 'password',     'name' => 'password_confirm', 'id' => 'kim-password-confirm', 'label' => 'Confirm Password',         'required' => true,  'attrs' => ['maxlength' => '255', 'autocomplete' => 'new-password']],
            ['type' => 'text',         'name' => 'captcha_answer',   'id' => 'kim-captcha',          'label' => 'Captcha: ' . Captcha::getQuestion('kim_signup'), 'required' => true, 'attrs' => ['maxlength' => '10', 'autocomplete' => 'off']],
        ], $wrapperTpl);

        $formHtml = null;
        $kimSignupTpl = $siteRepo->get('kim_signup_form_template') ?? '';
        if ($kimSignupTpl !== '') {
            $tokens = [
                '{site_name}'                        => View::e($siteName),
                '{field_name}'                        => $f['name'],
                '{field_name_input}'                  => $f['name_input'],
                '{field_url}'                         => $f['url'],
                '{field_url_input}'                   => $f['url_input'],
                '{field_email}'                       => $f['email'],
                '{field_email_input}'                 => $f['email_input'],
                '{field_fanlisting_id}'               => $f['fanlisting_id'],
                '{field_fanlisting_id_input}'         => $f['fanlisting_id_input'],
                '{field_password}'                    => $f['password'],
                '{field_password_input}'              => $f['password_input'],
                '{field_password_confirm}'            => $f['password_confirm'],
                '{field_password_confirm_input}'      => $f['password_confirm_input'],
                '{captcha_block}'                     => $f['captcha_answer'],
                '{captcha_block_input}'               => $f['captcha_answer_input'] ?? '',
                '{captcha_question}'                  => View::e(Captcha::getQuestion('kim_signup')),
                '{submit_button}'                     => '<button type="submit" class="fl-submit">Sign Up</button>',
                '{clear_button}'                      => '<button type="reset" class="fl-btn-secondary">Clear</button>',
            ];
            $innerHtml = FormTemplateRenderer::expandTokens($kimSignupTpl, $tokens);
            $formHtml  = FormTemplateRenderer::wrapForm($innerHtml, $baseUrl . '/kim', $this->app->getCSRF()->token(), false, formMarker: 'kim_signup');
        }

        return $this->renderKimPage('signup.php', [
            'page_title'  => 'KIM List — Sign Up',
            'form_action' => $baseUrl . '/kim',
            'csrf_token'  => $this->app->getCSRF()->token(),
            'errors'      => $error !== ''
                ? '<p>' . htmlspecialchars($error, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>'
                : '',
            'old'         => $old,
            'f'           => $f,
            'form_html'   => $formHtml,
            'base_url'    => $baseUrl,
            'theme_path'  => $baseUrl . '/assets/css/default/',
        ]);
    }

    private function renderKimPage(string $template, array $data): Response
    {
        $view     = $this->app->getView();
        $baseUrl  = $this->app->getBaseUrl();
        $siteName = (string) $this->app->getConfig()->get('site_name', 'Luster');

        $content = $view->render('default/addons/kim/' . $template, $data);

        $layoutData = array_merge($data, [
            'content'      => $content,
            'listing'      => null,
            'listing_title' => $siteName,
            'listing_url'  => $baseUrl,
            'theme_path'   => $baseUrl . '/assets/css/default/',
        ]);

        $layoutPath = $this->app->getBasePath() . '/templates/default/layout.php';
        if (is_file($layoutPath)) {
            $html = $view->render('default/layout.php', $layoutData);
        } else {
            $html = $content;
        }

        return Response::html($html);
    }

    private function issuePasswordSetupUrl(
        KimMemberRepo $kimRepo,
        string        $email,
        int           $fanlistingId,
    ): string {
        $member = $kimRepo->findByEmailAndFanlisting($email, $fanlistingId);
        if ($member === null) {
            return '';
        }

        $resetRepo = new KimPasswordResetRepo($this->app->getDatabase());
        $resetRepo->deleteByMember($member->id);

        $rawToken  = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $rawToken);
        $expiresAt = (new \DateTimeImmutable())
            ->modify('+3600 seconds')
            ->format('Y-m-d H:i:s');

        $resetRepo->create($member->id, $tokenHash, $expiresAt);

        // Resolve reset page: prefer the admin-configured KIM reset page URL so that
        // the token lands on the host page embedding the kim-reset.php embed, not on
        // the signup page or Luster's own routes.
        $siteRepo      = new SiteSettingsRepo($this->app->getDatabase());
        $resetPagePath = $siteRepo->get('kim_url_reset') ?? '';
        if ($resetPagePath !== '') {
            $parsed   = parse_url((string) ($this->app->getConfig()->get('site_url', '')));
            $siteRoot = ($parsed['scheme'] ?? 'https') . '://' . ($parsed['host'] ?? '');
            if (!empty($parsed['port'])) {
                $siteRoot .= ':' . $parsed['port'];
            }
            $baseResetUrl = $siteRoot . '/' . ltrim($resetPagePath, '/');
        } else {
            $baseResetUrl = $this->app->getBaseUrl() . '/kim/reset/confirm';
        }

        $sep = str_contains($baseResetUrl, '?') ? '&' : '?';
        return $baseResetUrl . $sep . 'token=' . urlencode($rawToken);
    }
}
