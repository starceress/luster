<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Manages the site owner's "joined" listings -- fanlistings the owner has joined
 as a member elsewhere. Provides the joined index (grouped by category with
 search and pagination), add/edit forms, delete, and active/inactive toggling.
 These entries appear on the public collective page when visitors browse the
 owner's joined fanlistings.
*/

declare(strict_types=1);

namespace Luster\Controllers\Admin;

use Luster\Core\Request;
use Luster\Core\Response;
use Luster\Core\Validator;
use Luster\Repositories\ActivityRepo;
use Luster\Repositories\CategoryRepo;
use Luster\Repositories\JoinedListingRepo;

/**
 * Manages the owner's joined-fanlisting entries: paginated index with network/
 * category/status filtering, add/edit/delete forms with image upload,
 * bulk status actions, active/inactive toggle, and display settings.
 */
final class JoinedController extends AdminController
{

    // -----------------------------------------------------------------------
    // GET /admin/joined
    // -----------------------------------------------------------------------

    /**
     * Renders the paginated joined-listings index with network, category, and
     * status-tab filters plus optional column sorting applied client-side.
     */
    public function index(Request $request, array $params): Response
    {
        $repo         = new JoinedListingRepo($this->app->getDatabase());
        $categoryRepo = new CategoryRepo($this->app->getDatabase());

        $displayMode    = (string) ($this->app->getConfig()->get('joined_display_mode', 'all'));
        $defaultPerPage = (int) ($this->app->getConfig()->get('joined_per_page', 10));

        $search        = trim((string) $request->query('search', ''));
        $filterNetwork = trim((string) $request->query('network', ''));
        $filterCat     = trim((string) $request->query('category', ''));
        $perPage       = max(5, min(100, (int) $request->query('per_page', $defaultPerPage)));
        $page          = max(1, (int) $request->query('page', 1));
        $sort          = trim((string) $request->query('sort', ''));
        $dir           = trim((string) $request->query('dir', 'asc'));
        $statusTab     = trim((string) $request->query('status_tab', 'all'));
        if (!in_array($sort, ['name', 'category', 'date_joined', 'status'], true)) { $sort = ''; }
        if (!in_array($dir, ['asc', 'desc'], true)) { $dir = 'asc'; }
        if (!in_array($statusTab, ['all', 'approved', 'pending', 'hidden'], true)) { $statusTab = 'all'; }

        $networkCategories = $repo->getNetworkCategories();
        if ($filterNetwork !== '' && isset($networkCategories[$filterNetwork])) {
            $categories = $networkCategories[$filterNetwork];
        } else {
            $categories = $repo->getCategories();
        }
        $tflCats         = $categoryRepo->getBySource('tfl');
        $taflCats        = $categoryRepo->getBySource('tafl');
        $customNetworks  = $categoryRepo->getCustomNetworks();

        $tabCounts      = $repo->countAllByStatusTab($search, $filterNetwork, $filterCat);
        $networkCounts  = $repo->getNetworkCounts();
        $categoryCounts = $repo->getCategoryCountMap(false);

        if ($search !== '' || $filterNetwork !== '') {
            $result = $repo->search($search, $filterNetwork, $perPage, $page, $filterCat, $statusTab);
        } else {
            $result = $repo->getAll($perPage, $page, $filterCat, $statusTab);
        }

        $joined     = $result['items'];
        $totalPages = $result['pages'];
        $totalCount = $result['total'];

        if ($sort !== '') {
            usort($joined, static function ($a, $b) use ($sort, $dir): int {
                $cmp = match ($sort) {
                    'category'   => strcasecmp($a->category ?? '', $b->category ?? '') ?: strcasecmp($a->name, $b->name),
                    'date_joined' => strcmp($a->dateJoined ?? '', $b->dateJoined ?? '') ?: strcasecmp($a->name, $b->name),
                    'status'     => strcmp($a->status ?? 'approved', $b->status ?? 'approved') ?: strcasecmp($a->name, $b->name),
                    default      => strcasecmp($a->name, $b->name),
                };
                return $dir === 'desc' ? -$cmp : $cmp;
            });
        }

        $session = $this->app->getSession();
        $success = (string) $session->get('lstr_flash_success', '');
        $error   = (string) $session->get('lstr_flash_error', '');
        $session->remove('lstr_flash_success');
        $session->remove('lstr_flash_error');

        return $this->adminLayout('admin/joined/index.php', 'Joined Listings', 'joined', [
            'joined'             => $joined,
            'categories'         => $categories,
            'network_categories' => $networkCategories,
            'network_counts'     => $networkCounts,
            'category_counts'    => $categoryCounts,
            'filter_cat'         => $filterCat,
            'filter_network'     => $filterNetwork,
            'search'             => $search,
            'per_page'           => $perPage,
            'page'               => $page,
            'total_pages'        => $totalPages,
            'total'              => $totalCount,
            'status_tab'         => $statusTab,
            'tab_counts'         => $tabCounts,
            'tfl_cats'           => $tflCats,
            'tafl_cats'          => $taflCats,
            'custom_networks'    => $customNetworks,
            'sort'               => $sort,
            'dir'                => $dir,
            'success'            => $success,
            'error'              => $error,
        ]);
    }

