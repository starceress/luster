<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Handles POST submissions for the collective-update embed. Covers two
 actions: requesting a verification link (request) and applying the
 actual changes after the link is clicked (submit). The GET step that
 displays the update form is handled directly by the embed file.
*/

declare(strict_types=1);

namespace Luster\Controllers\Public;

use Luster\Core\Application;
use Luster\Core\Mailer;
use Luster\Core\PasswordPolicy;
use Luster\Core\RateLimit;
use Luster\Core\Request;
use Luster\Core\Response;
use Luster\Core\Validator;
use Luster\Models\Listing;
use Luster\Models\Member;
use Luster\Repositories\BlacklistRepo;
use Luster\Repositories\CollectiveUpdateTokenRepo;
use Luster\Repositories\EmailTemplateRepo;
use Luster\Repositories\ListingLifecycleRepo;
use Luster\Repositories\ListingRepo;
use Luster\Repositories\ListingSettingsRepo;
use Luster\Repositories\MemberRepo;
use Luster\Repositories\PendingUpdateRepo;
use Luster\Support\MemberAddressBusy;
use Luster\Support\MemberChangeList;
use Luster\Support\MemberEmailRekey;
use Luster\Support\MemberRemoval;
use Luster\Support\MemberWidths;
use Luster\Support\OwnerNotice;

/**
 * Handles collective-level cross-listing membership updates.
 *
 * The flow is email-ownership verification via a single-use token:
 *  1. Member submits their email.
 *  2. If they have memberships, a verification link is emailed to them.
 *  3. Clicking the link opens the update form (rendered by the embed file).
 *  4. Submitting the form calls submit(), which applies the changes.
 */
final class CollectiveUpdateController
{
    public function __construct(private readonly Application $app) {}

    /**
     * POST handler for the initial email-submission step.
     * Finds memberships for the submitted email, issues a verification token,
     * and sends a link. Always responds with the same neutral flash regardless
     * of whether the email was found, to prevent email enumeration.
     */
    public function request(Request $request): Response
    {
        $session       = $this->app->getSession();
        $embedReturnTo = (string) $session->get('lstr_embed_return_to', '');
        $rateLimit     = $this->app->getRateLimit();
        $ip            = $request->ip();
        $marker        = 'collective_update';

        if ($rateLimit->isBlocked($ip, RateLimit::ACTION_PUBLIC_COLLECTIVE_UPDATE_REQ)) {
            $session->set('lstr_flash_error:' . $marker, 'Too many attempts. Please try again later.');
            return Response::redirect($embedReturnTo);
        }

        // Honeypot — silent accept so bots see no difference
        if ((string) $request->post('website_confirm', '') !== '') {
            $session->set('lstr_flash_success_html:' . $marker, '<p class="fl-success">If your email is registered for one or more fanlistings here, you\'ll receive a verification link shortly.</p>');
            return Response::redirect($embedReturnTo);
        }

        // Count every real submission against the rate limit, not just invalid ones
        $rateLimit->hitDefault($ip, RateLimit::ACTION_PUBLIC_COLLECTIVE_UPDATE_REQ);

        $email = trim((string) $request->post('email', ''));

        $v = new Validator(['email' => $email]);
        $v->field('email')->required()->email()->maxLength(255);

        if ($v->fails()) {
            $session->set('lstr_flash_error:' . $marker, implode(' ', $v->allErrors()));
            $session->set('lstr_flash_old_email:' . $marker, $email);
            return Response::redirect($embedReturnTo);
        }

        $memberships = self::findMembershipsForEmail($email, $this->app);

        if ($memberships !== []) {
            $tokenRepo = new CollectiveUpdateTokenRepo($this->app->getDatabase());
            $rawToken  = $tokenRepo->create($email);

            $sep       = str_contains($embedReturnTo, '?') ? '&' : '?';
            $verifyUrl = $embedReturnTo . $sep . '_luster_cu_token=' . urlencode($rawToken);

            try {
                $cfg      = $this->app->getConfig();
                $siteName = (string) $cfg->get('site_name', 'Luster');

                $membershipList = implode("\n", array_map(
                    static fn(array $m) => '- ' . $m['listing']->title
                        . ($m['listing']->subject !== '' ? ' (' . $m['listing']->subject . ')' : ''),
                    $memberships
                ));

                $tokens = EmailTemplateRepo::buildCollectiveUpdateTokens(
                    siteName:       $siteName,
                    ownerName:      (string) $cfg->get('owner_name', $siteName),
                    ownerEmail:     (string) $cfg->get('owner_email', ''),
                    verifyUrl:      $verifyUrl,
                    membershipList: $membershipList,
                    siteUrl:        (string) $cfg->get('site_url', ''),
                );

                $emailRepo      = new EmailTemplateRepo($this->app->getDatabase());
                [$subject, $body] = $emailRepo->resolveForListing(null, EmailTemplateRepo::TYPE_COLLECTIVE_UPDATE, $tokens);

                if ($subject !== '') {
                    $mailer = new Mailer($cfg);
                    $mailer->send($email, '', $subject, $body);
                }
            } catch (\Throwable $e) {
                error_log('[Luster] Collective update email failed: ' . $e->getMessage());
            }
        }

        // Always the same neutral message to prevent enumeration
        $session->set('lstr_flash_success_html:' . $marker, '<p class="fl-success">If your email is registered for one or more fanlistings here, you\'ll receive a verification link shortly.</p>');
        return Response::redirect($embedReturnTo);
    }

