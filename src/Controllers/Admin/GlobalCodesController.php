<?php

declare(strict_types=1);

namespace Luster\Controllers\Admin;

use Luster\Core\Mailer;
use Luster\Core\Request;
use Luster\Core\Response;
use Luster\Repositories\ActivityRepo;
use Luster\Repositories\CodeCategoriesRepo;
use Luster\Repositories\CodeDonorsRepo;
use Luster\Repositories\CodesRepo;
use Luster\Repositories\EmailTemplateRepo;
use Luster\Repositories\ListingRepo;
use Luster\Repositories\ListingSettingsRepo;
use Luster\Repositories\SiteSettingsRepo;

/**
 * Global Codes admin page — cross-listing pending review and per-listing management hub.
 *
 * Routes:
 *   GET  /admin/codes                  — landing: global pending or empty state
 *   POST /admin/codes/{id}/approve     — approve single code (sends donor email if enabled)
 *   POST /admin/codes/{id}/reject      — reject single code
 *   POST /admin/codes/bulk             — bulk approve / reject
 *   POST /admin/codes/approve-all      — approve all pending (global or scoped to listing)
 */
final class GlobalCodesController extends AdminController
{
    private const PER_PAGE = 25;

    // -----------------------------------------------------------------------
    // GET /admin/codes
    // -----------------------------------------------------------------------

    public function index(Request $request, array $params): Response
    {
        $db     = $this->app->getDatabase();
        $config = $this->app->getConfig();

        $codesRepo   = new CodesRepo($db);
        $listingRepo = new ListingRepo($db, $config);

        $listingId = (int) $request->query('listing_id', 0);
        $tab       = trim((string) $request->query('tab', 'pending'));
        $page      = max(1, (int) $request->query('page', 1));

        $validTabs = ['all', 'pending', 'approved', 'rejected'];
        if (!in_array($tab, $validTabs, true)) {
            $tab = 'pending';
        }

        $allListings        = $listingRepo->getAll();
        $pendingPerListing  = $codesRepo->countPendingPerListing();

        $session = $this->app->getSession();
        $success = (string) $session->get('lstr_flash_success', '');
        $error   = (string) $session->get('lstr_flash_error', '');
        $session->remove('lstr_flash_success');
        $session->remove('lstr_flash_error');

        if ($listingId > 0) {
            $listing = $listingRepo->getById($listingId);
            if ($listing === null) {
                return Response::notFound('Listing not found.');
            }

            $statusFilter = $tab === 'all' ? null : $tab;
            $allCodes     = $codesRepo->getByListing($listingId, $statusFilter);
            $pendingCount = $codesRepo->countPendingByListing($listingId);

            // Build tab counts
            $tabCounts = [
                'all'      => count($codesRepo->getByListing($listingId)),
                'pending'  => count($codesRepo->getByListing($listingId, 'pending')),
                'approved' => count($codesRepo->getByListing($listingId, 'approved')),
                'rejected' => count($codesRepo->getByListing($listingId, 'rejected')),
            ];

            // Donor map for display
            $donorsRepo = new CodeDonorsRepo($db);
            $donors     = $donorsRepo->getByListing($listingId);
            $donorMap   = [];
            foreach ($donors as $dn) {
                $donorMap[$dn->id] = $dn;
            }

            $uploadsUrl = rtrim($this->app->getBaseUrl(), '/') . '/uploads/codes';

            return $this->adminLayout('admin/codes/index.php', 'Codes', 'codes', [
                'listing'       => $listing,
                'listing_id'    => $listingId,
                'all_listings'  => $allListings,
                'tab'           => $tab,
                'tab_counts'    => $tabCounts,
                'codes'         => $allCodes,
                'donor_map'     => $donorMap,
                'uploads_url'   => $uploadsUrl,
                'pending_count'      => $pendingCount,
                'pending_per_listing' => $pendingPerListing,
                'total'              => count($allCodes),
                'view_mode'          => 'listing',
                'success'       => $success,
                'error'         => $error,
            ]);
        }

        // Global view — show pending across all listings
        $result       = $codesRepo->getPendingAllPaginated(self::PER_PAGE, $page);
        $pendingTotal = $result['total'];

        // Build listing map for the table
        $listingMap = [];
        foreach ($allListings as $fl) {
            $listingMap[$fl->id] = $fl;
        }

        return $this->adminLayout('admin/codes/index.php', 'Codes', 'codes', [
            'listing'       => null,
            'listing_id'    => 0,
            'all_listings'  => $allListings,
            'listing_map'   => $listingMap,
            'tab'           => 'pending',
            'codes'         => $result['items'],
            'pending_count'       => $pendingTotal,
            'pending_per_listing' => $pendingPerListing,
            'page'                => $page,
            'total_pages'         => $result['pages'],
            'total'               => $pendingTotal,
            'view_mode'           => 'global',
            'success'       => $success,
            'error'         => $error,
        ]);
    }

