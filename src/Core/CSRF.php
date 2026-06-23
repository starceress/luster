<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 CSRF token manager. Generates a cryptographically secure random token per
 session and stores it under a namespaced session key. Every POST form
 includes this token in a hidden field named "_csrf", and CsrfMiddleware
 validates it automatically on every POST request using a timing-safe
 comparison.
*/

declare(strict_types=1);

namespace Luster\Core;

/**
 * CSRF token manager.
 *
 * Generates and validates per-session CSRF tokens using cryptographically
 * secure random bytes. Tokens are stored in the session under a namespaced key.
 *
 * Usage — in a form template:
 *   <input type="hidden" name="_csrf" value="<?= View::e($csrf->token()) ?>">
 *
 * Validated automatically by CsrfMiddleware on every POST request.
 */
final class CSRF
{
    private const SESSION_KEY = 'lstr_csrf_token';
    private const TOKEN_BYTES = 32;

    public function __construct(private readonly Session $session) {}

    /**
     * Returns the current session CSRF token, generating one if absent.
     */
    public function token(): string
    {
        if (!$this->session->has(self::SESSION_KEY)) {
            $this->session->set(self::SESSION_KEY, $this->generate());
        }

        return (string) $this->session->get(self::SESSION_KEY);
    }

    /**
     * Validates a submitted CSRF token using a timing-safe comparison.
     * Returns false immediately if no token has been issued this session.
     */
    public function validate(string $submitted): bool
    {
        $stored = $this->session->get(self::SESSION_KEY, '');

        if (!is_string($stored) || $stored === '') {
            return false;
        }

        return hash_equals($stored, $submitted);
    }

    /**
     * Regenerates the CSRF token.
     * Call after completing a sensitive action to invalidate old tokens.
     */
    public function regenerate(): string
    {
        $token = $this->generate();
        $this->session->set(self::SESSION_KEY, $token);

        return $token;
    }

    private function generate(): string
    {
        return bin2hex(random_bytes(self::TOKEN_BYTES));
    }
}
