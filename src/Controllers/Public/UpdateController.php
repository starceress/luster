<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Handles the two-step fan update form. Step 1 asks the fan to sign in with
 their email and password. Step 2 shows the pre-filled update form where they
 can change their name, email, URL, country, additional fields, or password.
 Members can also permanently delete their membership from this form. Updates
 can be applied immediately or queued for admin review depending on per-listing
 settings. MD5 and non-Argon2id hashes are silently upgraded on successful login.
*/

declare(strict_types=1);

namespace Luster\Controllers\Public;

use Luster\Core\Application;
use Luster\Core\Mailer;
use Luster\Core\MemberFormSessionService;
use Luster\Core\PasswordPolicy;
use Luster\Core\RateLimit;
use Luster\Core\Request;
use Luster\Core\Response;
use Luster\Core\Validator;
use Luster\Models\Listing;
use Luster\Repositories\EmailTemplateRepo;
use Luster\Repositories\ListingRepo;
use Luster\Repositories\ListingSettingsRepo;
use Luster\Repositories\FieldRepo;
use Luster\Repositories\BlacklistRepo;
use Luster\Repositories\MemberFormSessionRepo;
use Luster\Repositories\MemberRepo;
use Luster\Repositories\PendingUpdateRepo;
use Luster\Support\ListingFormUrlResolver;
use Luster\Support\SuccessRenderer;

/**
 * Fan update form — two-step flow.
 *
 * POST /update/{id}/sign-in  — verifies email + password, sets session auth key
 * POST /update/{id}          — applies fan changes (update or delete membership)
 *
 * Security properties:
 *  - CSRF token validated by CsrfMiddleware
 *  - Honeypot field silently rejects bot submissions
 *  - Rate-limited per IP via lstr_rate_limits
 *  - Password verified via MemberRepo::verifyAndUpgrade()
 *  - MD5 and non-Argon2id hashes (bcrypt, legacy) silently upgraded to Argon2id on success
 *  - Session key lstr_update_authed_{id} stores the authenticated member's email
 */
final class UpdateController
{
    /** Session key prefix for the authenticated member email. */
    private const AUTH_KEY = 'lstr_update_authed_';

    /**
     * Session key prefixes for the fallback single-use form tokens, issued
     * alongside AUTH_KEY at sign-in and embedded as hidden form fields so the
     * update form can still be submitted if the session is lost between
     * sign-in and submit (e.g. cross-subdomain cookie issues, session GC).
     */
    private const FORM_TOKEN_UPDATE_KEY = 'lstr_update_formtoken_update_';
    private const FORM_TOKEN_DELETE_KEY = 'lstr_update_formtoken_delete_';

    public function __construct(private readonly Application $app) {}

    // -----------------------------------------------------------------------
    // POST /update/{id}/sign-in
    // -----------------------------------------------------------------------

