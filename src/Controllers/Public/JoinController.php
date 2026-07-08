<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Handles the public join form where fans sign up to be members of a listing.
 On GET, it renders the form with CAPTCHA, country selector, and any additional
 fields configured for the listing. On POST, it validates all input (including
 honeypot, rate limiting, blacklist, and duplicate checks), inserts the new
 member, sends confirmation and admin notification emails, and redirects back.
 Also integrates with the Rules add-on gate when installed.
*/

declare(strict_types=1);

namespace Luster\Controllers\Public;

use Luster\Core\Application;
use Luster\Core\Captcha;
use Luster\Core\Countries;
use Luster\Core\Mailer;
use Luster\Core\PasswordPolicy;
use Luster\Core\RateLimit;
use Luster\Core\Request;
use Luster\Core\Response;
use Luster\Core\Validator;
use Luster\Core\View;
use Luster\Models\Listing;
use Luster\Repositories\EmailTemplateRepo;
use Luster\Repositories\FanPasswordResetRepo;
use Luster\Repositories\ListingRepo;
use Luster\Repositories\ListingSettingsRepo;
use Luster\Repositories\FieldRepo;
use Luster\Repositories\BlacklistRepo;
use Luster\Repositories\MemberLinkCheckRepo;
use Luster\Repositories\MemberRepo;
use Luster\Repositories\SiteSettingsRepo;
use Luster\Support\ListingFormUrlResolver;
use Luster\Support\SuccessRenderer;

/**
 * Public join form.
 *
 * GET  /join/{id} — renders the join form for a listing.
 * POST /join/{id} — validates input, checks for duplicates, inserts member,
 *                   sends confirmation email, redirects to host page.
 *
 * Security properties:
 *  - CSRF token validated by CsrfMiddleware before this action runs
 *  - Honeypot field silently rejects bot submissions
 *  - Arithmetic CAPTCHA prevents automated submissions
 *  - Rate-limited per IP (10 submissions per hour) via lstr_rate_limits
 *  - All input validated before any DB write
 *  - Duplicate email checked before insert
 *  - Additional field names resolved only from FieldRepo (never user-supplied)
 *  - Member password auto-generated if not supplied; only the hash is stored in DB
 */
final class JoinController
{
    public function __construct(private readonly Application $app) {}

    // -----------------------------------------------------------------------
    // GET /join/{id}
    // -----------------------------------------------------------------------

    // -----------------------------------------------------------------------
    // POST /join/{id}
    // -----------------------------------------------------------------------

