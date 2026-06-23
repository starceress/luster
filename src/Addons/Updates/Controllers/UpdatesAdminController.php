<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Admin controller for the News Feed add-on. Provides the listings overview
 with latest-post dates, per-fanlisting and collective entry lists, the
 add/edit/delete entry forms, bulk delete, and the News Feed settings page.
 All state-changing actions are CSRF-protected and logged to the activity log.
*/

declare(strict_types=1);

namespace Luster\Addons\Updates\Controllers;

use Luster\Addons\Updates\Update;
use Luster\Addons\Updates\UpdateRepo;
use Luster\Controllers\Admin\AdminController;
use Luster\Core\Request;
use Luster\Core\Response;
use Luster\Repositories\ActivityRepo;
use Luster\Repositories\ListingRepo;
use Luster\Repositories\SiteSettingsRepo;

/**
 * Admin controller for the Updates add-on.
 *
 * All state-changing actions require CSRF validation (handled globally by
 * CsrfMiddleware) and log to the activity log.
 */
final class UpdatesAdminController extends AdminController
{

    // -----------------------------------------------------------------------
    // GET /admin/updates  — overview index
    // -----------------------------------------------------------------------

    public function index(Request $request, array $params): Response
    {
        $db      = $this->app->getDatabase();
        $config  = $this->app->getConfig();
        $session = $this->app->getSession();

        $listingRepo = new ListingRepo($db, $config);
        $updateRepo  = new UpdateRepo($db);

        $listings      = $listingRepo->getAll();
        $latestPerFl   = $updateRepo->getLatestPerFanlisting();
        $latestColl    = $updateRepo->getLatestCollective();
        $collectiveCnt = $updateRepo->countCollective();

        $search = trim((string) $request->query('search', ''));
        $sort   = trim((string) $request->query('sort', 'id'));
        $dir    = trim((string) $request->query('dir',  'asc'));
        if (!in_array($sort, ['id', 'subject', 'latest'], true)) { $sort = 'id'; }
        if (!in_array($dir,  ['asc', 'desc'], true))              { $dir  = 'asc'; }

        if ($search !== '') {
            $needle   = mb_strtolower($search);
            $listings = array_values(array_filter(
                $listings,
                static fn($l) => str_contains(mb_strtolower($l->subject), $needle),
            ));
        }

        usort($listings, static function ($a, $b) use ($sort, $dir, $latestPerFl): int {
            if ($sort === 'subject') {
                $cmp = strcasecmp($a->subject, $b->subject);
                return $dir === 'asc' ? $cmp : -$cmp;
            }
            if ($sort === 'latest') {
                $aDate = $latestPerFl[$a->id]['latest']?->postedAt ?? '';
                $bDate = $latestPerFl[$b->id]['latest']?->postedAt ?? '';
                $cmp   = strcmp($aDate, $bDate);
                return $dir === 'asc' ? $cmp : -$cmp;
            }
            $diff = $a->id - $b->id;
            return $dir === 'asc' ? $diff : -$diff;
        });

        $totalListings = count($listings);
        $perPage       = max(5, min(25, (int) ((new SiteSettingsRepo($db))->get('updates_per_page', '10') ?? '10')));
        $pages         = $totalListings > 0 ? (int) ceil($totalListings / $perPage) : 1;
        $page          = min(max(1, (int) $request->query('page', 1)), $pages);
        $listings      = array_slice($listings, ($page - 1) * $perPage, $perPage);

        $success = (string) $session->get('lstr_flash_success', '');
        $error   = (string) $session->get('lstr_flash_error',   '');
        $session->remove('lstr_flash_success');
        $session->remove('lstr_flash_error');

        return $this->adminLayout('admin/addons/updates/index.php', 'News Feed', 'updates', [
            'listings'        => $listings,
            'latest_per_fl'   => $latestPerFl,
            'latest_coll'     => $latestColl,
            'collective_cnt'  => $collectiveCnt,
            'site_name'       => (string) $config->get('site_name', 'Luster'),
            'search'          => $search,
            'sort'            => $sort,
            'dir'             => $dir,
            'page'            => $page,
            'pages'           => $pages,
            'per_page'        => $perPage,
            'total_listings'  => $totalListings,
            'success'         => $success,
            'error'           => $error,
        ]);
    }

