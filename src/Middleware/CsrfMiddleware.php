<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 CSRF protection middleware. Runs on every POST request and checks
 the _csrf token field against the session-stored token. If the
 token is missing or invalid, it throws a CsrfException which the
 application converts into a 403 Forbidden response. GET requests
 pass through untouched.
*/

declare(strict_types=1);

namespace Luster\Middleware;

use Luster\Core\CSRF;
use Luster\Core\CsrfException;
use Luster\Core\Request;
use Luster\Core\Response;
/**
 * CSRF token guard — rejects any POST request whose _csrf field is
 * missing or does not match the session-stored token.
 */
final class CsrfMiddleware
{
    public function __construct(private readonly CSRF $csrf) {}

    /**
     * @return Response|null  null means "continue"; a Response short-circuits.
     * @throws CsrfException  If the request is a POST with a missing/invalid token.
     */
    public function process(Request $request): ?Response
    {
        if (!$request->isPost()) {
            return null;
        }

        $token = (string) $request->post('_csrf', '');

        if (!$this->csrf->validate($token)) {
            throw new CsrfException(
                'Invalid or missing CSRF token. Please reload the page and try again.'
            );
        }

        return null;
    }
}
