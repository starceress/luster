<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Resolves the URL for a per-listing form page (join, update, reset, etc.)
 when the admin has configured a custom page path on their own site to host
 the embedded form. Otherwise returns the listing URL as fallback.
 Pure function with no DB calls.
*/

declare(strict_types=1);

namespace Luster\Support;

/**
 * Resolves the public URL for a per-listing form page (join, update, reset,
 * codes-submit, contact, affiliate-apply) based on the admin's configured
 * Host Page URL setting.
 *
 * Resolution rules:
 * - Empty override → listing URL (the owner's listing homepage).
 * - Absolute URL (http/https) → used as-is.
 * - Relative filename (e.g. "reset.php") → resolved against the listing URL.
 *
 * Pure function — no DB calls, trivially unit-testable.
 */
final class ListingFormUrlResolver
{
    /**
     * @param string $override  The admin-configured form page URL (may be '', relative, or absolute).
     * @param string $listingUrl The listing's own public URL (used as resolution base and fallback).
     */
    public static function resolve(string $override, string $listingUrl): string
    {
        $override = trim($override);
        if ($override === '') {
            return $listingUrl;
        }
        if (preg_match('#^https?://#i', $override)) {
            return $override;
        }
        return rtrim($listingUrl, '/') . '/' . ltrim($override, '/');
    }
}