    // -----------------------------------------------------------------------
    // GET + POST /admin/joined/add
    // -----------------------------------------------------------------------

    /**
     * Handles the add form: GET renders empty form, POST validates, moves the
     * uploaded image, builds the multi-network categories JSON, and persists.
     */
    public function add(Request $request, array $params): Response
    {
        $repo         = new JoinedListingRepo($this->app->getDatabase());
        $categoryRepo = new CategoryRepo($this->app->getDatabase());
        $session      = $this->app->getSession();
        $baseUrl      = $this->app->getBaseUrl();

        if ($request->isPost()) {
            $name        = trim((string) $request->post('name', ''));
            $url         = self::httpsify(trim((string) $request->post('url', '')));
            $category    = trim((string) $request->post('category', ''));
            $dateJoined  = trim((string) $request->post('date_joined', ''));
            $active      = $request->post('active') === '1';
            $status      = trim((string) $request->post('status', 'approved'));
            $description = trim((string) $request->post('description', ''));
            $comments    = trim((string) $request->post('comments', ''));

            if (!in_array($status, ['approved', 'pending'], true)) {
                $status = 'approved';
            }

            $categoriesJson = $this->buildCategoriesJson($categoryRepo, $request);

            // --- Image upload ---
            $image      = null;
            $imageError = '';
            $newFilename = null;
            $tmpPath     = '';
            $imageFile   = $request->file('image') ?? [];

            $uploadErr = (int) ($imageFile['error'] ?? UPLOAD_ERR_NO_FILE);

            if ($uploadErr !== UPLOAD_ERR_NO_FILE) {
                if ($uploadErr !== UPLOAD_ERR_OK) {
                    $imageError = 'Image upload failed (error code ' . $uploadErr . ').';
                } else {
                    $tmpPath  = (string) ($imageFile['tmp_name'] ?? '');
                    $fileSize = (int) ($imageFile['size'] ?? 0);

                    if ($fileSize > 2 * 1024 * 1024) {
                        $imageError = 'Image must be 2 MB or smaller.';
                    } else {
                        $allowedMimes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
                        $finfo        = new \finfo(FILEINFO_MIME_TYPE);
                        $mime         = $finfo->file($tmpPath);

                        if ($mime === false || !in_array($mime, $allowedMimes, true)) {
                            $imageError = 'Image must be JPEG, PNG, GIF, or WebP.';
                        } else {
                            $extMap = [
                                'image/jpeg' => 'jpg',
                                'image/png'  => 'png',
                                'image/gif'  => 'gif',
                                'image/webp' => 'webp',
                            ];
                            $newFilename = uniqid('jn_', true) . '.' . $extMap[$mime];
                        }
                    }
                }
            }

            // --- Field validation ---
            $v = new Validator(['name' => $name, 'url' => $url]);
            $v->field('name')->required()->maxLength(255);
            $v->field('url')->required()->httpUrl()->maxLength(500);

            if ($imageError !== '' || $v->fails()) {
                $messages = $v->fails() ? $v->allErrors() : [];
                if ($imageError !== '') {
                    array_unshift($messages, $imageError);
                }

                $customNetworksErr = $categoryRepo->getCustomNetworks();
                $customCatsErr     = [];
                foreach ($customNetworksErr as $cn) {
                    $customCatsErr[$cn] = $categoryRepo->getBySource($cn);
                }

                return $this->adminLayout('admin/joined/add.php', 'Add Joined FL', 'joined', [
                    'joined'          => null,
                    'error'           => implode(' ', $messages),
                    'field_errors'    => $v->errors(),
                    'old'             => $request->postAll(),
                    'tfl_cats'        => $categoryRepo->getBySource('tfl'),
                    'tafl_cats'       => $categoryRepo->getBySource('tafl'),
                    'custom_networks' => $customNetworksErr,
                    'custom_cats'     => $customCatsErr,
                ], '', [
                    $this->crumb('Joined Listings', $this->adminUrl('/admin/joined')),
                ]);
            }

            // --- All valid: move upload, persist ---
            if ($newFilename !== null) {
                $uploadDir = $this->ensureUploadDirectory();
                $destPath  = $uploadDir . '/' . $newFilename;

                if (!move_uploaded_file($tmpPath, $destPath)) {
                    throw new \RuntimeException("Failed to move uploaded image to {$destPath}.");
                }

                $image = $newFilename;
            }

            $repo->insert([
                'name'            => $name,
                'url'             => $url,
                'image'           => $image,
                'category'        => $category,
                'date_joined'     => $dateJoined !== '' ? $dateJoined : null,
                'active'          => $active,
                'status'          => $status,
                'categories_json' => $categoriesJson,
                'description'     => $description !== '' ? $description : null,
                'comments'        => $comments    !== '' ? $comments    : null,
            ]);

            $session->set('lstr_flash_success', 'Joined listing added.');
            return Response::redirect($baseUrl . '/admin/joined');
        }

        $customNetworks = $categoryRepo->getCustomNetworks();
        $customCats     = [];
        foreach ($customNetworks as $cn) {
            $customCats[$cn] = $categoryRepo->getBySource($cn);
        }

        return $this->adminLayout('admin/joined/add.php', 'Add Joined FL', 'joined', [
            'joined'          => null,
            'error'           => '',
            'old'             => [],
            'tfl_cats'        => $categoryRepo->getBySource('tfl'),
            'tafl_cats'       => $categoryRepo->getBySource('tafl'),
            'custom_networks' => $customNetworks,
            'custom_cats'     => $customCats,
        ], '', [
            $this->crumb('Joined Listings', $this->adminUrl('/admin/joined')),
        ]);
    }

