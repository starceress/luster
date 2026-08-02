<?php

declare(strict_types=1);

namespace Luster\Tests\Unit;

use Luster\Core\NetworkAddressGuard;
use PHPUnit\Framework\TestCase;

/**
 * Covers the SSRF address checks in NetworkAddressGuard, including:
 *
 * - The IANA special-purpose IPv4 ranges PHP's FILTER_FLAG_NO_RES_RANGE does
 *   not treat as reserved on its own: shared address space / CGNAT
 *   (100.64.0.0/10), IETF protocol assignments (192.0.0.0/24), benchmark
 *   testing (198.18.0.0/15), the three TEST-NET documentation ranges
 *   (192.0.2.0/24, 198.51.100.0/24, 203.0.113.0/24), and multicast
 *   (224.0.0.0/4) — matches NetworkAddressGuard::EXTRA_BLOCKED_IPV4_RANGES
 *   exactly. Not a claim that these are the only IANA special-purpose ranges
 *   in existence, just the ones PHP's own filter misses that this guard
 *   additionally closes.
 * - The IPv6 multicast range (ff00::/8), also missed by PHP's filter.
 * - IPv4-mapped (::ffff:a.b.c.d) and NAT64 (64:ff9b::a.b.c.d) IPv6
 *   addresses, which must be validated against the address they actually
 *   carry rather than treated as opaque IPv6 literals — otherwise they
 *   bypass every IPv4 rule above outright (a known SSRF filter bypass
 *   technique).
 */
final class NetworkAddressGuardTest extends TestCase
{
    public function testAllowsOrdinaryPublicIpv4(): void
    {
        $this->assertNull(NetworkAddressGuard::validatePublicHost('8.8.8.8'));
    }

    public function testAllowsOrdinaryPublicIpv6(): void
    {
        $this->assertNull(NetworkAddressGuard::validatePublicHost('2001:4860:4860::8888'));
    }

    public function testBlocksPrivateRfc1918Range(): void
    {
        $this->assertNotNull(NetworkAddressGuard::validatePublicHost('192.168.1.1'));
    }

    public function testBlocksLoopback(): void
    {
        $this->assertNotNull(NetworkAddressGuard::validatePublicHost('127.0.0.1'));
    }

    public function testBlocksIpv6Loopback(): void
    {
        $this->assertNotNull(NetworkAddressGuard::validatePublicHost('::1'));
    }

    /**
     * @dataProvider cgnatRangeProvider
     */
    public function testBlocksCgnatSharedAddressSpace(string $ip): void
    {
        $this->assertNotNull(NetworkAddressGuard::validatePublicHost($ip));
    }

    /** @return array<string,array{string}> */
    public static function cgnatRangeProvider(): array
    {
        return [
            'range start'  => ['100.64.0.0'],
            'mid-range'    => ['100.64.0.1'],
            'range end'    => ['100.127.255.255'],
        ];
    }

    public function testAllowsJustOutsideCgnatRange(): void
    {
        $this->assertNull(NetworkAddressGuard::validatePublicHost('100.63.255.255'));
        $this->assertNull(NetworkAddressGuard::validatePublicHost('100.128.0.0'));
    }

    /**
     * @dataProvider ietfProtocolAssignmentsRangeProvider
     */
    public function testBlocksIetfProtocolAssignments(string $ip): void
    {
        $this->assertNotNull(NetworkAddressGuard::validatePublicHost($ip));
    }

    /** @return array<string,array{string}> */
    public static function ietfProtocolAssignmentsRangeProvider(): array
    {
        return [
            'range start' => ['192.0.0.0'],
            'mid-range'   => ['192.0.0.192'],
            'range end'   => ['192.0.0.255'],
        ];
    }

    public function testAllowsJustOutsideIetfProtocolAssignmentsRange(): void
    {
        $this->assertNull(NetworkAddressGuard::validatePublicHost('192.0.1.0'));
    }

    /**
     * @dataProvider benchmarkTestingRangeProvider
     */
    public function testBlocksBenchmarkTestingRange(string $ip): void
    {
        $this->assertNotNull(NetworkAddressGuard::validatePublicHost($ip));
    }

    /** @return array<string,array{string}> */
    public static function benchmarkTestingRangeProvider(): array
    {
        return [
            'range start' => ['198.18.0.0'],
            'mid-range'   => ['198.19.0.1'],
            'range end'   => ['198.19.255.255'],
        ];
    }

    public function testAllowsJustOutsideBenchmarkTestingRange(): void
    {
        $this->assertNull(NetworkAddressGuard::validatePublicHost('198.17.255.255'));
        $this->assertNull(NetworkAddressGuard::validatePublicHost('198.20.0.0'));
    }

    /**
     * @dataProvider documentationRangeProvider
     */
    public function testBlocksTestNetDocumentationRanges(string $ip): void
    {
        $this->assertNotNull(NetworkAddressGuard::validatePublicHost($ip));
    }