    // -----------------------------------------------------------------------
    // GET|POST /admin/updates/settings
    // -----------------------------------------------------------------------

    public function settings(Request $request, array $params): Response
    {
        $db           = $this->app->getDatabase();
        $session      = $this->app->getSession();
        $settingsRepo = new SiteSettingsRepo($db);

        $success = (string) $session->get('lstr_flash_success', '');
        $error   = (string) $session->get('lstr_flash_error',   '');
        $session->remove('lstr_flash_success');
        $session->remove('lstr_flash_error');

        if ($request->isPost()) {
            if (!$this->validCsrf($request)) {
                $session->set('lstr_flash_error', 'Invalid security token.');
                return Response::redirect($this->app->getBaseUrl() . '/admin/updates/settings');
            }

            $perPage = max(5, min(25, (int) $request->post('updates_per_page', 10)));
            $settingsRepo->set('updates_per_page', (string) $perPage);

            $session->set('lstr_flash_success', 'News feed display settings saved.');
            return Response::redirect($this->app->getBaseUrl() . '/admin/updates/settings');
        }

        $perPage = max(1, min(100, (int) ($settingsRepo->get('updates_per_page', '10') ?? '10')));

        return $this->adminLayout('admin/addons/updates/settings.php', 'News Feed Settings', 'updates', [
            'updates_per_page' => $perPage,
            'success'          => $success,
            'error'            => $error,
        ], '', [
            $this->crumb('News Feed', $this->adminUrl('/admin/updates')),
        ]);
    }

    // -----------------------------------------------------------------------
    // GET /admin/updates/collective  — collective updates list
    // -----------------------------------------------------------------------

    public function collectiveList(Request $request, array $params): Response
    {
        $db      = $this->app->getDatabase();
        $session = $this->app->getSession();
        $config  = $this->app->getConfig();

        $perPage         = max(5, min(25, (int) ((new SiteSettingsRepo($db))->get('updates_per_page', '10') ?? '10')));
        $page            = max(1, (int) ($request->query('page', 1)));
        $search          = trim((string) $request->query('search', ''));
        $filterCategory  = trim((string) $request->query('category', ''));

        $updateRepo = new UpdateRepo($db);
        $updates    = $updateRepo->getFiltered(null, $search, $filterCategory, $page, $perPage);
        $total      = $updateRepo->countFiltered(null, $search, $filterCategory);
        $pages      = max(1, (int) ceil($total / $perPage));

        $siteName = (string) $config->get('site_name', 'Luster');

        $success = (string) $session->get('lstr_flash_success', '');
        $error   = (string) $session->get('lstr_flash_error',   '');
        $session->remove('lstr_flash_success');
        $session->remove('lstr_flash_error');

        return $this->adminLayout('admin/addons/updates/list.php', 'News Feed', 'updates', [
            'updates'          => $updates,
            'total'            => $total,
            'page'             => $page,
            'pages'            => $pages,
            'per_page'         => $perPage,
            'is_collective'    => true,
            'listing'          => null,
            'ctx_name'         => $siteName,
            'search'           => $search,
            'filter_category'  => $filterCategory,
            'known_categories' => $updateRepo->getKnownCategories(),
            'success'          => $success,
            'error'            => $error,
        ], '', [
            $this->crumb('News Feed', $this->adminUrl('/admin/updates')),
            $this->crumb($siteName),
        ]);
    }

    // -----------------------------------------------------------------------
    // GET|POST /admin/updates/collective/add
    // -----------------------------------------------------------------------

    public function collectiveAddForm(Request $request, array $params): Response
    {
        return $this->renderAddForm($request, null, 'Collective');
    }

    public function collectiveAdd(Request $request, array $params): Response
    {
        return $this->handleAdd($request, null, 'Collective');
    }

    // -----------------------------------------------------------------------
    // GET /admin/updates/{fid}  — per-fanlisting list
    // -----------------------------------------------------------------------

