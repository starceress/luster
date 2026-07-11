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
    setting_value TEXT NULL
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
        self::assertNull($result['network_url']);
        self::assertSame([], $result['candidates']);
        self::assertSame('Unknown network.', $result['error']);
        self::assertNull($result['cache_age']);
    }

    public function testLookupReportsMissingCategoryForTfl(): void
    {
        $result = TflLookupService::lookup('HUNTR/X', 'tfl', $this->siteRepo(), null);

        self::assertNull($result['url']);
        self::assertNull($result['network_url']);
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
    // networkPageUrl() — the URL saved as network_url, deliberately the
    // "view everything in this category" page rather than a per-letter or
    // per-subcategory guess (TFL's start= parameter isn't alphabetical for
    // every category — see the method's own docblock).
    // -----------------------------------------------------------------------

    public function testNetworkPageUrlForTfl(): void
    {
        $url = $this->invokePrivate('networkPageUrl', ['tfl', 'animation']);

        self::assertSame('https://thefanlistings.org/browse.php?show=animation&start=*', $url);
    }

    public function testNetworkPageUrlForTafl(): void
    {
        $url = $this->invokePrivate('networkPageUrl', ['tafl', 'characters_0m']);

        self::assertSame('https://www.animefanlistings.com/browse/characters_0m/all', $url);
    }

    // -----------------------------------------------------------------------
    // clearCache()
    // -----------------------------------------------------------------------

    public function testClearCacheReturnsFalseForUnknownNetwork(): void
    {
        self::assertFalse(TflLookupService::clearCache('bogus', 'Animation', $this->siteRepo()));
    }

    public function testClearCacheReturnsFalseWhenNoCategoryAssigned(): void
    {
        self::assertFalse(TflLookupService::clearCache('tfl', null, $this->siteRepo()));
        self::assertFalse(TflLookupService::clearCache('tfl', '', $this->siteRepo()));
    }

    public function testClearCacheReturnsFalseForUnmappedCategory(): void
    {
        self::assertFalse(TflLookupService::clearCache('tfl', 'Anime/Manga', $this->siteRepo()));
    }

    public function testClearCacheClearsStoredCacheEntries(): void
    {
        $siteRepo = $this->siteRepo();
        $siteRepo->set('tfl_link_map_animation_json', '[{"text":"Stale Entry","url":"http://example.test/"}]');
        $siteRepo->set('tfl_link_map_animation_fetched_at', (string) time());

        $cleared = TflLookupService::clearCache('tfl', 'Animation', $siteRepo);

        self::assertTrue($cleared);
        self::assertNull($siteRepo->get('tfl_link_map_animation_json'));
        self::assertNull($siteRepo->get('tfl_link_map_animation_fetched_at'));
    }

    public function testClearCacheReturnsTrueEvenWhenNothingWasCachedYet(): void
    {
        // A valid, mapped category always resolves to a real cache key —
        // clearCache() reports success whether or not anything was cached
        // yet, distinct from the "can't even resolve a key" false cases above.
        self::assertTrue(TflLookupService::clearCache('tafl', 'Anime/Manga: Episodes', $this->siteRepo()));
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
        $html = '<div class="thelistings"><span class="two"><a href="http://noonvale.net/anita/" target="_blank">101 Dalmatians: Radcliffe, Anita</a></span></div>'
            . '<div class="thelistings"><span class="two"><a href="https://fan.nebuluna.com/huntrix/" target="_blank">KPop Demon Hunters: [+] HUNTR/X</a></span></div>';

        $links = $this->invokePrivate('parseTflEntries', [$html]);

        self::assertCount(2, $links);
        self::assertSame('https://fan.nebuluna.com/huntrix/', $links[1]['url']);
        self::assertSame('KPop Demon Hunters: [+] HUNTR/X', $links[1]['text']);
    }

    public function testParseTflEntriesIgnoresNonListingLinks(): void
    {
        $html = '<a href="browse.php?show=animation&amp;start=3">Characters</a>'
            . '<div class="thelistings"><span class="two"><a href="http://example.test/" target="_blank">Some Fanlisting</a></span></div>';

        $links = $this->invokePrivate('parseTflEntries', [$html]);

        self::assertCount(1, $links);
        self::assertSame('Some Fanlisting', $links[0]['text']);
    }

    public function testParseTflEntriesCapturesAlternatingRowsWithoutClassTwo(): void
    {
        // TFL zebra-stripes rows: every other <div class="thelistings"> wraps
        // a bare <span> with no class at all. A regex requiring class="two"
        // literally silently drops every one of these — confirmed live
        // against a real category page where entries alternated bare/two/
        // bare/two with zero exceptions across 644 consecutive entries.
        $html = '<div class="thelistings"><span><a href="http://a.test/" target="_blank">First Fanlisting</a></span></div>'
            . '<div class="thelistings"><span class="two"><a href="http://b.test/" target="_blank">Second Fanlisting</a></span></div>'
            . '<div class="thelistings"><span><a href="http://c.test/" target="_blank">Third Fanlisting</a></span></div>';

        $links = $this->invokePrivate('parseTflEntries', [$html]);

        self::assertCount(3, $links);
        self::assertSame('First Fanlisting', $links[0]['text']);
        self::assertSame('Second Fanlisting', $links[1]['text']);
        self::assertSame('Third Fanlisting', $links[2]['text']);
    }

    public function testParseTflEntriesSkipsEntriesWithNoLink(): void
    {
        // A handful of real entries are plain text with no href at all
        // (dead/pending listings) — nothing to extract, correctly skipped.
        $html = '<div class="thelistings"><span class="two">Some Dead Listing</span></div>'
            . '<div class="thelistings"><span><a href="http://real.test/" target="_blank">Real Fanlisting</a></span></div>';

        $links = $this->invokePrivate('parseTflEntries', [$html]);

        self::assertCount(1, $links);
        self::assertSame('Real Fanlisting', $links[0]['text']);
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
