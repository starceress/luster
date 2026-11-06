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
use Luster\Core\SiteClock;
use Luster\Core\Validator;
use Luster\Core\View;
use Luster\Models\Listing;
use Luster\Repositories\EmailTemplateRepo;
use Luster\Repositories\ListingRepo;
use Luster\Repositories\ListingSettingsRepo;
use Luster\Repositories\FieldRepo;
use Luster\Repositories\BlacklistRepo;
use Luster\Repositories\MemberRepo;
use Luster\Repositories\SiteSettingsRepo;
use Luster\Support\FieldAnswers;
use Luster\Support\ListingFormUrlResolver;
use Luster\Support\MemberAddressBusy;
use Luster\Support\MemberAddressHandover;
use Luster\Support\MemberWidths;
use Luster\Support\OwnerNotice;
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
     * notification if the listing has an email address). Every new member lands
     * in the pending queue; an admin approves them from the Members page.
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

        // Everything below that sends the fan back to the form goes through refuse(), with
        // this, so what they typed is still there. Passwords aren't in it.
        $postData = $request->postAll();
        $old      = FieldAnswers::oldForm($postData, ['name', 'email', 'url', 'country', 'send_info'], $fields);
        $backTo   = $embedReturnTo !== '' ? $embedReturnTo : $formUrl;

        // Rate limiting
        if ($rateLimit->isBlocked($ip, RateLimit::ACTION_PUBLIC_JOIN)) {
            return $this->refuse($marker, 'Too many join attempts. Please try again later.', $old, $backTo);
        }

        // Honeypot — silent reject; fake success so bots see no difference
        if ((string) $request->post('website_confirm', '') !== '') {
            $session->remove('lstr_embed_return_to');
            $session->set('lstr_flash_success_html:' . $marker, SuccessRenderer::render('', 'joined-pending'));
            return Response::redirect($embedReturnTo !== '' ? $embedReturnTo : $formUrl);
        }

        // Count every real submission against the rate limit, not just invalid
        // ones - one call here covers every branch below (rules gate, captcha,
        // validation, blacklist, duplicate email, password checks, and
        // success) instead of scattering per-branch hits that both missed
        // some rejection paths and double-counted others.
        $rateLimit->hitDefault($ip, RateLimit::ACTION_PUBLIC_JOIN);

        // Rules gate enforcement — catches direct POSTs that bypass the embed gate.
        if ($this->app->isAddonInstalled('Rules') && !$this->rulesAgreed($listingId)) {
            $session->set('lstr_embed_return_to', $embedReturnTo !== '' ? $embedReturnTo : $formUrl);
            return Response::redirect($embedReturnTo !== '' ? $embedReturnTo : $formUrl);
        }

        // Captcha — redirect with flash on failure so the form re-renders with a fresh question
        if (!Captcha::validate((string) $request->post('captcha_answer', ''), 'join')) {
            return $this->refuse($marker, 'Incorrect answer — please try again.', $old, $backTo);
        }

        // Collect and validate posted data
        $name    = trim((string) $request->post('name',    ''));
        $email   = trim((string) $request->post('email',   ''));
        $url     = trim((string) $request->post('url',     ''));
        $country = trim((string) $request->post('country', ''));

        $v = new Validator($postData);
        $v->field('name')->required()->maxLength(100);
        $v->field('email')->required()->email()->maxLength(255);
        $v->field('url')->httpUrl()->maxLength(500);
        $countryFieldEnabled = (bool) $embedSettings['country_field_enabled'];
        $countryField = $v->field('country')->maxLength(100);
        if ($countryFieldEnabled) {
            $countryField->required();
        }

        // Additional fields — names from FieldRepo only, never raw user input. These
        // answers are what's checked here and what gets saved below.
        $answers = FieldAnswers::fromPost($fields, $postData, $v);

        if ($v->fails()) {
            return $this->refuse($marker, implode(' ', $v->allErrors()), $old, $backTo);
        }

        $memberRepo = new MemberRepo($this->app->getDatabase(), $this->app->getConfig());

        // Blacklist check
        if ((new BlacklistRepo($this->app->getDatabase()))->isBlocked($email)) {
            return $this->refuse($marker, 'That email address cannot be used to join.', $old, $backTo);
        }

        // Duplicate email check
        if ($memberRepo->getByEmail($listing->dbprefix, $email) !== null) {
            return $this->refuse($marker, 'That email address is already registered for this listing.', $old, $backTo);
        }

        $extraValues = FieldAnswers::forPublicForm(
            new FieldRepo($this->app->getDatabase(), $this->app->getConfig()),
            $listing,
            $fields,
            $answers,
            'Join',
        );
        if ($extraValues === null) {
            return $this->refuse($marker, FieldAnswers::PUBLIC_REFUSAL, $old, $backTo);
        }

        // Password — use the supplied value or auto-generate a secure one
        $rawPassword     = (string) $request->post('password',         '');
        $passwordConfirm = (string) $request->post('password_confirm', '');

        if ($rawPassword !== '' && $rawPassword !== $passwordConfirm) {
            return $this->refuse($marker, 'Passwords do not match. Please try again.', $old, $backTo);
        }

        if ($rawPassword !== '') {
            $passwordValidator = new Validator(['password' => $rawPassword]);
            PasswordPolicy::apply($passwordValidator->field('password'));
            if ($passwordValidator->fails()) {
                return $this->refuse($marker, implode(' ', $passwordValidator->allErrors()), $old, $backTo);
            }
        }

        if ($rawPassword === '') {
            $rawPassword = bin2hex(random_bytes(8));
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
            'added'    => SiteClock::now($this->app->getConfig()),
        ];

        // Every new join waits for approval: pending=1 or approved=0, depending on schema.
        $insertData = array_merge($insertData, $memberRepo->pendingJoinColumns());

        foreach ($extraValues as $column => $value) {
            $insertData[$column] = $value;
        }

        // Real member tables are often narrower than this form's own limits.
        $problem = MemberWidths::publicProblem($memberRepo, $listing, $insertData, $fields, 'Join');
        if ($problem !== null) {
            return $this->refuse($marker, $problem, $old, $backTo);
        }

        // Checked again under the address's lock, which also keeps a save still in
        // progress for whoever had this address before from landing on this member.
        try {
            $inserted = $this->app->getMemberAddressLock()->run(
                [[$listingId, $email]],
                function () use ($memberRepo, $listing, $listingId, $email, $insertData): bool {
                    if ($memberRepo->getByEmail($listing->dbprefix, $email) !== null) {
                        return false;
                    }
                    MemberAddressHandover::for($this->app)->clear($listingId, $email);
                    $memberRepo->insert($listing->dbprefix, $insertData);
                    return true;
                },
            );
        } catch (MemberAddressBusy) {
            return $this->refuse($marker, MemberAddressBusy::MESSAGE, $old, $backTo);
        }
        if (!$inserted) {
            return $this->refuse($marker, 'That email address is already registered for this listing.', $old, $backTo);
        }
        // Last Updated and the member link-check snapshot are handled when an
        // admin approves the member (MemberController), not here.

        // Confirmation email always sent (best-effort — do not abort on failure)
        {
            try {
                $cfg       = $this->app->getConfig();
                $mailer    = new Mailer($cfg);
                $emailRepo = new EmailTemplateRepo($this->app->getDatabase());
                // The reset page itself. The fan can request a link there once approved.
                $resetUrl  = ListingFormUrlResolver::resolve(
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
            } catch (\Throwable $e) {
                error_log('[Luster] Join email failed: ' . $e->getMessage());
            }
        }

        // Tell the owner (best-effort). OwnerNotice decides where it goes.
        $notice     = OwnerNotice::for($this->app);
        $noticeName = "new member notice for {$listing->title}";
        try {
            $cfg          = $this->app->getConfig();
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
            $notice->send($listingId, EmailTemplateRepo::TYPE_NOTIFY_JOIN, $notifyTokens, $notice->addressFor($listing), $listing->title, $noticeName);
        } catch (\Throwable $e) {
            $notice->reportFailure($noticeName, $e);
        }

        $session->remove('lstr_embed_return_to');
        $successTpl = (new ListingSettingsRepo($this->app->getDatabase()))->getSuccessTemplate($listingId) ?? '';
        $session->set('lstr_flash_success_html:' . $marker, SuccessRenderer::render($successTpl, 'joined-pending', [
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
     * Back to the form with an error, and what the fan typed filled back in.
     *
     * @param array<string, string|list<string>> $old From FieldAnswers::oldForm().
     */
    private function refuse(string $marker, string $error, array $old, string $backTo): Response
    {
        $session = $this->app->getSession();
        $session->set('lstr_flash_error:' . $marker, $error);
        $session->set('lstr_flash_old:' . $marker, $old);

        return Response::redirect($backTo);
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
