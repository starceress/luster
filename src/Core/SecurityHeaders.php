<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Sends security response headers (CSP, X-Frame-Options, X-Content-Type-Options,
 etc.) via PHP as a fallback for non-Apache deployments. On Apache, the same
 headers are set by .htaccess via mod_headers, so running both is idempotent.
 Called once per request from Application::boot().
*/

declare(strict_types=1);

namespace Luster\Core;

/**
 * PHP-level security header fallback.
 *
 * public/.htaccess already sets these headers on Apache via mod_headers.
 * This class sends the same policy via PHP header() so deployments on
 * Nginx, Caddy, or PHP-FPM standalone are not left without protection.
 *
 * On Apache with mod_headers, the "Header always set" directives in
 * .htaccess will overwrite any PHP-set header of the same name — so
 * running both is idempotent and produces no duplicates.
 *
 * Called once per request from Application::boot() before Response::send().
 */
final class SecurityHeaders
{
    /**
     * Emits all security response headers via PHP header().
     *
     * Safe to call multiple times — returns immediately when headers have already
     * been sent. script-src is 'self' only — every admin script is either a
     * static asset under public/assets/js/ or an inline handler attribute, and
     * there are no remaining inline <script> blocks to allow. Keep it that way:
     * a new inline <script> would be silently blocked by this policy in any
     * CSP-enforcing browser rather than raising an error, so it's easy to miss
     * in testing. Move new page behavior into admin.js instead.
     */
    public static function apply(): void
    {
        if (headers_sent()) {
            return;
        }

        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('X-XSS-Protection: 1; mode=block');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('Permissions-Policy: geolocation=(), microphone=(), camera=()');
        header(
            "Content-Security-Policy: default-src 'self'; "
            . "script-src 'self'; "
            . "style-src 'self' 'unsafe-inline'; "
            . "img-src 'self' data:; "
            . "font-src 'self' data:; "
            . "connect-src 'self'; "
            . "frame-ancestors 'none'; "
            . "base-uri 'self'; "
            . "form-action 'self'"
        );
        header_remove('X-Powered-By');
    }
}