    // -----------------------------------------------------------------------
    // GET + POST /admin/joined/{id}/edit
    // -----------------------------------------------------------------------

    /**
     * Handles the edit form: GET populates fields from the existing record,
     * POST validates, replaces or deletes the image file, and persists changes.
     */
    public function edit(Request $request, array $params): Response
    {
        $id           = (int) ($params['id'] ?? 0);
        $repo         = new JoinedListingRepo($this->app->getDatabase());
        $categoryRepo = new CategoryRepo($this->app->getDatabase());

        $joined = $repo->getById($id);

        if ($joined === null) {
            return Response::notFound('Joined listing not found.');
        }

        $session = $this->app->getSession();
        $baseUrl = $this->app->getBaseUrl();

        if ($request->isPost()) {
            $name        = trim((string) $request->post('name', ''));
            $url         = self::httpsify(trim((string) $request->post('url', '')));
            $category    = trim((string) $request->post('category', ''));
            $dateJoined  = trim((string) $request->post('date_joined', ''));
            $active      = $request->post('active') === '1';
            $deleteImage = $request->post('delete_image') === '1';
            $status      = trim((string) $request->post('status', 'approved'));
            $description = trim((string) $request->post('description', ''));
            $comments    = trim((string) $request->post('comments', ''));

            if (!in_array($status, ['approved', 'pending'], true)) {
                $status = 'approved';
            }

            $categoriesJson = $this->buildCategoriesJson($categoryRepo, $request);

            // --- Image upload ---
            $image       = $joined->image;   // preserve existing by default
            $imageError  = '';
            $newFilename = null;
            $tmpPath     = '';
            $imageFile   = $request->file('image') ?? [];

            if (!$deleteImage) {
                $uploadErr = (int) ($imageFile['error'] ?? UPLOAD_ERR_NO_FILE);

                if ($uploadErr !== UPLOAD_ERR_NO_FILE) {
                    if ($uploadErr !== UPLOAD_ERR_OK) {
                        $imageError = 'Image upload failed (error code ' . $uploadErr . ').';
                    } else {
                        $tmpPath  = (string) ($imageFile['tmp_name'] ?? '');
                        $fileSize = (int) ($imageFile['size'] ?? 0);

                        if ($fileSize > 2 * 1024 * 1024) {
                            $imageError = 'Image must be 2 MB or smaller.';
                        } else {
                            $allowedMimes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
                            $finfo        = new \finfo(FILEINFO_MIME_TYPE);
                            $mime         = $finfo->file($tmpPath);

                            if ($mime === false || !in_array($mime, $allowedMimes, true)) {
                                $imageError = 'Image must be JPEG, PNG, GIF, or WebP.';
                            } else {
                                $extMap = [
                                    'image/jpeg' => 'jpg',
                                    'image/png'  => 'png',
                                    'image/gif'  => 'gif',
                                    'image/webp' => 'webp',
                                ];
                                $newFilename = uniqid('jn_', true) . '.' . $extMap[$mime];
                            }
                        }
                    }
                }
            }

            // --- Field validation ---
            $v = new Validator(['name' => $name, 'url' => $url]);
            $v->field('name')->required()->maxLength(255);
            $v->field('url')->required()->httpUrl()->maxLength(500);

            if ($imageError !== '' || $v->fails()) {
                $messages = $v->fails() ? $v->allErrors() : [];
                if ($imageError !== '') {
                    array_unshift($messages, $imageError);
                }

                $customNetworksErr = $categoryRepo->getCustomNetworks();
                $customCatsErr     = [];
                foreach ($customNetworksErr as $cn) {
                    $customCatsErr[$cn] = $categoryRepo->getBySource($cn);
                }

                return $this->adminLayout('admin/joined/add.php', 'Edit Joined FL', 'joined', [
                    'joined'          => $joined,
                    'error'           => implode(' ', $messages),
                    'field_errors'    => $v->errors(),
                    'old'             => $request->postAll(),
                    'tfl_cats'        => $categoryRepo->getBySource('tfl'),
                    'tafl_cats'       => $categoryRepo->getBySource('tafl'),
                    'custom_networks' => $customNetworksErr,
                    'custom_cats'     => $customCatsErr,
                ], '', [
                    $this->crumb('Joined Listings', $this->adminUrl('/admin/joined')),
                    $this->crumb($joined->name),
                ]);
            }

            // --- All valid: handle image, persist ---
            if ($deleteImage) {
                if ($joined->image !== null && $joined->image !== '') {
                    $oldPath = $this->ensureUploadDirectory() . '/' . $joined->image;
                    if (is_file($oldPath)) {
                        unlink($oldPath);
                    }
                }
                $image = null;
            } elseif ($newFilename !== null) {
                $uploadDir = $this->ensureUploadDirectory();
                $destPath  = $uploadDir . '/' . $newFilename;

                if (!move_uploaded_file($tmpPath, $destPath)) {
                    throw new \RuntimeException("Failed to move uploaded image to {$destPath}.");
                }

                if ($joined->image !== null && $joined->image !== '') {
                    $oldPath = $uploadDir . '/' . $joined->image;
                    if (is_file($oldPath)) {
                        unlink($oldPath);
                    }
                }

                $image = $newFilename;
            }

            $repo->update($id, [
                'name'            => $name,
                'url'             => $url,
                'image'           => $image,
                'category'        => $category,
                'date_joined'     => $dateJoined !== '' ? $dateJoined : null,
                'active'          => $active,
                'status'          => $status,
                'categories_json' => $categoriesJson,
                'description'     => $description !== '' ? $description : null,
                'comments'        => $comments    !== '' ? $comments    : null,
            ]);

            $session->set('lstr_flash_success', 'Joined listing updated.');
            return Response::redirect($baseUrl . '/admin/joined');
        }

        $customNetworks = $categoryRepo->getCustomNetworks();
        $customCats     = [];
        foreach ($customNetworks as $cn) {
            $customCats[$cn] = $categoryRepo->getBySource($cn);
        }

        return $this->adminLayout('admin/joined/add.php', 'Edit Joined FL', 'joined', [
            'joined'          => $joined,
            'error'           => '',
            'old'             => [],
            'tfl_cats'        => $categoryRepo->getBySource('tfl'),
            'tafl_cats'       => $categoryRepo->getBySource('tafl'),
            'custom_networks' => $customNetworks,
            'custom_cats'     => $customCats,
        ], '', [
            $this->crumb('Joined Listings', $this->adminUrl('/admin/joined')),
            $this->crumb($joined->name),
        ]);
    }

