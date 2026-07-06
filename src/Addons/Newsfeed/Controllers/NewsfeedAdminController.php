<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Admin controller for the Newsfeed add-on. Provides the listings overview
 with latest-post dates, per-fanlisting and collective entry lists, the
 add/edit/delete entry forms, bulk delete, and the Newsfeed settings page.
 All state-changing actions are CSRF-protected and logged to the activity log.
*/

declare(strict_types=1);

namespace Luster\Addons\Newsfeed\Controllers;

use Luster\Addons\Newsfeed\NewsfeedEntry;
use Luster\Addons\Newsfeed\NewsfeedRepo;
use Luster\Controllers\Admin\AdminController;
use Luster\Core\Request;
use Luster\Core\Response;
use Luster\Repositories\ActivityRepo;
use Luster\Repositories\ListingLifecycleRepo;
use Luster\Repositories\ListingRepo;
use Luster\Repositories\SiteSettingsRepo;

/**
 * Admin controller for the Newsfeed add-on.
 *
 * All state-changing actions require CSRF validation (handled globally by
 * CsrfMiddleware) and log to the activity log.
 */
final class NewsfeedAdminController extends AdminController
{

    // -----------------------------------------------------------------------
    // GET /admin/newsfeed  — overview index
    // -----------------------------------------------------------------------

