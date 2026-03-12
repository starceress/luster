<?php

declare(strict_types=1);

namespace Luster\Controllers\Admin;

use Luster\Core\Application;
use Luster\Core\Request;
use Luster\Core\Response;
use Luster\Core\Validator;
use Luster\Repositories\CategoryRepo;

/**
 * Manages categories for joined fanlistings.
 *
 * Routes:
 *   GET  /admin/categories          — list all categories
 *   POST /admin/categories/add      — insert new category
 *   POST /admin/categories/{id}/delete — delete category
 */
final class CategoryController
{
    public function __construct(private readonly Application $app) {}

    // -----------------------------------------------------------------------
    // GET /admin/categories
    // -----------------------------------------------------------------------

    public function index(Request $request, array $params): Response
    {
        $repo    = new CategoryRepo($this->app->getDatabase());
        $source  = trim((string) $request->query('source', ''));
        $search  = trim((string) $request->query('search', ''));
        $page    = max(1, (int) $request->query('page', 1));
        $perPage = 10;

        $result        = $repo->paginate($perPage, $page, $source, $search);
        $customSources = $repo->getCustomSources();

        $session = $this->app->getSession();
        $success = (string) $session->get('lstr_flash_success', '');
        $error   = (string) $session->get('lstr_flash_error', '');
        $session->remove('lstr_flash_success');
        $session->remove('lstr_flash_error');

        $all = $repo->getAll();

        return $this->layout('admin/categories/index.php', [
            'items'          => $result['items'],
            'total'          => $result['total'],
            'pages'          => $result['pages'],
            'page'           => $page,
            'total_pages'    => $result['pages'],
            'source'         => $source,
            'search'         => $search,
            'custom_sources' => $customSources,
            'all_cats'       => $all,
            'success'        => $success,
            'error'          => $error,
        ], 'Categories', 'categories');
    }

    // -----------------------------------------------------------------------
    // GET /admin/categories/add
    // -----------------------------------------------------------------------

    public function addForm(Request $request, array $params): Response
    {
        $repo = new CategoryRepo($this->app->getDatabase());
        $all  = $repo->getAll();

        $session = $this->app->getSession();
        $success = (string) $session->get('lstr_flash_success', '');
        $error   = (string) $session->get('lstr_flash_error', '');
        $session->remove('lstr_flash_success');
        $session->remove('lstr_flash_error');

        return $this->layout('admin/categories/add.php', [
            'all_cats'   => $all,
            'csrf_token' => $this->app->getSession()->get('csrf_token', ''),
            'success'    => $success,
            'error'      => $error,
        ], 'Add Category', 'categories');
    }

    // -----------------------------------------------------------------------
    // POST /admin/categories/add
    // -----------------------------------------------------------------------

    public function add(Request $request, array $params): Response
    {
        $name     = trim((string) $request->post('name', ''));
        $parentId = (int) $request->post('parent_id', 0);
        $baseUrl  = $this->app->getBaseUrl();
        $session  = $this->app->getSession();

        $v = new Validator(['name' => $name]);
        $v->field('name')->required()->maxLength(150);

        if ($v->fails()) {
            $session->set('lstr_flash_error', implode(' ', $v->allErrors()));
            return Response::redirect($baseUrl . '/admin/categories');
        }

        $categoryRepo = new CategoryRepo($this->app->getDatabase());

        // Guard: the selected parent must be top-level (max 1 level of nesting)
        $resolvedParentId = $parentId > 0 ? $parentId : null;
        if ($resolvedParentId !== null) {
            $parentCat = $categoryRepo->getById($resolvedParentId);
            if ($parentCat !== null && $parentCat->parentId !== null) {
                $session->set('lstr_flash_error', 'The selected parent is itself a sub-category. Only top-level categories may be used as parents.');
                return Response::redirect($baseUrl . '/admin/categories/add');
            }
        }

        $networkName = trim((string) $request->post('network_name', ''));
        $source      = $networkName !== '' ? strtolower($networkName) : 'custom';
        $categoryRepo->add($name, $resolvedParentId, $source);

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

        $all = $repo->getAll();

        $session = $this->app->getSession();
        $success = (string) $session->get('lstr_flash_success', '');
        $error   = (string) $session->get('lstr_flash_error', '');
        $session->remove('lstr_flash_success');
        $session->remove('lstr_flash_error');

        return $this->layout('admin/categories/edit.php', [
            'cat'        => $cat,
            'all_cats'   => $all,
            'csrf_token' => $this->app->getSession()->get('csrf_token', ''),
            'success'    => $success,
            'error'      => $error,
        ], 'Edit Category', 'categories');
    }

    // -----------------------------------------------------------------------
    // POST /admin/categories/{id}/edit
    // -----------------------------------------------------------------------

    public function edit(Request $request, array $params): Response
    {
        $id       = (int) ($params['id'] ?? 0);
        $name     = trim((string) $request->post('name', ''));
        $parentId = $request->post('parent_id', '') !== '' ? (int) $request->post('parent_id', 0) : null;
        $baseUrl  = $this->app->getBaseUrl();
        $session  = $this->app->getSession();

        $v = new Validator(['name' => $name]);
        $v->field('name')->required()->maxLength(150);

        if ($v->fails()) {
            $session->set('lstr_flash_error', 'Could not save changes: ' . implode(' ', $v->allErrors()));
            return Response::redirect($baseUrl . '/admin/categories/' . $id . '/edit');
        }

        $repo = new CategoryRepo($this->app->getDatabase());

        // Guard: cannot set a category as its own parent
        if ($parentId !== null && $parentId === $id) {
            $session->set('lstr_flash_error', 'A category cannot be its own parent.');
            return Response::redirect($baseUrl . '/admin/categories/' . $id . '/edit');
        }

        // Guard: cannot make a category a child if it already has children (max 2 levels)
        if ($parentId !== null && $repo->hasChildren($id)) {
            $session->set('lstr_flash_error', 'This category has sub-categories and cannot itself become a sub-category. Move or delete its children first.');
            return Response::redirect($baseUrl . '/admin/categories/' . $id . '/edit');
        }

        // Guard: the selected parent must be top-level (no deeper than 1 level of nesting)
        if ($parentId !== null) {
            $parentCat = $repo->getById($parentId);
            if ($parentCat !== null && $parentCat->parentId !== null) {
                $session->set('lstr_flash_error', 'The selected parent is itself a sub-category. Only top-level categories may be used as parents.');
                return Response::redirect($baseUrl . '/admin/categories/' . $id . '/edit');
            }
        }

        $addNetwork    = trim((string) $request->post('add_network', 'no'));
        $networkName   = trim((string) $request->post('network_name', ''));
        $currentSource = trim((string) $request->post('current_source', 'custom'));
        $source        = ($addNetwork === 'yes' && $networkName !== '') ? strtolower($networkName) : $currentSource;
        $repo->update($id, $name, $parentId, $source);

        $session->set('lstr_flash_success', 'Category updated successfully.');
        return Response::redirect($baseUrl . '/admin/categories/' . $id . '/edit');
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