    // -----------------------------------------------------------------------
    // POST /admin/joined/{id}/delete-image
    // -----------------------------------------------------------------------

    /**
     * Immediately deletes the joined listing's image from disk and clears the
     * DB reference. Returns JSON {ok:true} on success or {ok:false,error:...}.
     */
    public function deleteImage(Request $request, array $params): Response
    {
        $id   = (int) ($params['id'] ?? 0);
        $repo = new JoinedListingRepo($this->app->getDatabase());

        $joined = $repo->getById($id);
        if ($joined === null) {
            return Response::json(['ok' => false, 'error' => 'Joined listing not found.']);
        }

        $filename = $joined->image ?? '';
        if ($filename === '') {
            return Response::json(['ok' => false, 'error' => 'No image to delete.']);
        }

        $uploadDir = dirname(__DIR__, 3) . '/public/uploads/joined';
        $path      = $uploadDir . '/' . $filename;
        if (is_file($path)) {
            unlink($path);
        }

        $repo->updateImage($id, null);

        $session = $this->app->getSession();
        (new ActivityRepo($this->app->getDatabase()))->log(
            (int) $session->get('lstr_admin_id'),
            'joined.image_delete',
            "Deleted image for joined listing #{$id} ({$joined->name})",
            $request->ip(),
        );

        return Response::json(['ok' => true]);
    }

