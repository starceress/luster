<?php

declare(strict_types=1);

namespace Luster\Controllers\Admin;

use Luster\Core\Application;
use Luster\Core\Mailer;
use Luster\Core\Request;
use Luster\Core\Response;
use Luster\Repositories\ActivityRepo;
use Luster\Repositories\EmailTemplateRepo;
use Luster\Repositories\ListingRepo;
use Luster\Repositories\MemberLinkCheckRepo;
use Luster\Repositories\MemberRepo;
use Luster\Repositories\PendingUpdateRepo;

/**
 * Handles approve/reject POST actions for pending member update requests.
 *
 * The list and inline detail view live in the Members overview page
 * (templates/admin/members/overview.php). These handlers redirect back there.
 *
 * Routes:
 *   POST /admin/pending-updates/{id}/approve — apply changes + delete record
 *   POST /admin/pending-updates/{id}/reject  — delete record without applying
 */
final class PendingUpdateController
{
    public function __construct(private readonly Application $app) {}

    // -----------------------------------------------------------------------
    // POST /admin/pending-updates/{id}/approve
    // -----------------------------------------------------------------------

    public function approve(Request $request, array $params): Response
    {
        $id = (int) ($params['id'] ?? 0);

        $db      = $this->app->getDatabase();
        $config  = $this->app->getConfig();
        $session = $this->app->getSession();
        $baseUrl = $this->app->getBaseUrl();

        $pendingRepo  = new PendingUpdateRepo($db);
        $listingRepo  = new ListingRepo($db, $config);
        $memberRepo   = new MemberRepo($db, $config);
        $activityRepo = new ActivityRepo($db);

        $pending = $pendingRepo->getById($id);

        if ($pending === null) {
            $session->set('lstr_flash_error', 'Pending update not found.');
            return Response::redirect($baseUrl . '/admin/members');
        }

        $listing = $listingRepo->getById($pending->fanlisting_id);

        if ($listing === null) {
            $session->set('lstr_flash_error', 'Associated listing no longer exists.');
            $pendingRepo->delete($id);
            return Response::redirect($baseUrl . '/admin/members');
        }

        // Verify the member still exists
        $member = $memberRepo->getByEmail($listing->dbprefix, $pending->member_email);

        if ($member === null) {
            $session->set('lstr_flash_error', 'Member no longer exists — update discarded.');
            $pendingRepo->delete($id);
            return Response::redirect($baseUrl . '/admin/members');
        }

        // Apply the queued changes
        $memberRepo->update($listing->dbprefix, $pending->member_email, $pending->changes);

        $pendingRepo->delete($id);

        // Sync member link check snapshot (best-effort)
        $updatedUrl = (string) ($pending->changes['url'] ?? $member->url ?? '');
        if ($updatedUrl !== '') {
            try {
                $updatedName = (string) ($pending->changes['name'] ?? $member->name ?? '');
                (new MemberLinkCheckRepo($db, $config))->upsertMember(
                    $pending->fanlisting_id,
                    $pending->member_email,
                    $updatedName,
                    $updatedUrl,
                );
            } catch (\Throwable) {}
        }

        // Notify the fan that their update has been applied (best-effort)
        try {
            $cfg       = $this->app->getConfig();
            $mailer    = new Mailer($cfg);
            $emailRepo = new EmailTemplateRepo($db);
            $tokens    = EmailTemplateRepo::buildTokens(
                listingName:    $listing->title,
                listingUrl:     $listing->url,
                siteName:       (string) $cfg->get('site_name', 'Luster'),
                listingSubject: $listing->subject,
                ownerName:      (string) $cfg->get('owner_name', ''),
                ownerEmail:     (string) $cfg->get('owner_email', ''),
                fanName:        $member->name,
                fanEmail:       $member->email,
            );
            [$subject, $body] = $emailRepo->resolveForListing($pending->fanlisting_id, EmailTemplateRepo::TYPE_UPDATE, $tokens);
            $mailer->send($member->email, $member->name, $subject, $body);
        } catch (\Throwable $e) {
            error_log('[Luster] Pending update approval email failed: ' . $e->getMessage());
        }

        $activityRepo->log(
            (int) $session->get('lstr_admin_id'),
            'pending_update.approve',
            "Approved update #{$id} for {$pending->member_email} on {$listing->title}",
            $request->ip(),
        );

        $session->set('lstr_flash_success', "Update for {$pending->member_email} approved and applied.");
        return Response::redirect(
            $baseUrl . '/admin/members?listing_id=' . $pending->fanlisting_id . '&status=pending'
        );
    }

    // -----------------------------------------------------------------------
    // POST /admin/pending-updates/approve-all
    // -----------------------------------------------------------------------