    public function list(Request $request, array $params): Response
    {
        $fid = (int) ($params['fid'] ?? 0);

        $db      = $this->app->getDatabase();
        $config  = $this->app->getConfig();
        $session = $this->app->getSession();

        $listingRepo = new ListingRepo($db, $config);
        $listing     = $listingRepo->getById($fid);

        if ($listing === null) {
            return Response::notFound('Listing not found.');
        }

        $perPage         = max(5, min(25, (int) ((new SiteSettingsRepo($db))->get('updates_per_page', '10') ?? '10')));
        $page            = max(1, (int) ($request->query('page', 1)));
        $search          = trim((string) $request->query('search', ''));
        $filterCategory  = trim((string) $request->query('category', ''));

        $updateRepo = new UpdateRepo($db);
        $updates    = $updateRepo->getFiltered($fid, $search, $filterCategory, $page, $perPage);
        $total      = $updateRepo->countFiltered($fid, $search, $filterCategory);
        $pages      = max(1, (int) ceil($total / $perPage));

        $success = (string) $session->get('lstr_flash_success', '');
        $error   = (string) $session->get('lstr_flash_error',   '');
        $session->remove('lstr_flash_success');
        $session->remove('lstr_flash_error');

        return $this->adminLayout('admin/addons/updates/list.php', 'News Feed', 'updates', [
            'updates'          => $updates,
            'total'            => $total,
            'page'             => $page,
            'pages'            => $pages,
            'per_page'         => $perPage,
            'is_collective'    => false,
            'listing'          => $listing,
            'ctx_name'         => $listing->subject,
            'search'           => $search,
            'filter_category'  => $filterCategory,
            'known_categories' => $updateRepo->getKnownCategories(),
            'success'          => $success,
            'error'            => $error,
        ], '', [
            $this->crumb('News Feed', $this->adminUrl('/admin/updates')),
            $this->crumb($listing->subject),
        ]);
    }

    // -----------------------------------------------------------------------
    // GET|POST /admin/updates/{fid}/add
    // -----------------------------------------------------------------------

    public function addForm(Request $request, array $params): Response
    {
        $fid     = (int) ($params['fid'] ?? 0);
        $db      = $this->app->getDatabase();
        $config  = $this->app->getConfig();
        $listing = (new ListingRepo($db, $config))->getById($fid);

        if ($listing === null) {
            return Response::notFound('Listing not found.');
        }

        return $this->renderAddForm($request, $fid, $listing->subject);
    }

    public function add(Request $request, array $params): Response
    {
        $fid     = (int) ($params['fid'] ?? 0);
        $db      = $this->app->getDatabase();
        $config  = $this->app->getConfig();
        $listing = (new ListingRepo($db, $config))->getById($fid);

        if ($listing === null) {
            return Response::notFound('Listing not found.');
        }

        return $this->handleAdd($request, $fid, $listing->subject);
    }

    // -----------------------------------------------------------------------
    // GET|POST /admin/updates/entry/{id}/edit
    // -----------------------------------------------------------------------

    public function editForm(Request $request, array $params): Response
    {
        $id = (int) ($params['id'] ?? 0);

        $db      = $this->app->getDatabase();
        $config  = $this->app->getConfig();
        $session = $this->app->getSession();

        $updateRepo = new UpdateRepo($db);
        $update     = $updateRepo->getById($id);

        if ($update === null) {
            return Response::notFound('Update not found.');
        }

        [$contextLabel, $backUrl, $breadcrumbs] = $this->subtitleAndBack($update, $config, $db);

        $error = (string) $session->get('lstr_flash_error', '');
        $session->remove('lstr_flash_error');

        return $this->adminLayout('admin/addons/updates/add.php', 'Edit Update', 'updates', [
            'update'           => $update,
            'fanlisting_id'    => $update->fanlistingId,
            'known_categories' => $updateRepo->getKnownCategories(),
            'back_url'         => $backUrl,
            'ctx_label'        => $contextLabel,
            'back_label'       => $contextLabel,
            'is_edit'          => true,
            'error'            => $error,
        ], '', $breadcrumbs);
    }