    /**
     * POST handler for the update-submission step.
     * Validates the token, applies updates to each selected listing,
     * and consumes the token to prevent replay.
     */
    public function submit(Request $request): Response
    {
        $session       = $this->app->getSession();
        $embedReturnTo = (string) $session->get('lstr_embed_return_to', '');
        $rateLimit     = $this->app->getRateLimit();
        $ip            = $request->ip();
        $marker        = 'collective_update';

        if ($rateLimit->isBlocked($ip, RateLimit::ACTION_PUBLIC_UPDATE)) {
            $session->set('lstr_flash_error:' . $marker, 'Too many attempts. Please try again later.');
            return Response::redirect($embedReturnTo);
        }

        $rawToken = (string) $request->post('_cu_token', '');

        if ($rawToken === '') {
            $session->set('lstr_flash_error:' . $marker, 'Missing update token. Please use the link from your email.');
            return Response::redirect($embedReturnTo);
        }

        $tokenRepo = new CollectiveUpdateTokenRepo($this->app->getDatabase());
        $tokenRow  = $tokenRepo->findValid($rawToken);

        if ($tokenRow === null) {
            $session->set('lstr_flash_error:' . $marker, 'That update link is invalid or has expired. Please request a new one.');
            return Response::redirect($embedReturnTo);
        }

        $email = (string) $tokenRow['email'];

        // Build the token URL so validation errors redirect back to Step 2
        // rather than to the clean host URL (which would drop the form entirely).
        $sep      = str_contains($embedReturnTo, '?') ? '&' : '?';
        $tokenUrl = $embedReturnTo . $sep . '_luster_cu_token=' . urlencode($rawToken);

        $memberships = self::findMembershipsForEmail($email, $this->app);

        if ($memberships === []) {
            $session->set('lstr_flash_error:' . $marker, 'No active memberships were found for your email address.');
            return Response::redirect($tokenUrl);
        }

        // Build a map of valid listing IDs to their membership entry
        $validMap = [];
        foreach ($memberships as $m) {
            $validMap[$m['listing']->id] = $m;
        }

        // Submitted listing IDs must be a subset of valid memberships
        $rawIds      = (array) $request->post('listing_ids', []);
        $selectedIds = array_unique(array_filter(array_map('intval', $rawIds)));
        $selectedIds = array_values(array_filter($selectedIds, fn(int $id) => isset($validMap[$id])));

        $newName            = trim((string) $request->post('name', ''));
        $newEmail           = trim((string) $request->post('new_email', ''));
        $newUrl             = trim((string) $request->post('url', ''));
        $deleteUrl          = ((string) $request->post('delete_url', '') === '1');
        $newCountry         = trim((string) $request->post('country', ''));
        $newPassword        = (string) $request->post('new_password', '');
        $newPasswordConfirm = (string) $request->post('new_password_confirm', '');
        $deleteMembership   = ((string) $request->post('delete_membership', '') === '1');

        if ($selectedIds === []) {
            $session->set('lstr_flash_error:' . $marker, 'Please select at least one fanlisting to update.');
            return Response::redirect($tokenUrl);
        }

        // Permanently delete membership instead of updating it — skips every
        // other field entirely, matching the single-listing update form.
        if ($deleteMembership) {
            return $this->deleteMemberships($selectedIds, $validMap, $email, $embedReturnTo, $tokenRepo, $rawToken, $tokenUrl);
        }

        $v = new Validator(['name' => $newName, 'new_email' => $newEmail, 'url' => $newUrl, 'country' => $newCountry, 'new_password' => $newPassword]);
        if ($newName !== '') {
            $v->field('name')->maxLength(100);
        }
        if ($newEmail !== '') {
            $v->field('new_email')->email()->maxLength(255);
        }
        if ($newUrl !== '' && !$deleteUrl) {
            $v->field('url')->httpUrl()->maxLength(500);
        }
        if ($newCountry !== '') {
            $v->field('country')->maxLength(100);
        }
        if ($newPassword !== '') {
            PasswordPolicy::applyOptional($v->field('new_password'), $newPassword);
        }

        if ($v->fails()) {
            $rateLimit->hitDefault($ip, RateLimit::ACTION_PUBLIC_UPDATE);
            $session->set('lstr_flash_error:' . $marker, implode(' ', $v->allErrors()));
            return Response::redirect($tokenUrl);
        }

        if ($newPassword !== '' && $newPassword !== $newPasswordConfirm) {
            $rateLimit->hitDefault($ip, RateLimit::ACTION_PUBLIC_UPDATE);
            $session->set('lstr_flash_error:' . $marker, 'New passwords do not match. Please try again.');
            return Response::redirect($tokenUrl);
        }

        if ($newEmail !== '' && $newEmail !== $email && (new BlacklistRepo($this->app->getDatabase()))->isBlocked($newEmail)) {
            $rateLimit->hitDefault($ip, RateLimit::ACTION_PUBLIC_UPDATE);
            $session->set('lstr_flash_error:' . $marker, 'That email address cannot be used.');
            return Response::redirect($tokenUrl);
        }

        $memberRepo    = new MemberRepo($this->app->getDatabase(), $this->app->getConfig());
        $settingsRepo  = new ListingSettingsRepo($this->app->getDatabase());
        $lifecycleRepo = new ListingLifecycleRepo($this->app->getDatabase());
        $results       = ['updated' => [], 'queued' => [], 'skipped' => [], 'email_conflict' => [], 'failed' => [], 'unconfirmed' => []];

        // What the owner gets told, one entry per listing that really changed.
        $ownerEntries = [];

        // Set if a transaction can't be closed. Nothing more goes near the database after that.
        $stopped = false;

        // Tracks the address the confirmation email should go to. Starts as the
        // verified (old) address; switches to the new one the moment an
        // immediate (non-queued) email change is actually applied, since by
        // that point the old address may no longer reach the member.
        $confirmationEmail = $email;

        // Every address in every selected listing is locked before the token is used
        // or anything is written, so a busy one can't leave this half done.
        $addresses = [];
        foreach ($selectedIds as $listingId) {
            $addresses[] = [$listingId, $email];
            if ($newEmail !== '' && $newEmail !== $email) {
                $addresses[] = [$listingId, $newEmail];
            }
        }

        // Set if a value won't fit one of the listings. Nothing has changed anywhere then.
        $tooLong = null;

        try {
            $tokenWasGood = $this->app->getMemberAddressLock()->run(
                $addresses,
                function () use (
                    $tokenRepo, $rawToken, $email, $selectedIds, $validMap, $lifecycleRepo, $memberRepo, $settingsRepo,
                    $newPassword, $newName, $deleteUrl, $newUrl, $newCountry, $newEmail, &$results, &$confirmationEmail, &$stopped, &$tooLong, &$ownerEntries
                ): bool {
                    // Hash once; applied immediately to every selected listing (never queued)
                    $passwordHash = $newPassword !== '' ? password_hash($newPassword, PASSWORD_ARGON2ID) : null;

                    // Read again now it's locked: who's at this address may have changed.
                    $current = self::membershipsByListing($email, $this->app);

                    // Every selected listing has to be able to hold this before the token is
                    // used or anything is written, so one narrow table can't leave the others
                    // changed, and the same link still works once the fan has fixed it.
                    foreach ($selectedIds as $listingId) {
                        $listing = $validMap[$listingId]['listing'];
                        $member  = $current[$listingId]['member'] ?? null;
                        if ($member === null || $lifecycleRepo->getByListingId($listingId) !== null) {
                            continue;
                        }

                        $planned = self::changesFor($member, $listing, $email, $memberRepo, $newName, $deleteUrl, $newUrl, $newCountry, $newEmail);
                        $tooLong = MemberWidths::publicProblem(
                            $memberRepo,
                            $listing,
                            $planned['changes'] + ($passwordHash !== null ? ['password' => $passwordHash] : []),
                            [],
                            'Collective Update',
                            $listing->title,
                        );
                        if ($tooLong !== null) {
                            return true;
                        }
                    }

                    // All validation passed — atomically consume the token now.
                    // verifyAndConsume() returning null here means a race beat us to it;
                    // treat it as an expired token rather than applying updates twice.
                    if ($tokenRepo->verifyAndConsume($rawToken) === null) {
                        return false;
                    }

                    foreach ($selectedIds as $listingId) {
                        $listing = $validMap[$listingId]['listing'];
                        $member  = $current[$listingId]['member'] ?? null;

                        if ($stopped) {
                            $results['failed'][] = ['title' => $listing->title, 'subject' => $listing->subject];
                            continue;
                        }

                        // Skip if removed or archived since the memberships were loaded
                        if ($member === null || $lifecycleRepo->getByListingId($listingId) !== null) {
                            $results['skipped'][] = ['title' => $listing->title, 'subject' => $listing->subject];
                            continue;
                        }

                        $planned    = self::changesFor($member, $listing, $email, $memberRepo, $newName, $deleteUrl, $newUrl, $newCountry, $newEmail);
                        $updateData = $planned['changes'];
                        if ($planned['email_taken']) {
                            $results['email_conflict'][] = ['title' => $listing->title, 'subject' => $listing->subject];
                        }

                        $shouldQueue = $settingsRepo->getQueueUpdates($listingId);

                        $ownerEntry = self::ownerEntry($listing, $member, MemberChangeList::lines(
                            $updateData,
                            ['name' => $member->name, 'email' => $email, 'url' => $member->url, 'country' => $member->country],
                            [],
                            $passwordHash !== null,
                            $shouldQueue,
                        ));

                        // Password always applied immediately — never queued. A change of
                        // address sends it in the same UPDATE. If that reports failed,
                        // nothing changed on this listing and the old password still
                        // works. MyISAM can't undo a write, so an unconfirmed result is
                        // still possible, and it's handled below.
                        $renaming = !$shouldQueue && isset($updateData['email']);
                        if ($passwordHash !== null && !$renaming) {
                            $memberRepo->update($listing->dbprefix, $email, ['password' => $passwordHash]);
                        }

                        if ($updateData === []) {
                            if ($passwordHash === null) {
                                $results['skipped'][] = ['title' => $listing->title, 'subject' => $listing->subject];
                            } else {
                                $results['updated'][] = ['title' => $listing->title, 'subject' => $listing->subject];
                                $ownerEntries[] = $ownerEntry;
                            }
                            continue;
                        }

                        if ($shouldQueue) {
                            (new PendingUpdateRepo($this->app->getDatabase()))->insert($listingId, $email, $updateData);
                            $results['queued'][] = ['title' => $listing->title, 'subject' => $listing->subject];
                            $ownerEntries[] = $ownerEntry;
                        } elseif ($renaming) {
                            $rekey = MemberEmailRekey::for($this->app)->run(
                                $listingId,
                                $listing->dbprefix,
                                $email,
                                $updateData + ($passwordHash !== null ? ['password' => $passwordHash] : []),
                            );
                            if ($rekey['outcome'] === MemberEmailRekey::DONE) {
                                $results['updated'][] = ['title' => $listing->title, 'subject' => $listing->subject];
                                $confirmationEmail    = $updateData['email'];
                                $ownerEntries[]       = $ownerEntry;
                                $settingsRepo->touchLastUpdated($listingId);
                            } elseif ($rekey['outcome'] === MemberEmailRekey::FAILED) {
                                $results['failed'][] = ['title' => $listing->title, 'subject' => $listing->subject];
                            } else {
                                $results['unconfirmed'][] = ['title' => $listing->title, 'subject' => $listing->subject];
                                $stopped = $rekey['outcome'] === MemberEmailRekey::UNRESOLVED;
                            }
                        } else {
                            $memberRepo->update($listing->dbprefix, $email, $updateData);
                            $results['updated'][] = ['title' => $listing->title, 'subject' => $listing->subject];
                            $ownerEntries[] = $ownerEntry;
                            if (ListingSettingsRepo::changesCountAsUpdate($updateData)) {
                                $settingsRepo->touchLastUpdated($listingId);
                            }
                        }
                    }

                    return true;
                },
            );
        } catch (MemberAddressBusy) {
            $session->set('lstr_flash_error:' . $marker, MemberAddressBusy::MESSAGE);
            return Response::redirect($tokenUrl);
        }

        if ($tooLong !== null) {
            $rateLimit->hitDefault($ip, RateLimit::ACTION_PUBLIC_UPDATE);
            $session->set('lstr_flash_error:' . $marker, $tooLong);
            return Response::redirect($tokenUrl);
        }

        if (!$tokenWasGood) {
            $session->set('lstr_flash_error:' . $marker, 'That update link is invalid or has expired. Please request a new one.');
            return Response::redirect($embedReturnTo);
        }

        if ($results['updated'] !== [] && !$stopped) {
            self::sendUpdateConfirmationEmail($this->app, $confirmationEmail, $results['updated']);
        }

        if (!$stopped) {
            $this->notifyOwners($ownerEntries, $email);
        }

        $unconfirmedPassword = $newPassword !== '' ? " Also, if your new password doesn't work, try your old one." : '';

        // Nothing went through, so this isn't a success with a footnote.
        if ($results['updated'] === [] && $results['queued'] === [] && ($results['failed'] !== [] || $results['unconfirmed'] !== [])) {
            $session->set('lstr_flash_error:' . $marker, $results['unconfirmed'] !== []
                ? "We couldn't confirm your changes were saved. Please check your details before trying again, or contact the listing owner." . $unconfirmedPassword
                : "We couldn't save your changes because of a database error, and nothing was changed." . ($newPassword !== '' ? ' Your old password still works.' : '') . ' Please request a new link and try again.');
            $session->remove('lstr_embed_return_to');
            return Response::redirect($embedReturnTo);
        }

        $sections = [];
        if ($results['updated'] !== []) {
            $sections[] = [
                'intro' => 'Your information has been successfully updated across the following listing(s):',
                'items' => $results['updated'],
                'outro' => 'Your changes are effective immediately.',
            ];
        }
        if ($results['queued'] !== []) {
            $sections[] = [
                'intro' => 'Your requested changes have been submitted for approval on the following listing(s):',
                'items' => $results['queued'],
                'outro' => "You'll see them reflected once an admin approves the update.",
            ];
        }
        if ($results['skipped'] !== [] && ($results['updated'] !== [] || $results['queued'] !== [])) {
            $sections[] = [
                'intro' => 'No changes were needed for the following listing(s), since your details already matched:',
                'items' => $results['skipped'],
                'outro' => '',
            ];
        }
        if ($results['email_conflict'] !== []) {
            $sections[] = [
                'intro' => 'That email address is already in use, so it was not changed for the following listing(s):',
                'items' => $results['email_conflict'],
                'outro' => '',
            ];
        }
        if ($results['failed'] !== []) {
            $sections[] = [
                'intro' => "We couldn't save your changes on the following listing(s) because of a database error, and nothing was changed there:",
                'items' => $results['failed'],
                'outro' => ($newPassword !== '' ? 'Your old password still works there. ' : '') . 'Please request a new link and try again.',
            ];
        }
        if ($results['unconfirmed'] !== []) {
            $sections[] = [
                'intro' => "We couldn't confirm your changes were saved on the following listing(s):",
                'items' => $results['unconfirmed'],
                'outro' => 'Please check your details there before trying again, or contact the listing owner.' . $unconfirmedPassword,
            ];
        }

        if ($sections === []) {
            $session->set('lstr_flash_success_html:' . $marker, '<p class="fl-success">No changes were needed — your membership details were already up to date.</p>');
        } else {
            $session->set('lstr_flash_success_html:' . $marker, self::renderSuccessSections($sections));
        }

        $session->remove('lstr_embed_return_to');
        return Response::redirect($embedReturnTo);
    }