    // -----------------------------------------------------------------------
    // POST /admin/codes/{id}/approve
    // -----------------------------------------------------------------------

    public function approve(Request $request, array $params): Response
    {
        $id      = (int) ($params['id'] ?? 0);
        $db      = $this->app->getDatabase();
        $session = $this->app->getSession();
        $baseUrl = $this->app->getBaseUrl();

        $codesRepo = new CodesRepo($db);
        $code      = $codesRepo->getById($id);

        if ($code === null) {
            return Response::notFound('Code not found.');
        }

        $codesRepo->updateStatus($id, 'approved');

        (new ActivityRepo($db))->log(
            (int) $session->get('lstr_admin_id'),
            'code.approve',
            "Approved code #{$id} for listing #{$code->fanlisting_id}",
            $request->ip(),
        );

        // Send donor email if enabled
        $this->maybeSendDonorEmail($db, $code);

        $session->set('lstr_flash_success', 'Code approved.');
        return $this->redirectBack($request, $baseUrl, (int) $code->fanlisting_id);
    }

    // -----------------------------------------------------------------------
    // POST /admin/codes/{id}/reject
    // -----------------------------------------------------------------------

    public function reject(Request $request, array $params): Response
    {
        $id      = (int) ($params['id'] ?? 0);
        $db      = $this->app->getDatabase();
        $session = $this->app->getSession();
        $baseUrl = $this->app->getBaseUrl();

        $codesRepo = new CodesRepo($db);
        $code      = $codesRepo->getById($id);

        if ($code === null) {
            return Response::notFound('Code not found.');
        }

        $codesRepo->updateStatus($id, 'rejected');

        (new ActivityRepo($db))->log(
            (int) $session->get('lstr_admin_id'),
            'code.reject',
            "Rejected code #{$id} for listing #{$code->fanlisting_id}",
            $request->ip(),
        );

        $session->set('lstr_flash_success', 'Code rejected.');
        return $this->redirectBack($request, $baseUrl, (int) $code->fanlisting_id);
    }

    // -----------------------------------------------------------------------
    // POST /admin/codes/bulk
    // -----------------------------------------------------------------------

    public function bulk(Request $request, array $params): Response
    {
        $db      = $this->app->getDatabase();
        $session = $this->app->getSession();
        $baseUrl = $this->app->getBaseUrl();

        $action    = trim((string) $request->post('bulk_action', ''));
        $listingId = (int) $request->post('listing_id', 0);
        $rawIds    = (array) $request->post('code_ids', []);
        $ids       = array_values(array_filter(array_map('intval', $rawIds), fn(int $i) => $i > 0));

        if (empty($ids) || !in_array($action, ['approve', 'reject'], true)) {
            $session->set('lstr_flash_error', 'No codes selected or invalid action.');
            return $this->redirectBack($request, $baseUrl, $listingId);
        }

        $codesRepo = new CodesRepo($db);
        $status    = $action === 'approve' ? 'approved' : 'rejected';
        $count     = $codesRepo->bulkStatus($ids, $status);

        // Send donor emails for approved codes
        if ($action === 'approve') {
            foreach ($ids as $codeId) {
                $code = $codesRepo->getById($codeId);
                if ($code !== null) {
                    $this->maybeSendDonorEmail($db, $code);
                }
            }
        }

        (new ActivityRepo($db))->log(
            (int) $session->get('lstr_admin_id'),
            "code.bulk_{$action}",
            "Bulk {$action}: {$count} code(s)" . ($listingId ? " for listing #{$listingId}" : ' (global)'),
            $request->ip(),
        );

        $session->set('lstr_flash_success', ucfirst($action) . "d {$count} code(s).");
        return $this->redirectBack($request, $baseUrl, $listingId);
    }

