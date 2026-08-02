<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Sends security response headers (CSP, X-Frame-Options, X-Content-Type-Options,
 etc.) via PHP as a fallback for non-Apache deployments. On Apache, the same
 headers are also set by .htaccess via mod_headers — see the class docblock
 for why route-scoped .htaccess overrides need more than a plain "Header set"
 to actually replace this class's header rather than sit alongside it.
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
 * A plain "Header always set" in .htaccess does NOT reliably replace a
 * header this class already sent — verified empirically (curl against a
 * live request): both ended up on the response as two separate
 * Content-Security-Policy headers, not one. Per the CSP spec, browsers
 * enforce the intersection of every delivered policy, so two different
 * img-src values silently collapse to the stricter one with no visible
 * error — any route-scoped .htaccess override of a header this class also
 * sends needs "Header unset" *and* "Header always unset" before the
 * "Header always set", in that order (see the working example in
 * public/.htaccess for the News Feed composer's img-src relaxation). Plain,
 * unconditional headers where both layers always agree (X-Frame-Options,
 * the default CSP, etc.) are unaffected by this — the duplication is
 * harmless when both values are identical.
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
            . self::imgSrcDirective() . "; "
            . "font-src 'self' data:; "
            . "connect-src 'self'; "
            . "frame-ancestors 'none'; "
            . "base-uri 'self'; "
            . "form-action 'self'"
        );
        header_remove('X-Powered-By');
    }

    /**
     * img-src is 'self' data: everywhere except the News Feed composer's live
     * preview, which needs to load whatever http(s):// image URL the admin
     * just typed (NewsfeedBodySanitizer accepts both http and https for src -
     * ValidatorField::httpUrl()'s existing rule - so the CSP here allows both
     * too, or a valid http:// image would render for real once saved but
     * silently fail to load in this preview only). This only affects whether
     * the admin's own browser is allowed to fetch it while previewing, not
     * what gets saved or rendered on the live public site, which is never
     * under this admin-only CSP at all. Mirrors the equivalent <If> block in
     * public/.htaccess — keep both in sync.
     *
     * The (?=[?\s]|$) lookahead after the alternation is required - without
     * it, "\d+/add" and "entry/\d+/edit" match as a *prefix* of a longer path
     * segment (confirmed matching /admin/newsfeed/1/additional,
     * /admin/newsfeed/entry/1/edit-old, and
     * /admin/newsfeed/collective/additional, none of which are real routes).
     */
    private static function imgSrcDirective(): string
    {
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '');

        if (preg_match('#/admin/newsfeed/(collective/add|entry/\d+/edit|\d+/add)(?=[?\s]|$)#', $uri) === 1) {
            return "img-src 'self' data: http: https:";
        }

        return "img-src 'self' data:";
    }
}