    /**
     * Permanently removes the member's row from every selected listing,
     * discarding any other requested field changes. Mirrors
     * UpdateController's single-listing delete_membership handling, applied
     * across every selected fanlisting, plus the same side-table cleanup
     * MemberController::edit() does for a permanently-removed member
     * (pending updates, link-check history, outstanding reset tokens).
     *
     * @param int[] $selectedIds
     * @param array<int, array{listing: Listing, member: Member}> $validMap
     */
    private function deleteMemberships(
        array $selectedIds,
        array $validMap,
        string $email,
        string $embedReturnTo,
        CollectiveUpdateTokenRepo $tokenRepo,
        string $rawToken,
        string $tokenUrl,
    ): Response {
        $db            = $this->app->getDatabase();
        $config        = $this->app->getConfig();
        $session       = $this->app->getSession();
        $lifecycleRepo = new ListingLifecycleRepo($db);
        $marker        = 'collective_update';

        $deleted   = [];
        $skipped   = [];
        $farewells = [];

        try {
            $tokenWasGood = $this->app->getMemberAddressLock()->run(
                array_map(static fn(int $listingId): array => [$listingId, $email], $selectedIds),
                function () use ($tokenRepo, $rawToken, $email, $selectedIds, $validMap, $lifecycleRepo, &$deleted, &$skipped, &$farewells): bool {
                    if ($tokenRepo->verifyAndConsume($rawToken) === null) {
                        return false;
                    }

                    // Read again now it's locked: who's at this address may have changed.
                    $current = self::membershipsByListing($email, $this->app);

                    foreach ($selectedIds as $listingId) {
                        $listing = $validMap[$listingId]['listing'];
                        $member  = $current[$listingId]['member'] ?? null;

                        // Skip if removed or archived since the memberships were loaded
                        if ($member === null || $lifecycleRepo->getByListingId($listingId) !== null) {
                            $skipped[] = $listing->title;
                            continue;
                        }

                        MemberRemoval::for($this->app)->remove($listingId, $listing->dbprefix, $email);

                        $deleted[]   = $listing->title;
                        $farewells[] = [$listingId, $listing, $member];
                    }

                    return true;
                },
            );
        } catch (MemberAddressBusy) {
            $session->set('lstr_flash_error:' . $marker, MemberAddressBusy::MESSAGE);
            return Response::redirect($tokenUrl);
        }

        if (!$tokenWasGood) {
            $session->set('lstr_flash_error:' . $marker, 'That update link is invalid or has expired. Please request a new one.');
            return Response::redirect($embedReturnTo);
        }

        $session->remove('lstr_embed_return_to');
        $mailer = new Mailer($config);

        foreach ($farewells as [$listingId, $listing, $member]) {
            // Farewell email (best-effort)
            try {
                $emailRepo = new EmailTemplateRepo($db);
                $_es       = (new ListingSettingsRepo($db))->getResolvedEmbedSettings($listingId);
                $tokens    = EmailTemplateRepo::buildTokens(
                    listingName:    $listing->title,
                    listingUrl:     $listing->url,
                    siteName:       (string) $config->get('site_name', 'Luster'),
                    listingSubject: $listing->subject,
                    ownerName:      (string) $config->get('owner_name', ''),
                    ownerEmail:     (string) $config->get('owner_email', ''),
                    fanName:        $member->name,
                    fanEmail:       $email,
                    listingEmail:   $listing->email,
                    formUrlJoin:    (string) $_es['form_url_join'],
                    formUrlUpdate:  (string) $_es['form_url_update'],
                    formUrlReset:   (string) $_es['form_url_reset'],
                    listingType:    (new ListingSettingsRepo($db))->getListingType($listingId),
                );
                [$subject, $body] = $emailRepo->resolveForListing($listingId, EmailTemplateRepo::TYPE_REMOVE, $tokens);
                if ($subject !== '') {
                    $mailer->send($email, $member->name, $subject, $body);
                }
            } catch (\Throwable $e) {
                error_log('[Luster] Collective update removal email failed: ' . $e->getMessage());
            }
        }

        if ($farewells !== []) {
            $this->notifyOwners(
                array_map(static fn(array $farewell): array => self::ownerEntry($farewell[1], $farewell[2], [MemberChangeList::REMOVED_MEMBERSHIP]), $farewells),
                $email,
            );
        }

        $parts = [];
        if ($deleted !== []) {
            $parts[] = 'Membership deleted: ' . implode(', ', array_map('htmlspecialchars', $deleted));
        }
        if ($skipped !== []) {
            $parts[] = 'No longer available: ' . implode(', ', array_map('htmlspecialchars', $skipped));
        }

        $session->set('lstr_flash_success_html:collective_update', '<p class="fl-success">Done! ' . implode('. ', $parts) . '.</p>');
        return Response::redirect($embedReturnTo);
    }

