<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Handles approve and reject actions for pending member update requests. When a
 fan submits changes through the public update form and the listing has hold-update
 enabled, the changes are queued in lstr_pending_updates. This controller applies
 the queued changes to the member record on approval (sending a notification email
 if configured) or silently discards them on rejection. Both actions redirect back
 to the Members overview.
*/

declare(strict_types=1);

namespace Luster\Controllers\Admin;

use Luster\Core\Application;
use Luster\Core\Config;
use Luster\Core\Database;
use Luster\Core\Mailer;
use Luster\Core\Request;
use Luster\Core\Response;
use Luster\Repositories\ActivityRepo;
use Luster\Repositories\EmailTemplateRepo;
use Luster\Repositories\FanPasswordResetRepo;
use Luster\Repositories\ListingRepo;
use Luster\Repositories\ListingSettingsRepo;
use Luster\Repositories\MemberLinkCheckRepo;
use Luster\Repositories\MemberRepo;
use Luster\Repositories\PendingUpdateRepo;
use Luster\Support\MemberAddressBusy;

/**
 * Handles approve and reject actions for pending member update requests.
 *
 * When a fan submits the public update form and the listing has hold-update
 * enabled, the changes are queued in lstr_pending_updates rather than applied
 * immediately. This controller applies the queued changes to the member record
 * on approval (sending a notification email if configured) or silently discards
 * them on rejection. Both single-record and bulk actions redirect back to the
 * Members page at the appropriate listing/status context.
 */
final class PendingUpdateController
{
    private const MOVED_MESSAGE = 'That update changed while it was being approved. Try again.';

    public function __construct(private readonly Application $app) {}

    /**
     * Applies one queued request with its addresses locked, the same way for every
     * approval path. The row is read again under the lock, and an earlier row in a
     * batch may have renamed its member, so a row that now needs an address that
     * isn't held is left alone. Mail is the caller's job, after this returns.
     *
     * @return array{outcome: string, pending: ?object, listing: ?object, member: ?object}
     *         outcome is applied, gone, busy, moved, no_listing, no_member or conflict.
     */
    public function applyQueued(int $id): array
    {
        $db     = $this->app->getDatabase();
        $config = $this->app->getConfig();
        $lock   = $this->app->getMemberAddressLock();

        $pendingRepo = new PendingUpdateRepo($db);
        $listingRepo = new ListingRepo($db, $config);
        $memberRepo  = new MemberRepo($db, $config);

        $result = static fn(string $outcome, ?object $pending = null, ?object $listing = null, ?object $member = null): array =>
            ['outcome' => $outcome, 'pending' => $pending, 'listing' => $listing, 'member' => $member];

        $firstLook = $pendingRepo->getById($id);
        if ($firstLook === null) {
            return $result('gone');
        }

        try {
            return $lock->run(self::addressesOf($firstLook), function () use ($id, $db, $config, $lock, $pendingRepo, $listingRepo, $memberRepo, $result): array {
                $pending = $pendingRepo->getById($id);
                if ($pending === null) {
                    return $result('gone');
                }
                if (!$lock->holds(self::addressesOf($pending))) {
                    return $result('moved', $pending);
                }

                $listing = $listingRepo->getById($pending->fanlisting_id);
                if ($listing === null) {
                    $pendingRepo->delete($id);
                    return $result('no_listing', $pending);
                }

                // Verify the member still exists
                $member = $memberRepo->getByEmail($listing->dbprefix, $pending->member_email);
                if ($member === null) {
                    $pendingRepo->delete($id);
                    return $result('no_member', $pending, $listing);
                }

                if (self::hasEmailConflict($pending, $listing, $memberRepo)) {
                    $pendingRepo->delete($id);
                    return $result('conflict', $pending, $listing, $member);
                }

                // Apply the queued changes
                $memberRepo->update($listing->dbprefix, $pending->member_email, $pending->changes);
                self::rekeyOnEmailChange($db, $config, $pending);
                $pendingRepo->delete($id);

                return $result('applied', $pending, $listing, $member);
            });
        } catch (MemberAddressBusy) {
            return $result('busy', $firstLook);
        }
    }

