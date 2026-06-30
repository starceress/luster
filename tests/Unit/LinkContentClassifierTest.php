<?php

declare(strict_types=1);

namespace Luster\Tests\Unit;

use Luster\Core\LinkContentClassifier;
use PHPUnit\Framework\TestCase;

/**
 * Tests LinkContentClassifier against fixture HTML bodies only — no live HTTP requests.
 */
final class LinkContentClassifierTest extends TestCase
{
    // -----------------------------------------------------------------------
    // classify() — combined result
    // -----------------------------------------------------------------------

    public function testClassifyReturnsExpectedShape(): void
    {
        $result = LinkContentClassifier::classify('<html><body>Hello world</body></html>');

        self::assertArrayHasKey('parked', $result);
        self::assertArrayHasKey('directory_listing', $result);
        self::assertArrayHasKey('meta_refresh', $result);
        self::assertIsBool($result['parked']);
        self::assertIsBool($result['directory_listing']);
    }

    public function testClassifyOrdinaryPageHasNoFlags(): void
    {
        $body   = '<html><head><title>My Fanlisting</title></head><body><h1>Welcome!</h1></body></html>';
        $result = LinkContentClassifier::classify($body);

        self::assertFalse($result['parked']);
        self::assertFalse($result['directory_listing']);
        self::assertNull($result['meta_refresh']);
    }

    // -----------------------------------------------------------------------
    // isParkedDomain()
    // -----------------------------------------------------------------------

    public function testDetectsDomainForSalePage(): void
    {
        $body = '<html><head><title>domain for sale</title></head><body><p>This domain is for sale. Make an offer.</p></body></html>';
        self::assertTrue(LinkContentClassifier::isParkedDomain($body));
    }

    public function testDetectsBuyThisDomain(): void
    {
        $body = '<html><body><h1>Buy This Domain</h1><p>The domain is available for purchase.</p></body></html>';
        self::assertTrue(LinkContentClassifier::isParkedDomain($body));
    }

    public function testDetectsSedoParking(): void
    {
        $body = '<html><body><!-- sedoparking --><p>This page hosted by Sedo.</p></body></html>';
        self::assertTrue(LinkContentClassifier::isParkedDomain($body));
    }

    public function testParkedDetectionIsCaseInsensitive(): void
    {
        $body = '<html><body><p>THIS DOMAIN IS FOR SALE</p></body></html>';
        self::assertTrue(LinkContentClassifier::isParkedDomain($body));
    }

    public function testNormalPageNotFlaggedAsParked(): void
    {
        $body = '<html><head><title>Archeology Fans</title></head><body><p>Welcome to the fanlisting.</p></body></html>';
        self::assertFalse(LinkContentClassifier::isParkedDomain($body));
    }

    // -----------------------------------------------------------------------
    // isDirectoryListing()
    // -----------------------------------------------------------------------

    public function testDetectsApacheIndexOf(): void
    {
        $body = '<html><head><title>Index of /var/www</title></head><body><h1>Index of /var/www</h1></body></html>';
        self::assertTrue(LinkContentClassifier::isDirectoryListing($body));
    }

    public function testDetectsNginxDirectoryListing(): void
    {
        $body = '<html><head><title>Index of /</title></head><body><pre>../</pre></body></html>';
        self::assertTrue(LinkContentClassifier::isDirectoryListing($body));
    }

    public function testNormalPageNotFlaggedAsDirectoryListing(): void
    {
        $body = '<html><head><title>Files and Downloads</title></head><body><p>Here are my fanlisting buttons:</p></body></html>';
        self::assertFalse(LinkContentClassifier::isDirectoryListing($body));
    }

    // -----------------------------------------------------------------------
    // extractMetaRefresh()
    // -----------------------------------------------------------------------

    public function testExtractsMetaRefreshUrl(): void
    {
        $body = '<html><head><meta http-equiv="refresh" content="0; url=https://example.com/new-page"></head></html>';
        self::assertSame('https://example.com/new-page', LinkContentClassifier::extractMetaRefresh($body));
    }

    public function testExtractsMetaRefreshWithDelay(): void
    {
        $body = '<html><head><meta http-equiv="refresh" content="5;url=https://newhost.net/"></head></html>';
        self::assertSame('https://newhost.net/', LinkContentClassifier::extractMetaRefresh($body));
    }

    public function testExtractsMetaRefreshWithReversedAttributeOrder(): void
    {
        $body = '<html><head><meta content="0; url=https://moved.example.com" http-equiv="refresh"></head></html>';
        self::assertSame('https://moved.example.com', LinkContentClassifier::extractMetaRefresh($body));
    }

    public function testExtractsMetaRefreshCaseInsensitive(): void
    {
        $body = '<html><head><META HTTP-EQUIV="Refresh" CONTENT="0; URL=https://upper.example.com"></head></html>';
        self::assertSame('https://upper.example.com', LinkContentClassifier::extractMetaRefresh($body));
    }

    public function testReturnsNullWhenNoMetaRefresh(): void
    {
        $body = '<html><head><title>No refresh here</title></head><body>Content</body></html>';
        self::assertNull(LinkContentClassifier::extractMetaRefresh($body));
    }

    public function testReturnsNullForMetaWithoutUrlInContent(): void
    {
        $body = '<html><head><meta http-equiv="refresh" content="10"></head></html>';
        self::assertNull(LinkContentClassifier::extractMetaRefresh($body));
    }
}