    /**
     * What this form would change for one membership, leaving out anything that already
     * matches. Worked out the same way for the check before the token is used and for the
     * write after it, so what's checked is what's saved.
     *
     * @return array{changes: array<string, mixed>, email_taken: bool}
     */
    private static function changesFor(
        Member $member,
        Listing $listing,
        string $email,
        MemberRepo $memberRepo,
        string $newName,
        bool $deleteUrl,
        string $newUrl,
        string $newCountry,
        string $newEmail,
    ): array {
        $changes = [];
        if ($newName !== '' && $newName !== $member->name) {
            $changes['name'] = $newName;
        }
        if ($deleteUrl) {
            if ($member->url !== '') {
                $changes['url'] = '';
            }
            if ($member->showurl) {
                $changes['showurl'] = 0;
            }
        } elseif ($newUrl !== '' && $newUrl !== $member->url) {
            $changes['url'] = $newUrl;
            if (!$member->showurl) {
                $changes['showurl'] = 1;
            }
        }
        if ($newCountry !== '' && $newCountry !== $member->country) {
            $changes['country'] = $newCountry;
        }

        $emailTaken = false;
        if ($newEmail !== '' && $newEmail !== $email) {
            // Email is unique per-listing (it's the member table's key), so a
            // match elsewhere doesn't block this listing — only a conflict
            // within this specific listing's own member table does.
            if ($memberRepo->getByEmail($listing->dbprefix, $newEmail) !== null) {
                $emailTaken = true;
            } else {
                $changes['email'] = $newEmail;
            }
        }

        return ['changes' => $changes, 'email_taken' => $emailTaken];
    }

