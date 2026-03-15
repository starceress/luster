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

        if ($row === false) {
            return false;
        }

        $this->session->regenerate();
        $this->session->set('lstr_admin_id',       $userId);
        $this->session->set('lstr_admin_username', (string) $row['username']);
        $this->session->set('lstr_admin_email',    (string) $row['email']);
        return true;
    }
}