    /**
     * The address a queued request is at, and the one it asks for.
     *
     * @return list<array{0: int, 1: string}>
     */
    private static function addressesOf(object $pending): array
    {
        $addresses = [[$pending->fanlisting_id, $pending->member_email]];
        $requested = $pending->changes['email'] ?? '';
        if (is_string($requested) && $requested !== '') {
            $addresses[] = [$pending->fanlisting_id, $requested];
        }

        return $addresses;
    }

    // -----------------------------------------------------------------------
    // POST /admin/pending-updates/{id}/approve
    // -----------------------------------------------------------------------

    /**
     * Approves a single pending update and applies its changes to the member record.
     *
     * Looks up the pending update, verifies both the listing and the member still
     * exist, guards against email-address conflicts, writes the changes, sends an
     * update-confirmation email to the fan (best-effort), and logs the action.
     * Deletes the pending row regardless of email-send outcome.
     */
    public function approve(Request $request, array $params): Response
    {
        $id = (int) ($params['id'] ?? 0);

        $db      = $this->app->getDatabase();
        $config  = $this->app->getConfig();
        $session = $this->app->getSession();
        $baseUrl = $this->app->getBaseUrl();

        $activityRepo = new ActivityRepo($db);

        $applied = $this->applyQueued($id);

        $problem = match ($applied['outcome']) {
            'applied'    => null,
            'gone'       => 'Pending update not found.',
            'busy'       => MemberAddressBusy::MESSAGE,
            'moved'      => self::MOVED_MESSAGE,
            'no_listing' => 'Associated listing no longer exists.',
            'no_member'  => 'Member no longer exists — update discarded.',
            'conflict'   => 'Cannot apply update — the requested email is already taken by another member. Update discarded.',
        };
        if ($problem !== null) {
            $session->set('lstr_flash_error', $problem);
            return Response::redirect($baseUrl . '/admin/members');
        }

        $pending = $applied['pending'];
        $listing = $applied['listing'];
        $member  = $applied['member'];

        $finalEmail = (string) ($pending->changes['email'] ?? $pending->member_email);
        $finalName  = (string) ($pending->changes['name']  ?? $member->name);

        // Sync member link check snapshot (best-effort)
        $updatedUrl = (string) ($pending->changes['url'] ?? $member->url ?? '');
        if ($updatedUrl !== '') {
            try {
                (new MemberLinkCheckRepo($db, $config))->upsertMember(
                    $pending->fanlisting_id,
                    $finalEmail,
                    $finalName,
                    $updatedUrl,
                );
            } catch (\Throwable) {}
        }

        // Notify the fan that their update has been applied (best-effort)
        try {
            $cfg       = $this->app->getConfig();
            $mailer    = new Mailer($cfg);
            $emailRepo = new EmailTemplateRepo($db);
            $_es       = (new ListingSettingsRepo($db))->getResolvedEmbedSettings($pending->fanlisting_id);
            $tokens    = EmailTemplateRepo::buildTokens(
                listingName:    $listing->title,
                listingUrl:     $listing->url,
                siteName:       (string) $cfg->get('site_name', 'Luster'),
                listingSubject: $listing->subject,
                ownerName:      (string) $cfg->get('owner_name', ''),
                ownerEmail:     (string) $cfg->get('owner_email', ''),
                fanName:        $finalName,
                fanEmail:       $finalEmail,
                listingEmail:   $listing->email,
                formUrlJoin:    (string) $_es['form_url_join'],
                formUrlUpdate:  (string) $_es['form_url_update'],
                formUrlReset:   (string) $_es['form_url_reset'],
                listingType:    (new ListingSettingsRepo($db))->getListingType($pending->fanlisting_id),
            );
            [$subject, $body] = $emailRepo->resolveForListing($pending->fanlisting_id, EmailTemplateRepo::TYPE_UPDATE, $tokens);
            if ($subject !== '') { $mailer->send($finalEmail, $finalName, $subject, $body); }
        } catch (\Throwable $e) {
            error_log('[Luster] Pending update approval email failed: ' . $e->getMessage());
        }

        $activityRepo->log(
            (int) $session->get('lstr_admin_id'),
            'pending_update.approve',
            "Approved update #{$id} for {$pending->member_email} on {$listing->title}",
            $request->ip(),
        );

        $session->set('lstr_flash_success', "Update for {$finalEmail} approved and applied.");
        return Response::redirect(
            $baseUrl . '/admin/members?listing_id=' . $pending->fanlisting_id . '&status=pending'
        );
    }

