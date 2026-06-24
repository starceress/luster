<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Public controller for KIM password resets. A two-step flow: the fan enters
 their email to receive a time-limited reset link (1 hour TTL), then follows
 the link to set a new password. The response is always the same regardless
 of whether the email exists, to prevent account enumeration.
*/

declare(strict_types=1);

namespace Luster\Addons\Kim\Controllers;

use Luster\Addons\Kim\KimMemberRepo;
use Luster\Addons\Kim\KimPasswordResetRepo;
use Luster\Core\Application;
use Luster\Core\Mailer;
use Luster\Core\PasswordPolicy;
use Luster\Core\Request;
use Luster\Core\Response;
use Luster\Core\TemplateEngine;
use Luster\Core\Validator;
use Luster\Core\View;
use Luster\Repositories\EmailTemplateRepo;
use Luster\Repositories\SiteSettingsRepo;
use Luster\Support\FormTemplateRenderer;
use Luster\Support\SuccessRenderer;

/**
 * KIM password reset flow.
 *
 * GET  /kim/reset          — email entry form
 * POST /kim/reset          — sends reset email, redirects to host page (no enumeration)
 * GET  /kim/reset/confirm  — new password form (validates token from ?token=)
 * POST /kim/reset/confirm  — applies new password, consumes token, redirects to host page
 */
final class KimResetController
{
    private const TOKEN_TTL_SECONDS = 3600; // 1 hour

    public function __construct(private readonly Application $app) {}

    // -----------------------------------------------------------------------
    // GET /kim/reset
    // -----------------------------------------------------------------------

    /** Renders the email-entry form for step 1 of the KIM password reset flow. */
    public function showForm(Request $request, array $params): Response
    {
        $session     = $this->app->getSession();
        $successHtml = (string) $session->get('lstr_flash_success_html', '');
        $error       = (string) $session->get('lstr_flash_error',     '');
        $oldEmail    = (string) $session->get('lstr_flash_old_email', '');
        $session->remove('lstr_flash_success_html');
        $session->remove('lstr_flash_error');
        $session->remove('lstr_flash_old_email');

        return $this->renderResetForm($oldEmail, $error, $successHtml);
    }

    // -----------------------------------------------------------------------
    // POST /kim/reset
    // -----------------------------------------------------------------------

