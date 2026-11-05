<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Handles approve and reject actions for pending member update requests. When a
 fan submits changes through the public update form and the listing has hold-update
 enabled, the changes are queued in lstr_pending_updates. Approving goes through
 QueuedUpdateApplier, which decides what can be applied. This controller sends the
 email and says what happened. Rejecting silently discards a request. Every action
 redirects back to the Members overview.
*/

declare(strict_types=1);

namespace Luster\Controllers\Admin;

use Luster\Core\Application;
use Luster\Core\Request;
use Luster\Core\Response;
use Luster\Core\Session;
use Luster\Repositories\ActivityRepo;
use Luster\Repositories\PendingUpdateRepo;
use Luster\Support\QueuedUpdateApplier;

/**
 * Handles approve and reject actions for pending member update requests.
 *
 * When a fan submits the public update form and the listing has hold-update
 * enabled, the changes are queued in lstr_pending_updates rather than applied
 * immediately. Single approvals and Approve All email the fan, and bulk approvals
 * don't. Rejecting never emails anyone. Both single-record and bulk actions redirect
 * back to the Members page at the appropriate listing/status context.
 */
final class PendingUpdateController
{
    public function __construct(private readonly Application $app) {}

    // -----------------------------------------------------------------------
    // POST /admin/pending-updates/{id}/approve
    // -----------------------------------------------------------------------

    /**
     * Approves a single pending update and applies its changes to the member record,
     * then emails the fan (best-effort) and logs the action.
     */
    public function approve(Request $request, array $params): Response
    {
        $id = (int) ($params['id'] ?? 0);

        $session = $this->app->getSession();
        $baseUrl = $this->app->getBaseUrl();

        $applier = QueuedUpdateApplier::for($this->app);
        $result  = $applier->apply($id);
        $pending = $result['pending'];

        $redirect = $pending !== null
            ? $baseUrl . '/admin/members?listing_id=' . $pending->fanlisting_id . '&status=pending'
            : $baseUrl . '/admin/members';

        if ($result['outcome'] !== 'applied') {
            $session->set(
                $result['outcome'] === 'nothing' ? 'lstr_flash_success' : 'lstr_flash_error',
                QueuedUpdateApplier::message($result),
            );
            return Response::redirect($redirect);
        }

        $applier->sendConfirmation($result);

        (new ActivityRepo($this->app->getDatabase()))->log(
            (int) $session->get('lstr_admin_id'),
            'pending_update.approve',
            "Approved update #{$id} for {$pending->member_email} on {$result['listing']->title}",
            $request->ip(),
        );

        $session->set('lstr_flash_success', "Update for {$result['final']->email} approved and applied.");
        return Response::redirect($redirect);
    }

    // -----------------------------------------------------------------------
    // POST /admin/pending-updates/approve-all
    // -----------------------------------------------------------------------

    /**
     * Approves all pending updates for a listing (or all listings when no listing ID
     * is given), oldest first so the newest valid request wins, emailing each fan
     * best-effort. Anything that can't be applied is skipped and reported.
     */
    public function approveAll(Request $request, array $params): Response
    {
        $db      = $this->app->getDatabase();
        $session = $this->app->getSession();
        $baseUrl = $this->app->getBaseUrl();

        $listingId = (int) $request->post('_listing_id', 0);

        $ids   = (new PendingUpdateRepo($db))->idsOldestFirst($listingId > 0 ? $listingId : null);
        $tally = QueuedUpdateApplier::for($this->app)->applyAll($ids, true);
        $count = $tally['applied'] ?? 0;

        // After a transaction that can't be closed, nothing more goes near the database.
        if (!isset($tally['unresolved'])) {
            (new ActivityRepo($db))->log(
                (int) $session->get('lstr_admin_id'),
                'pending_update.approve_all',
                "Approved all {$count} pending update(s)" . ($listingId > 0 ? " for listing #{$listingId}" : ' across all listings'),
                $request->ip(),
            );
        }

        self::flashBatch($session, 'Approved', $count, $tally);

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
     * Rejected rows are deleted without applying anything. Approved rows go oldest
     * first whatever order they were ticked in, and no email is sent. Emails only go
     * through single-approve and Approve All.
     */
    public function bulk(Request $request, array $params): Response
    {
        $db      = $this->app->getDatabase();
        $session = $this->app->getSession();
        $baseUrl = $this->app->getBaseUrl();

        $action = (string) $request->post('bulk_action', '');
        $ids    = array_values(array_filter(array_map('intval', (array) $request->post('ids', []))));

        if (empty($ids) || !in_array($action, ['approve', 'reject'], true)) {
            $session->set('lstr_flash_error', 'No updates selected.');
            return Response::redirect($baseUrl . '/admin/members');
        }

        $pendingRepo = new PendingUpdateRepo($db);

        $tally = [];
        if ($action === 'reject') {
            $count = 0;
            foreach ($ids as $id) {
                if ($pendingRepo->delete($id)) {
                    $count++;
                }
            }
        } else {
            $tally = QueuedUpdateApplier::for($this->app)->applyAll($pendingRepo->idsOldestFirst(null, $ids), false);
            $count = $tally['applied'] ?? 0;
        }

        $actionPastTense = $action === 'approve' ? 'Approved' : 'Rejected';

        // After a transaction that can't be closed, nothing more goes near the database.
        if (!isset($tally['unresolved'])) {
            (new ActivityRepo($db))->log(
                (int) $session->get('lstr_admin_id'),
                'pending_update.bulk_' . $action,
                "Bulk {$actionPastTense} {$count} pending update(s)",
                $request->ip(),
            );
        }

        self::flashBatch($session, $actionPastTense, $count, $tally);

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
     * notification on rejection. If an approval got there first, this says so
     * rather than claiming it was rejected.
     */
    public function reject(Request $request, array $params): Response
    {
        $id = (int) ($params['id'] ?? 0);

        $db      = $this->app->getDatabase();
        $session = $this->app->getSession();
        $baseUrl = $this->app->getBaseUrl();

        $pendingRepo = new PendingUpdateRepo($db);

        $pending = $pendingRepo->getById($id);

        if ($pending === null || !$pendingRepo->delete($id)) {
            $session->set('lstr_flash_error', 'That update was already handled.');
            return Response::redirect($baseUrl . '/admin/members');
        }

        (new ActivityRepo($db))->log(
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
     * Says how a batch went. If nothing was applied and there's something to explain,
     * that's what the admin needs to read, so it's shown as an error on its own. A batch
     * that had to stop is an error whatever else went through.
     *
     * @param array<string, int> $tally
     */
    private static function flashBatch(Session $session, string $pastTense, int $count, array $tally): void
    {
        $notes = QueuedUpdateApplier::batchNotes($tally);

        if ($count === 0 && $notes !== '') {
            $session->set('lstr_flash_error', $notes);
            return;
        }

        $message = "{$pastTense} {$count} pending update" . ($count !== 1 ? 's' : '') . '.';
        $session->set(
            isset($tally['unresolved']) ? 'lstr_flash_error' : 'lstr_flash_success',
            $notes !== '' ? $message . ' ' . $notes : $message,
        );
    }
}