    /** @return array<string,array{string}> */
    public static function documentationRangeProvider(): array
    {
        return [
            'TEST-NET-1 start' => ['192.0.2.0'],
            'TEST-NET-1 end'   => ['192.0.2.255'],
            'TEST-NET-2 start' => ['198.51.100.0'],
            'TEST-NET-2 end'   => ['198.51.100.255'],
            'TEST-NET-3 start' => ['203.0.113.0'],
            'TEST-NET-3 end'   => ['203.0.113.255'],
        ];
    }

    public function testAllowsJustOutsideTestNetRanges(): void
    {
        $this->assertNull(NetworkAddressGuard::validatePublicHost('192.0.1.255'));
        $this->assertNull(NetworkAddressGuard::validatePublicHost('192.0.3.0'));
        $this->assertNull(NetworkAddressGuard::validatePublicHost('198.51.99.255'));
        $this->assertNull(NetworkAddressGuard::validatePublicHost('198.51.101.0'));
        $this->assertNull(NetworkAddressGuard::validatePublicHost('203.0.112.255'));
        $this->assertNull(NetworkAddressGuard::validatePublicHost('203.0.114.0'));
    }

    /**
     * @dataProvider multicastRangeProvider
     */
    public function testBlocksMulticastRange(string $ip): void
    {
        $this->assertNotNull(NetworkAddressGuard::validatePublicHost($ip));
    }

    /** @return array<string,array{string}> */
    public static function multicastRangeProvider(): array
    {
        return [
            'range start' => ['224.0.0.0'],
            'mid-range'   => ['230.1.2.3'],
            'range end'   => ['239.255.255.255'],
        ];
    }

    public function testAllowsJustBelowMulticastRange(): void
    {
        // The high boundary (240.0.0.0) is already blocked by PHP's own
        // FILTER_FLAG_NO_RES_RANGE, independent of this guard's extra ranges.
        $this->assertNull(NetworkAddressGuard::validatePublicHost('223.255.255.255'));
    }

    // -----------------------------------------------------------------------
    // IPv6 multicast (ff00::/8) — also missed by PHP's own filter
    // -----------------------------------------------------------------------

    /**
     * @dataProvider ipv6MulticastRangeProvider
     */
    public function testBlocksIpv6MulticastRange(string $ip): void
    {
        $this->assertNotNull(NetworkAddressGuard::validatePublicHost($ip));
    }

    /** @return array<string,array{string}> */
    public static function ipv6MulticastRangeProvider(): array
    {
        return [
            'range start'           => ['ff00::'],
            'link-local all-nodes'  => ['ff02::1'],
            'range end'             => ['ffff:ffff:ffff:ffff:ffff:ffff:ffff:ffff'],
        ];
    }

    public function testAllowsJustBelowIpv6MulticastRange(): void
    {
        $this->assertNull(NetworkAddressGuard::validatePublicHost('feff:ffff:ffff:ffff:ffff:ffff:ffff:ffff'));
    }

    // -----------------------------------------------------------------------
    // IPv4-mapped (::ffff:a.b.c.d) and NAT64 (64:ff9b::a.b.c.d) IPv6
    // addresses — the embedded IPv4 address must be checked, not just the
    // IPv6 wrapper, or every IPv4 rule above is bypassed outright.
    // -----------------------------------------------------------------------

    /**
     * @dataProvider mappedIpv4BypassProvider
     */
    public function testBlocksNonPublicIpv4EmbeddedInMappedIpv6(string $host): void
    {
        $this->assertNotNull(NetworkAddressGuard::validatePublicHost($host));
    }

    /** @return array<string,array{string}> */
    public static function mappedIpv4BypassProvider(): array
    {
        return [
            'IPv4-mapped loopback'            => ['[::ffff:127.0.0.1]'],
            'IPv4-mapped RFC1918 private'      => ['[::ffff:10.0.0.1]'],
            'IPv4-mapped link-local/metadata'  => ['[::ffff:169.254.169.254]'],
            'IPv4-mapped CGNAT (SEC-02 range)' => ['[::ffff:100.64.0.1]'],
            'NAT64 + private IPv4'             => ['[64:ff9b::10.0.0.1]'],
            'NAT64 + link-local/metadata'      => ['[64:ff9b::169.254.169.254]'],
        ];
    }

    /**
     * @dataProvider mappedIpv4PublicProvider
     */
    public function testAllowsPublicIpv4EmbeddedInMappedIpv6(string $host): void
    {
        $this->assertNull(NetworkAddressGuard::validatePublicHost($host));
    }

    /** @return array<string,array{string}> */
    public static function mappedIpv4PublicProvider(): array
    {
        return [
            'IPv4-mapped public' => ['[::ffff:8.8.8.8]'],
            'NAT64 + public'     => ['[64:ff9b::8.8.8.8]'],
        ];
    }
}