    /**
     * Processes the email-entry step. Issues time-limited reset tokens for each
     * approved KIM entry found for the submitted email, then sends a single email
     * containing all reset links. Always redirects with the same neutral flash
     * message to prevent account enumeration.
     */
    public function submit(Request $request, array $params): Response
    {
        $baseUrl       = $this->app->getBaseUrl();
        $session       = $this->app->getSession();
        $rateLimit     = $this->app->getRateLimit();
        $ip            = $request->ip();
        $embedReturnTo = (string) $session->get('lstr_embed_return_to', '');
        $fallbackUrl   = $baseUrl . '/kim/reset';

        // Never reveal blocked status
        if ($rateLimit->isBlocked($ip, 'kim_reset')) {
            $session->remove('lstr_embed_return_to');
            $session->set('lstr_flash_success_html', SuccessRenderer::render('', 'reset-sent'));
            return Response::redirect($embedReturnTo !== '' ? $embedReturnTo : $fallbackUrl);
        }

        // Honeypot — silent reject; same neutral message so bots see no difference
        if ((string) $request->post('website_confirm', '') !== '') {
            $session->remove('lstr_embed_return_to');
            $session->set('lstr_flash_success_html', SuccessRenderer::render('', 'reset-sent'));
            return Response::redirect($embedReturnTo !== '' ? $embedReturnTo : $fallbackUrl);
        }

        $email = trim((string) $request->post('email', ''));

        $v = new Validator(['email' => $email]);
        $v->field('email')->required()->email()->maxLength(255);

        $rateLimit->hit($ip, 'kim_reset', 5, 3600);

        if (!$v->fails()) {
            $db        = $this->app->getDatabase();
            $config    = $this->app->getConfig();
            $kimRepo   = new KimMemberRepo($db);
            $resetRepo = new KimPasswordResetRepo($db);
            $members   = $kimRepo->findAllByEmail($email);
            $siteName  = (string) $config->get('site_name', '');

            $listingRepo = new \Luster\Repositories\ListingRepo($db, $config);
            $resetLinks  = [];
            $recipientName = '';

            foreach ($members as $member) {
                if ($member->pending) {
                    continue;
                }

                $resetRepo->deleteByMember($member->id);

                $rawToken  = bin2hex(random_bytes(32));
                $tokenHash = hash('sha256', $rawToken);
                $expiresAt = (new \DateTimeImmutable())
                    ->modify('+' . self::TOKEN_TTL_SECONDS . ' seconds')
                    ->format('Y-m-d H:i:s');

                $resetRepo->create($member->id, $tokenHash, $expiresAt);

                $sep      = str_contains($embedReturnTo, '?') ? '&' : '?';
                $resetUrl = $embedReturnTo !== ''
                    ? $embedReturnTo . $sep . 'token=' . urlencode($rawToken)
                    : $baseUrl . '/kim/reset/confirm?token=' . urlencode($rawToken);

                $listing     = $listingRepo->getById($member->fanlistingId);
                $listingName = $listing !== null ? ($listing->subject ?: $listing->title) : 'Listing #' . $member->fanlistingId;

                $resetLinks[] = ['listing' => $listingName, 'url' => $resetUrl];
                if ($recipientName === '') {
                    $recipientName = $member->name;
                }
            }

            if ($resetLinks !== []) {
                try {
                    $ownerName  = (string) $config->get('owner_name',  '');
                    $ownerEmail = (string) $config->get('owner_email', '');

                    // Single entry: bare reset URL. Multiple entries: numbered list.
                    if (count($resetLinks) === 1) {
                        $resetUrlText = $resetLinks[0]['url'];
                    } else {
                        $lines = [];
                        foreach ($resetLinks as $i => $link) {
                            $lines[] = ($i + 1) . '. ' . $link['listing'] . "\n   " . $link['url'];
                        }
                        $resetUrlText = implode("\n\n", $lines);
                    }

                    $emailRepo = new EmailTemplateRepo($this->app->getDatabase());
                    $tokens    = EmailTemplateRepo::buildKimTokens(
                        listingName: $siteName,
                        listingUrl:  (string) $this->app->getConfig()->get('site_url', ''),
                        siteName:    $siteName,
                        ownerName:   $ownerName,
                        ownerEmail:  $ownerEmail,
                        kimName:     $recipientName,
                        kimEmail:    $email,
                        resetUrl:    $resetUrlText,
                    );
                    [$subject, $body] = $emailRepo->resolveForListing(null, EmailTemplateRepo::TYPE_KIM_RESET, $tokens);

                    $mailer = new Mailer($config);
                    $mailer->send($email, $recipientName, $subject, $body);
                } catch (\Throwable $e) {
                    error_log('[KIM] Password reset email failed: ' . $e->getMessage());
                }
            }
        }

        // Always flash the same neutral message — never enumerate
        $session->remove('lstr_embed_return_to');
        $session->set('lstr_flash_success_html', SuccessRenderer::render('', 'reset-sent'));
        return Response::redirect($embedReturnTo !== '' ? $embedReturnTo : $fallbackUrl);
    }

    // -----------------------------------------------------------------------
    // GET /kim/reset/confirm
    // -----------------------------------------------------------------------

    /**
     * Validates the reset token from the query string and renders the new-password
     * form when the token is valid, or an inline error when it is expired or missing.
     */
    public function showConfirm(Request $request, array $params): Response
    {
        $rawToken = trim((string) $request->query('token', ''));

        if ($rawToken === '') {
            return $this->renderConfirmForm('', false, 'Missing or invalid reset link.');
        }

        $resetRepo = new KimPasswordResetRepo($this->app->getDatabase());
        $tokenRow  = $resetRepo->findValidByToken(hash('sha256', $rawToken));

        if ($tokenRow === null) {
            return $this->renderConfirmForm(
                '',
                false,
                'This reset link has expired or is invalid. Please request a new one.',
            );
        }

        return $this->renderConfirmForm($rawToken, true);
    }

    // -----------------------------------------------------------------------
    // POST /kim/reset/confirm
    // -----------------------------------------------------------------------

