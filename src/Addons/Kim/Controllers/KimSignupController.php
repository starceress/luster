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
use Luster\Repositories\EmailTemplateRepo;
use Luster\Repositories\ListingRepo;
use Luster\Repositories\ListingSettingsRepo;
use Luster\Repositories\SiteSettingsRepo;
use Luster\Support\SuccessRenderer;

/**
 * Public KIM sign-up form.
 *
 * POST /kim  — validates, inserts, sends confirmation, redirects to host page
 */
final class KimSignupController
{
    public function __construct(private readonly Application $app) {}

    // -----------------------------------------------------------------------
    // POST /kim
    // -----------------------------------------------------------------------

    /**
     * Processes the KIM sign-up form.
     *
     * Applies rate limiting, honeypot, captcha, Validator, blacklist, and
     * duplicate checks. On success inserts the pending member, sends a
     * member confirmation email with a password setup link, and sends an
     * admin notification email.
     */
    public function submit(Request $request, array $params): Response
    {
        $baseUrl       = $this->app->getBaseUrl();
        $session       = $this->app->getSession();
        $rateLimit     = $this->app->getRateLimit();
        $ip            = $request->ip();
        $embedReturnTo = (string) $session->get('lstr_embed_return_to', '');
        $siteUrl       = (string) $this->app->getConfig()->get('site_url', '');
        $fallbackUrl   = \Luster\Support\CollectiveFormUrlResolver::resolve(
            (new SiteSettingsRepo($this->app->getDatabase()))->get('kim_url_signup') ?: 'kim.php',
            $siteUrl !== '' ? $siteUrl : $baseUrl,
            $siteUrl !== '' ? $siteUrl : $baseUrl,
        );

        // Rate limit check
        if ($rateLimit->isBlocked($ip, 'kim_signup')) {
            $session->set('lstr_flash_error:kim_signup', 'Too many attempts. Please try again later.');
            return Response::redirect($embedReturnTo !== '' ? $embedReturnTo : $fallbackUrl);
        }

        // Honeypot — silent reject; fake success so bots see no difference
        if ((string) $request->post('website_confirm', '') !== '') {
            $session->remove('lstr_embed_return_to');
            $session->set('lstr_flash_success_html:kim_signup', SuccessRenderer::render('', 'kim-signed-up'));
            return Response::redirect($embedReturnTo !== '' ? $embedReturnTo : $fallbackUrl);
        }

        // CAPTCHA
        if (!Captcha::validate((string) $request->post('captcha_answer', ''), 'kim_signup')) {
            $rateLimit->hit($ip, 'kim_signup', 5, 3600);
            $session->set('lstr_flash_error:kim_signup', 'Incorrect answer — please try again.');
            $session->set('lstr_flash_old:kim_signup', [
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
            $session->set('lstr_flash_error:kim_signup', implode(' ', $v->allErrors()) ?: 'Please select a valid fanlisting.');
            $session->set('lstr_flash_old:kim_signup', $oldFields);
            return Response::redirect($embedReturnTo !== '' ? $embedReturnTo : $fallbackUrl);
        }

        // Blacklist check
        if ((new \Luster\Repositories\BlacklistRepo($this->app->getDatabase()))->isBlocked($email)) {
            $session->set('lstr_flash_error:kim_signup', 'That email address cannot be used to sign up.');
            $session->set('lstr_flash_old:kim_signup', $oldFields);
            return Response::redirect($embedReturnTo !== '' ? $embedReturnTo : $fallbackUrl);
        }

        $kimRepo = new KimMemberRepo($this->app->getDatabase());

        // Duplicate check
        if ($kimRepo->findByEmailAndFanlisting($email, $fanlistingId) !== null) {
            $session->set('lstr_flash_error:kim_signup', 'That email is already signed up for this fanlisting.');
            $session->set('lstr_flash_old:kim_signup', $oldFields);
            return Response::redirect($embedReturnTo !== '' ? $embedReturnTo : $fallbackUrl);
        }

        // Password — use the supplied value or auto-generate a secure one
        $rawPassword     = (string) $request->post('password',         '');
        $passwordConfirm = (string) $request->post('password_confirm', '');

        if ($rawPassword !== '' && $rawPassword !== $passwordConfirm) {
            $session->set('lstr_flash_error:kim_signup', 'Passwords do not match. Please try again.');
            $session->set('lstr_flash_old:kim_signup', $oldFields);
            return Response::redirect($embedReturnTo !== '' ? $embedReturnTo : $fallbackUrl);
        }

        if ($rawPassword !== '') {
            $passwordValidator = new Validator(['password' => $rawPassword]);
            PasswordPolicy::apply($passwordValidator->field('password'));
            if ($passwordValidator->fails()) {
                $session->set('lstr_flash_error:kim_signup', implode(' ', $passwordValidator->allErrors()));
                $session->set('lstr_flash_old:kim_signup', $oldFields);
                return Response::redirect($embedReturnTo !== '' ? $embedReturnTo : $fallbackUrl);
            }
        }

        if ($rawPassword === '') {
            $rawPassword  = bin2hex(random_bytes(8));
        }

        $passwordHash = password_hash($rawPassword, PASSWORD_ARGON2ID);

        $newEntryId = $kimRepo->create($fanlistingId, $name, $email, $url, $passwordHash);
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
            $setupUrl  = $this->issuePasswordSetupUrl($newEntryId, $email);
            $emailRepo = new EmailTemplateRepo($this->app->getDatabase());
            $_kimSiteRepo = new SiteSettingsRepo($this->app->getDatabase());
            $_kimSiteUrl  = (string) $config->get('app_url', '');
            $tokens    = EmailTemplateRepo::buildKimTokens(
                listingName:    $listingName,
                listingUrl:     $listingUrl,
                siteName:       $siteName,
                ownerName:      $ownerName,
                ownerEmail:     $ownerEmail,
                kimName:        $name,
                kimEmail:       $email,
                kimUrl:         $url,
                resetUrl:       $setupUrl,
                listingSubject: $selectedListing->subject ?? '',
                listingType:    $selectedListing !== null
                    ? (new ListingSettingsRepo($this->app->getDatabase()))->getListingType((int) $selectedListing->id)
                    : 'listing',
                kimPassword:    $rawPassword,
                kimUpdateUrl:   \Luster\Support\CollectiveFormUrlResolver::resolve(
                    $_kimSiteRepo->get('kim_url_update') ?: 'kim-update.php',
                    ((string) $config->get('site_url', '')) ?: (string) $config->get('app_url', ''),
                    ((string) $config->get('site_url', '')) ?: (string) $config->get('app_url', ''),
                ),
            );
            [$subject, $body] = $emailRepo->resolveForListing(null, EmailTemplateRepo::TYPE_KIM_SIGNUP, $tokens);
            // No setup link means the entry moved or went away before this could be sent.
            // The signup still stands, but this mail has the password in it, and that
            // address doesn't own the entry any more.
            if ($subject !== '' && $setupUrl !== '') {
                (new Mailer($config))->send($email, $name, $subject, $body);
            }
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
                    listingName    : $selectedListing?->title ?? '',
                    listingUrl     : $selectedListing?->url   ?? '',
                    siteName       : (string) $config->get('site_name',   ''),
                    ownerName      : (string) $config->get('owner_name',  ''),
                    ownerEmail     : (string) $config->get('owner_email', ''),
                    kimName        : $name,
                    kimEmail       : $email,
                    kimUrl         : $url,
                    listingSubject : $selectedListing?->subject ?? '',
                    listingType    : $selectedListing !== null
                        ? (new ListingSettingsRepo($this->app->getDatabase()))->getListingType((int) $selectedListing->id)
                        : 'listing',
                    kimUpdateUrl   : \Luster\Support\CollectiveFormUrlResolver::resolve(
                        (new SiteSettingsRepo($this->app->getDatabase()))->get('kim_url_update') ?: 'kim-update.php',
                        ((string) $config->get('site_url', '')) ?: (string) $config->get('app_url', ''),
                        ((string) $config->get('site_url', '')) ?: (string) $config->get('app_url', ''),
                    ),
                );
                [$notifySubject, $notifyBody] = $emailRepo->resolveForListing(
                    $fanlistingId,
                    EmailTemplateRepo::TYPE_KIM_APPLIED,
                    $tokens,
                );
                if ($notifySubject !== '') {
                    (new Mailer($config))->send(
                        $adminTo,
                        (string) $config->get('owner_name', ''),
                        $notifySubject,
                        $notifyBody,
                    );
                }
            }
        } catch (\Throwable) {
            // Email failures are non-fatal
        }

