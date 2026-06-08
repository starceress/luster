<?php

declare(strict_types=1);

namespace Luster\Middleware;

use Luster\Core\Database;
use Luster\Core\Request;
use Luster\Core\Response;
use Luster\Core\Session;

/**
 * Enforces admin authentication for all admin routes.
 *
 * Exempt paths bypass the auth check (login page, forgot, reset).
 * Paths ending in '/' are matched as prefixes (e.g. '/admin/reset/').
 *
 * If no session exists and a remember-me cookie is present, the middleware
 * validates the token against lstr_admin_remember_tokens and auto-logs the
 * user in for the current request.
 */
final class AuthMiddleware
{
    public function __construct(
        private readonly Session   $session,
        private readonly string    $loginUrl,
        private readonly array     $exemptPaths = [],
        private readonly ?Database $db = null,
    ) {}

    public function process(Request $request): ?Response
    {
        $path = $request->path();

        foreach ($this->exemptPaths as $exempt) {
            if ($path === $exempt) {
                return null;
            }
            // Trailing-slash entries are treated as prefix matches
            if (str_ends_with($exempt, '/') && str_starts_with($path, $exempt)) {
                return null;
            }
        }

        if (!$this->session->has('lstr_admin_id')) {
            if ($this->db !== null && $this->tryRememberMe()) {
                return null;
            }
            return Response::redirect($this->loginUrl);
        }

        return null;
    }

    private function tryRememberMe(): bool
    {
        $cookie = (string) ($_COOKIE['lstr_remember'] ?? '');
        if ($cookie === '' || !str_contains($cookie, ':')) {
            return false;
        }

        [$userIdStr, $rawToken] = explode(':', $cookie, 2);
        $userId = (int) $userIdStr;
        if ($userId <= 0 || $rawToken === '') {
            return false;
        }

        $tokenHash = hash('sha256', $rawToken);

        try {
            $stmt = $this->db->prepare(
                'SELECT rt.id, u.username, u.email
                 FROM lstr_admin_remember_tokens rt
                 JOIN lstr_admin_users u ON u.id = rt.admin_user_id
                 WHERE rt.admin_user_id = :uid
                   AND rt.token_hash    = :hash
                   AND rt.expires_at    > NOW()
                 LIMIT 1'
            );
            $stmt->execute(['uid' => $userId, 'hash' => $tokenHash]);
            $row = $stmt->fetch();
        } catch (\PDOException $e) {
            // DB missing or tables not yet created (e.g. fresh reinstall).
            // Expire the stale cookie so future requests don't keep hitting this path.
            setcookie('lstr_remember', '', ['expires' => 1, 'path' => '/', 'httponly' => true, 'samesite' => 'Strict']);
            return false;
        }

        if ($row === false) {
            return false;
        }

        // Rotate the token — invalidates the old cookie immediately so a copied
        // cookie cannot be reused. One active token per admin is intentional.
        $newRawToken  = bin2hex(random_bytes(32));
        $newTokenHash = hash('sha256', $newRawToken);
        $newExpiresAt = date('Y-m-d H:i:s', time() + 86400 * 30);

        // Compare-and-swap: include the old hash so a concurrent request that
        // already rotated (rowCount = 0) does not silently succeed here.
        $updateStmt = $this->db->prepare(
            'UPDATE lstr_admin_remember_tokens
             SET token_hash = :hash, expires_at = :expires
             WHERE id = :id AND token_hash = :old_hash'
        );
        $updateStmt->execute([
            'hash'     => $newTokenHash,
            'expires'  => $newExpiresAt,
            'id'       => (int) $row['id'],
            'old_hash' => $tokenHash,
        ]);

        if ($updateStmt->rowCount() === 0) {
            // A concurrent request rotated the token first; this cookie is stale.
            return false;
        }

        $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
                || (int) ($_SERVER['SERVER_PORT'] ?? 80) === 443
                || strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';

        setcookie('lstr_remember', $userId . ':' . $newRawToken, [
            'expires'  => time() + 86400 * 30,
            'path'     => '/',
            'httponly' => true,
            'secure'   => $isHttps,
            'samesite' => 'Strict',
        ]);

        $this->session->regenerate();
        $this->session->set('lstr_admin_id',       $userId);
        $this->session->set('lstr_admin_username', (string) $row['username']);
        $this->session->set('lstr_admin_email',    (string) $row['email']);
        return true;
    }
}