    // -----------------------------------------------------------------------
    // POST /admin/joined/{id}/delete
    // -----------------------------------------------------------------------

    /**
     * Deletes a joined listing and removes its image file from disk if present.
     */
    public function delete(Request $request, array $params): Response
    {
        $id   = (int) ($params['id'] ?? 0);
        $repo = new JoinedListingRepo($this->app->getDatabase());

        $joined = $repo->getById($id);

        if ($joined === null) {
            return Response::notFound('Joined listing not found.');
        }

        // Remove image file if present
        if ($joined->image !== null && $joined->image !== '') {
            $uploadDir = dirname(__DIR__, 3) . '/public/uploads/joined';
            $oldPath   = $uploadDir . '/' . $joined->image;
            if (is_file($oldPath)) {
                unlink($oldPath);
            }
        }

        $repo->delete($id);

        $session = $this->app->getSession();
        $session->set('lstr_flash_success', 'Joined listing deleted.');

        return Response::redirect($this->app->getBaseUrl() . '/admin/joined');
    }

    // -----------------------------------------------------------------------
    // POST /admin/joined/{id}/toggle
    // -----------------------------------------------------------------------

    /**
     * Flips the active flag on a joined listing between visible and hidden.
     */
    public function toggle(Request $request, array $params): Response
    {
        $id   = (int) ($params['id'] ?? 0);
        $repo = new JoinedListingRepo($this->app->getDatabase());

        if ($repo->getById($id) === null) {
            return Response::notFound('Joined listing not found.');
        }

        $repo->toggleActive($id);

        $session = $this->app->getSession();
        $session->set('lstr_flash_success', 'Status toggled.');

        return Response::redirect($this->app->getBaseUrl() . '/admin/joined');
    }

    // -----------------------------------------------------------------------
    // POST /admin/joined/bulk
    // -----------------------------------------------------------------------

