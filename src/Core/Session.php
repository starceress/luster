<?php

declare(strict_types=1);

namespace Luster\Core;

use RuntimeException;

/**
 * Secure session wrapper.
 *
 * Enforces secure session settings before
 * ever calling session_start(). Call start() once at application bootstrap
 * (Application::run() does this automatically).
 */
final class Session
{
    private bool $started = false;

    /**
     * Starts the session with all required security settings applied.
     * Safe to call multiple times; subsequent calls are no-ops.
     */
    public function start(): void
    {
        if ($this->started) {
            return;
        }

        if (session_status() === PHP_SESSION_ACTIVE) {
            $this->started = true;
            return;
        }

        ini_set('session.cookie_httponly', '1');
        ini_set('session.cookie_secure', '1');
        ini_set('session.cookie_samesite', 'Strict');
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');

        session_name('luster_session');

        if (!session_start()) {
            throw new RuntimeException('Failed to start session.');
        }

        $this->started = true;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $_SESSION[$key] ?? $default;
    }

    public function set(string $key, mixed $value): void
    {
        $_SESSION[$key] = $value;
    }

    public function has(string $key): bool
    {
        return isset($_SESSION[$key]);
    }

    public function remove(string $key): void
    {
        unset($_SESSION[$key]);
    }

    /**
     * Regenerates the session ID to mitigate session fixation.
     * Must be called on every successful admin login.
     */
    public function regenerate(): void
    {
        session_regenerate_id(true);
    }

    /**
     * Destroys the session completely (logout).
     */
    public function destroy(): void
    {
        $_SESSION = [];

        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                time() - 42000,
                $params['path'],
                $params['domain'],
                $params['secure'],
                $params['httponly']
            );
        }

        session_destroy();
        $this->started = false;
    }

    public function isStarted(): bool
    {
        return $this->started;
    }
}
