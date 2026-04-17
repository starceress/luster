<?php

declare(strict_types=1);

namespace Luster\Controllers\Admin;

use Luster\Core\Request;
use Luster\Core\Response;
use Luster\Core\Validator;
use Luster\Repositories\ActivityRepo;
use Luster\Repositories\AdminUserRepo;

/**
 * Admin user management — add, edit, delete.
 *
 * The account list lives in the Settings page (Admin Details tab).
 * These handlers cover the add/edit sub-pages and the delete POST action.
 *
 * GET  /admin/admin-users/add          → addForm()
 * POST /admin/admin-users/add          → add()
 * GET  /admin/admin-users/{id}/edit    → editForm()
 * POST /admin/admin-users/{id}/edit    → edit()
 * POST /admin/admin-users/{id}/delete  → delete()
 *
 * Guards:
 *  - Cannot delete your own account
 *  - Cannot delete the last remaining admin account
 */
final class AdminUsersController extends AdminController
{

    // -----------------------------------------------------------------------
    // GET + POST /admin/admin-users/add
    // -----------------------------------------------------------------------

    public function addForm(Request $request, array $params): Response
    {
        $session = $this->app->getSession();
        $error       = (string) $session->get('lstr_flash_error', '');
        $old         = (array)  $session->get('lstr_flash_old',   []);
        $fieldErrors = json_decode((string) $session->get('lstr_flash_field_errors', '{}'), true);
        $session->remove('lstr_flash_error');
        $session->remove('lstr_flash_old');
        $session->remove('lstr_flash_field_errors');

        return $this->adminLayout('admin/admin-users/add.php', 'Admin Accounts', 'settings', [
            'error'        => $error,
            'old'          => $old,
            'field_errors' => is_array($fieldErrors) ? $fieldErrors : [],
        ]);
    }

