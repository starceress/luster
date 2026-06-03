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
        $file = $this->cacheDir . '/netdir_' . md5('tfl|example.com/fl') . '.json';
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
        $file = $this->cacheDir . '/netdir_' . md5('tfl|example.com/fl') . '.json';
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
        $file = $this->cacheDir . '/netdir_' . md5('tfl|example.com/fl') . '.json';
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
        $file = $this->cacheDir . '/netdir_' . md5('tfl|example.com/fl') . '.json';
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
     * Mirrors the production normalizeForDirectory + href-extraction logic:
     * scheme and www stripped, fragment stripped, host lowercased (only),
     * path/query kept as-is, trailing slash trimmed.
     */
    private function matchesHref(string $listingUrl, string $bodyHtml): bool
    {
        $normalize = static function (string $url): string {
            $url = preg_replace('#^(?:https?:)?//#i', '', trim($url)) ?? '';
            $url = preg_replace('#^www\.#i', '', $url)                ?? $url;
            $hashPos = strpos($url, '#');
            if ($hashPos !== false) {
                $url = substr($url, 0, $hashPos);
            }
            $hostEnd = strcspn($url, '/?');
            return rtrim(strtolower(substr($url, 0, $hostEnd)) . substr($url, $hostEnd), '/');
        };
        $needle = $normalize($listingUrl);
        if ($needle === '') {
            return false;
        }
        preg_match_all('/href=["\']([^"\']+)["\']/i', $bodyHtml, $m);
        foreach ($m[1] ?? [] as $href) {
            $href = html_entity_decode((string) $href, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if ($normalize($href) === $needle) {
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
        // http and https normalise to the same needle
        $body = '<a href="http://example.com/fl/">Visit</a>';
        self::assertTrue($this->matchesHref('https://example.com/fl/', $body));
    }

    public function testQueryStringsArePreserved(): void
    {
        // index.php?id=1 and index.php?id=2 must not match each other
        $body = '<a href="https://example.com/index.php?id=2">FL 2</a>';
        self::assertFalse($this->matchesHref('https://example.com/index.php?id=1', $body),
            'Query-string differences must not be collapsed');
    }

    public function testQueryStringMatchIsFound(): void
    {
        $body = '<a href="https://example.com/index.php?id=1">FL 1</a>';
        self::assertTrue($this->matchesHref('https://example.com/index.php?id=1', $body));
    }

    public function testFragmentIsStripped(): void
    {
        // href with a fragment must match the same URL without one
        $body = '<a href="https://example.com/fl/#section">Visit</a>';
        self::assertTrue($this->matchesHref('https://example.com/fl/', $body));
    }

    public function testPathCaseIsPreserved(): void
    {
        // /FL/ (upper-case path) must NOT match /fl/ — paths are case-sensitive
        $body = '<a href="https://example.com/fl/">Visit</a>';
        self::assertFalse($this->matchesHref('https://EXAMPLE.COM/FL/', $body),
            'Path case is significant — /FL/ and /fl/ are different pages');
    }

    public function testHtmlEntitiesInHrefAreDecoded(): void
    {
        // Search-result pages encode & as &amp; in href attributes
        $body = '<a href="https://example.com/index.php?id=1&amp;view=members">FL</a>';
        self::assertTrue($this->matchesHref('https://example.com/index.php?id=1&view=members', $body));
    }

    public function testProtocolRelativeHrefIsFound(): void
    {
        // TFL/TAFL may emit //example.com/fl/ (no scheme) in search results
        $body = '<a href="//example.com/fl/">Visit</a>';
        self::assertTrue($this->matchesHref('https://example.com/fl/', $body));
    }

    // -----------------------------------------------------------------------
    // maxLookupSeconds
    // -----------------------------------------------------------------------

    public function testMaxLookupSecondsCoversAllHops(): void
    {
        // Must be at least TIMEOUT × (MAX_HOPS + 1)
        $expected = NetworkDirectoryService::TIMEOUT * (NetworkDirectoryService::MAX_HOPS + 1);
        self::assertSame($expected, NetworkDirectoryService::maxLookupSeconds());
    }
}
