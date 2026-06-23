<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Resolves the URL for collective-level form pages like code donation and
 affiliate application. When the admin has configured a custom page path on
 their own site to host the embedded form, this builds a full URL relative
 to the site URL. Otherwise it returns the caller's fallback (one of
 Luster's built-in routes). Pure function with no DB calls.
*/

declare(strict_types=1);

namespace Luster\Support;

/**
 * Resolves the public URL for a collective-wide form page (code donation,
 * affiliate application) when the admin has configured a custom page on
 * their own site to host the embedded form.
 *
 * An override path is resolved relative to the collective's public site URL
 * (`site_url`), since that is where the admin-hosted page lives. With no
 * override, the caller's fallback (one of Luster's own built-in routes) is
 * used unchanged.
 *
 * Pure function — no DB calls, trivially unit-testable.
 */
final class CollectiveFormUrlResolver
{
    public static function resolve(?string $override, string $siteUrl, string $fallback): string
    {
        $override = trim((string) ($override ?? ''));
        if ($override === '') {
            return $fallback;
        }

        return rtrim($siteUrl, '/') . '/' . ltrim($override, '/');
    }
}