    /**
     * Renders the listings overview showing the latest post date and entry count
     * for each fanlisting plus the collective feed. Supports search by subject and
     * sorting by ID, subject, or latest post date.
     */
    public function index(Request $request, array $params): Response
    {
        $db      = $this->app->getDatabase();
        $config  = $this->app->getConfig();
        $session = $this->app->getSession();

        $listingRepo = new ListingRepo($db, $config);
        $newsfeedRepo = new NewsfeedRepo($db);

        $listings      = $listingRepo->getAll();
        $latestPerFl   = $newsfeedRepo->getLatestPerFanlisting();
        $latestColl    = $newsfeedRepo->getLatestCollective();
        $collectiveCnt = $newsfeedRepo->countCollective();

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
        $perPage       = max(5, min(25, (int) ((new SiteSettingsRepo($db))->get('newsfeed_per_page', '10') ?? '10')));
        $pages         = $totalListings > 0 ? (int) ceil($totalListings / $perPage) : 1;
        $page          = min(max(1, (int) $request->query('page', 1)), $pages);
        $listings     = array_slice($listings, ($page - 1) * $perPage, $perPage);
        $lifecycleMap = (new ListingLifecycleRepo($db))
            ->getMapForListings(array_map(static fn($l) => $l->id, $listings));

        $success = (string) $session->get('lstr_flash_success', '');
        $error   = (string) $session->get('lstr_flash_error',   '');
        $session->remove('lstr_flash_success');
        $session->remove('lstr_flash_error');

        return $this->adminLayout('admin/addons/newsfeed/index.php', 'News Feed', 'newsfeed', [
            'listings'        => $listings,
            'lifecycle_map'   => $lifecycleMap,
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
    // GET|POST /admin/newsfeed/settings
    // -----------------------------------------------------------------------

    /** Renders or saves the Newsfeed display settings (items per page). */
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
            $this->app->getCSRF()->validate($request->post('_csrf', ''));

            $perPage = max(5, min(25, (int) $request->post('newsfeed_per_page', 10)));
            $settingsRepo->set('newsfeed_per_page', (string) $perPage);

            $session->set('lstr_flash_success', 'News Feed display settings saved.');
            return Response::redirect($this->app->getBaseUrl() . '/admin/newsfeed/settings');
        }

        $perPage = max(1, min(100, (int) ($settingsRepo->get('newsfeed_per_page', '10') ?? '10')));

        return $this->adminLayout('admin/addons/newsfeed/settings.php', 'News Feed Settings', 'newsfeed', [
            'newsfeed_per_page' => $perPage,
            'success'          => $success,
            'error'            => $error,
        ], '', [
            $this->crumb('News Feed', $this->adminUrl('/admin/newsfeed')),
        ]);
    }

    // -----------------------------------------------------------------------
    // GET /admin/newsfeed/collective  — collective entries list
    // -----------------------------------------------------------------------

    /**
     * Renders the paginated entry list for the collective feed (fanlisting_id IS NULL),
     * with optional search and category filtering.
     */
    public function collectiveList(Request $request, array $params): Response
    {
        $db      = $this->app->getDatabase();
        $session = $this->app->getSession();
        $config  = $this->app->getConfig();

        $perPage         = max(5, min(25, (int) ((new SiteSettingsRepo($db))->get('newsfeed_per_page', '10') ?? '10')));
        $page            = max(1, (int) ($request->query('page', 1)));
        $search          = trim((string) $request->query('search', ''));
        $filterCategory  = trim((string) $request->query('category', ''));

        $newsfeedRepo = new NewsfeedRepo($db);
        $entries      = $newsfeedRepo->getFiltered(null, $search, $filterCategory, $page, $perPage);
        $total        = $newsfeedRepo->countFiltered(null, $search, $filterCategory);
        $pages        = max(1, (int) ceil($total / $perPage));

        $siteName = (string) $config->get('site_name', 'Luster');

        $success = (string) $session->get('lstr_flash_success', '');
        $error   = (string) $session->get('lstr_flash_error',   '');
        $session->remove('lstr_flash_success');
        $session->remove('lstr_flash_error');

        return $this->adminLayout('admin/addons/newsfeed/list.php', 'News Feed', 'newsfeed', [
            'updates'          => $entries,
            'total'            => $total,
            'page'             => $page,
            'pages'            => $pages,
            'per_page'         => $perPage,
            'is_collective'    => true,
            'listing'          => null,
            'ctx_name'         => $siteName,
            'search'           => $search,
            'filter_category'  => $filterCategory,
            'known_categories' => $newsfeedRepo->getKnownCategories(),
            'success'          => $success,
            'error'            => $error,
        ], '', [
            $this->crumb('News Feed', $this->adminUrl('/admin/newsfeed')),
            $this->crumb($siteName),
        ]);
    }

    // -----------------------------------------------------------------------
    // GET|POST /admin/newsfeed/collective/add
    // -----------------------------------------------------------------------

    /** Renders the add-entry form for the collective feed. */
    public function collectiveAddForm(Request $request, array $params): Response
    {
        return $this->renderAddForm($request, null, 'Collective');
    }

    /** Saves a new collective entry. */
    public function collectiveAdd(Request $request, array $params): Response
    {
        return $this->handleAdd($request, null, 'Collective');
    }

    // -----------------------------------------------------------------------
    // GET /admin/newsfeed/{fid}  — per-fanlisting list
    // -----------------------------------------------------------------------

    /**
     * Renders the paginated entry list for a single fanlisting, with optional
     * search and category filtering.
     */
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

        $perPage         = max(5, min(25, (int) ((new SiteSettingsRepo($db))->get('newsfeed_per_page', '10') ?? '10')));
        $page            = max(1, (int) ($request->query('page', 1)));
        $search          = trim((string) $request->query('search', ''));
        $filterCategory  = trim((string) $request->query('category', ''));

        $newsfeedRepo = new NewsfeedRepo($db);
        $entries      = $newsfeedRepo->getFiltered($fid, $search, $filterCategory, $page, $perPage);
        $total        = $newsfeedRepo->countFiltered($fid, $search, $filterCategory);
        $pages        = max(1, (int) ceil($total / $perPage));

        $success = (string) $session->get('lstr_flash_success', '');
        $error   = (string) $session->get('lstr_flash_error',   '');
        $session->remove('lstr_flash_success');
        $session->remove('lstr_flash_error');

        return $this->adminLayout('admin/addons/newsfeed/list.php', 'News Feed', 'newsfeed', [
            'updates'          => $entries,
            'total'            => $total,
            'page'             => $page,
            'pages'            => $pages,
            'per_page'         => $perPage,
            'is_collective'    => false,
            'listing'          => $listing,
            'ctx_name'         => $listing->subject,
            'search'           => $search,
            'filter_category'  => $filterCategory,
            'known_categories' => $newsfeedRepo->getKnownCategories(),
            'success'          => $success,
            'error'            => $error,
        ], '', [
            $this->crumb('News Feed', $this->adminUrl('/admin/newsfeed')),
            $this->crumb($listing->subject),
        ]);
    }