    /**
     * Validates the token and new password, then applies the password change
     * and consumes the reset token so it cannot be reused.
     */
    public function submitConfirm(Request $request, array $params): Response
    {
        $baseUrl       = $this->app->getBaseUrl();
        $session       = $this->app->getSession();
        $rateLimit     = $this->app->getRateLimit();
        $ip            = $request->ip();
        $embedReturnTo = (string) $session->get('lstr_embed_return_to', '');
        $fallbackUrl   = $baseUrl . '/kim/reset';

        if ($rateLimit->isBlocked($ip, 'kim_reset')) {
            $session->set('lstr_flash_error', 'Too many attempts. Please try again later.');
            return Response::redirect($embedReturnTo !== '' ? $embedReturnTo : $fallbackUrl);
        }

        $rawToken = trim((string) $request->post('token', ''));

        if ($rawToken === '') {
            if ($embedReturnTo !== '') {
                $session->set('lstr_flash_error', 'Missing token. Please use the link from your email.');
                return Response::redirect($embedReturnTo);
            }
            return $this->renderConfirmForm('', false, 'Missing token. Please use the link from your email.');
        }

        $resetRepo = new KimPasswordResetRepo($this->app->getDatabase());
        $tokenRow  = $resetRepo->findValidByToken(hash('sha256', $rawToken));

        if ($tokenRow === null) {
            $rateLimit->hit($ip, 'kim_reset', 5, 3600);
            if ($embedReturnTo !== '') {
                $session->set('lstr_flash_error', 'This reset link has expired or is invalid. Please request a new one.');
                return Response::redirect($embedReturnTo);
            }
            return $this->renderConfirmForm(
                '',
                false,
                'This reset link has expired or is invalid. Please request a new one.',
            );
        }

        $newPassword        = (string) $request->post('new_password',         '');
        $newPasswordConfirm = (string) $request->post('new_password_confirm', '');

        $v = new Validator(['new_password' => $newPassword]);
        PasswordPolicy::apply($v->field('new_password'));

        if ($v->fails()) {
            if ($embedReturnTo !== '') {
                $sep = str_contains($embedReturnTo, '?') ? '&' : '?';
                $session->set('lstr_flash_error', implode(' ', $v->allErrors()));
                return Response::redirect($embedReturnTo . $sep . 'token=' . urlencode($rawToken));
            }
            return $this->renderConfirmForm($rawToken, true, implode(' ', $v->allErrors()));
        }

        if ($newPassword !== $newPasswordConfirm) {
            if ($embedReturnTo !== '') {
                $sep = str_contains($embedReturnTo, '?') ? '&' : '?';
                $session->set('lstr_flash_error', 'Passwords do not match. Please try again.');
                return Response::redirect($embedReturnTo . $sep . 'token=' . urlencode($rawToken));
            }
            return $this->renderConfirmForm($rawToken, true, 'Passwords do not match. Please try again.');
        }

        $kimMemberId = (int) $tokenRow['kim_member_id'];
        $kimRepo     = new KimMemberRepo($this->app->getDatabase());

        $kimRepo->update($kimMemberId, [
            'password' => password_hash($newPassword, PASSWORD_ARGON2ID),
        ]);

        $resetRepo->deleteByToken(hash('sha256', $rawToken));
        $rateLimit->clear($ip, 'kim_reset');

        $session->remove('lstr_embed_return_to');
        $session->set('lstr_flash_success_html', SuccessRenderer::render('', 'kim-reset-done'));
        return Response::redirect($embedReturnTo !== '' ? $embedReturnTo : $fallbackUrl);
    }

    // -----------------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------------