    // -----------------------------------------------------------------------
    // POST /admin/pending-updates/approve-all
    // -----------------------------------------------------------------------

    /**
     * Approves all pending updates for a listing (or all listings when no listing ID is given).
     *
     * Iterates every pending row in scope, skipping any where the listing or member
     * no longer exists or where the requested email is already taken. Sends
     * update-confirmation emails best-effort per approved row. Reports a summary
     * of how many updates were applied and how many were skipped.
     */
    public function approveAll(Request $request, array $params): Response
    {
        $db      = $this->app->getDatabase();
        $config  = $this->app->getConfig();
        $session = $this->app->getSession();
        $baseUrl = $this->app->getBaseUrl();

        $listingId    = (int) $request->post('_listing_id', 0);
        $pendingRepo  = new PendingUpdateRepo($db);
        $activityRepo = new ActivityRepo($db);
        $mailer       = new Mailer($config);

        $pendings = $listingId > 0
            ? $pendingRepo->getByListing($listingId)
            : $pendingRepo->getAll();

        $count    = 0;
        $skipped  = 0;
        $busy     = 0;

        foreach ($pendings as $pendingStale) {
            // applyQueued() re-fetches by ID: an earlier row in this same batch may
            // have renamed this row's member_email via rekeyOnEmailChange()
            // (renameMemberEmail() re-keys every sibling row for that
            // listing+old-email, not just the one being approved), so the
            // copy loaded before the loop started can be stale.
            $applied = $this->applyQueued($pendingStale->id);
            if ($applied['outcome'] === 'conflict') {
                $skipped++;
            } elseif (in_array($applied['outcome'], ['busy', 'moved'], true)) {
                $busy++;
            }
            if ($applied['outcome'] !== 'applied') {
                continue;
            }

            $pending = $applied['pending'];
            $listing = $applied['listing'];
            $member  = $applied['member'];

            $finalEmail = (string) ($pending->changes['email'] ?? $pending->member_email);
            $finalName  = (string) ($pending->changes['name']  ?? $member->name);

            // Sync member link check snapshot (best-effort)
            $_updatedUrl = (string) ($pending->changes['url'] ?? $member->url ?? '');
            if ($_updatedUrl !== '') {
                try {
                    (new MemberLinkCheckRepo($db, $config))->upsertMember(
                        $pending->fanlisting_id, $finalEmail, $finalName, $_updatedUrl,
                    );
                } catch (\Throwable) {}
            }

            try {
                $emailRepo = new EmailTemplateRepo($db);
                $_es       = (new ListingSettingsRepo($db))->getResolvedEmbedSettings($pending->fanlisting_id);
                $tokens    = EmailTemplateRepo::buildTokens(
                    listingName:    $listing->title,
                    listingUrl:     $listing->url,
                    siteName:       (string) $config->get('site_name', 'Luster'),
                    listingSubject: $listing->subject,
                    ownerName:      (string) $config->get('owner_name', ''),
                    ownerEmail:     (string) $config->get('owner_email', ''),
                    fanName:        $finalName,
                    fanEmail:       $finalEmail,
                    listingEmail:   $listing->email,
                    formUrlJoin:    (string) $_es['form_url_join'],
                    formUrlUpdate:  (string) $_es['form_url_update'],
                    formUrlReset:   (string) $_es['form_url_reset'],
                    listingType:    (new ListingSettingsRepo($db))->getListingType($pending->fanlisting_id),
                );
                [$subject, $body] = $emailRepo->resolveForListing($pending->fanlisting_id, EmailTemplateRepo::TYPE_UPDATE, $tokens);
                if ($subject !== '') { $mailer->send($finalEmail, $finalName, $subject, $body); }
            } catch (\Throwable $e) {
                error_log('[Luster] Approve-all-updates email failed for ' . $finalEmail . ': ' . $e->getMessage());
            }

            $count++;
        }

        $activityRepo->log(
            (int) $session->get('lstr_admin_id'),
            'pending_update.approve_all',
            "Approved all {$count} pending update(s)" . ($listingId > 0 ? " for listing #{$listingId}" : ' across all listings'),
            $request->ip(),
        );

        $msg = "Approved {$count} pending update" . ($count !== 1 ? 's' : '') . '.';
        if ($skipped > 0) {
            $msg .= " Skipped {$skipped} because the requested email was already taken.";
        }
        if ($busy > 0) {
            $msg .= " {$busy} couldn't be applied just now, so they're still in the queue. Try again in a moment.";
        }
        $session->set('lstr_flash_success', $msg);

        $redirect = $listingId > 0
            ? $baseUrl . '/admin/members?listing_id=' . $listingId . '&status=pending'
            : $baseUrl . '/admin/members?status=pending';
        return Response::redirect($redirect);
    }