    /**
     * Processes a join form submission.
     *
     * Runs honeypot, rate-limit, CAPTCHA, blacklist, and duplicate-email checks
     * before inserting the new member. Sends a confirmation email (and an admin
     * notification if the listing has an email address). On auto-approve listings
     * the member is active immediately; otherwise they land in the pending queue.
     */
    public function submit(Request $request, array $params): Response
    {
        $listingId = (int) ($params['id'] ?? 0);
        $baseUrl      = $this->app->getBaseUrl();
        $session      = $this->app->getSession();
        $rateLimit    = $this->app->getRateLimit();
        $ip           = $request->ip();
        $embedReturnTo = (string) $session->get('lstr_embed_return_to', '');
        $marker        = (string) $request->post('_luster_embed_form', '');

        $listingRepo = new ListingRepo($this->app->getDatabase(), $this->app->getConfig());
        $listing     = $listingRepo->getById($listingId);

        if ($listing === null) {
            return Response::notFound('That listing does not exist.');
        }

        $fieldRepo       = new FieldRepo($this->app->getDatabase(), $this->app->getConfig());
        $fields          = $fieldRepo->getFieldsForListing($listingId);
        $settingsRepo    = new ListingSettingsRepo($this->app->getDatabase());
        $embedSettings   = $settingsRepo->getResolvedEmbedSettings($listingId);
        $globalDefaults  = $settingsRepo->getGlobalFormUrlDefaults();

        $formUrl = ListingFormUrlResolver::resolve(
            (string) $embedSettings['form_url_join'],
            $listing->url,
            $globalDefaults['form_url_join'],
        );

        // Archived listings (Closed/Adopted Out) no longer accept new joins.
        if ((new \Luster\Repositories\ListingLifecycleRepo($this->app->getDatabase()))->getByListingId($listingId) !== null) {
            $session->set('lstr_flash_error:' . $marker, 'This fanlisting is no longer accepting new members.');
            return Response::redirect($embedReturnTo !== '' ? $embedReturnTo : $formUrl);
        }

        // Rate limiting
        if ($rateLimit->isBlocked($ip, RateLimit::ACTION_PUBLIC_JOIN)) {
            $session->set('lstr_flash_error:' . $marker, 'Too many join attempts. Please try again later.');
            return Response::redirect($embedReturnTo !== '' ? $embedReturnTo : $formUrl);
        }

        // Honeypot — silent reject; fake success so bots see no difference
        if ((string) $request->post('website_confirm', '') !== '') {
            $session->remove('lstr_embed_return_to');
            $session->set('lstr_flash_success_html:' . $marker, SuccessRenderer::render('', 'joined'));
            return Response::redirect($embedReturnTo !== '' ? $embedReturnTo : $formUrl);
        }

        // Rules gate enforcement — catches direct POSTs that bypass the embed gate.
        if ($this->app->isAddonInstalled('Rules') && !$this->rulesAgreed($listingId)) {
            $session->set('lstr_embed_return_to', $embedReturnTo !== '' ? $embedReturnTo : $formUrl);
            return Response::redirect($embedReturnTo !== '' ? $embedReturnTo : $formUrl);
        }

        // Captcha — redirect with flash on failure so the form re-renders with a fresh question
        if (!Captcha::validate((string) $request->post('captcha_answer', ''), 'join')) {
            $rateLimit->hitDefault($ip, RateLimit::ACTION_PUBLIC_JOIN);
            $session->set('lstr_flash_error:' . $marker, 'Incorrect answer — please try again.');
            $session->set('lstr_flash_old:' . $marker, [
                'name'      => trim((string) $request->post('name',    '')),
                'email'     => trim((string) $request->post('email',   '')),
                'url'       => trim((string) $request->post('url',     '')),
                'country'   => trim((string) $request->post('country', '')),
                'send_info' => (string) $request->post('send_info', ''),
            ]);
            return Response::redirect($embedReturnTo !== '' ? $embedReturnTo : $formUrl);
        }

        // Collect and validate posted data
        $name    = trim((string) $request->post('name',    ''));
        $email   = trim((string) $request->post('email',   ''));
        $url     = trim((string) $request->post('url',     ''));
        $country = trim((string) $request->post('country', ''));

        $postData = $request->postAll();

        $v = new Validator($postData);
        $v->field('name')->required()->maxLength(100);
        $v->field('email')->required()->email()->maxLength(255);
        $v->field('url')->httpUrl()->maxLength(500);
        $countryFieldEnabled = (bool) $embedSettings['country_field_enabled'];
        $countryField = $v->field('country')->maxLength(100);
        if ($countryFieldEnabled) {
            $countryField->required();
        }

        foreach ($fields as $field) {
            $vf = $v->field('field_' . $field->name)->maxLength(255);
            if ($field->required) {
                $vf->required();
            }
        }

        if ($v->fails()) {
            $rateLimit->hitDefault($ip, RateLimit::ACTION_PUBLIC_JOIN);
            $session->set('lstr_flash_error:' . $marker, implode(' ', $v->allErrors()));
            $session->set('lstr_flash_old:' . $marker, [
                'name'      => $name,
                'email'     => $email,
                'url'       => $url,
                'country'   => $country,
                'send_info' => (string) $request->post('send_info', ''),
            ]);
            return Response::redirect($embedReturnTo !== '' ? $embedReturnTo : $formUrl);
        }

        $memberRepo = new MemberRepo($this->app->getDatabase(), $this->app->getConfig());

        // Blacklist check
        if ((new BlacklistRepo($this->app->getDatabase()))->isBlocked($email)) {
            $session->set('lstr_flash_error:' . $marker, 'That email address cannot be used to join.');
            $session->set('lstr_flash_old:' . $marker, [
                'name'      => $name,
                'email'     => $email,
                'url'       => $url,
                'country'   => $country,
                'send_info' => (string) $request->post('send_info', ''),
            ]);
            return Response::redirect($embedReturnTo !== '' ? $embedReturnTo : $formUrl);
        }

        // Duplicate email check
        if ($memberRepo->getByEmail($listing->dbprefix, $email) !== null) {
            $rateLimit->hitDefault($ip, RateLimit::ACTION_PUBLIC_JOIN);
            $session->set('lstr_flash_error:' . $marker, 'That email address is already registered for this listing.');
            $session->set('lstr_flash_old:' . $marker, [
                'name'      => $name,
                'email'     => $email,
                'url'       => $url,
                'country'   => $country,
                'send_info' => (string) $request->post('send_info', ''),
            ]);
            return Response::redirect($embedReturnTo !== '' ? $embedReturnTo : $formUrl);
        }

        // Password — use the supplied value or auto-generate a secure one
        $rawPassword     = (string) $request->post('password',         '');
        $passwordConfirm = (string) $request->post('password_confirm', '');

        if ($rawPassword !== '' && $rawPassword !== $passwordConfirm) {
            $session->set('lstr_flash_error:' . $marker, 'Passwords do not match. Please try again.');
            $session->set('lstr_flash_old:' . $marker, [
                'name'      => $name,
                'email'     => $email,
                'url'       => $url,
                'country'   => $country,
                'send_info' => (string) $request->post('send_info', ''),
            ]);
            return Response::redirect($embedReturnTo !== '' ? $embedReturnTo : $formUrl);
        }

        if ($rawPassword !== '') {
            $passwordValidator = new Validator(['password' => $rawPassword]);
            PasswordPolicy::apply($passwordValidator->field('password'));
            if ($passwordValidator->fails()) {
                $session->set('lstr_flash_error:' . $marker, implode(' ', $passwordValidator->allErrors()));
                $session->set('lstr_flash_old:' . $marker, [
                    'name'      => $name,
                    'email'     => $email,
                    'url'       => $url,
                    'country'   => $country,
                    'send_info' => (string) $request->post('send_info', ''),
                ]);
                return Response::redirect($embedReturnTo !== '' ? $embedReturnTo : $formUrl);
            }
        }

        if ($rawPassword === '') {
            $rawPassword  = bin2hex(random_bytes(8));
            $wasGenerated = true;
        } else {
            $wasGenerated = false;
        }
        $passwordHash = password_hash($rawPassword, PASSWORD_ARGON2ID);

        // Build insert data
        $insertData = [
            'name'     => $name,
            'email'    => $email,
            'url'      => $url,
            'country'  => $country,
            'showurl'  => $url !== '' ? 1 : 0,
            'password' => $passwordHash,
            'added'    => date('Y-m-d H:i:s'),
        ];

        // Per-listing join settings (auto-approve + email override).
        $joinSettings = $settingsRepo->getJoinSettings($listingId);
        $autoApprove  = $joinSettings['auto_approve'];

        // Determine approved/pending column based on schema variant.
        // Default: new members are pending (manual approval required).
        // Per-listing auto_approve opts this listing into immediate approval.
        $prefix = (string) $this->app->getConfig()->get('enth_table_prefix', '');

        if ($prefix === '') {
            // Unprefixed schema: pending=0 = approved, pending=1 = queued
            $insertData['pending'] = $autoApprove ? 0 : 1;
        } else {
            // Standard schema: approved=1 = approved, approved=0 = pending
            $insertData['approved'] = $autoApprove ? 1 : 0;
            $insertData['updated']  = date('Y-m-d H:i:s');
        }

        // Additional fields — names from FieldRepo only, never raw user input.
        // Checkbox fields post as arrays; implode to a comma-separated string.
        foreach ($fields as $field) {
            $colName   = ($prefix === '') ? $field->name : 'additional_' . $field->name;
            $raw       = $postData['field_' . $field->name] ?? '';
            $insertData[$colName] = is_array($raw)
                ? implode(', ', array_map('trim', $raw))
                : trim((string) $raw);
        }

        $memberRepo->insert($listing->dbprefix, $insertData);
        $rateLimit->clear($ip, RateLimit::ACTION_PUBLIC_JOIN);
        // Only reset the stale clock when the join is immediately approved.
        // Pending joins wait for admin approval, which will touch it via MemberController.
        if ($autoApprove) {
            $settingsRepo->touchLastUpdated($listingId);
            // Sync member link check snapshot for auto-approved joins (best-effort)
            if ($url !== '') {
                try {
                    (new MemberLinkCheckRepo($this->app->getDatabase(), $this->app->getConfig()))
                        ->upsertMember($listingId, $email, $name, $url);
                } catch (\Throwable) {}
            }
        }

        // Confirmation email always sent (best-effort — do not abort on failure)
        {
            try {
                $cfg       = $this->app->getConfig();
                $mailer    = new Mailer($cfg);
                $emailRepo = new EmailTemplateRepo($this->app->getDatabase());
                $resetUrl  = $autoApprove
                    ? $this->issuePasswordSetupUrl($listingId, $email)
                    : ListingFormUrlResolver::resolve(
                        (string) ($embedSettings['form_url_reset'] ?? ''),
                        $listing->url,
                        $globalDefaults['form_url_reset'],
                    );
                $tokens    = EmailTemplateRepo::buildTokens(
                    listingName:    $listing->title,
                    listingUrl:     $listing->url,
                    siteName:       (string) $cfg->get('site_name', 'Luster'),
                    listingSubject: $listing->subject,
                    ownerName:      (string) $cfg->get('owner_name', ''),
                    ownerEmail:     (string) $cfg->get('owner_email', ''),
                    fanName:        $name,
                    fanEmail:       $email,
                    fanUrl:         $url,
                    fanCountry:     $country,
                    resetUrl:       $resetUrl,
                    fanPassword:    $rawPassword,
                    listingEmail:   $listing->email,
                    formUrlJoin:    (string) ($embedSettings['form_url_join']   ?: $globalDefaults['form_url_join']),
                    formUrlUpdate:  (string) ($embedSettings['form_url_update'] ?: $globalDefaults['form_url_update']),
                    formUrlReset:   (string) ($embedSettings['form_url_reset']  ?: $globalDefaults['form_url_reset']),
                    listingType:    $settingsRepo->getListingType($listingId),
                );
                [$subject, $body] = $emailRepo->resolveForListing($listingId, EmailTemplateRepo::TYPE_JOIN, $tokens);
                if ($subject !== '') {
                    $mailer->send($email, $name, $subject, $body);
                }

                if ($wasGenerated && $resetUrl !== '') {
                    [$resetSubject, $resetBody] = $emailRepo->resolveForListing(
                        $listingId,
                        EmailTemplateRepo::TYPE_RESET,
                        $tokens,
                    );
                    if ($resetSubject !== '') {
                        $mailer->send($email, $name, $resetSubject, $resetBody);
                    }
                }
            } catch (\Throwable $e) {
                error_log('[Luster] Join email failed: ' . $e->getMessage());
            }
        }

        // Admin notification email (best-effort — only when listing has an email address)
        if ($listing->email !== '') {
            try {
                $cfg          = $this->app->getConfig();
                $notifyMailer = new Mailer($cfg);
                $notifyRepo   = new EmailTemplateRepo($this->app->getDatabase());
                $notifyTokens = EmailTemplateRepo::buildTokens(
                    listingName:    $listing->title,
                    listingUrl:     $listing->url,
                    siteName:       (string) $cfg->get('site_name', 'Luster'),
                    listingSubject: $listing->subject,
                    ownerName:      (string) $cfg->get('owner_name', ''),
                    ownerEmail:     (string) $cfg->get('owner_email', ''),
                    fanName:        $name,
                    fanEmail:       $email,
                    fanUrl:         $url,
                    fanCountry:     $country,
                    listingEmail:   $listing->email,
                    formUrlJoin:    (string) ($embedSettings['form_url_join']   ?: $globalDefaults['form_url_join']),
                    formUrlUpdate:  (string) ($embedSettings['form_url_update'] ?: $globalDefaults['form_url_update']),
                    formUrlReset:   (string) ($embedSettings['form_url_reset']  ?: $globalDefaults['form_url_reset']),
                    listingType:    $settingsRepo->getListingType($listingId),
                );
                [$notifySubject, $notifyBody] = $notifyRepo->resolveForListing(
                    $listingId, EmailTemplateRepo::TYPE_NOTIFY_JOIN, $notifyTokens,
                );
                if ($notifySubject !== '') {
                    $notifyMailer->send($listing->email, $listing->title, $notifySubject, $notifyBody);
                }
            } catch (\Throwable $e) {
                error_log('[Luster] Join notification email failed: ' . $e->getMessage());
            }
        }

        $session->remove('lstr_embed_return_to');
        $successTpl = (new ListingSettingsRepo($this->app->getDatabase()))->getSuccessTemplate($listingId) ?? '';
        $action     = $autoApprove ? 'joined' : 'joined-pending';
        $session->set('lstr_flash_success_html:' . $marker, SuccessRenderer::render($successTpl, $action, [
            'fan_name'      => $name,
            'listing_title' => $listing->title,
            'listing_url'   => $listing->url,
        ]));
        return Response::redirect($embedReturnTo !== '' ? $embedReturnTo : $formUrl);
    }

