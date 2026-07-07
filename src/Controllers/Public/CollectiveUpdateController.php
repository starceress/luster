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
use Luster\Core\Config;
use Luster\Core\Database;
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
use Luster\Repositories\FanPasswordResetRepo;
use Luster\Repositories\ListingLifecycleRepo;
use Luster\Repositories\ListingRepo;
use Luster\Repositories\ListingSettingsRepo;
use Luster\Repositories\MemberLinkCheckRepo;
use Luster\Repositories\MemberRepo;
use Luster\Repositories\PendingUpdateRepo;

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

        if ($rateLimit->isBlocked($ip, RateLimit::ACTION_PUBLIC_COLLECTIVE_UPDATE_REQ)) {
            $session->set('lstr_flash_error', 'Too many attempts. Please try again later.');
            return Response::redirect($embedReturnTo);
        }

        // Honeypot — silent accept so bots see no difference
        if ((string) $request->post('website_confirm', '') !== '') {
            $session->set('lstr_flash_success_html', '<p class="fl-success">If your email is registered for one or more fanlistings here, you\'ll receive a verification link shortly.</p>');
            return Response::redirect($embedReturnTo);
        }

        // Count every real submission against the rate limit, not just invalid ones
        $rateLimit->hitDefault($ip, RateLimit::ACTION_PUBLIC_COLLECTIVE_UPDATE_REQ);

        $email = trim((string) $request->post('email', ''));

        $v = new Validator(['email' => $email]);
        $v->field('email')->required()->email()->maxLength(255);

        if ($v->fails()) {
            $session->set('lstr_flash_error', implode(' ', $v->allErrors()));
            $session->set('lstr_flash_old_email', $email);
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
        $session->set('lstr_flash_success_html', '<p class="fl-success">If your email is registered for one or more fanlistings here, you\'ll receive a verification link shortly.</p>');
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

        if ($rateLimit->isBlocked($ip, RateLimit::ACTION_PUBLIC_UPDATE)) {
            $session->set('lstr_flash_error', 'Too many attempts. Please try again later.');
            return Response::redirect($embedReturnTo);
        }

        $rawToken = (string) $request->post('_cu_token', '');

        if ($rawToken === '') {
            $session->set('lstr_flash_error', 'Missing update token. Please use the link from your email.');
            return Response::redirect($embedReturnTo);
        }

        $tokenRepo = new CollectiveUpdateTokenRepo($this->app->getDatabase());
        $tokenRow  = $tokenRepo->findValid($rawToken);

        if ($tokenRow === null) {
            $session->set('lstr_flash_error', 'That update link is invalid or has expired. Please request a new one.');
            return Response::redirect($embedReturnTo);
        }

        $email = (string) $tokenRow['email'];

        // Build the token URL so validation errors redirect back to Step 2
        // rather than to the clean host URL (which would drop the form entirely).
        $sep      = str_contains($embedReturnTo, '?') ? '&' : '?';
        $tokenUrl = $embedReturnTo . $sep . '_luster_cu_token=' . urlencode($rawToken);

        $memberships = self::findMembershipsForEmail($email, $this->app);

        if ($memberships === []) {
            $session->set('lstr_flash_error', 'No active memberships were found for your email address.');
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
            $session->set('lstr_flash_error', 'Please select at least one fanlisting to update.');
            return Response::redirect($tokenUrl);
        }

        // Permanently delete membership instead of updating it — skips every
        // other field entirely, matching the single-listing update form.
        if ($deleteMembership) {
            if ($tokenRepo->verifyAndConsume($rawToken) === null) {
                $session->set('lstr_flash_error', 'That update link is invalid or has expired. Please request a new one.');
                return Response::redirect($embedReturnTo);
            }

            $session->remove('lstr_embed_return_to');
            return $this->deleteMemberships($selectedIds, $validMap, $email, $embedReturnTo);
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
            $session->set('lstr_flash_error', implode(' ', $v->allErrors()));
            return Response::redirect($tokenUrl);
        }

        if ($newPassword !== '' && $newPassword !== $newPasswordConfirm) {
            $rateLimit->hitDefault($ip, RateLimit::ACTION_PUBLIC_UPDATE);
            $session->set('lstr_flash_error', 'New passwords do not match. Please try again.');
            return Response::redirect($tokenUrl);
        }

        if ($newEmail !== '' && $newEmail !== $email && (new BlacklistRepo($this->app->getDatabase()))->isBlocked($newEmail)) {
            $rateLimit->hitDefault($ip, RateLimit::ACTION_PUBLIC_UPDATE);
            $session->set('lstr_flash_error', 'That email address cannot be used.');
            return Response::redirect($tokenUrl);
        }

        // All validation passed — atomically consume the token now.
        // verifyAndConsume() returning null here means a race beat us to it;
        // treat it as an expired token rather than applying updates twice.
        if ($tokenRepo->verifyAndConsume($rawToken) === null) {
            $session->set('lstr_flash_error', 'That update link is invalid or has expired. Please request a new one.');
            return Response::redirect($embedReturnTo);
        }

        $memberRepo    = new MemberRepo($this->app->getDatabase(), $this->app->getConfig());
        $settingsRepo  = new ListingSettingsRepo($this->app->getDatabase());
        $lifecycleRepo = new ListingLifecycleRepo($this->app->getDatabase());
        $results       = ['updated' => [], 'queued' => [], 'skipped' => [], 'email_conflict' => []];

        // Hash once; applied immediately to every selected listing (never queued)
        $passwordHash = $newPassword !== '' ? password_hash($newPassword, PASSWORD_ARGON2ID) : null;

        foreach ($selectedIds as $listingId) {
            $entry   = $validMap[$listingId];
            $listing = $entry['listing'];
            $member  = $entry['member'];

            // Skip if archived since the memberships were loaded
            if ($lifecycleRepo->getByListingId($listingId) !== null) {
                $results['skipped'][] = $listing->title;
                continue;
            }

            // Password always applied immediately — never queued
            $passwordChanged = false;
            if ($passwordHash !== null) {
                $memberRepo->update($listing->dbprefix, $email, ['password' => $passwordHash]);
                $passwordChanged = true;
            }

            $updateData = [];
            if ($newName !== '' && $newName !== $member->name) {
                $updateData['name'] = $newName;
            }
            if ($deleteUrl) {
                $updateData['url']     = '';
                $updateData['showurl'] = 0;
            } elseif ($newUrl !== '' && $newUrl !== $member->url) {
                $updateData['url']     = $newUrl;
                $updateData['showurl'] = 1;
            }
            if ($newCountry !== '' && $newCountry !== $member->country) {
                $updateData['country'] = $newCountry;
            }
            if ($newEmail !== '' && $newEmail !== $email) {
                // Email is unique per-listing (it's the member table's key), so a
                // match elsewhere doesn't block this listing — only a conflict
                // within this specific listing's own member table does.
                if ($memberRepo->getByEmail($listing->dbprefix, $newEmail) !== null) {
                    $results['email_conflict'][] = $listing->title;
                } else {
                    $updateData['email'] = $newEmail;
                }
            }

            if ($updateData === []) {
                if (!$passwordChanged) {
                    $results['skipped'][] = $listing->title;
                } else {
                    $results['updated'][] = $listing->title;
                }
                continue;
            }

            if ($settingsRepo->getQueueUpdates($listingId)) {
                (new PendingUpdateRepo($this->app->getDatabase()))->insert($listingId, $email, $updateData);
                $results['queued'][] = $listing->title;
            } else {
                $memberRepo->update($listing->dbprefix, $email, $updateData);
                self::rekeyOnEmailChange($this->app->getDatabase(), $this->app->getConfig(), $listingId, $email, $updateData);
                $results['updated'][] = $listing->title;
            }
        }

        $parts = [];
        if ($results['updated'] !== []) {
            $parts[] = 'Updated: ' . implode(', ', array_map('htmlspecialchars', $results['updated']));
        }
        if ($results['queued'] !== []) {
            $parts[] = 'Pending approval: ' . implode(', ', array_map('htmlspecialchars', $results['queued']));
        }
        if ($results['skipped'] !== [] && ($results['updated'] !== [] || $results['queued'] !== [])) {
            $parts[] = 'No changes: ' . implode(', ', array_map('htmlspecialchars', $results['skipped']));
        }
        if ($results['email_conflict'] !== []) {
            $parts[] = 'Email address already in use, so it was not changed for: ' . implode(', ', array_map('htmlspecialchars', $results['email_conflict']));
        }

        if ($parts === []) {
            $session->set('lstr_flash_success_html', '<p class="fl-success">No changes were needed — your membership details were already up to date.</p>');
        } else {
            $session->set('lstr_flash_success_html', '<p class="fl-success">Done! ' . implode('. ', $parts) . '.</p>');
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
    private function deleteMemberships(array $selectedIds, array $validMap, string $email, string $embedReturnTo): Response
    {
        $db            = $this->app->getDatabase();
        $config        = $this->app->getConfig();
        $session       = $this->app->getSession();
        $memberRepo    = new MemberRepo($db, $config);
        $lifecycleRepo = new ListingLifecycleRepo($db);
        $pendingRepo   = new PendingUpdateRepo($db);
        $mailer        = new Mailer($config);

        $deleted = [];
        $skipped = [];

        foreach ($selectedIds as $listingId) {
            $entry   = $validMap[$listingId];
            $listing = $entry['listing'];
            $member  = $entry['member'];

            // Skip if archived since the memberships were loaded
            if ($lifecycleRepo->getByListingId($listingId) !== null) {
                $skipped[] = $listing->title;
                continue;
            }

            $memberRepo->deleteByEmail($listing->dbprefix, $email);
            $pendingRepo->deleteByListingAndEmail($listingId, $email);
            (new FanPasswordResetRepo($db))->deleteByEmail($listingId, $email);
            try {
                (new MemberLinkCheckRepo($db, $config))->removeMember($listingId, $email);
            } catch (\Throwable) {}

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

            $deleted[] = $listing->title;
        }

        $parts = [];
        if ($deleted !== []) {
            $parts[] = 'Membership deleted: ' . implode(', ', array_map('htmlspecialchars', $deleted));
        }
        if ($skipped !== []) {
            $parts[] = 'No longer available: ' . implode(', ', array_map('htmlspecialchars', $skipped));
        }

        $session->set('lstr_flash_success_html', '<p class="fl-success">Done! ' . implode('. ', $parts) . '.</p>');
        return Response::redirect($embedReturnTo);
    }

    /**
     * Re-keys side tables that reference the member by email string (no FK) so
     * queued updates and link-check history follow an email rename instead of
     * being orphaned under the old address, and invalidates any outstanding
     * password-reset token issued to the old address. Mirrors the same
     * re-keying MemberController::edit() does for admin-initiated email changes.
     *
     * No-op when $updateData doesn't actually change the email.
     *
     * @param array<string, mixed> $updateData
     */
    private static function rekeyOnEmailChange(Database $db, Config $config, int $listingId, string $oldEmail, array $updateData): void
    {
        $newEmail = (string) ($updateData['email'] ?? '');
        if ($newEmail === '' || $newEmail === $oldEmail) {
            return;
        }

        (new PendingUpdateRepo($db))->renameMemberEmail($listingId, $oldEmail, $newEmail);
        try {
            (new MemberLinkCheckRepo($db, $config))->renameMemberEmail($listingId, $oldEmail, $newEmail);
        } catch (\Throwable) {}
        (new FanPasswordResetRepo($db))->deleteByEmail($listingId, $oldEmail);
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
