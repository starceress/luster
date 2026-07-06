<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Admin controller for the Wishlist add-on. Provides the paginated index
 with type/category filters, add/edit/delete entry forms, bulk actions
 (delete, show, hide, change type), drag-and-drop sort order persistence,
 and the wishlist settings page.
*/

declare(strict_types=1);

namespace Luster\Addons\Wishlist\Controllers;

use Luster\Addons\Wishlist\WishlistRepo;
use Luster\Controllers\Admin\AdminController;
use Luster\Core\Request;
use Luster\Core\Response;
use Luster\Repositories\ActivityRepo;
use Luster\Repositories\CategoryRepo;
use Luster\Repositories\SiteSettingsRepo;

/**
 * Admin controller for the Wishlist add-on.
 *
 * Routes (registered by WishlistAddon::registerRoutes()):
 *   GET|POST /admin/wishlist           index()    — paginated index with filters
 *   GET|POST /admin/wishlist/add       add()      — add entry form
 *   GET|POST /admin/wishlist/{id}/edit edit()     — edit entry form
 *   POST     /admin/wishlist/{id}/delete delete() — delete entry (+ image)
 *   POST     /admin/wishlist/{id}/toggle toggle() — AJAX active toggle
 *   POST     /admin/wishlist/bulk      bulk()     — bulk actions
 *   POST     /admin/wishlist/reorder   reorder()  — AJAX drag-and-drop reorder
 *   GET|POST /admin/wishlist/settings  settings() — display settings
 */
final class WishlistAdminController extends AdminController
{
    private const VALID_TYPES = ['wanted', 'top', 'granted'];
    private const VALID_FILTERS = ['wanted', 'top', 'granted', 'hidden'];

    // -----------------------------------------------------------------------
    // GET /admin/wishlist
    // -----------------------------------------------------------------------

    /**
     * Renders the paginated wishlist index with type, active/hidden, category, and
     * keyword filters. Shows per-type counts and all distinct categories in the sidebar.
     */
    public function index(Request $request, array $params): Response
    {
        $db      = $this->app->getDatabase();
        $session = $this->app->getSession();

        $repo        = new WishlistRepo($db);
        $settingsRepo = new SiteSettingsRepo($db);
        $perPage     = max(1, min(100, (int) ($settingsRepo->get('wishlist_per_page', '10') ?? '10')));

        $type      = trim((string) $request->query('type', 'all'));
        $filter    = in_array($type, self::VALID_FILTERS, true) ? $type : 'all';
        $search    = trim((string) $request->query('search', ''));
        $filterCat = trim((string) $request->query('category', ''));
        $sort      = trim((string) $request->query('sort', 'subject'));
        $dir       = trim((string) $request->query('dir', 'asc'));
        $page      = max(1, (int) ($request->query('page', 1) ?: 1));

        $filtersActive = $search !== '' || $filterCat !== '';
        $typeFilter    = in_array($filter, self::VALID_TYPES, true) ? $filter : null;
        $activeFilter  = $filter === 'hidden' ? false : null;

        $result     = $repo->paginate($typeFilter, false, $search, $filterCat, $sort, $dir, $perPage, $page, $activeFilter);
        $typeCounts     = $repo->countByType();
        $categories     = $repo->getCategories();
        $categoryCounts = $repo->getCategoryCounts();

        $success = (string) $session->get('lstr_flash_success', '');
        $error   = (string) $session->get('lstr_flash_error', '');
        $session->remove('lstr_flash_success');
        $session->remove('lstr_flash_error');

        return $this->adminLayout('admin/addons/wishlist/index.php', 'Wishlist', 'wishlist', [
            'entries'        => $result['items'],
            'total'          => $result['total'],
            'page'           => $page,
            'per_page'       => $perPage,
            'total_pages'    => max(1, (int) ceil($result['total'] / $perPage)),
            'filter_type'    => $filter,
            'type_counts'    => $typeCounts,
            'hidden_count'   => $repo->countHidden(),
            'categories'        => $categories,
            'category_counts'   => $categoryCounts,
            'search'         => $search,
            'filter_cat'     => $filterCat,
            'filters_active' => $filtersActive,
            'success'        => $success,
            'error'          => $error,
            'sort'           => $sort,
            'dir'            => $dir,
            ...$this->getCatData(),
        ]);
    }