    // -----------------------------------------------------------------------
    // POST /admin/codes/approve-all
    // -----------------------------------------------------------------------

    public function approveAll(Request $request, array $params): Response
    {
        $db        = $this->app->getDatabase();
        $session   = $this->app->getSession();
        $baseUrl   = $this->app->getBaseUrl();
        $codesRepo = new CodesRepo($db);

        $listingId = (int) $request->post('listing_id', 0);
        $scope     = $listingId > 0 ? $listingId : null;

        // Fetch pending codes before approving (for emails)
        $pending = $scope !== null
            ? $codesRepo->getByListing($scope, 'pending')
            : $codesRepo->getPendingAllPaginated(999, 1)['items'];

        $count = $codesRepo->approveAllPending($scope);

        foreach ($pending as $code) {
            $this->maybeSendDonorEmail($db, $code);
        }

        (new ActivityRepo($db))->log(
            (int) $session->get('lstr_admin_id'),
            'code.approve_all',
            "Approved all {$count} pending code(s)" . ($scope ? " for listing #{$scope}" : ' (global)'),
            $request->ip(),
        );

        $session->set('lstr_flash_success', "Approved {$count} pending code(s).");
        return $this->redirectBack($request, $baseUrl, $listingId);
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    private function redirectBack(Request $request, string $baseUrl, int $listingId): Response
    {
        $tab = trim((string) $request->post('_tab', 'pending'));
        $qs  = $listingId > 0
            ? '?listing_id=' . $listingId . '&tab=' . urlencode($tab)
            : '?tab=pending';
        return Response::redirect($baseUrl . '/admin/codes' . $qs);
    }

    private function maybeSendDonorEmail(\Luster\Core\Database $db, object $code): void
    {
        $email = (string) ($code->submitter_email ?? '');
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return;
        }

        $listingId = (int) $code->fanlisting_id;

        // Resolve send preference: per-listing → global → default on
        $settingsRepo    = new ListingSettingsRepo($db);
        $perListing      = $settingsRepo->getCodeApprovalEmail($listingId);
        $globalEnabled   = (new SiteSettingsRepo($db))->get('code_approval_email_enabled', '1') !== '0';
        $shouldSend      = $perListing ?? $globalEnabled;

        if (!$shouldSend) {
            return;
        }

        try {
            $config      = $this->app->getConfig();
            $listingRepo = new ListingRepo($db, $config);
            $listing     = $listingRepo->getById($listingId);
            if ($listing === null) {
                return;
            }

            $emailRepo = new EmailTemplateRepo($db);
            $tokens = EmailTemplateRepo::buildTokens(
                listingName:    $listing->title,
                listingUrl:     $listing->url,
                siteName:       (string) $config->get('site_name', ''),
                listingSubject: $listing->subject,
                ownerName:      (string) $config->get('owner_name', ''),
                ownerEmail:     (string) $config->get('owner_email', ''),
                fanName:        (string) ($code->submitter_name ?? ''),
                fanEmail:       $email,
                fanUrl:         (string) ($code->submitter_url ?? ''),
            );

            [$subject, $body] = $emailRepo->resolveForListing($listingId, EmailTemplateRepo::TYPE_CODE_APPROVED, $tokens);
            (new Mailer($config))->send($email, (string) ($code->submitter_name ?? ''), $subject, $body);
        } catch (\Throwable $e) {
            error_log('[Luster] Code approval email failed: ' . $e->getMessage());
        }
    }
}
