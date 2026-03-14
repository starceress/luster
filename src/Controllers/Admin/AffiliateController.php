<?php

declare(strict_types=1);

namespace Luster\Controllers\Admin;

use Luster\Core\Application;
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
 *   GET  /admin/listings/{fid}/affiliates/add     — add form (fanlisting context)
 *   POST /admin/listings/{fid}/affiliates/add     — insert (fanlisting context)
 *   GET  /admin/affiliates/{id}/edit                 — edit form
 *   POST /admin/affiliates/{id}/edit                 — update
 *   POST /admin/affiliates/{id}/toggle               — toggle active/inactive
 *   POST /admin/affiliates/{id}/delete               — delete
 *
 * Listing context: when accessed via /admin/listings/{fid}/affiliates/add,
 * or when from_listing is passed as a query/POST param, save/toggle/delete
 * redirect back to /admin/listings/{fid} instead of /admin/affiliates.
 */
final class AffiliateController
{
    public function __construct(private readonly Application $app) {}

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
            $fanMap[$fl->id] = $fl->name;
        }

        $filterFid = trim((string) $request->query('fanlisting_id', 'collective'));
        $search    = trim((string) $request->query('search', ''));
        $page      = max(1, (int) $request->query('page', 1));
        $perPage   = 10;

        if ($search !== '') {
            $fid    = $filterFid === 'collective' ? null : ($filterFid === '' ? -1 : (int) $filterFid);
            $result = $affiliateRepo->search($search, $fid, $perPage, $page);
        } elseif ($filterFid === 'collective') {
            $result = $affiliateRepo->getCollective($perPage, $page);
        } elseif ($filterFid !== '') {
            $result = $affiliateRepo->getByListing((int) $filterFid, $perPage, $page);
        } else {
            $result = $affiliateRepo->getAll($perPage, $page);
        }

        $affiliates = $result['items'];
        $totalPages = $result['pages'];
        $total      = $result['total'];

        $session = $this->app->getSession();
        $success = (string) $session->get('lstr_flash_success', '');
        $error   = (string) $session->get('lstr_flash_error', '');
        $session->remove('lstr_flash_success');
        $session->remove('lstr_flash_error');

        $siteName = (string) ($this->app->getConfig()->get('app_name', 'Collective'));

        return $this->layout('admin/affiliates/index.php', [
            'affiliates'  => $affiliates,
            'fan_map'     => $fanMap,
            'listings' => $listings,
            'filter_fid'  => $filterFid,
            'search'      => $search,
            'page'        => $page,
            'per_page'    => $perPage,
            'total_pages' => $totalPages,
            'total'       => $total,
            'site_name'   => $siteName,
            'success'     => $success,
            'error'       => $error,
        ], 'Affiliates', 'affiliates');
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

        if ($request->isPost()) {
            $listingId = (int) $request->post('listing_id', 0);
            $name         = trim((string) $request->post('name', ''));
            $url          = trim((string) $request->post('url', ''));
            $email        = trim((string) $request->post('email', ''));
            $sortOrder    = (int) $request->post('sort_order', 0);
            // Re-read context from hidden field in case it was a listing-routed form
            $postContextFid = (int) $request->post('context_fid', $contextFid);

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

            $siteName = (string) ($this->app->getConfig()->get('app_name', 'Collective'));

            if ($v->fails()) {
                return $this->layout('admin/affiliates/form.php', [
                    'listings'  => $listings,
                    'affiliate'    => null,
                    'error'        => implode(' ', $v->allErrors()),
                    'old'          => $request->postAll(),
                    'context_fid'  => $postContextFid,
                    'site_name'    => $siteName,
                ], 'Add Affiliate', 'affiliates');
            }

            $affiliateRepo->insert([
                'fanlisting_id' => $listingId > 0 ? $listingId : null,
                'name'          => $name,
                'url'           => $url,
                'email'         => $email !== '' ? $email : null,
                'image'         => $image !== '' ? $image : null,
                'sort_order'    => $sortOrder,
            ]);

            if ($postContextFid > 0) {
                (new ListingSettingsRepo($db))->touchLastUpdated($postContextFid);
            }

            $session->set('lstr_flash_success', 'Affiliate added.');

            $redirect = $postContextFid > 0
                ? $baseUrl . '/admin/listings/' . $postContextFid
                : $baseUrl . '/admin/affiliates';

            return Response::redirect($redirect);
        }

        $siteName = (string) ($this->app->getConfig()->get('app_name', 'Collective'));

        return $this->layout('admin/affiliates/form.php', [
            'listings' => $listings,
            'affiliate'   => null,
            'error'       => '',
            'old'         => [],
            'context_fid' => $contextFid,
            'site_name'   => $siteName,
        ], 'Add Affiliate', 'affiliates');
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

            $siteName = (string) ($this->app->getConfig()->get('app_name', 'Collective'));

            if ($v->fails()) {
                return $this->layout('admin/affiliates/form.php', [
                    'listings' => $listings,
                    'affiliate'   => $affiliate,
                    'error'       => implode(' ', $v->allErrors()),
                    'old'         => $request->postAll(),
                    'context_fid' => $fromFl,
                    'site_name'   => $siteName,
                ], 'Edit Affiliate', 'affiliates');
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

        $siteName = (string) ($this->app->getConfig()->get('app_name', 'Collective'));

        return $this->layout('admin/affiliates/form.php', [
            'listings' => $listings,
            'affiliate'   => $affiliate,
            'error'       => '',
            'old'         => [],
            'context_fid' => $fromFl,
            'site_name'   => $siteName,
        ], 'Edit Affiliate', 'affiliates');
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

        if ($affiliateRepo->getById($id) === null) {
            return Response::notFound('Affiliate not found.');
        }

        $affiliateRepo->delete($id);
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
    // Shared render helper
    // -----------------------------------------------------------------------

    private function layout(string $template, array $data, string $pageTitle, string $section): Response
    {
        $view      = $this->app->getView();
        $config    = $this->app->getConfig();
        $session   = $this->app->getSession();
        $baseUrl   = $this->app->getBaseUrl();
        $csrfToken = $this->app->getCSRF()->token();

        $inner = $view->render($template, array_merge($data, [
            'base_url'   => $baseUrl,
            'csrf_token' => $csrfToken,
        ]));

        return Response::html($view->render('admin/layout.php', [
            'page_title'      => $pageTitle,
            'app_name'        => (string) $config->get('app_name', 'Luster'),
            'admin_username'  => (string) $session->get('lstr_admin_username', ''),
            'csrf_token'      => $csrfToken,
            'current_section' => $section,
            'base_url'        => $baseUrl,
            'content'         => $inner,
        ]));
    }
}
