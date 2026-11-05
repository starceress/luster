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
use Luster\Support\FieldAnswers;
use Luster\Support\ListingFormUrlResolver;
use Luster\Support\MemberAddressBusy;
use Luster\Support\MemberEmailRekey;
use Luster\Support\MemberRemoval;
use Luster\Support\MemberSignIn;
use Luster\Support\MemberWidths;
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
 *  - A sign-in remembers which member it verified, not just their address (MemberSignIn)
 *  - Everything that depends on who's at an address holds its lock (MemberAddressLock)
 */
final class UpdateController
{
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

        // Locked from the first read of the member to the sign-in being set. Someone
        // else getting this address in that gap would otherwise stamp this session with
        // their hash, and verifyAndUpgrade() writes by address.
        try {
            return $this->app->getMemberAddressLock()->run(
                [[$listingId, $email]],
                function () use ($listing, $listingId, $email, $password, $session, $rateLimit, $ip, $marker, $embedReturnTo, $formUrl): Response {
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

                    // Read back, because an upgrade has just replaced the hash the stamp is made from.
                    $verifiedMember = $verified ? $memberRepo->getByEmail($listing->dbprefix, $email) : null;

                    if ($verifiedMember === null) {
                        $rateLimit->hitDefault($ip, RateLimit::ACTION_PUBLIC_UPDATE);
                        $session->set('lstr_flash_error:' . $marker, 'Incorrect password. Please try again.');
                        $session->set('lstr_flash_old:' . $marker, ['email' => $email]);
                        return Response::redirect($embedReturnTo !== '' ? $embedReturnTo : $formUrl);
                    }

                    $rateLimit->clear($ip, RateLimit::ACTION_PUBLIC_UPDATE);
                    MemberSignIn::remember($session, $listingId, $email, $verifiedMember->password);

                    // Issue fallback form-session tokens so the update form still works if
                    // the session is lost before the member submits step 2.
                    $formSessionService = new MemberFormSessionService(new MemberFormSessionRepo($this->app->getDatabase()));
                    $stamp       = MemberSignIn::stamp($verifiedMember->password);
                    $updateToken = $formSessionService->issue($listingId, $email, 'update', $stamp);
                    $deleteToken = $formSessionService->issue($listingId, $email, 'delete', $stamp);
                    if ($updateToken !== null) {
                        $session->set(MemberSignIn::FORM_TOKEN_UPDATE . $listingId, $updateToken);
                    }
                    if ($deleteToken !== null) {
                        $session->set(MemberSignIn::FORM_TOKEN_DELETE . $listingId, $deleteToken);
                    }

                    return Response::redirect($embedReturnTo !== '' ? $embedReturnTo : $formUrl);
                },
            );
        } catch (MemberAddressBusy) {
            $session->set('lstr_flash_error:' . $marker, MemberAddressBusy::MESSAGE);
            $session->set('lstr_flash_old:' . $marker, ['email' => $email]);
            return Response::redirect($embedReturnTo !== '' ? $embedReturnTo : $formUrl);
        }
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

        // Anything that sends the fan back to the form goes through refuse(), with this,
        // so what they typed is still there. Passwords aren't in it.
        $old    = FieldAnswers::oldForm($request->postAll(), ['new_name', 'new_email', 'url', 'country'], $fields);
        $backTo = $embedReturnTo !== '' ? $embedReturnTo : $formUrl;

        if ($rateLimit->isBlocked($ip, RateLimit::ACTION_PUBLIC_UPDATE)) {
            return $this->refuse($marker, 'Too many attempts. Please try again later.', $old, $backTo);
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
        // this listing and to the specific action being attempted. It's only looked
        // at here, and gets used once the locks are held.
        $email     = MemberSignIn::email($session, $listingId);
        $action    = ((string) $request->post('delete_membership', '') === '1') ? 'delete' : 'update';
        $formToken = '';
        if ($email === '') {
            $formToken = (string) $request->post('_form_token_' . $action, '');
            $peeked    = (new MemberFormSessionService(new MemberFormSessionRepo($this->app->getDatabase())))
                ->peek($listingId, $formToken, $action);
            $email     = (string) ($peeked['email'] ?? '');
        }
        if ($email === '') {
            return Response::redirect($embedReturnTo !== '' ? $embedReturnTo : $formUrl);
        }

        // Every address this could touch is locked before anything else, so a busy new
        // address can't leave the password changed or the token used.
        $addresses = [[$listingId, $email]];
        $newEmail  = trim((string) $request->post('new_email', ''));
        if ($action === 'update' && $newEmail !== '') {
            $addresses[] = [$listingId, $newEmail];
        }

        $afterRelease = null;
        try {
            $response = $this->app->getMemberAddressLock()->run(
                $addresses,
                function () use ($request, $listing, $listingId, $fields, $fieldRepo, $settingsRepo, $embedSettings, $globalDefaults, $formUrl, $embedReturnTo, $marker, $ip, $email, $formToken, $action, $old, &$afterRelease): Response {
                    return $this->applySubmission($request, $listing, $listingId, $fields, $fieldRepo, $settingsRepo, $embedSettings, $globalDefaults, $formUrl, $embedReturnTo, $marker, $ip, $email, $formToken, $action, $old, $afterRelease);
                },
            );
        } catch (MemberAddressBusy) {
            return $this->refuse($marker, MemberAddressBusy::MESSAGE, $old, $backTo);
        }

        // Mail waits until the locks are released.
        if ($afterRelease !== null) {
            $afterRelease();
        }

        return $response;
    }

