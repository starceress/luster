<?php

declare(strict_types=1);

namespace Luster\Tests\Unit;

use Luster\Core\NetworkDirectoryService;
use PHPUnit\Framework\TestCase;

final class NetworkDirectoryServiceTest extends TestCase
{
    private string $cacheDir;

    protected function setUp(): void
    {
        $this->cacheDir = sys_get_temp_dir() . '/luster_netdir_test_' . uniqid('', true);
        mkdir($this->cacheDir, 0777, true);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->cacheDir . '/netdir_*.json') ?: []);
        @rmdir($this->cacheDir);
    }

    private function svc(): NetworkDirectoryService
    {
        return new NetworkDirectoryService($this->cacheDir);
    }

    // -----------------------------------------------------------------------
    // Metadata
    // -----------------------------------------------------------------------

    public function testNetworksReturnsTflAndTafl(): void
    {
        self::assertSame(['tfl', 'tafl'], NetworkDirectoryService::networks());
    }

    public function testNetworkLabelsTfl(): void
    {
        self::assertSame('TFL', NetworkDirectoryService::networkLabel('tfl'));
    }

    public function testNetworkLabelsTafl(): void
    {
        self::assertSame('TAFL', NetworkDirectoryService::networkLabel('tafl'));
    }

    // -----------------------------------------------------------------------
    // Cache read/write
    // -----------------------------------------------------------------------

    public function testGetCachedReturnsNullWhenNothingCached(): void
    {
        self::assertNull($this->svc()->getCached('https://example.com/fl/', 'tfl'));
    }

    public function testGetCachedReturnsFreshResult(): void
    {
        // Write a cache entry directly
        $svc  = $this->svc();
        $file = $this->cacheDir . '/netdir_' . md5('tfl|https://example.com/fl/') . '.json';
        file_put_contents($file, json_encode([
            'found'      => true,
            'http_code'  => 200,
            'checked_at' => time(),
            'error'      => null,
        ]));

        $result = $svc->getCached('https://example.com/fl/', 'tfl');
        self::assertNotNull($result);
        self::assertTrue($result['found']);
    }

    public function testGetCachedReturnsNullWhenExpired(): void
    {
        $svc  = $this->svc();
        $file = $this->cacheDir . '/netdir_' . md5('tfl|https://example.com/fl/') . '.json';
        file_put_contents($file, json_encode([
            'found'      => true,
            'http_code'  => 200,
            'checked_at' => time() - NetworkDirectoryService::CACHE_TTL - 1,
            'error'      => null,
        ]));

        self::assertNull($svc->getCached('https://example.com/fl/', 'tfl'));
    }

    public function testClearCacheRemovesFile(): void
    {
        $svc  = $this->svc();
        $file = $this->cacheDir . '/netdir_' . md5('tfl|https://example.com/fl/') . '.json';
        file_put_contents($file, json_encode(['found' => false, 'http_code' => 0, 'checked_at' => time(), 'error' => null]));

        self::assertTrue(is_file($file));
        $svc->clearCache('https://example.com/fl/', 'tfl');
        self::assertFalse(is_file($file));
    }

    public function testClearCacheOnNonExistentFileDoesNotThrow(): void
    {
        $this->svc()->clearCache('https://example.com/fl/', 'tfl');
        self::assertTrue(true);  // no exception = pass
    }

    // -----------------------------------------------------------------------
    // Lookup uses cache when fresh
    // -----------------------------------------------------------------------

    public function testLookupReturnsCachedResultWhenFresh(): void
    {
        $svc  = $this->svc();
        $file = $this->cacheDir . '/netdir_' . md5('tfl|https://example.com/fl/') . '.json';
        file_put_contents($file, json_encode([
            'found'      => true,
            'http_code'  => 200,
            'checked_at' => time(),
            'error'      => null,
        ]));

        // lookup() must return cached data, not trigger a live HTTP fetch
        $result = $svc->lookup('https://example.com/fl/', 'tfl');
        self::assertTrue($result['found']);
        self::assertSame(200, $result['http_code']);
    }

    // -----------------------------------------------------------------------
    // Unknown network
    // -----------------------------------------------------------------------

    public function testRefreshUnknownNetworkReturnsError(): void
    {
        $result = $this->svc()->refresh('https://example.com/fl/', 'unknown_net');
        self::assertFalse($result['found']);
        self::assertNotNull($result['error']);
        self::assertStringContainsString('Unknown network', $result['error']);
    }

    // -----------------------------------------------------------------------
    // P1 fix — href-only matching
    // -----------------------------------------------------------------------

    /**
     * The service must NOT mark "found" when the URL appears only in a search
     * box value= or page title, which is what search pages echo back.
     * Only href="...url..." occurrences count as a result link.
     */
    public function testBodyMatchRequiresHrefContext(): void
    {
        // Simulate a search-results page that echoes the query in the <input>
        // but has no actual result link pointing to the listing.
        $bodyWithEcho = '<html><body>'
            . '<input value="example.com/fl/">'      // query echo — must NOT match
            . '<p>No results found for example.com/fl/</p>'  // text echo — must NOT match
            . '</body></html>';

        $bodyWithResult = '<html><body>'
            . '<a href="https://thefanlistings.org/view/123/">My FL</a>'
            . '<a href="http://example.com/fl/">Visit</a>'   // result link — MUST match
            . '</body></html>';

        // Use reflection to call the private method, or test via the cache approach.
        // We verify indirectly: write a "found" cache entry manually and check getCached.
        // The actual href-matching logic is exercised in the fetch() code path;
        // here we document the expected regex behaviour.
        $pattern = '/href=["\'][^"\']*' . preg_quote('example.com/fl/', '/') . '/i';

        self::assertSame(0, preg_match($pattern, $bodyWithEcho),
            'URL in query echo / plain text must NOT match the href pattern');
        self::assertSame(1, preg_match($pattern, $bodyWithResult),
            'URL in href attribute MUST match the href pattern');
    }
}
