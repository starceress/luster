<?php

declare(strict_types=1);

namespace Luster\Controllers\Admin;

use Luster\Core\Request;
use Luster\Core\Response;
use Luster\Core\Validator;
use Luster\Repositories\CategoryRepo;

/**
 * Manages categories for joined listings.
 *
 * Routes:
 *   GET  /admin/categories          — list all categories
 *   POST /admin/categories/add      — insert new category
 *   POST /admin/categories/{id}/delete — delete category
 */
final class CategoryController extends AdminController
{

    // -----------------------------------------------------------------------
    // GET /admin/categories
    // -----------------------------------------------------------------------

    public function index(Request $request, array $params): Response
    {
        $repo    = new CategoryRepo($this->app->getDatabase());
        $source  = trim((string) $request->query('source',   ''));
        $search  = trim((string) $request->query('search',   ''));
        $catName = trim((string) $request->query('cat_name', ''));
        $page    = max(1, (int) $request->query('page', 1));
        $sort    = trim((string) $request->query('sort', 'source'));
        $dir     = trim((string) $request->query('dir', 'asc'));
        $perPage = max(5, min(100, (int) $this->app->getConfig()->get('categories_per_page', 10)));

        if (!in_array($sort, ['id', 'source', 'name'], true)) { $sort = 'source'; }
        if (!in_array($dir, ['asc', 'desc'], true)) { $dir = 'asc'; }

        $result        = $repo->paginate($perPage, $page, $source, $search, $sort, $dir, $catName);
        $customSources = $repo->getCustomSources();

        // Group all categories by source for the dropdown data attributes
        $allCats   = $repo->getAll();
        $tflCats   = array_values(array_filter($allCats, static fn($c) => $c->source === 'tfl'));
        $taflCats  = array_values(array_filter($allCats, static fn($c) => $c->source === 'tafl'));
        $customMap = [];
        foreach ($allCats as $c) {
            if ($c->source !== 'tfl' && $c->source !== 'tafl') {
                $customMap[$c->source][] = ['name' => $c->name];
            }
        }
        $tflJson    = json_encode(array_map(static fn($c) => ['name' => $c->name], $tflCats),  JSON_HEX_QUOT | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_TAG);
        $taflJson   = json_encode(array_map(static fn($c) => ['name' => $c->name], $taflCats), JSON_HEX_QUOT | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_TAG);
        $customJson = json_encode($customMap,  JSON_HEX_QUOT | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_TAG);

        $session = $this->app->getSession();
        $success = (string) $session->get('lstr_flash_success', '');
        $error   = (string) $session->get('lstr_flash_error', '');
        $session->remove('lstr_flash_success');
        $session->remove('lstr_flash_error');

        $seedHealth = $repo->seedHealth();

        return $this->adminLayout('admin/categories/index.php', 'Categories', 'categories', [
            'items'          => $result['items'],
            'total'          => $result['total'],
            'pages'          => $result['pages'],
            'page'           => $page,
            'total_pages'    => $result['pages'],
            'source'         => $source,
            'search'         => $search,
            'sort'           => $sort,
            'dir'            => $dir,
            'custom_sources' => $customSources,
            'seed_health'    => $seedHealth,
            'cat_name'       => $catName,
            'tfl_json'       => $tflJson,
            'tafl_json'      => $taflJson,
            'custom_json'    => $customJson,
            'success'        => $success,
            'error'          => $error,
        ]);
    }

    // -----------------------------------------------------------------------
    // GET /admin/categories/add
    // -----------------------------------------------------------------------

    public function addForm(Request $request, array $params): Response
    {

        $session = $this->app->getSession();
        $success     = (string) $session->get('lstr_flash_success', '');
        $error       = (string) $session->get('lstr_flash_error', '');
        $fieldErrors = json_decode((string) $session->get('lstr_flash_field_errors', '{}'), true);
        $session->remove('lstr_flash_success');
        $session->remove('lstr_flash_error');
        $session->remove('lstr_flash_field_errors');

        return $this->adminLayout('admin/categories/add.php', 'Add Category', 'categories', [
            'success'      => $success,
            'error'        => $error,
            'field_errors' => is_array($fieldErrors) ? $fieldErrors : [],
        ], '', [
            $this->crumb('Categories', $this->adminUrl('/admin/categories')),
        ]);
    }