    // -----------------------------------------------------------------------
    // GET + POST /admin/wishlist/settings
    // -----------------------------------------------------------------------

    /** Renders or saves the wishlist display settings (items per page). */
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
            $perPage = max(1, min(100, (int) $request->post('wishlist_per_page', 10)));
            $settingsRepo->set('wishlist_per_page', (string) $perPage);

            $session->set('lstr_flash_success', 'Wishlist display settings saved.');
            return Response::redirect($this->app->getBaseUrl() . '/admin/wishlist/settings');
        }

        $perPage = max(1, min(100, (int) ($settingsRepo->get('wishlist_per_page', '10') ?? '10')));

        return $this->adminLayout('admin/addons/wishlist/settings.php', 'Wishlist Settings', 'wishlist', [
            'wishlist_per_page' => $perPage,
            'success'           => $success,
            'error'             => $error,
        ], '', [
            $this->crumb('Wishlist', $this->adminUrl('/admin/wishlist')),
        ]);
    }

    // -----------------------------------------------------------------------
    // GET + POST /admin/wishlist/add
    // -----------------------------------------------------------------------

    /**
     * Renders the add form (GET) or creates a new wishlist entry (POST). On POST
     * it validates the submitted data, processes an optional image upload, and
     * inserts the entry.
     */
    public function add(Request $request, array $params): Response
    {
        $db      = $this->app->getDatabase();
        $session = $this->app->getSession();
        $repo    = new WishlistRepo($db);

        if ($request->isPost()) {
            $data   = $this->collectFormData($request);
            $errors = $this->validate($data);

            [$image, $imageError, $tmpPath, $newFilename] = $this->processImageUpload($request);
            if ($imageError !== '') {
                $errors[] = $imageError;
            }

            if (!empty($errors)) {
                return $this->adminLayout('admin/addons/wishlist/add.php', 'Add Wishlist Entry', 'wishlist', [
                    'entry' => null,
                    'old'   => $data,
                    'error' => implode(' ', $errors),
                    ...$this->getCatData(),
                ], '', [
                    $this->crumb('Wishlist', $this->adminUrl('/admin/wishlist')),
                ]);
            }

            if ($newFilename !== null) {
                $destPath = $this->ensureUploadDirectory() . '/' . $newFilename;
                if (!move_uploaded_file($tmpPath, $destPath)) {
                    throw new \RuntimeException("Failed to move uploaded image to {$destPath}.");
                }
                $image = $newFilename;
            }

            $id = $repo->insert([...$data, 'image' => $image]);

            (new ActivityRepo($db))->log(
                (int) $session->get('lstr_admin_id'),
                'wishlist.add',
                "Added wishlist entry #{$id}: " . $data['subject'],
                $request->ip(),
            );

            $session->set('lstr_flash_success', 'Wishlist entry added.');
            return Response::redirect($this->app->getBaseUrl() . '/admin/wishlist');
        }

        return $this->adminLayout('admin/addons/wishlist/add.php', 'Add Wishlist Entry', 'wishlist', [
            'entry' => null,
            'old'   => [],
            'error' => '',
            ...$this->getCatData(),
        ], '', [
            $this->crumb('Wishlist', $this->adminUrl('/admin/wishlist')),
        ]);
    }

    // -----------------------------------------------------------------------
    // GET + POST /admin/wishlist/{id}/edit
    // -----------------------------------------------------------------------

    /**
     * Renders the edit form for an existing entry (GET) or saves the changes (POST).
     * On POST an optional new image can be uploaded, or the existing image deleted
     * via the "delete_image" checkbox.
     */
    public function edit(Request $request, array $params): Response
    {
        $id      = (int) ($params['id'] ?? 0);
        $db      = $this->app->getDatabase();
        $session = $this->app->getSession();
        $repo    = new WishlistRepo($db);
        $entry   = $repo->getById($id);

        if ($entry === null) {
            return Response::notFound('Wishlist entry not found.');
        }

        if ($request->isPost()) {
            $data        = $this->collectFormData($request);
            $errors      = $this->validate($data);
            $deleteImage = $request->post('delete_image') === '1';

            $image      = $entry->image;
            $imageError = '';
            $newFilename = null;
            $tmpPath     = '';

            if (!$deleteImage) {
                [$image, $imageError, $tmpPath, $newFilename] = $this->processImageUpload($request);
                $image = $entry->image; // reset — processImageUpload returns null when no upload
                if ($imageError !== '') {
                    $errors[] = $imageError;
                }
            }

            if (!empty($errors)) {
                return $this->adminLayout('admin/addons/wishlist/add.php', 'Edit Wishlist Entry', 'wishlist', [
                    'entry' => $entry,
                    'old'   => $data,
                    'error' => implode(' ', $errors),
                    ...$this->getCatData(),
                ], '', [
                    $this->crumb('Wishlist', $this->adminUrl('/admin/wishlist')),
                    $this->crumb($entry->subject),
                ]);
            }

            if ($deleteImage) {
                if ($entry->image !== null && $entry->image !== '') {
                    $oldPath = $this->ensureUploadDirectory() . '/' . $entry->image;
                    if (is_file($oldPath)) {
                        unlink($oldPath);
                    }
                }
                $image = null;
            } elseif ($newFilename !== null) {
                $destPath = $this->ensureUploadDirectory() . '/' . $newFilename;
                if (!move_uploaded_file($tmpPath, $destPath)) {
                    throw new \RuntimeException("Failed to move uploaded image to {$destPath}.");
                }
                $image = $newFilename;
            }

            $repo->update($id, [...$data, 'image' => $image]);

            (new ActivityRepo($db))->log(
                (int) $session->get('lstr_admin_id'),
                'wishlist.edit',
                "Edited wishlist entry #{$id}: " . $data['subject'],
                $request->ip(),
            );

            $session->set('lstr_flash_success', 'Wishlist entry updated.');
            return Response::redirect($this->app->getBaseUrl() . '/admin/wishlist');
        }

        return $this->adminLayout('admin/addons/wishlist/add.php', 'Edit Wishlist Entry', 'wishlist', [
            'entry' => $entry,
            'old'   => [],
            'error' => '',
            ...$this->getCatData(),
        ], '', [
            $this->crumb('Wishlist', $this->adminUrl('/admin/wishlist')),
            $this->crumb($entry->subject),
        ]);
    }

    // -----------------------------------------------------------------------
    // POST /admin/wishlist/{id}/delete
    // -----------------------------------------------------------------------

    /** Deletes a wishlist entry and its associated image file, if any. */
    public function delete(Request $request, array $params): Response
    {
        $id      = (int) ($params['id'] ?? 0);
        $db      = $this->app->getDatabase();
        $session = $this->app->getSession();
        $repo    = new WishlistRepo($db);
        $entry   = $repo->getById($id);

        if ($entry === null) {
            $session->set('lstr_flash_error', 'Wishlist entry not found.');
            return Response::redirect($this->app->getBaseUrl() . '/admin/wishlist');
        }

        if ($entry->image !== null && $entry->image !== '') {
            $oldPath = $this->ensureUploadDirectory() . '/' . $entry->image;
            if (is_file($oldPath)) {
                unlink($oldPath);
            }
        }

        $repo->delete($id);

        (new ActivityRepo($db))->log(
            (int) $session->get('lstr_admin_id'),
            'wishlist.delete',
            "Deleted wishlist entry #{$id}: " . $entry->subject,
            $request->ip(),
        );

        $session->set('lstr_flash_success', 'Wishlist entry deleted.');
        return Response::redirect($this->app->getBaseUrl() . '/admin/wishlist');
    }

    // -----------------------------------------------------------------------
    // POST /admin/wishlist/{id}/toggle  (AJAX)
    // -----------------------------------------------------------------------

    /** AJAX endpoint that flips the active flag for an entry and returns the new state as JSON. */
    public function toggle(Request $request, array $params): Response
    {
        $id   = (int) ($params['id'] ?? 0);
        $repo = new WishlistRepo($this->app->getDatabase());

        if ($repo->getById($id) === null) {
            return Response::json(['ok' => false, 'error' => 'Not found'], 404);
        }

        return Response::json(['ok' => true, 'active' => $repo->toggleActive($id)]);
    }

    // -----------------------------------------------------------------------
    // POST /admin/wishlist/bulk
    // -----------------------------------------------------------------------

    /**
     * Handles bulk actions on selected wishlist entries: move to a different type,
     * delete (including associated image files), show, or hide.
     */
    public function bulk(Request $request, array $params): Response
    {
        $db      = $this->app->getDatabase();
        $session = $this->app->getSession();
        $repo    = new WishlistRepo($db);

        $action = trim((string) $request->post('bulk_action', ''));
        $ids    = array_values(array_filter(
            array_map('intval', (array) $request->post('ids', [])),
            fn(int $id) => $id > 0,
        ));

        if (empty($ids)) {
            $session->set('lstr_flash_error', 'No entries selected.');
            return Response::redirect($this->app->getBaseUrl() . '/admin/wishlist');
        }

        $count = count($ids);
        $noun  = $count === 1 ? 'entry' : 'entries';

        $typeLabels = [
            'wanted'  => 'Wanted',
            'top'     => 'Top Wanted',
            'granted' => 'Granted',
        ];

        switch ($action) {
            case 'move':
                $targetType = trim((string) $request->post('move_to_type', ''));
                if (!in_array($targetType, self::VALID_TYPES, true)) {
                    $session->set('lstr_flash_error', 'Invalid target type.');
                    break;
                }
                foreach ($ids as $id) {
                    if ($repo->getById($id) !== null) {
                        $repo->setType($id, $targetType);
                    }
                }
                $session->set('lstr_flash_success', "Moved {$count} {$noun} to {$typeLabels[$targetType]}.");
                break;

            case 'delete':
                foreach ($ids as $id) {
                    $entry = $repo->getById($id);
                    if ($entry === null) {
                        continue;
                    }
                    if ($entry->image !== null && $entry->image !== '') {
                        $oldPath = $this->ensureUploadDirectory() . '/' . $entry->image;
                        if (is_file($oldPath)) {
                            unlink($oldPath);
                        }
                    }
                    $repo->delete($id);
                }
                (new ActivityRepo($db))->log(
                    (int) $session->get('lstr_admin_id'),
                    'wishlist.bulk_delete',
                    "Bulk deleted {$count} wishlist {$noun}.",
                    $request->ip(),
                );
                $session->set('lstr_flash_success', "Deleted {$count} {$noun}.");
                break;

            case 'show':
                foreach ($ids as $id) {
                    $entry = $repo->getById($id);
                    if ($entry !== null && !$entry->active) {
                        $repo->toggleActive($id);
                    }
                }
                $session->set('lstr_flash_success', "Made {$count} {$noun} visible.");
                break;

            case 'hide':
                foreach ($ids as $id) {
                    $entry = $repo->getById($id);
                    if ($entry !== null && $entry->active) {
                        $repo->toggleActive($id);
                    }
                }
                $session->set('lstr_flash_success', "Hidden {$count} {$noun}.");
                break;

            default:
                $session->set('lstr_flash_error', 'Unknown action.');
        }

        return Response::redirect($this->app->getBaseUrl() . '/admin/wishlist');
    }

    // -----------------------------------------------------------------------
    // POST /admin/wishlist/reorder  (AJAX)
    // -----------------------------------------------------------------------

    /**
     * AJAX endpoint for drag-and-drop sort reordering. Accepts a type, page, and
     * per_page so the submitted page-slice of IDs is spliced into the full canonical
     * order before all items are renumbered.
     */
    public function reorder(Request $request, array $params): Response
    {
        $this->app->getCSRF()->validate($request->post('_csrf', ''));

        $type = trim((string) $request->post('type', ''));
        if (!in_array($type, self::VALID_TYPES, true)) {
            return Response::json(['ok' => false, 'error' => 'Invalid type.'], 400);
        }

        $page    = max(1, (int) $request->post('page', 1));
        $perPage = max(1, min(100, (int) $request->post('per_page', 10)));

        $submittedIds = array_values(array_filter(
            array_map('intval', (array) $request->post('ids', [])),
            fn(int $id) => $id > 0,
        ));

        if (empty($submittedIds)) {
            return Response::json(['ok' => true]);
        }

        $db   = $this->app->getDatabase();
        $repo = new WishlistRepo($db);

        // Fetch all IDs for this type in current canonical order
        $allIds = $repo->getAllIdsForType($type);
        if (empty($allIds)) {
            return Response::json(['ok' => true]);
        }

        // Validate submitted IDs all belong to this type
        $validIdSet  = array_flip($allIds);
        $filteredIds = array_values(array_filter(
            $submittedIds,
            fn(int $id) => isset($validIdSet[$id]),
        ));

        if (empty($filteredIds)) {
            return Response::json(['ok' => true]);
        }

        // Replace the page slice in the global order with the new sequence,
        // then renumber all items 0..N-1 so cross-page values never collide.
        $offset = ($page - 1) * $perPage;
        array_splice($allIds, $offset, count($filteredIds), $filteredIds);
        $repo->reorder($allIds);

        return Response::json(['ok' => true]);
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    /**
     * Extracts and normalises the wishlist form fields from the request.
     * Category entries are built from the parallel cat_network / cat_name arrays and
     * joined as a comma-separated string.
     */
    private function collectFormData(Request $request): array
    {
        $rawNetworks = array_values((array) $request->post('cat_network', []));
        $rawNames    = array_values((array) $request->post('cat_name', []));
        $rawOther    = array_values((array) $request->post('cat_network_other', []));

        $catNames = array_values(array_filter(
            array_map('trim', $rawNames),
            static fn(string $s): bool => $s !== '',
        ));

        return [
            'subject'           => trim((string) $request->post('subject', '')),
            'url'               => trim((string) $request->post('url', '')),
            'category'          => implode(', ', $catNames),
            'cat_network'       => $rawNetworks,
            'cat_name'          => $rawNames,
            'cat_network_other' => $rawOther,
            'type'              => trim((string) $request->post('type', 'wanted')),
            'description'       => trim((string) $request->post('description', '')),
            'added_at'          => trim((string) $request->post('added_at', '')),
            'sort_order'        => max(0, (int) $request->post('sort_order', 0)),
            'active'            => $request->post('active') === '1',
        ];
    }

    /**
     * Validates the uploaded image file (MIME check via finfo, 2 MB size limit).
     * Returns [image, error, tmpPath, newFilename]; image is null, newFilename is null when
     * no upload was made or validation fails.
     *
     * @return array{null, string, string, string|null}
     */
    private function processImageUpload(Request $request): array
    {
        $imageFile = $request->file('image') ?? [];
        $uploadErr = (int) ($imageFile['error'] ?? UPLOAD_ERR_NO_FILE);

        if ($uploadErr === UPLOAD_ERR_NO_FILE) {
            return [null, '', '', null];
        }

        if ($uploadErr !== UPLOAD_ERR_OK) {
            return [null, 'Image upload failed (error code ' . $uploadErr . ').', '', null];
        }

        $tmpPath  = (string) ($imageFile['tmp_name'] ?? '');
        $fileSize = (int) ($imageFile['size'] ?? 0);

        if ($fileSize > 2 * 1024 * 1024) {
            return [null, 'Image must be 2 MB or smaller.', $tmpPath, null];
        }

        $allowedMimes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        $finfo        = new \finfo(FILEINFO_MIME_TYPE);
        $mime         = $finfo->file($tmpPath);

        if ($mime === false || !in_array($mime, $allowedMimes, true)) {
            return [null, 'Image must be JPEG, PNG, GIF, or WebP.', $tmpPath, null];
        }

        $extMap      = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp'];
        $newFilename = uniqid('wl_', true) . '.' . $extMap[$mime];

        return [null, '', $tmpPath, $newFilename];
    }

    /**
     * Returns TFL, TAFL, and custom-network category data for the add/edit form's
     * category widget, keyed as tfl_cats, tafl_cats, custom_networks, custom_cats.
     */
    private function getCatData(): array
    {
        $repo           = new CategoryRepo($this->app->getDatabase());
        $customNetworks = $repo->getCustomNetworks();
        $customCats     = [];
        foreach ($customNetworks as $cn) {
            $customCats[$cn] = $repo->getBySource($cn);
        }
        return [
            'tfl_cats'        => $repo->getBySource('tfl'),
            'tafl_cats'       => $repo->getBySource('tafl'),
            'custom_networks' => $customNetworks,
            'custom_cats'     => $customCats,
        ];
    }

    /**
     * Validates the collected form data and returns an array of human-readable error strings.
     * An empty array means the data is valid.
     *
     * @return string[]
     */
    private function validate(array $data): array
    {
        $errors = [];

        if ($data['subject'] === '') {
            $errors[] = 'Subject is required.';
        } elseif (strlen($data['subject']) > 255) {
            $errors[] = 'Subject must be 255 characters or fewer.';
        }

        if ($data['url'] !== '') {
            if (!filter_var($data['url'], FILTER_VALIDATE_URL)
                || !preg_match('/^https?:\/\//i', $data['url'])) {
                $errors[] = 'URL must be a valid HTTP or HTTPS address.';
            } elseif (strlen($data['url']) > 255) {
                $errors[] = 'URL must be 255 characters or fewer.';
            }
        }

        if (strlen($data['category']) > 500) {
            $errors[] = 'Category selection is too long.';
        }

        if (!in_array($data['type'], self::VALID_TYPES, true)) {
            $errors[] = 'Invalid type selected.';
        }

        if ($data['description'] !== '' && strlen($data['description']) > 2000) {
            $errors[] = 'Description must be 2000 characters or fewer.';
        }

        if ($data['added_at'] !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $data['added_at'])) {
            $errors[] = 'Date must be in YYYY-MM-DD format.';
        }

        return $errors;
    }

    /**
     * Ensures public/uploads/wishlist/ exists and has a PHP-execution-blocking .htaccess,
     * then returns the absolute directory path.
     */
    private function ensureUploadDirectory(): string
    {
        $uploadDir = dirname(__DIR__, 4) . '/public/uploads/wishlist';

        if (!is_dir($uploadDir)) {
            if (!mkdir($uploadDir, 0755, true)) {
                throw new \RuntimeException("Failed to create upload directory: {$uploadDir}");
            }
        }

        $htaccess = $uploadDir . '/.htaccess';
        if (!is_file($htaccess)) {
            $content = "<FilesMatch \"\\.ph(p[0-9]?|tml)\$\">\n    Deny from all\n</FilesMatch>\n";
            if (file_put_contents($htaccess, $content) === false) {
                throw new \RuntimeException("Failed to write .htaccess in {$uploadDir}");
            }
        }

        return $uploadDir;
    }
}
