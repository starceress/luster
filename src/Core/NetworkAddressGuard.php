<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 SSRF protection guard for all outbound HTTP requests. Validates that a
 hostname resolves to a public IP address before allowing a connection,
 blocking private, loopback, and reserved ranges. Used by LinkChecker and
 NetworkDirectoryService to ensure server-side fetches never reach internal
 network resources.
*/

declare(strict_types=1);

namespace Luster\Core;

/**
 * SSRF protection guard for all outbound HTTP connections.
 *
 * Validates that a hostname resolves exclusively to public IP addresses before
 * any connection is attempted, blocking private RFC 1918 ranges, loopback,
 * link-local, and other reserved ranges. Used by LinkChecker and
 * NetworkDirectoryService.
 */
final class NetworkAddressGuard
{
    /**
     * Returns null when a host is acceptable for outbound connections, or an
     * error message when it resolves to a private/reserved destination.
     */
    public static function validatePublicHost(string $host): ?string
    {
        return self::validateAndResolve($host)['error'];
    }

    /**
     * Validates a host and returns the resolved public IPs.
     *
     * @return array{error: ?string, ips: list<string>}
     */
    public static function validateAndResolve(string $host): array
    {
        $normalizedHost = self::normalizeHost($host);
        if ($normalizedHost === '') {
            return ['error' => 'Host is required.', 'ips' => []];
        }

        if (self::isBlockedHostname($normalizedHost)) {
            return ['error' => 'Connection to private or loopback addresses is not permitted.', 'ips' => []];
        }

        if (self::isIpAddress($normalizedHost)) {
            return self::isPublicIpAddress($normalizedHost)
                ? ['error' => null, 'ips' => [$normalizedHost]]
                : ['error' => 'Connection to private or loopback addresses is not permitted.', 'ips' => []];
        }

        $resolvedIps = self::resolveHost($normalizedHost);
        if ($resolvedIps === []) {
            return ['error' => null, 'ips' => []];
        }

        foreach ($resolvedIps as $ip) {
            if (!self::isPublicIpAddress($ip)) {
                return ['error' => 'Connection to private or loopback addresses is not permitted.', 'ips' => []];
            }
        }

        return ['error' => null, 'ips' => $resolvedIps];
    }

    /**
     * Lowercases the host and strips surrounding IPv6 brackets if present.
     * Used to normalise both literal IP addresses and hostnames before validation.
     */
    public static function normalizeHost(string $host): string
    {
        $normalized = trim($host);

        if (str_starts_with($normalized, '[') && str_ends_with($normalized, ']')) {
            $normalized = substr($normalized, 1, -1);
        }

        return strtolower($normalized);
    }

    /**
     * @return list<string>
     */
    private static function resolveHost(string $host): array
    {
        $ips = [];

        if (function_exists('dns_get_record')) {
            $records = @dns_get_record($host, DNS_A + DNS_AAAA);
            if (is_array($records)) {
                foreach ($records as $record) {
                    $candidate = (string) ($record['ip'] ?? $record['ipv6'] ?? '');
                    if ($candidate !== '' && self::isIpAddress($candidate)) {
                        $ips[] = $candidate;
                    }
                }
            }
        }

        if ($ips === [] && function_exists('gethostbynamel')) {
            $resolved = @gethostbynamel($host);
            if (is_array($resolved)) {
                foreach ($resolved as $candidate) {
                    if (self::isIpAddress($candidate)) {
                        $ips[] = $candidate;
                    }
                }
            }
        }

        return array_values(array_unique($ips));
    }

    private static function isBlockedHostname(string $host): bool
    {
        return $host === 'localhost'
            || str_ends_with($host, '.localhost');
    }

    private static function isIpAddress(string $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_IP) !== false;
    }

    private static function isPublicIpAddress(string $ip): bool
    {
        return filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) !== false;
    }
}