        $session->remove('lstr_embed_return_to');
        $session->set('lstr_flash_success_html:kim_signup', SuccessRenderer::render('', 'kim-signed-up'));
        return Response::redirect($embedReturnTo !== '' ? $embedReturnTo : $fallbackUrl);
    }

    // -----------------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------------

    /**
     * Creates a one-hour password setup token for a freshly registered KIM member
     * and returns the full reset URL to include in the welcome email.
     *
     * Prefers the admin-configured KIM reset page URL (kim_url_reset) so the
     * link lands on the correct host-page embed, not Luster's own routes.
     * Returns an empty string if the entry has been moved or removed since it was
     * created, because this link is going to the address it was created with. The
     * welcome mail isn't sent then either.
     */
    private function issuePasswordSetupUrl(int $memberId, string $email): string
    {
        $rawToken = (new KimPasswordResetRepo($this->app->getDatabase()))->issueLocked($memberId, $email, 3600);
        if ($rawToken === null) {
            return '';
        }

        $siteRepo       = new SiteSettingsRepo($this->app->getDatabase());
        $siteUrl        = (string) $this->app->getConfig()->get('site_url', '');
        $kimResetPage   = $siteRepo->get('kim_url_reset') ?: 'kim-reset.php';
        $siteUrlOrBase  = $siteUrl !== '' ? $siteUrl : $this->app->getBaseUrl();
        $baseResetUrl   = \Luster\Support\CollectiveFormUrlResolver::resolve(
            $kimResetPage,
            $siteUrlOrBase,
            $siteUrlOrBase,
        );

        $sep = str_contains($baseResetUrl, '?') ? '&' : '?';
        return $baseResetUrl . $sep . 'token=' . urlencode($rawToken);
    }
}
