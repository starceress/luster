<?php

declare(strict_types=1);

namespace Luster\Controllers\Admin;

use Luster\Core\Application;
use Luster\Core\RateLimit;
use Luster\Core\Request;
use Luster\Core\Response;
use Luster\Core\Validator;

/**
 * Handles admin authentication: login form, login submission, and logout.
 *
 * Security properties:
 *  - CSRF token validated by CsrfMiddleware before this controller runs
 *  - Login attempts rate-limited via lstr_rate_limits (5 / 15 min per IP)
 *  - Passwords verified with password_verify() (Argon2id)
 *  - Legacy MD5 hashes detected, verified, and immediately upgraded to Argon2id
 *  - Session ID regenerated on every successful login (prevents fixation)
 *  - Flash messages via session to support POST-Redirect-GET on failure
 */
final class AuthController
{
    public function __construct(private readonly Application $app) {}

    // -----------------------------------------------------------------------
    // GET /admin/login
    // -----------------------------------------------------------------------

    public function loginForm(Request $request, array $params): Response
    {
        $session = $this->app->getSession();

        // Already authenticated → send straight to the dashboard
        if ($session->has('lstr_admin_id')) {
            return Response::redirect($this->app->getBaseUrl() . '/admin');
        }

        // Consume flash data written on a failed login attempt
        $error    = (string) $session->get('lstr_flash_error', '');
        $username = (string) $session->get('lstr_flash_username', '');
        $session->remove('lstr_flash_error');
        $session->remove('lstr_flash_username');

        $html = $this->app->getView()->render('admin/auth/login.php', [
            'csrf_token' => $this->app->getCSRF()->token(),
            'error'      => $error,
            'username'   => $username,
            'app_name'   => (string) $this->app->getConfig()->get('app_name', 'Luster'),
        ]);

        return Response::html($html);
    }

    // -----------------------------------------------------------------------
    // POST /admin/login
    // -----------------------------------------------------------------------

    public function login(Request $request, array $params): Response
    {
        $ip        = $request->ip();
        $rateLimit = $this->app->getRateLimit();
        $session   = $this->app->getSession();
        $baseUrl   = $this->app->getBaseUrl();

        // Check rate limit before touching the database
        if ($rateLimit->isBlocked($ip, RateLimit::ACTION_ADMIN_LOGIN)) {
            $session->set('lstr_flash_error', 'Too many failed login attempts. Please wait a few minutes and try again.');
            return Response::redirect($baseUrl . '/admin/login');
        }

        $username = trim((string) $request->post('username', ''));
        $password = (string) $request->post('password', '');

        // Basic presence validation (content validated against DB next)
        $v = new Validator(['username' => $username, 'password' => $password]);
        $v->field('username')->required();
        $v->field('password')->required();

        if ($v->fails()) {
            $session->set('lstr_flash_error', 'Please enter your username and password.');
            $session->set('lstr_flash_username', $username);
            return Response::redirect($baseUrl . '/admin/login');
        }

        // Fetch the user record
        $db   = $this->app->getDatabase();
        $stmt = $db->prepare(
            'SELECT id, username, email, password FROM lstr_admin_users WHERE username = :username'
        );
        $stmt->execute(['username' => $username]);
        $user = $stmt->fetch();

        if ($user === false) {
            $rateLimit->hitDefault($ip, RateLimit::ACTION_ADMIN_LOGIN);
            $session->set('lstr_flash_error', 'Invalid username or password.');
            $session->set('lstr_flash_username', $username);
            return Response::redirect($baseUrl . '/admin/login');
        }

        // Verify password — detect and upgrade legacy MD5 hashes
        $storedHash = (string) $user['password'];
        $verified   = false;

        if (strlen($storedHash) === 32 && ctype_xdigit($storedHash)) {
            // Legacy Enthusiast MD5 hash — verify then re-hash with Argon2id
            if (hash_equals($storedHash, md5($password))) {
                $verified    = true;
                $newHash     = password_hash($password, PASSWORD_ARGON2ID);
                $upgradeStmt = $db->prepare(
                    'UPDATE lstr_admin_users SET password = :password WHERE id = :id'
                );
                $upgradeStmt->execute(['password' => $newHash, 'id' => (int) $user['id']]);
            }
        } else {
            $verified = password_verify($password, $storedHash);
        }

        if (!$verified) {
            $rateLimit->hitDefault($ip, RateLimit::ACTION_ADMIN_LOGIN);
            $session->set('lstr_flash_error', 'Invalid username or password.');
            $session->set('lstr_flash_username', $username);
            return Response::redirect($baseUrl . '/admin/login');
        }

        // Authentication successful
        $rateLimit->clear($ip, RateLimit::ACTION_ADMIN_LOGIN);
        $session->regenerate();
        $session->set('lstr_admin_id',       (int)    $user['id']);
        $session->set('lstr_admin_username', (string) $user['username']);
        $session->set('lstr_admin_email',    (string) $user['email']);

        return Response::redirect($baseUrl . '/admin');
    }

    // -----------------------------------------------------------------------
    // POST /admin/logout
    // -----------------------------------------------------------------------

    public function logout(Request $request, array $params): Response
    {
        $this->app->getSession()->destroy();

        return Response::redirect($this->app->getBaseUrl() . '/admin/login');
    }
}