    /**
     * findMembershipsForEmail(), keyed by listing id.
     *
     * @return array<int, array{listing: Listing, member: Member}>
     */
    private static function membershipsByListing(string $email, Application $app): array
    {
        $byListing = [];
        foreach (self::findMembershipsForEmail($email, $app) as $membership) {
            $byListing[$membership['listing']->id] = $membership;
        }

        return $byListing;
    }

    /**
     * One listing's part of the owner's notice. $member is as they were before this
     * form changed anything, so the owner sees the name they know this fan by.
     *
     * @param list<string> $lines
     * @return array{listing: Listing, heading: string, lines: list<string>, identity: array<string, string>}
     */
    private static function ownerEntry(Listing $listing, Member $member, array $lines): array
    {
        return [
            'listing'  => $listing,
            'heading'  => $listing->title . ($listing->subject !== '' ? ' (' . $listing->subject . ')' : ''),
            'lines'    => $lines,
            'identity' => ['{fan_name}' => $member->name],
        ];
    }

    /**
     * Tells the owner what this form just did (best-effort: it's already saved). One
     * form can touch many listings, so each address gets one email about its listings.
     *
     * @param list<array{listing: Listing, heading: string, lines: list<string>, identity: array<string, string>}> $entries
     */
    private function notifyOwners(array $entries, string $fanEmail): void
    {
        $notice = OwnerNotice::for($this->app);
        $what   = 'collective update notice';

        try {
            $config   = $this->app->getConfig();
            $siteName = (string) $config->get('site_name', 'Luster');
            $tokens   = EmailTemplateRepo::buildCollectiveUpdateTokens(
                siteName:   $siteName,
                ownerName:  (string) $config->get('owner_name', $siteName),
                ownerEmail: (string) $config->get('owner_email', ''),
                siteUrl:    (string) $config->get('site_url', ''),
            ) + ['{fan_email}' => $fanEmail];

            $notice->sendForEachAddress(EmailTemplateRepo::TYPE_NOTIFY_COLL_UPDATE, $tokens, $entries, $siteName, $what);
        } catch (\Throwable $e) {
            $notice->reportFailure($what, $e);
        }
    }

