<?php

declare(strict_types=1);

namespace Luster\Controllers\Admin;

use Luster\Core\Request;
use Luster\Core\Response;
use Luster\Core\Validator;
use Luster\Repositories\AffiliateRepo;
use Luster\Repositories\ListingRepo;
use Luster\Repositories\ListingSettingsRepo;

/**
 * Manages affiliates across all listings.
 *
 * Routes:
 *   GET  /admin/affiliates                           — list all affiliates
 *   GET  /admin/affiliates/add                       — add form (global context)
 *   POST /admin/affiliates/add                       — insert (global context)
 *   POST /admin/listings/{fid}/affiliates/add        — insert (listing context; form is inline in the listing edit tab)
 *   GET  /admin/affiliates/{id}/edit                 — edit form
 *   POST /admin/affiliates/{id}/edit                 — update
 *   POST /admin/affiliates/{id}/toggle               — toggle active/inactive
 *   POST /admin/affiliates/{id}/delete               — delete
 *
 * Listing context: when accessed via /admin/listings/{fid}/affiliates/add,
 * or when from_listing is passed as a query/POST param, save/toggle/delete
 * redirect back to /admin/listings/{fid} instead of /admin/affiliates.
 */
final class AffiliateController extends AdminController
{

    // -----------------------------------------------------------------------
    // GET /admin/affiliates
    // -----------------------------------------------------------------------

    public function index(Request $request, array $params): Response
    {
        $db     = $this->app->getDatabase();
        $config = $this->app->getConfig();

        $affiliateRepo  = new AffiliateRepo($db);
        $listingRepo = new ListingRepo($db, $config);

        $listings = $listingRepo->getAll();

        // Build listing lookup map for display
        $fanMap = [];
        foreach ($listings as $fl) {
            $fanMap[$fl->id] = $fl->subject;
        }

        $filterFid   = trim((string) $request->query('fanlisting_id', 'collective'));
        $search      = trim((string) $request->query('search', ''));
        $page        = max(1, (int) $request->query('page', 1));
        $perPage     = 10;
        $reorderMode = $request->query('reorder', '') === '1' && $search === '';

        if ($reorderMode) {
            // Reorder mode: load every affiliate for the current scope in
            // sort_order ASC, no pagination, and skip the repack step.
            $orderBy = 'sort_order ASC, name ASC';
            if ($filterFid === 'collective') {
                $result = $affiliateRepo->getCollective(PHP_INT_MAX, 1, $orderBy);
            } elseif ($filterFid !== '') {
                $result = $affiliateRepo->getByListing((int) $filterFid, PHP_INT_MAX, 1, $orderBy);
            } else {
                $result = $affiliateRepo->getAll(PHP_INT_MAX, 1, $orderBy);
            }
            $sortParam  = 'sort_order';
            $sortDir    = 'ASC';
            $totalPages = 1;
        } else {
            // Normal mode: repack gaps, then paginate/sort as requested.
            if ($search === '') {
                if ($filterFid === 'collective') {
                    $affiliateRepo->repackSortOrder(null);
                } elseif ($filterFid !== '') {
                    $affiliateRepo->repackSortOrder((int) $filterFid);
                }
            }

            $allowedCols = ['sort_order' => 'sort_order', 'name' => 'name', 'email' => 'email', 'status' => 'active'];
            $sortParam   = (string) $request->query('sort', 'sort_order');
            $dirParam    = strtolower(trim((string) $request->query('dir', 'asc')));
            $sortCol     = $allowedCols[$sortParam] ?? 'sort_order';
            $sortDir     = $dirParam === 'desc' ? 'DESC' : 'ASC';
            $orderBy     = $sortCol . ' ' . $sortDir . ($sortCol !== 'name' ? ', name ASC' : '');

            if ($search !== '') {
                $fid    = $filterFid === 'collective' ? null : ($filterFid === '' ? -1 : (int) $filterFid);
                $result = $affiliateRepo->search($search, $fid, $perPage, $page, $orderBy);
            } elseif ($filterFid === 'collective') {
                $result = $affiliateRepo->getCollective($perPage, $page, $orderBy);
            } elseif ($filterFid !== '') {
                $result = $affiliateRepo->getByListing((int) $filterFid, $perPage, $page, $orderBy);
            } else {
                $result = $affiliateRepo->getAll($perPage, $page, $orderBy);
            }
            $totalPages = $result['pages'];
        }

        $affiliates = $result['items'];
        $total      = $result['total'];

        $session = $this->app->getSession();
        $success = (string) $session->get('lstr_flash_success', '');
        $error   = (string) $session->get('lstr_flash_error', '');
        $session->remove('lstr_flash_success');
        $session->remove('lstr_flash_error');

        $siteName = (string) ($this->app->getConfig()->get('site_name', 'Collective'));

        $baseUrl = $this->app->getBaseUrl();

        $indexBreadcrumbs = [];
        if ($filterFid !== '' && $filterFid !== 'collective' && isset($fanMap[(int) $filterFid])) {
            $indexBreadcrumbs = [
                $this->crumb('Affiliates', $this->adminUrl('/admin/affiliates')),
                $this->crumb($fanMap[(int) $filterFid]),
            ];
        }

        return $this->adminLayout('admin/affiliates/index.php', 'Affiliates', 'affiliates', [
            'affiliates'   => $affiliates,
            'fan_map'      => $fanMap,
            'listings'     => $listings,
            'filter_fid'   => $filterFid,
            'search'       => $search,
            'page'         => $page,
            'per_page'     => $perPage,
            'total_pages'  => $totalPages,
            'total'        => $total,
            'site_name'    => $siteName,
            'sort'         => $sortParam,
            'dir'          => strtolower($sortDir),
            'reorder_url'  => $baseUrl . '/admin/affiliates/reorder',
            'reorder_mode' => $reorderMode,
            'success'      => $success,
            'error'        => $error,
        ], '', $indexBreadcrumbs);
    }

