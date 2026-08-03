<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Immutable wrapper around the HTTP request superglobals ($_GET, $_POST,
 $_SERVER, $_FILES, $_COOKIE). Constructed once at bootstrap and passed into
 controllers so they never touch superglobals directly. All accessors return
 raw values -- callers are responsible for validation and escaping.
*/

declare(strict_types=1);

namespace Luster\Core;

/**
 * Immutable-ish wrapper around the current HTTP request superglobals.
 *
 * Constructed once at bootstrap and injected into controllers.
 * All accessors return raw values — callers are responsible for
 * validation and escaping appropriate to their context.
 */
final class Request
{
    private readonly array $query;
    private readonly array $post;
    private readonly array $server;
    private readonly array $files;
    private readonly array $cookies;

    public function __construct(
        ?array $query   = null,
        ?array $post    = null,
        ?array $server  = null,
        ?array $files   = null,
        ?array $cookies = null,
    ) {
        $this->query   = $query   ?? $_GET;
        $this->post    = $post    ?? $_POST;
        $this->server  = $server  ?? $_SERVER;
        $this->files   = $files   ?? $_FILES;
        $this->cookies = $cookies ?? $_COOKIE;
    }

    // -----------------------------------------------------------------------
    // Scheme
    // -----------------------------------------------------------------------

    /**
     * Returns true when $server indicates an HTTPS request — checks the HTTPS
     * variable, port 443, and X-Forwarded-Proto (for reverse-proxy setups
     * that terminate TLS upstream of PHP).
     *
     * Static and defaults to the live $_SERVER superglobal so it can be
     * called before a Request instance exists — e.g. Session::start() sets
     * session.cookie_secure during bootstrap, before the front controller
     * constructs a Request.
     *
     * X-Forwarded-Proto is trusted unconditionally, with no configured
     * trusted-proxy allowlist gating it. Evaluated and deliberately left
     * this way: on a directly-internet-facing install (no reverse proxy), a
     * client sending X-Forwarded-Proto: https on a plain-HTTP request flips
     * this to true and Secure-flags *that request's own* session cookie —
     * the browser then silently drops it (Secure cookies aren't set over
     * plain HTTP), breaking only that one visitor's own session. This cannot
     * be used to alter another visitor's cookie flags or downgrade someone
     * else's session security. Gating it behind a trusted-proxy config would
     * need threading a config value through every call site, including
     * install/index.php and public/migrate/index.php — both standalone
     * scripts with no Config/Application instance at all (install hasn't
     * happened yet; migrate is a no-autoloader runner) — so either they'd
     * need a second, separately-maintained implementation of the same check
     * (the exact class of drift that bit install/index.php's HTTPS detection
     * before it was synced back to match this method), or they'd stay
     * ungated while every other call site became gated, inconsistent either
     * way. Not worth that either-way cost for a self-referential-only edge
     * case with no way to affect another visitor's session.
     *
     * @param array<string, mixed>|null $server  Defaults to $_SERVER when omitted.
     */
    public static function detectHttps(?array $server = null): bool
    {
        $server ??= $_SERVER;

        return (!empty($server['HTTPS']) && $server['HTTPS'] !== 'off')
            || (int) ($server['SERVER_PORT'] ?? 80) === 443
            || strtolower((string) ($server['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
    }

    /** Returns true when this request was made over HTTPS. */
    public function isHttps(): bool
    {
        return self::detectHttps($this->server);
    }

    // -----------------------------------------------------------------------
    // Method / path
    // -----------------------------------------------------------------------

    /** Returns the HTTP method in uppercase (e.g. 'GET', 'POST'). */
    public function method(): string
    {
        return strtoupper((string) ($this->server['REQUEST_METHOD'] ?? 'GET'));
    }

    /** Returns true when the request method is GET. */
    public function isGet(): bool
    {
        return $this->method() === 'GET';
    }

    /** Returns true when the request method is POST. */
    public function isPost(): bool
    {
        return $this->method() === 'POST';
    }

    /**
     * Returns the URL path component only, without query string,
     * with the installation base directory stripped.
     *
     * Always starts with '/'. Works correctly whether Luster is installed
     * at the document root (/index.php) or a subdirectory
     * (/luster/public/index.php).
     *
     * Examples for a /luster/public/ install:
     *   REQUEST_URI /luster/public/admin  → /admin
     *   REQUEST_URI /luster/public/       → /
     */
    public function path(): string
    {
        $uri  = (string) ($this->server['REQUEST_URI'] ?? '/');
        $path = (string) parse_url($uri, PHP_URL_PATH);

        // Determine the base directory from the executing script's location.
        // e.g. SCRIPT_NAME=/luster/public/admin.php → base=/luster/public
        //      SCRIPT_NAME=/index.php               → base='' (no stripping)
        $scriptName = (string) ($this->server['SCRIPT_NAME'] ?? '');
        $base       = rtrim(dirname($scriptName), '/');

        if ($base !== '' && $base !== '.' && str_starts_with($path, $base)) {
            $path = substr($path, strlen($base));
        }

        return '/' . ltrim($path ?: '/', '/');
    }

    // -----------------------------------------------------------------------
    // Input accessors
    // -----------------------------------------------------------------------

    /** Query-string ($_GET) value. */
    public function query(string $key, mixed $default = null): mixed
    {
        return $this->query[$key] ?? $default;
    }

    /** POST body ($_POST) value. */
    public function post(string $key, mixed $default = null): mixed
    {
        return $this->post[$key] ?? $default;
    }

    /**
     * Looks in POST first, then GET.
     * Useful for controller actions that accept both.
     */
    public function input(string $key, mixed $default = null): mixed
    {
        return $this->post[$key] ?? $this->query[$key] ?? $default;
    }

    /** Returns merged GET + POST (POST wins on collision). */
    public function all(): array
    {
        return array_merge($this->query, $this->post);
    }

    /** Returns the raw POST array. */
    public function postAll(): array
    {
        return $this->post;
    }

    // -----------------------------------------------------------------------
    // File uploads
    // -----------------------------------------------------------------------

    /** Returns the $_FILES entry for $key, or null if absent. */
    public function file(string $key): ?array
    {
        return isset($this->files[$key]) ? (array) $this->files[$key] : null;
    }

    // -----------------------------------------------------------------------
    // Headers / cookies / IP
    // -----------------------------------------------------------------------

    /** Returns a request header value by name (case-insensitive). */
    public function header(string $name): ?string
    {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));

        return isset($this->server[$key]) ? (string) $this->server[$key] : null;
    }

    /** Returns a cookie value by name, or $default when absent. */
    public function cookie(string $key, mixed $default = null): mixed
    {
        return $this->cookies[$key] ?? $default;
    }

    /** Returns the client IP address. Never trust this for security decisions alone. */
    public function ip(): string
    {
        return (string) ($this->server['REMOTE_ADDR'] ?? '');
    }

    /** Returns true when the request carries the X-Requested-With: XMLHttpRequest header. */
    public function isAjax(): bool
    {
        return $this->header('X-Requested-With') === 'XMLHttpRequest';
    }

    /** Full request URI including query string. */
    public function uri(): string
    {
        return (string) ($this->server['REQUEST_URI'] ?? '/');
    }
}