    // -----------------------------------------------------------------------
    // GET|POST /admin/newsfeed/{fid}/add
    // -----------------------------------------------------------------------

    /** Renders the add-entry form for a per-fanlisting feed. */
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

    /** Saves a new entry for a per-fanlisting feed. */
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
    // GET|POST /admin/newsfeed/entry/{id}/edit
    // -----------------------------------------------------------------------

    /** Renders the edit form for an existing entry. */
    public function editForm(Request $request, array $params): Response
    {
        $id = (int) ($params['id'] ?? 0);

        $db      = $this->app->getDatabase();
        $config  = $this->app->getConfig();
        $session = $this->app->getSession();

        $newsfeedRepo = new NewsfeedRepo($db);
        $entry        = $newsfeedRepo->getById($id);

        if ($entry === null) {
            return Response::notFound('Entry not found.');
        }

        [$contextLabel, $backUrl, $breadcrumbs] = $this->subtitleAndBack($entry, $config, $db);

        $error = (string) $session->get('lstr_flash_error', '');
        $session->remove('lstr_flash_error');

        return $this->adminLayout('admin/addons/newsfeed/add.php', 'Edit Entry', 'newsfeed', [
            'update'           => $entry,
            'fanlisting_id'    => $entry->fanlistingId,
            'known_categories' => $newsfeedRepo->getKnownCategories(),
            'back_url'         => $backUrl,
            'ctx_label'        => $contextLabel,
            'back_label'       => $contextLabel,
            'is_edit'          => true,
            'error'            => $error,
        ], '', $breadcrumbs);
    }

    /** Saves edits to an existing entry. */
    public function edit(Request $request, array $params): Response
    {
        $id = (int) ($params['id'] ?? 0);

        $db      = $this->app->getDatabase();
        $config  = $this->app->getConfig();
        $session = $this->app->getSession();
        $baseUrl = $this->app->getBaseUrl();

        $newsfeedRepo = new NewsfeedRepo($db);
        $entry        = $newsfeedRepo->getById($id);

        if ($entry === null) {
            return Response::notFound('Entry not found.');
        }

        [, $backUrl] = $this->subtitleAndBack($entry, $config, $db);

        $title    = trim((string) $request->post('title',     ''));
        $body     = trim((string) $request->post('body',      ''));
        $category = trim((string) $request->post('category',  ''));
        $postedAt = trim((string) $request->post('posted_at', ''));

        $errors = $this->validateFields($title, $body, $postedAt);

        if (!empty($errors)) {
            $session->set('lstr_flash_error', implode(' ', $errors));
            return Response::redirect($baseUrl . '/admin/newsfeed/entry/' . $id . '/edit');
        }

        $newsfeedRepo->update($id, [
            'title'     => $title,
            'body'      => $body,
            'category'  => $category,
            'posted_at' => $postedAt,
        ]);

        (new ActivityRepo($db))->log(
            (int) $session->get('lstr_admin_id'),
            'update_edited',
            'Edited newsfeed entry "' . $title . '"',
            $request->ip(),
        );

        $session->set('lstr_flash_success', 'Entry saved.');
        return Response::redirect($backUrl);
    }

    // -----------------------------------------------------------------------
    // POST /admin/newsfeed/entry/{id}/delete
    // -----------------------------------------------------------------------

    /** Deletes a single entry and redirects back to its parent feed list. */
    public function delete(Request $request, array $params): Response
    {
        $id = (int) ($params['id'] ?? 0);

        $db      = $this->app->getDatabase();
        $config  = $this->app->getConfig();
        $session = $this->app->getSession();
        $baseUrl = $this->app->getBaseUrl();

        $newsfeedRepo = new NewsfeedRepo($db);
        $entry        = $newsfeedRepo->getById($id);

        if ($entry === null) {
            return Response::notFound('Entry not found.');
        }

        [, $backUrl] = $this->subtitleAndBack($entry, $config, $db);

        $newsfeedRepo->delete($id);

        $context = $entry->fanlistingId === null
            ? 'Collective'
            : 'listing #' . $entry->fanlistingId;

        (new ActivityRepo($db))->log(
            (int) $session->get('lstr_admin_id'),
            'update_deleted',
            'Deleted newsfeed entry "' . $entry->title . '" for ' . $context,
            $request->ip(),
        );

        $session->set('lstr_flash_success', 'Entry deleted.');
        return Response::redirect($backUrl);
    }

