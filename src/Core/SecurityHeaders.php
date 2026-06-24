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
     * The CSP script hash covers the one inline <script> block present in
     * admin templates (theme-toggle initialisation). If that script ever
     * changes, re-compute:  base64_encode(hash('sha256', $scriptContent, true))
     */
    private const CSP_SCRIPT_HASH = 'sha256-lfZvxb5T4sGLkAT0mD3Vb+Uc8DCNaZf6KEuzEkUi0eA=';

    /**
     * Emits all security response headers via PHP header().
     *
     * Safe to call multiple times — returns immediately when headers have already
     * been sent. The CSP_SCRIPT_HASH constant must be updated if the inline
     * theme-toggle script in admin layout templates ever changes.
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
            . "script-src 'self' '" . self::CSP_SCRIPT_HASH . "'; "
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
