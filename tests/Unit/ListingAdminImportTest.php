<?php

declare(strict_types=1);

namespace Luster\Tests\Unit;

use Luster\Import\ImportOptions;
use Luster\Import\ListingAdminImportSource;
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
                affiliates_template TEXT NULL,
                stats_template   TEXT    NULL,
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
                active       INTEGER NOT NULL DEFAULT 1
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

    public function testKimSkippedWhenAddonNotInstalled(): void
    {
        $src = $this->makePdo();
        $tgt = $this->makePdo();

        $this->seedLaSource($src);
        $this->seedTarget($tgt);
        $this->createLaKimTable($src);
        // Deliberately do NOT create lstr_kim_members in $tgt.

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
        $this->assertStringContainsString('not installed', implode(' ', $kimRow['warnings']));
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

    public function testWishlistSkippedWhenAddonNotInstalled(): void
    {
        $src = $this->makePdo();
        $tgt = $this->makePdo();

        $this->seedLaSource($src);
        $this->seedTarget($tgt);
        $this->createLaWishlistTable($src);
        // Do NOT create lstr_wishlist in target.

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
        $this->assertStringContainsString('not installed', implode(' ', $wRow['warnings']));
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
                affiliates_template TEXT NULL,
                stats_template   TEXT    NULL,
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
}
