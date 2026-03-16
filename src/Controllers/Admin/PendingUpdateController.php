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

        // Notify the fan that their update has been applied (best-effort)
        try {
            $cfg       = $this->app->getConfig();
            $mailer    = new Mailer($cfg);
            $emailRepo = new EmailTemplateRepo($db);
            $tokens    = EmailTemplateRepo::buildTokens(
                listingName:    $listing->name,
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
            "Approved update #{$id} for {$pending->member_email} on {$listing->name}",
            $request->ip(),
        );

        $session->set('lstr_flash_success', "Update for {$pending->member_email} approved and applied.");
        return Response::redirect($baseUrl . '/admin/members');
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
        return Response::redirect($baseUrl . '/admin/members');
    }
}