    // -----------------------------------------------------------------------
    // POST /admin/pending-updates/bulk
    // -----------------------------------------------------------------------

    /**
     * Approves or rejects a specific set of pending updates identified by their IDs.
     *
     * For rejections, rows are deleted immediately without applying changes.
     * For approvals, each row goes through the same guards as the single-approve
     * action (listing exists, member exists, no email conflict). No notification
     * emails are sent during bulk approve — those go through the single-approve path.
     */
    public function bulk(Request $request, array $params): Response
    {
        $db      = $this->app->getDatabase();
        $config  = $this->app->getConfig();
        $session = $this->app->getSession();
        $baseUrl = $this->app->getBaseUrl();

        $action = (string) $request->post('bulk_action', '');
        $ids    = array_filter(array_map('intval', (array) $request->post('ids', [])));

        if (empty($ids) || !in_array($action, ['approve', 'reject'], true)) {
            $session->set('lstr_flash_error', 'No updates selected.');
            return Response::redirect($baseUrl . '/admin/members');
        }

        $pendingRepo  = new PendingUpdateRepo($db);
        $activityRepo = new ActivityRepo($db);

        $pendings = $pendingRepo->getByIds($ids);
        $count    = 0;
        $skipped  = 0;
        $busy     = 0;

        foreach ($pendings as $pendingStale) {
            if ($action === 'reject') {
                $pendingRepo->delete($pendingStale->id);
                $count++;
                continue;
            }

            // approve — applyQueued() re-fetches by ID: an earlier row in this same
            // batch may have renamed this row's member_email via rekeyOnEmailChange()
            // (renameMemberEmail() re-keys every sibling row for that
            // listing+old-email, not just the one being approved), so the
            // copy loaded before the loop started can be stale.
            $applied = $this->applyQueued($pendingStale->id);
            if ($applied['outcome'] === 'conflict') {
                $skipped++;
            } elseif (in_array($applied['outcome'], ['busy', 'moved'], true)) {
                $busy++;
            }
            if ($applied['outcome'] !== 'applied') {
                continue;
            }

            $pending = $applied['pending'];
            $member  = $applied['member'];

            $finalEmail = (string) ($pending->changes['email'] ?? $pending->member_email);
            $finalName  = (string) ($pending->changes['name']  ?? $member->name);

            // Sync member link check snapshot (best-effort)
            $_bUrl = (string) ($pending->changes['url'] ?? $member->url ?? '');
            if ($_bUrl !== '') {
                try {
                    (new MemberLinkCheckRepo($db, $config))->upsertMember(
                        $pending->fanlisting_id, $finalEmail, $finalName, $_bUrl,
                    );
                } catch (\Throwable) {}
            }

            $count++;
        }

        $actionPastTense = $action === 'approve' ? 'Approved' : 'Rejected';

        $activityRepo->log(
            (int) $session->get('lstr_admin_id'),
            'pending_update.bulk_' . $action,
            "Bulk {$actionPastTense} {$count} pending update(s)",
            $request->ip(),
        );

        $msg = "{$actionPastTense} {$count} pending update" . ($count !== 1 ? 's' : '') . '.';
        if ($skipped > 0) {
            $msg .= " Skipped {$skipped} because the requested email was already taken.";
        }
        if ($busy > 0) {
            $msg .= " {$busy} couldn't be applied just now, so they're still in the queue. Try again in a moment.";
        }
        $session->set('lstr_flash_success', $msg);

        $returnListingId = (int) $request->post('_listing_id', 0);
        $redirect = $returnListingId > 0
            ? $baseUrl . '/admin/members?listing_id=' . $returnListingId . '&status=pending'
            : $baseUrl . '/admin/members';
        return Response::redirect($redirect);
    }

