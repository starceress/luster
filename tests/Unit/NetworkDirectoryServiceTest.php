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
    // Href matching logic (production normalization)
    // -----------------------------------------------------------------------

    /**
     * Exercises the exact logic the production fetch() uses: extract href values,
     * normalize each with UrlNormalizer::normalizeUrlForSearch(), compare exact strings.
     */
    private function matchesHref(string $listingUrl, string $bodyHtml): bool
    {
        $needle = \Luster\Core\UrlNormalizer::normalizeUrlForSearch($listingUrl);
        if ($needle === '') {
            return false;
        }
        preg_match_all('/href=["\']([^"\']+)["\']/i', $bodyHtml, $m);
        foreach ($m[1] ?? [] as $href) {
            if (\Luster\Core\UrlNormalizer::normalizeUrlForSearch((string) $href) === $needle) {
                return true;
            }
        }
        return false;
    }

    public function testQueryEchoInSearchBoxDoesNotMatch(): void
    {
        $body = '<html><body>'
            . '<input value="example.com/fl/">'
            . '<p>No results for example.com/fl/</p>'
            . '</body></html>';

        self::assertFalse($this->matchesHref('https://example.com/fl/', $body));
    }

    public function testExactHrefMatchIsFound(): void
    {
        $body = '<a href="https://thefanlistings.org/view/1/">TFL page</a>'
              . '<a href="http://example.com/fl/">Visit listing</a>';

        self::assertTrue($this->matchesHref('https://example.com/fl/', $body));
    }

    public function testPrefixUrlDoesNotFalsePositive(): void
    {
        // example.com/fl must NOT match example.com/fl2/ or example.com/fl-old/
        $body = '<a href="https://example.com/fl2/">FL 2</a>'
              . '<a href="https://example.com/fl-old/">Old FL</a>';

        self::assertFalse($this->matchesHref('https://example.com/fl/', $body),
            'Prefix URLs must not trigger a false positive');
    }

    public function testSchemeDifferenceIsIgnored(): void
    {
        // http and https should normalise to the same needle
        $body = '<a href="http://example.com/fl/">Visit</a>';
        self::assertTrue($this->matchesHref('https://example.com/fl/', $body));
    }
}
