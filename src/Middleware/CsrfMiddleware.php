<?php

declare(strict_types=1);

namespace Luster\Middleware;

use Luster\Core\CSRF;
use Luster\Core\CsrfException;
use Luster\Core\Request;
use Luster\Core\Response;

/**
 * Validates the CSRF token on every POST request.
 *
 * The token is read from $_POST['_csrf']. On failure a CsrfException is
 * thrown, which Application::handleException() catches and converts to a
 * 403 Forbidden response.
 *
 * Returns null when the request should continue through the pipeline.
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
