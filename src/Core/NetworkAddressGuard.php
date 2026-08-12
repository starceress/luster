<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 SSRF protection guard for all outbound HTTP requests. Validates that a
 hostname resolves to a public IP address before allowing a connection,
 blocking private, loopback, and reserved ranges. Used by LinkChecker and
 ImageMigrator to ensure server-side fetches never reach internal network
 resources.
*/

declare(strict_types=1);

namespace Luster\Core;

/**
 * SSRF protection guard for all outbound HTTP connections.
 *
 * Validates that a hostname resolves exclusively to public IP addresses before
 * any connection is attempted, blocking private RFC 1918 ranges, loopback,
 * link-local, and other reserved ranges. Used by LinkChecker and ImageMigrator.
 *
 * IPv4-mapped (::ffff:a.b.c.d) and NAT64 (64:ff9b::a.b.c.d) IPv6 addresses
 * are unwrapped to the IPv4 address they carry before that address is
 * checked — otherwise they bypass every IPv4 rule below outright, since
 * PHP's own filter and this class's CIDR helpers only recognise plain
 * dotted-decimal IPv4.
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
     * Builds a single CURLOPT_RESOLVE entry from all of a host's resolved IPs,
     * IPv4 addresses first.
     *
     * Adding one array entry per IP for the same host:port (the naive approach)
     * is wrong: curl's resolve cache is keyed by host:port, so a second entry
     * for the same key silently overwrites the first rather than adding a
     * fallback candidate — only the last IP added ever gets used. For a
     * dual-stack host that pins the connection to whichever address family
     * happened to be resolved last, which breaks outright on any network
     * without working outbound IPv6 (common on shared hosting) even though
     * the IPv4 address would have worked fine. A single comma-separated
     * entry instead lets curl try every listed address in order.
     *
     * @param list<string> $ips
     */
    public static function buildCurlResolveEntry(string $host, int $port, array $ips): string
    {
        usort($ips, static fn(string $a, string $b): int => str_contains($a, ':') <=> str_contains($b, ':'));

        return $host . ':' . $port . ':' . implode(',', $ips);
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

    /**
     * IANA special-purpose IPv4 ranges that PHP's FILTER_FLAG_NO_RES_RANGE
     * does not treat as reserved: shared address space used for carrier-grade
     * NAT, IETF protocol assignments, benchmark testing, documentation
     * (TEST-NET), and multicast. Each entry is [network, prefix length].
     */
    private const EXTRA_BLOCKED_IPV4_RANGES = [
        ['100.64.0.0', 10],    // RFC 6598 — Shared Address Space (CGNAT)
        ['192.0.0.0', 24],     // RFC 6890 — IETF Protocol Assignments
        ['192.0.2.0', 24],     // RFC 5737 — TEST-NET-1 (documentation)
        ['198.18.0.0', 15],    // RFC 2544 — Benchmark testing
        ['198.51.100.0', 24],  // RFC 5737 — TEST-NET-2 (documentation)
        ['203.0.113.0', 24],   // RFC 5737 — TEST-NET-3 (documentation)
        ['224.0.0.0', 4],      // RFC 5771/1112 — Multicast
    ];

    /**
     * IANA special-purpose IPv6 ranges that PHP's FILTER_FLAG_NO_RES_RANGE
     * does not treat as reserved. Each entry is [network, prefix length].
     */
    private const EXTRA_BLOCKED_IPV6_RANGES = [
        ['ff00::', 8], // RFC 4291 — Multicast
    ];

    /** 96-bit prefix of ::ffff:0:0/96 (RFC 4291 §2.5.5.2 — IPv4-mapped IPv6). */
    private const IPV4_MAPPED_PREFIX = "\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\xff\xff";

    /** 96-bit prefix of 64:ff9b::/96 (RFC 6052 — NAT64 well-known prefix). */
    private const NAT64_WELL_KNOWN_PREFIX = "\x00\x64\xff\x9b\x00\x00\x00\x00\x00\x00\x00\x00";

    private static function isPublicIpAddress(string $ip): bool
    {
        // IPv4-mapped (::ffff:a.b.c.d) and NAT64 (64:ff9b::a.b.c.d) IPv6
        // addresses carry a real IPv4 address inside an IPv6 literal. Left
        // unhandled, every private/reserved IPv4 check below is bypassed
        // outright — filter_var() and the CIDR helpers here only recognise
        // plain dotted-decimal IPv4, so e.g. "::ffff:169.254.169.254" (a
        // cloud metadata endpoint) would otherwise validate as public. Check
        // the embedded address instead of the wrapper.
        $embeddedIpv4 = self::extractEmbeddedIpv4($ip);
        if ($embeddedIpv4 !== null) {
            return self::isPublicIpAddress($embeddedIpv4);
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return false;
        }

        foreach (self::EXTRA_BLOCKED_IPV4_RANGES as [$network, $prefixLength]) {
            if (self::ipv4InCidr($ip, $network, $prefixLength)) {
                return false;
            }
        }

        foreach (self::EXTRA_BLOCKED_IPV6_RANGES as [$network, $prefixLength]) {
            if (self::ipv6InCidr($ip, $network, $prefixLength)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Returns the dotted-decimal IPv4 address embedded in an IPv4-mapped or
     * NAT64 IPv6 literal, or null when $ip isn't one of those two forms.
     * Compares against the packed (inet_pton()) form rather than the textual
     * one, since the same address has multiple valid textual spellings
     * (e.g. "::ffff:127.0.0.1" and "::ffff:7f00:1").
     */
    private static function extractEmbeddedIpv4(string $ip): ?string
    {
        $packed = @inet_pton($ip);
        if ($packed === false || strlen($packed) !== 16) {
            return null;
        }

        $prefix = substr($packed, 0, 12);
        if ($prefix !== self::IPV4_MAPPED_PREFIX && $prefix !== self::NAT64_WELL_KNOWN_PREFIX) {
            return null;
        }

        $embedded = inet_ntop(substr($packed, 12, 4));

        return $embedded !== false ? $embedded : null;
    }

    /**
     * Checks whether an IPv4 address falls within a CIDR range. Always false
     * for IPv6 addresses (ip2long() rejects them) — none of the extra
     * blocked ranges above are IPv6.
     */
    private static function ipv4InCidr(string $ip, string $network, int $prefixLength): bool
    {
        $ipLong      = ip2long($ip);
        $networkLong = ip2long($network);
        if ($ipLong === false || $networkLong === false) {
            return false;
        }

        $mask = $prefixLength === 0 ? 0 : (~0 << (32 - $prefixLength)) & 0xFFFFFFFF;

        return ($ipLong & $mask) === ($networkLong & $mask);
    }

    /**
     * Checks whether an IPv6 address falls within a CIDR range, comparing
     * packed (inet_pton()) bytes so textual formatting differences (leading
     * zeros, "::" compression, casing) never affect the result.
     */
    private static function ipv6InCidr(string $ip, string $network, int $prefixLength): bool
    {
        $ipPacked  = @inet_pton($ip);
        $netPacked = @inet_pton($network);
        if ($ipPacked === false || $netPacked === false || strlen($ipPacked) !== 16 || strlen($netPacked) !== 16) {
            return false;
        }

        $fullBytes     = intdiv($prefixLength, 8);
        $remainingBits = $prefixLength % 8;

        if ($fullBytes > 0 && substr($ipPacked, 0, $fullBytes) !== substr($netPacked, 0, $fullBytes)) {
            return false;
        }

        if ($remainingBits === 0) {
            return true;
        }

        $mask = (~0 << (8 - $remainingBits)) & 0xFF;

        return (ord($ipPacked[$fullBytes]) & $mask) === (ord($netPacked[$fullBytes]) & $mask);
    }
}
