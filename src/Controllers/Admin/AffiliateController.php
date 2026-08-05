<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Manages affiliates (link exchange partners) across all listings and at the
 collective level. Handles the affiliate index, add/edit/delete/toggle actions,
 pending affiliate approval with email notifications, bulk operations, and the
 affiliate bulk email composer. Supports both global and per-listing contexts,
 redirecting back to the correct page depending on where the action originated.
*/

declare(strict_types=1);

namespace Luster\Controllers\Admin;

use Luster\Core\Mailer;
use Luster\Core\Request;
use Luster\Core\Response;
use Luster\Core\Validator;
use Luster\Repositories\ActivityRepo;
use Luster\Repositories\AffiliateRepo;
use Luster\Repositories\EmailTemplateRepo;
use Luster\Repositories\ListingRepo;
use Luster\Repositories\ListingSettingsRepo;
use Luster\Repositories\SavedEmailRepo;

/**
 * Manages affiliate (link exchange) entries across all listings and at the
 * collective level.
 *
 * Covers: paginated/searchable index with status tabs, add/edit/delete, active
 * toggle, drag-drop reordering, pending application approval/rejection/re-approval
 * with email notifications, bulk actions, and the affiliate bulk email composer.
 */
final class AffiliateController extends AdminController
{

    // -----------------------------------------------------------------------
    // POST /admin/affiliates/bulk
    // -----------------------------------------------------------------------

    /**
     * Handles bulk operations on selected affiliates: approve, reject, re-approve,
     * activate, deactivate, delete, or redirect to the bulk email composer.
     */
    public function bulk(Request $request, array $params): Response
    {
        $db      = $this->app->getDatabase();
        $session = $this->app->getSession();
        $baseUrl = $this->app->getBaseUrl();

        $action        = (string) $request->post('action', '');
        $affiliateRepo = new AffiliateRepo($db);

        $returnTo = trim((string) $request->post('_return', ''));
        $redirect = ($returnTo !== '' && str_starts_with($returnTo, $baseUrl . '/admin/affiliates'))
            ? $returnTo
            : $baseUrl . '/admin/affiliates';

        $selected = array_values(array_filter(
            array_map('intval', (array) $request->post('selected', [])),
            fn(int $id) => $id > 0
        ));

        if (empty($selected)) {
            $session->set('lstr_flash_error', 'No affiliates selected.');
            return Response::redirect($redirect);
        }

        if ($action === 'email_selected') {
            $session->set('lstr_aff_selected_ids', $selected);
            return Response::redirect($baseUrl . '/admin/affiliates/email');
        }

        $count = count($selected);

        $affected = match ($action) {
            'approve'    => $affiliateRepo->bulkApprove($selected),
            'reject'     => $affiliateRepo->bulkReject($selected),
            're-approve' => $affiliateRepo->bulkReApprove($selected),
            'activate'   => $affiliateRepo->bulkSetActive($selected, true),
            'deactivate' => $affiliateRepo->bulkSetActive($selected, false),
            'delete'     => $affiliateRepo->bulkDelete($selected),
            default      => 0,
        };

        $msg = match ($action) {
            'approve'    => "Approved {$affected} affiliate application(s).",
            'reject'     => "Rejected {$affected} affiliate application(s).",
            're-approve' => "Re-approved {$affected} affiliate(s).",
            'activate'   => "Activated {$count} affiliate(s).",
            'deactivate' => "Deactivated {$count} affiliate(s).",
            'delete'     => "Deleted {$count} affiliate(s).",
            default      => 'Action applied.',
        };

        $session->set('lstr_flash_success', $msg);
        return Response::redirect($redirect);
    }

    // -----------------------------------------------------------------------
    // GET /admin/affiliates
    // -----------------------------------------------------------------------

