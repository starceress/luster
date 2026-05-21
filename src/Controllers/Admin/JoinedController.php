<?php

declare(strict_types=1);

namespace Luster\Controllers\Admin;

use Luster\Core\Request;
use Luster\Core\Response;
use Luster\Core\Validator;
use Luster\Repositories\CategoryRepo;
use Luster\Repositories\JoinedListingRepo;

/**
 * Manages the site owner's joined listings list.
 *
 * Routes:
 *   GET  /admin/joined              — list, grouped by category
 *   GET  /admin/joined/add          — add form
 *   POST /admin/joined/add          — insert
 *   GET  /admin/joined/{id}/edit    — edit form
 *   POST /admin/joined/{id}/edit    — update
 *   POST /admin/joined/{id}/delete  — delete
 *   POST /admin/joined/{id}/toggle  — toggle active/inactive
 */
final class JoinedController extends AdminController
{

    // -----------------------------------------------------------------------
    // GET /admin/joined
    // -----------------------------------------------------------------------

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
        if (!in_array($sort, ['name', 'category', 'date_joined', 'status'], true)) { $sort = ''; }
        if (!in_array($dir, ['asc', 'desc'], true)) { $dir = 'asc'; }

        $networkCategories = $repo->getNetworkCategories();
        if ($filterNetwork !== '' && isset($networkCategories[$filterNetwork])) {
            $categories = $networkCategories[$filterNetwork];
        } else {
            $categories = $repo->getCategories();
        }
        $tflCats         = $categoryRepo->getBySource('tfl');
        $taflCats        = $categoryRepo->getBySource('tafl');
        $customNetworks  = $categoryRepo->getCustomNetworks();

        if ($search !== '' || $filterNetwork !== '') {
            $result = $repo->search($search, $filterNetwork, $perPage, $page, $filterCat);
        } else {
            $result = $repo->getAll($perPage, $page, $filterCat);
        }

        $joined     = $result['items'];
        $totalPages = $result['pages'];

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
            'filter_cat'         => $filterCat,
            'filter_network'     => $filterNetwork,
            'display_mode'       => $displayMode,
            'search'             => $search,
            'per_page'           => $perPage,
            'page'               => $page,
            'total_pages'        => $totalPages,
            'group_counts'       => $repo->countByGroup($displayMode),
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

    public function add(Request $request, array $params): Response
    {
        $repo         = new JoinedListingRepo($this->app->getDatabase());
        $categoryRepo = new CategoryRepo($this->app->getDatabase());
        $session      = $this->app->getSession();
        $baseUrl      = $this->app->getBaseUrl();

        if ($request->isPost()) {
            $name       = trim((string) $request->post('name', ''));
            $url        = trim((string) $request->post('url', ''));
            $category   = trim((string) $request->post('category', ''));
            $dateJoined = trim((string) $request->post('date_joined', ''));
            $active     = $request->post('active') === '1';
            $status     = trim((string) $request->post('status', 'approved'));

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
            $v->field('url')->required()->url()->maxLength(500);

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
            $name       = trim((string) $request->post('name', ''));
            $url        = trim((string) $request->post('url', ''));
            $category   = trim((string) $request->post('category', ''));
            $dateJoined = trim((string) $request->post('date_joined', ''));
            $active      = $request->post('active') === '1';
            $deleteImage = $request->post('delete_image') === '1';
            $status      = trim((string) $request->post('status', 'approved'));

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
            $v->field('url')->required()->url()->maxLength(500);

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
    // POST /admin/joined/{id}/delete
    // -----------------------------------------------------------------------

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

        $action = (string) $request->post('bulk_action', '');
        $ids    = array_filter(
            array_map('intval', (array) $request->post('ids', [])),
            static fn(int $i): bool => $i > 0,
        );

        if ($ids === [] || !in_array($action, ['approve', 'pending', 'delete'], true)) {
            $session->set('lstr_flash_error', 'No items selected or invalid action.');
            return Response::redirect($baseUrl . '/admin/joined');
        }

        $db    = $this->app->getDatabase();
        $repo  = new JoinedListingRepo($db);
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
        $session->set('lstr_flash_success', ucfirst($action) . "d {$count} {$noun}.");

        return Response::redirect($baseUrl . '/admin/joined');
    }

    // -----------------------------------------------------------------------
    // GET + POST /admin/joined/settings
    // -----------------------------------------------------------------------

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

    private function ensureUploadDirectory(): string
    {
        $uploadDir = dirname(__DIR__, 3) . '/public/uploads/joined';

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

    // -----------------------------------------------------------------------
    // Writes config array to config/app.php via safe temp-file rename
    // -----------------------------------------------------------------------

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
