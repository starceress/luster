<?php

declare(strict_types=1);

namespace Luster\Controllers\Admin;

use DateTimeImmutable;
use Luster\Core\Application;
use Luster\Core\Mailer;
use Luster\Core\RateLimit;
use Luster\Core\Request;
use Luster\Core\Response;
use Luster\Core\Validator;
use Luster\Repositories\AdminPasswordResetRepo;
use Luster\Repositories\AdminUserRepo;
use Luster\Repositories\ErrorLogRepo;
use Luster\Repositories\SiteSettingsRepo;

/**
 * Handles admin authentication: login, logout, remember-me,
 * forgot-password flow (email token), and password reset.
 *
 * Security properties:
 *  - CSRF token validated by CsrfMiddleware before this controller runs
 *  - Login attempts rate-limited via lstr_rate_limits (5 / 15 min per IP)
 *  - Forgot-password submissions rate-limited (3 / hr per IP)
 *  - Passwords verified with password_verify() (Argon2id)
 *  - Legacy MD5 hashes detected, verified, and immediately upgraded to Argon2id
 *  - Session ID regenerated on every successful login (prevents fixation)
 *  - Remember-me tokens stored as SHA-256 hashes, 30-day expiry
 *  - Reset tokens stored as SHA-256 hashes, 60-minute expiry, one-time use
 *  - Forgot-password response is always identical (no user enumeration)
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

        $isLocalDemo = ($request->query('demo') === '1')
            && in_array($request->ip(), ['127.0.0.1', '::1'], true);

        if (!$isLocalDemo && $session->has('lstr_admin_id')) {
            return Response::redirect($this->app->getBaseUrl() . '/admin');
        }

        $error    = (string) $session->get('lstr_flash_error', '');
        $username = (string) $session->get('lstr_flash_username', '');
        $session->remove('lstr_flash_error');
        $session->remove('lstr_flash_username');

        $html = $this->app->getView()->render('admin/auth/login.php', [
            'csrf_token'    => $this->app->getCSRF()->token(),
            'error'         => $error,
            'username'      => $username,
            'site_name'     => (string) $this->app->getConfig()->get('site_name', 'Luster'),
            'base_url'      => $this->app->getBaseUrl(),
            'selected_skin' => $this->selectedSkin(),
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

        if ($rateLimit->isBlocked($ip, RateLimit::ACTION_ADMIN_LOGIN)) {
            $session->set('lstr_flash_error', 'Too many failed login attempts. Please wait a few minutes and try again.');
            return Response::redirect($baseUrl . '/admin/login');
        }

        $username   = trim((string) $request->post('username', ''));
        $password   = (string) $request->post('password', '');
        $rememberMe = $request->post('remember_me') === '1';

        $v = new Validator(['username' => $username, 'password' => $password]);
        $v->field('username')->required();
        $v->field('password')->required();

        if ($v->fails()) {
            $session->set('lstr_flash_error', 'Please enter your username and password.');
            $session->set('lstr_flash_username', $username);
            return Response::redirect($baseUrl . '/admin/login');
        }

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

        $storedHash = (string) $user['password'];
        $verified   = false;

        if (strlen($storedHash) === 32 && ctype_xdigit($storedHash)) {
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

        $rateLimit->clear($ip, RateLimit::ACTION_ADMIN_LOGIN);
        $session->regenerate();
        $this->app->getCSRF()->regenerate();

        $userId = (int) $user['id'];
        $session->set('lstr_admin_id',       $userId);
        $session->set('lstr_admin_username', (string) $user['username']);
        $session->set('lstr_admin_email',    (string) $user['email']);

        try {
            (new AdminUserRepo($db))->updateLastLogin($userId);
        } catch (\Throwable) {
            // Column may not exist on older installs; migrate.php migration 25 adds it
        }

        // Remember-me cookie
        if ($rememberMe) {
            $rawToken  = bin2hex(random_bytes(32));
            $tokenHash = hash('sha256', $rawToken);
            $expiresAt = (new DateTimeImmutable())->modify('+30 days')->format('Y-m-d H:i:s');

            // One active token per user
            $db->prepare('DELETE FROM lstr_admin_remember_tokens WHERE admin_user_id = :uid')
               ->execute(['uid' => $userId]);
            $db->prepare(
                'INSERT INTO lstr_admin_remember_tokens (admin_user_id, token_hash, expires_at)
                 VALUES (:uid, :hash, :expires)'
            )->execute(['uid' => $userId, 'hash' => $tokenHash, 'expires' => $expiresAt]);

            $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
                    || (int) ($_SERVER['SERVER_PORT'] ?? 80) === 443
                    || strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';

            setcookie('lstr_remember', $userId . ':' . $rawToken, [
                'expires'  => time() + 86400 * 30,
                'path'     => '/',
                'httponly' => true,
                'secure'   => $isHttps,
                'samesite' => 'Strict',
            ]);
        }

        return Response::redirect($baseUrl . '/admin');
    }

    // -----------------------------------------------------------------------
    // POST /admin/logout
    // -----------------------------------------------------------------------

    public function logout(Request $request, array $params): Response
    {
        $session = $this->app->getSession();
        $userId  = (int) $session->get('lstr_admin_id', 0);
        $session->destroy();

        // Clear remember-me token if present
        if ($userId > 0) {
            $cookie = (string) ($_COOKIE['lstr_remember'] ?? '');
            if ($cookie !== '' && str_contains($cookie, ':')) {
                [, $rawToken] = explode(':', $cookie, 2);
                if ($rawToken !== '') {
                    try {
                        $this->app->getDatabase()->prepare(
                            'DELETE FROM lstr_admin_remember_tokens
                             WHERE admin_user_id = :uid AND token_hash = :hash'
                        )->execute(['uid' => $userId, 'hash' => hash('sha256', $rawToken)]);
                    } catch (\Throwable) {}
                }
            }

            $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
                    || (int) ($_SERVER['SERVER_PORT'] ?? 80) === 443
                    || strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';

            setcookie('lstr_remember', '', [
                'expires'  => time() - 3600,
                'path'     => '/',
                'httponly' => true,
                'secure'   => $isHttps,
                'samesite' => 'Strict',
            ]);
        }

        return Response::redirect($this->app->getBaseUrl() . '/admin/login');
    }

    // -----------------------------------------------------------------------
    // GET /admin/forgot
    // -----------------------------------------------------------------------

    public function forgotForm(Request $request, array $params): Response
    {
        $session = $this->app->getSession();

        $success = (string) $session->get('lstr_flash_success', '');
        $error   = (string) $session->get('lstr_flash_error', '');
        $session->remove('lstr_flash_success');
        $session->remove('lstr_flash_error');

        $html = $this->app->getView()->render('admin/auth/forgot.php', [
            'csrf_token'    => $this->app->getCSRF()->token(),
            'success'       => $success,
            'error'         => $error,
            'site_name'     => (string) $this->app->getConfig()->get('site_name', 'Luster'),
            'base_url'      => $this->app->getBaseUrl(),
            'selected_skin' => $this->selectedSkin(),
        ]);

        return Response::html($html);
    }

    // -----------------------------------------------------------------------
    // POST /admin/forgot
    // -----------------------------------------------------------------------

    public function forgot(Request $request, array $params): Response
    {
        $ip        = $request->ip();
        $rateLimit = $this->app->getRateLimit();
        $session   = $this->app->getSession();
        $baseUrl   = $this->app->getBaseUrl();

        if ($rateLimit->isBlocked($ip, RateLimit::ACTION_ADMIN_FORGOT)) {
            $session->set('lstr_flash_error', 'Too many requests. Please wait before trying again.');
            return Response::redirect($baseUrl . '/admin/forgot');
        }

        $identifier = trim((string) $request->post('identifier', ''));

        if ($identifier === '') {
            $session->set('lstr_flash_error', 'Please enter your username or email address.');
            return Response::redirect($baseUrl . '/admin/forgot');
        }

        $rateLimit->hitDefault($ip, RateLimit::ACTION_ADMIN_FORGOT);

        // Always show the same message regardless of whether the account exists
        $db   = $this->app->getDatabase();
        $stmt = $db->prepare(
            'SELECT id, username, email FROM lstr_admin_users
             WHERE username = :id OR email = :id2
             LIMIT 1'
        );
        $stmt->execute(['id' => $identifier, 'id2' => $identifier]);
        $user = $stmt->fetch();

        if ($user !== false) {
            $resetRepo = new AdminPasswordResetRepo($db);
            $resetRepo->deleteByUser((int) $user['id']);

            $rawToken  = bin2hex(random_bytes(32));
            $tokenHash = hash('sha256', $rawToken);
            $expiresAt = (new DateTimeImmutable())->modify('+60 minutes')->format('Y-m-d H:i:s');

            $resetRepo->create((int) $user['id'], $tokenHash, $expiresAt);

            $resetLink = $baseUrl . '/admin/reset/' . $rawToken;
            $siteName  = (string) $this->app->getConfig()->get('site_name', 'Luster');

            try {
                $mailer = new Mailer($this->app->getConfig());
                $mailer->send(
                    (string) $user['email'],
                    (string) $user['username'],
                    'Reset your ' . $siteName . ' admin password',
                    "Hello {$user['username']},\n\n"
                    . "A password reset was requested for your {$siteName} admin account.\n\n"
                    . "Click the link below to set a new password. This link expires in 60 minutes.\n\n"
                    . $resetLink . "\n\n"
                    . "If you did not request this, you can safely ignore this email.\n\n"
                    . "— {$siteName}"
                );
            } catch (\Throwable $e) {
                try {
                    (new ErrorLogRepo($db))->log(
                        'Admin password reset email failed: ' . $e->getMessage(),
                        'error',
                        'AuthController::forgot'
                    );
                } catch (\Throwable) {}
            }
        }

        $session->set('lstr_flash_success', 'If an account with that username or email exists, a reset link has been sent.');
        return Response::redirect($baseUrl . '/admin/forgot');
    }

    // -----------------------------------------------------------------------
    // GET /admin/reset/{token}
    // -----------------------------------------------------------------------

    public function resetForm(Request $request, array $params): Response
    {
        $rawToken  = (string) ($params['token'] ?? '');
        $baseUrl   = $this->app->getBaseUrl();
        $session   = $this->app->getSession();

        if ($rawToken === '') {
            return Response::redirect($baseUrl . '/admin/forgot');
        }

        $tokenHash = hash('sha256', $rawToken);
        $resetRepo = new AdminPasswordResetRepo($this->app->getDatabase());
        $tokenRow  = $resetRepo->findValidByToken($tokenHash);

        if ($tokenRow === null) {
            $session->set('lstr_flash_error', 'This reset link has expired or already been used. Please request a new one.');
            return Response::redirect($baseUrl . '/admin/forgot');
        }

        $error = (string) $session->get('lstr_flash_error', '');
        $session->remove('lstr_flash_error');

        $html = $this->app->getView()->render('admin/auth/reset.php', [
            'csrf_token'    => $this->app->getCSRF()->token(),
            'error'         => $error,
            'raw_token'     => $rawToken,
            'site_name'     => (string) $this->app->getConfig()->get('site_name', 'Luster'),
            'base_url'      => $baseUrl,
            'selected_skin' => $this->selectedSkin(),
        ]);

        return Response::html($html);
    }

    // -----------------------------------------------------------------------
    // POST /admin/reset/{token}
    // -----------------------------------------------------------------------

    public function reset(Request $request, array $params): Response
    {
        $rawToken = (string) ($params['token'] ?? '');
        $baseUrl  = $this->app->getBaseUrl();
        $session  = $this->app->getSession();

        if ($rawToken === '') {
            return Response::redirect($baseUrl . '/admin/forgot');
        }

        $tokenHash = hash('sha256', $rawToken);
        $db        = $this->app->getDatabase();
        $resetRepo = new AdminPasswordResetRepo($db);
        $tokenRow  = $resetRepo->findValidByToken($tokenHash);

        if ($tokenRow === null) {
            $session->set('lstr_flash_error', 'This reset link has expired or already been used. Please request a new one.');
            return Response::redirect($baseUrl . '/admin/forgot');
        }

        $newPassword     = (string) $request->post('new_password', '');
        $confirmPassword = (string) $request->post('new_password_confirm', '');

        $vp = new Validator(['new_password' => $newPassword]);
        $vp->field('new_password')->required()->minLength(12)->maxLength(255);
        if ($vp->fails()) {
            $session->set('lstr_flash_error', implode(' ', $vp->allErrors()));
            return Response::redirect($baseUrl . '/admin/reset/' . $rawToken);
        }

        if (!hash_equals($newPassword, $confirmPassword)) {
            $session->set('lstr_flash_error', 'Passwords do not match.');
            return Response::redirect($baseUrl . '/admin/reset/' . $rawToken);
        }

        $hash = password_hash($newPassword, PASSWORD_ARGON2ID);
        (new AdminUserRepo($db))->updatePassword((int) $tokenRow['admin_user_id'], $hash);
        $resetRepo->markUsed($tokenHash);

        // Invalidate any remember-me tokens for this user
        $db->prepare('DELETE FROM lstr_admin_remember_tokens WHERE admin_user_id = :uid')
           ->execute(['uid' => (int) $tokenRow['admin_user_id']]);

        // Rotate CSRF token — password changed, treat as privilege change
        $this->app->getCSRF()->regenerate();

        $session->set('lstr_flash_success', 'Your password has been reset. Please sign in with your new password.');
        return Response::redirect($baseUrl . '/admin/login');
    }

    private function selectedSkin(): string
    {
        try {
            $siteRepo = new SiteSettingsRepo($this->app->getDatabase());
            $settings = $siteRepo->getMany(['selected_skin', 'selected_skin_hash']);
            $slug     = $settings['selected_skin'] ?? '';

            if ($slug !== '' && !\Luster\Core\SkinCatalog::isBundled($slug)) {
                $skinFile    = $this->app->getBasePath() . '/public/assets/css/skins/' . $slug . '.css';
                $currentHash = is_file($skinFile) ? hash_file('sha256', $skinFile) : false;
                $storedHash  = $settings['selected_skin_hash'] ?? null;

                if ($currentHash === false || ($storedHash !== null && $currentHash !== $storedHash)) {
                    return '';
                }
            }

            return $slug;
        } catch (\Throwable) {
            return '';
        }
    }
}