    // -----------------------------------------------------------------------
    // POST /admin/pending-updates/{id}/reject
    // -----------------------------------------------------------------------

    /**
     * Rejects and discards a single pending update without applying any changes.
     *
     * The pending row is deleted and the action is logged. The fan receives no
     * notification on rejection.
     */
    public function reject(Request $request, array $params): Response
    {
        $id = (int) ($params['id'] ?? 0);

        $db      = $this->app->getDatabase();
        $session = $this->app->getSession();
        $baseUrl = $this->app->getBaseUrl();

        $pendingRepo  = new PendingUpdateRepo($db);
        $activityRepo = new ActivityRepo($db);

        $pending = $pendingRepo->getById($id);

        if ($pending === null) {
            $session->set('lstr_flash_error', 'Pending update not found.');
            return Response::redirect($baseUrl . '/admin/members');
        }

        $pendingRepo->delete($id);

        $activityRepo->log(
            (int) $session->get('lstr_admin_id'),
            'pending_update.reject',
            "Rejected update #{$id} for {$pending->member_email}",
            $request->ip(),
        );

        $session->set('lstr_flash_success', "Update for {$pending->member_email} rejected.");
        return Response::redirect(
            $baseUrl . '/admin/members?listing_id=' . $pending->fanlisting_id . '&status=pending'
        );
    }

    /**
     * Returns true if the pending update requests an email address already held by
     * a different member in the same listing's member table.
     */
    private static function hasEmailConflict(object $pending, object $listing, MemberRepo $memberRepo): bool
    {
        $changes = $pending->changes;
        if (!isset($changes['email']) || $changes['email'] === $pending->member_email) {
            return false;
        }
        return $memberRepo->getByEmail($listing->dbprefix, $changes['email']) !== null;
    }

    /**
     * Re-keys side tables that reference the member by email string (no FK) so
     * queued updates and link-check history follow an email rename instead of
     * being orphaned under the old address, and invalidates any outstanding
     * password-reset token issued to the old address. Mirrors the same
     * re-keying MemberController::edit() does for admin-initiated email changes.
     *
     * No-op when this pending update doesn't actually change the email.
     */
    private static function rekeyOnEmailChange(Database $db, Config $config, object $pending): void
    {
        $newEmail = (string) ($pending->changes['email'] ?? '');
        if ($newEmail === '' || $newEmail === $pending->member_email) {
            return;
        }

        (new PendingUpdateRepo($db))->renameMemberEmail($pending->fanlisting_id, $pending->member_email, $newEmail);
        try {
            (new MemberLinkCheckRepo($db, $config))->renameMemberEmail($pending->fanlisting_id, $pending->member_email, $newEmail);
        } catch (\Throwable) {}
        (new FanPasswordResetRepo($db))->deleteByEmail($pending->fanlisting_id, $pending->member_email);
    }
}
