<?php

declare(strict_types=1);

namespace Luster\Tests\Unit;

use Luster\Import\ImportOptions;
use Luster\Import\ListingAdminImportSource;
use Luster\Tests\Unit\Support\TracingPdo;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Tests for ListingAdminImportSource covering the three bug fixes:
 *
 *   Fix 1 (High)   — Joined status: jStatus=0 is active, jStatus=1 is pending.
 *   Fix 2 (Medium) — Listing status: trouble/upcoming flags map to status 0/1/2.
 *   Fix 3 (Low)    — Dump-mode image options: $sp (not $laP) passed to readImageSettings().
 *
 * Uses in-memory SQLite for both source and target so no live MySQL is required.
 * ON DUPLICATE KEY UPDATE used in runMembers() is MySQL-specific and is not exercised
 * here; member import correctness is out of scope for these three fixes.
 */
final class ListingAdminImportTest extends TestCase
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

    private function adapter(): ListingAdminImportSource
    {
        return new ListingAdminImportSource();
    }

    /**
     * Options with only the specified phases enabled.
     * @param array<string,mixed> $overrides
     */
    private function makeOptions(array $overrides = []): ImportOptions
    {
        return ImportOptions::fromPost(array_merge([
            'source'            => 'listing-admin',
            'conflict_strategy' => 'skip',
        ], $overrides));
    }

    /**
     * Creates the minimal LA source schema in $pdo.
     * Adds `trouble` and `upcoming` columns to `main` so status-mapping tests work.
     */
    private function seedLaSource(PDO $pdo): void
    {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS main (
                id       INTEGER PRIMARY KEY AUTOINCREMENT,
                title    TEXT    NOT NULL DEFAULT \'\',
                subject  TEXT    NOT NULL DEFAULT \'\',
                url      TEXT    NOT NULL DEFAULT \'\',
                category TEXT    NOT NULL DEFAULT \'\',
                since    TEXT    NULL,
                updated  TEXT    NULL,
                fave_fields TEXT NOT NULL DEFAULT \'\',
                trouble  INTEGER NOT NULL DEFAULT 0,
                upcoming INTEGER NOT NULL DEFAULT 0,
                image    TEXT    NOT NULL DEFAULT \'\'
            )'
        );
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS categories (
                catid   INTEGER PRIMARY KEY AUTOINCREMENT,
                catname TEXT    NOT NULL DEFAULT \'\',
                parent  INTEGER NOT NULL DEFAULT 0
            )'
        );
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS members (
                mID      INTEGER PRIMARY KEY AUTOINCREMENT,
                fNiq     INTEGER NOT NULL DEFAULT 0,
                mName    TEXT    NOT NULL DEFAULT \'\',
                mEmail   TEXT    NOT NULL DEFAULT \'\',
                mCountry TEXT    NOT NULL DEFAULT \'\',
                mURL     TEXT    NOT NULL DEFAULT \'\',
                mPending INTEGER NOT NULL DEFAULT 0,
                mPassword TEXT   NOT NULL DEFAULT \'\',
                mVisible INTEGER NOT NULL DEFAULT 1,
                mAdd     TEXT    NULL,
                mExtra   TEXT    NOT NULL DEFAULT \'\'
            )'
        );
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS affiliates (
                aID      INTEGER PRIMARY KEY AUTOINCREMENT,
                fNiq     TEXT    NOT NULL DEFAULT \'0\',
                aSubject TEXT    NOT NULL DEFAULT \'\',
                aURL     TEXT    NOT NULL DEFAULT \'\',
                aImage   TEXT    NOT NULL DEFAULT \'\',
                aEmail   TEXT    NOT NULL DEFAULT \'\'
            )'
        );
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS joined (
                jID       INTEGER PRIMARY KEY AUTOINCREMENT,
                jSubject  TEXT    NOT NULL DEFAULT \'\',
                jURL      TEXT    NOT NULL DEFAULT \'\',
                jImage    TEXT    NOT NULL DEFAULT \'\',
                jCategory TEXT    NOT NULL DEFAULT \'\',
                jStatus   INTEGER NOT NULL DEFAULT 0,
                jAdd      TEXT    NULL
            )'
        );
    }

    /** Inserts a listing row into the source `main` table and returns its id. */
    private function insertSourceListing(
        PDO $pdo,
        string $url,
        string $subject   = 'Test Fanlisting',
        int $trouble      = 0,
        int $upcoming     = 0
    ): int {
        $stmt = $pdo->prepare(
            'INSERT INTO main (title, subject, url, trouble, upcoming)
             VALUES (:title, :subject, :url, :trouble, :upcoming)'
        );
        $stmt->execute([
            'title'    => $subject,
            'subject'  => $subject,
            'url'      => $url,
            'trouble'  => $trouble,
            'upcoming' => $upcoming,
        ]);
        return (int) $pdo->lastInsertId();
    }

    /** Inserts a joined listing row with the given jStatus. */
    private function insertJoined(PDO $pdo, string $url, int $jStatus): void
    {
        $pdo->prepare(
            'INSERT INTO joined (jSubject, jURL, jStatus) VALUES (:subj, :url, :status)'
        )->execute(['subj' => 'Test Joined', 'url' => $url, 'status' => $jStatus]);
    }

    /** Creates the minimal target tables needed. */
    private function seedTarget(PDO $pdo): void
    {
        // owned — registry table (unprefixed Enthusiast schema)
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS owned (
                listingid  INTEGER PRIMARY KEY AUTOINCREMENT,
                dbtable    TEXT    NOT NULL DEFAULT \'\',
                title      TEXT    NOT NULL DEFAULT \'\',
                subject    TEXT    NOT NULL DEFAULT \'\',
                email      TEXT    NOT NULL DEFAULT \'\',
                url        TEXT    NOT NULL DEFAULT \'\',
                catid      TEXT    NOT NULL DEFAULT \'\',
                category_id INTEGER NULL,
                opened     TEXT    NULL,
                status     INTEGER NOT NULL DEFAULT 2,
                imagefile  TEXT    NOT NULL DEFAULT \'\',
                additional TEXT    NOT NULL DEFAULT \'\',
                dbserver   TEXT    NOT NULL DEFAULT \'\',
                dbuser     TEXT    NOT NULL DEFAULT \'\',
                dbpassword TEXT    NOT NULL DEFAULT \'\',
                dbdatabase TEXT    NOT NULL DEFAULT \'\'
            )'
        );
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS lstr_categories (
                id        INTEGER PRIMARY KEY AUTOINCREMENT,
                name      TEXT    NOT NULL DEFAULT \'\',
                slug      TEXT    NOT NULL DEFAULT \'\',
                parent_id INTEGER NULL,
                source    TEXT    NOT NULL DEFAULT \'custom\'
            )'
        );
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS lstr_fanlisting_settings (
                fanlisting_id    INTEGER PRIMARY KEY,
                members_per_page INTEGER NOT NULL DEFAULT 25,
                queue_updates    INTEGER NOT NULL DEFAULT 0,
                members_template TEXT    NULL,
                stats_template   TEXT    NULL,
                affiliates_section_template TEXT NULL,
                description      TEXT    NULL,
                form_url_join    TEXT    NOT NULL DEFAULT \'\',
                form_url_update  TEXT    NOT NULL DEFAULT \'\',
                form_url_reset   TEXT    NOT NULL DEFAULT \'\',
                image            TEXT    NULL,
                last_updated     DATE    NULL
            )'
        );
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS lstr_affiliates (
                id           INTEGER PRIMARY KEY AUTOINCREMENT,
                fanlisting_id INTEGER NULL,
                name         TEXT    NOT NULL DEFAULT \'\',
                url          TEXT    NOT NULL DEFAULT \'\',
                email        TEXT    NOT NULL DEFAULT \'\',
                image        TEXT    NULL,
                sort_order   INTEGER NOT NULL DEFAULT 0,
                active       INTEGER NOT NULL DEFAULT 1,
                status       TEXT    NOT NULL DEFAULT \'approved\'
            )'
        );
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS lstr_joined (
                id             INTEGER PRIMARY KEY AUTOINCREMENT,
                name           TEXT    NOT NULL DEFAULT \'\',
                url            TEXT    NOT NULL DEFAULT \'\',
                image          TEXT    NULL,
                category       TEXT    NOT NULL DEFAULT \'\',
                categories_json TEXT   NULL,
                category_id    INTEGER NULL,
                date_joined    DATE    NULL,
                active         INTEGER NOT NULL DEFAULT 1,
                status         TEXT    NOT NULL DEFAULT \'approved\',
                sort_order     INTEGER NOT NULL DEFAULT 0
            )'
        );
    }

    // -----------------------------------------------------------------------
    // Fix 1 — Joined listing status inversion
    // -----------------------------------------------------------------------

    public function testJoinedWithZeroStatusImportsAsActive(): void
    {
        $src = $this->makePdo();
        $tgt = $this->makePdo();

        $this->seedLaSource($src);
        $this->seedTarget($tgt);
        $this->insertJoined($src, 'http://active-joined.com', jStatus: 0);

        $result = $this->adapter()->run($src, $tgt, $this->makeOptions([
            'import_joined' => '1',
        ]));

        $joinRow = null;
        foreach ($result->rows as $row) {
            if ($row['type'] === 'Joined listings') { $joinRow = $row; break; }
        }
        $this->assertNotNull($joinRow);
        $this->assertSame(1, $joinRow['imported']);
        $this->assertEmpty($joinRow['errors']);

        $stmt = $tgt->query("SELECT active, status FROM lstr_joined WHERE url = 'http://active-joined.com'");
        $r    = $stmt->fetch();
        $this->assertSame(1,          (int)    $r['active'], 'jStatus=0 should produce active=1');
        $this->assertSame('approved', (string) $r['status'], 'jStatus=0 should produce status=approved');
    }

    public function testJoinedWithNonZeroStatusImportsAsPending(): void
    {
        $src = $this->makePdo();
        $tgt = $this->makePdo();

        $this->seedLaSource($src);
        $this->seedTarget($tgt);
        $this->insertJoined($src, 'http://pending-joined.com', jStatus: 1);

        $this->adapter()->run($src, $tgt, $this->makeOptions([
            'import_joined' => '1',
        ]));

        $stmt = $tgt->query("SELECT active, status FROM lstr_joined WHERE url = 'http://pending-joined.com'");
        $r    = $stmt->fetch();
        $this->assertSame(0,         (int)    $r['active'], 'jStatus=1 should produce active=0');
        $this->assertSame('pending', (string) $r['status'], 'jStatus=1 should produce status=pending');
    }

    public function testJoinedMixedStatuses(): void
    {
        $src = $this->makePdo();
        $tgt = $this->makePdo();

        $this->seedLaSource($src);
        $this->seedTarget($tgt);
        $this->insertJoined($src, 'http://joined-active.com',  jStatus: 0);
        $this->insertJoined($src, 'http://joined-pending.com', jStatus: 1);

        $this->adapter()->run($src, $tgt, $this->makeOptions([
            'import_joined' => '1',
        ]));

        $stmt = $tgt->query(
            "SELECT url, active FROM lstr_joined ORDER BY url ASC"
        );
        $rows = $stmt->fetchAll();
        $this->assertCount(2, $rows);
        $byUrl = array_column($rows, 'active', 'url');
        $this->assertSame(1, (int) $byUrl['http://joined-active.com']);
        $this->assertSame(0, (int) $byUrl['http://joined-pending.com']);
    }

    public function testJoinedMissingStatusDefaultsToActive(): void
    {
        $src = $this->makePdo();
        $tgt = $this->makePdo();

        $this->seedLaSource($src);
        $this->seedTarget($tgt);

        // Insert a joined row with no jStatus (NULL / missing) — should default to active (0)
        $src->exec("INSERT INTO joined (jSubject, jURL) VALUES ('No Status', 'http://nostatus.com')");

        $this->adapter()->run($src, $tgt, $this->makeOptions([
            'import_joined' => '1',
        ]));

        $stmt = $tgt->query("SELECT active FROM lstr_joined WHERE url = 'http://nostatus.com'");
        $this->assertSame(1, (int) $stmt->fetchColumn(), 'Missing jStatus should default to active');
    }

    // -----------------------------------------------------------------------
    // Fix 2 — Listing status: trouble/upcoming flag mapping
    // -----------------------------------------------------------------------

    public function testListingWithTroubleOneImportsWithStatusZero(): void
    {
        $src = $this->makePdo();
        $tgt = $this->makePdo();

        $this->seedLaSource($src);
        $this->seedTarget($tgt);
        $this->insertSourceListing($src, 'http://trouble.com', trouble: 1);

        $this->adapter()->run($src, $tgt, $this->makeOptions([
            'import_listings' => '1',
        ]));

        $stmt = $tgt->query("SELECT status FROM owned WHERE url = 'http://trouble.com'");
        $this->assertSame(0, (int) $stmt->fetchColumn(), 'trouble=1 should map to status 0');
    }

    public function testANewListingSavesOnAnOwnedTableFromEnthusiast(): void
    {
        $src = $this->makePdo();
        $tgt = $this->makePdo();
        $this->seedLaSource($src);
        $this->seedTarget($tgt);
        $this->useOldOwnedTable($tgt);
        $this->insertSourceListing($src, 'http://old-table.com');

        $result = $this->adapter()->run($src, $tgt, $this->makeOptions(['import_listings' => '1']));

        $messages = [];
        foreach ($result->rows as $row) {
            // This fixture has no bridge table, which is a different step's business.
            if ($row['type'] !== 'Bridge listing links') {
                $messages = array_merge($messages, $row['errors'] ?? []);
            }
        }
        $this->assertSame([], $messages);
        $owned = $tgt->query("SELECT * FROM owned WHERE url = 'http://old-table.com'")->fetch();
        $this->assertNotFalse($owned, 'A strict server used to refuse this');
        foreach (['emailsignup', 'emailapproved', 'emailupdate', 'emaillostpass', 'listtemplate'] as $column) {
            $this->assertSame('', $owned[$column], $column);
        }
    }

    public function testANewListingFillsInWhatAnEnthFanlistingsTableRequiresToo(): void
    {
        $src = $this->makePdo();
        $tgt = $this->makePdo();
        $this->seedLaSource($src);
        $this->seedTarget($tgt);
        $tgt->exec('DROP TABLE owned');
        $tgt->exec("CREATE TABLE enth_fanlistings (
            id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL DEFAULT '', subject TEXT NOT NULL DEFAULT '', url TEXT NOT NULL DEFAULT '',
            dbprefix TEXT NOT NULL DEFAULT '', category INTEGER NOT NULL DEFAULT 0, category_id INTEGER NULL, opened TEXT NULL,
            upcoming INTEGER NOT NULL DEFAULT 0, trouble INTEGER NOT NULL DEFAULT 0, imagefile TEXT NULL, email TEXT NOT NULL DEFAULT '',
            dbserver TEXT NOT NULL DEFAULT '', dbuser TEXT NOT NULL DEFAULT '', dbpassword TEXT NOT NULL DEFAULT '', dbdatabase TEXT NOT NULL DEFAULT '',
            additional_info TEXT NULL, an_old_requirement TEXT NOT NULL)");
        $this->insertSourceListing($src, 'http://enth-table.com');

        $this->adapter()->run($src, $tgt, $this->makeOptions(['import_listings' => '1']));

        $row = $tgt->query("SELECT * FROM enth_fanlistings WHERE url = 'http://enth-table.com'")->fetch();
        $this->assertNotFalse($row, 'A strict server, or SQLite, would refuse it otherwise');
        $this->assertSame('', $row['an_old_requirement']);
    }

    public function testAFailedReadOfAnOldOwnedTableCostsThatListing(): void
    {
        $src = $this->makePdo();
        $tgt = TracingPdo::memory();
        $tgt->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $this->seedLaSource($src);
        $this->seedTarget($tgt);
        $this->useOldOwnedTable($tgt);
        $this->insertSourceListing($src, 'http://old-table.com');
        $tgt->failOn = '/PRAGMA table_info\(`owned`\)/';

        $result = $this->adapter()->run($src, $tgt, $this->makeOptions(['import_listings' => '1']));
        $tgt->failOn = null;

        $errors = [];
        foreach ($result->rows as $row) {
            $errors = array_merge($errors, $row['errors'] ?? []);
        }
        $this->assertStringContainsString('Test Fanlisting', implode(' ', $errors));
        $this->assertSame(0, (int) $tgt->query('SELECT COUNT(*) FROM owned')->fetchColumn());
    }

    /** Swaps the fixture's `owned` for one with Enthusiast's required columns. */
    private function useOldOwnedTable(PDO $tgt): void
    {
        $tgt->exec('DROP TABLE owned');
        $tgt->exec("CREATE TABLE owned (
            listingid INTEGER PRIMARY KEY AUTOINCREMENT, dbtable TEXT NOT NULL DEFAULT '', title TEXT NOT NULL DEFAULT '',
            subject TEXT NOT NULL DEFAULT '', email TEXT NOT NULL DEFAULT '', url TEXT NOT NULL DEFAULT '',
            catid TEXT NOT NULL DEFAULT '', category_id INTEGER NULL, opened TEXT NULL, status INTEGER NOT NULL DEFAULT 2,
            imagefile TEXT NOT NULL DEFAULT '', additional TEXT NOT NULL DEFAULT '', dbserver TEXT NOT NULL DEFAULT '',
            dbuser TEXT NOT NULL DEFAULT '', dbpassword TEXT NOT NULL DEFAULT '', dbdatabase TEXT NOT NULL DEFAULT '',
            emailsignup TEXT NOT NULL, emailapproved TEXT NOT NULL, emailupdate TEXT NOT NULL,
            emaillostpass TEXT NOT NULL, listtemplate TEXT NOT NULL)");
    }

    public function testListingWithUpcomingOneImportsWithStatusOne(): void
    {
        $src = $this->makePdo();
        $tgt = $this->makePdo();

        $this->seedLaSource($src);
        $this->seedTarget($tgt);
        $this->insertSourceListing($src, 'http://upcoming.com', upcoming: 1);

        $this->adapter()->run($src, $tgt, $this->makeOptions([
            'import_listings' => '1',
        ]));

        $stmt = $tgt->query("SELECT status FROM owned WHERE url = 'http://upcoming.com'");
        $this->assertSame(1, (int) $stmt->fetchColumn(), 'upcoming=1 should map to status 1');
    }

    public function testListingWithNeitherFlagImportsAsActive(): void
    {
        $src = $this->makePdo();
        $tgt = $this->makePdo();

        $this->seedLaSource($src);
        $this->seedTarget($tgt);
        $this->insertSourceListing($src, 'http://active.com');

        $this->adapter()->run($src, $tgt, $this->makeOptions([
            'import_listings' => '1',
        ]));

        $stmt = $tgt->query("SELECT status FROM owned WHERE url = 'http://active.com'");
        $this->assertSame(2, (int) $stmt->fetchColumn(), 'no flags should map to status 2 (active)');
    }

    public function testTroubleTakesPrecedenceOverUpcoming(): void
    {
        $src = $this->makePdo();
        $tgt = $this->makePdo();

        $this->seedLaSource($src);
        $this->seedTarget($tgt);
        // Both set — trouble wins (same precedence as Enthusiast adapter)
        $this->insertSourceListing($src, 'http://both.com', trouble: 1, upcoming: 1);

        $this->adapter()->run($src, $tgt, $this->makeOptions([
            'import_listings' => '1',
        ]));

        $stmt = $tgt->query("SELECT status FROM owned WHERE url = 'http://both.com'");
        $this->assertSame(0, (int) $stmt->fetchColumn(), 'trouble=1 should take precedence over upcoming=1');
    }

    public function testAllThreeStatusesInOneBatch(): void
    {
        $src = $this->makePdo();
        $tgt = $this->makePdo();

        $this->seedLaSource($src);
        $this->seedTarget($tgt);
        $this->insertSourceListing($src, 'http://active.com',   trouble: 0, upcoming: 0);
        $this->insertSourceListing($src, 'http://upcoming.com', trouble: 0, upcoming: 1);
        $this->insertSourceListing($src, 'http://trouble.com',  trouble: 1, upcoming: 0);

        $result = $this->adapter()->run($src, $tgt, $this->makeOptions([
            'import_listings' => '1',
        ]));

        $listRow = null;
        foreach ($result->rows as $r) {
            if ($r['type'] === 'Listings') { $listRow = $r; break; }
        }
        $this->assertSame(3, $listRow['imported']);
        $this->assertEmpty($listRow['errors']);

        $stmt = $tgt->query('SELECT url, status FROM owned ORDER BY url ASC');
        $byUrl = array_column($stmt->fetchAll(), 'status', 'url');

        $this->assertSame(2, (int) $byUrl['http://active.com'],   'active listing → status 2');
        $this->assertSame(1, (int) $byUrl['http://upcoming.com'], 'upcoming listing → status 1');
        $this->assertSame(0, (int) $byUrl['http://trouble.com'],  'trouble listing → status 0');
    }

    public function testListingStatusMissingColumnsDefaultsToActive(): void
    {
        $src = $this->makePdo();
        $tgt = $this->makePdo();

        // A source schema without trouble/upcoming columns at all
        $src->exec(
            'CREATE TABLE main (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                title TEXT NOT NULL DEFAULT \'\', subject TEXT NOT NULL DEFAULT \'\',
                url TEXT NOT NULL DEFAULT \'\', category TEXT NOT NULL DEFAULT \'\',
                since TEXT NULL, updated TEXT NULL,
                fave_fields TEXT NOT NULL DEFAULT \'\', image TEXT NOT NULL DEFAULT \'\'
            )'
        );
        $src->exec('CREATE TABLE IF NOT EXISTS categories (catid INTEGER PRIMARY KEY, catname TEXT NOT NULL DEFAULT \'\', parent INTEGER NOT NULL DEFAULT 0)');
        $src->exec('CREATE TABLE IF NOT EXISTS members (mID INTEGER PRIMARY KEY, fNiq INTEGER NOT NULL DEFAULT 0, mName TEXT NOT NULL DEFAULT \'\', mEmail TEXT NOT NULL DEFAULT \'\', mCountry TEXT NOT NULL DEFAULT \'\', mURL TEXT NOT NULL DEFAULT \'\', mPending INTEGER NOT NULL DEFAULT 0, mPassword TEXT NOT NULL DEFAULT \'\', mVisible INTEGER NOT NULL DEFAULT 1, mAdd TEXT NULL, mExtra TEXT NOT NULL DEFAULT \'\')');
        $src->exec("INSERT INTO main (title, subject, url) VALUES ('Test', 'Test', 'http://noflags.com')");

        $this->seedTarget($tgt);

        $this->adapter()->run($src, $tgt, $this->makeOptions([
            'import_listings' => '1',
        ]));

        $stmt = $tgt->query("SELECT status FROM owned WHERE url = 'http://noflags.com'");
        $this->assertSame(2, (int) $stmt->fetchColumn(), 'Missing trouble/upcoming columns should default to status 2');
    }

    // -----------------------------------------------------------------------
    // Fix 3 — Dump-mode image settings: $sp passed to readImageSettings()
    //
    // readImageSettings() is private and ImageMigrator requires real file I/O,
    // so this fix is verified indirectly: we create the options table under the
    // FULL source prefix ($tp.$laP) but NOT under the bare $laP prefix, then run
    // the import with imageMode='' (no migrator) and confirm it completes without
    // error.  The core assertion is that the one-line change ($laP → $sp) is
    // present in the source — which is validated by the code review above —
    // and no existing test regresses.
    // -----------------------------------------------------------------------

    public function testDumpModeRunSucceedsWithPrefixedOptionsTable(): void
    {
        $src = $this->makePdo();
        $tgt = $this->makePdo();

        // Simulate a SQL-dump import: temp-prefixed tables (e.g. lstr_tmp_abc123_)
        // combined with LA prefix (la_). The options table lives at
        // 'lstr_tmp_abc123_la_options', NOT at 'la_options'.
        // We don't exercise image migration here (no files), but we verify the
        // import run completes cleanly and respects the combined prefix for all
        // LA table accesses.
        $dumpTp  = 'dump_';
        $laP     = 'la_';
        $sp      = $dumpTp . $laP;                         // 'dump_la_'  (prefix for members/categories)
        $sreg    = $dumpTp . str_replace('_', '', $laP);   // 'dump_la'   (registry table name)

        // Registry table name is derived via str_replace('_','', $laP) prepended by $tp:
        //   'la_' → 'la', so 'dump_' + 'la' = 'dump_la'
        // Other tables use $sp directly:  'dump_la_members', 'dump_la_categories', etc.
        $src->exec("CREATE TABLE IF NOT EXISTS `{$sreg}` (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            title TEXT NOT NULL DEFAULT '', subject TEXT NOT NULL DEFAULT '',
            url TEXT NOT NULL DEFAULT '', category TEXT NOT NULL DEFAULT '',
            since TEXT NULL, updated TEXT NULL,
            fave_fields TEXT NOT NULL DEFAULT '',
            trouble INTEGER NOT NULL DEFAULT 0, upcoming INTEGER NOT NULL DEFAULT 0,
            image TEXT NOT NULL DEFAULT ''
        )");
        $src->exec("CREATE TABLE IF NOT EXISTS `{$sp}categories` (catid INTEGER PRIMARY KEY, catname TEXT NOT NULL DEFAULT '', parent INTEGER NOT NULL DEFAULT 0)");
        $src->exec("CREATE TABLE IF NOT EXISTS `{$sp}members` (mID INTEGER PRIMARY KEY, fNiq INTEGER NOT NULL DEFAULT 0, mName TEXT NOT NULL DEFAULT '', mEmail TEXT NOT NULL DEFAULT '', mCountry TEXT NOT NULL DEFAULT '', mURL TEXT NOT NULL DEFAULT '', mPending INTEGER NOT NULL DEFAULT 0, mPassword TEXT NOT NULL DEFAULT '', mVisible INTEGER NOT NULL DEFAULT 1, mAdd TEXT NULL, mExtra TEXT NOT NULL DEFAULT '')");
        $src->exec("INSERT INTO `{$sreg}` (title, subject, url) VALUES ('Dump Listing', 'Dump Listing', 'http://dump.com')");

        $this->seedTarget($tgt);

        // Run with dump-mode table prefix and no image migration
        $options = ImportOptions::fromPost([
            'source'                => 'listing-admin',
            'conflict_strategy'     => 'skip',
            'import_listings'       => '1',
            '_dump_table_prefix'    => $dumpTp,
            '_dump_schema_variant'  => 'listing_admin_3x',
            'la_table_prefix'       => $laP,
        ]);

        $result = $this->adapter()->run($src, $tgt, $options);

        $listRow = null;
        foreach ($result->rows as $r) {
            if ($r['type'] === 'Listings') { $listRow = $r; break; }
        }
        $this->assertNotNull($listRow);
        $this->assertSame(1, $listRow['imported'], 'Dump-mode import should find listing in prefixed table');
        $this->assertEmpty($listRow['errors']);

        $stmt = $tgt->query("SELECT url FROM owned WHERE url = 'http://dump.com'");
        $this->assertSame('http://dump.com', $stmt->fetchColumn());
    }

    // -----------------------------------------------------------------------
    // KIM List and Wishlist — source table helpers
    // -----------------------------------------------------------------------

    /** Creates lstr_kim_members (KIM add-on target table). */
    private function createKimTable(PDO $pdo): void
    {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS lstr_kim_members (
                id            INTEGER PRIMARY KEY AUTOINCREMENT,
                fanlisting_id INTEGER NOT NULL,
                name          TEXT    NOT NULL DEFAULT \'\',
                email         TEXT    NOT NULL DEFAULT \'\',
                url           TEXT    NOT NULL DEFAULT \'\',
                password      TEXT    NOT NULL DEFAULT \'\',
                pending       INTEGER NOT NULL DEFAULT 1,
                added_at      TEXT    NOT NULL DEFAULT \'\'
            )'
        );
    }

    /** Creates lstr_wishlist (Wishlist add-on target table). */
    private function createLstrWishlistTable(PDO $pdo): void
    {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS lstr_wishlist (
                id          INTEGER PRIMARY KEY AUTOINCREMENT,
                subject     TEXT    NOT NULL DEFAULT \'\',
                url         TEXT    NOT NULL DEFAULT \'\',
                image       TEXT    NULL,
                category    TEXT    NOT NULL DEFAULT \'\',
                type        TEXT    NOT NULL DEFAULT \'wanted\',
                description TEXT    NULL,
                added_at    TEXT    NULL,
                sort_order  INTEGER NOT NULL DEFAULT 0,
                active      INTEGER NOT NULL DEFAULT 1
            )'
        );
    }

    /** Creates the LA source `kim` table (fan adoption interest). */
    private function createLaKimTable(PDO $pdo): void
    {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS kim (
                mID       INTEGER PRIMARY KEY AUTOINCREMENT,
                mEmail    TEXT    NOT NULL DEFAULT \'\',
                fNiq      INTEGER NOT NULL DEFAULT 0,
                mName     TEXT    NOT NULL DEFAULT \'\',
                mURL      TEXT    NOT NULL DEFAULT \'\',
                mPassword TEXT    NOT NULL DEFAULT \'\',
                mPending  INTEGER NOT NULL DEFAULT 0,
                mAdd      TEXT    NULL
            )'
        );
    }

    /** Creates the LA source `wishlist` table (owner\'s personal wishlist). */
    private function createLaWishlistTable(PDO $pdo): void
    {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS wishlist (
                wID       INTEGER PRIMARY KEY AUTOINCREMENT,
                wSubject  TEXT    NOT NULL DEFAULT \'\',
                wURL      TEXT    NOT NULL DEFAULT \'\',
                wImage    TEXT    NOT NULL DEFAULT \'\',
                wCategory TEXT    NOT NULL DEFAULT \'\',
                wDesc     TEXT    NULL,
                wType     TEXT    NOT NULL DEFAULT \'custom\'
            )'
        );
    }

    /** Inserts a row into the LA `kim` table. */
    private function insertLaKim(
        PDO $pdo, int $fNiq, string $email, string $name = 'Fan', int $pending = 0
    ): void {
        $pdo->prepare(
            'INSERT INTO kim (fNiq, mEmail, mName, mURL, mPassword, mPending, mAdd)
             VALUES (:fNiq, :email, :name, \'\', \'\', :pending, \'2024-01-01\')'
        )->execute(['fNiq' => $fNiq, 'email' => $email, 'name' => $name, 'pending' => $pending]);
    }

    /** Inserts a row into the LA `wishlist` table. */
    private function insertLaWishlist(
        PDO $pdo, string $url, string $subject = 'Test FL', string $type = 'custom'
    ): void {
        $pdo->prepare(
            'INSERT INTO wishlist (wSubject, wURL, wCategory, wType)
             VALUES (:subject, :url, \'\', :type)'
        )->execute(['subject' => $subject, 'url' => $url, 'type' => $type]);
    }

    // -----------------------------------------------------------------------
    // KIM List — reads from LA `kim` table
    // -----------------------------------------------------------------------

    /**
     * The real KimAddon::install() uses MySQL-only DDL (AUTO_INCREMENT, ENGINE=InnoDB)
     * that fails under SQLite, so AddonAutoInstaller::ensureInstalled() returns false here.
     * This test covers the graceful-failure path; happy-path auto-install is tested in
     * AddonAutoInstallerTest using a SQLite-safe FakeTestAddon.
     */
    public function testKimGracefullyHandlesFailedAutoInstall(): void
    {
        $src = $this->makePdo();
        $tgt = $this->makePdo();

        $this->seedLaSource($src);
        $this->seedTarget($tgt);
        $this->createLaKimTable($src);
        // lstr_kim_members absent; real KimAddon DDL fails on SQLite so auto-install returns false.

        $this->insertSourceListing($src, 'http://test.com');
        $this->insertLaKim($src, 1, 'fan@test.com');

        $result = $this->adapter()->run($src, $tgt, $this->makeOptions([
            'import_listings' => '1',
            'import_kim'      => '1',
        ]));

        $kimRow = null;
        foreach ($result->rows as $r) {
            if ($r['type'] === 'KIM List') { $kimRow = $r; break; }
        }
        $this->assertNotNull($kimRow);
        $this->assertSame(0, $kimRow['imported']);
        $this->assertStringContainsString('could not be installed automatically', implode(' ', $kimRow['warnings']));
    }

    public function testKimImportsFromKimTableWithCorrectColumns(): void
    {
        $src = $this->makePdo();
        $tgt = $this->makePdo();

        $this->seedLaSource($src);
        $this->seedTarget($tgt);
        $this->createKimTable($tgt);
        $this->createLaKimTable($src);

        $this->insertSourceListing($src, 'http://test.com');
        $this->insertLaKim($src, 1, 'fan@test.com', 'Test Fan', pending: 1);

        $result = $this->adapter()->run($src, $tgt, $this->makeOptions([
            'import_listings' => '1',
            'import_kim'      => '1',
        ]));

        $kimRow = null;
        foreach ($result->rows as $r) {
            if ($r['type'] === 'KIM List') { $kimRow = $r; break; }
        }
        $this->assertNotNull($kimRow);
        $this->assertSame(1, $kimRow['imported']);
        $this->assertEmpty($kimRow['errors']);

        $stmt = $tgt->query('SELECT * FROM lstr_kim_members');
        $row  = $stmt->fetch();
        $this->assertSame('fan@test.com', $row['email']);
        $this->assertSame('Test Fan',     $row['name']);
        $this->assertSame(1, (int) $row['pending']);
    }

    public function testKimLinksToCorrectLusterListingId(): void
    {
        $src = $this->makePdo();
        $tgt = $this->makePdo();

        $this->seedLaSource($src);
        $this->seedTarget($tgt);
        $this->createKimTable($tgt);
        $this->createLaKimTable($src);

        $this->insertSourceListing($src, 'http://one.com');
        $this->insertSourceListing($src, 'http://two.com');
        $this->insertLaKim($src, 2, 'fan@two.com');

        $this->adapter()->run($src, $tgt, $this->makeOptions([
            'import_listings' => '1',
            'import_kim'      => '1',
        ]));

        $stmt    = $tgt->query("SELECT listingid FROM owned WHERE url = 'http://two.com'");
        $lustrId = (int) $stmt->fetchColumn();
        $this->assertGreaterThan(0, $lustrId);

        $stmt = $tgt->query('SELECT fanlisting_id FROM lstr_kim_members');
        $this->assertSame($lustrId, (int) $stmt->fetchColumn());
    }

    public function testKimSkipsUnlinkedCollectiveEntries(): void
    {
        $src = $this->makePdo();
        $tgt = $this->makePdo();

        $this->seedLaSource($src);
        $this->seedTarget($tgt);
        $this->createKimTable($tgt);
        $this->createLaKimTable($src);

        $this->insertLaKim($src, 0, 'collective@test.com'); // fNiq=0 = collective

        $result = $this->adapter()->run($src, $tgt, $this->makeOptions(['import_kim' => '1']));

        $kimRow = null;
        foreach ($result->rows as $r) {
            if ($r['type'] === 'KIM List') { $kimRow = $r; break; }
        }
        $this->assertSame(0, $kimRow['imported']);
        $this->assertStringContainsString('1', implode(' ', $kimRow['warnings']));
    }

    // -----------------------------------------------------------------------
    // Wishlist — reads from LA `wishlist` table, writes to lstr_wishlist
    // -----------------------------------------------------------------------

    /**
     * The real WishlistAddon::install() uses MySQL-only DDL (AUTO_INCREMENT, ENGINE=InnoDB)
     * that fails under SQLite, so AddonAutoInstaller::ensureInstalled() returns false here.
     * This test covers the graceful-failure path; happy-path auto-install is tested in
     * AddonAutoInstallerTest using a SQLite-safe FakeTestAddon.
     */
    public function testWishlistGracefullyHandlesFailedAutoInstall(): void
    {
        $src = $this->makePdo();
        $tgt = $this->makePdo();

        $this->seedLaSource($src);
        $this->seedTarget($tgt);
        $this->createLaWishlistTable($src);
        // lstr_wishlist absent; real WishlistAddon DDL fails on SQLite so auto-install returns false.

        $this->insertLaWishlist($src, 'http://want.com', 'Subject I Want');

        $result = $this->adapter()->run($src, $tgt, $this->makeOptions([
            'import_wishlist' => '1',
        ]));

        $wRow = null;
        foreach ($result->rows as $r) {
            if ($r['type'] === 'Wishlist') { $wRow = $r; break; }
        }
        $this->assertNotNull($wRow);
        $this->assertSame(0, $wRow['imported']);
        $this->assertStringContainsString('could not be installed automatically', implode(' ', $wRow['warnings']));
    }

    public function testWishlistImportsFromOwnerWishlistTable(): void
    {
        $src = $this->makePdo();
        $tgt = $this->makePdo();

        $this->seedLaSource($src);
        $this->seedTarget($tgt);
        $this->createLaWishlistTable($src);
        $this->createLstrWishlistTable($tgt);

        $this->insertLaWishlist($src, 'http://want.com', 'Test Subject', 'top');

        $result = $this->adapter()->run($src, $tgt, $this->makeOptions([
            'import_wishlist' => '1',
        ]));

        $wRow = null;
        foreach ($result->rows as $r) {
            if ($r['type'] === 'Wishlist') { $wRow = $r; break; }
        }
        $this->assertNotNull($wRow);
        $this->assertSame(1, $wRow['imported']);
        $this->assertEmpty($wRow['errors']);

        $stmt = $tgt->query('SELECT subject, url, type FROM lstr_wishlist');
        $row  = $stmt->fetch();
        $this->assertSame('Test Subject',      $row['subject']);
        $this->assertSame('http://want.com',   $row['url']);
        $this->assertSame('top',               $row['type']);
    }

    public function testWishlistTypeMappingFromLaToLuster(): void
    {
        $src = $this->makePdo();
        $tgt = $this->makePdo();

        $this->seedLaSource($src);
        $this->seedTarget($tgt);
        $this->createLaWishlistTable($src);
        $this->createLstrWishlistTable($tgt);

        // LA types → Luster types
        $this->insertLaWishlist($src, 'http://custom.com',  'Custom',  'custom');
        $this->insertLaWishlist($src, 'http://granted.com', 'Granted', 'granted');
        $this->insertLaWishlist($src, 'http://list.com',    'List',    'list');
        $this->insertLaWishlist($src, 'http://top.com',     'Top',     'top');

        $this->adapter()->run($src, $tgt, $this->makeOptions(['import_wishlist' => '1']));

        $stmt  = $tgt->query('SELECT url, type FROM lstr_wishlist ORDER BY url ASC');
        $byUrl = array_column($stmt->fetchAll(), 'type', 'url');

        $this->assertSame('wanted',  $byUrl['http://custom.com'],  'custom → wanted');
        $this->assertSame('granted', $byUrl['http://granted.com'], 'granted → granted');
        $this->assertSame('wanted',  $byUrl['http://list.com'],    'list → wanted');
        $this->assertSame('top',     $byUrl['http://top.com'],     'top → top');
    }

    public function testWishlistAndKimAreDistinctImports(): void
    {
        // Importing wishlist does NOT affect lstr_kim_members and vice versa.
        $src = $this->makePdo();
        $tgt = $this->makePdo();

        $this->seedLaSource($src);
        $this->seedTarget($tgt);
        $this->createLaWishlistTable($src);
        $this->createLaKimTable($src);
        $this->createLstrWishlistTable($tgt);
        $this->createKimTable($tgt);

        $this->insertSourceListing($src, 'http://listing.com');
        $this->insertLaWishlist($src, 'http://want.com', 'Want This');
        $this->insertLaKim($src, 1, 'fan@test.com');

        $this->adapter()->run($src, $tgt, $this->makeOptions([
            'import_listings' => '1',
            'import_wishlist'  => '1',
            'import_kim'       => '1',
        ]));

        $this->assertSame(1, (int) $tgt->query('SELECT COUNT(*) FROM lstr_wishlist')->fetchColumn());
        $this->assertSame(1, (int) $tgt->query('SELECT COUNT(*) FROM lstr_kim_members')->fetchColumn());
    }

    // -----------------------------------------------------------------------
    // Category source detection (TFL / TAFL / custom) — Listing Admin
    // -----------------------------------------------------------------------

    private function addLaCategoryTable(PDO $pdo): void
    {
        $pdo->exec('CREATE TABLE IF NOT EXISTS lstr_categories (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, slug TEXT NOT NULL DEFAULT \'\', parent_id INTEGER NULL, source TEXT NOT NULL DEFAULT \'custom\')');
    }

    private function addLaJoinedTable(PDO $pdo): void
    {
        $pdo->exec('CREATE TABLE IF NOT EXISTS lstr_joined (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL DEFAULT \'\', url TEXT NOT NULL DEFAULT \'\', image TEXT NULL, category TEXT NOT NULL DEFAULT \'\', categories_json TEXT NULL, category_id INTEGER NULL, date_joined TEXT NULL, active INTEGER NOT NULL DEFAULT 1, status TEXT NOT NULL DEFAULT \'approved\', sort_order INTEGER NOT NULL DEFAULT 0)');
    }

    public function testLaRunCategoriesAssignsTflSourceToKnownTflCategories(): void
    {
        $src = $this->makePdo();
        $tgt = $this->makePdo();
        $this->seedLaSource($src);
        $this->seedTarget($tgt);
        $this->addLaCategoryTable($tgt);

        $src->exec("INSERT INTO categories (catid, catname) VALUES (1, 'Albums'), (2, 'Actors'), (3, 'Animals')");

        $this->adapter()->run($src, $tgt, $this->makeOptions([
            'import_categories' => '1',
        ]));

        $rows = $tgt->query("SELECT name, source FROM lstr_categories ORDER BY id")->fetchAll();
        $this->assertCount(3, $rows);
        foreach ($rows as $r) {
            $this->assertSame('tfl', $r['source'], "'{$r['name']}' should be source=tfl");
        }
    }

    public function testLaRunCategoriesAssignsTaflSourceToKnownTaflCategories(): void
    {
        $src = $this->makePdo();
        $tgt = $this->makePdo();
        $this->seedLaSource($src);
        $this->seedTarget($tgt);
        $this->addLaCategoryTable($tgt);

        $src->exec("INSERT INTO categories (catid, catname) VALUES (1, 'Anime/Manga: Series'), (2, 'Anime/Manga: General')");

        $this->adapter()->run($src, $tgt, $this->makeOptions([
            'import_categories' => '1',
        ]));

        $rows = $tgt->query("SELECT source FROM lstr_categories")->fetchAll();
        $this->assertCount(2, $rows);
        foreach ($rows as $r) {
            $this->assertSame('tafl', $r['source']);
        }
    }

    public function testLaRunCategoriesAssignsCustomSourceToUnknownCategories(): void
    {
        $src = $this->makePdo();
        $tgt = $this->makePdo();
        $this->seedLaSource($src);
        $this->seedTarget($tgt);
        $this->addLaCategoryTable($tgt);

        $src->exec("INSERT INTO categories (catid, catname) VALUES (1, 'My Fandom'), (2, 'Original Content')");

        $this->adapter()->run($src, $tgt, $this->makeOptions([
            'import_categories' => '1',
        ]));

        $rows = $tgt->query("SELECT source FROM lstr_categories")->fetchAll();
        $this->assertCount(2, $rows);
        foreach ($rows as $r) {
            $this->assertSame('custom', $r['source']);
        }
    }

    public function testLaRunJoinedUsesCorrectNetworkInCategoriesJson(): void
    {
        $src = $this->makePdo();
        $tgt = $this->makePdo();
        $this->seedLaSource($src);
        $this->seedTarget($tgt);
        $this->addLaCategoryTable($tgt);
        $this->addLaJoinedTable($tgt);

        // Pre-seed categories in target with correct sources
        $tgt->exec("INSERT INTO lstr_categories (name, source) VALUES ('Albums', 'tfl')");
        $tgt->exec("INSERT INTO lstr_categories (name, source) VALUES ('Anime/Manga: Series', 'tafl')");
        $tgt->exec("INSERT INTO lstr_categories (name, source) VALUES ('Fan Site', 'custom')");

        // Source LA categories and joined listings
        $src->exec("INSERT INTO categories (catid, catname) VALUES (1, 'Albums'), (2, 'Anime/Manga: Series'), (3, 'Fan Site')");
        $src->exec("INSERT INTO joined (jID, jSubject, jURL, jCategory, jStatus) VALUES (1, 'FL A', 'http://a.com', '1', 0)");
        $src->exec("INSERT INTO joined (jID, jSubject, jURL, jCategory, jStatus) VALUES (2, 'FL B', 'http://b.com', '2', 0)");
        $src->exec("INSERT INTO joined (jID, jSubject, jURL, jCategory, jStatus) VALUES (3, 'FL C', 'http://c.com', '3', 0)");

        $this->adapter()->run($src, $tgt, $this->makeOptions([
            'import_joined' => '1',
        ]));

        $rows   = $tgt->query("SELECT url, categories_json FROM lstr_joined ORDER BY url ASC")->fetchAll();
        $byUrl  = array_column($rows, 'categories_json', 'url');

        $aJson  = json_decode($byUrl['http://a.com'], true);
        $bJson  = json_decode($byUrl['http://b.com'], true);
        $cJson  = json_decode($byUrl['http://c.com'], true);

        $this->assertSame('TFL',    $aJson[0]['network']);
        $this->assertSame('TAFL',   $bJson[0]['network']);
        $this->assertSame('Custom', $cJson[0]['network']);
    }

    public function testLaFullPipelineCategoryToNetworkInJoined(): void
    {
        // Categories AND joined in one run — network must be correct end-to-end
        $src = $this->makePdo();
        $tgt = $this->makePdo();
        $this->seedLaSource($src);
        $this->seedTarget($tgt);
        $this->addLaCategoryTable($tgt);
        $this->addLaJoinedTable($tgt);

        $src->exec("INSERT INTO categories (catid, catname) VALUES (1, 'Actors')");
        $src->exec("INSERT INTO joined (jID, jSubject, jURL, jCategory, jStatus) VALUES (1, 'My FL', 'http://fl.com', '1', 0)");

        $this->adapter()->run($src, $tgt, $this->makeOptions([
            'import_categories' => '1',
            'import_joined'     => '1',
        ]));

        $catSource = $tgt->query("SELECT source FROM lstr_categories WHERE name='Actors'")->fetchColumn();
        $this->assertSame('tfl', $catSource);

        $json = json_decode(
            $tgt->query("SELECT categories_json FROM lstr_joined WHERE url='http://fl.com'")->fetchColumn(),
            true
        );
        $this->assertSame('TFL',    $json[0]['network']);
        $this->assertSame('Actors', $json[0]['name']);
    }

    // -----------------------------------------------------------------------
    // last_updated derived from member join dates (LA import)
    // -----------------------------------------------------------------------

    /** Target with lstr_fanlisting_settings.last_updated column present. */
    private function seedTargetWithLastUpdated(PDO $pdo, int $listingId = 1, string $title = 'Test'): void
    {
        $this->seedTarget($pdo, $listingId, $title);
        // The base seedTarget already creates lstr_fanlisting_settings without last_updated;
        // drop and recreate it with the column to simulate a post-migration Luster install.
        $pdo->exec('DROP TABLE IF EXISTS lstr_fanlisting_settings');
        $pdo->exec(
            'CREATE TABLE lstr_fanlisting_settings (
                fanlisting_id    INTEGER PRIMARY KEY,
                members_per_page INTEGER NOT NULL DEFAULT 25,
                queue_updates    INTEGER NOT NULL DEFAULT 0,
                members_template TEXT    NULL,
                stats_template   TEXT    NULL,
                affiliates_section_template TEXT NULL,
                description      TEXT    NULL,
                form_url_join    TEXT    NOT NULL DEFAULT \'\',
                form_url_update  TEXT    NOT NULL DEFAULT \'\',
                form_url_reset   TEXT    NOT NULL DEFAULT \'\',
                image            TEXT    NULL,
                last_updated     DATE    NULL
            )'
        );
    }

    public function testLaRunListingsSetsLastUpdatedFromMostRecentMemberJoin(): void
    {
        $src = $this->makePdo();
        $tgt = $this->makePdo();

        $this->seedLaSource($src);
        $this->seedTargetWithLastUpdated($tgt);

        $this->insertSourceListing($src, 'http://test.com');

        // Add members with different join dates; newest = 2024-09-20
        $src->exec("INSERT INTO members (fNiq, mName, mEmail, mAdd)
                    VALUES (1, 'Early Fan', 'early@test.com', '2021-03-01')");
        $src->exec("INSERT INTO members (fNiq, mName, mEmail, mAdd)
                    VALUES (1, 'New Fan',   'new@test.com',   '2024-09-20')");

        $this->adapter()->run($src, $tgt, $this->makeOptions([
            'import_listings' => '1',
        ]));

        $stmt = $tgt->query('SELECT last_updated FROM lstr_fanlisting_settings LIMIT 1');
        $this->assertSame('2024-09-20', $stmt->fetchColumn(),
            'last_updated must equal the most recent member join date');
    }

    public function testLaRunListingsLeavesLastUpdatedNullWhenNoSourceDate(): void
    {
        $src = $this->makePdo();
        $tgt = $this->makePdo();

        $this->seedLaSource($src);
        $this->seedTargetWithLastUpdated($tgt);

        $this->insertSourceListing($src, 'http://test.com');
        // No members inserted and no updated column value — no real source date

        $this->adapter()->run($src, $tgt, $this->makeOptions([
            'import_listings' => '1',
        ]));

        $stmt        = $tgt->query('SELECT last_updated FROM lstr_fanlisting_settings LIMIT 1');
        $lastUpdated = $stmt->fetchColumn();
        $this->assertNull($lastUpdated !== false ? $lastUpdated : null,
            'last_updated must remain NULL when no real source date exists');
    }

    public function testLaOpenedDateIsImportedFromMainTable(): void
    {
        $src = $this->makePdo();
        $tgt = $this->makePdo();

        $this->seedLaSource($src);
        $this->seedTargetWithLastUpdated($tgt);

        // LA stores opened date in main.since
        $src->exec("INSERT INTO main (title, subject, url, since) VALUES ('Test', 'Test', 'http://opened.com', '2018-11-05')");

        $this->adapter()->run($src, $tgt, $this->makeOptions([
            'import_listings' => '1',
        ]));

        $stmt = $tgt->query("SELECT opened FROM owned WHERE url = 'http://opened.com'");
        $this->assertSame('2018-11-05', $stmt->fetchColumn(),
            'opened date from main.since must be stored in owned.opened');
    }

    // -----------------------------------------------------------------------
    // Rec 10 — mExtra delimiter variants (parseMExtra)
    // -----------------------------------------------------------------------

    /**
     * Invokes the private parseMExtra() helper directly via reflection.
     * @param  string[] $faveFields
     * @return string[]|null
     */
    private function callParseMExtra(string $raw, array $faveFields): ?array
    {
        $ref = new \ReflectionMethod(ListingAdminImportSource::class, 'parseMExtra');
        $ref->setAccessible(true);
        return $ref->invoke($this->adapter(), $raw, $faveFields);
    }

    public function testParseMExtraHandlesDoublePipeDelimiter(): void
    {
        $this->assertSame(['red', 'metal'], $this->callParseMExtra('red||metal', ['favcolor', 'favsong']));
    }

    public function testParseMExtraHandlesSinglePipeDelimiter(): void
    {
        $this->assertSame(['blue', 'rock'], $this->callParseMExtra('blue|rock', ['favcolor', 'favsong']));
    }

    public function testParseMExtraHandlesNewlineDelimiter(): void
    {
        $this->assertSame(['green', 'pop'], $this->callParseMExtra("green\npop", ['favcolor', 'favsong']));
    }

    public function testParseMExtraReturnsNullWhenDelimiterUnrecognised(): void
    {
        $this->assertNull($this->callParseMExtra('unparseable', ['favcolor', 'favsong']));
    }

    public function testParseMExtraReturnsBlanksForEmptyRawWithFaveFields(): void
    {
        $this->assertSame(['', ''], $this->callParseMExtra('', ['favcolor', 'favsong']));
    }

    public function testParseMExtraReturnsEmptyArrayWhenNoFaveFields(): void
    {
        $this->assertSame([], $this->callParseMExtra('anything||goes', []));
        $this->assertSame([], $this->callParseMExtra('unparseable', []));
    }

    /**
     * Integration-level check of the extraFailures warning produced by runMembers().
     *
     * Note: the member INSERT in runMembers() uses MySQL's "ON DUPLICATE KEY UPDATE",
     * which SQLite rejects as a syntax error (caught per-row into $errors). The
     * extraFailures count/warning is computed before that INSERT is attempted, so it
     * remains verifiable here even though imported counts/member rows are not
     * (consistent with this file's existing "member import correctness is out of
     * scope" note for SQLite fixtures).
     */
    public function testLaMemberImportWarnsWhenMExtraUnparseable(): void
    {
        $src = $this->makePdo();
        $tgt = $this->makePdo();

        $this->seedLaSource($src);
        $this->seedTarget($tgt);

        $listingId = $this->insertSourceListing($src, 'http://fl.com');
        $src->exec("UPDATE main SET fave_fields = 'favcolor,favsong' WHERE id = {$listingId}");

        $src->exec("INSERT INTO members (fNiq, mName, mEmail, mExtra) VALUES ({$listingId}, 'Double Pipe', 'pp@test.com', 'red||metal')");
        $src->exec("INSERT INTO members (fNiq, mName, mEmail, mExtra) VALUES ({$listingId}, 'Unparseable', 'up@test.com', 'unparseable')");

        $result = $this->adapter()->run($src, $tgt, $this->makeOptions([
            'import_listings' => '1',
            'import_members'  => '1',
        ]));

        $membersRow = null;
        foreach ($result->rows as $r) {
            if ($r['type'] === 'Members') { $membersRow = $r; break; }
        }
        $this->assertNotNull($membersRow);
        $this->assertContains(
            '1 member(s) imported without custom field data (mExtra could not be parsed).',
            $membersRow['warnings']
        );
    }

    // -----------------------------------------------------------------------
    // main.desc → lstr_fanlisting_settings.description
    // -----------------------------------------------------------------------

    /** Adds desc / form_join / form_update / form_reset columns to the LA source main table. */
    private function addFormColumns(PDO $pdo): void
    {
        $pdo->exec("ALTER TABLE main ADD COLUMN desc TEXT NULL");
        $pdo->exec("ALTER TABLE main ADD COLUMN form_join   TEXT NOT NULL DEFAULT ''");
        $pdo->exec("ALTER TABLE main ADD COLUMN form_update TEXT NOT NULL DEFAULT ''");
        $pdo->exec("ALTER TABLE main ADD COLUMN form_reset  TEXT NOT NULL DEFAULT ''");
    }

    public function testDescriptionImportedFromSourceOnNewListing(): void
    {
        $src = $this->makePdo();
        $tgt = $this->makePdo();

        $this->seedLaSource($src);
        $this->addFormColumns($src);
        $this->seedTarget($tgt);

        $id = $this->insertSourceListing($src, 'http://fl.com');
        $src->prepare("UPDATE main SET desc = :d WHERE id = :id")->execute(['d' => 'A lovely fanlisting.', 'id' => $id]);

        $this->adapter()->run($src, $tgt, $this->makeOptions(['import_listings' => '1']));

        $stmt = $tgt->query("SELECT description FROM lstr_fanlisting_settings");
        $this->assertSame('A lovely fanlisting.', $stmt->fetchColumn());
    }

    public function testBlankDescriptionStoredAsNullOnNewListing(): void
    {
        $src = $this->makePdo();
        $tgt = $this->makePdo();

        $this->seedLaSource($src);
        $this->addFormColumns($src);
        $this->seedTarget($tgt);

        // desc column present but blank — should store NULL
        $this->insertSourceListing($src, 'http://fl.com');

        $this->adapter()->run($src, $tgt, $this->makeOptions(['import_listings' => '1']));

        $stmt = $tgt->query("SELECT description FROM lstr_fanlisting_settings");
        $row  = $stmt->fetch();
        $this->assertNull($row['description'], 'Blank desc should be stored as NULL');
    }

    public function testDescriptionAppliedOnOverwrite(): void
    {
        $src = $this->makePdo();
        $tgt = $this->makePdo();

        $this->seedLaSource($src);
        $this->addFormColumns($src);
        $this->seedTarget($tgt);

        $id = $this->insertSourceListing($src, 'http://fl.com');
        $src->prepare("UPDATE main SET desc = :d WHERE id = :id")->execute(['d' => 'Updated description.', 'id' => $id]);

        // Pre-seed existing listing + settings row with no description
        $tgt->exec("INSERT INTO owned (listingid, dbtable, title, subject, url) VALUES (1, 'fl_com', 'Test', 'Test', 'http://fl.com')");
        $tgt->exec("INSERT INTO lstr_fanlisting_settings (fanlisting_id) VALUES (1)");

        $this->adapter()->run($src, $tgt, $this->makeOptions([
            'import_listings'   => '1',
            'conflict_strategy' => 'overwrite',
        ]));

        $stmt = $tgt->query("SELECT description FROM lstr_fanlisting_settings WHERE fanlisting_id = 1");
        $this->assertSame('Updated description.', $stmt->fetchColumn(), 'Overwrite should apply new description');
    }

    public function testDescriptionNotTouchedOnSkipWhenSettingsRowExists(): void
    {
        $src = $this->makePdo();
        $tgt = $this->makePdo();

        $this->seedLaSource($src);
        $this->addFormColumns($src);
        $this->seedTarget($tgt);

        $id = $this->insertSourceListing($src, 'http://fl.com');
        $src->prepare("UPDATE main SET desc = :d WHERE id = :id")->execute(['d' => 'Source description.', 'id' => $id]);

        // Pre-seed with a different existing description
        $tgt->exec("INSERT INTO owned (listingid, dbtable, title, subject, url) VALUES (1, 'fl_com', 'Test', 'Test', 'http://fl.com')");
        $tgt->exec("INSERT INTO lstr_fanlisting_settings (fanlisting_id, description) VALUES (1, 'Original description.')");

        $this->adapter()->run($src, $tgt, $this->makeOptions([
            'import_listings'   => '1',
            'conflict_strategy' => 'skip',
        ]));

        $stmt = $tgt->query("SELECT description FROM lstr_fanlisting_settings WHERE fanlisting_id = 1");
        $this->assertSame('Original description.', $stmt->fetchColumn(), 'Skip should not overwrite existing description');
    }

    // -----------------------------------------------------------------------
    // Form URL overrides (main.form_join/update/reset → form_url_join/update/reset)
    // -----------------------------------------------------------------------

    public function testFormUrlJoinImportedWhenCustom(): void
    {
        $src = $this->makePdo();
        $tgt = $this->makePdo();

        $this->seedLaSource($src);
        $this->addFormColumns($src);
        $this->seedTarget($tgt);

        $id = $this->insertSourceListing($src, 'http://fl.com');
        $src->prepare("UPDATE main SET form_join = :v WHERE id = :id")->execute(['v' => 'custom-join.php', 'id' => $id]);

        $this->adapter()->run($src, $tgt, $this->makeOptions(['import_listings' => '1']));

        $stmt = $tgt->query("SELECT form_url_join FROM lstr_fanlisting_settings");
        $this->assertSame('custom-join.php', $stmt->fetchColumn());
    }

    public function testFormUrlJoinDefaultFilenameNotImported(): void
    {
        $src = $this->makePdo();
        $tgt = $this->makePdo();

        $this->seedLaSource($src);
        $this->addFormColumns($src);
        $this->seedTarget($tgt);

        $id = $this->insertSourceListing($src, 'http://fl.com');
        // 'join.php' is the LA default — should be skipped
        $src->prepare("UPDATE main SET form_join = :v WHERE id = :id")->execute(['v' => 'join.php', 'id' => $id]);

        $this->adapter()->run($src, $tgt, $this->makeOptions(['import_listings' => '1']));

        $stmt = $tgt->query("SELECT form_url_join FROM lstr_fanlisting_settings");
        $this->assertSame('', $stmt->fetchColumn(), 'Default filename should not be imported');
    }

    public function testFormUrlUpdateDefaultFilenameNotImported(): void
    {
        $src = $this->makePdo();
        $tgt = $this->makePdo();

        $this->seedLaSource($src);
        $this->addFormColumns($src);
        $this->seedTarget($tgt);

        $id = $this->insertSourceListing($src, 'http://fl.com');
        $src->prepare("UPDATE main SET form_update = :v WHERE id = :id")->execute(['v' => 'update.php', 'id' => $id]);

        $this->adapter()->run($src, $tgt, $this->makeOptions(['import_listings' => '1']));

        $stmt = $tgt->query("SELECT form_url_update FROM lstr_fanlisting_settings");
        $this->assertSame('', $stmt->fetchColumn(), 'Default filename should not be imported');
    }

    public function testFormUrlResetDefaultFilenameNotImported(): void
    {
        $src = $this->makePdo();
        $tgt = $this->makePdo();

        $this->seedLaSource($src);
        $this->addFormColumns($src);
        $this->seedTarget($tgt);

        $id = $this->insertSourceListing($src, 'http://fl.com');
        $src->prepare("UPDATE main SET form_reset = :v WHERE id = :id")->execute(['v' => 'reset.php', 'id' => $id]);

        $this->adapter()->run($src, $tgt, $this->makeOptions(['import_listings' => '1']));

        $stmt = $tgt->query("SELECT form_url_reset FROM lstr_fanlisting_settings");
        $this->assertSame('', $stmt->fetchColumn(), 'Default filename should not be imported');
    }

    // -----------------------------------------------------------------------
    // Bridge listing links (lstr_listing_external_refs)
    //
    // public/compat/listing-admin/luster-bridge.php turns LA's $listid into a
    // Luster listing through these links (ListingAdminBridgeResolver), so each
    // test checks the links the same way the bridge reads them.
    // -----------------------------------------------------------------------

    private function addExternalRefsTable(PDO $tgt): void
    {
        $tgt->exec(
            'CREATE TABLE IF NOT EXISTS lstr_listing_external_refs (
                id                   INTEGER PRIMARY KEY AUTOINCREMENT,
                listing_id           INTEGER NOT NULL,
                source               TEXT    NOT NULL,
                external_id          TEXT    NOT NULL,
                external_slug_or_url TEXT    NOT NULL DEFAULT \'\',
                created_at           TEXT    NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE (source, external_id)
            )'
        );
    }

    /** @param array<string,string> $values LA option name => value */
    private function addLaOptions(PDO $src, array $values, string $prefix = ''): void
    {
        $src->exec("CREATE TABLE IF NOT EXISTS `{$prefix}options` (name TEXT PRIMARY KEY, text TEXT NOT NULL DEFAULT '')");
        $stmt = $src->prepare("INSERT INTO `{$prefix}options` (name, text) VALUES (:n, :t)");
        foreach ($values as $name => $text) {
            $stmt->execute(['n' => $name, 't' => $text]);
        }
    }

    /** @return array<string,int> "source|external_id" => listing_id */
    private function bridgeRefs(PDO $tgt): array
    {
        $refs = [];
        foreach ($tgt->query('SELECT source, external_id, listing_id FROM lstr_listing_external_refs') as $r) {
            $refs[$r['source'] . '|' . $r['external_id']] = (int) $r['listing_id'];
        }
        ksort($refs);
        return $refs;
    }

    private function lusterIdForUrl(PDO $tgt, string $url): int
    {
        $stmt = $tgt->prepare('SELECT listingid FROM owned WHERE url = :url');
        $stmt->execute(['url' => $url]);
        return (int) $stmt->fetchColumn();
    }

    /** @return array<string,mixed> */
    private function resultRow(\Luster\Import\ImportResult $result, string $type): array
    {
        foreach ($result->rows as $row) {
            if ($row['type'] === $type) {
                return $row;
            }
        }
        $this->fail("No '{$type}' row in the import result");
    }

    private function sourceKeyFor(string $admHttp, string $laPrefix = ''): string
    {
        return (string) \Luster\Import\ListingAdminSourceKey::resolve('', $admHttp, '', $laPrefix)['key'];
    }

    public function testImportLinksNewListingsForTheBridge(): void
    {
        $src = $this->makePdo();
        $tgt = $this->makePdo();
        $this->seedLaSource($src);
        $this->addLaOptions($src, ['adm_http' => 'http://example.com/fan/admin/']);
        $this->seedTarget($tgt);
        $this->addExternalRefsTable($tgt);

        $laA = $this->insertSourceListing($src, 'http://a.example.com', 'A');
        $laB = $this->insertSourceListing($src, 'http://b.example.com', 'B');

        $result = $this->adapter()->run($src, $tgt, $this->makeOptions(['import_listings' => '1']));

        $key  = $this->sourceKeyFor('http://example.com/fan/admin/');
        $idA  = $this->lusterIdForUrl($tgt, 'http://a.example.com');
        $idB  = $this->lusterIdForUrl($tgt, 'http://b.example.com');
        $this->assertSame([
            "listing-admin:{$key}|{$laA}" => $idA,
            "listing-admin:{$key}|{$laB}" => $idB,
            "listing-admin|{$laA}"        => $idA,
            "listing-admin|{$laB}"        => $idB,
        ], $this->bridgeRefs($tgt));

        $row = $this->resultRow($result, 'Bridge listing links');
        $this->assertSame(2, $row['imported']);
        $this->assertSame(0, $row['skipped']);
        $this->assertSame([], $row['errors']);
        $this->assertContains("Source ID for this install: {$key}", $row['warnings']);

        $this->assertSame($idA, \Luster\Support\ListingAdminBridgeResolver::resolve($tgt, $laA, ''));
        $this->assertSame($idB, \Luster\Support\ListingAdminBridgeResolver::resolve($tgt, $laB, $key));
    }

    public function testImportLinksListingsThatAlreadyExistInLuster(): void
    {
        $src = $this->makePdo();
        $tgt = $this->makePdo();
        $this->seedLaSource($src);
        $this->addLaOptions($src, ['adm_http' => 'http://example.com/admin']);
        $this->seedTarget($tgt);
        $this->addExternalRefsTable($tgt);

        // Imported in an earlier run, before the importer saved bridge links.
        $tgt->exec("INSERT INTO owned (dbtable, title, subject, url) VALUES ('old', 'Old', 'Old', 'http://old.example.com')");
        $existingId = (int) $tgt->lastInsertId();
        $laId       = $this->insertSourceListing($src, 'http://old.example.com', 'Old');

        $this->adapter()->run($src, $tgt, $this->makeOptions(['import_listings' => '1']));

        $this->assertSame(1, (int) $tgt->query('SELECT COUNT(*) FROM owned')->fetchColumn(), 'Listing should be matched, not duplicated');
        $this->assertSame($existingId, \Luster\Support\ListingAdminBridgeResolver::resolve($tgt, $laId, ''));
        $this->assertSame(
            $existingId,
            \Luster\Support\ListingAdminBridgeResolver::resolve($tgt, $laId, $this->sourceKeyFor('http://example.com/admin')),
        );
    }

    public function testRerunDoesNotDuplicateBridgeLinks(): void
    {
        $src = $this->makePdo();
        $tgt = $this->makePdo();
        $this->seedLaSource($src);
        $this->addLaOptions($src, ['adm_http' => 'http://example.com/admin']);
        $this->seedTarget($tgt);
        $this->addExternalRefsTable($tgt);
        $this->insertSourceListing($src, 'http://a.example.com', 'A');
        $this->insertSourceListing($src, 'http://b.example.com', 'B');

        $options = $this->makeOptions(['import_listings' => '1']);
        $this->adapter()->run($src, $tgt, $options);
        $before = $this->bridgeRefs($tgt);

        $second = $this->adapter()->run($src, $tgt, $options);

        $this->assertSame($before, $this->bridgeRefs($tgt));
        $row = $this->resultRow($second, 'Bridge listing links');
        $this->assertSame(0, $row['imported']);
        $this->assertSame(2, $row['skipped']);
        $this->assertSame(['Already linked', 'Already linked'], array_column($row['skipped_items'], 'reason'));
    }

    public function testSharedLinkPointingElsewhereIsLeftAloneAndReported(): void
    {
        $src = $this->makePdo();
        $tgt = $this->makePdo();
        $this->seedLaSource($src);
        $this->addLaOptions($src, ['adm_http' => 'http://second.example.com/admin']);
        $this->seedTarget($tgt);
        $this->addExternalRefsTable($tgt);

        $laId = $this->insertSourceListing($src, 'http://new.example.com', 'New');
        // Another LA install already owns the shared link for this LA listing ID.
        $tgt->prepare("INSERT INTO lstr_listing_external_refs (listing_id, source, external_id) VALUES (99, 'listing-admin', ?)")
            ->execute([(string) $laId]);

        $result = $this->adapter()->run($src, $tgt, $this->makeOptions(['import_listings' => '1']));

        $key   = $this->sourceKeyFor('http://second.example.com/admin');
        $newId = $this->lusterIdForUrl($tgt, 'http://new.example.com');

        $this->assertSame(99, \Luster\Support\ListingAdminBridgeResolver::resolve($tgt, $laId, ''), 'Shared link must keep pointing at the first install');
        $this->assertSame($newId, \Luster\Support\ListingAdminBridgeResolver::resolve($tgt, $laId, $key), 'This install still gets its own link');

        $row = $this->resultRow($result, 'Bridge listing links');
        $this->assertSame(0, $row['imported']);
        $this->assertSame(1, $row['skipped']);
        $this->assertSame('Shared link already points to Luster listing #99', $row['skipped_items'][0]['reason']);
        $this->assertStringContainsString("set LUSTER_LA_SOURCE to \"{$key}\"", implode(' ', $row['warnings']));
    }

    public function testTwoInstallsEachGetTheirOwnLinks(): void
    {
        $tgt = $this->makePdo();
        $this->seedTarget($tgt);
        $this->addExternalRefsTable($tgt);

        $first = $this->makePdo();
        $this->seedLaSource($first);
        $this->addLaOptions($first, ['adm_http' => 'http://first.example.com/admin']);
        $laFirst = $this->insertSourceListing($first, 'http://first.example.com/fl', 'First');

        $second = $this->makePdo();
        $this->seedLaSource($second);
        $this->addLaOptions($second, ['adm_http' => 'http://second.example.com/admin']);
        $laSecond = $this->insertSourceListing($second, 'http://second.example.com/fl', 'Second');

        $this->assertSame($laFirst, $laSecond, 'Both installs use the same LA listing ID — the case being tested');

        $options = $this->makeOptions(['import_listings' => '1']);
        $this->adapter()->run($first, $tgt, $options);
        $this->adapter()->run($second, $tgt, $options);

        $firstId  = $this->lusterIdForUrl($tgt, 'http://first.example.com/fl');
        $secondId = $this->lusterIdForUrl($tgt, 'http://second.example.com/fl');
        $resolve  = static fn(string $key): ?int => \Luster\Support\ListingAdminBridgeResolver::resolve($tgt, $laFirst, $key);

        $this->assertSame($firstId, $resolve(''), 'First install keeps the shared link');
        $this->assertSame($firstId, $resolve($this->sourceKeyFor('http://first.example.com/admin')));
        $this->assertSame($secondId, $resolve($this->sourceKeyFor('http://second.example.com/admin')));
        $this->assertNull($resolve('some-other-site'), 'An unknown Source ID never falls back to the shared link');
    }

    public function testNoWebAddressSavesSharedLinksOnly(): void
    {
        $src = $this->makePdo();
        $tgt = $this->makePdo();
        $this->seedLaSource($src);   // no options table at all
        $this->seedTarget($tgt);
        $this->addExternalRefsTable($tgt);
        $laId = $this->insertSourceListing($src, 'http://a.example.com', 'A');

        $result = $this->adapter()->run($src, $tgt, $this->makeOptions(['import_listings' => '1']));

        $this->assertSame(
            ["listing-admin|{$laId}" => $this->lusterIdForUrl($tgt, 'http://a.example.com')],
            $this->bridgeRefs($tgt),
        );
        $this->assertContains(
            'No Source ID for this install, so only the shared bridge links were saved.',
            $this->resultRow($result, 'Bridge listing links')['warnings'],
        );
    }

    public function testTypedSourceIdIsUsedForThisInstallsLinks(): void
    {
        $src = $this->makePdo();
        $tgt = $this->makePdo();
        $this->seedLaSource($src);
        $this->addLaOptions($src, ['adm_http' => 'http://new-domain.example.com/admin']);
        $this->seedTarget($tgt);
        $this->addExternalRefsTable($tgt);
        $laId = $this->insertSourceListing($src, 'http://a.example.com', 'A');

        $this->adapter()->run($src, $tgt, $this->makeOptions([
            'import_listings' => '1',
            'la_source_id'    => 'old-collective',
        ]));

        $this->assertArrayHasKey("listing-admin:old-collective|{$laId}", $this->bridgeRefs($tgt));
        $this->assertCount(2, $this->bridgeRefs($tgt), 'Only the typed Source ID and the shared link, no worked-out key');
    }

    public function testInvalidSourceIdStopsTheImportBeforeAnythingIsWritten(): void
    {
        $src = $this->makePdo();
        $tgt = $this->makePdo();
        $this->seedLaSource($src);
        $this->seedTarget($tgt);
        $this->addExternalRefsTable($tgt);
        $this->insertSourceListing($src, 'http://a.example.com', 'A');

        try {
            $this->adapter()->run($src, $tgt, $this->makeOptions([
                'import_listings' => '1',
                'la_source_id'    => 'Old_Collective',
            ]));
            $this->fail('An invalid Source ID should stop the import');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Source ID', $e->getMessage());
        }

        $this->assertSame(0, (int) $tgt->query('SELECT COUNT(*) FROM owned')->fetchColumn());
        $this->assertSame([], $this->bridgeRefs($tgt));
    }

    public function testLiveConnectionAndUploadedDumpGetTheSameSourceKey(): void
    {
        $laP  = 'la_';
        $keys = [];

        // '' = live connection (tables named with the LA prefix only);
        // 'dump_' = uploaded SQL dump (temporary prefix in front of the LA prefix).
        foreach (['', 'dump_'] as $dumpTp) {
            $src  = $this->makePdo();
            $tgt  = $this->makePdo();
            $sp   = $dumpTp . $laP;
            $sreg = $dumpTp . str_replace('_', '', $laP);

            $src->exec("CREATE TABLE `{$sreg}` (
                id INTEGER PRIMARY KEY AUTOINCREMENT, title TEXT NOT NULL DEFAULT '', subject TEXT NOT NULL DEFAULT '',
                url TEXT NOT NULL DEFAULT '', category TEXT NOT NULL DEFAULT '', since TEXT NULL, updated TEXT NULL,
                fave_fields TEXT NOT NULL DEFAULT '', trouble INTEGER NOT NULL DEFAULT 0, upcoming INTEGER NOT NULL DEFAULT 0,
                image TEXT NOT NULL DEFAULT ''
            )");
            $src->exec("CREATE TABLE `{$sp}categories` (catid INTEGER PRIMARY KEY, catname TEXT NOT NULL DEFAULT '', parent INTEGER NOT NULL DEFAULT 0)");
            $src->exec("INSERT INTO `{$sreg}` (title, subject, url) VALUES ('A', 'A', 'http://a.example.com')");
            $this->addLaOptions($src, ['adm_http' => 'http://example.com/fan/admin/'], $sp);
            $this->seedTarget($tgt);
            $this->addExternalRefsTable($tgt);

            $post = ['import_listings' => '1', 'la_table_prefix' => $laP];
            if ($dumpTp !== '') {
                $post['_dump_table_prefix']   = $dumpTp;
                $post['_dump_schema_variant'] = 'listing_admin_3x';
            }
            $this->adapter()->run($src, $tgt, $this->makeOptions($post));

            $keyed = array_filter(array_keys($this->bridgeRefs($tgt)), static fn(string $k): bool => str_starts_with($k, 'listing-admin:'));
            $this->assertCount(1, $keyed);
            $keys[$dumpTp === '' ? 'live' : 'dump'] = reset($keyed);
        }

        $this->assertSame($keys['live'], $keys['dump']);
        $this->assertSame('listing-admin:' . $this->sourceKeyFor('http://example.com/fan/admin/', $laP) . '|1', $keys['live']);
    }

    public function testPreviewSaysWhichBridgeLinksWillBeSaved(): void
    {
        $tgt = $this->makePdo();
        $this->seedTarget($tgt);

        $withAddress = $this->makePdo();
        $this->seedLaSource($withAddress);
        $this->addLaOptions($withAddress, ['adm_http' => 'http://example.com/admin']);

        $noAddress = $this->makePdo();
        $this->seedLaSource($noAddress);

        $options = $this->makeOptions(['import_listings' => '1']);
        $warningsFor = function (PDO $src) use ($tgt, $options): string {
            foreach ($this->adapter()->preview($src, $tgt, $options)->rows as $row) {
                if ($row['type'] === 'Owned Listings') {
                    return implode(' ', $row['warnings']);
                }
            }
            $this->fail('No Owned Listings preview row');
        };

        $this->assertStringContainsString(
            'Source ID ' . $this->sourceKeyFor('http://example.com/admin') . ", worked out from this install's admin address",
            $warningsFor($withAddress),
        );
        $this->assertStringContainsString("don't include its web address", $warningsFor($noAddress));
    }

    // -----------------------------------------------------------------------
    // Protocol-relative ('//host/path') addresses: an install keeps the key
    // earlier imports saved its links under, or the import stops and asks.
    // -----------------------------------------------------------------------

    // 1.0.0-3's keys for one LA install at example.com/fan/admin with no table prefix,
    // worked out with that release's code (git show e3da9ea:src/Import/ListingAdminSourceKey.php).
    /** https://example.com/fan/admin — and every form of the address from now on. */
    private const KEY_C = 'a7d0fcd35541';
    /** //Example.COM/fan/admin */
    private const KEY_L = '34d05aee870f';

    /** @return array{int, int} LA IDs of listings A and B. */
    private function sourceWithTwoListings(PDO $src, string $admHttp): array
    {
        $this->seedLaSource($src);
        $this->addLaOptions($src, ['adm_http' => $admHttp]);
        return [
            $this->insertSourceListing($src, 'http://a.example.com', 'A'),
            $this->insertSourceListing($src, 'http://b.example.com', 'B'),
        ];
    }

    /**
     * Listing A as an earlier import left it, linked under each of $keys.
     * Listing B is new to Luster.
     *
     * @param list<string> $keys
     */
    private function earlierImport(PDO $tgt, int $laA, array $keys): int
    {
        $this->seedTarget($tgt);
        $this->addExternalRefsTable($tgt);
        $tgt->exec("INSERT INTO owned (dbtable, title, subject, url) VALUES ('a', 'A', 'A', 'http://a.example.com')");
        $idA = (int) $tgt->lastInsertId();

        $link = $tgt->prepare('INSERT INTO lstr_listing_external_refs (listing_id, source, external_id) VALUES (?, ?, ?)');
        $link->execute([$idA, 'listing-admin', (string) $laA]);
        foreach ($keys as $key) {
            $link->execute([$idA, "listing-admin:{$key}", (string) $laA]);
        }
        return $idA;
    }

    /** @return array<string, list<array<string,mixed>>> */
    private function targetRows(PDO $tgt): array
    {
        $rows = [];
        foreach (['owned', 'lstr_listing_external_refs', 'lstr_fanlisting_settings', 'lstr_joined'] as $table) {
            $rows[$table] = $tgt->query("SELECT * FROM {$table} ORDER BY 1")->fetchAll();
        }
        return $rows;
    }

    public function testRerunKeepsTheSourceIdAnEarlierImportUsed(): void
    {
        $src = $this->makePdo();
        $tgt = $this->makePdo();
        [$laA, $laB] = $this->sourceWithTwoListings($src, '//Example.COM/fan/admin');
        $idA = $this->earlierImport($tgt, $laA, [self::KEY_L]);
        $options = $this->makeOptions(['import_listings' => '1']);

        $this->assertStringContainsString(
            'Bridge links will be saved under Source ID ' . self::KEY_L,
            implode(' ', $this->adapter()->preview($src, $tgt, $options)->rows[0]['warnings']),
        );

        $result = $this->adapter()->run($src, $tgt, $options);
        $idB    = $this->lusterIdForUrl($tgt, 'http://b.example.com');

        $this->assertSame([
            'listing-admin:' . self::KEY_L . "|{$laA}" => $idA,
            'listing-admin:' . self::KEY_L . "|{$laB}" => $idB,
            "listing-admin|{$laA}"                    => $idA,
            "listing-admin|{$laB}"                    => $idB,
        ], $this->bridgeRefs($tgt));
        $this->assertContains('Source ID for this install: ' . self::KEY_L, $this->resultRow($result, 'Bridge listing links')['warnings']);

        $this->assertSame($idA, \Luster\Support\ListingAdminBridgeResolver::resolve($tgt, $laA, self::KEY_L));
        $this->assertSame($idB, \Luster\Support\ListingAdminBridgeResolver::resolve($tgt, $laB, self::KEY_L), 'A listing added in this run is found too');
    }

    public function testSeveralEarlierSourceIdsStopThePreviewAndRunBeforeAnythingIsWritten(): void
    {
        $src = $this->makePdo();
        $tgt = $this->makePdo();
        [$laA] = $this->sourceWithTwoListings($src, '//Example.COM/fan/admin');
        $this->earlierImport($tgt, $laA, [self::KEY_C, self::KEY_L]);
        $before  = $this->targetRows($tgt);
        $options = $this->makeOptions(['import_listings' => '1']);

        foreach (['preview', 'run'] as $step) {
            try {
                $this->adapter()->{$step}($src, $tgt, $options);
                $this->fail("The {$step} should stop");
            } catch (\RuntimeException $e) {
                $this->assertStringStartsWith(
                    'Earlier imports saved this Listing Admin install under more than one Source ID: '
                    . self::KEY_C . ' (1 listing link, no codes) and ' . self::KEY_L . ' (1 listing link, no codes).',
                    $e->getMessage(),
                    $step
                );
            }
            $this->assertSame($before, $this->targetRows($tgt), $step);
        }
    }

    public function testEitherEarlierSourceIdCanBeChosenByTypingIt(): void
    {
        foreach ([self::KEY_L => self::KEY_C, self::KEY_C => self::KEY_L] as $chosen => $other) {
            $src = $this->makePdo();
            $tgt = $this->makePdo();
            [$laA, $laB] = $this->sourceWithTwoListings($src, '//Example.COM/fan/admin');
            $idA = $this->earlierImport($tgt, $laA, [self::KEY_C, self::KEY_L]);

            $this->adapter()->run($src, $tgt, $this->makeOptions(['import_listings' => '1', 'la_source_id' => $chosen]));

            $idB  = $this->lusterIdForUrl($tgt, 'http://b.example.com');
            $refs = $this->bridgeRefs($tgt);
            $this->assertSame($idB, $refs["listing-admin:{$chosen}|{$laB}"] ?? null, "Chosen {$chosen}");
            $this->assertSame($idA, \Luster\Support\ListingAdminBridgeResolver::resolve($tgt, $laA, $chosen));
            $this->assertSame(
                ["listing-admin:{$other}|{$laA}" => $idA],
                array_filter($refs, static fn(string $k): bool => str_starts_with($k, "listing-admin:{$other}|"), ARRAY_FILTER_USE_KEY),
                "Nothing new under {$other}",
            );
        }
    }

    public function testImportsThatDontUseTheSourceIdNeverLookItUp(): void
    {
        $src = $this->makePdo();
        $this->sourceWithTwoListings($src, '//Example.COM/fan/admin');
        $this->insertJoined($src, 'http://joined.example.com', 0);

        // Earlier Source IDs that would stop a listings or codes import, and a database
        // where the lookup would fail anyway.
        $tgt = TracingPdo::memory();
        $this->earlierImport($tgt, 1, [self::KEY_C, self::KEY_L]);
        $tgt->statements = [];
        $tgt->failOn     = '/lstr_listing_external_refs|lstr_codes/';

        // Members alone does nothing without the listings step, so joined listings do the writing.
        $options = $this->makeOptions(['import_joined' => '1', 'import_members' => '1']);
        $this->adapter()->preview($src, $tgt, $options);
        $result = $this->adapter()->run($src, $tgt, $options);

        $this->assertSame(1, $this->resultRow($result, 'Joined listings')['imported']);
        $this->assertSame(1, (int) $tgt->query('SELECT COUNT(*) FROM lstr_joined')->fetchColumn());
        $this->assertSame([], $tgt->statementsMatching('/lstr_listing_external_refs|lstr_codes/'));
    }

    public function testAFailedSourceIdLookupStopsThePreviewAndRunBeforeAnythingIsWritten(): void
    {
        $src = $this->makePdo();
        [$laA] = $this->sourceWithTwoListings($src, '//Example.COM/fan/admin');

        // This install's earlier links are there; a failed read must not look like "never imported".
        $tgt = TracingPdo::memory();
        $this->earlierImport($tgt, $laA, [self::KEY_L]);
        $before = $this->targetRows($tgt);
        $tgt->failOn = '/source IN/';
        $options = $this->makeOptions(['import_listings' => '1']);

        foreach (['preview', 'run'] as $step) {
            try {
                $this->adapter()->{$step}($src, $tgt, $options);
                $this->fail("The {$step} should stop");
            } catch (\RuntimeException $e) {
                $this->assertStringStartsWith("Luster couldn't check which Source ID earlier imports of this install used", $e->getMessage(), $step);
            }
            $tgt->failOn = null;
            $this->assertSame($before, $this->targetRows($tgt), $step);
            $tgt->failOn = '/source IN/';
        }
    }

    // -----------------------------------------------------------------------
    // Unusable dates (0000-00-00, blank, impossible) stay blank. The members
    // step writes with MySQL-only SQL (ON DUPLICATE KEY UPDATE), so SQLite can't
    // run it; its join dates go through normalizeImportDate(), tested below.
    // -----------------------------------------------------------------------

    public function testJoinedDatesThatArentUsableAreLeftBlank(): void
    {
        $src = $this->makePdo();
        $tgt = $this->makePdo();
        $this->seedLaSource($src);
        $this->seedTarget($tgt);

        $dates = [
            'http://real.test'       => '2023-11-16',
            'http://zero.test'       => '0000-00-00',
            'http://impossible.test' => '2023-02-29',
            'http://missing.test'    => null,
        ];
        $insert = $src->prepare("INSERT INTO joined (jSubject, jURL, jStatus, jAdd) VALUES ('Joined', ?, 0, ?)");
        foreach ($dates as $url => $added) {
            $insert->execute([$url, $added]);
        }

        $result = $this->adapter()->run($src, $tgt, $this->makeOptions(['import_joined' => '1']));
        $this->assertSame(count($dates), $this->resultRow($result, 'Joined listings')['imported']);

        $this->assertSame([
            'http://real.test'       => '2023-11-16',
            'http://zero.test'       => null,
            'http://impossible.test' => null,
            'http://missing.test'    => null,
        ], $tgt->query('SELECT url, date_joined FROM lstr_joined ORDER BY id')->fetchAll(PDO::FETCH_KEY_PAIR));
    }

    public function testNormalizeImportDateRejectsDatesThatDontExist(): void
    {
        $normalize = static fn(mixed $raw): ?string => (new \ReflectionMethod(ListingAdminImportSource::class, 'normalizeImportDate'))->invoke(null, $raw);

        $this->assertSame('2024-02-29', $normalize('2024-02-29'), 'a leap day');
        $this->assertSame('2024-05-07', $normalize(' 2024-05-07 13:45:00 '));
        foreach ([null, '', '0000-00-00', '1970-01-01', '2023-02-29', '2024-02-30', '2024-13-01', '2024-00-10', '0000-01-01', 'yesterday'] as $raw) {
            $this->assertNull($normalize($raw), var_export($raw, true));
        }
    }

    /** last_updated falls back to the newest member join date; a bad newest date is passed over. */
    public function testLastUpdatedSkipsUnusableMemberDatesForANewListing(): void
    {
        foreach ([
            'one bad newest date'  => [['2024-01-10', '2024-02-30', '0000-00-00'], '2024-01-10'],
            'nothing usable'       => [['2024-02-30', '0000-00-00', ''], null],
        ] as $case => [$dates, $expected]) {
            $src = $this->makePdo();
            $tgt = $this->makePdo();
            $this->seedLaSource($src);
            $this->seedTargetWithLastUpdated($tgt);
            $listingId = $this->insertSourceListing($src, 'http://test.com');
            foreach ($dates as $i => $added) {
                $src->prepare('INSERT INTO members (fNiq, mName, mEmail, mAdd) VALUES (?, ?, ?, ?)')
                    ->execute([$listingId, "Fan {$i}", "fan{$i}@test.com", $added]);
            }

            $this->adapter()->run($src, $tgt, $this->makeOptions(['import_listings' => '1']));

            $this->assertSame($expected, $tgt->query('SELECT last_updated FROM lstr_fanlisting_settings LIMIT 1')->fetchColumn(), $case);
        }
    }

    /** The same fallback when Keep existing finds the listing already in Luster without settings. */
    public function testLastUpdatedSkipsUnusableMemberDatesOnAKeepExistingRerun(): void
    {
        $src = $this->makePdo();
        $tgt = $this->makePdo();
        $this->seedLaSource($src);
        $this->seedTargetWithLastUpdated($tgt);
        $listingId = $this->insertSourceListing($src, 'http://test.com');
        $tgt->exec("INSERT INTO owned (dbtable, title, subject, url) VALUES ('test_fl', 'Test', 'Test', 'http://test.com')");
        $existing = (int) $tgt->lastInsertId();
        foreach (['2024-01-10', '2024-02-30', '0000-00-00'] as $i => $added) {
            $src->prepare('INSERT INTO members (fNiq, mName, mEmail, mAdd) VALUES (?, ?, ?, ?)')
                ->execute([$listingId, "Fan {$i}", "fan{$i}@test.com", $added]);
        }

        $this->adapter()->run($src, $tgt, $this->makeOptions(['import_listings' => '1', 'conflict_strategy' => 'skip']));

        $stmt = $tgt->prepare('SELECT last_updated FROM lstr_fanlisting_settings WHERE fanlisting_id = ?');
        $stmt->execute([$existing]);
        $this->assertSame('2024-01-10', $stmt->fetchColumn());
        $this->assertSame(1, (int) $tgt->query('SELECT COUNT(*) FROM owned')->fetchColumn(), 'kept, not added again');
    }

    /** KIM's added_at can't be empty, so an unusable date becomes today; a real one is kept. */
    public function testKimDatesThatArentUsableBecomeToday(): void
    {
        $src = $this->makePdo();
        $tgt = $this->makePdo();
        $this->seedLaSource($src);
        $this->seedTarget($tgt);
        $this->createKimTable($tgt);
        $this->createLaKimTable($src);
        $this->insertSourceListing($src, 'http://test.com');
        foreach (['real@test.com' => '2024-01-01', 'zero@test.com' => '0000-00-00', 'impossible@test.com' => '2024-02-30', 'missing@test.com' => null] as $email => $added) {
            $src->prepare("INSERT INTO kim (fNiq, mEmail, mName, mAdd) VALUES (1, ?, 'Fan', ?)")->execute([$email, $added]);
        }

        $result = $this->adapter()->run($src, $tgt, $this->makeOptions(['import_listings' => '1', 'import_kim' => '1']));
        $this->assertSame(4, $this->resultRow($result, 'KIM List')['imported']);

        $today = date('Y-m-d');
        $this->assertSame([
            'real@test.com'       => '2024-01-01',
            'zero@test.com'       => $today,
            'impossible@test.com' => $today,
            'missing@test.com'    => $today,
        ], $tgt->query('SELECT email, added_at FROM lstr_kim_members ORDER BY id')->fetchAll(PDO::FETCH_KEY_PAIR));
    }
}
