<?php

declare(strict_types=1);

namespace Luster\Core;

final class UrlGuard
{
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