    // -----------------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------------

    /**
     * Creates a one-time password-setup token for a newly auto-approved member
     * and returns the full URL to the confirm form with the raw token in the query string.
     *
     * Called only for auto-approved joins where the password was auto-generated —
     * the URL is embedded in both the join confirmation email and a separate reset email
     * so the fan can choose their own password before their first update.
     */
    private function issuePasswordSetupUrl(int $listingId, string $email): string
    {
        $resetRepo = new FanPasswordResetRepo($this->app->getDatabase());
        $resetRepo->deleteByEmail($listingId, $email);

        $rawToken  = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $rawToken);
        $expiresAt = (new \DateTimeImmutable())
            ->modify('+3600 seconds')
            ->format('Y-m-d H:i:s');

        $resetRepo->create($listingId, $email, $tokenHash, $expiresAt);

        $settingsRepo    = new ListingSettingsRepo($this->app->getDatabase());
        $embedSettings   = $settingsRepo->getResolvedEmbedSettings($listingId);
        $globalDefaults  = $settingsRepo->getGlobalFormUrlDefaults();
        $listingRepo     = new ListingRepo($this->app->getDatabase(), $this->app->getConfig());
        $listing         = $listingRepo->getById($listingId);
        $resetPageUrl    = ListingFormUrlResolver::resolve(
            (string) $embedSettings['form_url_reset'],
            (string) ($listing->url ?? ''),
            $globalDefaults['form_url_reset'],
        );

        $sep = str_contains($resetPageUrl, '?') ? '&' : '?';
        return $resetPageUrl . $sep . 'token=' . urlencode($rawToken);
    }

    /**
     * Returns true if the fan has agreed to the current rules version for this
     * listing, or if no active rules exist.  Wrapped in try/catch so a missing
     * lstr_rules table (addon uninstalled mid-session) never blocks joining.
     */
    private function rulesAgreed(int $listingId): bool
    {
        try {
            $repo    = new \Luster\Addons\Rules\RulesRepo($this->app->getDatabase());
            $ruleSet = $repo->getForListing($listingId);
            if ($ruleSet === null || !$ruleSet->enabled || trim($ruleSet->body) === '') {
                return true;
            }
            $stored = (string) $this->app->getSession()->get('lstr_rules_agreed_' . $listingId, '');
            return $stored === $ruleSet->fingerprint();
        } catch (\Throwable) {
            return true;
        }
    }
}
