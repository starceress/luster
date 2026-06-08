<?php

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
use Luster\Repositories\ListingRepo;
use Luster\Repositories\SiteSettingsRepo;
use Luster\Support\FormFieldBuilder;
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

        $listingRepo = new ListingRepo($this->app->getDatabase(), $this->app->getConfig());
        $fanlistings = $listingRepo->getAll();

        // Captcha — redirect with flash on failure so the form re-renders with a fresh question
        $captchaOk = Captcha::validate((string) $request->post('captcha_answer', ''), 'kim_signup');
        if (!$captchaOk) {
            $rateLimit->hitDefault($ip, 'kim_signup');
            $session->set('lstr_flash_error', 'Incorrect answer — please try again.');
            $session->set('lstr_flash_old', [
                'name'         => trim((string) $request->post('name',         '')),
                'email'        => trim((string) $request->post('email',        '')),
                'url'          => trim((string) $request->post('url',          '')),
                'fanlisting_id' => (string) $request->post('fanlisting_id',    ''),
                'send_copy'    => (string) $request->post('send_copy',         ''),
            ]);
            return Response::redirect($embedReturnTo !== '' ? $embedReturnTo : $fallbackUrl);
        }

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
        $v->field('url')->required()->httpUrl()->maxLength(500);
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
            'send_copy'    => (string) $request->post('send_copy', ''),
        ];

        if ($validationFailed) {
            $rateLimit->hit($ip, 'kim_signup', 5, 3600);
            $session->set('lstr_flash_error', implode(' ', $v->allErrors()) ?: 'Please select a valid fanlisting.');
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

        // Password — auto-generate if not supplied
        $rawPassword     = (string) $request->post('password',         '');
        $passwordConfirm = (string) $request->post('password_confirm', '');

        if ($rawPassword !== '' && $rawPassword !== $passwordConfirm) {
            $session->set('lstr_flash_error', 'Passwords do not match. Please try again.');
            $session->set('lstr_flash_old', $oldFields);
            return Response::redirect($embedReturnTo !== '' ? $embedReturnTo : $fallbackUrl);
        }

        if ($rawPassword !== '') {
            $passwordValidator = new Validator(['password' => $rawPassword]);
            PasswordPolicy::apply($passwordValidator->field('password'));
            if ($passwordValidator->fails()) {
                $session->set('lstr_flash_error', implode(' ', $passwordValidator->allErrors()));
                $session->set('lstr_flash_old', $oldFields);
                return Response::redirect($embedReturnTo !== '' ? $embedReturnTo : $fallbackUrl);
            }
        }

        if ($rawPassword === '') {
            $rawPassword  = bin2hex(random_bytes(8));
            $wasGenerated = true;
        } else {
            $wasGenerated = false;
        }

        $passwordHash = password_hash($rawPassword, PASSWORD_ARGON2ID);

        $kimRepo->create($fanlistingId, $name, $email, $url, $passwordHash);
        $rateLimit->clear($ip, 'kim_signup');

        // Find the fanlisting subject for the email
        $selectedListing = null;
        foreach ($fanlistings as $fl) {
            if ($fl->id === $fanlistingId) {
                $selectedListing = $fl;
                break;
            }
        }

        $sendCopy = ((string) $request->post('send_copy', '') === '1');

        if ($sendCopy || $wasGenerated) {
            try {
                $mailer  = new Mailer($this->app->getConfig());
                $siteName = (string) $this->app->getConfig()->get('site_name', 'Luster');
                $setupUrl = $wasGenerated ? $this->issuePasswordSetupUrl($kimRepo, $email, $fanlistingId) : '';
                $subject = "KIM List Sign-Up — {$siteName}";
                $body    = "Hi {$name},\n\n"
                         . "You have signed up for the KIM List for:\n"
                         . "  Fanlisting: " . ($selectedListing?->subject ?? "ID {$fanlistingId}") . "\n"
                         . "  Your name:  {$name}\n"
                         . "  Your email: {$email}\n"
                         . "  Your URL:   {$url}\n\n"
                         . "Your entry is currently pending approval.\n\n"
                         . "-- {$siteName}";
                if ($setupUrl !== '') {
                    $body = str_replace(
                        "-- {$siteName}",
                        "Use the link below to set your password. This link expires in 1 hour.\n"
                        . $setupUrl . "\n\n"
                        . "-- {$siteName}",
                        $body,
                    );
                }
                $mailer->send($email, $name, $subject, $body);
            } catch (\Throwable $e) {
                error_log('[KIM] Sign-up confirmation email failed: ' . $e->getMessage());
            }
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
        string $captchaError = '',
    ): Response {
        $baseUrl    = $this->app->getBaseUrl();
        $wrapperTpl = (new SiteSettingsRepo($this->app->getDatabase()))->get('collective_field_wrapper_template', '') ?? '';

        $flSelectHtml = '<select class="fl-select" id="kim-fanlisting" name="fanlisting_id" required>'
                      . '<option value="">&#8212; Select a fanlisting &#8212;</option>';
        foreach ($fanlistings as $_fl) {
            $_flId       = htmlspecialchars((string) $_fl->id, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $_flTitle    = htmlspecialchars((string) $_fl->subject, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $_flSelected = ((string) ($old['fanlisting_id'] ?? '')) === (string) $_fl->id ? ' selected' : '';
            $_flUpcoming = $_fl->upcoming ? ' (upcoming)' : '';
            $flSelectHtml .= "<option value=\"{$_flId}\"{$_flSelected}>{$_flTitle}{$_flUpcoming}</option>";
        }
        $flSelectHtml .= '</select>';

        $captchaQ = Captcha::getQuestion('kim_signup');
        $f = $this->app->getFormFieldBuilder()->build([
            ['type' => 'text',     'name' => 'name',             'id' => 'kim-name',             'label' => 'Name',                     'required' => true,  'value' => (string) ($old['name']  ?? ''), 'attrs' => ['maxlength' => '100', 'autocomplete' => 'name']],
            ['type' => 'url',      'name' => 'url',              'id' => 'kim-url',              'label' => 'Collective / Website URL',  'required' => true,  'value' => (string) ($old['url']   ?? ''), 'attrs' => ['maxlength' => '500', 'placeholder' => 'https://', 'autocomplete' => 'url']],
            ['type' => 'email',    'name' => 'email',            'id' => 'kim-email',            'label' => 'Email',                    'required' => true,  'value' => (string) ($old['email'] ?? ''), 'attrs' => ['maxlength' => '255', 'autocomplete' => 'email']],
            ['type' => 'select',   'name' => 'fanlisting_id',   'id' => 'kim-fanlisting',       'label' => 'Fanlisting Interested In', 'required' => true,  'raw_input' => $flSelectHtml],
            ['type' => 'password', 'name' => 'password',         'id' => 'kim-password',         'label' => 'Password',                 'required' => false, 'attrs' => ['maxlength' => '255', 'autocomplete' => 'new-password'], 'hint' => 'Leave blank to auto-generate; you\'ll receive it by email'],
            ['type' => 'password', 'name' => 'password_confirm', 'id' => 'kim-password-confirm', 'label' => 'Confirm Password',         'required' => false, 'attrs' => ['maxlength' => '255', 'autocomplete' => 'new-password']],
            ['type' => 'text',     'name' => 'captcha_answer',   'id' => 'kim-captcha',          'label' => 'Captcha: ' . $captchaQ,   'required' => true,  'error' => $captchaError, 'attrs' => ['maxlength' => '10', 'autocomplete' => 'off']],
        ], $wrapperTpl);

        return $this->renderKimPage('signup.php', [
            'page_title'  => 'KIM List — Sign Up',
            'form_action' => $baseUrl . '/kim',
            'csrf_token'  => $this->app->getCSRF()->token(),
            'errors'      => $error !== ''
                ? '<p>' . htmlspecialchars($error, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>'
                : '',
            'old'         => $old,
            'f'           => $f,
            'base_url'    => $baseUrl,
            'theme_path'  => $baseUrl . '/assets/default/',
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
            'listing_name' => $siteName,
            'listing_url'  => $baseUrl,
            'theme_path'   => $baseUrl . '/assets/default/',
        ]);

        $layoutPath = $this->app->getBasePath() . '/templates/default/layout.php';
        if (is_file($layoutPath)) {
            $html = $view->render('default/layout.php', $layoutData);
        } else {
            $html = $content;
        }

        return Response::html($html);
    }

    private function issuePasswordSetupUrl(KimMemberRepo $kimRepo, string $email, int $fanlistingId): string
    {
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

        return $this->app->getBaseUrl() . '/kim/reset/confirm?token=' . urlencode($rawToken);
    }
}
