<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 CRUD controller for admin user accounts. Provides add, edit, and delete actions
 for the admin accounts list on the Settings page. Passwords are hashed with
 Argon2id. Safety guards prevent deleting your own account or the last remaining
 admin account.
*/

declare(strict_types=1);

namespace Luster\Controllers\Admin;

use Luster\Core\Request;
use Luster\Core\Response;
use Luster\Core\Validator;
use Luster\Repositories\ActivityRepo;
use Luster\Repositories\AdminUserRepo;

/**
 * CRUD controller for admin user accounts.
 *
 * Handles creating, editing, and deleting the admin accounts shown on the
 * Settings page. All passwords are hashed with PASSWORD_ARGON2ID. Safety guards
 * prevent an admin from deleting their own account or removing the last
 * remaining account. Editing your own account refreshes the session identity
 * in-place; changing your own password also rotates the CSRF token.
 */
final class AdminUsersController extends AdminController
{

    // -----------------------------------------------------------------------
    // GET + POST /admin/admin-users/add
    // -----------------------------------------------------------------------

    /**
     * Renders the "Add Admin Account" form, repopulating fields and errors from flash.
     */
    public function addForm(Request $request, array $params): Response
    {
        $session = $this->app->getSession();
        $error       = (string) $session->get('lstr_flash_error', '');
        $old         = (array)  $session->get('lstr_flash_old',   []);
        $fieldErrors = json_decode((string) $session->get('lstr_flash_field_errors', '{}'), true);
        $session->remove('lstr_flash_error');
        $session->remove('lstr_flash_old');
        $session->remove('lstr_flash_field_errors');

        return $this->adminLayout('admin/settings/add.php', 'Admin Accounts', 'settings', [
            'error'        => $error,
            'old'          => $old,
            'field_errors' => is_array($fieldErrors) ? $fieldErrors : [],
        ], '', [
            $this->crumb('Settings', $this->adminUrl('/admin/settings')),
            $this->crumb('Admin Accounts'),
        ]);
    }

    /**
     * Processes the "Add Admin Account" form submission.
     *
     * Validates username, email, display name, and password (min 12 chars,
     * confirmed). On success, inserts the new account with an Argon2id password
     * hash and redirects to the accounts panel on the Settings page.
     */
    public function add(Request $request, array $params): Response
    {
        $baseUrl  = $this->app->getBaseUrl();
        $session  = $this->app->getSession();
        $username  = trim((string) $request->post('username',   ''));
        $email     = trim((string) $request->post('email',      ''));
        $displayName = trim((string) $request->post('display_name', ''));
        $password  = (string) $request->post('password',         '');
        $confirm   = (string) $request->post('password_confirm', '');

        $v = new Validator(['username' => $username, 'email' => $email, 'password' => $password, 'display_name' => $displayName]);
        $v->field('username')->required()->maxLength(50);
        $v->field('email')->required()->email()->maxLength(255);
        $v->field('password')->required()->minLength(12)->maxLength(255);
        $v->field('display_name')->required()->maxLength(100);

        if ($v->fails()) {
            $session->set('lstr_flash_error', implode(' ', $v->allErrors()));
            $session->set('lstr_flash_old', ['username' => $username, 'email' => $email, 'display_name' => $displayName]);
            $session->set('lstr_flash_field_errors', json_encode($v->errors()));
            return Response::redirect($baseUrl . '/admin/admin-users/add');
        }

        if ($password !== $confirm) {
            $session->set('lstr_flash_error', 'Passwords do not match.');
            $session->set('lstr_flash_old', ['username' => $username, 'email' => $email, 'display_name' => $displayName]);
            $session->set('lstr_flash_field_errors', json_encode(['password_confirm' => ['Passwords do not match.']]));
            return Response::redirect($baseUrl . '/admin/admin-users/add');
        }

        $repo = new AdminUserRepo($this->app->getDatabase());

        try {
            $repo->insert($username, $email, password_hash($password, PASSWORD_ARGON2ID), $displayName ?: null);
        } catch (\PDOException) {
            $session->set('lstr_flash_error', 'That username or email address is already in use.');
            $session->set('lstr_flash_old', ['username' => $username, 'email' => $email, 'display_name' => $displayName]);
            return Response::redirect($baseUrl . '/admin/admin-users/add');
        }

        $adminId = (int) $session->get('lstr_admin_id');
        (new ActivityRepo($this->app->getDatabase()))->log(
            $adminId, 'admin_user.add',
            "Created admin account: {$username}",
            $request->ip(),
        );

        $session->set('lstr_flash_success', "Admin account \"{$username}\" created.");
        return Response::redirect($baseUrl . '/admin/settings#panel-accounts');
    }

    // -----------------------------------------------------------------------
    // GET + POST /admin/admin-users/{id}/edit
    // -----------------------------------------------------------------------

    /**
     * Renders the "Edit Admin Account" form, pre-filled with the existing account values.
     */
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

        return $this->adminLayout('admin/settings/edit.php', 'Admin Accounts', 'settings', [
            'user'         => $user,
            'error'        => $error,
            'field_errors' => is_array($fieldErrors) ? $fieldErrors : [],
        ], '', [
            $this->crumb('Settings', $this->adminUrl('/admin/settings')),
            $this->crumb('Admin Accounts'),
            $this->crumb($user->username),
        ]);
    }

    /**
     * Processes the "Edit Admin Account" form submission.
     *
     * Always updates username, email, and display name. If the new_password field is
     * non-empty, validates it (min 12 chars, confirmed) and re-hashes with Argon2id,
     * then rotates the CSRF token as a privilege-change signal. If the edited account
     * belongs to the currently logged-in admin, the session identity is updated in-place.
     */
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

        $username  = trim((string) $request->post('username',   ''));
        $email     = trim((string) $request->post('email',      ''));
        $displayName = trim((string) $request->post('display_name', ''));
        $password  = (string) $request->post('new_password',         '');
        $confirm   = (string) $request->post('new_password_confirm', '');

        $v = new Validator(['username' => $username, 'email' => $email, 'display_name' => $displayName]);
        $v->field('username')->required()->maxLength(50);
        $v->field('email')->required()->email()->maxLength(255);
        $v->field('display_name')->required()->maxLength(100);
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
            $repo->updateProfile($id, $username, $email, $displayName ?: null);
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
            $request->ip(),
        );

        $session->set('lstr_flash_success', "Account \"{$username}\" updated.");
        return Response::redirect($baseUrl . '/admin/settings#panel-accounts');
    }

    // -----------------------------------------------------------------------
    // POST /admin/admin-users/{id}/delete
    // -----------------------------------------------------------------------

    /**
     * Deletes an admin account.
     *
     * Blocked by two safety guards: (1) you cannot delete your own account, and
     * (2) you cannot delete the last remaining admin account. Both conditions
     * return a flash error without making any database change.
     */
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
            $request->ip(),
        );

        $session->set('lstr_flash_success', "Account \"{$user->username}\" deleted.");
        return Response::redirect($baseUrl . '/admin/settings#panel-accounts');
    }

}