    // -----------------------------------------------------------------------
    // POST /admin/categories/add
    // -----------------------------------------------------------------------

    public function add(Request $request, array $params): Response
    {
        $name    = trim((string) $request->post('name', ''));
        $baseUrl = $this->app->getBaseUrl();
        $session = $this->app->getSession();

        $v = new Validator(['name' => $name]);
        $v->field('name')->required()->maxLength(150);

        if ($v->fails()) {
            $session->set('lstr_flash_error', implode(' ', $v->allErrors()));
            $session->set('lstr_flash_field_errors', json_encode($v->errors()));
            return Response::redirect($baseUrl . '/admin/categories/add');
        }

        $categoryRepo = new CategoryRepo($this->app->getDatabase());
        $networkName  = trim((string) $request->post('network_name', ''));
        $source       = $networkName !== '' ? strtolower($networkName) : 'custom';
        $categoryRepo->add($name, $source);

        $session->set('lstr_flash_success', 'Category added.');
        return Response::redirect($baseUrl . '/admin/categories/add');
    }

    // -----------------------------------------------------------------------
    // GET /admin/categories/{id}/edit
    // -----------------------------------------------------------------------

    public function editForm(Request $request, array $params): Response
    {
        $id   = (int) ($params['id'] ?? 0);
        $repo = new CategoryRepo($this->app->getDatabase());
        $cat  = $repo->getById($id);

        if ($cat === null) {
            return Response::redirect($this->app->getBaseUrl() . '/admin/categories');
        }

        $session = $this->app->getSession();
        $success     = (string) $session->get('lstr_flash_success', '');
        $error       = (string) $session->get('lstr_flash_error', '');
        $fieldErrors = json_decode((string) $session->get('lstr_flash_field_errors', '{}'), true);
        $session->remove('lstr_flash_success');
        $session->remove('lstr_flash_error');
        $session->remove('lstr_flash_field_errors');

        return $this->adminLayout('admin/categories/edit.php', 'Edit Category', 'categories', [
            'cat'          => $cat,
            'success'      => $success,
            'error'        => $error,
            'field_errors' => is_array($fieldErrors) ? $fieldErrors : [],
        ], '', [
            $this->crumb('Categories', $this->adminUrl('/admin/categories')),
            $this->crumb($cat->name),
        ]);
    }

    // -----------------------------------------------------------------------
    // POST /admin/categories/{id}/edit
    // -----------------------------------------------------------------------

    public function edit(Request $request, array $params): Response
    {
        $id      = (int) ($params['id'] ?? 0);
        $name    = trim((string) $request->post('name', ''));
        $baseUrl = $this->app->getBaseUrl();
        $session = $this->app->getSession();

        $v = new Validator(['name' => $name]);
        $v->field('name')->required()->maxLength(150);

        if ($v->fails()) {
            $session->set('lstr_flash_error', implode(' ', $v->allErrors()));
            $session->set('lstr_flash_field_errors', json_encode($v->errors()));
            return Response::redirect($baseUrl . '/admin/categories/' . $id . '/edit');
        }

        $repo          = new CategoryRepo($this->app->getDatabase());
        $addNetwork    = trim((string) $request->post('add_network', 'no'));
        $networkName   = trim((string) $request->post('network_name', ''));
        $currentSource = trim((string) $request->post('current_source', 'custom'));
        $source        = ($addNetwork === 'yes' && $networkName !== '') ? strtolower($networkName) : $currentSource;
        $repo->update($id, $name, $source);

        $session->set('lstr_flash_success', 'Category updated successfully.');
        return Response::redirect($baseUrl . '/admin/categories/' . $id . '/edit');
    }

    // -----------------------------------------------------------------------
    // POST /admin/categories/seed
    // -----------------------------------------------------------------------