    /**
     * Applies a bulk action (approve, pending, hide, unhide, delete) to a
     * set of joined-listing IDs; supports "select all" via a server-side filter.
     */
    public function bulkAction(Request $request, array $params): Response
    {
        $session = $this->app->getSession();
        $baseUrl = $this->app->getBaseUrl();

        // CSRF validation
        $csrf  = $this->app->getCSRF();
        $token = (string) $request->post('_csrf', '');
        if (!$csrf->validate($token)) {
            $session->set('lstr_flash_error', 'Invalid CSRF token.');
            return Response::redirect($baseUrl . '/admin/joined');
        }

        $validTabs  = ['all', 'approved', 'pending', 'hidden'];
        $statusTab  = trim((string) $request->post('_status_tab', 'all'));
        if (!in_array($statusTab, $validTabs, true)) { $statusTab = 'all'; }
        $redirectUrl = $baseUrl . '/admin/joined' . ($statusTab !== 'all' ? '?status_tab=' . urlencode($statusTab) : '');

        $action    = (string) $request->post('bulk_action', '');
        $db        = $this->app->getDatabase();
        $repo      = new JoinedListingRepo($db);
        $selectAll = $request->post('select_all', '0') === '1';

        if ($selectAll) {
            $saSearch    = trim((string) $request->post('sa_filter_search', ''));
            $saNetwork   = trim((string) $request->post('sa_filter_network', ''));
            $saCat       = trim((string) $request->post('sa_filter_cat', ''));
            $saStatusTab = trim((string) $request->post('sa_filter_status_tab', 'all'));
            if (!in_array($saStatusTab, $validTabs, true)) { $saStatusTab = 'all'; }
            $ids = $repo->getAllIdsByFilter($saSearch, $saNetwork, $saCat, $saStatusTab);
        } else {
            $ids = array_filter(
                array_map('intval', (array) $request->post('ids', [])),
                static fn(int $i): bool => $i > 0,
            );
        }

        if ($ids === [] || !in_array($action, ['approve', 'pending', 'hide', 'unhide', 'delete'], true)) {
            $session->set('lstr_flash_error', 'No items selected or invalid action.');
            return Response::redirect($redirectUrl);
        }
        $count = 0;

        if ($action === 'approve') {
            $stmt = $db->prepare('UPDATE lstr_joined SET status = :status WHERE id = :id');
            foreach ($ids as $id) {
                $stmt->execute(['status' => 'approved', 'id' => $id]);
                ++$count;
            }
        } elseif ($action === 'pending') {
            $stmt = $db->prepare('UPDATE lstr_joined SET status = :status WHERE id = :id');
            foreach ($ids as $id) {
                $stmt->execute(['status' => 'pending', 'id' => $id]);
                ++$count;
            }
        } elseif ($action === 'hide') {
            $stmt = $db->prepare('UPDATE lstr_joined SET active = 0 WHERE id = :id');
            foreach ($ids as $id) {
                $stmt->execute(['id' => $id]);
                ++$count;
            }
        } elseif ($action === 'unhide') {
            $stmt = $db->prepare('UPDATE lstr_joined SET active = 1 WHERE id = :id');
            foreach ($ids as $id) {
                $stmt->execute(['id' => $id]);
                ++$count;
            }
        } else {
            // delete
            $uploadDir = dirname(__DIR__, 3) . '/public/uploads/joined';
            $delStmt   = $db->prepare('DELETE FROM lstr_joined WHERE id = :id');
            foreach ($ids as $id) {
                $joined = $repo->getById($id);
                if ($joined === null) {
                    continue;
                }
                if ($joined->image !== null && $joined->image !== '') {
                    $oldPath = $uploadDir . '/' . $joined->image;
                    if (is_file($oldPath)) {
                        unlink($oldPath);
                    }
                }
                $delStmt->execute(['id' => $id]);
                ++$count;
            }
        }

        $noun = $count === 1 ? 'item' : 'items';
        $msg  = match ($action) {
            'approve' => "Approved {$count} {$noun}.",
            'pending' => "Marked {$count} {$noun} as pending.",
            'hide'    => "Hidden {$count} {$noun}.",
            'unhide'  => "Unhidden {$count} {$noun}.",
            'delete'  => "Deleted {$count} {$noun}.",
            default   => ucfirst($action) . "d {$count} {$noun}.",
        };
        $session->set('lstr_flash_success', $msg);

        return Response::redirect($redirectUrl);
    }

    // -----------------------------------------------------------------------
    // GET + POST /admin/joined/settings
    // -----------------------------------------------------------------------