    /**
     * Sends a single consolidated confirmation email listing every fanlisting
     * whose membership was actually updated (best-effort — a delivery failure
     * doesn't roll back the already-applied changes).
     *
     * @param array<int, array{title: string, subject: string}> $updatedListings
     */
    private static function sendUpdateConfirmationEmail(Application $app, string $email, array $updatedListings): void
    {
        try {
            $config   = $app->getConfig();
            $siteName = (string) $config->get('site_name', 'Luster');

            $membershipList = implode("\n", array_map(
                static fn(array $l) => '- ' . $l['title'] . ($l['subject'] !== '' ? ' (' . $l['subject'] . ')' : ''),
                $updatedListings
            ));

            $tokens = EmailTemplateRepo::buildCollectiveUpdateTokens(
                siteName:       $siteName,
                ownerName:      (string) $config->get('owner_name', $siteName),
                ownerEmail:     (string) $config->get('owner_email', ''),
                membershipList: $membershipList,
                siteUrl:        (string) $config->get('site_url', ''),
            );

            $emailRepo         = new EmailTemplateRepo($app->getDatabase());
            [$subject, $body]  = $emailRepo->resolveForListing(null, EmailTemplateRepo::TYPE_COLLECTIVE_UPDATE_CONFIRM, $tokens);

            if ($subject !== '') {
                (new Mailer($config))->send($email, '', $subject, $body);
            }
        } catch (\Throwable $e) {
            error_log('[Luster] Collective update confirmation email failed: ' . $e->getMessage());
        }
    }