    public function add(Request $request, array $params): Response
    {
        $baseUrl  = $this->app->getBaseUrl();
        $session  = $this->app->getSession();
        $username = trim((string) $request->post('username', ''));
        $email    = trim((string) $request->post('email',    ''));
        $password = (string) $request->post('password',         '');
        $confirm  = (string) $request->post('password_confirm', '');

        $v = new Validator(['username' => $username, 'email' => $email, 'password' => $password]);
        $v->field('username')->required()->maxLength(50);
        $v->field('email')->required()->email()->maxLength(255);
        $v->field('password')->required()->minLength(12)->maxLength(255);

        if ($v->fails()) {
            $session->set('lstr_flash_error', implode(' ', $v->allErrors()));
            $session->set('lstr_flash_old', ['username' => $username, 'email' => $email]);
            $session->set('lstr_flash_field_errors', json_encode($v->errors()));
            return Response::redirect($baseUrl . '/admin/admin-users/add');
        }

        if ($password !== $confirm) {
            $session->set('lstr_flash_error', 'Passwords do not match.');
            $session->set('lstr_flash_old', ['username' => $username, 'email' => $email]);
            $session->set('lstr_flash_field_errors', json_encode(['password_confirm' => ['Passwords do not match.']]));
            return Response::redirect($baseUrl . '/admin/admin-users/add');
        }

        $repo = new AdminUserRepo($this->app->getDatabase());

        try {
            $repo->insert($username, $email, password_hash($password, PASSWORD_ARGON2ID));
        } catch (\PDOException) {
            $session->set('lstr_flash_error', 'That username or email address is already in use.');
            $session->set('lstr_flash_old', ['username' => $username, 'email' => $email]);
            return Response::redirect($baseUrl . '/admin/admin-users/add');
        }

        $adminId = (int) $session->get('lstr_admin_id');
        (new ActivityRepo($this->app->getDatabase()))->log(
            $adminId, 'admin_user.add',
            "Created admin account: {$username}",
        );

        $session->set('lstr_flash_success', "Admin account <strong>" . htmlspecialchars($username, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . "</strong> created.");
        return Response::redirect($baseUrl . '/admin/settings#panel-accounts');
    }

    // -----------------------------------------------------------------------
    // GET + POST /admin/admin-users/{id}/edit
    // -----------------------------------------------------------------------

    public function editForm(Request $request, array $params): Response
    {
        $id      = (int) ($params['id'] ?? 0);
        $repo    = new AdminUserRepo($this->app->getDatabase());
        $user    = $repo->getById($id);
        $session = $this->app->getSession();

        if ($user === null) {
            return Response::notFound('Admin user not found.');
        }

        $error       = (string) $session->get('lstr_flash_error', '');
        $fieldErrors = json_decode((string) $session->get('lstr_flash_field_errors', '{}'), true);
        $session->remove('lstr_flash_error');
        $session->remove('lstr_flash_field_errors');

        return $this->adminLayout('admin/admin-users/edit.php', 'Admin Accounts', 'settings', [
            'user'         => $user,
            'error'        => $error,
            'field_errors' => is_array($fieldErrors) ? $fieldErrors : [],
        ]);
    }

    public function edit(Request $request, array $params): Response
    {
        $id      = (int) ($params['id'] ?? 0);
        $baseUrl = $this->app->getBaseUrl();
        $session = $this->app->getSession();
        $repo    = new AdminUserRepo($this->app->getDatabase());
        $user    = $repo->getById($id);

        if ($user === null) {
            return Response::notFound('Admin user not found.');
        }

        $username = trim((string) $request->post('username', ''));
        $email    = trim((string) $request->post('email',    ''));
        $password = (string) $request->post('new_password',         '');
        $confirm  = (string) $request->post('new_password_confirm', '');

        $v = new Validator(['username' => $username, 'email' => $email]);
        $v->field('username')->required()->maxLength(50);
        $v->field('email')->required()->email()->maxLength(255);
        if ($password !== '') {
            $v->field('new_password')->minLength(12)->maxLength(255);
        }

        if ($v->fails()) {
            $session->set('lstr_flash_error', implode(' ', $v->allErrors()));
            $session->set('lstr_flash_field_errors', json_encode($v->errors()));
            return Response::redirect($baseUrl . '/admin/admin-users/' . $id . '/edit');
        }

        if ($password !== '' && $password !== $confirm) {
            $session->set('lstr_flash_error', 'Passwords do not match.');
            $session->set('lstr_flash_field_errors', json_encode(['new_password_confirm' => ['Passwords do not match.']]));
            return Response::redirect($baseUrl . '/admin/admin-users/' . $id . '/edit');
        }

        try {
            $repo->updateProfile($id, $username, $email);
        } catch (\PDOException) {
            $session->set('lstr_flash_error', 'That username or email address is already in use.');
            return Response::redirect($baseUrl . '/admin/admin-users/' . $id . '/edit');
        }

        if ($password !== '') {
            $repo->updatePassword($id, password_hash($password, PASSWORD_ARGON2ID));
            // Rotate CSRF token — password changed, treat as privilege change
            $this->app->getCSRF()->regenerate();
        }

        // If the logged-in user edited their own account, refresh session identity
        $currentId = (int) $session->get('lstr_admin_id');
        if ($id === $currentId) {
            $session->set('lstr_admin_username', $username);
            $session->set('lstr_admin_email',    $email);
        }

        $adminId = (int) $session->get('lstr_admin_id');
        (new ActivityRepo($this->app->getDatabase()))->log(
            $adminId, 'admin_user.edit',
            "Edited admin account: {$username}",
        );

        $session->set('lstr_flash_success', "Account <strong>" . htmlspecialchars($username, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . "</strong> updated.");
        return Response::redirect($baseUrl . '/admin/settings#panel-accounts');
    }

    // -----------------------------------------------------------------------
    // POST /admin/admin-users/{id}/delete
    // -----------------------------------------------------------------------

    public function delete(Request $request, array $params): Response
    {
        $id      = (int) ($params['id'] ?? 0);
        $baseUrl = $this->app->getBaseUrl();
        $session = $this->app->getSession();

        $currentId = (int) $session->get('lstr_admin_id');

        if ($id === $currentId) {
            $session->set('lstr_flash_error', 'You cannot delete your own account.');
            return Response::redirect($baseUrl . '/admin/settings#panel-accounts');
        }

        $repo = new AdminUserRepo($this->app->getDatabase());

        if ($repo->count() <= 1) {
            $session->set('lstr_flash_error', 'Cannot delete the last admin account.');
            return Response::redirect($baseUrl . '/admin/settings#panel-accounts');
        }

        $user = $repo->getById($id);
        if ($user === null) {
            $session->set('lstr_flash_error', 'Admin user not found.');
            return Response::redirect($baseUrl . '/admin/settings#panel-accounts');
        }

        $repo->delete($id);

        $adminId = (int) $session->get('lstr_admin_id');
        (new ActivityRepo($this->app->getDatabase()))->log(
            $adminId, 'admin_user.delete',
            "Deleted admin account: {$user->username}",
        );

        $session->set('lstr_flash_success', "Account <strong>" . htmlspecialchars($user->username, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . "</strong> deleted.");
        return Response::redirect($baseUrl . '/admin/settings#panel-accounts');
    }

}