    /**
     * Reads and saves the joined-listings display settings (display_mode, per_page)
     * stored in config/app.php.
     */
    public function settings(Request $request, array $params): Response
    {
        $config  = $this->app->getConfig();
        $session = $this->app->getSession();
        $baseUrl = $this->app->getBaseUrl();

        $success = (string) $session->get('lstr_flash_success', '');
        $error   = (string) $session->get('lstr_flash_error', '');
        $session->remove('lstr_flash_success');
        $session->remove('lstr_flash_error');

        if ($request->isPost()) {
            // CSRF validation
            $token = (string) $request->post('_csrf', '');
            if (!$this->app->getCSRF()->validate($token)) {
                $session->set('lstr_flash_error', 'Invalid CSRF token.');
                return Response::redirect($baseUrl . '/admin/joined/settings');
            }

            if ($request->post('reset_defaults') === '1') {
                $mode = 'all';
                $pp   = 10;
            } else {
                $mode = (string) $request->post('display_mode', 'all');
                if (!in_array($mode, ['all', 'network', 'category', 'status'], true)) {
                    $mode = 'all';
                }
                $pp = max(5, min(100, (int) $request->post('per_page', 10)));
            }

            $updated = $config->all();
            $updated['joined_display_mode'] = $mode;
            $updated['joined_per_page']     = $pp;

            $this->writeConfig($updated);

            $msg = $request->post('reset_defaults') === '1' ? 'Settings reset to defaults.' : 'Joined settings saved.';
            $session->set('lstr_flash_success', $msg);
            return Response::redirect($baseUrl . '/admin/joined/settings');
        }

        return $this->adminLayout('admin/joined/settings.php', 'Joined Settings', 'joined', [
            'display_mode' => (string) $config->get('joined_display_mode', 'all'),
            'per_page'     => (int) $config->get('joined_per_page', 10),
            'success'      => $success,
            'error'        => $error,
        ], '', [
            $this->crumb('Joined Listings', $this->adminUrl('/admin/joined')),
        ]);
    }

    // -----------------------------------------------------------------------
    // Builds categories_json from parallel POST arrays cat_network[]/cat_name[]
    // -----------------------------------------------------------------------

    /**
     * Assembles the JSON blob stored in lstr_joined.categories_json from the
     * parallel cat_network[] / cat_name[] POST arrays; auto-registers any
     * "other" network name via CategoryRepo::addCustomNetwork().
     *
     * @return string|null JSON string, or null when no valid entries are present
     */
    private function buildCategoriesJson(CategoryRepo $categoryRepo, Request $request): ?string
    {
        $networks = array_values((array) $request->post('cat_network', []));
        $names    = array_values((array) $request->post('cat_name', []));
        $others   = array_values((array) $request->post('cat_network_other', []));

        $entries = [];

        foreach ($networks as $i => $rawNetwork) {
            $network = trim((string) $rawNetwork);
            $name    = trim((string) ($names[$i] ?? ''));

            if ($network === 'other') {
                $customName = trim((string) ($others[$i] ?? ''));
                if ($customName === '') {
                    continue;
                }
                $categoryRepo->addCustomNetwork($customName);
                $network = $customName;
            }

            if ($network === '' || $name === '') {
                continue;
            }

            $entries[] = ['network' => $network, 'name' => $name];
        }

        return $entries !== [] ? (string) json_encode($entries, JSON_UNESCAPED_UNICODE) : null;
    }

    // -----------------------------------------------------------------------
    // Ensures public/uploads/joined/ exists with an .htaccess guard
    // -----------------------------------------------------------------------

    /**
     * Creates public/uploads/joined/ if absent and writes an .htaccess guard
     * that denies PHP execution; returns the absolute directory path.
     */
    private function ensureUploadDirectory(): string
    {
        return $this->ensureUploadSubdirectory('joined');
    }

    // -----------------------------------------------------------------------
    // Writes config array to config/app.php via safe temp-file rename
    // -----------------------------------------------------------------------

    /**
     * Writes the config array to config/app.php via a temp-file rename and
     * invalidates the OPcache entry so changes take effect immediately.
     */
    private function writeConfig(array $config): void
    {
        $configPath = $this->app->getBasePath() . '/config/app.php';
        $tmpPath    = $configPath . '.tmp';

        $export  = "<?php\n\ndeclare(strict_types=1);\n\nreturn ";
        $export .= var_export($config, true);
        $export .= ";\n";

        $bytes = file_put_contents($tmpPath, $export, LOCK_EX);

        if ($bytes === false) {
            throw new \RuntimeException('Could not write temp config file: ' . $tmpPath);
        }

        if (!rename($tmpPath, $configPath)) {
            unlink($tmpPath);
            throw new \RuntimeException('Could not rename temp config file to: ' . $configPath);
        }

        if (function_exists('opcache_invalidate')) {
            opcache_invalidate($configPath, true);
        }
    }

}
