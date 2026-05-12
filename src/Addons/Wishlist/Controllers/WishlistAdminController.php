<?php

declare(strict_types=1);

namespace Luster\Addons\Wishlist\Controllers;

use Luster\Addons\Wishlist\WishlistRepo;
use Luster\Controllers\Admin\AdminController;
use Luster\Core\Request;
use Luster\Core\Response;
use Luster\Repositories\ActivityRepo;

final class WishlistAdminController extends AdminController
{
    private const VALID_TYPES = ['wanted', 'top', 'granted', 'upcoming'];

    // -----------------------------------------------------------------------
    // GET /admin/wishlist
    // -----------------------------------------------------------------------

    public function index(Request $request, array $params): Response
    {
        $db      = $this->app->getDatabase();
        $session = $this->app->getSession();

        $repo = new WishlistRepo($db);

        $type      = trim((string) $request->query('type', 'all'));
        $filter    = in_array($type, self::VALID_TYPES, true) ? $type : 'all';
        $search    = trim((string) $request->query('search', ''));
        $filterCat = trim((string) $request->query('category', ''));

        $filtersActive = $search !== '' || $filterCat !== '';

        $entries    = $repo->getAll(
            $filter !== 'all' ? $filter : null,
            false,
            $search,
            $filterCat,
        );
        $typeCounts = $repo->countByType();
        $categories = $repo->getCategories();

        $success = (string) $session->get('lstr_flash_success', '');
        $error   = (string) $session->get('lstr_flash_error', '');
        $session->remove('lstr_flash_success');
        $session->remove('lstr_flash_error');

        return $this->adminLayout('admin/addons/wishlist/index.php', 'Wishlist', 'wishlist', [
            'entries'        => $entries,
            'filter_type'    => $filter,
            'type_counts'    => $typeCounts,
            'categories'     => $categories,
            'search'         => $search,
            'filter_cat'     => $filterCat,
            'filters_active' => $filtersActive,
            'success'        => $success,
            'error'          => $error,
        ]);
    }

    // -----------------------------------------------------------------------
    // GET + POST /admin/wishlist/add
    // -----------------------------------------------------------------------

    public function add(Request $request, array $params): Response
    {
        $db      = $this->app->getDatabase();
        $session = $this->app->getSession();
        $repo    = new WishlistRepo($db);

        if ($request->isPost()) {
            $data   = $this->collectFormData($request);
            $errors = $this->validate($data);

            if (!empty($errors)) {
                return $this->adminLayout('admin/addons/wishlist/add.php', 'Add Wishlist Entry', 'wishlist', [
                    'entry' => null,
                    'old'   => $data,
                    'error' => implode(' ', $errors),
                ], '', [
                    $this->crumb('Wishlist', $this->adminUrl('/admin/wishlist')),
                ]);
            }

            $id = $repo->insert($data);

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
        ], '', [
            $this->crumb('Wishlist', $this->adminUrl('/admin/wishlist')),
        ]);
    }

    // -----------------------------------------------------------------------
    // GET + POST /admin/wishlist/{id}/edit
    // -----------------------------------------------------------------------

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
            $data   = $this->collectFormData($request);
            $errors = $this->validate($data);

            if (!empty($errors)) {
                return $this->adminLayout('admin/addons/wishlist/add.php', 'Edit Wishlist Entry', 'wishlist', [
                    'entry' => $entry,
                    'old'   => $data,
                    'error' => implode(' ', $errors),
                ], '', [
                    $this->crumb('Wishlist', $this->adminUrl('/admin/wishlist')),
                    $this->crumb($entry->subject),
                ]);
            }

            $repo->update($id, $data);

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
        ], '', [
            $this->crumb('Wishlist', $this->adminUrl('/admin/wishlist')),
            $this->crumb($entry->subject),
        ]);
    }

    // -----------------------------------------------------------------------
    // POST /admin/wishlist/{id}/delete
    // -----------------------------------------------------------------------

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
    // Helpers
    // -----------------------------------------------------------------------

    private function collectFormData(Request $request): array
    {
        return [
            'subject'     => trim((string) $request->post('subject', '')),
            'url'         => trim((string) $request->post('url', '')),
            'image'       => trim((string) $request->post('image', '')),
            'category'    => trim((string) $request->post('category', '')),
            'type'        => trim((string) $request->post('type', 'wanted')),
            'description' => trim((string) $request->post('description', '')),
            'added_at'    => trim((string) $request->post('added_at', '')),
            'sort_order'  => max(0, (int) $request->post('sort_order', 0)),
            'active'      => $request->post('active') === '1',
        ];
    }

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

        if (strlen($data['category']) > 100) {
            $errors[] = 'Category must be 100 characters or fewer.';
        }

        if (!in_array($data['type'], self::VALID_TYPES, true)) {
            $errors[] = 'Invalid type selected.';
        }

        if ($data['description'] !== '' && strlen($data['description']) > 2000) {
            $errors[] = 'Description must be 2000 characters or fewer.';
        }

        if ($data['image'] !== '') {
            if (!filter_var($data['image'], FILTER_VALIDATE_URL)
                || !preg_match('/^https?:\/\//i', $data['image'])) {
                $errors[] = 'Image URL must be a valid HTTP or HTTPS address.';
            } elseif (strlen($data['image']) > 255) {
                $errors[] = 'Image URL must be 255 characters or fewer.';
            }
        }

        if ($data['added_at'] !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $data['added_at'])) {
            $errors[] = 'Date must be in YYYY-MM-DD format.';
        }

        return $errors;
    }
}