    /**
     * Handles the sign-in step: verifies the fan's email and password,
     * upgrades any legacy MD5 hashes to Argon2id on success, and writes
     * the authenticated email to the session so showForm() can proceed to step 2.
     */
    public function signIn(Request $request, array $params): Response
    {
        $listingId = (int) ($params['id'] ?? 0);
        $baseUrl   = $this->app->getBaseUrl();
        $session   = $this->app->getSession();
        $rateLimit = $this->app->getRateLimit();
        $ip            = $request->ip();
        $embedReturnTo = (string) $session->get('lstr_embed_return_to', '');
        $marker        = (string) $request->post('_luster_embed_form', '');

        $listingRepo = new ListingRepo($this->app->getDatabase(), $this->app->getConfig());
        $listing     = $listingRepo->getById($listingId);

        if ($listing === null) {
            return Response::notFound('That listing does not exist.');
        }

        $settingsRepo    = new ListingSettingsRepo($this->app->getDatabase());
        $embedSettings   = $settingsRepo->getResolvedEmbedSettings($listingId);
        $globalDefaults  = $settingsRepo->getGlobalFormUrlDefaults();
        $formUrl         = $this->buildFormUrl($listing, $embedSettings, $globalDefaults);

        // Archived listings (Closed/Adopted Out) no longer accept sign-ins for updates.
        if ((new \Luster\Repositories\ListingLifecycleRepo($this->app->getDatabase()))->getByListingId($listingId) !== null) {
            $session->set('lstr_flash_error:' . $marker, 'This fanlisting is no longer accepting membership updates.');
            return Response::redirect($embedReturnTo !== '' ? $embedReturnTo : $formUrl);
        }

        if ($rateLimit->isBlocked($ip, RateLimit::ACTION_PUBLIC_UPDATE)) {
            $session->set('lstr_flash_error:' . $marker, 'Too many attempts. Please try again later.');
            return Response::redirect($embedReturnTo !== '' ? $embedReturnTo : $formUrl);
        }

        // Honeypot — silent reject
        if ((string) $request->post('website_confirm', '') !== '') {
            return Response::redirect($embedReturnTo !== '' ? $embedReturnTo : $formUrl);
        }

        $email    = trim((string) $request->post('email',    ''));
        $password = (string) $request->post('password', '');

        $v = new Validator(['email' => $email, 'password' => $password]);
        $v->field('email')->required()->email()->maxLength(255);
        $v->field('password')->required();

        if ($v->fails()) {
            $rateLimit->hitDefault($ip, RateLimit::ACTION_PUBLIC_UPDATE);
            $session->set('lstr_flash_error:' . $marker, implode(' ', $v->allErrors()));
            $session->set('lstr_flash_old:' . $marker, ['email' => $email]);
            return Response::redirect($embedReturnTo !== '' ? $embedReturnTo : $formUrl);
        }

        $memberRepo = new MemberRepo($this->app->getDatabase(), $this->app->getConfig());
        $member     = $memberRepo->getByEmail($listing->dbprefix, $email);

        if ($member === null) {
            $rateLimit->hitDefault($ip, RateLimit::ACTION_PUBLIC_UPDATE);
            $session->set('lstr_flash_error:' . $marker, 'No membership found for that email address.');
            $session->set('lstr_flash_old:' . $marker, ['email' => $email]);
            return Response::redirect($embedReturnTo !== '' ? $embedReturnTo : $formUrl);
        }

        // Verify password; silently upgrade MD5/bcrypt hashes to Argon2id on success.
        $verified = $memberRepo->verifyAndUpgrade($listing->dbprefix, $email, $password, $member->password);

        if (!$verified) {
            $rateLimit->hitDefault($ip, RateLimit::ACTION_PUBLIC_UPDATE);
            $session->set('lstr_flash_error:' . $marker, 'Incorrect password. Please try again.');
            $session->set('lstr_flash_old:' . $marker, ['email' => $email]);
            return Response::redirect($embedReturnTo !== '' ? $embedReturnTo : $formUrl);
        }

        $rateLimit->clear($ip, RateLimit::ACTION_PUBLIC_UPDATE);
        $session->set(self::AUTH_KEY . $listingId, $email);

        // Issue fallback form-session tokens so the update form still works if
        // the session is lost before the member submits step 2.
        $formSessionService = new MemberFormSessionService(new MemberFormSessionRepo($this->app->getDatabase()));
        $updateToken = $formSessionService->issue($listingId, $email, 'update');
        $deleteToken = $formSessionService->issue($listingId, $email, 'delete');
        if ($updateToken !== null) {
            $session->set(self::FORM_TOKEN_UPDATE_KEY . $listingId, $updateToken);
        }
        if ($deleteToken !== null) {
            $session->set(self::FORM_TOKEN_DELETE_KEY . $listingId, $deleteToken);
        }

        return Response::redirect($embedReturnTo !== '' ? $embedReturnTo : $formUrl);
    }

    // -----------------------------------------------------------------------
    // POST /update/{id}
    // -----------------------------------------------------------------------

