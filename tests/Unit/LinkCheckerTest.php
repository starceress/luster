<?php

declare(strict_types=1);

namespace Luster\Tests\Unit;

use Luster\Core\LinkChecker;
use Luster\Core\UrlNormalizer;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class LinkCheckerTest extends TestCase
{
    // -----------------------------------------------------------------------
    // Helper — invoke a private static method via reflection
    // -----------------------------------------------------------------------

    private static function call(string $method, mixed ...$args): mixed
    {
        $rm = new ReflectionMethod(LinkChecker::class, $method);
        $rm->setAccessible(true);
        return $rm->invoke(null, ...$args);
    }

    // -----------------------------------------------------------------------
    // parseLocation
    // -----------------------------------------------------------------------

    public function testParseLocationReturnsHeaderValue(): void
    {
        $headers = ['HTTP/1.1 301 Moved', 'Location: https://example.com/new'];
        self::assertSame('https://example.com/new', self::call('parseLocation', $headers));
    }

    public function testParseLocationIsCaseInsensitive(): void
    {
        $headers = ['HTTP/1.1 301 Moved', 'location: https://example.com/new'];
        self::assertSame('https://example.com/new', self::call('parseLocation', $headers));
    }

    public function testParseLocationReturnsNullWhenAbsent(): void
    {
        $headers = ['HTTP/1.1 200 OK', 'Content-Type: text/html'];
        self::assertNull(self::call('parseLocation', $headers));
    }

    public function testParseLocationReturnsNullForEmptyHeaders(): void
    {
        self::assertNull(self::call('parseLocation', []));
    }

    // -----------------------------------------------------------------------
    // resolveRedirectTarget
    // -----------------------------------------------------------------------

    public function testResolveRedirectTargetReturnsNullWhenNoLocation(): void
    {
        self::assertNull(self::call('resolveRedirectTarget', 'https://example.com/page', null));
    }

    public function testResolveRedirectTargetReturnsPublicAbsoluteUrl(): void
    {
        self::assertSame(
            'https://dest.example.com/new',
            self::call('resolveRedirectTarget', 'https://example.com/page', 'https://dest.example.com/new'),
        );
    }

    public function testResolveRedirectTargetRejectsLoopbackHost(): void
    {
        self::assertNull(self::call('resolveRedirectTarget', 'https://example.com/page', 'http://127.0.0.1/admin'));
    }

    public function testResolveRedirectTargetRejectsPrivateHost(): void
    {
        self::assertNull(self::call('resolveRedirectTarget', 'https://example.com/page', 'http://192.168.1.1/'));
    }

    public function testResolveRedirectTargetRejectsLocalhostByName(): void
    {
        self::assertNull(self::call('resolveRedirectTarget', 'https://example.com/page', 'http://localhost/secret'));
    }

    // -----------------------------------------------------------------------
    // UrlNormalizer::resolveLocation
    // -----------------------------------------------------------------------

    public function testResolveAbsoluteUrlPassedThrough(): void
    {
        self::assertSame(
            'https://dest.com/new',
            UrlNormalizer::resolveLocation('https://origin.com/page', 'https://dest.com/new'),
        );
    }

    public function testResolveHttpAbsoluteUrlPassedThrough(): void
    {
        self::assertSame(
            'http://dest.com/new',
            UrlNormalizer::resolveLocation('http://origin.com/page', 'http://dest.com/new'),
        );
    }

    public function testResolveProtocolRelativeAddsSchemeFromRequest(): void
    {
        self::assertSame(
            'https://dest.com/new',
            UrlNormalizer::resolveLocation('https://origin.com/page', '//dest.com/new'),
        );
        self::assertSame(
            'http://dest.com/new',
            UrlNormalizer::resolveLocation('http://origin.com/page', '//dest.com/new'),
        );
    }

    public function testResolveRootRelativePrependOrigin(): void
    {
        self::assertSame(
            'https://example.com/new-path',
            UrlNormalizer::resolveLocation('https://example.com/old/page', '/new-path'),
        );
    }

    public function testResolveRootRelativePreservesPort(): void
    {
        self::assertSame(
            'https://example.com:8080/new',
            UrlNormalizer::resolveLocation('https://example.com:8080/old', '/new'),
        );
    }

    public function testResolveRelativePathAgainstDirectory(): void
    {
        self::assertSame(
            'https://example.com/dir/other.php',
            UrlNormalizer::resolveLocation('https://example.com/dir/page.php', 'other.php'),
        );
    }

    // -----------------------------------------------------------------------
    // UrlNormalizer::normalizeUrlForSearch
    // -----------------------------------------------------------------------

    public function testNormalizeStripsHttpScheme(): void
    {
        self::assertSame('example.com/path', UrlNormalizer::normalizeUrlForSearch('http://example.com/path'));
    }

    public function testNormalizeStripsHttpsScheme(): void
    {
        self::assertSame('example.com/path', UrlNormalizer::normalizeUrlForSearch('https://example.com/path'));
    }

    public function testNormalizeStripsWwwPrefix(): void
    {
        self::assertSame('example.com/path', UrlNormalizer::normalizeUrlForSearch('https://www.example.com/path'));
    }

    public function testNormalizeStripsTrailingSlash(): void
    {
        self::assertSame('example.com/path', UrlNormalizer::normalizeUrlForSearch('https://example.com/path/'));
    }

    public function testNormalizeRootUrlProducesJustHost(): void
    {
        self::assertSame('example.com', UrlNormalizer::normalizeUrlForSearch('https://example.com/'));
        self::assertSame('example.com', UrlNormalizer::normalizeUrlForSearch('https://example.com'));
    }

    public function testNormalizeLowercasesHost(): void
    {
        self::assertSame('example.com/path', UrlNormalizer::normalizeUrlForSearch('https://WWW.EXAMPLE.COM/path'));
    }

    public function testNormalizePreservesNestedPath(): void
    {
        self::assertSame(
            'example.com/luster/public',
            UrlNormalizer::normalizeUrlForSearch('https://example.com/luster/public'),
        );
    }

    public function testNormalizeMatchesHttpAndHttpsVariants(): void
    {
        self::assertSame(
            UrlNormalizer::normalizeUrlForSearch('http://example.com/base'),
            UrlNormalizer::normalizeUrlForSearch('https://example.com/base'),
        );
        self::assertSame(
            UrlNormalizer::normalizeUrlForSearch('https://www.example.com/base'),
            UrlNormalizer::normalizeUrlForSearch('https://example.com/base'),
        );
    }
}
