<?php

declare(strict_types=1);

namespace Luster\Tests\Unit;

use Luster\Support\LegacyCatidMapper;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Tests for LegacyCatidMapper::buildMap().
 *
 * Covers the migration-51 scenarios:
 *   - Legacy 'category' (singular) table  — standard Enthusiast
 *   - Legacy 'categories' (plural) table  — Enthusiast fork variant
 *   - 'Anime/Manga: Foo' prefix stripping — matches Migration 49 normalisation
 *   - Source-aware disambiguation (TFL vs TAFL for identical category names)
 */
final class LegacyCatidMapperTest extends TestCase
{
    private function makeDb(string $lstrDdl = '', string $legacyDdl = ''): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec(
            "CREATE TABLE lstr_categories (
                id     INTEGER PRIMARY KEY AUTOINCREMENT,
                name   TEXT NOT NULL DEFAULT '',
                slug   TEXT NOT NULL DEFAULT '',
                source TEXT NOT NULL DEFAULT ''
            )"
        );
        if ($lstrDdl !== '') {
            $pdo->exec($lstrDdl);
        }
        if ($legacyDdl !== '') {
            $pdo->exec($legacyDdl);
        }
        return $pdo;
    }

    // -----------------------------------------------------------------------
    // No legacy table → null
    // -----------------------------------------------------------------------

    public function testReturnsNullWhenNoLegacyTableExists(): void
    {
        $pdo = $this->makeDb();
        $pdo->exec("INSERT INTO lstr_categories (name) VALUES ('Music')");

        self::assertNull(LegacyCatidMapper::buildMap($pdo));
    }

    // -----------------------------------------------------------------------
    // Singular 'category' table (standard Enthusiast)
    // -----------------------------------------------------------------------

    public function testMapsSingularCategoryTable(): void
    {
        $pdo = $this->makeDb(legacyDdl: "CREATE TABLE category (catid INTEGER PRIMARY KEY, catname TEXT)");
        $pdo->exec("INSERT INTO lstr_categories (name) VALUES ('Music')");
        $pdo->exec("INSERT INTO category (catid, catname) VALUES (7, 'Music')");

        $map     = LegacyCatidMapper::buildMap($pdo);
        $lstrId  = (int) $pdo->query("SELECT id FROM lstr_categories WHERE name = 'Music'")->fetchColumn();

        self::assertIsArray($map);
        self::assertSame($lstrId, $map[7] ?? null);
    }

    // -----------------------------------------------------------------------
    // Plural 'categories' table (Enthusiast fork variant)
    // -----------------------------------------------------------------------

    public function testMapsPluralCategoriesTable(): void
    {
        $pdo = $this->makeDb(legacyDdl: "CREATE TABLE categories (catid INTEGER PRIMARY KEY, catname TEXT)");
        $pdo->exec("INSERT INTO lstr_categories (name) VALUES ('Anime')");
        $pdo->exec("INSERT INTO categories (catid, catname) VALUES (3, 'Anime')");

        $map     = LegacyCatidMapper::buildMap($pdo);
        $lstrId  = (int) $pdo->query("SELECT id FROM lstr_categories WHERE name = 'Anime'")->fetchColumn();

        self::assertIsArray($map);
        self::assertSame($lstrId, $map[3] ?? null);
    }

    // -----------------------------------------------------------------------
    // Singular takes precedence when both tables are present
    // -----------------------------------------------------------------------

    public function testSingularTableTakesPrecedenceOverPlural(): void
    {
        $pdo = $this->makeDb(legacyDdl: "
            CREATE TABLE category   (catid INTEGER PRIMARY KEY, catname TEXT);
            CREATE TABLE categories (catid INTEGER PRIMARY KEY, catname TEXT)
        ");
        $pdo->exec("INSERT INTO lstr_categories (name) VALUES ('Music')");
        $pdo->exec("INSERT INTO category   (catid, catname) VALUES (1, 'Music')");
        $pdo->exec("INSERT INTO categories (catid, catname) VALUES (2, 'Music')");

        $map = LegacyCatidMapper::buildMap($pdo);

        self::assertIsArray($map);
        self::assertArrayHasKey(1, $map);    // came from singular table
        self::assertArrayNotHasKey(2, $map); // plural table not reached
    }

    // -----------------------------------------------------------------------
    // Anime/Manga: prefix stripping
    // -----------------------------------------------------------------------

    public function testStripsAnimeMangaPrefixBeforeMatching(): void
    {
        $pdo = $this->makeDb(legacyDdl: "CREATE TABLE category (catid INTEGER PRIMARY KEY, catname TEXT)");
        // lstr_categories has the stripped name (Migration 49 normalised them)
        $pdo->exec("INSERT INTO lstr_categories (name) VALUES ('Series')");
        // Legacy table still has the full Enthusiast name
        $pdo->exec("INSERT INTO category (catid, catname) VALUES (5, 'Anime/Manga: Series')");

        $map     = LegacyCatidMapper::buildMap($pdo);
        $lstrId  = (int) $pdo->query("SELECT id FROM lstr_categories WHERE name = 'Series'")->fetchColumn();

        self::assertIsArray($map);
        self::assertSame($lstrId, $map[5] ?? null);
    }

    public function testStripsAnimeMangaPrefixCaseInsensitively(): void
    {
        $pdo = $this->makeDb(legacyDdl: "CREATE TABLE category (catid INTEGER PRIMARY KEY, catname TEXT)");
        $pdo->exec("INSERT INTO lstr_categories (name) VALUES ('Movies')");
        $pdo->exec("INSERT INTO category (catid, catname) VALUES (8, 'ANIME/MANGA: Movies')");

        $map     = LegacyCatidMapper::buildMap($pdo);
        $lstrId  = (int) $pdo->query("SELECT id FROM lstr_categories WHERE name = 'Movies'")->fetchColumn();

        self::assertIsArray($map);
        self::assertSame($lstrId, $map[8] ?? null);
    }

    // -----------------------------------------------------------------------
    // Unresolvable catid excluded from map
    // -----------------------------------------------------------------------

    public function testUnresolvableCatidIsExcluded(): void
    {
        $pdo = $this->makeDb(legacyDdl: "CREATE TABLE category (catid INTEGER PRIMARY KEY, catname TEXT)");
        $pdo->exec("INSERT INTO lstr_categories (name) VALUES ('Music')");
        $pdo->exec("INSERT INTO category (catid, catname) VALUES (9, 'No Match In Luster')");

        $map = LegacyCatidMapper::buildMap($pdo);

        self::assertIsArray($map);
        self::assertArrayNotHasKey(9, $map);
    }

    // -----------------------------------------------------------------------
    // Plain match (no prefix) still works alongside prefix rows
    // -----------------------------------------------------------------------

    public function testMixedPrefixAndPlainRows(): void
    {
        $pdo = $this->makeDb(legacyDdl: "CREATE TABLE category (catid INTEGER PRIMARY KEY, catname TEXT)");
        $pdo->exec("INSERT INTO lstr_categories (name) VALUES ('Series')");
        $pdo->exec("INSERT INTO lstr_categories (name) VALUES ('Music')");
        $pdo->exec("INSERT INTO category (catid, catname) VALUES (1, 'Anime/Manga: Series')");
        $pdo->exec("INSERT INTO category (catid, catname) VALUES (2, 'Music')");

        $map      = LegacyCatidMapper::buildMap($pdo);
        $seriesId = (int) $pdo->query("SELECT id FROM lstr_categories WHERE name = 'Series'")->fetchColumn();
        $musicId  = (int) $pdo->query("SELECT id FROM lstr_categories WHERE name = 'Music'")->fetchColumn();

        self::assertIsArray($map);
        self::assertSame($seriesId, $map[1] ?? null);
        self::assertSame($musicId,  $map[2] ?? null);
    }

    public function testMapsOldTflTvStagePersonalitiesToPersonalities(): void
    {
        $pdo = $this->makeDb(legacyDdl: "CREATE TABLE category (catid INTEGER PRIMARY KEY, catname TEXT)");
        $pdo->exec("INSERT INTO lstr_categories (name, source) VALUES ('Personalities', 'tfl')");
        $pdo->exec("INSERT INTO category (catid, catname) VALUES (70, 'TV/Stage Personalities')");

        $map = LegacyCatidMapper::buildMap($pdo);
        $personalitiesId = (int) $pdo->query("SELECT id FROM lstr_categories WHERE name = 'Personalities'")->fetchColumn();

        self::assertIsArray($map);
        self::assertSame($personalitiesId, $map[70] ?? null);
    }

    public function testMapsOldTaflMangaKaDirectorsNameToCanonicalName(): void
    {
        $pdo = $this->makeDb(legacyDdl: "CREATE TABLE category (catid INTEGER PRIMARY KEY, catname TEXT)");
        $pdo->exec("INSERT INTO lstr_categories (name, source) VALUES ('Manga-ka & Directors', 'tafl')");
        $pdo->exec("INSERT INTO category (catid, catname) VALUES (16, 'Anime/Manga: Manga-ka/Directors')");

        $map = LegacyCatidMapper::buildMap($pdo);
        $canonicalId = (int) $pdo->query("SELECT id FROM lstr_categories WHERE name = 'Manga-ka & Directors'")->fetchColumn();

        self::assertIsArray($map);
        self::assertSame($canonicalId, $map[16] ?? null);
    }

    public function testMapsOldTaflMusicNameToMusicAndSongs(): void
    {
        $pdo = $this->makeDb(legacyDdl: "CREATE TABLE category (catid INTEGER PRIMARY KEY, catname TEXT)");
        $pdo->exec("INSERT INTO lstr_categories (name, source) VALUES ('Music & Songs', 'tafl')");
        $pdo->exec("INSERT INTO category (catid, catname) VALUES (18, 'Anime/Manga: Music')");

        $map = LegacyCatidMapper::buildMap($pdo);
        $canonicalId = (int) $pdo->query("SELECT id FROM lstr_categories WHERE name = 'Music & Songs'")->fetchColumn();

        self::assertIsArray($map);
        self::assertSame($canonicalId, $map[18] ?? null);
    }

    // -----------------------------------------------------------------------
    // Source-aware disambiguation — same name under both TFL and TAFL
    // -----------------------------------------------------------------------

    public function testPrefersTaflSourceForPrefixedAmbiguousName(): void
    {
        $pdo = $this->makeDb(legacyDdl: "CREATE TABLE category (catid INTEGER PRIMARY KEY, catname TEXT)");
        // Both TFL and TAFL have "Adult" — they get different auto-increment ids
        $pdo->exec("INSERT INTO lstr_categories (name, source) VALUES ('Adult', 'tfl')");
        $pdo->exec("INSERT INTO lstr_categories (name, source) VALUES ('Adult', 'tafl')");
        // Legacy Enthusiast stored this as an Anime/Manga subcategory — should resolve to TAFL
        $pdo->exec("INSERT INTO category (catid, catname) VALUES (10, 'Anime/Manga: Adult')");

        $map    = LegacyCatidMapper::buildMap($pdo);
        $taflId = (int) $pdo->query("SELECT id FROM lstr_categories WHERE name = 'Adult' AND source = 'tafl'")->fetchColumn();

        self::assertIsArray($map);
        self::assertSame($taflId, $map[10] ?? null);
    }

    public function testPrefersTflSourceForBareAmbiguousName(): void
    {
        $pdo = $this->makeDb(legacyDdl: "CREATE TABLE category (catid INTEGER PRIMARY KEY, catname TEXT)");
        $pdo->exec("INSERT INTO lstr_categories (name, source) VALUES ('Adult', 'tfl')");
        $pdo->exec("INSERT INTO lstr_categories (name, source) VALUES ('Adult', 'tafl')");
        // Legacy Enthusiast stored as a plain category name — should resolve to TFL
        $pdo->exec("INSERT INTO category (catid, catname) VALUES (11, 'Adult')");

        $map   = LegacyCatidMapper::buildMap($pdo);
        $tflId = (int) $pdo->query("SELECT id FROM lstr_categories WHERE name = 'Adult' AND source = 'tfl'")->fetchColumn();

        self::assertIsArray($map);
        self::assertSame($tflId, $map[11] ?? null);
    }
}