    // -----------------------------------------------------------------------
    // GET + POST /admin/affiliates/add
    // GET + POST /admin/listings/{fid}/affiliates/add
    // -----------------------------------------------------------------------

    public function add(Request $request, array $params): Response
    {
        $db     = $this->app->getDatabase();
        $config = $this->app->getConfig();

        $affiliateRepo  = new AffiliateRepo($db);
        $listingRepo = new ListingRepo($db, $config);
        $listings    = $listingRepo->getAll();

        $session = $this->app->getSession();
        $baseUrl = $this->app->getBaseUrl();

        // Listing context — comes from route {fid} or query param
        $contextFid = (int) ($params['fid'] ?? $request->query('context_fid', 0));

        $contextListing = $contextFid > 0
            ? (static function (array $ls, int $fid): ?object { foreach ($ls as $l) { if ((int) $l->id === $fid) return $l; } return null; })($listings, $contextFid)
            : null;
        $addBreadcrumbs = $this->affiliateBreadcrumbs($contextListing);

        if ($request->isPost()) {
            // When routed through /listings/{fid}/affiliates/add the listing ID
            // comes from the route param; for global add it comes from the form field.
            $fanlistingId   = $contextFid > 0 ? $contextFid : (int) $request->post('fanlisting_id', 0);
            $name           = trim((string) $request->post('name', ''));
            $url            = trim((string) $request->post('url', ''));
            $email          = trim((string) $request->post('email', ''));
            $sortOrder      = (int) $request->post('sort_order', 0);

            // Image upload
            $image     = '';
            $imageFile = $request->file('image') ?? [];
            if (!empty($imageFile['tmp_name'])) {
                $finfo   = new \finfo(FILEINFO_MIME_TYPE);
                $mime    = $finfo->file($imageFile['tmp_name']);
                $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp'];
                if (!isset($allowed[$mime]) || $imageFile['size'] > 2097152) {
                    throw new \RuntimeException('Invalid image: must be JPEG, PNG, GIF, or WebP and under 2 MB.');
                }
                $dir = dirname(__DIR__, 3) . '/public/uploads/affiliates/';
                if (!is_dir($dir)) {
                    mkdir($dir, 0755, true);
                    file_put_contents($dir . '.htaccess', "deny from all\n<FilesMatch \"\.(jpg|jpeg|png|gif|webp)$\">\nAllow from all\n</FilesMatch>");
                }
                $image = uniqid('af_', true) . '.' . $allowed[$mime];
                move_uploaded_file($imageFile['tmp_name'], $dir . $image);
            }

            $v = new Validator(['name' => $name, 'url' => $url]);
            $v->field('name')->required()->maxLength(255);
            $v->field('url')->required()->url()->maxLength(500);

            $siteName = (string) ($this->app->getConfig()->get('site_name', 'Collective'));

            if ($v->fails()) {
                if ($contextFid > 0) {
                    // Inline form in listing edit tab — flash and redirect back
                    $session->set('lstr_flash_error', implode(' ', $v->allErrors()));
                    return Response::redirect($baseUrl . '/admin/listings/' . $contextFid . '?tab=affiliates');
                }
                return $this->adminLayout('admin/affiliates/add.php', 'Add Affiliate', 'affiliates', [
                    'listings'     => $listings,
                    'affiliate'    => null,
                    'error'        => implode(' ', $v->allErrors()),
                    'field_errors' => $v->errors(),
                    'old'          => $request->postAll(),
                    'context_fid'  => 0,
                    'site_name'    => $siteName,
                ], '', $addBreadcrumbs);
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

            $redirect = $contextFid > 0
                ? $baseUrl . '/admin/listings/' . $contextFid . '?tab=affiliates'
                : $baseUrl . '/admin/affiliates';

            return Response::redirect($redirect);
        }

        $siteName = (string) ($this->app->getConfig()->get('site_name', 'Collective'));

        // Prefill sort order: next available in the context scope (collective when no context)
        $nextSortOrder = $affiliateRepo->getNextSortOrder($contextFid > 0 ? $contextFid : null);

        return $this->adminLayout('admin/affiliates/add.php', 'Add Affiliate', 'affiliates', [
            'listings'       => $listings,
            'affiliate'      => null,
            'error'          => '',
            'old'            => [],
            'context_fid'    => $contextFid,
            'site_name'      => $siteName,
            'next_sort_order' => $nextSortOrder,
        ], '', $addBreadcrumbs);
    }

    // -----------------------------------------------------------------------
    // GET + POST /admin/affiliates/{id}/edit
    // -----------------------------------------------------------------------

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
            $url       = trim((string) $request->post('url', ''));
            $email     = trim((string) $request->post('email', ''));
            $sortOrder = (int) $request->post('sort_order', 0);
            $fromFl    = (int) $request->post('from_listing', 0);

            // Image upload — start from current stored filename
            $image     = $affiliate->image ?? '';
            $imageFile = $request->file('image') ?? [];
            if (!empty($imageFile['tmp_name'])) {
                $finfo   = new \finfo(FILEINFO_MIME_TYPE);
                $mime    = $finfo->file($imageFile['tmp_name']);
                $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp'];
                if (!isset($allowed[$mime]) || $imageFile['size'] > 2097152) {
                    throw new \RuntimeException('Invalid image: must be JPEG, PNG, GIF, or WebP and under 2 MB.');
                }
                $dir = dirname(__DIR__, 3) . '/public/uploads/affiliates/';
                if (!is_dir($dir)) {
                    mkdir($dir, 0755, true);
                    file_put_contents($dir . '.htaccess', "deny from all\n<FilesMatch \"\.(jpg|jpeg|png|gif|webp)$\">\nAllow from all\n</FilesMatch>");
                }
                if ($image !== '' && file_exists($dir . $image)) {
                    unlink($dir . $image);
                }
                $image = uniqid('af_', true) . '.' . $allowed[$mime];
                move_uploaded_file($imageFile['tmp_name'], $dir . $image);
            }
            if ($request->post('delete_image') && $image !== '') {
                $dir = dirname(__DIR__, 3) . '/public/uploads/affiliates/';
                if (file_exists($dir . $image)) {
                    unlink($dir . $image);
                }
                $image = '';
            }

            $v = new Validator(['name' => $name, 'url' => $url]);
            $v->field('name')->required()->maxLength(255);
            $v->field('url')->required()->url()->maxLength(500);

            $siteName = (string) ($this->app->getConfig()->get('site_name', 'Collective'));

            if ($v->fails()) {
                return $this->adminLayout('admin/affiliates/add.php', 'Edit Affiliate', 'affiliates', [
                    'listings'     => $listings,
                    'affiliate'    => $affiliate,
                    'error'        => implode(' ', $v->allErrors()),
                    'field_errors' => $v->errors(),
                    'old'          => $request->postAll(),
                    'context_fid'  => $fromFl,
                    'site_name'    => $siteName,
                ], '', $editBreadcrumbs);
            }

            $affiliateRepo->update($id, [
                'name'       => $name,
                'url'        => $url,
                'email'      => $email !== '' ? $email : null,
                'image'      => $image !== '' ? $image : null,
                'sort_order' => $sortOrder,
            ]);

            if ($fromFl > 0) {
                (new ListingSettingsRepo($db))->touchLastUpdated($fromFl);
            }

            $session->set('lstr_flash_success', 'Affiliate updated.');

            $redirect = $fromFl > 0
                ? $baseUrl . '/admin/listings/' . $fromFl
                : $baseUrl . '/admin/affiliates';

            return Response::redirect($redirect);
        }

