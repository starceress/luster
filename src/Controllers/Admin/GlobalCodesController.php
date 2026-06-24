<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Cross-listing admin hub for fan-submitted codes (small web graphics). Shows all
 pending code submissions across every listing for review, with approve/reject
 actions for individual codes, bulk selection, and an approve-all shortcut.
 Approved donor codes optionally trigger a notification email to the submitter.
 Also serves as the entry point for navigating to per-listing code management.
*/

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
 * Cross-listing codes hub for the admin panel.
 *
 * Handles per-listing code management, collective codes (not tied to any
 * specific listing), and the global pending-code review queue that shows
 * submissions across all listings at once. Approve/reject actions optionally
 * trigger a notification email to the code submitter.
 */
final class GlobalCodesController extends AdminController
{
    private const PER_PAGE = 25;

    // -----------------------------------------------------------------------
    // GET /admin/codes
    // -----------------------------------------------------------------------

    /**
     * Renders the codes index in one of three modes: global queue, per-listing, or collective.
     *
     * Global (no listing selected): shows the cross-listing pending queue with pagination.
     * Per-listing (listing_id param): shows all codes for that listing, grouped by dimensions.
     * Collective (collective=1 param): shows codes not tied to any listing.
     */
    public function index(Request $request, array $params): Response
    {
        $db     = $this->app->getDatabase();
        $config = $this->app->getConfig();

        $codesRepo   = new CodesRepo($db);
        $listingRepo = new ListingRepo($db, $config);

        // Redirect if the listing selector submitted "collective", forwarding search term
        $listingIdRaw = trim((string) $request->query('listing_id', ''));
        if ($listingIdRaw === 'collective') {
            $fwdSearch = trim((string) $request->query('search', ''));
            $fwdTab    = trim((string) $request->query('tab', ''));
            $qs        = 'collective=1';
            if ($fwdTab !== '')    $qs .= '&tab='    . urlencode($fwdTab);
            if ($fwdSearch !== '') $qs .= '&search=' . urlencode($fwdSearch);
            return Response::redirect($this->app->getBaseUrl() . '/admin/codes?' . $qs);
        }

        $listingId = (int) $listingIdRaw;
        $tab       = trim((string) $request->query('tab', 'approved'));
        $page      = max(1, (int) $request->query('page', 1));

        $validTabs = ['all', 'pending', 'approved', 'rejected'];
        if (!in_array($tab, $validTabs, true)) {
            $tab = 'approved';
        }

        $allListings         = $listingRepo->getAll();
        $pendingPerListing   = $codesRepo->countPendingPerListing();
        $approvedPerListing  = $codesRepo->countApprovedPerListing();
        $filterOptions       = $codesRepo->getPendingFilterOptions();

        $filterSize   = trim((string) $request->query('size',   ''));
        $filterDonor  = trim((string) $request->query('donor',  ''));
        $filterSearch = trim((string) $request->query('search', ''));

        $session = $this->app->getSession();
        $success = (string) $session->get('lstr_flash_success', '');
        $error   = (string) $session->get('lstr_flash_error', '');
        $session->remove('lstr_flash_success');
        $session->remove('lstr_flash_error');

        $isCollective = (int) $request->query('collective', 0) === 1;

        if ($isCollective) {
            $validCollTab = in_array($tab, ['approved', 'pending', 'rejected'], true) ? $tab : 'approved';
            $filterSearch = trim((string) $request->query('search', ''));

            $codes = $codesRepo->getByListing(null);

            if ($filterSearch !== '') {
                $needle = mb_strtolower($filterSearch);
                $codes  = array_values(array_filter($codes, static function (object $c) use ($needle): bool {
                    return str_contains(mb_strtolower($c->submitterName ?? ''), $needle)
                        || str_contains(mb_strtolower($c->name ?? ''), $needle)
                        || str_contains(mb_strtolower($c->filename), $needle);
                }));
            }
            $statusCounts = ['approved' => 0, 'pending' => 0, 'rejected' => 0];
            $grouped      = [];
            foreach ($codes as $code) {
                $statusCounts[$code->status] = ($statusCounts[$code->status] ?? 0) + 1;
                if ($code->width > 0 && $code->height > 0) {
                    $key   = (int) $code->width . 'x' . (int) $code->height;
                    $label = (int) $code->width . '×' . (int) $code->height . 'px';
                } else {
                    $key   = 'unknown';
                    $label = 'Unknown dimensions';
                }
                if (!isset($grouped[$key])) {
                    $grouped[$key] = ['label' => $label, 'codes' => []];
                }
                $grouped[$key]['codes'][] = $code;
            }
            uksort($grouped, static function (string $a, string $b): int {
                if ($a === 'unknown') return 1;
                if ($b === 'unknown') return -1;
                [$aw, $ah] = explode('x', $a);
                [$bw, $bh] = explode('x', $b);
                return $aw !== $bw ? (int) $aw - (int) $bw : (int) $ah - (int) $bh;
            });

            $tabCounts = [
                'all'      => count($codes),
                'pending'  => $statusCounts['pending'],
                'approved' => $statusCounts['approved'],
                'rejected' => $statusCounts['rejected'],
            ];

            return $this->adminLayout('admin/codes/index.php', 'Collective Codes', 'codes', [
                'listing'              => null,
                'listing_id'           => 0,
                'all_listings'         => $allListings,
                'view_mode'            => 'collective',
                'codes'                => $codes,
                'code_grouped'         => $grouped,
                'code_status_counts'   => $statusCounts,
                'code_status_filter'   => $validCollTab,
                'tab'                  => $validCollTab,
                'tab_counts'           => $tabCounts,
                'total_codes'          => count($codes),
                'code_cats'            => [],
                'code_donors'          => [],
                'code_cat_map'         => [],
                'code_donor_map'       => [],
                'code_category_ids'    => [],
                'code_submitter_names' => $codesRepo->getSubmitterNamesForListing(null),
                'pending_per_listing'  => $pendingPerListing,
                'approved_per_listing' => $approvedPerListing,
                'filter_options'       => $filterOptions,
                'filter_size'          => '',
                'filter_donor'         => '',
                'filter_search'        => $filterSearch,
                'filters_active'       => $filterSearch !== '',
                'uploads_url'          => rtrim($this->app->getBaseUrl(), '/') . '/uploads/codes',
                'success'              => $success,
                'error'                => $error,
            ]);
        }

        if ($listingId > 0) {
            $listing = $listingRepo->getById($listingId);
            if ($listing === null) {
                return Response::notFound('Listing not found.');
            }

            // Apply size/donor/search filters when present; always load all status levels
            // so the client-side pending/all toggle continues to work.
            $listingFilters = array_filter([
                'size'   => $filterSize,
                'donor'  => $filterDonor,
                'search' => $filterSearch,
            ]);
            $hasFilters = $listingFilters !== [];

            $allCodes     = $hasFilters
                ? $codesRepo->getByListingFiltered($listingId, null, $listingFilters)
                : $codesRepo->getByListing($listingId);
            $pendingCount = $codesRepo->countPendingByListing($listingId);

            $codeStatusCounts = ['approved' => 0, 'pending' => 0, 'rejected' => 0];
            foreach ($allCodes as $c) {
                $codeStatusCounts[$c->status] = ($codeStatusCounts[$c->status] ?? 0) + 1;
            }

            $tabCounts = [
                'all'      => count($allCodes),
                'pending'  => $codeStatusCounts['pending'],
                'approved' => $codeStatusCounts['approved'],
                'rejected' => $codeStatusCounts['rejected'],
            ];

            // Group codes by dimensions for the tile grid
            $codeGrouped = [];
            foreach ($allCodes as $code) {
                if ($code->width > 0 && $code->height > 0) {
                    $gKey   = (int) $code->width . 'x' . (int) $code->height;
                    $gLabel = (int) $code->width . '×' . (int) $code->height . 'px';
                } else {
                    $gKey   = 'unknown';
                    $gLabel = 'Unknown dimensions';
                }
                if (!isset($codeGrouped[$gKey])) {
                    $codeGrouped[$gKey] = ['label' => $gLabel, 'codes' => []];
                }
                $codeGrouped[$gKey]['codes'][] = $code;
            }
            uksort($codeGrouped, function (string $a, string $b): int {
                if ($a === 'unknown') return 1;
                if ($b === 'unknown') return -1;
                [$aw, $ah] = explode('x', $a);
                [$bw, $bh] = explode('x', $b);
                return $aw !== $bw ? (int) $aw - (int) $bw : (int) $ah - (int) $bh;
            });

            // Donor data
            $donorsRepo     = new CodeDonorsRepo($db);
            $donors         = $donorsRepo->getByListing($listingId);
            $donorMap       = [];
            foreach ($donors as $dn) {
                $donorMap[$dn->id] = $dn;
            }
            $submitterNames = $codesRepo->getSubmitterNamesForListing($listingId);

            // Category data
            $codeCatsRepo    = new CodeCategoriesRepo($db);
            $codeCats        = $codeCatsRepo->getByListing($listingId);
            $codeCatMap      = [];
            foreach ($codeCats as $cat) {
                $codeCatMap[$cat->id] = $cat;
            }
            $codeCategoryIds = $codeCatsRepo->getCategoryMapForListing($listingId);

            // Initial client-side filter state derived from the tab param
            $codeStatusFilter = match ($tab) {
                'pending'  => 'pending',
                'approved' => 'approved',
                'rejected' => 'rejected',
                default    => 'approved',
            };

            // Filter options for size/donor dropdowns (across all statuses)
            $listingFilterOpts = $codesRepo->getListingFilterOptions($listingId, null);

            $uploadsUrl = rtrim($this->app->getBaseUrl(), '/') . '/uploads/codes';

            return $this->adminLayout('admin/codes/index.php', 'Codes', 'codes', [
                'listing'             => $listing,
                'listing_id'          => $listingId,
                'all_listings'        => $allListings,
                'tab'                 => $tab,
                'tab_counts'          => $tabCounts,
                'codes'               => $allCodes,
                'donor_map'           => $donorMap,
                'uploads_url'         => $uploadsUrl,
                'pending_count'       => $pendingCount,
                'pending_per_listing'  => $pendingPerListing,
                'approved_per_listing' => $approvedPerListing,
                'filter_options'      => $listingFilterOpts,
                'filter_size'         => $filterSize,
                'filter_donor'        => $filterDonor,
                'filter_search'       => $filterSearch,
                'filters_active'      => $hasFilters,
                'total'               => count($allCodes),
                'view_mode'           => 'listing',
                // Tile grid data
                'code_grouped'        => $codeGrouped,
                'code_cats'           => $codeCats,
                'code_donors'         => $donors,
                'code_submitter_names' => $submitterNames,
                'code_cat_map'        => $codeCatMap,
                'code_category_ids'   => $codeCategoryIds,
                'code_donor_map'      => $donorMap,
                'code_status_counts'  => $codeStatusCounts,
                'code_status_filter'  => $codeStatusFilter,
                'total_codes'         => count($allCodes),
                'code_edit_cat_id'    => ((int) $request->query('edit_cat', '0')) ?: null,
                'code_edit_donor_id'  => ((int) $request->query('edit_donor', '0')) ?: null,
                'success'             => $success,
                'error'               => $error,
            ]);
        }

        // Global view — All tab (placeholder) or Pending tab (cross-listing pending list)
        $globalTabRaw = trim((string) $request->query('tab', ''));
        $globalTab    = in_array($globalTabRaw, ['all', 'pending'], true) ? $globalTabRaw : '';

        $filters = array_filter([
            'size'   => $filterSize,
            'donor'  => $filterDonor,
            'search' => $filterSearch,
        ]);

        $result       = $codesRepo->getPendingAllPaginated(self::PER_PAGE, $page, $filters);
        $pendingTotal = $result['total'];

        // Smart default: land on Pending if any exist, otherwise All
        if ($globalTab === '') {
            $globalTab = $pendingTotal > 0 ? 'pending' : 'all';
        }

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
            'tab'           => $globalTab,
            'tab_counts'    => ['all' => 0, 'pending' => $pendingTotal],
            'codes'         => $globalTab === 'pending' ? $result['items'] : [],
            'pending_count'       => $pendingTotal,
            'pending_per_listing'  => $pendingPerListing,
            'approved_per_listing' => $approvedPerListing,
            'filter_options'       => $filterOptions,
            'filter_size'         => $filterSize,
            'filter_donor'        => $filterDonor,
            'filter_search'       => $filterSearch,
            'filters_active'      => $filterSize !== '' || $filterDonor !== '' || $filterSearch !== '',
            'page'                => $page,
            'total_pages'         => $globalTab === 'pending' ? $result['pages'] : 1,
            'total'               => $pendingTotal,
            'view_mode'           => 'global',
            'success'       => $success,
            'error'         => $error,
        ]);
    }

    // -----------------------------------------------------------------------
    // POST /admin/codes/{id}/approve
    // -----------------------------------------------------------------------

    /**
     * Approves a single code and optionally emails the submitter.
     */
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

    /**
     * Rejects a single code submission.
     */
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

    /**
     * Bulk-approves or bulk-rejects a selected set of codes.
     *
     * Accepted actions are "approve" and "reject". Sends donor emails for
     * each code that gets approved, if the per-listing or global setting allows it.
     */
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

    /**
     * Approves every pending code for a listing (or globally if no listing ID is posted).
     *
     * Fetches the pending codes first so donor emails can be sent before the
     * status update makes them no longer "pending".
     */
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
        if ($listingId > 0) {
            return Response::redirect($baseUrl . '/admin/codes?listing_id=' . $listingId . '&tab=approved');
        }
        return Response::redirect($baseUrl . '/admin/codes');
    }

    // -----------------------------------------------------------------------
    // POST /admin/codes/collective-upload
    // -----------------------------------------------------------------------

    /**
     * Handles multi-file upload for collective codes (not tied to any listing).
     *
     * Validates MIME type (jpeg/png/gif/webp) and file size (max 500 KB) per file.
     * Randomizes filenames on save. Optionally associates uploaded codes with a
     * submitter name/email/url pulled from the donor select or inline new-donor fields.
     */
    public function uploadCollective(Request $request, array $params): Response
    {
        $db      = $this->app->getDatabase();
        $session = $this->app->getSession();
        $baseUrl = $this->app->getBaseUrl();

        $codesRepo    = new CodesRepo($db);
        $activityRepo = new ActivityRepo($db);

        $uploadStatus = (string) $request->post('upload_status', 'approved');
        if (!in_array($uploadStatus, CodesRepo::STATUSES, true)) {
            $uploadStatus = 'approved';
        }

        // Resolve submitter from donor select (s:name = past submitter) or inline new-donor fields
        $postedDonorVal = (string) $request->post('donor_id', '');
        $submitterName  = '';
        $submitterEmail = '';
        $submitterUrl   = '';

        if (str_starts_with($postedDonorVal, 's:')) {
            $submitterName = trim(substr($postedDonorVal, 2));
        } else {
            $newDonorName = trim((string) $request->post('new_donor_name', ''));
            if ($newDonorName !== '') {
                $submitterName  = $newDonorName;
                $submitterEmail = trim((string) $request->post('new_donor_email', ''));
                $submitterUrl   = trim((string) $request->post('new_donor_url', ''));
            }
        }

        if ($submitterEmail !== '' && filter_var($submitterEmail, FILTER_VALIDATE_EMAIL) === false) {
            $submitterEmail = '';
        }
        if ($submitterUrl !== '' && (
            filter_var($submitterUrl, FILTER_VALIDATE_URL) === false
            || !in_array(parse_url($submitterUrl, PHP_URL_SCHEME), ['http', 'https'], true)
        )) {
            $submitterUrl = '';
        }

        $uploadsBase = realpath(__DIR__ . '/../../../public/uploads');
        if ($uploadsBase === false) {
            $session->set('lstr_flash_error', 'Uploads directory not found.');
            return Response::redirect($baseUrl . '/admin/codes?collective=1');
        }

        $uploadDir = $uploadsBase . DIRECTORY_SEPARATOR . 'codes';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }

        $fileData = $request->file('codes');
        if ($fileData === null || (is_array($fileData['name']) ? empty(array_filter($fileData['name'])) : $fileData['name'] === '')) {
            $session->set('lstr_flash_error', 'No files selected for upload.');
            return Response::redirect($baseUrl . '/admin/codes?collective=1');
        }

        $names    = is_array($fileData['name'])     ? $fileData['name']     : [$fileData['name']];
        $tmpNames = is_array($fileData['tmp_name']) ? $fileData['tmp_name'] : [$fileData['tmp_name']];
        $errors   = is_array($fileData['error'])    ? $fileData['error']    : [$fileData['error']];
        $sizes    = is_array($fileData['size'])      ? $fileData['size']    : [$fileData['size']];

        $uploaded   = 0;
        $skipped    = [];
        $sortOffset = $codesRepo->getMaxSortOrder(null);
        $finfo      = new \finfo(FILEINFO_MIME_TYPE);

        $allowedMime = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        $maxSize     = 512_000;

        foreach ($names as $i => $originalName) {
            if ($originalName === '' || $originalName === null) {
                continue;
            }

            $safeName = htmlspecialchars((string) $originalName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

            if ((int) $errors[$i] !== UPLOAD_ERR_OK) {
                $skipped[] = $safeName . ' (upload error)';
                continue;
            }

            $tmpName  = (string) $tmpNames[$i];
            $fileSize = (int) $sizes[$i];

            if ($fileSize > $maxSize) {
                $skipped[] = $safeName . ' (exceeds 500 KB)';
                continue;
            }

            $mime = $finfo->file($tmpName);
            if (!in_array($mime, $allowedMime, true)) {
                $skipped[] = $safeName . ' (not a supported image)';
                continue;
            }

            $ext = match ($mime) {
                'image/jpeg' => 'jpg',
                'image/png'  => 'png',
                'image/gif'  => 'gif',
                'image/webp' => 'webp',
                default      => 'bin',
            };

            $imgInfo = getimagesize($tmpName);
            $width   = $imgInfo !== false ? (int) $imgInfo[0] : 0;
            $height  = $imgInfo !== false ? (int) $imgInfo[1] : 0;

            $newFilename = 'c_' . uniqid('', true) . bin2hex(random_bytes(4)) . '.' . $ext;
            $dest        = $uploadDir . DIRECTORY_SEPARATOR . $newFilename;

            if (!move_uploaded_file($tmpName, $dest)) {
                $skipped[] = $safeName . ' (move failed)';
                continue;
            }

            $codesRepo->insert(
                null,
                $newFilename,
                $width,
                $height,
                ++$sortOffset,
                null,
                null,
                $uploadStatus,
                null,
                $submitterName !== '' ? $submitterName : null,
                $submitterEmail !== '' ? $submitterEmail : null,
                $submitterUrl !== '' ? $submitterUrl : null,
            );
            $uploaded++;
        }

        $activityRepo->log(
            (int) $session->get('lstr_admin_id'),
            'codes.collective.upload',
            "Uploaded {$uploaded} collective code(s)",
            $request->ip(),
        );

        if ($uploaded > 0) {
            $msg = 'Uploaded ' . $uploaded . ' collective code' . ($uploaded !== 1 ? 's' : '') . ' successfully.';
            if (!empty($skipped)) {
                $msg .= ' Skipped: ' . implode(', ', $skipped);
            }
            $session->set('lstr_flash_success', $msg);
        } else {
            $msg = 'No collective codes uploaded.';
            if (!empty($skipped)) {
                $msg .= ' Issues: ' . implode(', ', $skipped);
            }
            $session->set('lstr_flash_error', $msg);
        }

        return Response::redirect($baseUrl . '/admin/codes?collective=1');
    }

    // -----------------------------------------------------------------------
    // POST /admin/codes/collective/{id}/update
    // -----------------------------------------------------------------------

    /**
     * Updates the status, name, and submitter info for a single collective code.
     *
     * If the code is being approved for the first time (status changes to "approved"),
     * triggers the donor notification email.
     */
    public function updateCollective(Request $request, array $params): Response
    {
        $id      = (int) ($params['id'] ?? 0);
        $db      = $this->app->getDatabase();
        $session = $this->app->getSession();
        $baseUrl = $this->app->getBaseUrl();

        $codesRepo = new CodesRepo($db);
        $code      = $codesRepo->getById($id);

        if ($code === null || $code->fanlisting_id !== null) {
            return Response::notFound('Collective code not found.');
        }

        $status = trim((string) $request->post('status', ''));
        if (!in_array($status, CodesRepo::STATUSES, true)) {
            $status = $code->status;
        }

        $name           = trim((string) $request->post('name', ''));
        $submitterName  = trim((string) $request->post('submitter_name', ''));
        $submitterEmail = trim((string) $request->post('submitter_email', ''));
        $submitterUrl   = trim((string) $request->post('submitter_url', ''));

        if ($submitterEmail !== '' && filter_var($submitterEmail, FILTER_VALIDATE_EMAIL) === false) {
            $submitterEmail = '';
        }
        if ($submitterUrl !== '' && (
            filter_var($submitterUrl, FILTER_VALIDATE_URL) === false
            || !in_array(parse_url($submitterUrl, PHP_URL_SCHEME), ['http', 'https'], true)
        )) {
            $submitterUrl = '';
        }

        $codesRepo->updateStatus($id, $status);
        $codesRepo->updateName($id, $name !== '' ? $name : null);
        $codesRepo->updateSubmitterName($id, $submitterName !== '' ? $submitterName : null);
        $codesRepo->updateSubmitterEmail($id, $submitterEmail !== '' ? $submitterEmail : null);
        $codesRepo->updateSubmitterUrl($id, $submitterUrl !== '' ? $submitterUrl : null);

        if ($status === 'approved' && $code->status !== 'approved') {
            $this->maybeSendDonorEmail($db, $codesRepo->getById($id));
        }

        (new ActivityRepo($db))->log(
            (int) $session->get('lstr_admin_id'),
            'code.update',
            "Updated collective code #{$id}",
            $request->ip(),
        );

        $tab = trim((string) $request->post('_tab', $status));
        $session->set('lstr_flash_success', 'Code updated.');
        return Response::redirect($baseUrl . '/admin/codes?collective=1&tab=' . urlencode($tab));
    }

    // -----------------------------------------------------------------------
    // POST /admin/codes/collective/{id}/delete
    // -----------------------------------------------------------------------

    /**
     * Deletes a single collective code record (does not delete the image file from disk).
     */
    public function deleteCollective(Request $request, array $params): Response
    {
        $id      = (int) ($params['id'] ?? 0);
        $db      = $this->app->getDatabase();
        $session = $this->app->getSession();
        $baseUrl = $this->app->getBaseUrl();

        $codesRepo = new CodesRepo($db);
        $code      = $codesRepo->getById($id);

        if ($code === null || $code->fanlisting_id !== null) {
            return Response::notFound('Collective code not found.');
        }

        $codesRepo->delete($id);

        (new ActivityRepo($db))->log(
            (int) $session->get('lstr_admin_id'),
            'code.delete',
            "Deleted collective code #{$id} ({$code->filename})",
            $request->ip(),
        );

        $tab = trim((string) $request->post('_tab', 'approved'));
        $session->set('lstr_flash_success', 'Code deleted.');
        return Response::redirect($baseUrl . '/admin/codes?collective=1&tab=' . urlencode($tab));
    }

    // -----------------------------------------------------------------------
    // POST /admin/codes/collective/bulk
    // -----------------------------------------------------------------------

    /**
     * Bulk-approves, bulk-rejects, or bulk-deletes selected collective codes.
     *
     * Delete verifies each code is actually collective (null fanlisting_id) before acting.
     */
    public function bulkCollective(Request $request, array $params): Response
    {
        $db      = $this->app->getDatabase();
        $session = $this->app->getSession();
        $baseUrl = $this->app->getBaseUrl();

        $action = trim((string) $request->post('bulk_action', ''));
        $rawIds = (array) $request->post('selected', []);
        $ids    = array_values(array_filter(array_map('intval', $rawIds), fn(int $i) => $i > 0));
        $tab    = trim((string) $request->post('_tab', 'approved'));

        if (empty($ids) || !in_array($action, ['approve', 'reject', 'delete'], true)) {
            $session->set('lstr_flash_error', 'No codes selected or invalid action.');
            return Response::redirect($baseUrl . '/admin/codes?collective=1&tab=' . urlencode($tab));
        }

        $codesRepo = new CodesRepo($db);
        $count     = 0;

        if ($action === 'delete') {
            foreach ($ids as $codeId) {
                $c = $codesRepo->getById($codeId);
                if ($c !== null && $c->fanlisting_id === null) {
                    $codesRepo->delete($codeId);
                    $count++;
                }
            }
        } else {
            $status = $action === 'approve' ? 'approved' : 'rejected';
            $count  = $codesRepo->bulkStatus($ids, $status);
            if ($action === 'approve') {
                foreach ($ids as $codeId) {
                    $c = $codesRepo->getById($codeId);
                    if ($c !== null) {
                        $this->maybeSendDonorEmail($db, $c);
                    }
                }
            }
        }

        (new ActivityRepo($db))->log(
            (int) $session->get('lstr_admin_id'),
            "code.bulk_{$action}",
            "Bulk {$action}: {$count} collective code(s)",
            $request->ip(),
        );

        $session->set('lstr_flash_success', ucfirst($action) . "d {$count} collective code(s).");
        return Response::redirect($baseUrl . '/admin/codes?collective=1&tab=' . urlencode($tab));
    }

    // -----------------------------------------------------------------------
    // POST /admin/codes/collective/reorder
    // -----------------------------------------------------------------------

    /**
     * Saves a new sort order for collective codes from a comma-separated ID list.
     */
    public function reorderCollective(Request $request, array $params): Response
    {
        $db      = $this->app->getDatabase();
        $session = $this->app->getSession();
        $baseUrl = $this->app->getBaseUrl();

        $raw        = trim((string) $request->post('ordered_ids', ''));
        $orderedIds = $raw !== '' ? array_map('intval', explode(',', $raw)) : [];
        $orderedIds = array_values(array_filter($orderedIds, fn(int $i) => $i > 0));

        if (!empty($orderedIds)) {
            (new CodesRepo($db))->reorderCollective($orderedIds);

            (new ActivityRepo($db))->log(
                (int) $session->get('lstr_admin_id'),
                'code.reorder',
                'Reordered ' . count($orderedIds) . ' collective code(s)',
                $request->ip(),
            );
        }

        $tab = trim((string) $request->post('_tab', 'approved'));
        $session->set('lstr_flash_success', 'Collective code order saved.');
        return Response::redirect($baseUrl . '/admin/codes?collective=1&tab=' . urlencode($tab));
    }

    // -----------------------------------------------------------------------
    // GET /admin/codes/collective/export
    // -----------------------------------------------------------------------

    /**
     * Streams a ZIP archive of all approved collective code image files to the browser.
     *
     * Requires the PHP zip extension. Returns an error flash if no approved
     * collective codes exist or no image files are found on disk.
     */
    public function exportCollective(Request $request, array $params): Response
    {
        $session = $this->app->getSession();
        $baseUrl = $this->app->getBaseUrl();

        if (!class_exists('ZipArchive')) {
            $session->set('lstr_flash_error', 'The PHP zip extension is not enabled. Enable extension=zip in php.ini and restart your web server.');
            return Response::redirect($baseUrl . '/admin/codes?collective=1');
        }

        $db        = $this->app->getDatabase();
        $codesRepo = new CodesRepo($db);
        $codes     = $codesRepo->getByListing(null, 'approved');

        if (empty($codes)) {
            $session->set('lstr_flash_error', 'No approved collective codes to export.');
            return Response::redirect($baseUrl . '/admin/codes?collective=1');
        }

        $uploadDir = realpath(__DIR__ . '/../../../public/uploads/codes');
        if ($uploadDir === false) {
            $session->set('lstr_flash_error', 'Upload directory not found.');
            return Response::redirect($baseUrl . '/admin/codes?collective=1');
        }

        $tmpFile = tempnam(sys_get_temp_dir(), 'lstr_ccodes_');
        if ($tmpFile === false) {
            $session->set('lstr_flash_error', 'Could not create temporary file for export.');
            return Response::redirect($baseUrl . '/admin/codes?collective=1');
        }

        $zip = new \ZipArchive();
        if ($zip->open($tmpFile, \ZipArchive::OVERWRITE) !== true) {
            unlink($tmpFile);
            $session->set('lstr_flash_error', 'Could not create ZIP archive.');
            return Response::redirect($baseUrl . '/admin/codes?collective=1');
        }

        $added = 0;
        foreach ($codes as $code) {
            $filePath = $uploadDir . DIRECTORY_SEPARATOR . $code->filename;
            if (is_file($filePath)) {
                $zip->addFile($filePath, $code->filename);
                $added++;
            }
        }

        $zip->close();

        if ($added === 0) {
            unlink($tmpFile);
            $session->set('lstr_flash_error', 'No collective code image files found on disk.');
            return Response::redirect($baseUrl . '/admin/codes?collective=1');
        }

        $zipContent = file_get_contents($tmpFile);
        unlink($tmpFile);

        if ($zipContent === false) {
            $session->set('lstr_flash_error', 'Could not read ZIP archive.');
            return Response::redirect($baseUrl . '/admin/codes?collective=1');
        }

        $config   = $this->app->getConfig();
        $siteName = preg_replace('/[^a-zA-Z0-9_.\-]/', '_', (string) $config->get('site_name', 'collective'));
        $filename = 'codes_' . ($siteName !== '' ? $siteName : 'collective') . '_' . date('Ymd') . '.zip';

        return Response::download($zipContent, $filename, 'application/zip');
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    /**
     * Redirects back to the codes list, preserving the active tab and listing context.
     */
    private function redirectBack(Request $request, string $baseUrl, int $listingId): Response
    {
        $tab = trim((string) $request->post('_tab', 'pending'));
        $qs  = $listingId > 0
            ? '?listing_id=' . $listingId . '&tab=' . urlencode($tab)
            : '?tab=pending';
        return Response::redirect($baseUrl . '/admin/codes' . $qs);
    }

    /**
     * Sends a code-approval notification to the submitter if they provided an email.
     *
     * Respects the per-listing `code_approval_email` setting, falling back to the
     * global `code_approval_email_enabled` site setting. Failures are logged to the
     * PHP error log but do not interrupt the approval flow.
     */
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
            $_es       = (new ListingSettingsRepo($db))->getEmbedSettings($listingId);
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
                listingEmail:   $listing->email,
                formUrlJoin:    (string) $_es['form_url_join'],
                formUrlUpdate:  (string) $_es['form_url_update'],
                formUrlReset:   (string) $_es['form_url_reset'],
            );

            [$subject, $body] = $emailRepo->resolveForListing($listingId, EmailTemplateRepo::TYPE_CODE_APPROVED, $tokens);
            (new Mailer($config))->send($email, (string) ($code->submitter_name ?? ''), $subject, $body);
        } catch (\Throwable $e) {
            error_log('[Luster] Code approval email failed: ' . $e->getMessage());
        }
    }
}