    public function approveAll(Request $request, array $params): Response
    {
        $db      = $this->app->getDatabase();
        $config  = $this->app->getConfig();
        $session = $this->app->getSession();
        $baseUrl = $this->app->getBaseUrl();

        $listingId    = (int) $request->post('_listing_id', 0);
        $pendingRepo  = new PendingUpdateRepo($db);
        $listingRepo  = new ListingRepo($db, $config);
        $memberRepo   = new MemberRepo($db, $config);
        $activityRepo = new ActivityRepo($db);
        $mailer       = new Mailer($config);

        $pendings = $listingId > 0
            ? $pendingRepo->getByListing($listingId)
            : $pendingRepo->getAll();

        $count = 0;

        foreach ($pendings as $pending) {
            $listing = $listingRepo->getById($pending->fanlisting_id);
            if ($listing === null) {
                $pendingRepo->delete($pending->id);
                continue;
            }

            $member = $memberRepo->getByEmail($listing->dbprefix, $pending->member_email);
            if ($member === null) {
                $pendingRepo->delete($pending->id);
                continue;
            }

            $memberRepo->update($listing->dbprefix, $pending->member_email, $pending->changes);
            $pendingRepo->delete($pending->id);

            // Sync member link check snapshot (best-effort)
            $_updatedUrl = (string) ($pending->changes['url'] ?? $member->url ?? '');
            if ($_updatedUrl !== '') {
                try {
                    $_updatedName = (string) ($pending->changes['name'] ?? $member->name ?? '');
                    (new MemberLinkCheckRepo($db, $config))->upsertMember(
                        $pending->fanlisting_id,
                        $pending->member_email,
                        $_updatedName,
                        $_updatedUrl,
                    );
                } catch (\Throwable) {}
            }

            try {
                $emailRepo = new EmailTemplateRepo($db);
                $tokens    = EmailTemplateRepo::buildTokens(
                    listingName:    $listing->title,
                    listingUrl:     $listing->url,
                    siteName:       (string) $config->get('site_name', 'Luster'),
                    listingSubject: $listing->subject,
                    ownerName:      (string) $config->get('owner_name', ''),
                    ownerEmail:     (string) $config->get('owner_email', ''),
                    fanName:        $member->name,
                    fanEmail:       $member->email,
                );
                [$subject, $body] = $emailRepo->resolveForListing($pending->fanlisting_id, EmailTemplateRepo::TYPE_UPDATE, $tokens);
                $mailer->send($member->email, $member->name, $subject, $body);
            } catch (\Throwable $e) {
                error_log('[Luster] Approve-all-updates email failed for ' . $pending->member_email . ': ' . $e->getMessage());
            }

            $count++;
        }

        $activityRepo->log(
            (int) $session->get('lstr_admin_id'),
            'pending_update.approve_all',
            "Approved all {$count} pending update(s)" . ($listingId > 0 ? " for listing #{$listingId}" : ' across all listings'),
            $request->ip(),
        );

        $session->set('lstr_flash_success', "Approved {$count} pending update" . ($count !== 1 ? 's' : '') . '.');

        $redirect = $listingId > 0
            ? $baseUrl . '/admin/members?listing_id=' . $listingId . '&status=pending'
            : $baseUrl . '/admin/members?status=pending';
        return Response::redirect($redirect);
    }

    // -----------------------------------------------------------------------
    // POST /admin/pending-updates/bulk
    // -----------------------------------------------------------------------

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
        $listingRepo  = new ListingRepo($db, $config);
        $memberRepo   = new MemberRepo($db, $config);
        $activityRepo = new ActivityRepo($db);

        $pendings = $pendingRepo->getByIds($ids);
        $count    = 0;

        foreach ($pendings as $pending) {
            if ($action === 'reject') {
                $pendingRepo->delete($pending->id);
                $count++;
                continue;
            }

            // approve
            $listing = $listingRepo->getById($pending->fanlisting_id);
            if ($listing === null) {
                $pendingRepo->delete($pending->id);
                continue;
            }

            $member = $memberRepo->getByEmail($listing->dbprefix, $pending->member_email);
            if ($member === null) {
                $pendingRepo->delete($pending->id);
                continue;
            }

            $memberRepo->update($listing->dbprefix, $pending->member_email, $pending->changes);
            $pendingRepo->delete($pending->id);

            // Sync member link check snapshot (best-effort)
            $_bUrl = (string) ($pending->changes['url'] ?? $member->url ?? '');
            if ($_bUrl !== '') {
                try {
                    $_bName = (string) ($pending->changes['name'] ?? $member->name ?? '');
                    (new MemberLinkCheckRepo($db, $config))->upsertMember(
                        $pending->fanlisting_id, $pending->member_email, $_bName, $_bUrl,
                    );
                } catch (\Throwable) {}
            }

            $count++;
        }

        $activityRepo->log(
            (int) $session->get('lstr_admin_id'),
            'pending_update.bulk_' . $action,
            "Bulk {$action}d {$count} pending update(s)",
            $request->ip(),
        );

        $session->set('lstr_flash_success', ucfirst($action) . "d {$count} pending update" . ($count !== 1 ? 's' : '') . '.');

        $returnListingId = (int) $request->post('_listing_id', 0);
        $redirect = $returnListingId > 0
            ? $baseUrl . '/admin/members?listing_id=' . $returnListingId . '&status=pending'
            : $baseUrl . '/admin/members';
        return Response::redirect($redirect);
    }

    // -----------------------------------------------------------------------
    // POST /admin/pending-updates/{id}/reject
    // -----------------------------------------------------------------------

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
}