    /**
     * The rest of submit(), with the address locks held. The member at this address
     * has to be the one who signed in before anything else happens.
     *
     * @param array<int, object>  $fields
     * @param array<string,mixed> $embedSettings
     * @param array<string,mixed> $globalDefaults
     * @param array<string,mixed> $old          What the form shows again if this is refused.
     * @param ?callable           $afterRelease Set to whatever has to wait for the locks to go.
     */
    private function applySubmission(
        Request $request,
        Listing $listing,
        int $listingId,
        array $fields,
        FieldRepo $fieldRepo,
        ListingSettingsRepo $settingsRepo,
        array $embedSettings,
        array $globalDefaults,
        string $formUrl,
        string $embedReturnTo,
        string $marker,
        string $ip,
        string $email,
        string $formToken,
        string $action,
        array $old,
        ?callable &$afterRelease,
    ): Response {
        $session   = $this->app->getSession();
        $rateLimit = $this->app->getRateLimit();
        $backTo    = $embedReturnTo !== '' ? $embedReturnTo : $formUrl;

        // A form token is only looked at here. It gets used just before the first write,
        // so a save that's refused can't cost the fan their token.
        $tokens = new MemberFormSessionService(new MemberFormSessionRepo($this->app->getDatabase()));
        if ($formToken !== '') {
            $stamp = (string) ($tokens->peek($listingId, $formToken, $action)['stamp'] ?? '');
        } else {
            $stamp = MemberSignIn::sessionStamp($session, $listingId);
        }

        $memberRepo = new MemberRepo($this->app->getDatabase(), $this->app->getConfig());
        $member     = $memberRepo->getByEmail($listing->dbprefix, $email);

        if ($member === null) {
            MemberSignIn::forget($session, $listingId);
            return Response::redirect($backTo);
        }

        if (!MemberSignIn::matches($stamp, $member)) {
            return $this->signInAgain($listingId, $marker, $backTo);
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
            if ($formToken !== '' && $tokens->consume($listingId, $formToken, $action) === null) {
                return $this->signInAgain($listingId, $marker, $backTo);
            }

            $memberName = $member->name;
            MemberRemoval::for($this->app)->remove($listingId, $listing->dbprefix, $email);

            // Farewell email (best-effort)
            $afterRelease = function () use ($listing, $listingId, $email, $memberName, $embedSettings, $globalDefaults, $settingsRepo): void {
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
            };

            MemberSignIn::forget($session, $listingId);
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

        // These answers are what's checked here and what gets saved below. A saved answer
        // that's no longer a choice is still allowed, because the form still offers it.
        $answers = FieldAnswers::fromPost($fields, $postData, $v, $member->answers($fields));

        if ($v->fails()) {
            $rateLimit->hitDefault($ip, RateLimit::ACTION_PUBLIC_UPDATE);
            return $this->refuse($marker, implode(' ', $v->allErrors()), $old, $backTo);
        }

        // New password confirmation
        if ($newPassword !== '' && $newPassword !== $newPasswordConfirm) {
            return $this->refuse($marker, 'New passwords do not match. Please try again.', $old, $backTo);
        }

        // New email blacklist + uniqueness check
        if ($newEmail !== '' && $newEmail !== $email) {
            if ((new BlacklistRepo($this->app->getDatabase()))->isBlocked($newEmail)) {
                return $this->refuse($marker, 'That email address cannot be used.', $old, $backTo);
            }
            if ($memberRepo->getByEmail($listing->dbprefix, $newEmail) !== null) {
                return $this->refuse($marker, 'That email address is already registered for this listing.', $old, $backTo);
            }
        }

        // Where each extra answer goes, worked out before anything is written,
        // the immediate password change below included.
        $extraValues = FieldAnswers::forPublicForm($fieldRepo, $listing, $fields, $answers, 'Update');
        if ($extraValues === null) {
            return $this->refuse($marker, FieldAnswers::PUBLIC_REFUSAL, $old, $backTo);
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
        $passwordHash = $newPassword !== '' ? password_hash($newPassword, PASSWORD_ARGON2ID) : null;

        foreach ($extraValues as $column => $value) {
            $updateData[$column] = $value;
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

        // Everything about to be written has to fit, the new password's hash included,
        // before the token is used or anything changes. Real member tables are often
        // narrower than this form's own limits.
        $problem = MemberWidths::publicProblem(
            $memberRepo,
            $listing,
            $updateData + ($passwordHash !== null ? ['password' => $passwordHash] : []),
            $fields,
            'Update',
        );
        if ($problem !== null) {
            return $this->refuse($marker, $problem, $old, $backTo);
        }

        if ($formToken !== '' && $tokens->consume($listingId, $formToken, $action) === null) {
            return $this->signInAgain($listingId, $marker, $backTo);
        }

        $shouldQueue = $settingsRepo->getQueueUpdates($listingId);

        // Password changes always apply immediately (never queued). A change of address
        // sends the password in the same UPDATE, so there's no half-saved password to
        // explain. If that reports failed, nothing changed: the old password still works
        // and this sign-in can try again. MyISAM can't undo a write, so an unconfirmed
        // result is still possible, and it's handled below.
        $renaming = !$shouldQueue && isset($updateData['email']);
        if ($passwordHash !== null && !$renaming) {
            $memberRepo->update($listing->dbprefix, $email, ['password' => $passwordHash]);
        }

        if ($shouldQueue && $updateData !== []) {
            $pendingRepo = new PendingUpdateRepo($this->app->getDatabase());
            $pendingRepo->insert($listingId, $email, $updateData);

            MemberSignIn::forget($session, $listingId);
            $session->remove('lstr_embed_return_to');
            $successTpl = $settingsRepo->getSuccessTemplate($listingId) ?? '';
            $session->set('lstr_flash_success_html:' . $marker, SuccessRenderer::render($successTpl, 'updated-pending', [
                'listing_title' => $listing->title,
                'listing_url'   => $listing->url,
            ]));
        } else {
            if ($renaming) {
                $rekey = MemberEmailRekey::for($this->app)->run(
                    $listingId,
                    $listing->dbprefix,
                    $email,
                    $updateData + ($passwordHash !== null ? ['password' => $passwordHash] : []),
                );

                if ($rekey['outcome'] !== MemberEmailRekey::DONE) {
                    if ($rekey['outcome'] === MemberEmailRekey::FAILED) {
                        return $this->refuse($marker, "We couldn't save your changes because of a database error." . ($passwordHash !== null ? ' Your old password still works.' : '') . ' Please try again.', $old, $backTo);
                    }

                    // Their address may have changed, so this sign-in can't be trusted.
                    MemberSignIn::forget($session, $listingId);
                    return $this->refuse($marker, "We couldn't confirm your changes were saved. Please check your details before trying again, or contact the listing owner." . ($passwordHash !== null ? " Also, if your new password doesn't work, try your old one." : ''), $old, $backTo);
                }
            } elseif ($updateData !== []) {
                $memberRepo->update($listing->dbprefix, $email, $updateData);
            }

            // A change of password alone isn't in $updateData, so it doesn't count.
            if (ListingSettingsRepo::changesCountAsUpdate($updateData)) {
                $settingsRepo->touchLastUpdated($listingId);
            }

            MemberSignIn::forget($session, $listingId);
            $session->remove('lstr_embed_return_to');
            $successTpl = $settingsRepo->getSuccessTemplate($listingId) ?? '';
            $session->set('lstr_flash_success_html:' . $marker, SuccessRenderer::render($successTpl, 'updated', [
                'listing_title' => $listing->title,
                'listing_url'   => $listing->url,
            ]));
        }

        return Response::redirect($embedReturnTo !== '' ? $embedReturnTo : $formUrl);
    }

    /** Ends this sign-in and sends the fan back to sign in again. */
    private function signInAgain(int $listingId, string $marker, string $backTo): Response
    {
        $session = $this->app->getSession();
        MemberSignIn::forget($session, $listingId);
        $session->set('lstr_flash_error:' . $marker, MemberSignIn::SIGN_IN_AGAIN);

        return Response::redirect($backTo);
    }

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
