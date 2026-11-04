<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Handles all admin authentication flows: login, logout, remember-me cookie,
 forgot-password (email a time-limited reset token), and password reset.
 Rate-limiting, session regeneration, and Argon2id hashing are enforced here.
 Legacy MD5 password hashes are auto-upgraded on successful login.
*/

declare(strict_types=1);

namespace Luster\Controllers\Admin;

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
    /**
     * Fixed Argon2id hash with no corresponding real password, verified
     * against on the "username not found" login path so it costs
     * approximately the same CPU time as a real password_verify() call on
     * the "found, wrong password" path — closes the timing side-channel an
     * attacker could otherwise use to enumerate valid admin usernames
     * (the error message is already identical for both cases; only the
     * timing differed).
     */
    private const DUMMY_PASSWORD_HASH = '$argon2id$v=19$m=65536,t=4,p=1$VEV6R1pMWmRlNEI1djU5Vg$zGjkDtUJgrb8QVRA6FHV7pw53C6mkNRuK7RFBBuCWYk';

    public function __construct(private readonly Application $app) {}

    // -----------------------------------------------------------------------
    // GET /admin/login
    // -----------------------------------------------------------------------

    /**
     * Renders the login page, redirecting already-authenticated admins to the dashboard.
     */
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
            'csrf_token'          => $this->app->getCSRF()->token(),
            'error'               => $error,
            'username'            => $username,
            'site_name'           => (string) $this->app->getConfig()->get('site_name', 'Luster'),
            'base_url'            => $this->app->getBaseUrl(),
            'selected_skin'       => $this->selectedSkin(),
            'admin_default_theme' => $this->adminDefaultTheme(),
        ]);

        return Response::html($html);
    }

    // -----------------------------------------------------------------------
    // POST /admin/login
    // -----------------------------------------------------------------------

    /**
     * Validates credentials, upgrades legacy MD5 hashes, issues a session,
     * and optionally writes a 30-day remember-me cookie.
     */
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
            'SELECT id, username, email, display_name, password FROM lstr_admin_users WHERE username = :username'
        );
        $stmt->execute(['username' => $username]);
        $user = $stmt->fetch();

        if ($user === false) {
            // Dummy verify against a fixed hash, same cost as the real
            // password_verify() below — see DUMMY_PASSWORD_HASH docblock.
            password_verify($password, self::DUMMY_PASSWORD_HASH);
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
        $session->set('lstr_admin_id',           $userId);
        $session->set('lstr_admin_username',     (string) $user['username']);
        $session->set('lstr_admin_email',        (string) $user['email']);
        $session->set('lstr_admin_display_name', (string) ($user['display_name'] ?? ''));

        try {
            (new AdminUserRepo($db))->updateLastLogin($userId);
        } catch (\Throwable) {
        }

        // Remember-me cookie
        if ($rememberMe) {
            $rawToken       = bin2hex(random_bytes(32));
            $tokenHash      = hash('sha256', $rawToken);
            $expiresAt      = $db->expiresIn(86400 * 30);
            $ua             = substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 100);
            $deviceLabel    = self::labelFromUserAgent($ua);

            // Multi-device: insert without deleting other tokens; cap at 10 per user.
            try {
                $db->prepare(
                    'INSERT INTO lstr_admin_remember_tokens
                     (admin_user_id, token_hash, expires_at, device_label, last_used_at, user_agent_hint)
                     VALUES (:uid, :hash, :expires, :label, NOW(), :ua)'
                )->execute([
                    'uid'    => $userId,
                    'hash'   => $tokenHash,
                    'expires' => $expiresAt,
                    'label'  => $deviceLabel,
                    'ua'     => $ua,
                ]);
            } catch (\PDOException) {
                // Pre-migration columns absent — fall back to basic insert
                $db->prepare(
                    'INSERT INTO lstr_admin_remember_tokens (admin_user_id, token_hash, expires_at)
                     VALUES (:uid, :hash, :expires)'
                )->execute(['uid' => $userId, 'hash' => $tokenHash, 'expires' => $expiresAt]);
            }

            // Prune oldest tokens if the user now has more than 10.
            $countRow = $db->prepare(
                'SELECT id FROM lstr_admin_remember_tokens
                 WHERE admin_user_id = :uid
                 ORDER BY created_at ASC, id ASC'
            );
            $countRow->execute(['uid' => $userId]);
            $allIds = $countRow->fetchAll(\PDO::FETCH_COLUMN);
            if (count($allIds) > 10) {
                $toPrune = array_slice($allIds, 0, count($allIds) - 10);
                $ph = implode(',', array_fill(0, count($toPrune), '?'));
                $db->prepare("DELETE FROM lstr_admin_remember_tokens WHERE id IN ({$ph})")
                   ->execute($toPrune);
            }

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

    /**
     * Destroys the session, deletes the remember-me token from the DB, and
     * expires the remember-me cookie before redirecting to the login page.
     */
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

    /**
     * Renders the forgot-password form.
     */
    public function forgotForm(Request $request, array $params): Response
    {
        $session = $this->app->getSession();

        $success = (string) $session->get('lstr_flash_success', '');
        $error   = (string) $session->get('lstr_flash_error', '');
        $session->remove('lstr_flash_success');
        $session->remove('lstr_flash_error');

        $html = $this->app->getView()->render('admin/auth/forgot.php', [
            'csrf_token'          => $this->app->getCSRF()->token(),
            'success'             => $success,
            'error'               => $error,
            'site_name'           => (string) $this->app->getConfig()->get('site_name', 'Luster'),
            'base_url'            => $this->app->getBaseUrl(),
            'selected_skin'       => $this->selectedSkin(),
            'admin_default_theme' => $this->adminDefaultTheme(),
        ]);

        return Response::html($html);
    }

    // -----------------------------------------------------------------------
    // POST /admin/forgot
    // -----------------------------------------------------------------------

    /**
     * Generates a one-time SHA-256 reset token, emails it to the matching
     * account, and always returns the same message regardless of whether the
     * account exists (no user enumeration).
     */
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
            $expiresAt = $db->expiresIn(3600);

            $resetRepo->create((int) $user['id'], $tokenHash, $expiresAt);

            $resetLink = $baseUrl . '/admin/reset/' . $rawToken;
            $siteName  = (string) $this->app->getConfig()->get('site_name', 'Luster');
            $ownerName = (string) $this->app->getConfig()->get('owner_name', $siteName);
            $siteUrl   = (string) $this->app->getConfig()->get('site_url', '');

            try {
                $mailer = new Mailer($this->app->getConfig());
                $mailer->send(
                    (string) $user['email'],
                    (string) $user['username'],
                    'Reset your ' . $siteName . ' admin password',
                    "Hello {$user['username']},\n\n"
                    . "A password reset was requested for your {$siteName} admin account.\n\n"
                    . "Click the link below to set a new password. This link expires in 1 hour.\n\n"
                    . $resetLink . "\n\n"
                    . "If you did not request this, you can safely ignore this email.\n\n"
                    . "-- {$ownerName}\n"
                    . "{$siteName} ({$siteUrl})"
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

    /**
     * Validates the raw reset token (SHA-256 lookup) and renders the new-password form,
     * redirecting to the forgot page if the token is expired or already used.
     */
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
            'csrf_token'          => $this->app->getCSRF()->token(),
            'error'               => $error,
            'raw_token'           => $rawToken,
            'site_name'           => (string) $this->app->getConfig()->get('site_name', 'Luster'),
            'base_url'            => $baseUrl,
            'selected_skin'       => $this->selectedSkin(),
            'admin_default_theme' => $this->adminDefaultTheme(),
        ]);

        return Response::html($html);
    }

    // -----------------------------------------------------------------------
    // POST /admin/reset/{token}
    // -----------------------------------------------------------------------

    /**
     * Processes the new-password form: validates, hashes with Argon2id, invalidates
     * all remember-me tokens for the account, and marks the reset token as used.
     */
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

        // Atomically claim the token right before applying the password
        // change - closes the race where two concurrent requests with the
        // same raw token both pass the findValidByToken() check above before
        // either has consumed it.
        $tokenRow = $resetRepo->verifyAndConsume($tokenHash);
        if ($tokenRow === null) {
            $session->set('lstr_flash_error', 'This reset link has expired or already been used. Please request a new one.');
            return Response::redirect($baseUrl . '/admin/forgot');
        }

        $hash = password_hash($newPassword, PASSWORD_ARGON2ID);
        (new AdminUserRepo($db))->updatePassword((int) $tokenRow['admin_user_id'], $hash);

        // Invalidate any remember-me tokens for this user
        $db->prepare('DELETE FROM lstr_admin_remember_tokens WHERE admin_user_id = :uid')
           ->execute(['uid' => (int) $tokenRow['admin_user_id']]);

        // Rotate CSRF token — password changed, treat as privilege change
        $this->app->getCSRF()->regenerate();

        $session->set('lstr_flash_success', 'Your password has been reset. Please sign in with your new password.');
        return Response::redirect($baseUrl . '/admin/login');
    }

    /**
     * Returns the active skin slug for the auth pages (login/forgot/reset).
     */
    /**
     * Derives a short human-readable device label from the User-Agent string.
     * Best-effort; uses no external library.
     */
    private static function labelFromUserAgent(string $ua): string
    {
        $browser = 'Browser';
        if (str_contains($ua, 'Edg/') || str_contains($ua, 'Edge/')) {
            $browser = 'Edge';
        } elseif (str_contains($ua, 'Firefox/')) {
            $browser = 'Firefox';
        } elseif (str_contains($ua, 'OPR/') || str_contains($ua, 'Opera/')) {
            $browser = 'Opera';
        } elseif (str_contains($ua, 'Chrome/')) {
            $browser = 'Chrome';
        } elseif (str_contains($ua, 'Safari/')) {
            $browser = 'Safari';
        }

        $os = 'Unknown OS';
        if (str_contains($ua, 'iPhone') || str_contains($ua, 'iPad')) {
            $os = 'iOS';
        } elseif (str_contains($ua, 'Android')) {
            $os = 'Android';
        } elseif (str_contains($ua, 'Windows')) {
            $os = 'Windows';
        } elseif (str_contains($ua, 'Macintosh') || str_contains($ua, 'Mac OS')) {
            $os = 'macOS';
        } elseif (str_contains($ua, 'Linux')) {
            $os = 'Linux';
        }

        return $browser . ' on ' . $os;
    }

    private function selectedSkin(): string
    {
        return $this->selectedSkinAndTheme()['skin'];
    }

    /**
     * Returns the admin default theme ('dark' or 'light') for the auth pages.
     */
    private function adminDefaultTheme(): string
    {
        return $this->selectedSkinAndTheme()['theme'];
    }

    /**
     * Resolves the active skin slug and default theme from lstr_site_settings,
     * verifying community skin file integrity via a stored SHA-256 hash.
     * Uses a static request-lifetime cache to avoid repeated DB hits for the
     * three auth pages that share the same data.
     *
     * @return array{skin: string, theme: string}
     */
    private function selectedSkinAndTheme(): array
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }
        try {
            $siteRepo = new SiteSettingsRepo($this->app->getDatabase());
            $settings = $siteRepo->getMany(['selected_skin', 'selected_skin_hash', 'admin_default_theme']);
            $slug     = $settings['selected_skin'] ?? '';
            $theme    = in_array($settings['admin_default_theme'] ?? '', ['dark', 'light'], true)
                ? $settings['admin_default_theme']
                : 'dark';

            if ($slug !== '' && !\Luster\Core\SkinCatalog::isBundled($slug)) {
                $skinFile    = $this->app->getBasePath() . '/public/assets/css/skins/' . $slug . '.css';
                $currentHash = is_file($skinFile) ? hash_file('sha256', $skinFile) : false;
                $storedHash  = $settings['selected_skin_hash'] ?? null;

                if ($currentHash === false || ($storedHash !== null && $currentHash !== $storedHash)) {
                    $slug = '';
                }
            }

            $cache = ['skin' => $slug, 'theme' => $theme];
        } catch (\Throwable) {
            $cache = ['skin' => '', 'theme' => 'dark'];
        }
        return $cache;
    }
}