    /**
     * Renders the bulleted-list success message shown after submit(), one
     * section per outcome bucket (updated / queued / skipped / email_conflict).
     *
     * @param array<int, array{intro: string, items: array<int, array{title: string, subject: string}>, outro: string}> $sections
     */
    private static function renderSuccessSections(array $sections): string
    {
        $html = '';
        foreach ($sections as $section) {
            $html .= '<div class="fl-success">';
            $html .= '<p>' . htmlspecialchars($section['intro']) . '</p>';
            $html .= '<ul class="fl-success-list">';
            foreach ($section['items'] as $item) {
                $label = $item['title'] . ($item['subject'] !== '' ? ' (' . $item['subject'] . ')' : '');
                $html .= '<li>' . htmlspecialchars($label) . '</li>';
            }
            $html .= '</ul>';
            if ($section['outro'] !== '') {
                $html .= '<p>' . htmlspecialchars($section['outro']) . '</p>';
            }
            $html .= '</div>';
        }
        return $html;
    }

    /**
     * Finds all active (non-archived) listings where the given email has an
     * approved membership. Returns an array of ['listing' => Listing, 'member' => Member].
     *
     * @return array<int, array{listing: Listing, member: Member}>
     */
    public static function findMembershipsForEmail(string $email, Application $app): array
    {
        $listingRepo   = new ListingRepo($app->getDatabase(), $app->getConfig());
        $memberRepo    = new MemberRepo($app->getDatabase(), $app->getConfig());
        $lifecycleRepo = new ListingLifecycleRepo($app->getDatabase());

        $listings = $listingRepo->getAll();

        if ($listings === []) {
            return [];
        }

        $lifecycleMap = $lifecycleRepo->getMapForListings(
            array_map(static fn(Listing $l) => $l->id, $listings)
        );

        $found = [];
        foreach ($listings as $listing) {
            // Skip archived listings
            if (isset($lifecycleMap[$listing->id])) {
                continue;
            }

            $member = $memberRepo->getByEmail($listing->dbprefix, $email);
            if ($member !== null && $member->approved) {
                $found[] = ['listing' => $listing, 'member' => $member];
            }
        }

        return $found;
    }
}