        // GET: read context from query param
        $fromFl = (int) $request->query('from_listing', 0);

        $siteName = (string) ($this->app->getConfig()->get('site_name', 'Collective'));

        return $this->adminLayout('admin/affiliates/add.php', 'Edit Affiliate', 'affiliates', [
            'listings' => $listings,
            'affiliate'   => $affiliate,
            'error'       => '',
            'old'         => [],
            'context_fid' => $fromFl,
            'site_name'   => $siteName,
        ], '', $editBreadcrumbs);
    }

    // -----------------------------------------------------------------------
    // POST /admin/affiliates/reorder  (AJAX)
    // -----------------------------------------------------------------------

    public function reorder(Request $request, array $params): Response
    {
        $db   = $this->app->getDatabase();
        $repo = new AffiliateRepo($db);

        $raw = (array) $request->post('ids', []);
        $ids = array_values(array_filter(array_map('intval', $raw), fn(int $id) => $id > 0));

        if (empty($ids)) {
            return Response::json(['ok' => false, 'error' => 'No IDs provided.']);
        }

        $repo->setSortOrders($ids);

        return Response::json(['ok' => true]);
    }

    // -----------------------------------------------------------------------
    // POST /admin/affiliates/{id}/toggle
    // -----------------------------------------------------------------------

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
