<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 URL origin validator used to prevent open-redirect attacks. Compares a
 candidate URL against the application's base URL and returns true only
 when the scheme, host, and port match exactly. Used by redirect targets
 throughout the admin and public request flows.
*/

declare(strict_types=1);

namespace Luster\Core;

/**
 * Validates redirect target URLs to prevent open-redirect attacks.
 *
 * isSameOriginUrl() compares scheme, host, and port of a candidate URL against
 * the application base URL and returns true only when they match exactly.
 * Used anywhere a user-supplied redirect target must stay within the same origin.
 */
final class UrlGuard
{
    /**
     * Returns true when $candidateUrl shares the same scheme, host, and port as $baseUrl.
     *
     * Both URLs must have an explicit scheme and host — relative URLs and
     * protocol-relative URLs always return false.
     */
    public static function isSameOriginUrl(string $candidateUrl, string $baseUrl): bool
    {
        $candidate = parse_url($candidateUrl);
        $base      = parse_url($baseUrl);

        if (!is_array($candidate) || !is_array($base)) {
            return false;
        }

        $candidateScheme = strtolower((string) ($candidate['scheme'] ?? ''));
        $candidateHost   = strtolower((string) ($candidate['host'] ?? ''));
        $candidatePort   = self::normalizePort($candidateScheme, $candidate['port'] ?? null);

        $baseScheme = strtolower((string) ($base['scheme'] ?? ''));
        $baseHost   = strtolower((string) ($base['host'] ?? ''));
        $basePort   = self::normalizePort($baseScheme, $base['port'] ?? null);

        if ($candidateScheme === '' || $candidateHost === '' || $baseScheme === '' || $baseHost === '') {
            return false;
        }

        return $candidateScheme === $baseScheme
            && $candidateHost === $baseHost
            && $candidatePort === $basePort;
    }

    /**
     * Returns the effective port for a URL.
     *
     * When $port is explicitly given it is returned as-is. Otherwise the
     * well-known port for the scheme is used (80 for http, 443 for https, 0 for others).
     */
    private static function normalizePort(string $scheme, int|string|null $port): int
    {
        if (is_numeric($port)) {
            return (int) $port;
        }

        return match ($scheme) {
            'http' => 80,
            'https' => 443,
            default => 0,
        };
    }
}
