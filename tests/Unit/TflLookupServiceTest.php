<?php

declare(strict_types=1);

namespace Luster\Tests\Unit;

use Luster\Core\TflLookupService;
use Luster\Repositories\SiteSettingsRepo;
use Luster\Tests\Unit\Support\SqliteDatabase;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Tests for TflLookupService — the TFL/TAFL approval-URL lookup helper.
 *
 * Network fetches (LinkChecker::safeGet) are not mocked here, so these tests
 * cover everything reachable without live HTTP: the "no category"/"unmapped
 * category" early-return paths in lookup(), the category→slug tables
 * themselves, and the private HTML-parsing methods invoked directly via
 * reflection against small canned snippets modeled on each network's real
 * markup (captured while diagnosing why lookups always failed).
 */
final class TflLookupServiceTest extends TestCase
{
    private const SITE_SETTINGS_DDL = <<<SQL
CREATE TABLE lstr_site_settings (
    setting_key   TEXT NOT NULL PRIMARY KEY,
    setting_value TEXT NOT NULL DEFAULT ''
)
SQL;

    private function siteRepo(): SiteSettingsRepo
    {
        return new SiteSettingsRepo(SqliteDatabase::make(self::SITE_SETTINGS_DDL));
    }

    private function invokePrivate(string $method, array $args): mixed
    {
        $ref = new ReflectionMethod(TflLookupService::class, $method);
        $ref->setAccessible(true);
        return $ref->invokeArgs(null, $args);
    }

    // -----------------------------------------------------------------------
    // lookup() early-return paths (no network access needed)
    // -----------------------------------------------------------------------

    public function testLookupRejectsUnknownNetwork(): void
    {
        $result = TflLookupService::lookup('Some Subject', 'bogus', $this->siteRepo(), 'Animation');

        self::assertNull($result['url']);
        self::assertSame([], $result['candidates']);
        self::assertSame('Unknown network.', $result['error']);
        self::assertNull($result['cache_age']);
    }

    public function testLookupReportsMissingCategoryForTfl(): void
    {
        $result = TflLookupService::lookup('HUNTR/X', 'tfl', $this->siteRepo(), null);

        self::assertNull($result['url']);
        self::assertStringContainsString('no TFL category assigned', $result['error']);
    }

    public function testLookupReportsMissingCategoryForTafl(): void
    {
        $result = TflLookupService::lookup('HUNTR/X', 'tafl', $this->siteRepo(), '');

        self::assertNull($result['url']);
        self::assertStringContainsString('no TAFL category assigned', $result['error']);
    }

    public function testLookupReportsUnmappedTflCategory(): void
    {
        $result = TflLookupService::lookup('HUNTR/X', 'tfl', $this->siteRepo(), 'Anime/Manga');

        self::assertNull($result['url']);
        self::assertStringContainsString('isn\'t available yet', $result['error']);
        self::assertStringContainsString('Anime/Manga', $result['error']);
    }

    public function testLookupReportsUnmappedTaflCategory(): void
    {
        $result = TflLookupService::lookup('HUNTR/X', 'tafl', $this->siteRepo(), 'Anime/Manga: Toys/Collectibles');

        self::assertNull($result['url']);
        self::assertStringContainsString('isn\'t available yet', $result['error']);
    }

    // -----------------------------------------------------------------------
    // Category → slug tables
    // -----------------------------------------------------------------------

    public function testTflCategorySlugsAreAllValidUrlSegments(): void
    {
        $ref  = new ReflectionClass(TflLookupService::class);
        $map  = $ref->getConstant('TFL_CATEGORY_SLUGS');

        self::assertNotEmpty($map);
        foreach ($map as $name => $slug) {
            self::assertMatchesRegularExpression('/^[a-z0-9_]+$/', $slug, "Slug for \"{$name}\" must be a safe URL segment");
        }
    }

    public function testTaflCategorySlugsAreAllValidUrlSegments(): void
    {
        $ref = new ReflectionClass(TflLookupService::class);
        $map = $ref->getConstant('TAFL_CATEGORY_SLUGS');

        self::assertNotEmpty($map);
        foreach ($map as $name => $slug) {
            self::assertMatchesRegularExpression('/^[a-z0-9_]+$/', $slug, "Slug for \"{$name}\" must be a safe URL segment");
        }
    }

    public function testTflAnimationCategoryMapsToKnownSlug(): void
    {
        $ref = new ReflectionClass(TflLookupService::class);
        $map = $ref->getConstant('TFL_CATEGORY_SLUGS');

        self::assertSame('animation', $map['Animation']);
    }

    public function testTaflCharacterCategoriesMapToKnownSlugs(): void
    {
        $ref = new ReflectionClass(TflLookupService::class);
        $map = $ref->getConstant('TAFL_CATEGORY_SLUGS');

        self::assertSame('characters_0m', $map['Anime/Manga: Characters 0-M']);
        self::assertSame('characters_nz', $map['Anime/Manga: Characters N-Z']);
    }

    // -----------------------------------------------------------------------
    // parseTflEntries() — real TFL browse-page markup
    // -----------------------------------------------------------------------

    public function testParseTflEntriesExtractsListingSpans(): void
    {
        $html = '<span class="two"><a href="http://noonvale.net/anita/" target="_blank">101 Dalmatians: Radcliffe, Anita</a></span>'
            . '<span class="two"><a href="https://fan.nebuluna.com/huntrix/" target="_blank">KPop Demon Hunters: [+] HUNTR/X</a></span>';

        $links = $this->invokePrivate('parseTflEntries', [$html]);

        self::assertCount(2, $links);
        self::assertSame('https://fan.nebuluna.com/huntrix/', $links[1]['url']);
        self::assertSame('KPop Demon Hunters: [+] HUNTR/X', $links[1]['text']);
    }

    public function testParseTflEntriesIgnoresNonListingLinks(): void
    {
        $html = '<a href="browse.php?show=animation&amp;start=3">Characters</a>'
            . '<span class="two"><a href="http://example.test/" target="_blank">Some Fanlisting</a></span>';

        $links = $this->invokePrivate('parseTflEntries', [$html]);

        self::assertCount(1, $links);
        self::assertSame('Some Fanlisting', $links[0]['text']);
    }

    // -----------------------------------------------------------------------
    // parseTaflEntries() — real TAFL "view all" page markup
    // -----------------------------------------------------------------------

    public function testParseTaflEntriesExtractsExternalListingLinks(): void
    {
        $html = '<a href="http://www.after-god.net" target="_blank">After God</a><br />'
            . '<a href="http://fan.enamour.nu/ams" target="_blank">Ah! Megami-sama (Oh! My Goddess)</a><br />';

        $links = $this->invokePrivate('parseTaflEntries', [$html]);

        self::assertCount(2, $links);
        self::assertSame('After God', $links[0]['text']);
        self::assertSame('http://www.after-god.net', $links[0]['url']);
    }

    public function testParseTaflEntriesExcludesInternalNavigationLinks(): void
    {
        $html = '<a href="http://www.twitter.com/animefanlisting" target="_blank">Twitter</a>'
            . '<a href="http://board.animefanlistings.com" target="_blank">Message Board</a>'
            . '<a href="http://example.test/listing" target="_blank">Real Fanlisting</a>';

        $links = $this->invokePrivate('parseTaflEntries', [$html]);

        self::assertCount(1, $links);
        self::assertSame('Real Fanlisting', $links[0]['text']);
    }
}