    /**
     * Applies the fan's changes.
     *
     * Handles two distinct paths:
     *   - delete_membership=1: removes the member record immediately and sends a farewell email.
     *   - Otherwise: validates and applies field changes, either immediately (direct) or via
     *     the pending-updates queue when hold_update is enabled for the listing.
     *
     * Password changes always bypass the queue and take effect immediately, regardless of the
     * hold_update setting. Unchanged fields are stripped before the queue entry is written so
     * password-only submissions do not create no-op pending updates.
     */
    public function submit(Request $request, array $params): Response
    {
        $listingId = (int) ($params['id'] ?? 0);
        $baseUrl   = $this->app->getBaseUrl();
        $session   = $this->app->getSession();
        $rateLimit = $this->app->getRateLimit();
        $ip        = $request->ip();

        $embedReturnTo = (string) $session->get('lstr_embed_return_to', '');
        $marker        = (string) $request->post('_luster_embed_form', '');

        $listingRepo = new ListingRepo($this->app->getDatabase(), $this->app->getConfig());
        $listing     = $listingRepo->getById($listingId);

        if ($listing === null) {
            return Response::notFound('That listing does not exist.');
        }

        $fieldRepo     = new FieldRepo($this->app->getDatabase(), $this->app->getConfig());
        $fields        = $fieldRepo->getFieldsForListing($listingId);
        $settingsRepo    = new ListingSettingsRepo($this->app->getDatabase());
        $embedSettings   = $settingsRepo->getResolvedEmbedSettings($listingId);
        $globalDefaults  = $settingsRepo->getGlobalFormUrlDefaults();
        $formUrl         = $this->buildFormUrl($listing, $embedSettings, $globalDefaults);

        // Archived listings (Closed/Adopted Out) no longer accept updates or deletions,
        // even from a member who was already signed in before the listing was archived.
        if ((new \Luster\Repositories\ListingLifecycleRepo($this->app->getDatabase()))->getByListingId($listingId) !== null) {
            $session->set('lstr_flash_error:' . $marker, 'This fanlisting is no longer accepting membership updates.');
            return Response::redirect($embedReturnTo !== '' ? $embedReturnTo : $formUrl);
        }

        if ($rateLimit->isBlocked($ip, RateLimit::ACTION_PUBLIC_UPDATE)) {
            $session->set('lstr_flash_error:' . $marker, 'Too many attempts. Please try again later.');
            return Response::redirect($embedReturnTo !== '' ? $embedReturnTo : $formUrl);
        }

        // Honeypot — silent reject; fake success so bots see no difference
        if ((string) $request->post('website_confirm', '') !== '') {
            $session->remove('lstr_embed_return_to');
            $session->set('lstr_flash_success_html:' . $marker, SuccessRenderer::render('', 'updated'));
            return Response::redirect($embedReturnTo !== '' ? $embedReturnTo : $formUrl);
        }

        // Must be authenticated before reaching the update form. If the session
        // auth key is missing (e.g. lost between sign-in and submit), fall back
        // to the single-use form token embedded as a hidden field — bound to
        // this listing and to the specific action being attempted.
        $email = (string) $session->get(self::AUTH_KEY . $listingId, '');
        if ($email === '') {
            $action    = ((string) $request->post('delete_membership', '') === '1') ? 'delete' : 'update';
            $formToken = (string) $request->post('_form_token_' . $action, '');
            if ($formToken !== '') {
                $formSessionService = new MemberFormSessionService(new MemberFormSessionRepo($this->app->getDatabase()));
                $email = (string) ($formSessionService->consume($listingId, $formToken, $action) ?? '');
            }
        }
        if ($email === '') {
            return Response::redirect($embedReturnTo !== '' ? $embedReturnTo : $formUrl);
        }

        $memberRepo = new MemberRepo($this->app->getDatabase(), $this->app->getConfig());
        $member     = $memberRepo->getByEmail($listing->dbprefix, $email);

        if ($member === null) {
            $session->remove(self::AUTH_KEY . $listingId);
            $session->remove(self::FORM_TOKEN_UPDATE_KEY . $listingId);
            $session->remove(self::FORM_TOKEN_DELETE_KEY . $listingId);
            return Response::redirect($embedReturnTo !== '' ? $embedReturnTo : $formUrl);
        }

        // Update fields (all optional; blank = keep existing)
        $newName            = trim((string) $request->post('new_name',            ''));
        $newEmail           = trim((string) $request->post('new_email',           ''));
        $newPassword        = (string) $request->post('new_password',        '');
        $newPasswordConfirm = (string) $request->post('new_password_confirm', '');
        $url                = trim((string) $request->post('url',                 ''));
        $country            = trim((string) $request->post('country', ''));
        $deleteUrl          = ((string) $request->post('delete_url', '') === '1');

        $postData = $request->postAll();


        // Permanently delete membership instead of updating it
        if ((string) $request->post('delete_membership', '') === '1') {
            $memberName = $member->name;
            $memberRepo->deleteByEmail($listing->dbprefix, $email);

            $pendingRepo = new PendingUpdateRepo($this->app->getDatabase());
            $pendingRepo->deleteByListingAndEmail($listingId, $email);

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
                    listingEmail:   $listing->email,
                    formUrlJoin:    (string) ($embedSettings['form_url_join']   ?: $globalDefaults['form_url_join']),
                    formUrlUpdate:  (string) ($embedSettings['form_url_update'] ?: $globalDefaults['form_url_update']),
                    formUrlReset:   (string) ($embedSettings['form_url_reset']  ?: $globalDefaults['form_url_reset']),
                    listingType:    $settingsRepo->getListingType($listingId),
                );
                [$subject, $body] = $emailRepo->resolveForListing($listingId, EmailTemplateRepo::TYPE_REMOVE, $tokens);
                if ($subject !== '') {
                    $mailer->send($email, $memberName, $subject, $body);
                }
            } catch (\Throwable $e) {
                error_log('[Luster] Remove email failed: ' . $e->getMessage());
            }