    public function edit(Request $request, array $params): Response
    {
        $id = (int) ($params['id'] ?? 0);

        $db      = $this->app->getDatabase();
        $config  = $this->app->getConfig();
        $session = $this->app->getSession();
        $baseUrl = $this->app->getBaseUrl();

        $updateRepo = new UpdateRepo($db);
        $update     = $updateRepo->getById($id);

        if ($update === null) {
            return Response::notFound('Update not found.');
        }

        [, $backUrl] = $this->subtitleAndBack($update, $config, $db);

        $title    = trim((string) $request->post('title',     ''));
        $body     = trim((string) $request->post('body',      ''));
        $category = trim((string) $request->post('category',  ''));
        $postedAt = trim((string) $request->post('posted_at', ''));

        $errors = $this->validateFields($title, $body, $postedAt);

        if (!empty($errors)) {
            $session->set('lstr_flash_error', implode(' ', $errors));
            return Response::redirect($baseUrl . '/admin/updates/entry/' . $id . '/edit');
        }

        $updateRepo->update($id, [
            'title'     => $title,
            'body'      => $body,
            'category'  => $category,
            'posted_at' => $postedAt,
        ]);

        (new ActivityRepo($db))->log(
            (int) $session->get('lstr_admin_id'),
            'update_edited',
            'Edited update "' . $title . '"',
            $request->ip(),
        );

        $session->set('lstr_flash_success', 'Update saved.');
        return Response::redirect($backUrl);
    }

    // -----------------------------------------------------------------------
    // POST /admin/updates/entry/{id}/delete
    // -----------------------------------------------------------------------

    public function delete(Request $request, array $params): Response
    {
        $id = (int) ($params['id'] ?? 0);

        $db      = $this->app->getDatabase();
        $config  = $this->app->getConfig();
        $session = $this->app->getSession();
        $baseUrl = $this->app->getBaseUrl();

        $updateRepo = new UpdateRepo($db);
        $update     = $updateRepo->getById($id);

        if ($update === null) {
            return Response::notFound('Update not found.');
        }

        [, $backUrl] = $this->subtitleAndBack($update, $config, $db);

        $updateRepo->delete($id);

        $context = $update->fanlistingId === null
            ? 'Collective'
            : 'listing #' . $update->fanlistingId;

        (new ActivityRepo($db))->log(
            (int) $session->get('lstr_admin_id'),
            'update_deleted',
            'Deleted update "' . $update->title . '" for ' . $context,
            $request->ip(),
        );

        $session->set('lstr_flash_success', 'Update deleted.');
        return Response::redirect($backUrl);
    }

    // -----------------------------------------------------------------------
    // POST /admin/updates/bulk  — bulk delete across any page/listing
    // -----------------------------------------------------------------------

    public function bulkDelete(Request $request, array $params): Response
    {
        $db      = $this->app->getDatabase();
        $session = $this->app->getSession();
        $baseUrl = $this->app->getBaseUrl();

        $action = (string) $request->post('bulk_action', '');
        $rawIds = $request->post('ids', []);
        $ids    = array_values(array_filter(
            array_map('intval', is_array($rawIds) ? $rawIds : []),
            static fn(int $id) => $id > 0
        ));

        $returnTo = trim((string) $request->post('_return', ''));
        if ($returnTo === '' || !str_starts_with($returnTo, $baseUrl . '/admin/updates')) {
            $returnTo = $baseUrl . '/admin/updates';
        }

        if (empty($ids)) {
            $session->set('lstr_flash_error', 'No updates selected.');
            return Response::redirect($returnTo);
        }

        if ($action !== 'delete') {
            $session->set('lstr_flash_error', 'Unknown bulk action.');
            return Response::redirect($returnTo);
        }

        $updateRepo   = new UpdateRepo($db);
        $activityRepo = new ActivityRepo($db);
        $count        = 0;

        foreach ($ids as $id) {
            if ($updateRepo->getById($id) !== null) {
                $updateRepo->delete($id);
                $count++;
            }
        }

        $activityRepo->log(
            (int) $session->get('lstr_admin_id'),
            'update_bulk_deleted',
            "Bulk deleted {$count} update(s)",
            $request->ip(),
        );

        $session->set('lstr_flash_success', "Deleted {$count} update(s).");
        return Response::redirect($returnTo);
    }

    // -----------------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------------

    /**
     * Shared GET form renderer for add (both collective and per-fanlisting).
     */
    private function renderAddForm(Request $request, ?int $fanlistingId, string $contextLabel): Response
    {
        $db      = $this->app->getDatabase();
        $session = $this->app->getSession();
        $baseUrl = $this->app->getBaseUrl();

        $updateRepo = new UpdateRepo($db);

        $error = (string) $session->get('lstr_flash_error', '');
        $session->remove('lstr_flash_error');

        $backUrl = $fanlistingId !== null
            ? $baseUrl . '/admin/updates/' . $fanlistingId
            : $baseUrl . '/admin/updates/collective';

        return $this->adminLayout('admin/addons/updates/add.php', 'Add Update', 'updates', [
            'update'           => null,
            'fanlisting_id'    => $fanlistingId,
            'known_categories' => $updateRepo->getKnownCategories(),
            'back_url'         => $backUrl,
            'ctx_label'        => $contextLabel,
            'back_label'       => $contextLabel,
            'is_edit'          => false,
            'author'           => (string) $session->get('lstr_admin_username', ''),
            'error'            => $error,
        ], '', [
            $this->crumb('News Feed', $this->adminUrl('/admin/updates')),
            $this->crumb($contextLabel),
        ]);
    }