    // -----------------------------------------------------------------------
    // POST /admin/newsfeed/bulk  — bulk delete across any page/listing
    // -----------------------------------------------------------------------

    /** Deletes the selected entries in bulk and redirects to the originating list. */
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
        if ($returnTo === '' || !str_starts_with($returnTo, $baseUrl . '/admin/newsfeed')) {
            $returnTo = $baseUrl . '/admin/newsfeed';
        }

        if (empty($ids)) {
            $session->set('lstr_flash_error', 'No entries selected.');
            return Response::redirect($returnTo);
        }

        if ($action !== 'delete') {
            $session->set('lstr_flash_error', 'Unknown bulk action.');
            return Response::redirect($returnTo);
        }

        $newsfeedRepo = new NewsfeedRepo($db);
        $activityRepo = new ActivityRepo($db);
        $count        = 0;

        foreach ($ids as $id) {
            if ($newsfeedRepo->getById($id) !== null) {
                $newsfeedRepo->delete($id);
                $count++;
            }
        }

        $activityRepo->log(
            (int) $session->get('lstr_admin_id'),
            'update_bulk_deleted',
            "Bulk deleted {$count} newsfeed entr" . ($count === 1 ? 'y' : 'ies'),
            $request->ip(),
        );

        $session->set('lstr_flash_success', "Deleted {$count} " . ($count === 1 ? 'entry' : 'entries') . '.');
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

        $newsfeedRepo = new NewsfeedRepo($db);

        $error = (string) $session->get('lstr_flash_error', '');
        $session->remove('lstr_flash_error');

        $backUrl = $fanlistingId !== null
            ? $baseUrl . '/admin/newsfeed/' . $fanlistingId
            : $baseUrl . '/admin/newsfeed/collective';

        return $this->adminLayout('admin/addons/newsfeed/add.php', 'Add Entry', 'newsfeed', [
            'update'           => null,
            'fanlisting_id'    => $fanlistingId,
            'known_categories' => $newsfeedRepo->getKnownCategories(),
            'back_url'         => $backUrl,
            'ctx_label'        => $contextLabel,
            'back_label'       => $contextLabel,
            'is_edit'          => false,
            'author'           => (string) $session->get('lstr_admin_username', ''),
            'error'            => $error,
        ], '', [
            $this->crumb('News Feed', $this->adminUrl('/admin/newsfeed')),
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
            ? $baseUrl . '/admin/newsfeed/' . $fanlistingId . '/add'
            : $baseUrl . '/admin/newsfeed/collective/add';

        $backUrl = $fanlistingId !== null
            ? $baseUrl . '/admin/newsfeed/' . $fanlistingId
            : $baseUrl . '/admin/newsfeed/collective';

        if (!empty($errors)) {
            $session->set('lstr_flash_error', implode(' ', $errors));
            return Response::redirect($addUrl);
        }

        $newsfeedRepo = new NewsfeedRepo($db);
        $newsfeedRepo->insert($fanlistingId, [
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
            'Added newsfeed entry "' . $title . '" for ' . $context,
            $request->ip(),
        );

        $session->set('lstr_flash_success', 'Entry posted.');
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
     * Derives context label, back URL, and breadcrumbs for an existing entry.
     *
     * @return array{0: string, 1: string, 2: array<int,array{label:string,url?:string|null}>}
     */
    private function subtitleAndBack(NewsfeedEntry $entry, mixed $config, mixed $db): array
    {
        if ($entry->fanlistingId === null) {
            return [
                'Collective',
                $this->adminUrl('/admin/newsfeed/collective'),
                [
                    $this->crumb('News Feed', $this->adminUrl('/admin/newsfeed')),
                    $this->crumb('Collective'),
                ],
            ];
        }

        $listing = (new ListingRepo($db, $config))->getById($entry->fanlistingId);
        $name    = $listing?->subject ?? ('Listing #' . $entry->fanlistingId);

        return [
            $name,
            $this->adminUrl('/admin/newsfeed/' . $entry->fanlistingId),
            [
                $this->crumb('News Feed', $this->adminUrl('/admin/newsfeed')),
                $this->crumb($name),
            ],
        ];
    }

}
