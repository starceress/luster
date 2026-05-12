<?php

declare(strict_types=1);

namespace Luster\Tests\Unit;

use Luster\Core\LinkChecker;
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
    // parseStatusCode
    // -----------------------------------------------------------------------

    public function testParseStatusCodeHttp11(): void
    {
        self::assertSame(200, self::call('parseStatusCode', 'HTTP/1.1 200 OK'));
        self::assertSame(301, self::call('parseStatusCode', 'HTTP/1.1 301 Moved Permanently'));
        self::assertSame(404, self::call('parseStatusCode', 'HTTP/1.0 404 Not Found'));
        self::assertSame(500, self::call('parseStatusCode', 'HTTP/1.1 500 Internal Server Error'));
    }

    public function testParseStatusCodeHttp2(): void
    {
        self::assertSame(200, self::call('parseStatusCode', 'HTTP/2 200'));
        self::assertSame(301, self::call('parseStatusCode', 'HTTP/2 301'));
    }

    public function testParseStatusCodeReturnsZeroOnEmpty(): void
    {
        self::assertSame(0, self::call('parseStatusCode', ''));
        self::assertSame(0, self::call('parseStatusCode', 'not a status line'));
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
    // resolveLocation
    // -----------------------------------------------------------------------

    public function testResolveAbsoluteUrlPassedThrough(): void
    {
        $result = self::call('resolveLocation', 'https://origin.com/page', 'https://dest.com/new');
        self::assertSame('https://dest.com/new', $result);
    }

    public function testResolveHttpAbsoluteUrlPassedThrough(): void
    {
        $result = self::call('resolveLocation', 'http://origin.com/page', 'http://dest.com/new');
        self::assertSame('http://dest.com/new', $result);
    }

    public function testResolveProtocolRelativeAddsSchemeFromRequest(): void
    {
        $result = self::call('resolveLocation', 'https://origin.com/page', '//dest.com/new');
        self::assertSame('https://dest.com/new', $result);

        $result = self::call('resolveLocation', 'http://origin.com/page', '//dest.com/new');
        self::assertSame('http://dest.com/new', $result);
    }

    public function testResolveRootRelativePrependOrigin(): void
    {
        $result = self::call('resolveLocation', 'https://example.com/old/page', '/new-path');
        self::assertSame('https://example.com/new-path', $result);
    }

    public function testResolveRootRelativePreservesPort(): void
    {
        $result = self::call('resolveLocation', 'https://example.com:8080/old', '/new');
        self::assertSame('https://example.com:8080/new', $result);
    }

    public function testResolveRelativePathAgainstDirectory(): void
    {
        $result = self::call('resolveLocation', 'https://example.com/dir/page.php', 'other.php');
        self::assertSame('https://example.com/dir/other.php', $result);
    }

    // -----------------------------------------------------------------------
    // normalizeUrlForSearch
    // -----------------------------------------------------------------------

    public function testNormalizeStripsHttpScheme(): void
    {
        $result = self::call('normalizeUrlForSearch', 'http://example.com/path');
        self::assertSame('example.com/path', $result);
    }

    public function testNormalizeStripsHttpsScheme(): void
    {
        $result = self::call('normalizeUrlForSearch', 'https://example.com/path');
        self::assertSame('example.com/path', $result);
    }

    public function testNormalizeStripsWwwPrefix(): void
    {
        $result = self::call('normalizeUrlForSearch', 'https://www.example.com/path');
        self::assertSame('example.com/path', $result);
    }

    public function testNormalizeStripsTrailingSlash(): void
    {
        $result = self::call('normalizeUrlForSearch', 'https://example.com/path/');
        self::assertSame('example.com/path', $result);
    }

    public function testNormalizeRootUrlProducesJustHost(): void
    {
        self::assertSame('example.com', self::call('normalizeUrlForSearch', 'https://example.com/'));
        self::assertSame('example.com', self::call('normalizeUrlForSearch', 'https://example.com'));
    }

    public function testNormalizeLowercasesHost(): void
    {
        $result = self::call('normalizeUrlForSearch', 'https://WWW.EXAMPLE.COM/path');
        self::assertSame('example.com/path', $result);
    }

    public function testNormalizePreservesNestedPath(): void
    {
        $result = self::call('normalizeUrlForSearch', 'https://example.com/luster/public');
        self::assertSame('example.com/luster/public', $result);
    }

    public function testNormalizeMatchesHttpAndHttpsVariants(): void
    {
        // Both variants normalize to the same needle, ensuring reciprocal
        // check succeeds regardless of which scheme the affiliate uses.
        $http  = self::call('normalizeUrlForSearch', 'http://example.com/base');
        $https = self::call('normalizeUrlForSearch', 'https://example.com/base');
        self::assertSame($http, $https);

        $www    = self::call('normalizeUrlForSearch', 'https://www.example.com/base');
        $nonwww = self::call('normalizeUrlForSearch', 'https://example.com/base');
        self::assertSame($www, $nonwww);
    }
}