    /**
     * Renders the paginated affiliate index with status tabs, scope/search
     * filters, and column-header sorting. Auto-lands on the pending tab when
     * there are unapproved applications.
     */
    public function index(Request $request, array $params): Response
    {
        $db     = $this->app->getDatabase();
        $config = $this->app->getConfig();

        $affiliateRepo = new AffiliateRepo($db);
        $listingRepo   = new ListingRepo($db, $config);
        $listings      = $listingRepo->getAll();

        $listingMap = [];
        foreach ($listings as $fl) {
            $listingMap[$fl->id] = $fl;
        }

        $tabParam = $request->query('tab', null);
        $isBareNavigation = $tabParam === null
                         && $request->query('scope',  null) === null
                         && $request->query('search', null) === null
                         && $request->query('page',   null) === null;

        if ($isBareNavigation) {
            $affiliateRepo  = new AffiliateRepo($this->app->getDatabase());
            $pendingCount   = $affiliateRepo->getPendingCount();
            $landingTab     = $pendingCount > 0 ? 'pending' : 'all';
            return Response::redirect($this->app->getBaseUrl() . '/admin/affiliates?tab=' . $landingTab);
        }

        $tab = trim((string) ($tabParam ?? 'all'));
        if (!in_array($tab, ['all', 'pending', 'approved', 'inactive', 'rejected'], true)) {
            $tab = 'all';
        }
        $scope   = trim((string) $request->query('scope', 'all'));
        $search  = trim((string) $request->query('search', ''));
        $page    = max(1, (int) $request->query('page', 1));
        $perPage = 10;

        $allowedCols = [
            'name'       => 'name',
            'listing'    => 'COALESCE(fanlisting_id, 0)',
            'email'      => 'email',
            'status'     => 'status',
            'activity'   => 'active',
            'sort_order' => 'sort_order',
        ];
        $sortParam   = (string) $request->query('sort', 'name');
        $dirParam    = strtolower(trim((string) $request->query('dir', 'asc')));
        $sortCol     = $allowedCols[$sortParam] ?? 'name';
        $sortDir     = $dirParam === 'desc' ? 'DESC' : 'ASC';
        $orderBy     = $sortCol . ' ' . $sortDir . ($sortCol !== 'name' ? ', name ASC' : '');

        $result      = $affiliateRepo->getAdminPaginated($tab, $scope, $search, $perPage, $page, $orderBy);
        $counts      = $affiliateRepo->getAdminTabCounts($scope, $search);
        $scopeCounts = $affiliateRepo->getScopeCounts();
        $affiliates = $result['items'];
        $total      = $result['total'];
        $totalPages = $result['pages'];

        $conflictIds = $tab === 'pending'
            ? array_flip($affiliateRepo->getPendingIdsWithUrlConflict())
            : [];

        $session = $this->app->getSession();
        $success = (string) $session->get('lstr_flash_success', '');
        $error   = (string) $session->get('lstr_flash_error',   '');
        $session->remove('lstr_flash_success');
        $session->remove('lstr_flash_error');

        return $this->adminLayout('admin/affiliates/index.php', 'Affiliates', 'affiliates', [
            'affiliates'    => $affiliates,
            'listing_map'   => $listingMap,
            'listings'      => $listings,
            'tab'           => $tab,
            'scope'         => $scope,
            'search'        => $search,
            'page'          => $page,
            'per_page'      => $perPage,
            'total_pages'   => $totalPages,
            'total'         => $total,
            'counts'        => $counts,
            'scope_counts'  => $scopeCounts,
            'sort'          => $sortParam,
            'dir'           => strtolower($sortDir),
            'conflict_ids'  => $conflictIds,
            'filters_active' => $scope !== 'all' || $search !== '',
            'success'       => $success,
            'error'         => $error,
        ]);
    }

    // -----------------------------------------------------------------------
    // GET + POST /admin/affiliates/add
    // GET + POST /admin/listings/{fid}/affiliates/add
    // -----------------------------------------------------------------------

