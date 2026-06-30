<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Secure session wrapper that enforces httponly, secure, and SameSite=Strict
 cookie flags before starting the PHP session. Provides typed get/set/flash
 helpers and handles session regeneration on login. Called once at application
 bootstrap; subsequent calls are safe no-ops.
*/

declare(strict_types=1);

namespace Luster\Core;

use RuntimeException;

/**
 * Secure session wrapper.
 *
 * Enforces the session security settings required before
 * ever calling session_start(). Call start() once at application bootstrap
 * (Application::run() does this automatically).
 */
final class Session
{
    private bool $started = false;

    /** @var array{path: string, usedFallback: bool}|null */
    private ?array $pathDiagnostics = null;

    /**
     * @param string $cookieDomain  Optional session cookie domain (e.g. '.nebuluna.com').
     *                              Pass an empty string for single-domain setups (PHP default).
     *                              Read from config key 'session_cookie_domain' in Application.
     * @param string $basePath      Absolute path to LUSTER_BASE, used to resolve a writable
     *                              session save path via SessionPathResolver. Pass an empty
     *                              string to skip path resolution and use the PHP default
     *                              unconditionally (e.g. in tests).
     */
    public function __construct(
        private readonly string $cookieDomain = '',
        private readonly string $basePath = '',
    ) {}

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
            if (session_name() === 'luster_session') {
                // Already our session — nothing to do.
                $this->started = true;
                return;
            }
            // Another session is active (e.g. session.auto_start = On on shared
            // hosting started a PHPSESSID session). Write-close it so its data is
            // preserved, then start our own named session below.
            session_write_close();
        }

        ini_set('session.cookie_httponly', '1');
        ini_set('session.cookie_secure', '1');
        ini_set('session.cookie_samesite', 'Strict');
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.gc_maxlifetime', '28800');
        ini_set('session.cookie_lifetime', '28800');

        if ($this->cookieDomain !== '') {
            ini_set('session.cookie_domain', $this->cookieDomain);
        }

        if ($this->basePath !== '') {
            $this->pathDiagnostics = (new SessionPathResolver($this->basePath))->resolve();
            session_save_path($this->pathDiagnostics['path']);
        }

        session_name('luster_session');

        if (!session_start()) {
            throw new RuntimeException('Failed to start session.');
        }

        $this->started = true;
    }

    /**
     * Returns the resolved session save path and whether a fallback was
     * needed, or null when path resolution was skipped (no basePath given,
     * or start() has not been called yet). Used by the System Information
     * panel to surface a warning when a fallback path is in use.
     *
     * @return array{path: string, usedFallback: bool}|null
     */
    public function getPathDiagnostics(): ?array
    {
        return $this->pathDiagnostics;
    }

    /** Returns the session value for $key, or $default when absent. */
    public function get(string $key, mixed $default = null): mixed
    {
        return $_SESSION[$key] ?? $default;
    }

    /** Stores a value in the session under $key. */
    public function set(string $key, mixed $value): void
    {
        $_SESSION[$key] = $value;
    }

    /** Returns true when $key exists in the session and is not null. */
    public function has(string $key): bool
    {
        return isset($_SESSION[$key]);
    }

    /** Removes $key from the session. No-op when the key does not exist. */
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

    /** Returns true when start() has been called successfully this request. */
    public function isStarted(): bool
    {
        return $this->started;
    }
}