    public function seed(Request $request, array $params): Response
    {
        $taflOnly = (bool) (int) ($request->post('include_tafl', '0'));
        $repo     = new CategoryRepo($this->app->getDatabase());
        $inserted = $taflOnly
            ? $repo->backfillSeeds(false, true)
            : $repo->backfillSeeds(true, false);
        $session  = $this->app->getSession();

        $label = $taflOnly ? 'TAFL' : 'TFL';
        if ($inserted > 0) {
            $session->set('lstr_flash_success', "Added {$inserted} missing {$label} categor" . ($inserted === 1 ? 'y' : 'ies') . '.');
        } else {
            $session->set('lstr_flash_success', "{$label} categories are already complete — nothing to add.");
        }

        return Response::redirect($this->app->getBaseUrl() . '/admin/categories');
    }

    // -----------------------------------------------------------------------
    // POST /admin/categories/{id}/delete
    // -----------------------------------------------------------------------

    public function delete(Request $request, array $params): Response
    {
        $id      = (int) ($params['id'] ?? 0);
        $baseUrl = $this->app->getBaseUrl();
        $session = $this->app->getSession();

        $categoryRepo = new CategoryRepo($this->app->getDatabase());

        if ($categoryRepo->getById($id) === null) {
            return Response::notFound('Category not found.');
        }

        $categoryRepo->delete($id);

        $session->set('lstr_flash_success', 'Category deleted.');
        return Response::redirect($baseUrl . '/admin/categories');
    }

    // -----------------------------------------------------------------------
    // POST /admin/categories/bulk
    // -----------------------------------------------------------------------

    public function bulk(Request $request, array $params): Response
    {
        $baseUrl = $this->app->getBaseUrl();
        $session = $this->app->getSession();

        $ids = array_filter(
            array_map('intval', (array) $request->post('ids', [])),
            static fn(int $i): bool => $i > 0,
        );

        if ($ids === [] || $request->post('bulk_action') !== 'delete') {
            $session->set('lstr_flash_error', 'No items selected or invalid action.');
            return Response::redirect($baseUrl . '/admin/categories');
        }

        $repo  = new CategoryRepo($this->app->getDatabase());
        $count = 0;
        foreach ($ids as $id) {
            if ($repo->getById($id) !== null) {
                $repo->delete($id);
                ++$count;
            }
        }

        $session->set('lstr_flash_success', "Deleted {$count} categor" . ($count === 1 ? 'y' : 'ies') . '.');
        return Response::redirect($baseUrl . '/admin/categories');
    }

    // -----------------------------------------------------------------------
    // GET /admin/categories/settings
    // -----------------------------------------------------------------------

    public function settingsForm(Request $request, array $params): Response
    {
        $config  = $this->app->getConfig();
        $session = $this->app->getSession();

        $success = (string) $session->get('lstr_flash_success', '');
        $error   = (string) $session->get('lstr_flash_error', '');
        $session->remove('lstr_flash_success');
        $session->remove('lstr_flash_error');

        return $this->adminLayout('admin/categories/settings.php', 'Category Settings', 'categories', [
            'per_page' => (int) $config->get('categories_per_page', 10),
            'success'  => $success,
            'error'    => $error,
        ], '', [
            $this->crumb('Categories', $this->adminUrl('/admin/categories')),
            $this->crumb('Display Settings'),
        ]);
    }

    // -----------------------------------------------------------------------
    // POST /admin/categories/settings
    // -----------------------------------------------------------------------

    public function saveSettings(Request $request, array $params): Response
    {
        $baseUrl = $this->app->getBaseUrl();
        $session = $this->app->getSession();

        $token = (string) $request->post('_csrf', '');
        if (!$this->app->getCSRF()->validate($token)) {
            $session->set('lstr_flash_error', 'Invalid CSRF token.');
            return Response::redirect($baseUrl . '/admin/categories/settings');
        }

        if ($request->post('reset_defaults') === '1') {
            $pp = 10;
            $msg = 'Settings reset to defaults.';
        } else {
            $pp  = max(5, min(100, (int) $request->post('per_page', 10)));
            $msg = 'Category settings saved.';
        }

        $updated = $this->app->getConfig()->all();
        $updated['categories_per_page'] = $pp;
        $this->writeConfig($updated);

        $session->set('lstr_flash_success', $msg);
        return Response::redirect($baseUrl . '/admin/categories/settings');
    }

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
