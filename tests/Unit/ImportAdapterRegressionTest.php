<?php

declare(strict_types=1);

namespace Luster\Tests\Unit;

use Luster\Import\EnthusiastImportSource;
use Luster\Import\ListingAdminImportSource;
use Luster\Import\ImportOptions;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Regression tests for five import-adapter bug fixes:
 *
 *   Fix 1 — Enthusiast `category` (singular) table name
 *   Fix 2 — $tp scope propagated to all preview/run helpers (dump mode)
 *   Fix 3 — Listing Admin enth_ target: dbprefix stored with trailing underscore,
 *            member table created as {slug}_members
 *   Fix 4 — Listing Admin custom registry prefix (e.g. trex_ → registry = trex)
 *   Fix 5 — CodeSort collective scope (cat_fl=0 / code_fl=0) is covered by
 *            CodeSortImportSource — no adapter-level unit test needed here;
 *            the warning path is exercised in the preview helper.
 */
final class ImportAdapterRegressionTest extends TestCase
{
    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    private function makePdo(): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE,            PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        return $pdo;
    }

    private function enthAdapter(): EnthusiastImportSource
    {
        return new EnthusiastImportSource();
    }

    private function laAdapter(): ListingAdminImportSource
    {
        return new ListingAdminImportSource();
    }

    /**
     * Creates a minimal Luster `owned`-style target in $pdo.
     * Used when the import target schema is unprefixed Enthusiast.
     */
    private function seedOwnedTarget(PDO $pdo): void
    {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS `owned` (
                listingid   INTEGER PRIMARY KEY AUTOINCREMENT,
                dbtable     TEXT NOT NULL DEFAULT '',
                title       TEXT NOT NULL DEFAULT '',
                subject     TEXT NOT NULL DEFAULT '',
                email       TEXT NOT NULL DEFAULT '',
                url         TEXT NOT NULL DEFAULT '',
                catid       TEXT NOT NULL DEFAULT '',
                category_id INTEGER NULL,
                opened      TEXT NULL,
                status      INTEGER NOT NULL DEFAULT 2,
                imagefile   TEXT NULL,
                additional  TEXT NOT NULL DEFAULT '',
                dbserver    TEXT NOT NULL DEFAULT '',
                dbuser      TEXT NOT NULL DEFAULT '',
                dbpassword  TEXT NOT NULL DEFAULT '',
                dbdatabase  TEXT NOT NULL DEFAULT ''
            )"
        );
        $pdo->exec("CREATE TABLE IF NOT EXISTS lstr_categories (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, parent_id INTEGER NULL, source TEXT NOT NULL DEFAULT 'custom')");
        $pdo->exec("CREATE TABLE IF NOT EXISTS lstr_fanlisting_settings (fanlisting_id INTEGER PRIMARY KEY, members_per_page INTEGER NOT NULL DEFAULT 25, queue_updates INTEGER NOT NULL DEFAULT 0, members_template TEXT NULL, affiliates_template TEXT NULL, stats_template TEXT NULL, image TEXT NULL, last_updated TEXT NULL)");
    }

    /**
     * Creates a minimal Luster `enth_fanlistings`-style target in $pdo.
     * Used when the import target schema is enth_.
     */
    private function seedEnthTarget(PDO $pdo): void
    {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS `enth_fanlistings` (
                id              INTEGER PRIMARY KEY AUTOINCREMENT,
                dbprefix        TEXT NOT NULL DEFAULT '',
                name            TEXT NOT NULL DEFAULT '',
                subject         TEXT NOT NULL DEFAULT '',
                email           TEXT NOT NULL DEFAULT '',
                url             TEXT NOT NULL DEFAULT '',
                catid           TEXT NOT NULL DEFAULT '',
                category        TEXT NOT NULL DEFAULT '',
                category_id     INTEGER NULL,
                opened          TEXT NULL,
                upcoming        INTEGER NOT NULL DEFAULT 0,
                trouble         INTEGER NOT NULL DEFAULT 0,
                imagefile       TEXT NULL,
                additional_info TEXT NOT NULL DEFAULT '',
                dbserver        TEXT NOT NULL DEFAULT '',
                dbuser          TEXT NOT NULL DEFAULT '',
                dbpassword      TEXT NOT NULL DEFAULT '',
                dbdatabase      TEXT NOT NULL DEFAULT ''
            )"
        );
        $pdo->exec("CREATE TABLE IF NOT EXISTS lstr_categories (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, parent_id INTEGER NULL, source TEXT NOT NULL DEFAULT 'custom')");
        $pdo->exec("CREATE TABLE IF NOT EXISTS lstr_fanlisting_settings (fanlisting_id INTEGER PRIMARY KEY, members_per_page INTEGER NOT NULL DEFAULT 25, queue_updates INTEGER NOT NULL DEFAULT 0, members_template TEXT NULL, affiliates_template TEXT NULL, stats_template TEXT NULL, image TEXT NULL, last_updated TEXT NULL)");
    }

    // -----------------------------------------------------------------------
    // Fix 1 — detectSchema() uses `category` (singular)
    // -----------------------------------------------------------------------

    /**
     * Real Enthusiast unprefixed schema has a `category` table (singular).
     * detectSchema() must return 'enthusiast_unprefixed' when `category` exists.
     */
    public function testDetectSchemaIdentifiesUnprefixedByCategoryTable(): void
    {
        $src = $this->makePdo();
        $src->exec("CREATE TABLE `category` (catid INTEGER PRIMARY KEY, catname TEXT)");
        $src->exec("CREATE TABLE `owned` (listingid INTEGER PRIMARY KEY)");

        $schema = $this->enthAdapter()->detectSchema($src);

        $this->assertSame('enthusiast_unprefixed', $schema);
    }

    /**
     * A DB that has `categories` (plural, old/incorrect name) but NOT `category`
     * must NOT be detected as enthusiast_unprefixed.  Before Fix 1, detectSchema()
     * incorrectly matched the plural form.
     */
    public function testDetectSchemaDoesNotMatchPluralCategories(): void
    {
        $src = $this->makePdo();
        $src->exec("CREATE TABLE `categories` (catid INTEGER PRIMARY KEY, catname TEXT)");
        $src->exec("CREATE TABLE `owned` (listingid INTEGER PRIMARY KEY)");

        $schema = $this->enthAdapter()->detectSchema($src);

        // No `category` (singular) and no `enth_fanlistings` → unknown
        $this->assertSame('unknown', $schema);
    }

    /**
     * runCategories() reads from `category` (singular) for the unprefixed schema.
     * This confirms Fix 1 end-to-end by running a real import against an
     * in-memory SQLite DB whose registry uses `category`.
     */
    public function testRunCategoriesReadsFromSingularCategoryTable(): void
    {
        $src = $this->makePdo();
        $src->exec("CREATE TABLE `category` (catid INTEGER PRIMARY KEY, parent INTEGER NOT NULL DEFAULT 0, catname TEXT NOT NULL)");
        $src->exec("INSERT INTO `category` VALUES (1, 0, 'Music')");
        $src->exec("INSERT INTO `category` VALUES (2, 0, 'Anime')");

        $tgt = $this->makePdo();
        $tgt->exec("CREATE TABLE IF NOT EXISTS lstr_categories (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, parent_id INTEGER NULL, source TEXT NOT NULL DEFAULT 'custom')");

        $opts = ImportOptions::fromPost([
            'source'              => 'enthusiast',
            'conflict_strategy'   => 'skip',
            'import_categories'   => '1',
            '_dump_schema_variant' => 'enthusiast_unprefixed',
        ]);

        $result = $this->enthAdapter()->run($src, $tgt, $opts);

        $catRow = null;
        foreach ($result->rows as $row) {
            if ($row['type'] === 'Categories') {
                $catRow = $row;
                break;
            }
        }

        $this->assertNotNull($catRow, 'Run result must include a Categories row');
        $this->assertSame(2, $catRow['imported']);
        $this->assertEmpty($catRow['errors']);

        $count = (int) $tgt->query('SELECT COUNT(*) FROM lstr_categories')->fetchColumn();
        $this->assertSame(2, $count);
    }

    // -----------------------------------------------------------------------
    // Fix 2 — $tp propagated to preview and run helpers (dump-mode prefix)
    // -----------------------------------------------------------------------

    /**
     * previewCategories() must query the temp-prefixed table when tablePrefix is set.
     * Before Fix 2, $tp was undefined in the helper (evaluates to ''), causing a
     * table-not-found error for SQL dump mode imports.
     */
    public function testPreviewCategoriesUsesTablePrefixInDumpMode(): void
    {
        $src = $this->makePdo();
        // Simulate dump-mode: tables loaded under temp prefix 'tmp_x_'
        $src->exec("CREATE TABLE `tmp_x_category` (catid INTEGER PRIMARY KEY, parent INTEGER NOT NULL DEFAULT 0, catname TEXT NOT NULL)");
        $src->exec("INSERT INTO `tmp_x_category` VALUES (1, 0, 'Anime')");
        $src->exec("INSERT INTO `tmp_x_category` VALUES (2, 0, 'Music')");

        $opts = ImportOptions::fromPost([
            'source'               => 'enthusiast',
            'conflict_strategy'    => 'skip',
            'import_categories'    => '1',
            '_dump_table_prefix'   => 'tmp_x_',
            '_dump_schema_variant' => 'enthusiast_unprefixed',
        ]);

        $preview = $this->enthAdapter()->preview($src, $opts);

        $catRow = null;
        foreach ($preview->rows as $row) {
            if ($row['type'] === 'Categories') {
                $catRow = $row;
                break;
            }
        }

        $this->assertNotNull($catRow, 'Preview must include a Categories row when tablePrefix is set');
        $this->assertSame(2, $catRow['found'], 'Should count rows from the prefixed table');
    }

    /**
     * runCategories() must import from the temp-prefixed source table when tablePrefix is set.
     * Before Fix 2, $tp was undefined in runCategories() so dump imports read from the
     * un-prefixed bare table name (which does not exist), silently importing 0 rows.
     */
    public function testRunCategoriesUsesTablePrefixInDumpMode(): void
    {
        $src = $this->makePdo();
        $src->exec("CREATE TABLE `tmp_y_category` (catid INTEGER PRIMARY KEY, parent INTEGER NOT NULL DEFAULT 0, catname TEXT NOT NULL)");
        $src->exec("INSERT INTO `tmp_y_category` VALUES (1, 0, 'Games')");

        $tgt = $this->makePdo();
        $tgt->exec("CREATE TABLE IF NOT EXISTS lstr_categories (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, parent_id INTEGER NULL, source TEXT NOT NULL DEFAULT 'custom')");

        $opts = ImportOptions::fromPost([
            'source'               => 'enthusiast',
            'conflict_strategy'    => 'skip',
            'import_categories'    => '1',
            '_dump_table_prefix'   => 'tmp_y_',
            '_dump_schema_variant' => 'enthusiast_unprefixed',
        ]);

        $result = $this->enthAdapter()->run($src, $tgt, $opts);

        $catRow = null;
        foreach ($result->rows as $row) {
            if ($row['type'] === 'Categories') {
                $catRow = $row;
                break;
            }
        }

        $this->assertNotNull($catRow);
        $this->assertSame(1, $catRow['imported'], 'Should import from the temp-prefixed source table');
        $this->assertEmpty($catRow['errors']);
    }

    public function testEnthOwnedImportSeedsLastUpdatedWhenSettingsAreNotImported(): void
    {
        $src = $this->makePdo();
        $src->exec(
            "CREATE TABLE `owned` (
                listingid INTEGER PRIMARY KEY,
                dbtable TEXT NOT NULL DEFAULT '',
                title TEXT NOT NULL DEFAULT '',
                subject TEXT NOT NULL DEFAULT '',
                email TEXT NOT NULL DEFAULT '',
                url TEXT NOT NULL DEFAULT '',
                catid TEXT NOT NULL DEFAULT '',
                additional TEXT NOT NULL DEFAULT '',
                status INTEGER NOT NULL DEFAULT 2
            )"
        );
        $src->exec("INSERT INTO `owned` VALUES (7, 'demo', 'Demo', 'Demo', 'owner@test.com', 'https://demo.example.com', '', '', 2)");

        $tgt = $this->makePdo();
        $this->seedOwnedTarget($tgt);

        $opts = ImportOptions::fromPost([
            'source'                => 'enthusiast',
            'conflict_strategy'     => 'skip',
            'import_owned_listings' => '1',
            '_dump_schema_variant'  => 'enthusiast_unprefixed',
        ]);

        $result = $this->enthAdapter()->run($src, $tgt, $opts);

        $ownedRow = null;
        foreach ($result->rows as $row) {
            if ($row['type'] === 'Owned listings') {
                $ownedRow = $row;
                break;
            }
        }

        $this->assertNotNull($ownedRow);
        $this->assertSame(1, $ownedRow['imported']);
        $this->assertSame(
            $tgt->query('SELECT CURRENT_DATE')->fetchColumn(),
            $tgt->query('SELECT last_updated FROM lstr_fanlisting_settings WHERE fanlisting_id = 7')->fetchColumn()
        );
    }

    public function testEnthSettingsImportSeedsLastUpdatedWithoutBlockingSettings(): void
    {
        $src = $this->makePdo();
        $src->exec(
            "CREATE TABLE `owned` (
                listingid INTEGER PRIMARY KEY,
                dbtable TEXT NOT NULL DEFAULT '',
                title TEXT NOT NULL DEFAULT '',
                subject TEXT NOT NULL DEFAULT '',
                email TEXT NOT NULL DEFAULT '',
                url TEXT NOT NULL DEFAULT '',
                catid TEXT NOT NULL DEFAULT '',
                additional TEXT NOT NULL DEFAULT '',
                status INTEGER NOT NULL DEFAULT 2,
                perpage INTEGER NOT NULL DEFAULT 25,
                holdupdate INTEGER NOT NULL DEFAULT 0,
                listtemplate TEXT NOT NULL DEFAULT '',
                affiliatestemplate TEXT NOT NULL DEFAULT '',
                statstemplate TEXT NOT NULL DEFAULT '',
                imagefile TEXT NOT NULL DEFAULT ''
            )"
        );
        $src->exec("INSERT INTO `owned` VALUES (8, 'demo2', 'Demo 2', 'Demo 2', 'owner@test.com', 'https://demo2.example.com', '', '', 2, 42, 1, '<li>{name}</li>', '<a>{name}</a>', '<b>{count}</b>', '')");

        $tgt = $this->makePdo();
        $this->seedOwnedTarget($tgt);

        $opts = ImportOptions::fromPost([
            'source'                => 'enthusiast',
            'conflict_strategy'     => 'skip',
            'import_owned_listings' => '1',
            'import_settings'       => '1',
            '_dump_schema_variant'  => 'enthusiast_unprefixed',
        ]);

        $result = $this->enthAdapter()->run($src, $tgt, $opts);

        $settingsRow = null;
        foreach ($result->rows as $row) {
            if ($row['type'] === 'Listing settings') {
                $settingsRow = $row;
                break;
            }
        }

        $this->assertNotNull($settingsRow);
        $this->assertSame(1, $settingsRow['imported']);

        $row = $tgt->query('SELECT members_per_page, queue_updates, last_updated FROM lstr_fanlisting_settings WHERE fanlisting_id = 8')->fetch();
        $this->assertSame(42, (int) $row['members_per_page']);
        $this->assertSame(1, (int) $row['queue_updates']);
        $this->assertSame($tgt->query('SELECT CURRENT_DATE')->fetchColumn(), $row['last_updated']);
    }

    /**
     * previewMembers() must use tablePrefix when resolving the source registry table.
     * This verifies that the $options parameter added to previewMembers() works.
     */
    public function testPreviewMembersUsesTablePrefixInDumpMode(): void
    {
        $src = $this->makePdo();
        $src->exec("CREATE TABLE `tmp_z_owned` (listingid INTEGER PRIMARY KEY, dbtable TEXT NOT NULL, additional TEXT NOT NULL DEFAULT '')");
        $src->exec("INSERT INTO `tmp_z_owned` VALUES (1, 'huntrix', '')");
        $src->exec("CREATE TABLE `tmp_z_huntrix` (email TEXT PRIMARY KEY)");
        $src->exec("INSERT INTO `tmp_z_huntrix` VALUES ('fan@test.com')");
        $src->exec("INSERT INTO `tmp_z_huntrix` VALUES ('fan2@test.com')");

        $opts = ImportOptions::fromPost([
            'source'               => 'enthusiast',
            'conflict_strategy'    => 'skip',
            'import_members'       => '1',
            '_dump_table_prefix'   => 'tmp_z_',
            '_dump_schema_variant' => 'enthusiast_unprefixed',
        ]);

        $preview = $this->enthAdapter()->preview($src, $opts);

        $memRow = null;
        foreach ($preview->rows as $row) {
            if ($row['type'] === 'Members') {
                $memRow = $row;
                break;
            }
        }

        $this->assertNotNull($memRow);
        $this->assertSame(2, $memRow['found'], 'Should count members from the temp-prefixed table');
    }

    // -----------------------------------------------------------------------
    // Fix 3 — Listing Admin enth_ target: dbprefix stored with trailing underscore
    // -----------------------------------------------------------------------

    /**
     * When importing Listing Admin listings into an enth_fanlistings target,
     * the stored dbprefix must include a trailing underscore so that
     * MemberRepo::resolveTable('music_') = 'music_members' (not 'musicmembers').
     *
     * Before Fix 3, the code stored 'music' and MemberRepo resolved 'musicmembers',
     * which never matched the physical table 'music_members' that was created.
     */
    public function testListingAdminEnthTargetStoresDbprefixWithTrailingUnderscore(): void
    {
        $src = $this->makePdo();
        $src->exec(
            "CREATE TABLE `main` (
                id       INTEGER PRIMARY KEY,
                fNiq     TEXT NOT NULL DEFAULT '',
                title    TEXT NOT NULL DEFAULT '',
                subject  TEXT NOT NULL DEFAULT '',
                url      TEXT NOT NULL DEFAULT '',
                email    TEXT NOT NULL DEFAULT '',
                status   TEXT NOT NULL DEFAULT 'active',
                opened   TEXT NULL,
                imagefile TEXT NULL,
                catid    INTEGER NULL,
                fave_fields TEXT NOT NULL DEFAULT ''
            )"
        );
        $src->exec("INSERT INTO `main` (id, fNiq, title, subject, url) VALUES (1, 'music-fanlisting', 'My Music FL', 'Music', 'http://music.example.com')");
        $src->exec(
            "CREATE TABLE `members` (
                mID    INTEGER PRIMARY KEY,
                fNiq   INTEGER NOT NULL DEFAULT 0,
                mEmail TEXT NOT NULL DEFAULT '',
                mName  TEXT NOT NULL DEFAULT '',
                mExtra TEXT NOT NULL DEFAULT ''
            )"
        );

        $tgt = $this->makePdo();
        $this->seedEnthTarget($tgt);

        $opts = ImportOptions::fromPost([
            'source'            => 'listing-admin',
            'conflict_strategy' => 'skip',
            'import_listings'   => '1',
            '_dump_schema_variant' => 'listing_admin_3x',
        ]);

        $result = $this->laAdapter()->run($src, $tgt, $opts);

        $listRow = null;
        foreach ($result->rows as $row) {
            if ($row['type'] === 'Listings') {
                $listRow = $row;
                break;
            }
        }

        $this->assertNotNull($listRow, 'Run result must include Listings row');
        $this->assertSame(1, $listRow['imported']);

        // Verify dbprefix was stored with trailing underscore
        $stmt = $tgt->query("SELECT dbprefix FROM enth_fanlistings LIMIT 1");
        $dbprefix = (string) $stmt->fetchColumn();

        $this->assertStringEndsWith('_', $dbprefix, "dbprefix stored in enth_fanlistings must end with '_' so MemberRepo can resolve '{$dbprefix}members' correctly");
    }

    /**
     * The physical member table created during LA→enth_ import must be named
     * {slug}_members (e.g. 'music_members'), not bare 'music'.
     * Before Fix 3, the bare table 'music' was created, but MemberRepo would
     * look for 'music_members' (because it appends 'members' to the stored dbprefix).
     */
    public function testListingAdminEnthTargetCreatesMembersTableWithSuffix(): void
    {
        $src = $this->makePdo();
        $src->exec(
            "CREATE TABLE `main` (
                id       INTEGER PRIMARY KEY,
                fNiq     TEXT NOT NULL DEFAULT '',
                title    TEXT NOT NULL DEFAULT '',
                subject  TEXT NOT NULL DEFAULT '',
                url      TEXT NOT NULL DEFAULT '',
                email    TEXT NOT NULL DEFAULT '',
                status   TEXT NOT NULL DEFAULT 'active',
                opened   TEXT NULL,
                imagefile TEXT NULL,
                catid    INTEGER NULL,
                fave_fields TEXT NOT NULL DEFAULT ''
            )"
        );
        $src->exec("INSERT INTO `main` (id, fNiq, subject, url) VALUES (1, 'anime-fl', 'Anime', 'http://anime.example.com')");
        $src->exec(
            "CREATE TABLE `members` (
                mID    INTEGER PRIMARY KEY,
                fNiq   INTEGER NOT NULL DEFAULT 0,
                mEmail TEXT NOT NULL DEFAULT '',
                mName  TEXT NOT NULL DEFAULT '',
                mExtra TEXT NOT NULL DEFAULT ''
            )"
        );

        $tgt = $this->makePdo();
        $this->seedEnthTarget($tgt);

        $opts = ImportOptions::fromPost([
            'source'            => 'listing-admin',
            'conflict_strategy' => 'skip',
            'import_listings'   => '1',
            '_dump_schema_variant' => 'listing_admin_3x',
        ]);

        $this->laAdapter()->run($src, $tgt, $opts);

        // Read the generated dbprefix
        $stmt    = $tgt->query("SELECT dbprefix FROM enth_fanlistings LIMIT 1");
        $dbprefix = (string) $stmt->fetchColumn();

        // Derive expected physical table name: dbprefix + 'members'
        $expectedTable = $dbprefix . 'members';

        // Verify the physical table exists in the target
        $chk  = $tgt->query("SELECT name FROM sqlite_master WHERE type='table' AND name='{$expectedTable}'");
        $found = $chk->fetchColumn();

        $this->assertNotFalse($found, "Physical member table `{$expectedTable}` must exist in target after import");

        // Also confirm the bare slug table does NOT exist (regression guard)
        $bare = rtrim($dbprefix, '_');
        $chkBare = $tgt->query("SELECT name FROM sqlite_master WHERE type='table' AND name='{$bare}'");
        $this->assertFalse($chkBare->fetchColumn(), "Bare slug table `{$bare}` must NOT exist — the physical table is `{$expectedTable}`");
    }

    // -----------------------------------------------------------------------
    // Fix 4 — Listing Admin custom registry prefix
    // -----------------------------------------------------------------------

    /**
     * When $laTablePrefix = 'trex_', the registry table is 'trex' (not 'main'),
     * and other tables are 'trex_members', 'trex_categories', etc.
     * preview() must read from the correctly-derived table names.
     */
    public function testListingAdminCustomPrefixPreviewReadsFromDerivedRegistryTable(): void
    {
        $src = $this->makePdo();
        // Custom-prefix LA install: registry = 'trex', members = 'trex_members', etc.
        $src->exec(
            "CREATE TABLE `trex` (
                id       INTEGER PRIMARY KEY,
                fNiq     TEXT NOT NULL DEFAULT '',
                subject  TEXT NOT NULL DEFAULT '',
                url      TEXT NOT NULL DEFAULT ''
            )"
        );
        $src->exec("INSERT INTO `trex` VALUES (1, 'anime', 'Anime', 'http://anime.example.com')");
        $src->exec("INSERT INTO `trex` VALUES (2, 'music', 'Music', 'http://music.example.com')");
        $src->exec("CREATE TABLE `trex_members` (mID INTEGER PRIMARY KEY, fNiq INTEGER)");
        $src->exec("INSERT INTO `trex_members` VALUES (1, 1), (2, 1), (3, 2)");

        $opts = ImportOptions::fromPost([
            'source'             => 'listing-admin',
            'conflict_strategy'  => 'skip',
            'import_listings'    => '1',
            'import_members'     => '1',
            'la_table_prefix'    => 'trex_',
            '_dump_schema_variant' => 'listing_admin_3x',
        ]);

        $preview = $this->laAdapter()->preview($src, $opts);

        $listingsRow = null;
        $membersRow  = null;
        foreach ($preview->rows as $row) {
            if ($row['type'] === 'Owned Listings') { $listingsRow = $row; }
            if ($row['type'] === 'Members')         { $membersRow  = $row; }
        }

        $this->assertNotNull($listingsRow, 'Preview must include Owned listings row');
        $this->assertSame(2, $listingsRow['found'], 'Should find 2 listings from the custom-prefix registry table `trex`');

        $this->assertNotNull($membersRow, 'Preview must include Members row');
        $this->assertSame(3, $membersRow['found'], 'Should find 3 members from `trex_members`');
    }

    /**
     * detectSchema() must identify a custom-prefix LA install via the heuristic
     * that looks for a table where {table}_members and {table}_affiliates both exist.
     */
    public function testDetectSchemaIdentifiesCustomPrefixListingAdmin(): void
    {
        $src = $this->makePdo();
        $src->exec("CREATE TABLE `trex` (id INTEGER PRIMARY KEY)");
        $src->exec("CREATE TABLE `trex_members` (id INTEGER PRIMARY KEY)");
        $src->exec("CREATE TABLE `trex_affiliates` (id INTEGER PRIMARY KEY)");
        $src->exec("CREATE TABLE `trex_categories` (id INTEGER PRIMARY KEY)");

        $schema = $this->laAdapter()->detectSchema($src);

        $this->assertSame('listing_admin_3x', $schema);
    }

    /**
     * When laTablePrefix is empty, the default 'main' registry is used — existing behaviour
     * must not regress.
     */
    public function testListingAdminDefaultPrefixStillUsesMainTable(): void
    {
        $src = $this->makePdo();
        $src->exec(
            "CREATE TABLE `main` (
                id       INTEGER PRIMARY KEY,
                fNiq     TEXT NOT NULL DEFAULT '',
                subject  TEXT NOT NULL DEFAULT '',
                url      TEXT NOT NULL DEFAULT ''
            )"
        );
        $src->exec("INSERT INTO `main` VALUES (1, 'test', 'Test', 'http://test.example.com')");

        $opts = ImportOptions::fromPost([
            'source'             => 'listing-admin',
            'conflict_strategy'  => 'skip',
            'import_listings'    => '1',
            // la_table_prefix intentionally absent → empty string → use 'main'
        ]);

        $preview = $this->laAdapter()->preview($src, $opts);

        $listingsRow = null;
        foreach ($preview->rows as $row) {
            if ($row['type'] === 'Owned Listings') {
                $listingsRow = $row;
                break;
            }
        }

        $this->assertNotNull($listingsRow);
        $this->assertSame(1, $listingsRow['found'], 'Default prefix should still read from `main`');
    }

    // -----------------------------------------------------------------------
    // Category ID remapping — owned listings catid
    // -----------------------------------------------------------------------

    /**
     * When categories and owned listings are imported together, the catid stored
     * in owned must be the Luster lstr_categories.id assigned during this import
     * run — NOT the raw source catid.
     *
     * Scenario: source has catid=4 → "Music".  Target already has "Music" at
     * id=7 (pre-existing rows pushed the auto-increment past 4).  After import
     * the owned row must store catid='7', not '4'.
     */
    public function testRunOwnedListingsRemapsCatidViaImportedCategoryMap(): void
    {
        $src = $this->makePdo();
        $src->exec(
            "CREATE TABLE `category` (
                catid   INTEGER PRIMARY KEY,
                parent  INTEGER NOT NULL DEFAULT 0,
                catname TEXT    NOT NULL
            )"
        );
        $src->exec("INSERT INTO `category` VALUES (4, 0, 'Music')");

        $src->exec(
            "CREATE TABLE `owned` (
                listingid INTEGER PRIMARY KEY,
                dbtable   TEXT NOT NULL DEFAULT '',
                title     TEXT NOT NULL DEFAULT '',
                subject   TEXT NOT NULL DEFAULT '',
                email     TEXT NOT NULL DEFAULT '',
                url       TEXT NOT NULL DEFAULT '',
                catid     TEXT NOT NULL DEFAULT '',
                additional TEXT NOT NULL DEFAULT '',
                status    INTEGER NOT NULL DEFAULT 2
            )"
        );
        $src->exec(
            "INSERT INTO `owned` VALUES (1, 'music_fans', 'Music Fans', 'Music', 'owner@test.com', 'https://music.example.com', '4', '', 2)"
        );

        $tgt = $this->makePdo();
        $this->seedOwnedTarget($tgt);

        // Pre-populate target lstr_categories so that auto-increment assigns
        // id=7 to "Music" (not id=1, which would hide the remapping bug).
        for ($i = 1; $i <= 6; $i++) {
            $tgt->exec("INSERT INTO lstr_categories (name, source) VALUES ('Filler{$i}', 'custom')");
        }
        $tgt->exec("INSERT INTO lstr_categories (name, source, parent_id) VALUES ('Music', 'custom', NULL)");
        // Music is now at id=7 in the target.

        $opts = ImportOptions::fromPost([
            'source'                => 'enthusiast',
            'conflict_strategy'     => 'skip',
            'import_categories'     => '1',
            'import_owned_listings' => '1',
            '_dump_schema_variant'  => 'enthusiast_unprefixed',
        ]);

        $result = $this->enthAdapter()->run($src, $tgt, $opts);

        $ownedRow = null;
        foreach ($result->rows as $row) {
            if ($row['type'] === 'Owned listings') {
                $ownedRow = $row;
                break;
            }
        }

        $this->assertNotNull($ownedRow, 'Run result must include an Owned listings row');
        $this->assertSame(1, $ownedRow['imported']);
        $this->assertEmpty($ownedRow['errors']);

        $storedCatid = (string) $tgt->query(
            "SELECT catid FROM owned WHERE url = 'https://music.example.com'"
        )->fetchColumn();

        $this->assertSame('7', $storedCatid,
            'catid must be remapped to the Luster category ID (7), not the raw source ID (4)');
    }

    /**
     * When owned listings are imported WITHOUT importing categories first
     * (no catIdMap), the catid must be stored verbatim from the source.
     * This guards the no-map path against an unintended no-op change.
     */
    public function testRunOwnedListingsPassesCatidThroughWhenCategoriesNotImported(): void
    {
        $src = $this->makePdo();
        $src->exec(
            "CREATE TABLE `owned` (
                listingid INTEGER PRIMARY KEY,
                dbtable   TEXT NOT NULL DEFAULT '',
                title     TEXT NOT NULL DEFAULT '',
                subject   TEXT NOT NULL DEFAULT '',
                email     TEXT NOT NULL DEFAULT '',
                url       TEXT NOT NULL DEFAULT '',
                catid     TEXT NOT NULL DEFAULT '',
                additional TEXT NOT NULL DEFAULT '',
                status    INTEGER NOT NULL DEFAULT 2
            )"
        );
        $src->exec(
            "INSERT INTO `owned` VALUES (1, 'games_fans', 'Games Fans', 'Games', 'owner@test.com', 'https://games.example.com', '12', '', 2)"
        );

        $tgt = $this->makePdo();
        $this->seedOwnedTarget($tgt);

        $opts = ImportOptions::fromPost([
            'source'                => 'enthusiast',
            'conflict_strategy'     => 'skip',
            'import_owned_listings' => '1',   // categories intentionally NOT imported
            '_dump_schema_variant'  => 'enthusiast_unprefixed',
        ]);

        $result = $this->enthAdapter()->run($src, $tgt, $opts);

        $ownedRow = null;
        foreach ($result->rows as $row) {
            if ($row['type'] === 'Owned listings') {
                $ownedRow = $row;
                break;
            }
        }

        $this->assertNotNull($ownedRow);
        $this->assertSame(1, $ownedRow['imported']);

        $storedCatid = (string) $tgt->query(
            "SELECT catid FROM owned WHERE url = 'https://games.example.com'"
        )->fetchColumn();

        $this->assertSame('12', $storedCatid,
            'catid must pass through unchanged when no category map is available');
    }

    // -----------------------------------------------------------------------
    // Fix 3 + Fix 4 combined — custom LA prefix into owned target
    // -----------------------------------------------------------------------

    /**
     * A custom-prefix LA import (trex_) into an `owned`-schema target must:
     *   - Create member tables with the bare slug name (e.g. 'anime', not 'anime_members')
     *   - Store dbtable = bare slug in `owned`
     *
     * This guards against the LA prefix logic accidentally breaking owned-schema imports.
     */
    public function testListingAdminCustomPrefixIntoOwnedTargetUsesBareSlug(): void
    {
        $src = $this->makePdo();
        $src->exec(
            "CREATE TABLE `trex` (
                id       INTEGER PRIMARY KEY,
                fNiq     TEXT NOT NULL DEFAULT '',
                title    TEXT NOT NULL DEFAULT '',
                subject  TEXT NOT NULL DEFAULT '',
                url      TEXT NOT NULL DEFAULT '',
                email    TEXT NOT NULL DEFAULT '',
                status   TEXT NOT NULL DEFAULT 'active',
                opened   TEXT NULL,
                imagefile TEXT NULL,
                catid    INTEGER NULL,
                fave_fields TEXT NOT NULL DEFAULT ''
            )"
        );
        $src->exec("INSERT INTO `trex` (id, fNiq, title, subject, url) VALUES (1, 'anime', 'Anime FL', 'Anime', 'http://anime.example.com')");
        $src->exec(
            "CREATE TABLE `trex_members` (
                mID    INTEGER PRIMARY KEY,
                fNiq   INTEGER NOT NULL DEFAULT 0,
                mEmail TEXT NOT NULL DEFAULT '',
                mName  TEXT NOT NULL DEFAULT '',
                mExtra TEXT NOT NULL DEFAULT ''
            )"
        );

        $tgt = $this->makePdo();
        $this->seedOwnedTarget($tgt);

        $opts = ImportOptions::fromPost([
            'source'             => 'listing-admin',
            'conflict_strategy'  => 'skip',
            'import_listings'    => '1',
            'la_table_prefix'    => 'trex_',
            '_dump_schema_variant' => 'listing_admin_3x',
        ]);

        $this->laAdapter()->run($src, $tgt, $opts);

        // Owned target: dbtable should be the bare slug (e.g. 'anime')
        $stmt   = $tgt->query("SELECT dbtable FROM owned LIMIT 1");
        $dbtable = (string) $stmt->fetchColumn();

        $this->assertNotEmpty($dbtable, 'dbtable must not be empty');
        $this->assertStringEndsNotWith('_', $dbtable, 'For owned target, dbtable should be a bare slug without trailing underscore');

        // Physical member table should match the bare slug
        $chk   = $tgt->query("SELECT name FROM sqlite_master WHERE type='table' AND name='{$dbtable}'");
        $found = $chk->fetchColumn();
        $this->assertNotFalse($found, "Physical member table `{$dbtable}` must exist in target");
    }

    // -----------------------------------------------------------------------
    // categories_json — joined import
    // -----------------------------------------------------------------------

    /**
     * Helper: creates a minimal lstr_joined table in a target PDO.
     */
    private function createJoinedTable(PDO $pdo): void
    {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS lstr_joined (
                id              INTEGER PRIMARY KEY AUTOINCREMENT,
                name            TEXT NOT NULL DEFAULT '',
                url             TEXT NOT NULL DEFAULT '',
                image           TEXT NULL,
                category        TEXT NOT NULL DEFAULT '',
                categories_json TEXT NULL,
                category_id     INTEGER NULL,
                date_joined     TEXT NULL,
                active          INTEGER NOT NULL DEFAULT 1,
                status          TEXT NOT NULL DEFAULT 'approved',
                sort_order      INTEGER NOT NULL DEFAULT 0
            )"
        );
    }

    /**
     * When LA's jCategory contains a pipe-delimited ID string (e.g. '|12|'),
     * the import must resolve it through the source categories table and:
     *   - store the category name in lstr_joined.category
     *   - store a Luster category ID in lstr_joined.category_id
     *   - populate lstr_joined.categories_json
     */
    public function testListingAdminJoinedImportResolvesPipeDelimitedCategoryId(): void
    {
        $src = $this->makePdo();
        $src->exec(
            "CREATE TABLE `categories` (
                catid   INTEGER PRIMARY KEY,
                parent  INTEGER NOT NULL DEFAULT 0,
                catname TEXT    NOT NULL
            )"
        );
        $src->exec("INSERT INTO `categories` VALUES (12, 0, 'Movies')");

        $src->exec(
            "CREATE TABLE `joined` (
                jID       INTEGER PRIMARY KEY,
                jSubject  TEXT NOT NULL DEFAULT '',
                jURL      TEXT NOT NULL DEFAULT '',
                jImage    TEXT NULL,
                jCategory TEXT NOT NULL DEFAULT '',
                jStatus   INTEGER NOT NULL DEFAULT 1,
                jAdd      TEXT NULL
            )"
        );
        $src->exec("INSERT INTO `joined` VALUES (1, 'A Movie FL', 'http://movies.example.com', NULL, '|12|', 1, '2025-01-01')");

        $tgt = $this->makePdo();
        $tgt->exec("CREATE TABLE IF NOT EXISTS lstr_categories (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, parent_id INTEGER NULL, source TEXT NOT NULL DEFAULT 'custom')");
        $tgt->exec("INSERT INTO lstr_categories (name, source) VALUES ('Movies', 'tfl')");
        $this->createJoinedTable($tgt);

        $opts = ImportOptions::fromPost([
            'source'               => 'listing-admin',
            'conflict_strategy'    => 'skip',
            'import_joined'        => '1',
            '_dump_schema_variant' => 'listing_admin_3x',
        ]);

        $result = $this->laAdapter()->run($src, $tgt, $opts);

        $joinRow = null;
        foreach ($result->rows as $row) {
            if ($row['type'] === 'Joined listings') {
                $joinRow = $row;
                break;
            }
        }
        $this->assertNotNull($joinRow);
        $this->assertSame(1, $joinRow['imported']);
        $this->assertEmpty($joinRow['errors']);

        $row = $tgt->query(
            "SELECT category, categories_json, category_id FROM lstr_joined WHERE url = 'http://movies.example.com'"
        )->fetch();

        $this->assertSame('Movies', $row['category'],
            'category must be the resolved name, not the raw pipe-delimited ID string');
        $this->assertNotNull($row['categories_json'],
            'categories_json must be populated for imported joined listings');
        $decoded = json_decode((string) $row['categories_json'], true);
        $this->assertSame('Movies', $decoded[0]['name'] ?? '',
            'categories_json must contain the resolved category name');
        $this->assertSame(1, (int) $row['category_id'],
            'category_id must be the Luster ID for Movies');
    }

    /**
     * When the source joined.catid contains a pipe-delimited multi-value string
     * (e.g. |3|9|), all resolved category names must appear in categories_json.
     * The primary category/category_id fields hold the first resolved entry only.
     */
    public function testEnthusiastJoinedImportPreservesMultipleCategories(): void
    {
        $src = $this->makePdo();
        $src->exec(
            "CREATE TABLE `category` (
                catid   INTEGER PRIMARY KEY,
                parent  INTEGER NOT NULL DEFAULT 0,
                catname TEXT    NOT NULL
            )"
        );
        $src->exec("INSERT INTO `category` VALUES (3, 0, 'Movies')");
        $src->exec("INSERT INTO `category` VALUES (9, 0, 'Relationships: TV')");

        $src->exec(
            "CREATE TABLE `joined` (
                joinedid  INTEGER PRIMARY KEY,
                subject   TEXT NOT NULL DEFAULT '',
                url       TEXT NOT NULL DEFAULT '',
                catid     TEXT NOT NULL DEFAULT '',
                imagefile TEXT NULL,
                pending   INTEGER NOT NULL DEFAULT 0,
                added     TEXT NULL
            )"
        );
        // Pipe-delimited multi-category: |3|9|
        $src->exec("INSERT INTO `joined` VALUES (1, 'Multi-Cat FL', 'http://multi.example.com', '|3|9|', NULL, 0, '2025-06-01')");

        $tgt = $this->makePdo();
        $tgt->exec("CREATE TABLE IF NOT EXISTS lstr_categories (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, parent_id INTEGER NULL, source TEXT NOT NULL DEFAULT 'custom')");
        $tgt->exec("INSERT INTO lstr_categories (name, source) VALUES ('Movies', 'tfl')");
        $tgt->exec("INSERT INTO lstr_categories (name, source) VALUES ('Relationships: TV', 'tfl')");
        $this->createJoinedTable($tgt);

        $opts = ImportOptions::fromPost([
            'source'               => 'enthusiast',
            'conflict_strategy'    => 'skip',
            'import_joined'        => '1',
            '_dump_schema_variant' => 'enthusiast_unprefixed',
        ]);

        $result = $this->enthAdapter()->run($src, $tgt, $opts);

        $joinRow = null;
        foreach ($result->rows as $row) {
            if ($row['type'] === 'Joined listings') { $joinRow = $row; break; }
        }
        $this->assertNotNull($joinRow);
        $this->assertSame(1, $joinRow['imported']);
        $this->assertEmpty($joinRow['errors']);

        $row = $tgt->query(
            "SELECT category, categories_json, category_id FROM lstr_joined WHERE url = 'http://multi.example.com'"
        )->fetch();

        $this->assertSame('Movies', $row['category'],
            'primary category must be the first resolved name');
        $this->assertSame(1, (int) $row['category_id'],
            'category_id must point to Movies (id=1)');
        $this->assertNotNull($row['categories_json']);
        $decoded = json_decode((string) $row['categories_json'], true);
        $names = array_column($decoded, 'name');
        $this->assertContains('Movies', $names);
        $this->assertContains('Relationships: TV', $names,
            'categories_json must contain all resolved category names');
    }

    /**
     * Enthusiast joined import must populate lstr_joined.categories_json
     * with the single resolved category name.
     */
    public function testEnthusiastJoinedImportPopulatesCategoriesJson(): void
    {
        $src = $this->makePdo();
        $src->exec(
            "CREATE TABLE `category` (
                catid   INTEGER PRIMARY KEY,
                parent  INTEGER NOT NULL DEFAULT 0,
                catname TEXT    NOT NULL
            )"
        );
        $src->exec("INSERT INTO `category` VALUES (3, 0, 'TV Shows')");

        $src->exec(
            "CREATE TABLE `joined` (
                joinedid  INTEGER PRIMARY KEY,
                subject   TEXT NOT NULL DEFAULT '',
                url       TEXT NOT NULL DEFAULT '',
                catid     INTEGER NOT NULL DEFAULT 0,
                imagefile TEXT NULL,
                pending   INTEGER NOT NULL DEFAULT 0,
                added     TEXT NULL
            )"
        );
        $src->exec("INSERT INTO `joined` VALUES (1, 'Best TV FL', 'http://tv.example.com', 3, NULL, 0, '2025-03-01')");

        $tgt = $this->makePdo();
        $tgt->exec("CREATE TABLE IF NOT EXISTS lstr_categories (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, parent_id INTEGER NULL, source TEXT NOT NULL DEFAULT 'custom')");
        $tgt->exec("INSERT INTO lstr_categories (name, source) VALUES ('TV Shows', 'tfl')");
        $this->createJoinedTable($tgt);

        $opts = ImportOptions::fromPost([
            'source'               => 'enthusiast',
            'conflict_strategy'    => 'skip',
            'import_joined'        => '1',
            '_dump_schema_variant' => 'enthusiast_unprefixed',
        ]);

        $result = $this->enthAdapter()->run($src, $tgt, $opts);

        $joinRow = null;
        foreach ($result->rows as $row) {
            if ($row['type'] === 'Joined listings') {
                $joinRow = $row;
                break;
            }
        }
        $this->assertNotNull($joinRow);
        $this->assertSame(1, $joinRow['imported']);
        $this->assertEmpty($joinRow['errors']);

        $row = $tgt->query(
            "SELECT category, categories_json, category_id FROM lstr_joined WHERE url = 'http://tv.example.com'"
        )->fetch();

        $this->assertSame('TV Shows', $row['category']);
        $this->assertNotNull($row['categories_json']);
        $decoded = json_decode((string) $row['categories_json'], true);
        $this->assertSame('TV Shows', $decoded[0]['name'] ?? '');
        $this->assertSame(1, (int) $row['category_id']);
    }
}