    /**
     * Renders the add-affiliate form (GET) or creates a new affiliate record
     * with optional image upload (POST). Works in both global context (no
     * listing selected) and per-listing context (fid route param or context_fid
     * query param).
     */
    public function add(Request $request, array $params): Response
    {
        $db     = $this->app->getDatabase();
        $config = $this->app->getConfig();

        $affiliateRepo  = new AffiliateRepo($db);
        $listingRepo = new ListingRepo($db, $config);
        $listings    = $listingRepo->getAll();

        $session = $this->app->getSession();
        $baseUrl = $this->app->getBaseUrl();

        // Listing context — route {fid} means per-listing tab; ?listing= is a preselect hint
        $routeFid   = (int) ($params['fid'] ?? 0);
        $contextFid = $routeFid > 0 ? $routeFid : (int) $request->query('context_fid', 0);
        if ($contextFid === 0) {
            $contextFid = (int) $request->query('listing', 0);
        }

        $contextListing = $contextFid > 0
            ? (static function (array $ls, int $fid): ?object { foreach ($ls as $l) { if ((int) $l->id === $fid) return $l; } return null; })($listings, $contextFid)
            : null;
        $addBreadcrumbs = $this->affiliateBreadcrumbs($contextListing);

        if ($request->isPost()) {
            // When routed through /listings/{fid}/affiliates/add the listing ID
            // comes from the route param; for global add it comes from the form field.
            $fanlistingId   = $contextFid > 0 ? $contextFid : (int) $request->post('fanlisting_id', 0);
            $name           = trim((string) $request->post('name', ''));
            $url            = self::httpsify(trim((string) $request->post('url', '')));
            $email          = trim((string) $request->post('email', ''));
            $sortOrder      = (int) $request->post('sort_order', 0);

            // Image upload — validate only; file is NOT moved here.
            // move_uploaded_file() runs after ALL validation passes to prevent orphaned files.
            $image       = '';
            $imageError  = '';
            $pendingMove = null; // set when image is valid but not yet stored
            $imageFile   = $request->file('image') ?? [];
            if (!empty($imageFile['name']) && (int) ($imageFile['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                if ((int) $imageFile['error'] !== UPLOAD_ERR_OK) {
                    $imageError = 'File upload failed. Please try again.';
                } elseif (!is_uploaded_file((string) $imageFile['tmp_name'])) {
                    $imageError = 'Invalid upload. Please try again.';
                } else {
                    $finfo   = new \finfo(FILEINFO_MIME_TYPE);
                    $mime    = $finfo->file((string) $imageFile['tmp_name']);
                    $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp'];
                    if (!isset($allowed[$mime])) {
                        $imageError = 'Image must be a JPEG, PNG, GIF, or WebP file.';
                    } elseif ((int) $imageFile['size'] > 512000) {
                        $imageError = 'Image must be under 500 KB.';
                    } elseif (getimagesize((string) $imageFile['tmp_name']) === false) {
                        $imageError = 'The uploaded file is not a valid image.';
                    } else {
                        $pendingMove = [
                            'tmp'      => (string) $imageFile['tmp_name'],
                            'filename' => 'af_' . bin2hex(random_bytes(8)) . '.' . $allowed[$mime],
                        ];
                    }
                }
            }

            $v = new Validator(['name' => $name, 'url' => $url]);
            $v->field('name')->required()->maxLength(255);
            $v->field('url')->required()->httpUrl()->maxLength(500);

            $siteName  = (string) ($this->app->getConfig()->get('site_name', 'Collective'));
            $allErrors = $v->fails() ? $v->allErrors() : [];
            if ($imageError !== '') {
                $allErrors[] = $imageError;
            }

            if (!empty($allErrors)) {
                // tmp file was never moved — PHP cleans it up automatically
                if ($routeFid > 0) {
                    $session->set('lstr_flash_error', implode(' ', $allErrors));
                    return Response::redirect($baseUrl . '/admin/listings/' . $routeFid . '/edit?tab=affiliates');
                }
                return $this->adminLayout('admin/affiliates/add.php', 'Add Affiliate', 'affiliates', [
                    'listings'     => $listings,
                    'affiliate'    => null,
                    'error'        => implode(' ', $allErrors),
                    'field_errors' => $v->errors(),
                    'old'          => $request->postAll(),
                    'context_fid'  => 0,
                    'site_name'    => $siteName,
                ], '', $addBreadcrumbs);
            }

            // All validation passed — now commit the image to disk
            if ($pendingMove !== null) {
                $dir = dirname(__DIR__, 3) . '/public/uploads/affiliates/';
                if (!is_dir($dir)) {
                    mkdir($dir, 0755, true);
                    file_put_contents($dir . '.htaccess',
                        "<FilesMatch \"\\.(?i:jpg|jpeg|png|gif|webp)$\">\n    Require all granted\n</FilesMatch>\n<FilesMatch \".\">\n    Require all denied\n</FilesMatch>\n"
                    );
                }
                if (!move_uploaded_file($pendingMove['tmp'], $dir . $pendingMove['filename'])) {
                    $session->set('lstr_flash_error', 'Could not save the uploaded image. Please try again.');
                    if ($routeFid > 0) {
                        return Response::redirect($baseUrl . '/admin/listings/' . $routeFid . '/edit?tab=affiliates');
                    }
                    return Response::redirect($baseUrl . '/admin/affiliates/add');
                }
                $image = $pendingMove['filename'];
            }

            $affiliateRepo->insert([
                'fanlisting_id' => $fanlistingId > 0 ? $fanlistingId : null,
                'name'          => $name,
                'url'           => $url,
                'email'         => $email !== '' ? $email : null,
                'image'         => $image !== '' ? $image : null,
                'sort_order'    => $sortOrder,
            ]);

            if ($contextFid > 0) {
                (new ListingSettingsRepo($db))->touchLastUpdated($contextFid);
            }

            $session->set('lstr_flash_success', 'Affiliate added.');

            $fromListing = (int) $request->post('from_listing', 0);
            if ($fromListing > 0) {
                $redirect = $baseUrl . '/admin/listings/' . $fromListing . '/edit?tab=affiliates';
            } else {
                $redirect = $baseUrl . '/admin/affiliates';
            }

            return Response::redirect($redirect);
        }

        $siteName = (string) ($this->app->getConfig()->get('site_name', 'Collective'));

        // Prefill sort order: next available in the context scope (collective when no context)
        $nextSortOrder = $affiliateRepo->getNextSortOrder($contextFid > 0 ? $contextFid : null);

        $preselect = (int) $request->query('listing', 0);
        $old       = $preselect > 0 ? ['fanlisting_id' => $preselect] : [];

        return $this->adminLayout('admin/affiliates/add.php', 'Add Affiliate', 'affiliates', [
            'listings'       => $listings,
            'affiliate'      => null,
            'error'          => '',
            'old'            => $old,
            'context_fid'    => $contextFid,
            'from_listing'   => $routeFid,
            'site_name'      => $siteName,
            'next_sort_order' => $nextSortOrder,
        ], '', $addBreadcrumbs);
    }

    // -----------------------------------------------------------------------
    // GET + POST /admin/affiliates/{id}/edit
    // -----------------------------------------------------------------------

    /**
     * Renders the edit form for an existing affiliate (GET) or saves updated
     * name/URL/email/image/status/sort_order (POST).
     */
    public function editAffiliate(Request $request, array $params): Response
    {
        $id = (int) ($params['id'] ?? 0);

        $db     = $this->app->getDatabase();
        $config = $this->app->getConfig();

        $affiliateRepo  = new AffiliateRepo($db);
        $listingRepo = new ListingRepo($db, $config);

        $affiliate   = $affiliateRepo->getById($id);
        $listings = $listingRepo->getAll();

        if ($affiliate === null) {
            return Response::notFound('Affiliate not found.');
        }

        $editListingObj = $affiliate->listingId !== null
            ? (static function (array $ls, int $fid): ?object { foreach ($ls as $l) { if ((int) $l->id === $fid) return $l; } return null; })($listings, $affiliate->listingId)
            : null;
        $editBreadcrumbs = $this->affiliateBreadcrumbs($editListingObj, $affiliate->name);

        $session = $this->app->getSession();
        $baseUrl = $this->app->getBaseUrl();

        if ($request->isPost()) {
            $name      = trim((string) $request->post('name', ''));
            $url       = self::httpsify(trim((string) $request->post('url', '')));
            $email     = trim((string) $request->post('email', ''));
            $sortOrder = (int) $request->post('sort_order', 0);
            $fromFl    = (int) $request->post('from_listing', 0);
            $statusVal = trim((string) $request->post('status', ''));
            $activeVal = $request->post('active', null);

            // Image upload — validate only; file is NOT moved here.
            // move_uploaded_file() and old-image deletion run after ALL validation
            // passes to prevent orphaned files and loss of the previous image.
            $image       = $affiliate->image ?? '';
            $imageError  = '';
            $pendingMove = null;
            $deleteImage = (bool) $request->post('delete_image');
            $imageFile   = $request->file('image') ?? [];
            if (!empty($imageFile['name']) && (int) ($imageFile['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                if ((int) $imageFile['error'] !== UPLOAD_ERR_OK) {
                    $imageError = 'File upload failed. Please try again.';
                } elseif (!is_uploaded_file((string) $imageFile['tmp_name'])) {
                    $imageError = 'Invalid upload. Please try again.';
                } else {
                    $finfo   = new \finfo(FILEINFO_MIME_TYPE);
                    $mime    = $finfo->file((string) $imageFile['tmp_name']);
                    $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp'];
                    if (!isset($allowed[$mime])) {
                        $imageError = 'Image must be a JPEG, PNG, GIF, or WebP file.';
                    } elseif ((int) $imageFile['size'] > 512000) {
                        $imageError = 'Image must be under 500 KB.';
                    } elseif (getimagesize((string) $imageFile['tmp_name']) === false) {
                        $imageError = 'The uploaded file is not a valid image.';
                    } else {
                        $pendingMove = [
                            'tmp'      => (string) $imageFile['tmp_name'],
                            'filename' => 'af_' . bin2hex(random_bytes(8)) . '.' . $allowed[$mime],
                        ];
                    }
                }
            }

            $v = new Validator(['name' => $name, 'url' => $url]);
            $v->field('name')->required()->maxLength(255);
            $v->field('url')->required()->httpUrl()->maxLength(500);

            $siteName  = (string) ($this->app->getConfig()->get('site_name', 'Collective'));
            $allErrors = $v->fails() ? $v->allErrors() : [];
            if ($imageError !== '') {
                $allErrors[] = $imageError;
            }

            if (!empty($allErrors)) {
                // tmp file was never moved — PHP cleans it up automatically
                return $this->adminLayout('admin/affiliates/add.php', 'Edit Affiliate', 'affiliates', [
                    'listings'      => $listings,
                    'affiliate'     => $affiliate,
                    'error'         => implode(' ', $allErrors),
                    'field_errors'  => $v->errors(),
                    'old'           => $request->postAll(),
                    'context_fid'   => $fromFl,
                    'from_listing'  => $fromFl,
                    'site_name'     => $siteName,
                ], '', $editBreadcrumbs);
            }

            // All validation passed — now commit image changes to disk
            $dir = dirname(__DIR__, 3) . '/public/uploads/affiliates/';
            if ($pendingMove !== null) {
                if (!is_dir($dir)) {
                    mkdir($dir, 0755, true);
                    file_put_contents($dir . '.htaccess',
                        "<FilesMatch \"\\.(?i:jpg|jpeg|png|gif|webp)$\">\n    Require all granted\n</FilesMatch>\n<FilesMatch \".\">\n    Require all denied\n</FilesMatch>\n"
                    );
                }
                if (!move_uploaded_file($pendingMove['tmp'], $dir . $pendingMove['filename'])) {
                    $session->set('lstr_flash_error', 'Could not save the uploaded image. Please try again.');
                    $editQs = $fromFl > 0 ? '?from_listing=' . $fromFl : '';
                    return Response::redirect($baseUrl . '/admin/affiliates/' . $id . '/edit' . $editQs);
                }
                // New file is on disk — now safe to delete the old one
                if ($image !== '' && file_exists($dir . $image)) {
                    unlink($dir . $image);
                }
                $image = $pendingMove['filename'];
            } elseif ($deleteImage && $image !== '') {
                if (file_exists($dir . $image)) {
                    unlink($dir . $image);
                }
                $image = '';
            }

            $affiliateRepo->update($id, [
                'name'       => $name,
                'url'        => $url,
                'email'      => $email !== '' ? $email : null,
                'image'      => $image !== '' ? $image : null,
                'sort_order' => $sortOrder,
                'status'     => in_array($statusVal, ['pending', 'approved', 'rejected'], true) ? $statusVal : null,
                'active'     => $activeVal !== null ? (int) $activeVal : null,
            ]);

            if ($fromFl > 0) {
                (new ListingSettingsRepo($db))->touchLastUpdated($fromFl);
            }

            $session->set('lstr_flash_success', 'Affiliate updated.');

            $redirect = $fromFl > 0
                ? $baseUrl . '/admin/listings/' . $fromFl . '/edit?tab=affiliates'
                : $baseUrl . '/admin/affiliates';

            return Response::redirect($redirect);
        }

        // GET: read context from query param
        $fromFl = (int) $request->query('from_listing', 0);

        $siteName = (string) ($this->app->getConfig()->get('site_name', 'Collective'));

        return $this->adminLayout('admin/affiliates/add.php', 'Edit Affiliate', 'affiliates', [
            'listings'     => $listings,
            'affiliate'    => $affiliate,
            'error'        => '',
            'old'          => [],
            'context_fid'  => $fromFl,
            'from_listing' => $fromFl,
            'site_name'    => $siteName,
        ], '', $editBreadcrumbs);
    }

    // -----------------------------------------------------------------------
    // POST /admin/affiliates/{id}/delete-image
    // -----------------------------------------------------------------------

    /**
     * Immediately deletes the affiliate's image from disk and clears the DB
     * reference. Returns JSON {ok:true} on success or {ok:false,error:...}.
     */
    public function deleteImage(Request $request, array $params): Response
    {
        $id = (int) ($params['id'] ?? 0);

        $db            = $this->app->getDatabase();
        $session       = $this->app->getSession();
        $affiliateRepo = new AffiliateRepo($db);
        $activityRepo  = new ActivityRepo($db);

        $affiliate = $affiliateRepo->getById($id);
        if ($affiliate === null) {
            return Response::json(['ok' => false, 'error' => 'Affiliate not found.']);
        }

        $filename = $affiliate->image ?? '';
        if ($filename === '') {
            return Response::json(['ok' => false, 'error' => 'No image to delete.']);
        }

        $dir  = dirname(__DIR__, 3) . '/public/uploads/affiliates/';
        $path = $dir . $filename;
        if (is_file($path)) {
            unlink($path);
        }

        $affiliateRepo->updateImage($id, null);

        $activityRepo->log(
            (int) $session->get('lstr_admin_id'),
            'affiliate.image_delete',
            "Deleted image for affiliate #{$id} ({$affiliate->name})",
            $request->ip(),
        );

        return Response::json(['ok' => true]);
    }

    // -----------------------------------------------------------------------
    // POST /admin/affiliates/reorder  (AJAX)
    // -----------------------------------------------------------------------

    /**
     * Accepts a drag-drop reorder payload and updates sort_order values.
     * Splices the paged slice into the full ordered list so cross-page sort
     * positions remain consistent. Returns JSON {ok: true}.
     */
    public function reorder(Request $request, array $params): Response
    {
        $db   = $this->app->getDatabase();
        $repo = new AffiliateRepo($db);

        $raw = (array) $request->post('ids', []);
        $ids = array_values(array_filter(array_map('intval', $raw), fn(int $id) => $id > 0));

        if (empty($ids)) {
            return Response::json(['ok' => false, 'error' => 'No IDs provided.']);
        }

        // Splice the page slice into the full ordered list so cross-page sort_order
        // values are consistent — same approach as the wishlist reorder.
        $scopeRaw  = trim((string) $request->post('scope', 'all'));
        $status    = trim((string) $request->post('tab',   'approved'));
        $page      = max(1, (int) $request->post('page',     1));
        $perPage   = max(1, min(200, (int) $request->post('per_page', 10)));

        $listingId = match (true) {
            $scopeRaw === 'collective'  => null,
            ctype_digit($scopeRaw) && (int) $scopeRaw > 0 => (int) $scopeRaw,
            default => -1,
        };

        $allIds  = $repo->getIdsByScope($listingId, $status);
        $offset  = ($page - 1) * $perPage;
        $before  = array_slice($allIds, 0, $offset);
        $after   = array_slice($allIds, $offset + count($ids));
        $reordered = array_merge($before, $ids, $after);

        $repo->setSortOrders($reordered);

        return Response::json(['ok' => true]);
    }

    // -----------------------------------------------------------------------
    // POST /admin/affiliates/{id}/toggle
    // -----------------------------------------------------------------------

    /**
     * Flips the active/inactive state of an affiliate and updates the listing's
     * last_updated timestamp when called from a per-listing context.
     */
    public function toggle(Request $request, array $params): Response
    {
        $id = (int) ($params['id'] ?? 0);
        $db = $this->app->getDatabase();

        $affiliateRepo = new AffiliateRepo($db);
        $session       = $this->app->getSession();
        $baseUrl       = $this->app->getBaseUrl();

        if ($affiliateRepo->getById($id) === null) {
            return Response::notFound('Affiliate not found.');
        }

        $affiliateRepo->toggle($id);
        $fromFl = (int) $request->post('from_listing', 0);

        if ($fromFl > 0) {
            (new ListingSettingsRepo($db))->touchLastUpdated($fromFl);
        }

        $session->set('lstr_flash_success', 'Affiliate status toggled.');

        $redirect = $fromFl > 0
            ? $baseUrl . '/admin/listings/' . $fromFl
            : $baseUrl . '/admin/affiliates';

        return Response::redirect($redirect);
    }

    // -----------------------------------------------------------------------
    // POST /admin/affiliates/{id}/delete
    // -----------------------------------------------------------------------

    /**
     * Deletes an affiliate record, repacks sort_order values for the scope,
     * and redirects back to the listing or the global affiliates page.
     */
    public function deleteAffiliate(Request $request, array $params): Response
    {
        $id = (int) ($params['id'] ?? 0);
        $db = $this->app->getDatabase();

        $affiliateRepo = new AffiliateRepo($db);
        $session       = $this->app->getSession();
        $baseUrl       = $this->app->getBaseUrl();

        $affiliate = $affiliateRepo->getById($id);

        if ($affiliate === null) {
            return Response::notFound('Affiliate not found.');
        }

        $affiliateRepo->delete($id);
        $affiliateRepo->repackSortOrder($affiliate->listingId);

        $fromFl = (int) $request->post('from_listing', 0);

        if ($fromFl > 0) {
            (new ListingSettingsRepo($db))->touchLastUpdated($fromFl);
        }

        $session->set('lstr_flash_success', 'Affiliate deleted.');

        $redirect = $fromFl > 0
            ? $baseUrl . '/admin/listings/' . $fromFl
            : $baseUrl . '/admin/affiliates';

        return Response::redirect($redirect);
    }

    // -----------------------------------------------------------------------
    // GET /admin/affiliates/pending
    // -----------------------------------------------------------------------

    /**
     * Permanent redirect from the legacy /pending URL to the pending status tab.
     */
    public function pending(Request $request, array $params): Response
    {
        return Response::redirect($this->app->getBaseUrl() . '/admin/affiliates?tab=pending');
    }

    // -----------------------------------------------------------------------
    // POST /admin/affiliates/{id}/approve
    // -----------------------------------------------------------------------

    /**
     * Approves a pending affiliate application, logs the action, and sends
     * an approval email to the applicant when an email address is provided.
     */
    public function approve(Request $request, array $params): Response
    {
        $id      = (int) ($params['id'] ?? 0);
        $db      = $this->app->getDatabase();
        $session = $this->app->getSession();
        $baseUrl = $this->app->getBaseUrl();

        $affiliateRepo = new AffiliateRepo($db);
        $affiliate     = $affiliateRepo->getById($id);

        if ($affiliate === null || $affiliate->status !== 'pending') {
            return Response::notFound('Pending application not found.');
        }

        $affiliateRepo->approve($id);

        if ($affiliate->listingId !== null) {
            (new ListingSettingsRepo($db))->touchLastUpdated($affiliate->listingId);
        }

        (new ActivityRepo($db))->log(
            (int) $session->get('lstr_admin_id'),
            'affiliate.approve',
            "Approved affiliate application #{$id}: {$affiliate->name} ({$affiliate->url})",
        );

        $emailNote = '';
        if ($affiliate->email !== null && $affiliate->email !== '') {
            if ($this->sendAffiliateEmail($affiliate, EmailTemplateRepo::TYPE_AFFILIATE_APPROVED)) {
                $emailNote = ' Email sent to ' . $affiliate->email . '.';
            }
        }

        $session->set('lstr_flash_success', 'Affiliate application approved.' . $emailNote);
        return Response::redirect($this->redirectAfterStatusChange($request, $baseUrl, 'pending'));
    }

    // -----------------------------------------------------------------------
    // POST /admin/affiliates/{id}/reject
    // -----------------------------------------------------------------------

    /**
     * Rejects a pending affiliate application, sends a rejection email if
     * the template is enabled, and logs the action.
     */
    public function reject(Request $request, array $params): Response
    {
        $id      = (int) ($params['id'] ?? 0);
        $db      = $this->app->getDatabase();
        $session = $this->app->getSession();
        $baseUrl = $this->app->getBaseUrl();

        $affiliateRepo = new AffiliateRepo($db);
        $affiliate     = $affiliateRepo->getById($id);

        if ($affiliate === null || $affiliate->status !== 'pending') {
            return Response::notFound('Pending application not found.');
        }

        $affiliateRepo->reject($id);

        $emailNote = '';
        if ($this->sendAffiliateEmail($affiliate, EmailTemplateRepo::TYPE_AFFILIATE_REJECTED)) {
            $emailNote = ' Email sent to ' . $affiliate->email . '.';
        }

        (new ActivityRepo($db))->log(
            (int) $session->get('lstr_admin_id'),
            'affiliate.reject',
            "Rejected affiliate application #{$id}: {$affiliate->name} ({$affiliate->url})",
        );

        $session->set('lstr_flash_success', 'Affiliate application rejected.' . $emailNote);
        return Response::redirect($this->redirectAfterStatusChange($request, $baseUrl, 'pending'));
    }

    // -----------------------------------------------------------------------
    // POST /admin/affiliates/{id}/re-approve
    // -----------------------------------------------------------------------

    /**
     * Moves a previously rejected application back to approved status.
     */
    public function reApprove(Request $request, array $params): Response
    {
        $id      = (int) ($params['id'] ?? 0);
        $db      = $this->app->getDatabase();
        $session = $this->app->getSession();
        $baseUrl = $this->app->getBaseUrl();

        $affiliateRepo = new AffiliateRepo($db);
        $affiliate     = $affiliateRepo->getById($id);

        if ($affiliate === null || $affiliate->status !== 'rejected') {
            return Response::notFound('Rejected application not found.');
        }

        $affiliateRepo->reApprove($id);

        (new ActivityRepo($db))->log(
            (int) $session->get('lstr_admin_id'),
            'affiliate.re_approve',
            "Re-approved affiliate #{$id}: {$affiliate->name} ({$affiliate->url})",
        );

        $session->set('lstr_flash_success', 'Affiliate re-approved.');
        return Response::redirect($this->redirectAfterStatusChange($request, $baseUrl, 'rejected'));
    }

    // -----------------------------------------------------------------------
    // GET /admin/affiliates/email
    // -----------------------------------------------------------------------

    /**
     * Renders the affiliate bulk email composer. Supports a "selected only"
     * mode (triggered by the bulk email_selected action) and a scope selector
     * for emailing all, collective, or per-listing affiliates.
     */
    public function emailCompose(Request $request, array $params): Response
    {
        $db      = $this->app->getDatabase();
        $config  = $this->app->getConfig();
        $session = $this->app->getSession();

        $affiliateRepo = new AffiliateRepo($db);
        $listingRepo   = new ListingRepo($db, $config);
        $listings      = $listingRepo->getAll();

        // Selected-only mode: triggered by bulk email_selected action
        $selectedIds  = array_values(array_filter(
            array_map('intval', (array) $session->get('lstr_aff_selected_ids', [])),
            fn(int $id) => $id > 0
        ));
        $session->remove('lstr_aff_selected_ids');
        $selectedOnly = !empty($selectedIds);

        if ($selectedOnly) {
            $idRecipMap  = $affiliateRepo->getEmailRecipientsByIds($selectedIds);
            $scopeCounts = [];
        } else {
            $idRecipMap = [];
            // Pre-compute recipient counts for each scope option
            $scopeCounts = [
                'all'        => count($affiliateRepo->getEmailRecipients('all')),
                'collective' => count($affiliateRepo->getEmailRecipients('collective')),
            ];
            foreach ($listings as $fl) {
                $scopeCounts[(string) $fl->id] = count($affiliateRepo->getEmailRecipients($fl->id));
            }
        }

        $savedEmails = (new SavedEmailRepo($db))->getAll();
        $selectedScope = trim((string) $request->query('scope', 'all'));

        $error   = (string) $session->get('lstr_flash_error',   '');
        $success = (string) $session->get('lstr_flash_success', '');
        $old     = (array)  $session->get('lstr_email_old',     []);
        $session->remove('lstr_flash_error');
        $session->remove('lstr_flash_success');
        $session->remove('lstr_email_old');

        $fromEmail = (string) $config->get('smtp_from', '') ?: (string) $config->get('owner_email', '');
        $fromName  = (string) $config->get('smtp_from_name', '') ?: (string) $config->get('owner_name', '');

        return $this->adminLayout('admin/affiliates/email.php', 'Email Affiliates', 'affiliates', [
            'listings'      => $listings,
            'scope_counts'  => $scopeCounts,
            'selected_scope'=> $selectedScope,
            'selected_only' => $selectedOnly,
            'id_recip_map'  => $idRecipMap,
            'aff_ids'       => $selectedIds,
            'saved_emails'  => $savedEmails,
            'from_name'     => $fromName,
            'from_email'    => $fromEmail,
            'owner_name'    => (string) $config->get('owner_name',  ''),
            'owner_email'   => (string) $config->get('owner_email', ''),
            'site_name'     => (string) $config->get('site_name',   ''),
            'site_url'      => (string) $config->get('site_url',     ''),
            'old'           => $old,
            'error'         => $error,
            'success'       => $success,
        ], '', [
            $this->crumb('Affiliates', $this->adminUrl('/admin/affiliates')),
        ]);
    }

    // -----------------------------------------------------------------------
    // POST /admin/affiliates/email
    // -----------------------------------------------------------------------

    /**
     * Sends the composed email to all affiliates in the selected scope,
     * substituting per-recipient tokens ({aff_name}, {listing_title}, etc.)
     * before each send. Logs the outcome to the activity log.
     */
    public function emailSend(Request $request, array $params): Response
    {
        $db      = $this->app->getDatabase();
        $config  = $this->app->getConfig();
        $session = $this->app->getSession();
        $baseUrl = $this->app->getBaseUrl();

        $subject = trim((string) $request->post('subject', ''));
        $body    = trim((string) $request->post('body',    ''));
        $scope   = trim((string) $request->post('scope',   'all'));

        $affIds       = array_values(array_filter(
            array_map('intval', (array) $request->post('aff_ids', [])),
            fn(int $id) => $id > 0
        ));
        $selectedOnly = !empty($affIds);

        if ($subject === '' || $body === '') {
            $session->set('lstr_flash_error', 'Please fill in both the subject and message before sending.');
            $session->set('lstr_email_old', ['subject' => $subject, 'body' => $body, 'scope' => $scope]);
            if ($selectedOnly) {
                $session->set('lstr_aff_selected_ids', $affIds);
                return Response::redirect($baseUrl . '/admin/affiliates/email');
            }
            return Response::redirect($baseUrl . '/admin/affiliates/email?scope=' . urlencode($scope));
        }

        $affiliateRepo = new AffiliateRepo($db);
        $listingRepo   = new ListingRepo($db, $config);

        if ($selectedOnly) {
            $recipients    = array_values($affiliateRepo->getEmailRecipientsByIds($affIds));
            $resolvedScope = 'selected';
        } else {
            // Resolve scope value
            $resolvedScope = match (true) {
                $scope === 'all'        => 'all',
                $scope === 'collective' => 'collective',
                ctype_digit($scope)     => (int) $scope,
                default                 => 'all',
            };
            $recipients = $affiliateRepo->getEmailRecipients($resolvedScope);
        }

        if (empty($recipients)) {
            $session->set('lstr_flash_error', 'No affiliates with email addresses found for the selected scope.');
            $session->set('lstr_email_old', ['subject' => $subject, 'body' => $body, 'scope' => $scope]);
            if ($selectedOnly) {
                $session->set('lstr_aff_selected_ids', $affIds);
                return Response::redirect($baseUrl . '/admin/affiliates/email');
            }
            return Response::redirect($baseUrl . '/admin/affiliates/email?scope=' . urlencode($scope));
        }

        // Pre-load listings for token resolution
        $listingMap = [];
        foreach ($listingRepo->getAll() as $fl) {
            $listingMap[$fl->id] = $fl;
        }

        $siteName  = (string) $config->get('site_name',   '');
        $siteUrl   = (string) $config->get('app_url',     '');
        $ownerName = (string) $config->get('owner_name',  '');
        $ownerEmail= (string) $config->get('owner_email', '');

        $mailer = new Mailer($config);
        $sent   = 0;
        $failed = 0;

        foreach ($recipients as $r) {
            $fl           = $r['listing_id'] !== null ? ($listingMap[$r['listing_id']] ?? null) : null;
            $listingName  = $fl !== null ? $fl->title : $siteName;
            $listingUrl   = $fl !== null ? $fl->url  : $siteUrl;

            $finalSubject = str_replace(
                ['{aff_name}', '{aff_email}', '{aff_url}', '{listing_title}', '{listing_url}', '{site_name}', '{owner_name}', '{owner_email}'],
                [$r['name'],   $r['email'],   $r['url'],   $listingName,     $listingUrl,      $siteName,     $ownerName,     $ownerEmail],
                $subject,
            );
            $finalBody = str_replace(
                ['{aff_name}', '{aff_email}', '{aff_url}', '{listing_title}', '{listing_url}', '{site_name}', '{owner_name}', '{owner_email}'],
                [$r['name'],   $r['email'],   $r['url'],   $listingName,     $listingUrl,      $siteName,     $ownerName,     $ownerEmail],
                $body,
            );

            try {
                $mailer->send($r['email'], $r['name'], $finalSubject, $finalBody);
                $sent++;
            } catch (\Throwable $e) {
                $failed++;
                error_log('[Affiliate] Bulk email failed for ' . $r['email'] . ': ' . $e->getMessage());
            }
        }

        $scopeLabel = match (true) {
            $resolvedScope === 'selected'   => 'selected affiliates (' . count($recipients) . ')',
            $resolvedScope === 'all'        => 'all affiliates',
            $resolvedScope === 'collective' => 'collective affiliates',
            default                         => 'listing #' . $resolvedScope . ' affiliates',
        };

        (new ActivityRepo($db))->log(
            (int) $session->get('lstr_admin_id'),
            'affiliate.bulk_email',
            "Sent bulk email to {$scopeLabel}: {$sent} sent" . ($failed > 0 ? ", {$failed} failed" : ''),
            $request->ip(),
        );

        if ($sent === 0) {
            $session->set('lstr_flash_error', "Bulk email failed: all {$failed} send(s) could not be delivered.");
        } else {
            $session->set(
                'lstr_flash_success',
                "Bulk email sent: {$sent} delivered" . ($failed > 0 ? ", {$failed} failed" : '') . '.'
            );
        }
        return Response::redirect($baseUrl . '/admin/affiliates');
    }

    // -----------------------------------------------------------------------
    // Private helpers
    // -----------------------------------------------------------------------

    /**
     * Resolves the redirect URL after a single-row status change. Always
     * lands on the pending tab when more pending applications remain.
     */
    private function redirectAfterStatusChange(Request $request, string $baseUrl, string $defaultTab): string
    {
        $returnTo = trim((string) $request->post('_return', ''));
        if ($returnTo !== '' && str_starts_with($returnTo, $baseUrl . '/admin/affiliates')) {
            return $returnTo;
        }

        $tab = (new AffiliateRepo($this->app->getDatabase()))->getPendingCount() > 0 ? 'pending' : 'all';
        return $baseUrl . '/admin/affiliates?tab=' . $tab;
    }

    // -----------------------------------------------------------------------
    // Private: send affiliate email
    // -----------------------------------------------------------------------

    /**
     * Sends a transactional affiliate email of the given type (approved,
     * rejected, applied) using the stored or default template. Returns true
     * if the email was dispatched successfully.
     */
    private function sendAffiliateEmail(object $affiliate, string $type): bool
    {
        if ($affiliate->email === null || $affiliate->email === '') {
            return false;
        }

        $db     = $this->app->getDatabase();
        $config = $this->app->getConfig();

        if ($affiliate->listingId !== null) {
            $listingRepo = new ListingRepo($db, $config);
            $listing     = $listingRepo->getById($affiliate->listingId);
            if ($listing === null) {
                return false;
            }
            $listingName = $listing->title;
            $listingUrl  = $listing->url;
        } else {
            $listingName = (string) $config->get('site_name', '');
            $listingUrl  = (string) $config->get('site_url', '');
        }

        $emailRepo = new EmailTemplateRepo($db);
        $tokens    = EmailTemplateRepo::buildAffiliateTokens(
            listingName:    $listingName,
            listingUrl:     $listingUrl,
            siteName:       (string) $config->get('site_name', ''),
            ownerName:      (string) $config->get('owner_name', ''),
            ownerEmail:     (string) $config->get('owner_email', ''),
            affName:        $affiliate->name,
            affUrl:         $affiliate->url,
            affEmail:       $affiliate->email,
            affMessage:     $affiliate->applicationMessage ?? '',
            listingSubject: $listing->subject ?? '',
            listingType:    $affiliate->listingId !== null
                ? (new ListingSettingsRepo($db))->getListingType($affiliate->listingId)
                : 'listing',
        );

        [$subject, $body] = $emailRepo->resolveForListing($affiliate->listingId, $type, $tokens);

        if ($subject === '') {
            return false;
        }

        try {
            $mailer = new Mailer($config);
            $mailer->send($affiliate->email, $affiliate->name, $subject, $body);
            return true;
        } catch (\Exception) {
            return false;
        }
    }

    /**
     * @return array<int,array{label:string,url?:string|null}>
     */
    private function affiliateBreadcrumbs(?object $listing, ?string $affiliateName = null): array
    {
        if ($listing === null) {
            $crumbs = [
                $this->crumb('Affiliates', $this->adminUrl('/admin/affiliates')),
            ];
        } else {
            $crumbs = [
                $this->crumb($listing->subject, $this->adminUrl('/admin/listings/' . $listing->id . '/edit')),
                $this->crumb('Affiliates', $this->adminUrl('/admin/affiliates?fanlisting_id=' . $listing->id)),
            ];
        }

        if ($affiliateName !== null && trim($affiliateName) !== '') {
            $crumbs[] = $this->crumb($affiliateName);
        }

        return $crumbs;
    }

}