    /**
     * Shared POST handler for add (both collective and per-fanlisting).
     */
    private function handleAdd(Request $request, ?int $fanlistingId, string $contextLabel): Response
    {
        $db      = $this->app->getDatabase();
        $config  = $this->app->getConfig();
        $session = $this->app->getSession();
        $baseUrl = $this->app->getBaseUrl();

        $title    = trim((string) $request->post('title',     ''));
        $body     = trim((string) $request->post('body',      ''));
        $category = trim((string) $request->post('category',  ''));
        $postedAt = trim((string) $request->post('posted_at', ''));
        $author   = (string) $session->get('lstr_admin_username', '');

        $errors = $this->validateFields($title, $body, $postedAt);

        $addUrl = $fanlistingId !== null
            ? $baseUrl . '/admin/updates/' . $fanlistingId . '/add'
            : $baseUrl . '/admin/updates/collective/add';

        $backUrl = $fanlistingId !== null
            ? $baseUrl . '/admin/updates/' . $fanlistingId
            : $baseUrl . '/admin/updates/collective';

        if (!empty($errors)) {
            $session->set('lstr_flash_error', implode(' ', $errors));
            return Response::redirect($addUrl);
        }

        $updateRepo = new UpdateRepo($db);
        $updateRepo->insert($fanlistingId, [
            'title'     => $title,
            'body'      => $body,
            'category'  => $category,
            'author'    => $author,
            'posted_at' => $postedAt,
        ]);

        $context = $fanlistingId !== null
            ? 'listing #' . $fanlistingId
            : 'Collective';

        (new ActivityRepo($db))->log(
            (int) $session->get('lstr_admin_id'),
            'update_added',
            'Added update "' . $title . '" for ' . $context,
            $request->ip(),
        );

        $session->set('lstr_flash_success', 'Update posted.');
        return Response::redirect($backUrl);
    }

    /**
     * Validates shared fields. Returns array of error strings (empty = valid).
     *
     * @return string[]
     */
    private function validateFields(string $title, string $body, string $postedAt): array
    {
        $errors = [];

        if ($title === '') {
            $errors[] = 'Title is required.';
        } elseif (mb_strlen($title) > 255) {
            $errors[] = 'Title must be 255 characters or fewer.';
        }

        if ($body === '') {
            $errors[] = 'Body is required.';
        }

        if ($postedAt === '') {
            $errors[] = 'Date is required.';
        } elseif (\DateTime::createFromFormat('Y-m-d\TH:i', $postedAt) === false
               && \DateTime::createFromFormat('Y-m-d H:i:s', $postedAt) === false
               && \DateTime::createFromFormat('Y-m-d H:i', $postedAt) === false) {
            $errors[] = 'Date is not a valid datetime.';
        }

        return $errors;
    }

    /**
     * Derives context label, back URL, and breadcrumbs for an existing update.
     *
     * @return array{0: string, 1: string, 2: array<int,array{label:string,url?:string|null}>}
     */
    private function subtitleAndBack(Update $update, mixed $config, mixed $db): array
    {
        if ($update->fanlistingId === null) {
            return [
                'Collective',
                $this->adminUrl('/admin/updates/collective'),
                [
                    $this->crumb('News Feed', $this->adminUrl('/admin/updates')),
                    $this->crumb('Collective'),
                ],
            ];
        }

        $listing = (new ListingRepo($db, $config))->getById($update->fanlistingId);
        $name    = $listing?->subject ?? ('Listing #' . $update->fanlistingId);

        return [
            $name,
            $this->adminUrl('/admin/updates/' . $update->fanlistingId),
            [
                $this->crumb('News Feed', $this->adminUrl('/admin/updates')),
                $this->crumb($name),
            ],
        ];
    }

}