    /**
     * Builds and returns the email-entry form response.
     *
     * Uses the admin-defined kim_reset_form_template when available,
     * otherwise falls back to the default fragment template.
     */
    private function renderResetForm(
        string $oldEmail = '',
        string $error = '',
        string $successHtml = '',
    ): Response {
        $baseUrl  = $this->app->getBaseUrl();
        $siteRepo = new SiteSettingsRepo($this->app->getDatabase());

        $f = $this->app->getFormFieldBuilder()->build([
            ['type' => 'email',  'name' => 'email',          'id' => 'kim-email',      'label' => 'Email',                  'required' => true, 'value' => $oldEmail, 'attrs' => ['maxlength' => '255', 'autocomplete' => 'email']],
        ], '');

        $formHtml = null;
        $resetTpl = $siteRepo->get('kim_reset_form_template') ?? '';
        if ($resetTpl !== '') {
            $tpl = TemplateEngine::conditionals($resetTpl, [
                'step_email'    => true,
                'step_confirm'  => false,
                'token_valid'   => false,
                'token_invalid' => false,
            ]);
            $tokens = [
                '{site_name}'            => View::e((string) $this->app->getConfig()->get('site_name', 'Collective')),
                '{field_email}'          => $f['email'],
                '{field_email_input}'    => $f['email_input'],
                '{submit_button}'        => '<button type="submit" class="fl-submit">Send Reset Link</button>',
                '{clear_button}'         => '<button type="reset" class="fl-btn-secondary">Clear</button>',
                '{reset_request_url}'    => View::e($baseUrl . '/kim/reset'),
            ];
            $innerHtml = FormTemplateRenderer::expandTokens($tpl, $tokens);
            $formHtml  = FormTemplateRenderer::wrapForm($innerHtml, $baseUrl . '/kim/reset', $this->app->getCSRF()->token(), false, formMarker: 'kim_reset');
        }

        return $this->renderKimPage('reset.php', [
            'page_title'   => 'KIM List — Reset Password',
            'form_action'  => $baseUrl . '/kim/reset',
            'csrf_token'   => $this->app->getCSRF()->token(),
            'errors'       => $error !== ''
                ? '<p>' . htmlspecialchars($error, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>'
                : '',
            'f'            => $f,
            'form_html'    => $formHtml,
            'success_html' => $successHtml,
            'link_update'  => null,
            'base_url'     => $baseUrl,
            'theme_path'   => $baseUrl . '/assets/css/default/',
        ]);
    }

    /**
     * Builds and returns the new-password form (confirm step).
     *
     * When $tokenValid is false the form is hidden and an error message is shown
     * in its place. Uses the admin-defined kim_reset_form_template when available.
     */
    private function renderConfirmForm(
        string $rawToken,
        bool   $tokenValid,
        string $error = '',
    ): Response {
        $baseUrl  = $this->app->getBaseUrl();
        $siteRepo = new SiteSettingsRepo($this->app->getDatabase());

        $f = $this->app->getFormFieldBuilder()->build([
            ['type' => 'password', 'name' => 'new_password',         'id' => 'kim-new-password',         'label' => 'New Password',         'required' => true, 'attrs' => ['maxlength' => '255', 'autocomplete' => 'new-password'], 'hint' => 'Minimum 12 characters.'],
            ['type' => 'password', 'name' => 'new_password_confirm', 'id' => 'kim-new-password-confirm', 'label' => 'Confirm New Password', 'required' => true, 'attrs' => ['maxlength' => '255', 'autocomplete' => 'new-password']],
        ], '');

        $formHtml = null;
        $resetTpl = $siteRepo->get('kim_reset_form_template') ?? '';
        if ($resetTpl !== '') {
            $tpl = TemplateEngine::conditionals($resetTpl, [
                'step_email'    => false,
                'step_confirm'  => true,
                'token_valid'   => $tokenValid,
                'token_invalid' => !$tokenValid,
            ]);
            $tokens = [
                '{site_name}'            => View::e((string) $this->app->getConfig()->get('site_name', 'Collective')),
                '{submit_button}'        => '<button type="submit" class="fl-submit">Set New Password</button>',
                '{clear_button}'         => '<button type="reset" class="fl-btn-secondary">Clear</button>',
                '{reset_request_url}'    => View::e($baseUrl . '/kim/reset'),
                '{field_password}'       => $f['new_password'],
                '{field_password_input}' => $f['new_password_input'],
                '{field_password_confirm}'       => $f['new_password_confirm'],
                '{field_password_confirm_input}' => $f['new_password_confirm_input'],
            ];
            $innerHtml = FormTemplateRenderer::expandTokens($tpl, $tokens);
            if ($rawToken !== '') {
                $innerHtml .= '<input type="hidden" name="token" value="' . View::e($rawToken) . '">';
            }
            $formHtml = FormTemplateRenderer::wrapForm($innerHtml, $baseUrl . '/kim/reset/confirm', $this->app->getCSRF()->token(), false, formMarker: 'kim_reset');
        }

        return $this->renderKimPage('reset-confirm.php', [
            'page_title'  => 'KIM — Set New Password',
            'form_action' => $baseUrl . '/kim/reset/confirm',
            'csrf_token'  => $this->app->getCSRF()->token(),
            'errors'      => $error !== ''
                ? '<p>' . htmlspecialchars($error, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>'
                : '',
            'token'       => $rawToken,
            'token_valid' => $tokenValid,
            'f'           => $f,
            'form_html'   => $formHtml,
            'base_url'    => $baseUrl,
            'theme_path'  => $baseUrl . '/assets/css/default/',
        ]);
    }

    /**
     * Wraps a KIM fragment template in the public layout and returns the response.
     *
     * Falls back to rendering the fragment alone when the layout file does not exist.
     */
    private function renderKimPage(string $template, array $data): Response
    {
        $view    = $this->app->getView();
        $baseUrl = $this->app->getBaseUrl();
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
}