            $session->remove(self::AUTH_KEY . $listingId);
            $session->remove(self::FORM_TOKEN_UPDATE_KEY . $listingId);
            $session->remove(self::FORM_TOKEN_DELETE_KEY . $listingId);
            $session->remove('lstr_embed_return_to');
            $successTpl = $settingsRepo->getSuccessTemplate($listingId) ?? '';
            $session->set('lstr_flash_success_html:' . $marker, SuccessRenderer::render($successTpl, 'removed', [
                'listing_title' => $listing->title,
                'listing_url'   => $listing->url,
            ]));
            return Response::redirect($embedReturnTo !== '' ? $embedReturnTo : $formUrl);
        }

        // Validate update fields only when non-empty
        $v = new Validator($postData);
        if ($newName !== '') {
            $v->field('new_name')->maxLength(100);
        }
        if ($newEmail !== '') {
            $v->field('new_email')->email()->maxLength(255);
        }
        if ($url !== '') {
            $v->field('url')->httpUrl()->maxLength(500);
        }
        if ($country !== '') {
            $v->field('country')->maxLength(100);
        }
        if ($newPassword !== '') {
            PasswordPolicy::applyOptional($v->field('new_password'), $newPassword);
        }

        foreach ($fields as $field) {
            $vf = $v->field('field_' . $field->name)->maxLength(255);
            if ($field->required) {
                $vf->required();
            }
        }

        if ($v->fails()) {
            $rateLimit->hitDefault($ip, RateLimit::ACTION_PUBLIC_UPDATE);
            $old = [
                'new_name'  => $newName,
                'new_email' => $newEmail,
                'url'       => $url,
                'country'   => $country,
            ];
            foreach ($fields as $field) {
                $old['field_' . $field->name] = $postData['field_' . $field->name] ?? '';
            }
            $session->set('lstr_flash_error:' . $marker, implode(' ', $v->allErrors()));
            $session->set('lstr_flash_old:' . $marker, $old);
            return Response::redirect($embedReturnTo !== '' ? $embedReturnTo : $formUrl);
        }

        // New password confirmation
        if ($newPassword !== '' && $newPassword !== $newPasswordConfirm) {
            $old = ['new_name' => $newName, 'new_email' => $newEmail, 'url' => $url, 'country' => $country];
            foreach ($fields as $field) {
                $old['field_' . $field->name] = $postData['field_' . $field->name] ?? '';
            }
            $session->set('lstr_flash_error:' . $marker, 'New passwords do not match. Please try again.');
            $session->set('lstr_flash_old:' . $marker, $old);
            return Response::redirect($embedReturnTo !== '' ? $embedReturnTo : $formUrl);
        }

        // New email blacklist + uniqueness check
        if ($newEmail !== '' && $newEmail !== $email) {
            $old = ['new_name' => $newName, 'new_email' => $newEmail, 'url' => $url, 'country' => $country];
            foreach ($fields as $field) {
                $old['field_' . $field->name] = $postData['field_' . $field->name] ?? '';
            }
            if ((new BlacklistRepo($this->app->getDatabase()))->isBlocked($newEmail)) {
                $session->set('lstr_flash_error:' . $marker, 'That email address cannot be used.');
                $session->set('lstr_flash_old:' . $marker, $old);
                return Response::redirect($embedReturnTo !== '' ? $embedReturnTo : $formUrl);
            }
            if ($memberRepo->getByEmail($listing->dbprefix, $newEmail) !== null) {
                $session->set('lstr_flash_error:' . $marker, 'That email address is already registered for this listing.');
                $session->set('lstr_flash_old:' . $marker, $old);
                return Response::redirect($embedReturnTo !== '' ? $embedReturnTo : $formUrl);
            }
        }

        // Resolve final values
        $finalName    = $newName  !== '' ? $newName  : $member->name;
        $finalEmail   = $newEmail !== '' ? $newEmail : $email;
        $finalCountry = $country  !== '' ? $country  : $member->country;

        if ($deleteUrl) {
            $finalUrl = '';
        } elseif ($url !== '') {
            $finalUrl = $url;
        } else {
            $finalUrl = $member->url;
        }

        $updateData = [
            'name'    => $finalName,
            'url'     => $finalUrl,
            'country' => $finalCountry,
            'showurl' => $finalUrl !== '' ? 1 : 0,
        ];

        if ($newEmail !== '' && $newEmail !== $email) {
            $updateData['email'] = $finalEmail;
        }
        if ($newPassword !== '') {
            $updateData['password'] = password_hash($newPassword, PASSWORD_ARGON2ID);
        }

        $prefix = (string) $this->app->getConfig()->get('enth_table_prefix', '');
        foreach ($fields as $field) {
            $colName = ($prefix === '') ? $field->name : 'additional_' . $field->name;
            $raw     = $postData['field_' . $field->name] ?? '';
            $updateData[$colName] = is_array($raw)
                ? implode(', ', array_map('trim', $raw))
                : trim((string) $raw);
        }

        // Password changes always apply immediately (never queued)
        if (isset($updateData['password'])) {
            $memberRepo->update($listing->dbprefix, $email, ['password' => $updateData['password']]);
            unset($updateData['password']);
        }

        // Strip unchanged fields so password-only submissions don't create no-op queue entries
        $currentRow = $memberRepo->getRawRow($listing->dbprefix, $email);
        if ($currentRow !== null) {
            foreach ($updateData as $k => $v) {
                if (array_key_exists($k, $currentRow) && (string) $currentRow[$k] === (string) $v) {
                    unset($updateData[$k]);
                }
            }
        }

        $shouldQueue = $settingsRepo->getQueueUpdates($listingId);

        if ($shouldQueue && $updateData !== []) {
            $pendingRepo = new PendingUpdateRepo($this->app->getDatabase());
            $pendingRepo->insert($listingId, $email, $updateData);

            $session->remove(self::AUTH_KEY . $listingId);
            $session->remove(self::FORM_TOKEN_UPDATE_KEY . $listingId);
            $session->remove(self::FORM_TOKEN_DELETE_KEY . $listingId);
            $session->remove('lstr_embed_return_to');
            $successTpl = $settingsRepo->getSuccessTemplate($listingId) ?? '';
            $session->set('lstr_flash_success_html:' . $marker, SuccessRenderer::render($successTpl, 'updated-pending', [
                'listing_title' => $listing->title,
                'listing_url'   => $listing->url,
            ]));
        } else {
            if ($updateData !== []) {
                $memberRepo->update($listing->dbprefix, $email, $updateData);
            }

            $session->remove(self::AUTH_KEY . $listingId);
            $session->remove(self::FORM_TOKEN_UPDATE_KEY . $listingId);
            $session->remove(self::FORM_TOKEN_DELETE_KEY . $listingId);
            $session->remove('lstr_embed_return_to');
            $successTpl = $settingsRepo->getSuccessTemplate($listingId) ?? '';
            $session->set('lstr_flash_success_html:' . $marker, SuccessRenderer::render($successTpl, 'updated', [
                'listing_title' => $listing->title,
                'listing_url'   => $listing->url,
            ]));
        }

        return Response::redirect($embedReturnTo !== '' ? $embedReturnTo : $formUrl);
    }

    /**
     * Resolves the update form URL, using the per-listing form_url_update path
     * when configured, otherwise falling back to the global default then the listing URL.
     *
     * @param array<string,mixed> $embedSettings
     * @param array<string,string> $globalDefaults
     */
    private function buildFormUrl(Listing $listing, array $embedSettings, array $globalDefaults = []): string
    {
        return ListingFormUrlResolver::resolve(
            (string) ($embedSettings['form_url_update'] ?? ''),
            $listing->url,
            $globalDefaults['form_url_update'] ?? '',
        );
    }

    /**
     * Resolves the "Forgot your password?" link URL, preferring the per-listing
     * form_url_reset override, then the global default. Returns an empty string
     * when neither is set (the template then hides the link entirely).
     *
     * @param array<string,mixed> $embedSettings
     * @param array<string,string> $globalDefaults
     */
    private function buildResetUrl(Listing $listing, array $embedSettings, array $globalDefaults = []): string
    {
        $resetPage   = (string) ($embedSettings['form_url_reset'] ?? '');
        $resetGlobal = (string) ($globalDefaults['form_url_reset'] ?? '');
        if ($resetPage === '' && $resetGlobal === '') {
            return '';
        }
        return ListingFormUrlResolver::resolve($resetPage, $listing->url, $resetGlobal);
    }
}
