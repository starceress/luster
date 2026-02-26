<?php

declare(strict_types=1);

namespace Luster\Middleware;

use Luster\Core\Request;
use Luster\Core\Response;
use Luster\Core\Session;

/**
 * Enforces admin authentication for all admin routes.
 *
 * Paths listed in $exemptPaths bypass the authentication check entirely
 * (used for the login page itself). All other admin paths redirect to
 * $loginUrl when the admin session is absent.
 */
final class AuthMiddleware
{
    /**
     * @param string       $loginUrl     Full URL of the login page (used in Location header).
     * @param list<string> $exemptPaths  Route paths that skip the auth check.
     */
    public function __construct(
        private readonly Session $session,
        private readonly string  $loginUrl,
        private readonly array   $exemptPaths = [],
    ) {}

    /**
     * @return Response|null  Redirect response if unauthenticated; null to continue.
     */
    public function process(Request $request): ?Response
    {
        $path = $request->path();

        foreach ($this->exemptPaths as $exempt) {
            if ($path === $exempt) {
                return null;
            }
        }

        if (!$this->session->has('lstr_admin_id')) {
            return Response::redirect($this->loginUrl);
        }

        return null;
    }
}
