<?php

declare(strict_types=1);

namespace Luster\Tests\Unit;

use Luster\Core\FieldRenderer;
use Luster\Core\FieldWrapper;
use Luster\Import\FanaticallyImportSource;
use Luster\Import\ImportOptions;
use Luster\Models\AdditionalField;
use Luster\Support\FormFieldBuilder;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Tests for FanaticallyImportSource:
 *
 *   Fix 1 — approved_by stored as '' not null for unapproved listings
 *   Fix 2 — notify_member_on_join=1 → join_confirmation_email IS NULL;
 *            notify_member_on_join=0 → join_confirmation_email=0
 *   Fix 3 — hold_for_approval absent → auto_approve_joins=1 (Fanatically default = auto)
 *   Fix 4 — for_adoption value imported into lstr_fanlisting_settings
 *
 * Plus joined-status mapping and multi-category categories_json.
 *
 * Uses in-memory SQLite for both source and target; no live MySQL required.
 * MySQL-specific syntax (ON DUPLICATE KEY UPDATE, etc.) is not exercised here.
 */
final class FanaticallyImportSourceTest extends TestCase
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

    private function adapter(): FanaticallyImportSource
    {
        return new FanaticallyImportSource();
    }

    private function makeOptions(array $overrides = []): ImportOptions
    {
        return ImportOptions::fromPost(array_merge([
            'source'            => 'fanatically',
            'conflict_strategy' => 'skip',
            // Use no prefix so tests can create tables without the fanatic_ prefix
            'fn_table_prefix'   => '',
        ], $overrides));
    }

    /** Creates the minimal Fanatically source schema (no prefix). */
    private function seedFanaticallySource(PDO $pdo, array $settings = []): void
    {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS listings (
                id           INTEGER PRIMARY KEY AUTOINCREMENT,
                title        TEXT    NOT NULL DEFAULT \'\',
                subject      TEXT    NOT NULL DEFAULT \'\',
                url          TEXT    NOT NULL DEFAULT \'\',
                status       TEXT    NOT NULL DEFAULT \'current\',
                approved_by  TEXT    NOT NULL DEFAULT \'\',
                last_updated TEXT    NULL,
                for_adoption INTEGER NOT NULL DEFAULT 0,
                opened_date  TEXT    NULL,
                image_file   TEXT    NOT NULL DEFAULT \'\',
                description  TEXT    NOT NULL DEFAULT \'\'
            )'
        );
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS categories (
                id        INTEGER PRIMARY KEY AUTOINCREMENT,
                name      TEXT    NOT NULL DEFAULT \'\',
                parent_id INTEGER NULL
            )'
        );
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS joined (
                id               INTEGER PRIMARY KEY AUTOINCREMENT,
                subject          TEXT    NOT NULL DEFAULT \'\',
                url              TEXT    NOT NULL DEFAULT \'\',
                status           TEXT    NOT NULL DEFAULT \'current\',
                image_file       TEXT    NOT NULL DEFAULT \'\',
                added_at         TEXT    NULL,
                link_status      TEXT    NOT NULL DEFAULT \'unchecked\',
                link_checked_at  TEXT    NULL,
                link_redirect_url TEXT   NOT NULL DEFAULT \'\'
            )'
        );
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS settings (
                setting_key   TEXT NOT NULL PRIMARY KEY,
                setting_value TEXT NOT NULL DEFAULT \'\'
            )'
        );
        // Seed provided settings rows
        $ins = $pdo->prepare('INSERT OR REPLACE INTO settings (setting_key, setting_value) VALUES (:k, :v)');
        foreach ($settings as $k => $v) {
            $ins->execute(['k' => $k, 'v' => $v]);
        }
    }

    /** Creates the minimal Luster target tables. */
    private function seedTarget(PDO $pdo): void
    {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS owned (
                listingid  INTEGER PRIMARY KEY AUTOINCREMENT,
                dbtable    TEXT    NOT NULL DEFAULT \'\',
                title      TEXT    NOT NULL DEFAULT \'\',
                subject    TEXT    NOT NULL DEFAULT \'\',
                email      TEXT    NOT NULL DEFAULT \'\',
                url        TEXT    NOT NULL DEFAULT \'\',
                catid      TEXT    NULL,
                category_id INTEGER NULL,
                opened     TEXT    NULL,
                status     INTEGER NOT NULL DEFAULT 2,
                imagefile  TEXT    NOT NULL DEFAULT \'\',
                additional TEXT    NOT NULL DEFAULT \'\',
                upcoming   INTEGER NOT NULL DEFAULT 0,
                trouble    INTEGER NOT NULL DEFAULT 0,
                dbserver   TEXT    NOT NULL DEFAULT \'\',
                dbuser     TEXT    NOT NULL DEFAULT \'\',
                dbpassword TEXT    NOT NULL DEFAULT \'\',
                dbdatabase TEXT    NOT NULL DEFAULT \'\'
            )'
        );
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS lstr_fanlisting_settings (
                fanlisting_id          INTEGER PRIMARY KEY,
                last_updated           DATE    NULL,
                approved_by            TEXT    NOT NULL DEFAULT \'\',
                queue_updates          INTEGER NOT NULL DEFAULT 0,
                auto_approve_joins     INTEGER NOT NULL DEFAULT 0,
                join_confirmation_email INTEGER NULL,
                for_adoption           INTEGER NOT NULL DEFAULT 0,
                members_per_page       INTEGER NOT NULL DEFAULT 25,
                members_template       TEXT    NULL,
                stats_template         TEXT    NULL,
                image                  TEXT    NULL,
                description            TEXT    NULL
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
            'CREATE TABLE IF NOT EXISTS lstr_joined (
                id               INTEGER PRIMARY KEY AUTOINCREMENT,
                name             TEXT    NOT NULL DEFAULT \'\',
                url              TEXT    NOT NULL DEFAULT \'\',
                image            TEXT    NULL,
                category         TEXT    NOT NULL DEFAULT \'\',
                categories_json  TEXT    NULL,
                category_id      INTEGER NULL,
                date_joined      DATE    NULL,
                active           INTEGER NOT NULL DEFAULT 1,
                status           TEXT    NOT NULL DEFAULT \'joined\',
                sort_order       INTEGER NOT NULL DEFAULT 0,
                link_status      TEXT    NOT NULL DEFAULT \'unchecked\',
                link_checked_at  TEXT    NULL,
                link_redirect_url TEXT   NULL
            )'
        );
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS lstr_affiliates (
                id                    INTEGER PRIMARY KEY AUTOINCREMENT,
                fanlisting_id         INTEGER NULL,
                name                  TEXT    NOT NULL DEFAULT \'\',
                url                   TEXT    NOT NULL DEFAULT \'\',
                email                 TEXT    NOT NULL DEFAULT \'\',
                image                 TEXT    NULL,
                pending               INTEGER NOT NULL DEFAULT 0,
                added_at              TEXT    NULL,
                sort_order            INTEGER NOT NULL DEFAULT 0,
                active                INTEGER NOT NULL DEFAULT 1,
                status                TEXT    NOT NULL DEFAULT \'approved\',
                link_status           TEXT    NOT NULL DEFAULT \'unchecked\',
                link_checked_at       TEXT    NULL,
                link_redirect_url     TEXT    NOT NULL DEFAULT \'\',
                reciprocal_status     TEXT    NOT NULL DEFAULT \'unchecked\',
                reciprocal_checked_at TEXT    NULL
            )'
        );
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS lstr_site_settings (
                setting_key   TEXT NOT NULL PRIMARY KEY,
                setting_value TEXT NOT NULL DEFAULT \'\'
            )'
        );
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS lstr_codes (
                id            INTEGER PRIMARY KEY AUTOINCREMENT,
                fanlisting_id INTEGER NOT NULL,
                size_id       INTEGER NULL,
                donor_id      INTEGER NULL,
                name          TEXT    NOT NULL DEFAULT \'\',
                filename      TEXT    NOT NULL DEFAULT \'\',
                status        TEXT    NOT NULL DEFAULT \'approved\',
                width         INTEGER NOT NULL DEFAULT 0,
                height        INTEGER NOT NULL DEFAULT 0
            )'
        );
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS lstr_code_sizes (
                id            INTEGER PRIMARY KEY AUTOINCREMENT,
                fanlisting_id INTEGER NOT NULL,
                label         TEXT    NULL,
                width         INTEGER NOT NULL DEFAULT 0,
                height        INTEGER NOT NULL DEFAULT 0
            )'
        );
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS lstr_code_donors (
                id            INTEGER PRIMARY KEY AUTOINCREMENT,
                fanlisting_id INTEGER NOT NULL,
                name          TEXT    NOT NULL DEFAULT \'\',
                url           TEXT    NULL,
                email         TEXT    NULL
            )'
        );
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS lstr_listing_categories (
                fanlisting_id INTEGER NOT NULL,
                category_id   INTEGER NOT NULL,
                PRIMARY KEY (fanlisting_id, category_id)
            )'
        );
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS lstr_listing_external_refs (
                id          INTEGER PRIMARY KEY AUTOINCREMENT,
                listing_id  INTEGER NOT NULL,
                source      TEXT    NOT NULL,
                external_id TEXT    NOT NULL,
                external_slug_or_url TEXT NOT NULL DEFAULT \'\',
                metadata_json TEXT NULL,
                UNIQUE (source, external_id)
            )'
        );
    }

    /** Inserts a listing into the source and returns its id. */
    private function insertListing(
        PDO $pdo,
        string  $url,
        string  $approvedBy  = '',
        string  $status      = 'current',
        int     $forAdoption = 0,
        ?string $openedDate  = null,
        ?string $lastUpdated = null,
        string  $description = '',
    ): int {
        $pdo->prepare(
            'INSERT INTO listings (title, subject, url, status, approved_by, for_adoption, opened_date, last_updated, description)
             VALUES (:title, :subject, :url, :status, :ab, :fa, :od, :lu, :desc)'
        )->execute([
            'title'   => 'Test Listing',
            'subject' => 'Test Subject',
            'url'     => $url,
            'status'  => $status,
            'ab'      => $approvedBy,
            'fa'      => $forAdoption,
            'od'      => $openedDate,
            'lu'      => $lastUpdated,
            'desc'    => $description,
        ]);
        return (int) $pdo->lastInsertId();
    }

    /** Inserts a joined listing into the source and returns its id. */
    private function insertJoined(PDO $pdo, string $url, string $status = 'current'): int
    {
        $pdo->prepare(
            'INSERT INTO joined (subject, url, status) VALUES (:subj, :url, :status)'
        )->execute(['subj' => 'Test Joined', 'url' => $url, 'status' => $status]);
        return (int) $pdo->lastInsertId();
    }

    // -----------------------------------------------------------------------
    // Fix 1 — approved_by stored as '' not null
    // -----------------------------------------------------------------------

    public function testApprovedByIsEmptyStringNotNullForUnapprovedListing(): void
    {
        $src = $this->makePdo();
        $tgt = $this->makePdo();
        $this->seedFanaticallySource($src);
        $this->seedTarget($tgt);

        $this->insertListing($src, 'https://example.com/fl/', approvedBy: '');

        $opts = $this->makeOptions(['import_listings' => '1']);
        $this->adapter()->run($src, $tgt, $opts);

        $row = $tgt->query('SELECT approved_by FROM lstr_fanlisting_settings LIMIT 1')->fetch();
        self::assertNotNull($row, 'Settings row should be inserted');
        self::assertSame('', $row['approved_by'], 'approved_by should be empty string, not null');
    }

    public function testApprovedByTflPreservedForTflListing(): void
    {
        $src = $this->makePdo();
        $tgt = $this->makePdo();
        $this->seedFanaticallySource($src);
        $this->seedTarget($tgt);

        $this->insertListing($src, 'https://example.com/fl/', approvedBy: 'tfl');

        $opts = $this->makeOptions(['import_listings' => '1']);
        $this->adapter()->run($src, $tgt, $opts);

        $row = $tgt->query('SELECT approved_by FROM lstr_fanlisting_settings LIMIT 1')->fetch();
        self::assertSame('tfl', $row['approved_by']);
    }

    public function testApprovedByAflMappedToTafl(): void
    {
        $src = $this->makePdo();
        $tgt = $this->makePdo();
        $this->seedFanaticallySource($src);
        $this->seedTarget($tgt);

        $this->insertListing($src, 'https://example.com/fl/', approvedBy: 'afl');

        $opts = $this->makeOptions(['import_listings' => '1']);
        $this->adapter()->run($src, $tgt, $opts);

        $row = $tgt->query('SELECT approved_by FROM lstr_fanlisting_settings LIMIT 1')->fetch();
        self::assertSame('tafl', $row['approved_by']);
    }

    // -----------------------------------------------------------------------
    // Fix 2 — notify_member_on_join mapping
    // -----------------------------------------------------------------------

    public function testNotifyMemberOnJoinOneYieldsNullConfirmationEmail(): void
    {
        $src = $this->makePdo();
        $tgt = $this->makePdo();
        $this->seedFanaticallySource($src, ['notify_member_on_join' => '1']);
        $this->seedTarget($tgt);

        $this->insertListing($src, 'https://example.com/fl/');

        $opts = $this->makeOptions(['import_listings' => '1']);
        $this->adapter()->run($src, $tgt, $opts);

        $row = $tgt->query('SELECT join_confirmation_email FROM lstr_fanlisting_settings LIMIT 1')->fetch();
        self::assertNull($row['join_confirmation_email'],
            'notify_member_on_join=1 (Fanatically default: send) should map to NULL in Luster');
    }

    public function testNotifyMemberOnJoinZeroYieldsZeroConfirmationEmail(): void
    {
        $src = $this->makePdo();
        $tgt = $this->makePdo();
        $this->seedFanaticallySource($src, ['notify_member_on_join' => '0']);
        $this->seedTarget($tgt);

        $this->insertListing($src, 'https://example.com/fl/');

        $opts = $this->makeOptions(['import_listings' => '1']);
        $this->adapter()->run($src, $tgt, $opts);

        $row = $tgt->query('SELECT join_confirmation_email FROM lstr_fanlisting_settings LIMIT 1')->fetch();
        self::assertSame('0', (string) $row['join_confirmation_email'],
            'notify_member_on_join=0 (disable) should map to 0 in Luster');
    }

    public function testNotifyMemberOnJoinAbsentYieldsNullConfirmationEmail(): void
    {
        $src = $this->makePdo();
        $tgt = $this->makePdo();
        $this->seedFanaticallySource($src); // no notify_member_on_join setting
        $this->seedTarget($tgt);

        $this->insertListing($src, 'https://example.com/fl/');

        $opts = $this->makeOptions(['import_listings' => '1']);
        $this->adapter()->run($src, $tgt, $opts);

        $row = $tgt->query('SELECT join_confirmation_email FROM lstr_fanlisting_settings LIMIT 1')->fetch();
        self::assertNull($row['join_confirmation_email'],
            'Absent notify_member_on_join should default to NULL (Luster default behaviour)');
    }

    // -----------------------------------------------------------------------
    // Fix 3 — hold_for_approval default
    // -----------------------------------------------------------------------

    public function testHoldForApprovalAbsentDefaultsToAutoApprove(): void
    {
        $src = $this->makePdo();
        $tgt = $this->makePdo();
        $this->seedFanaticallySource($src); // no hold_for_approval row
        $this->seedTarget($tgt);

        $this->insertListing($src, 'https://example.com/fl/');

        $opts = $this->makeOptions(['import_listings' => '1']);
        $this->adapter()->run($src, $tgt, $opts);

        $row = $tgt->query('SELECT auto_approve_joins FROM lstr_fanlisting_settings LIMIT 1')->fetch();
        self::assertSame('1', (string) $row['auto_approve_joins'],
            'Absent hold_for_approval (Fanatically default=0=auto) should produce auto_approve_joins=1');
    }

    public function testHoldForApprovalOneProducesManualApproval(): void
    {
        $src = $this->makePdo();
        $tgt = $this->makePdo();
        $this->seedFanaticallySource($src, ['hold_for_approval' => '1']);
        $this->seedTarget($tgt);

        $this->insertListing($src, 'https://example.com/fl/');

        $opts = $this->makeOptions(['import_listings' => '1']);
        $this->adapter()->run($src, $tgt, $opts);

        $row = $tgt->query('SELECT auto_approve_joins FROM lstr_fanlisting_settings LIMIT 1')->fetch();
        self::assertSame('0', (string) $row['auto_approve_joins'],
            'hold_for_approval=1 should produce auto_approve_joins=0 (manual approval)');
    }

    public function testHoldForApprovalZeroProducesAutoApprove(): void
    {
        $src = $this->makePdo();
        $tgt = $this->makePdo();
        $this->seedFanaticallySource($src, ['hold_for_approval' => '0']);
        $this->seedTarget($tgt);

        $this->insertListing($src, 'https://example.com/fl/');

        $opts = $this->makeOptions(['import_listings' => '1']);
        $this->adapter()->run($src, $tgt, $opts);

        $row = $tgt->query('SELECT auto_approve_joins FROM lstr_fanlisting_settings LIMIT 1')->fetch();
        self::assertSame('1', (string) $row['auto_approve_joins'],
            'hold_for_approval=0 should produce auto_approve_joins=1');
    }

    // -----------------------------------------------------------------------
    // Fix 4 — for_adoption imported into lstr_fanlisting_settings
    // -----------------------------------------------------------------------

    public function testForAdoptionOneWrittenToSettings(): void
    {
        $src = $this->makePdo();
        $tgt = $this->makePdo();
        $this->seedFanaticallySource($src);
        $this->seedTarget($tgt);

        $this->insertListing($src, 'https://example.com/fl/', forAdoption: 1);

        $opts = $this->makeOptions(['import_listings' => '1']);
        $this->adapter()->run($src, $tgt, $opts);

        $row = $tgt->query('SELECT for_adoption FROM lstr_fanlisting_settings LIMIT 1')->fetch();
        self::assertSame('1', (string) $row['for_adoption'],
            'for_adoption=1 in Fanatically should be written to lstr_fanlisting_settings.for_adoption');
    }

    public function testForAdoptionZeroWritesZeroToSettings(): void
    {
        $src = $this->makePdo();
        $tgt = $this->makePdo();
        $this->seedFanaticallySource($src);
        $this->seedTarget($tgt);

        $this->insertListing($src, 'https://example.com/fl/', forAdoption: 0);

        $opts = $this->makeOptions(['import_listings' => '1']);
        $this->adapter()->run($src, $tgt, $opts);

        $row = $tgt->query('SELECT for_adoption FROM lstr_fanlisting_settings LIMIT 1')->fetch();
        self::assertSame('0', (string) $row['for_adoption']);
    }

    public function testForAdoptionOverwriteClearsExistingOne(): void
    {
        $src = $this->makePdo();
        $tgt = $this->makePdo();
        $this->seedFanaticallySource($src);
        $this->seedTarget($tgt);

        // Source says for_adoption=0
        $this->insertListing($src, 'https://example.com/fl/', forAdoption: 0);

        // Pre-seed an existing settings row with for_adoption=1
        $tgt->exec(
            "INSERT INTO owned (dbtable, title, subject, url) VALUES ('existing_fl', 'T', 'S', 'https://example.com/fl')"
        );
        $existingId = (int) $tgt->lastInsertId();
        $tgt->exec(
            "INSERT INTO lstr_fanlisting_settings (fanlisting_id, for_adoption) VALUES ({$existingId}, 1)"
        );

        $opts = $this->makeOptions(['import_listings' => '1', 'conflict_strategy' => 'overwrite']);
        $this->adapter()->run($src, $tgt, $opts);

        $row = $tgt->query("SELECT for_adoption FROM lstr_fanlisting_settings WHERE fanlisting_id={$existingId}")->fetch();
        self::assertSame('0', (string) $row['for_adoption'],
            'Overwrite mode must clear for_adoption to 0 when source says 0');
    }

    // -----------------------------------------------------------------------
    // Listing description imported into lstr_fanlisting_settings.description
    // -----------------------------------------------------------------------

    public function testDescriptionImportedToSettings(): void
    {
        $src = $this->makePdo();
        $tgt = $this->makePdo();
        $this->seedFanaticallySource($src);
        $this->seedTarget($tgt);

        $this->insertListing($src, 'https://example.com/fl/', description: 'A fanlisting about test data.');

        $opts = $this->makeOptions(['import_listings' => '1']);
        $this->adapter()->run($src, $tgt, $opts);

        $row = $tgt->query('SELECT description FROM lstr_fanlisting_settings LIMIT 1')->fetch();
        self::assertSame('A fanlisting about test data.', $row['description'],
            'Fanatically listing description should be written to lstr_fanlisting_settings.description');
    }

    public function testBlankDescriptionDoesNotClearExistingOnOverwrite(): void
    {
        $src = $this->makePdo();
        $tgt = $this->makePdo();
        $this->seedFanaticallySource($src);
        $this->seedTarget($tgt);

        // Source has a blank description
        $this->insertListing($src, 'https://example.com/fl/', description: '');

        // Pre-seed an existing settings row with a description already set
        $tgt->exec(
            "INSERT INTO owned (dbtable, title, subject, url) VALUES ('existing_fl', 'T', 'S', 'https://example.com/fl')"
        );
        $existingId = (int) $tgt->lastInsertId();
        $tgt->exec(
            "INSERT INTO lstr_fanlisting_settings (fanlisting_id, description) VALUES ({$existingId}, 'Existing description')"
        );

        $opts = $this->makeOptions(['import_listings' => '1', 'conflict_strategy' => 'overwrite']);
        $this->adapter()->run($src, $tgt, $opts);

        $row = $tgt->query("SELECT description FROM lstr_fanlisting_settings WHERE fanlisting_id={$existingId}")->fetch();
        self::assertSame('Existing description', $row['description'],
            'A blank source description must not overwrite an existing Luster description');
    }

    public function testNonBlankDescriptionOverwritesExisting(): void
    {
        $src = $this->makePdo();
        $tgt = $this->makePdo();
        $this->seedFanaticallySource($src);
        $this->seedTarget($tgt);

        $this->insertListing($src, 'https://example.com/fl/', description: 'Updated description');

        $tgt->exec(
            "INSERT INTO owned (dbtable, title, subject, url) VALUES ('existing_fl', 'T', 'S', 'https://example.com/fl')"
        );
        $existingId = (int) $tgt->lastInsertId();
        $tgt->exec(
            "INSERT INTO lstr_fanlisting_settings (fanlisting_id, description) VALUES ({$existingId}, 'Old description')"
        );

        $opts = $this->makeOptions(['import_listings' => '1', 'conflict_strategy' => 'overwrite']);
        $this->adapter()->run($src, $tgt, $opts);

        $row = $tgt->query("SELECT description FROM lstr_fanlisting_settings WHERE fanlisting_id={$existingId}")->fetch();
        self::assertSame('Updated description', $row['description']);
    }

    // -----------------------------------------------------------------------
    // Joined listing — status mapping
    // -----------------------------------------------------------------------

    public function testJoinedCurrentStatusImportsAsActiveJoined(): void
    {
        $src = $this->makePdo();
        $tgt = $this->makePdo();
        $this->seedFanaticallySource($src);
        $this->seedTarget($tgt);

        $this->insertJoined($src, 'https://example.com/fl/', status: 'current');

        $opts = $this->makeOptions(['import_joined' => '1']);
        $this->adapter()->run($src, $tgt, $opts);

        $row = $tgt->query('SELECT active, status FROM lstr_joined LIMIT 1')->fetch();
        self::assertNotNull($row, 'Joined listing should be inserted');
        self::assertSame('1', (string) $row['active']);
        self::assertSame('joined', $row['status']);
    }

    public function testJoinedStatusValueImportsAsActiveJoined(): void
    {
        $src = $this->makePdo();
        $tgt = $this->makePdo();
        $this->seedFanaticallySource($src);
        $this->seedTarget($tgt);

        $this->insertJoined($src, 'https://example.com/fl/', status: 'joined');

        $opts = $this->makeOptions(['import_joined' => '1']);
        $this->adapter()->run($src, $tgt, $opts);

        $row = $tgt->query('SELECT active, status FROM lstr_joined LIMIT 1')->fetch();
        self::assertSame('1', (string) $row['active']);
        self::assertSame('joined', $row['status']);
    }

    public function testJoinedPendingStatusImportsAsInactive(): void
    {
        $src = $this->makePdo();
        $tgt = $this->makePdo();
        $this->seedFanaticallySource($src);
        $this->seedTarget($tgt);

        $this->insertJoined($src, 'https://example.com/fl/', status: 'pending');

        $opts = $this->makeOptions(['import_joined' => '1']);
        $this->adapter()->run($src, $tgt, $opts);

        $row = $tgt->query('SELECT active, status FROM lstr_joined LIMIT 1')->fetch();
        self::assertNotNull($row, 'Joined listing should be inserted even with pending status');
        self::assertSame('0', (string) $row['active']);
        self::assertSame('pending', $row['status']);
    }

    // -----------------------------------------------------------------------
    // Joined listing — multi-category categories_json
    // -----------------------------------------------------------------------

    public function testJoinedWithJunctionCategoriesBuildsJson(): void
    {
        $src = $this->makePdo();
        $tgt = $this->makePdo();
        $this->seedFanaticallySource($src);
        $this->seedTarget($tgt);

        // Create the junction table in source
        $src->exec(
            'CREATE TABLE joined_categories (
                id          INTEGER PRIMARY KEY AUTOINCREMENT,
                joined_id   INTEGER NOT NULL,
                category_id INTEGER NOT NULL
            )'
        );

        // Add source categories
        $src->exec("INSERT INTO categories (id, name) VALUES (1, 'Music'), (2, 'Anime')");

        // Add matching Luster categories
        $tgt->exec("INSERT INTO lstr_categories (id, name, source) VALUES (10, 'Music', 'tfl'), (11, 'Anime', 'custom')");

        // Add joined listing and wire up junction
        $jId = $this->insertJoined($src, 'https://example.com/fl/');
        $src->exec("INSERT INTO joined_categories (joined_id, category_id) VALUES ({$jId}, 1), ({$jId}, 2)");

        // The adapter needs categoryIdMap — we must also seed listings + run with importListings
        // but that requires `owned`. Easier: run importJoined=true + importCategories=true so
        // the adapter builds categoryIdMap internally from the source.
        $opts = $this->makeOptions([
            'import_joined'     => '1',
            'import_categories' => '1',
        ]);
        $this->adapter()->run($src, $tgt, $opts);

        $row = $tgt->query('SELECT categories_json FROM lstr_joined LIMIT 1')->fetch();
        self::assertNotNull($row, 'Joined listing should be inserted');

        $json = $row['categories_json'];
        self::assertNotNull($json, 'categories_json should be set for multi-category joined listing');

        $decoded = json_decode($json, true);
        self::assertIsArray($decoded);
        self::assertCount(2, $decoded);
        self::assertSame('Music', $decoded[0]['name']);
        self::assertSame('Anime', $decoded[1]['name']);
    }

    // -----------------------------------------------------------------------
    // Conflict strategy
    // -----------------------------------------------------------------------

    public function testSkipStrategyDoesNotOverwriteExistingListing(): void
    {
        $src = $this->makePdo();
        $tgt = $this->makePdo();
        $this->seedFanaticallySource($src);
        $this->seedTarget($tgt);

        $this->insertListing($src, 'https://example.com/fl/', approvedBy: 'tfl');

        // Pre-seed an owned listing at the same URL
        $tgt->exec("INSERT INTO owned (dbtable, title, subject, url) VALUES ('existing_fl', 'Old Title', 'Old Subject', 'https://example.com/fl')");

        $opts = $this->makeOptions(['import_listings' => '1', 'conflict_strategy' => 'skip']);
        $result = $this->adapter()->run($src, $tgt, $opts);

        // Title should NOT have changed
        $row = $tgt->query('SELECT title FROM owned WHERE url=\'https://example.com/fl\'')->fetch();
        self::assertSame('Old Title', $row['title'], 'Skip strategy should not overwrite existing listing title');
    }

    public function testOverwriteStrategyUpdatesExistingListing(): void
    {
        $src = $this->makePdo();
        $tgt = $this->makePdo();
        $this->seedFanaticallySource($src);
        $this->seedTarget($tgt);

        $src->exec("INSERT INTO listings (title, subject, url, status, approved_by, for_adoption) VALUES ('New Title', 'New Subject', 'https://example.com/fl', 'current', '', 0)");

        // Pre-seed an owned listing at the same URL
        $tgt->exec("INSERT INTO owned (dbtable, title, subject, url) VALUES ('existing_fl', 'Old Title', 'Old Subject', 'https://example.com/fl')");

        $opts = $this->makeOptions(['import_listings' => '1', 'conflict_strategy' => 'overwrite']);
        $this->adapter()->run($src, $tgt, $opts);

        $row = $tgt->query('SELECT title FROM owned WHERE url=\'https://example.com/fl\'')->fetch();
        self::assertSame('New Title', $row['title'], 'Overwrite strategy should update existing listing title');
    }

    // -----------------------------------------------------------------------
    // Date migration — opened_date and last_updated
    // -----------------------------------------------------------------------

    public function testOpenedDateImportedOnNewInsert(): void
    {
        $src = $this->makePdo();
        $tgt = $this->makePdo();
        $this->seedFanaticallySource($src);
        $this->seedTarget($tgt);

        $this->insertListing($src, 'https://example.com/fl/', openedDate: '2020-03-15');

        $opts = $this->makeOptions(['import_listings' => '1']);
        $this->adapter()->run($src, $tgt, $opts);

        $row = $tgt->query('SELECT opened FROM owned LIMIT 1')->fetch();
        self::assertNotNull($row, 'owned row should exist');
        self::assertSame('2020-03-15', $row['opened'], 'opened_date should be stored in owned.opened');
    }

    public function testOpenedDateImportedOnOverwriteUpdate(): void
    {
        $src = $this->makePdo();
        $tgt = $this->makePdo();
        $this->seedFanaticallySource($src);
        $this->seedTarget($tgt);

        $this->insertListing($src, 'https://example.com/fl/', openedDate: '2019-07-01');

        // Pre-seed an existing listing with no opened date
        $tgt->exec("INSERT INTO owned (dbtable, title, subject, url, opened) VALUES ('existing_fl', 'Old', 'Subj', 'https://example.com/fl', NULL)");

        $opts = $this->makeOptions(['import_listings' => '1', 'conflict_strategy' => 'overwrite']);
        $this->adapter()->run($src, $tgt, $opts);

        $row = $tgt->query('SELECT opened FROM owned LIMIT 1')->fetch();
        self::assertSame('2019-07-01', $row['opened'], 'Overwrite should update owned.opened from source opened_date');
    }

    public function testLastUpdatedImportedToSettingsOnNewInsert(): void
    {
        $src = $this->makePdo();
        $tgt = $this->makePdo();
        $this->seedFanaticallySource($src);
        $this->seedTarget($tgt);

        $this->insertListing($src, 'https://example.com/fl/', lastUpdated: '2023-11-20 14:00:00');

        $opts = $this->makeOptions(['import_listings' => '1']);
        $this->adapter()->run($src, $tgt, $opts);

        $row = $tgt->query('SELECT last_updated FROM lstr_fanlisting_settings LIMIT 1')->fetch();
        self::assertNotNull($row, 'Settings row should exist');
        // Only the date portion is stored in lstr_fanlisting_settings.last_updated (DATE column)
        self::assertStringStartsWith('2023-11-20', (string) $row['last_updated'],
            'last_updated should be stored in lstr_fanlisting_settings from Fanatically last_updated');
    }

    public function testSkipModeStillImportsLastUpdatedToSettings(): void
    {
        $src = $this->makePdo();
        $tgt = $this->makePdo();
        $this->seedFanaticallySource($src);
        $this->seedTarget($tgt);

        $this->insertListing($src, 'https://example.com/fl/', approvedBy: 'tfl', lastUpdated: '2022-06-10 09:00:00');

        // Pre-seed a listing at the same URL so conflict triggers
        $tgt->exec("INSERT INTO owned (dbtable, title, subject, url) VALUES ('existing_fl', 'Old', 'Subj', 'https://example.com/fl')");
        $existingId = (int) $tgt->lastInsertId();

        $opts = $this->makeOptions(['import_listings' => '1', 'conflict_strategy' => 'skip']);
        $this->adapter()->run($src, $tgt, $opts);

        $row = $tgt->query("SELECT last_updated, approved_by FROM lstr_fanlisting_settings WHERE fanlisting_id={$existingId}")->fetch();
        self::assertNotNull($row, 'Settings row should be created even for skipped listings');
        self::assertStringStartsWith('2022-06-10', (string) $row['last_updated'],
            'Skip mode should still import last_updated into lstr_fanlisting_settings');
        self::assertSame('tfl', $row['approved_by'],
            'Skip mode should still import approved_by into lstr_fanlisting_settings');
    }

    public function testSkipModeBackfillsOpenedWhenTargetHasNone(): void
    {
        $src = $this->makePdo();
        $tgt = $this->makePdo();
        $this->seedFanaticallySource($src);
        $this->seedTarget($tgt);

        $this->insertListing($src, 'https://example.com/fl/', openedDate: '2018-01-01');

        // Pre-seed an existing listing with no opened date
        $tgt->exec("INSERT INTO owned (dbtable, title, subject, url, opened) VALUES ('existing_fl', 'Old', 'Subj', 'https://example.com/fl', NULL)");

        $opts = $this->makeOptions(['import_listings' => '1', 'conflict_strategy' => 'skip']);
        $this->adapter()->run($src, $tgt, $opts);

        $row = $tgt->query('SELECT opened FROM owned LIMIT 1')->fetch();
        self::assertSame('2018-01-01', $row['opened'],
            'Skip mode should backfill opened_date into owned.opened when target row has none');
    }

    public function testSkipModeDoesNotOverwriteExistingOpenedDate(): void
    {
        $src = $this->makePdo();
        $tgt = $this->makePdo();
        $this->seedFanaticallySource($src);
        $this->seedTarget($tgt);

        $this->insertListing($src, 'https://example.com/fl/', openedDate: '2020-05-05');

        // Pre-seed an existing listing that already has an opened date
        $tgt->exec("INSERT INTO owned (dbtable, title, subject, url, opened) VALUES ('existing_fl', 'Old', 'Subj', 'https://example.com/fl', '2015-12-25')");

        $opts = $this->makeOptions(['import_listings' => '1', 'conflict_strategy' => 'skip']);
        $this->adapter()->run($src, $tgt, $opts);

        $row = $tgt->query('SELECT opened FROM owned LIMIT 1')->fetch();
        self::assertSame('2015-12-25', $row['opened'],
            'Skip mode should not overwrite an existing opened date');
    }

    public function testStatusCurrentMapsToStatusTwo(): void
    {
        $src = $this->makePdo();
        $tgt = $this->makePdo();
        $this->seedFanaticallySource($src);
        $this->seedTarget($tgt);

        $this->insertListing($src, 'https://example.com/fl/', status: 'current');

        $opts = $this->makeOptions(['import_listings' => '1']);
        $this->adapter()->run($src, $tgt, $opts);

        $row = $tgt->query('SELECT status FROM owned LIMIT 1')->fetch();
        self::assertSame('2', (string) $row['status'], 'current status should map to owned.status=2 (active)');
    }

    public function testStatusUpcomingMapsToStatusOne(): void
    {
        $src = $this->makePdo();
        $tgt = $this->makePdo();
        $this->seedFanaticallySource($src);
        $this->seedTarget($tgt);

        $this->insertListing($src, 'https://example.com/fl/', status: 'upcoming');

        $opts = $this->makeOptions(['import_listings' => '1']);
        $this->adapter()->run($src, $tgt, $opts);

        $row = $tgt->query('SELECT status FROM owned LIMIT 1')->fetch();
        self::assertSame('1', (string) $row['status'], 'upcoming status should map to owned.status=1');
    }

    public function testStatusPendingMapsToStatusZero(): void
    {
        $src = $this->makePdo();
        $tgt = $this->makePdo();
        $this->seedFanaticallySource($src);
        $this->seedTarget($tgt);

        $this->insertListing($src, 'https://example.com/fl/', status: 'pending');

        $opts = $this->makeOptions(['import_listings' => '1']);
        $this->adapter()->run($src, $tgt, $opts);

        $row = $tgt->query('SELECT status FROM owned LIMIT 1')->fetch();
        self::assertSame('0', (string) $row['status'], 'pending status should map to owned.status=0 (trouble)');
    }

    public function testStatusClosedMapsToInactiveNotCurrent(): void
    {
        $src = $this->makePdo();
        $tgt = $this->makePdo();
        $this->seedFanaticallySource($src);
        $this->seedTarget($tgt);

        $this->insertListing($src, 'https://example.com/fl/', status: 'closed');

        $opts = $this->makeOptions(['import_listings' => '1']);
        $this->adapter()->run($src, $tgt, $opts);

        $row = $tgt->query('SELECT status FROM owned LIMIT 1')->fetch();
        self::assertSame('0', (string) $row['status'], 'closed status should map to owned.status=0 (inactive)');
    }

    public function testStatusAdoptedOutMapsToInactiveNotCurrent(): void
    {
        $src = $this->makePdo();
        $tgt = $this->makePdo();
        $this->seedFanaticallySource($src);
        $this->seedTarget($tgt);

        $this->insertListing($src, 'https://example.com/fl/', status: 'adopted_out');

        $opts = $this->makeOptions(['import_listings' => '1']);
        $this->adapter()->run($src, $tgt, $opts);

        $row = $tgt->query('SELECT status FROM owned LIMIT 1')->fetch();
        self::assertSame('0', (string) $row['status'], 'adopted_out status should map to owned.status=0 (inactive)');
    }

    public function testUnrecognizedFutureStatusFailsSafeToInactive(): void
    {
        $src = $this->makePdo();
        $tgt = $this->makePdo();
        $this->seedFanaticallySource($src);
        $this->seedTarget($tgt);

        $this->insertListing($src, 'https://example.com/fl/', status: 'suspended');

        $opts = $this->makeOptions(['import_listings' => '1']);
        $this->adapter()->run($src, $tgt, $opts);

        $row = $tgt->query('SELECT status FROM owned LIMIT 1')->fetch();
        self::assertSame('0', (string) $row['status'], 'unknown status should fail safe to owned.status=0 (inactive)');
    }

    public function testClosedStatusPreservedInExternalRefMetadata(): void
    {
        $src = $this->makePdo();
        $tgt = $this->makePdo();
        $this->seedFanaticallySource($src);
        $this->seedTarget($tgt);

        $this->insertListing($src, 'https://example.com/fl/', status: 'closed');

        $opts = $this->makeOptions(['import_listings' => '1']);
        $this->adapter()->run($src, $tgt, $opts);

        $row = $tgt->query("SELECT metadata_json FROM lstr_listing_external_refs WHERE source='fanatically'")->fetch();
        self::assertNotFalse($row, 'External ref row must exist');
        $meta = json_decode((string) ($row['metadata_json'] ?? ''), true);
        self::assertSame('closed', $meta['source_status'] ?? null, 'closed source_status must be preserved in metadata_json');
    }

    public function testCurrentStatusDoesNotWriteSourceStatusMetadata(): void
    {
        $src = $this->makePdo();
        $tgt = $this->makePdo();
        $this->seedFanaticallySource($src);
        $this->seedTarget($tgt);

        $this->insertListing($src, 'https://example.com/fl/', status: 'current');

        $opts = $this->makeOptions(['import_listings' => '1']);
        $this->adapter()->run($src, $tgt, $opts);

        $row = $tgt->query("SELECT metadata_json FROM lstr_listing_external_refs WHERE source='fanatically'")->fetch();
        self::assertNotFalse($row, 'External ref row must exist');
        self::assertNull($row['metadata_json'], 'metadata_json should be NULL for current status (no lossless mapping needed)');
    }

    public function testBlankUrlListingIsImportedNotSkipped(): void
    {
        $src = $this->makePdo();
        $tgt = $this->makePdo();
        $this->seedFanaticallySource($src);
        $this->seedTarget($tgt);

        $this->insertListing($src, '', status: 'current');

        $opts = $this->makeOptions(['import_listings' => '1']);
        $this->adapter()->run($src, $tgt, $opts);

        $count = (int) $tgt->query('SELECT COUNT(*) FROM owned')->fetchColumn();
        self::assertSame(1, $count, 'Blank-URL listing should be imported, not skipped');
    }

    public function testBlankUrlListingImportedAsInactiveEvenWhenStatusCurrent(): void
    {
        $src = $this->makePdo();
        $tgt = $this->makePdo();
        $this->seedFanaticallySource($src);
        $this->seedTarget($tgt);

        $this->insertListing($src, '', status: 'current');

        $opts = $this->makeOptions(['import_listings' => '1']);
        $this->adapter()->run($src, $tgt, $opts);

        $row = $tgt->query('SELECT status FROM owned LIMIT 1')->fetch();
        self::assertSame('0', (string) $row['status'], 'Blank-URL listing must be inactive regardless of source status');
    }

    public function testBlankUrlListingPreservesOriginalStatusAsMetadata(): void
    {
        $src = $this->makePdo();
        $tgt = $this->makePdo();
        $this->seedFanaticallySource($src);
        $this->seedTarget($tgt);

        $this->insertListing($src, '', status: 'current');

        $opts = $this->makeOptions(['import_listings' => '1']);
        $this->adapter()->run($src, $tgt, $opts);

        $row = $tgt->query("SELECT metadata_json FROM lstr_listing_external_refs WHERE source='fanatically'")->fetch();
        self::assertNotFalse($row, 'External ref row must exist for blank-URL listing');
        $meta = json_decode((string) ($row['metadata_json'] ?? ''), true);
        self::assertSame('current', $meta['source_status'] ?? null, 'Original source status must be in metadata even for blank-URL listings');
    }

    public function testMultipleBlankUrlListingsDoNotCollideWithEachOther(): void
    {
        $src = $this->makePdo();
        $tgt = $this->makePdo();
        $this->seedFanaticallySource($src);
        $this->seedTarget($tgt);

        $src->exec("INSERT INTO listings (id, title, subject, url, status, approved_by, for_adoption) VALUES (1, 'FL One', 'One', '', 'current', '', 0)");
        $src->exec("INSERT INTO listings (id, title, subject, url, status, approved_by, for_adoption) VALUES (2, 'FL Two', 'Two', '', 'current', '', 0)");

        $opts = $this->makeOptions(['import_listings' => '1']);
        $this->adapter()->run($src, $tgt, $opts);

        $count = (int) $tgt->query('SELECT COUNT(*) FROM owned')->fetchColumn();
        self::assertSame(2, $count, 'Each blank-URL listing must produce a separate owned row');
    }

    public function testBlankUrlListingMatchedByExternalIdOnReimport(): void
    {
        $src = $this->makePdo();
        $tgt = $this->makePdo();
        $this->seedFanaticallySource($src);
        $this->seedTarget($tgt);

        // First import — blank-URL listing gets its own owned row
        $this->insertListing($src, '', status: 'current');
        $opts = $this->makeOptions(['import_listings' => '1']);
        $this->adapter()->run($src, $tgt, $opts);

        $firstCount = (int) $tgt->query('SELECT COUNT(*) FROM owned')->fetchColumn();
        self::assertSame(1, $firstCount, 'First import should create one row');

        // Second import (skip strategy) — must re-use the existing row, not create a duplicate
        $this->adapter()->run($src, $tgt, $opts);

        $secondCount = (int) $tgt->query('SELECT COUNT(*) FROM owned')->fetchColumn();
        self::assertSame(1, $secondCount, 'Re-import must match blank-URL listing by external_id and not create a duplicate');
    }

    public function testBlankUrlListingMembersStillImport(): void
    {
        $src = $this->makePdo();
        $tgt = $this->makePdo();
        $this->seedFanaticallySource($src);
        $this->seedTarget($tgt);

        $listingId = $this->insertListing($src, '', status: 'current');

        $src->exec(
            "CREATE TABLE IF NOT EXISTS members (
                id                  INTEGER PRIMARY KEY AUTOINCREMENT,
                listing_id          INTEGER NOT NULL,
                name                TEXT    NOT NULL DEFAULT '',
                email               TEXT    NOT NULL DEFAULT '',
                url                 TEXT    NOT NULL DEFAULT '',
                country             TEXT    NOT NULL DEFAULT '',
                password_hash       TEXT    NOT NULL DEFAULT '',
                custom_field_values TEXT    NULL,
                is_approved         INTEGER NOT NULL DEFAULT 1,
                joined_at           TEXT    NULL
            )"
        );
        $src->prepare(
            "INSERT INTO members (listing_id, name, email, url, country, password_hash, is_approved)
             VALUES (:lid, 'Fan One', 'fan@example.com', 'https://fan.example.com', 'AU', '', 1)"
        )->execute(['lid' => $listingId]);

        $opts = $this->makeOptions(['import_listings' => '1', 'import_members' => '1']);
        $this->adapter()->run($src, $tgt, $opts);

        $ownedRow = $tgt->query('SELECT listingid, dbtable FROM owned LIMIT 1')->fetch();
        self::assertNotFalse($ownedRow, 'Blank-URL listing must still produce an owned row');
        self::assertNotSame('', $ownedRow['dbtable'], 'Blank-URL listing must still get a member dbtable');

        $memberRow = $tgt->query("SELECT email FROM `{$ownedRow['dbtable']}`")->fetch();
        self::assertNotFalse($memberRow, 'Member tied to a blank-URL listing must still be imported, not dropped via an empty listingIdMap entry');
        self::assertSame('fan@example.com', $memberRow['email']);
    }

    public function testBlankUrlListingAffiliatesStillImport(): void
    {
        $src = $this->makePdo();
        $tgt = $this->makePdo();
        $this->seedFanaticallySource($src);
        $this->seedTarget($tgt);

        $listingId = $this->insertListing($src, '', status: 'current');

        $src->exec(
            "CREATE TABLE IF NOT EXISTS affiliates (
                id           INTEGER PRIMARY KEY AUTOINCREMENT,
                name         TEXT    NOT NULL DEFAULT '',
                url          TEXT    NOT NULL DEFAULT '',
                image_file   TEXT    NOT NULL DEFAULT '',
                listing_ids  TEXT    NOT NULL DEFAULT '[]',
                is_approved  INTEGER NOT NULL DEFAULT 1,
                link_status  TEXT    NOT NULL DEFAULT 'unchecked'
            )"
        );
        $src->prepare(
            "INSERT INTO affiliates (name, url, listing_ids, is_approved)
             VALUES ('Aff One', 'https://aff.example.com', :lids, 1)"
        )->execute(['lids' => json_encode([$listingId])]);

        $opts = $this->makeOptions(['import_listings' => '1', 'import_affiliates' => '1']);
        $this->adapter()->run($src, $tgt, $opts);

        $count = (int) $tgt->query('SELECT COUNT(*) FROM lstr_affiliates WHERE fanlisting_id IS NOT NULL')->fetchColumn();
        self::assertSame(1, $count, 'Affiliate tied to a blank-URL listing must still be imported with its fanlisting_id link, not dropped');
    }

    // -----------------------------------------------------------------------
    // Existing-listing overwrite
    // -----------------------------------------------------------------------

    public function testOverwriteModeReusesExistingDbtableForMembers(): void
    {
        $src = $this->makePdo();
        $tgt = $this->makePdo();
        $this->seedFanaticallySource($src);
        $this->seedTarget($tgt);

        // Source: one listing with one member
        $src->exec(
            "INSERT INTO listings (id, title, subject, url, status, approved_by, for_adoption)
             VALUES (1, 'FL', 'FL Subject', 'https://example.com/fl', 'current', '', 0)"
        );
        $src->exec(
            "CREATE TABLE IF NOT EXISTS members (
                id                  INTEGER PRIMARY KEY AUTOINCREMENT,
                listing_id          INTEGER NOT NULL,
                name                TEXT    NOT NULL DEFAULT '',
                email               TEXT    NOT NULL DEFAULT '',
                url                 TEXT    NOT NULL DEFAULT '',
                country             TEXT    NOT NULL DEFAULT '',
                password_hash       TEXT    NOT NULL DEFAULT '',
                custom_field_values TEXT    NULL,
                is_approved         INTEGER NOT NULL DEFAULT 1,
                joined_at           TEXT    NULL
            )"
        );
        $src->exec(
            "INSERT INTO members (listing_id, name, email, url, country, password_hash, is_approved)
             VALUES (1, 'Fan One', 'fan@example.com', 'https://fan.example.com', 'AU', '', 1)"
        );

        // Target: existing listing at the same URL with a specific dbtable
        $tgt->exec(
            "INSERT INTO owned (dbtable, title, subject, url) VALUES ('my_existing_table', 'Old FL', 'Old Subject', 'https://example.com/fl')"
        );
        $existingId = (int) $tgt->lastInsertId();
        $tgt->exec(
            'CREATE TABLE IF NOT EXISTS my_existing_table (
                email   TEXT NOT NULL PRIMARY KEY,
                name    TEXT NOT NULL DEFAULT \'\',
                country TEXT NOT NULL DEFAULT \'\',
                url     TEXT NOT NULL DEFAULT \'\',
                pending INTEGER NOT NULL DEFAULT 0,
                password TEXT NOT NULL DEFAULT \'\',
                showemail INTEGER NOT NULL DEFAULT 1,
                showurl  INTEGER NOT NULL DEFAULT 1,
                added   TEXT NULL
            )'
        );

        $opts = $this->makeOptions([
            'import_listings' => '1',
            'import_members'  => '1',
            'conflict_strategy' => 'overwrite',
        ]);
        $this->adapter()->run($src, $tgt, $opts);

        // Member must land in my_existing_table, not a freshly generated table
        $row = $tgt->query("SELECT email FROM my_existing_table")->fetch();
        self::assertNotFalse($row, 'Member should be imported into the existing dbtable (my_existing_table)');
        self::assertSame('fan@example.com', $row['email']);

        // dbtable in owned must still be the original slug
        $ownedRow = $tgt->query("SELECT dbtable FROM owned WHERE listingid={$existingId}")->fetch();
        self::assertSame('my_existing_table', $ownedRow['dbtable'],
            'overwrite should not change the existing dbtable slug');
    }

    // -----------------------------------------------------------------------
    // Preview — dependent scope warnings
    // -----------------------------------------------------------------------

    public function testPreviewWarnsMembersSelectedWithoutListings(): void
    {
        $src = $this->makePdo();
        $tgt = $this->makePdo();
        $this->seedFanaticallySource($src);
        $this->seedTarget($tgt);

        $opts = $this->makeOptions([
            'import_members'  => '1',
            'import_listings' => '0',
        ]);
        $adapter = $this->adapter();
        $preview = $adapter->preview($src, $tgt, $opts);

        $allWarnings = [];
        foreach ($preview->rows as $row) {
            foreach ($row['warnings'] ?? [] as $w) {
                $allWarnings[] = $w;
            }
        }
        $joined = implode(' ', $allWarnings);
        self::assertStringContainsString('Members', $joined,
            'Preview should warn that Members selected without Listings will produce empty results');
        self::assertStringContainsString('Listings', $joined);
    }

    public function testPreviewWarnsAboutClosedAdoptedOutAndBlankUrlCounts(): void
    {
        $src = $this->makePdo();
        $tgt = $this->makePdo();
        $this->seedFanaticallySource($src);
        $this->seedTarget($tgt);

        $src->exec("INSERT INTO listings (id, title, subject, url, status, approved_by, for_adoption) VALUES (1, 'FL One',   'One', 'https://one.example.com/', 'current',      '', 0)");
        $src->exec("INSERT INTO listings (id, title, subject, url, status, approved_by, for_adoption) VALUES (2, 'FL Two',   'Two', 'https://two.example.com/', 'closed',       '', 0)");
        $src->exec("INSERT INTO listings (id, title, subject, url, status, approved_by, for_adoption) VALUES (3, 'FL Three', 'Thr', 'https://thr.example.com/', 'adopted_out',  '', 0)");
        $src->exec("INSERT INTO listings (id, title, subject, url, status, approved_by, for_adoption) VALUES (4, 'FL Four',  'Fou', '',                         'current',      '', 0)");

        $opts    = $this->makeOptions(['import_listings' => '1']);
        $preview = $this->adapter()->preview($src, $tgt, $opts);

        $listRow = null;
        foreach ($preview->rows as $row) {
            if ($row['type'] === 'Owned Listings') {
                $listRow = $row;
                break;
            }
        }
        self::assertNotNull($listRow, 'Preview must include an Owned Listings row');

        $warns = implode(' ', $listRow['warnings'] ?? []);
        self::assertStringContainsString('1 listing(s) have no URL', $warns, 'Preview must warn about blank-URL listings');
        self::assertStringContainsString('1 closed listing(s)',       $warns, 'Preview must warn about closed listings');
        self::assertStringContainsString('1 adopted-out listing(s)',  $warns, 'Preview must warn about adopted-out listings');
    }

    public function testPreviewWarnsUpcomingJoinedStatus(): void
    {
        $src = $this->makePdo();
        $tgt = $this->makePdo();
        $this->seedFanaticallySource($src);
        $this->seedTarget($tgt);

        // Add joined table and one upcoming row
        $src->exec(
            'CREATE TABLE IF NOT EXISTS joined (
                id        INTEGER PRIMARY KEY AUTOINCREMENT,
                subject   TEXT NOT NULL DEFAULT \'\',
                url       TEXT NOT NULL DEFAULT \'\',
                status    TEXT NOT NULL DEFAULT \'current\',
                image_file TEXT NOT NULL DEFAULT \'\',
                added_at  TEXT NULL
            )'
        );
        $src->exec("INSERT INTO joined (subject, url, status) VALUES ('Test', 'https://example.com/j/', 'upcoming')");

        $opts = $this->makeOptions(['import_joined' => '1']);
        $adapter = $this->adapter();
        $preview = $adapter->preview($src, $tgt, $opts);

        $allWarnings = [];
        foreach ($preview->rows as $row) {
            foreach ($row['warnings'] ?? [] as $w) {
                $allWarnings[] = $w;
            }
        }
        $joined = implode(' ', $allWarnings);
        self::assertStringContainsString("upcoming", $joined,
            'Preview should warn that upcoming joined listings will be degraded');
    }

    // -----------------------------------------------------------------------
    // Code dedup by (fanlisting_id, name, size_id)
    // -----------------------------------------------------------------------

    public function testSameFilenameDifferentSizeLabelProducesTwoCodeRows(): void
    {
        $src = $this->makePdo();
        $tgt = $this->makePdo();
        $this->seedFanaticallySource($src);
        $this->seedTarget($tgt);

        // Source listing
        $this->insertListing($src, 'https://example.com/fl/');

        // Source codes table with two rows: same filename, different size_label
        $src->exec(
            'CREATE TABLE IF NOT EXISTS codes (
                id          INTEGER PRIMARY KEY AUTOINCREMENT,
                listing_id  INTEGER NOT NULL,
                file_name   TEXT    NOT NULL DEFAULT \'\',
                size_label  TEXT    NOT NULL DEFAULT \'\',
                donor_name  TEXT    NOT NULL DEFAULT \'\',
                donor_url   TEXT    NOT NULL DEFAULT \'\',
                donor_email TEXT    NOT NULL DEFAULT \'\',
                is_approved INTEGER NOT NULL DEFAULT 1
            )'
        );
        $src->exec("INSERT INTO codes (listing_id, file_name, size_label) VALUES (1, 'banner.gif', '50x50')");
        $src->exec("INSERT INTO codes (listing_id, file_name, size_label) VALUES (1, 'banner.gif', '100x35')");

        $opts = $this->makeOptions(['import_listings' => '1', 'import_codes' => '1']);
        $this->adapter()->run($src, $tgt, $opts);

        $count = (int) $tgt->query('SELECT COUNT(*) FROM lstr_codes')->fetchColumn();
        self::assertSame(2, $count,
            'Same filename under two different size labels must produce two distinct lstr_codes rows');
    }

    public function testSameFilenameAndSizeLabelDedupsToOneRow(): void
    {
        $src = $this->makePdo();
        $tgt = $this->makePdo();
        $this->seedFanaticallySource($src);
        $this->seedTarget($tgt);

        $this->insertListing($src, 'https://example.com/fl/');

        $src->exec(
            'CREATE TABLE IF NOT EXISTS codes (
                id          INTEGER PRIMARY KEY AUTOINCREMENT,
                listing_id  INTEGER NOT NULL,
                file_name   TEXT    NOT NULL DEFAULT \'\',
                size_label  TEXT    NOT NULL DEFAULT \'\',
                donor_name  TEXT    NOT NULL DEFAULT \'\',
                donor_url   TEXT    NOT NULL DEFAULT \'\',
                donor_email TEXT    NOT NULL DEFAULT \'\',
                is_approved INTEGER NOT NULL DEFAULT 1
            )'
        );
        $src->exec("INSERT INTO codes (listing_id, file_name, size_label) VALUES (1, 'banner.gif', '50x50')");

        // Run twice — second run should skip (conflict_strategy=skip)
        $opts = $this->makeOptions(['import_listings' => '1', 'import_codes' => '1']);
        $this->adapter()->run($src, $tgt, $opts);
        $this->adapter()->run($src, $tgt, $opts);

        $count = (int) $tgt->query('SELECT COUNT(*) FROM lstr_codes')->fetchColumn();
        self::assertSame(1, $count, 'Re-running the same code with same size should not insert a duplicate');
    }

    // -----------------------------------------------------------------------
    // Duplicate category names get suffix
    // -----------------------------------------------------------------------

    public function testDuplicateCategoryNamesGetNumericSuffix(): void
    {
        $src = $this->makePdo();
        $tgt = $this->makePdo();
        $this->seedFanaticallySource($src);
        $this->seedTarget($tgt);

        $src->exec("INSERT INTO categories (id, name, parent_id) VALUES (1, 'Music', 0)");
        $src->exec("INSERT INTO categories (id, name, parent_id) VALUES (2, 'Music', 0)");

        $opts = $this->makeOptions(['import_categories' => '1']);
        $this->adapter()->run($src, $tgt, $opts);

        $rows = $tgt->query('SELECT name FROM lstr_categories ORDER BY id ASC')->fetchAll(PDO::FETCH_COLUMN);
        self::assertCount(2, $rows, 'Both Music categories should be imported as separate rows');
        self::assertSame('Music', $rows[0]);
        self::assertSame('Music (2)', $rows[1]);
    }

    public function testThreeDuplicateCategoryNamesSuffixedCorrectly(): void
    {
        $src = $this->makePdo();
        $tgt = $this->makePdo();
        $this->seedFanaticallySource($src);
        $this->seedTarget($tgt);

        $src->exec("INSERT INTO categories (id, name, parent_id) VALUES (1, 'Anime', 0)");
        $src->exec("INSERT INTO categories (id, name, parent_id) VALUES (2, 'Anime', 0)");
        $src->exec("INSERT INTO categories (id, name, parent_id) VALUES (3, 'Anime', 0)");

        $opts = $this->makeOptions(['import_categories' => '1']);
        $this->adapter()->run($src, $tgt, $opts);

        $rows = $tgt->query('SELECT name FROM lstr_categories ORDER BY id ASC')->fetchAll(PDO::FETCH_COLUMN);
        self::assertCount(3, $rows);
        self::assertSame('Anime',    $rows[0]);
        self::assertSame('Anime (2)', $rows[1]);
        self::assertSame('Anime (3)', $rows[2]);
    }

    // -----------------------------------------------------------------------
    // Custom field definition persistence to owned.additional
    // -----------------------------------------------------------------------

    public function testCustomFieldsPersistedToOwnedAdditional(): void
    {
        $src = $this->makePdo();
        $tgt = $this->makePdo();
        $this->seedFanaticallySource($src);
        $this->seedTarget($tgt);

        // Listing with custom_fields JSON
        $src->exec(
            'ALTER TABLE listings ADD COLUMN custom_fields TEXT NULL'
        );
        $customFields = json_encode([['name' => 'fave_song', 'label' => 'Fave Song', 'type' => 'text', 'required' => false]]);
        $src->exec(
            "INSERT INTO listings (id, title, subject, url, status, approved_by, for_adoption, custom_fields)
             VALUES (1, 'FL', 'Subj', 'https://example.com/fl', 'current', '', 0, '{$customFields}')"
        );

        $src->exec(
            'CREATE TABLE IF NOT EXISTS members (
                id                  INTEGER PRIMARY KEY AUTOINCREMENT,
                listing_id          INTEGER NOT NULL,
                name                TEXT    NOT NULL DEFAULT \'\',
                email               TEXT    NOT NULL DEFAULT \'\',
                url                 TEXT    NOT NULL DEFAULT \'\',
                country             TEXT    NOT NULL DEFAULT \'\',
                password_hash       TEXT    NOT NULL DEFAULT \'\',
                custom_field_values TEXT    NULL,
                is_approved         INTEGER NOT NULL DEFAULT 1,
                joined_at           TEXT    NULL
            )'
        );
        $src->exec(
            "INSERT INTO members (listing_id, name, email, is_approved)
             VALUES (1, 'Fan', 'fan@example.com', 1)"
        );

        $opts = $this->makeOptions(['import_listings' => '1', 'import_members' => '1']);
        $this->adapter()->run($src, $tgt, $opts);

        $row = $tgt->query('SELECT additional FROM owned LIMIT 1')->fetch();
        self::assertNotNull($row, 'owned row should exist');
        self::assertStringContainsString('fave_song', (string) $row['additional'],
            'owned.additional should contain the imported custom field name');
    }

    /**
     * Fanatically's own custom_fields schema has no explicit "type" — a field is
     * a dropdown purely by having a non-empty options list (same convention its
     * own join-form renderer uses). The importer used to collapse every field to
     * a bare name, discarding label/options/required entirely — every imported
     * field silently became an untyped text input even when the source had a
     * real, admin-configured dropdown with real choices.
     */
    public function testCustomFieldWithOptionsPersistedAsDropdownNotText(): void
    {
        $src = $this->makePdo();
        $tgt = $this->makePdo();
        $this->seedFanaticallySource($src);
        $this->seedTarget($tgt);

        $src->exec('ALTER TABLE listings ADD COLUMN custom_fields TEXT NULL');
        $customFields = json_encode([[
            'name'     => 'favorite_movie',
            'label'    => 'Favorite Movie',
            'required' => true,
            'options'  => ['John Wick', 'John Wick: Chapter 2', 'All of them'],
        ]]);
        $src->exec(
            "INSERT INTO listings (id, title, subject, url, status, approved_by, for_adoption, custom_fields)
             VALUES (1, 'FL', 'Subj', 'https://example.com/fl', 'current', '', 0, '{$customFields}')"
        );

        $src->exec(
            'CREATE TABLE IF NOT EXISTS members (
                id                  INTEGER PRIMARY KEY AUTOINCREMENT,
                listing_id          INTEGER NOT NULL,
                name                TEXT    NOT NULL DEFAULT \'\',
                email               TEXT    NOT NULL DEFAULT \'\',
                url                 TEXT    NOT NULL DEFAULT \'\',
                country             TEXT    NOT NULL DEFAULT \'\',
                password_hash       TEXT    NOT NULL DEFAULT \'\',
                custom_field_values TEXT    NULL,
                is_approved         INTEGER NOT NULL DEFAULT 1,
                joined_at           TEXT    NULL
            )'
        );
        $src->exec(
            "INSERT INTO members (listing_id, name, email, is_approved)
             VALUES (1, 'Fan', 'fan@example.com', 1)"
        );

        $opts = $this->makeOptions(['import_listings' => '1', 'import_members' => '1']);
        $this->adapter()->run($src, $tgt, $opts);

        $row = $tgt->query('SELECT additional FROM owned LIMIT 1')->fetch();
        self::assertNotNull($row, 'owned row should exist');

        $decoded = @unserialize((string) $row['additional'], ['allowed_classes' => false]);
        self::assertIsArray($decoded, 'owned.additional should hold a serialized array, not a bare name string.');
        self::assertSame('favorite_movie', $decoded[0]['name']);
        self::assertSame('Favorite Movie', $decoded[0]['label']);
        self::assertSame('dropdown', $decoded[0]['type'], 'A field with real options must import as a dropdown, not text.');
        self::assertTrue($decoded[0]['required']);
        self::assertSame('John Wick, John Wick: Chapter 2, All of them', $decoded[0]['options']);

        // Round-trip through the actual public-form rendering path — proves the
        // three choices really render as three distinct <option>s, not one
        // pipe-joined blob (FormFieldBuilder/FieldRenderer split options on
        // commas, not pipes).
        $field = new AdditionalField(
            name:     $decoded[0]['name'],
            label:    $decoded[0]['label'],
            type:     $decoded[0]['type'],
            required: $decoded[0]['required'],
            options:  $decoded[0]['options'],
        );
        $fb = new FormFieldBuilder(new FieldRenderer(), new FieldWrapper());
        $descriptors = $fb->descriptorsFromAdditionalFields([$field], []);
        // Dropdown descriptors get an extra leading blank '' => '' placeholder
        // option (the "choose one" row), so 3 real choices means 4 total entries.
        self::assertCount(4, $descriptors[0]['options'] ?? [],
            'Rendered dropdown must have the blank placeholder plus 3 distinct options, not one pipe-joined string.');
        self::assertArrayHasKey('John Wick', $descriptors[0]['options']);
        self::assertArrayHasKey('John Wick: Chapter 2', $descriptors[0]['options']);
        self::assertArrayHasKey('All of them', $descriptors[0]['options']);
    }

    // -----------------------------------------------------------------------
    // Affiliate link/reciprocal status mapping
    // -----------------------------------------------------------------------

    /** Seeds the source affiliates table. */
    private function seedSourceAffiliates(PDO $pdo): void
    {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS affiliates (
                id                    INTEGER PRIMARY KEY AUTOINCREMENT,
                subject               TEXT    NOT NULL DEFAULT '',
                email                 TEXT    NOT NULL DEFAULT '',
                url                   TEXT    NOT NULL DEFAULT '',
                image_file            TEXT    NOT NULL DEFAULT '',
                added_at              TEXT    NULL,
                is_approved           INTEGER NOT NULL DEFAULT 1,
                listing_ids           TEXT    NOT NULL DEFAULT '[]',
                link_status           TEXT    NOT NULL DEFAULT 'unchecked',
                link_checked_at       TEXT    NULL,
                link_redirect_url     TEXT    NOT NULL DEFAULT '',
                reciprocal_status     TEXT    NOT NULL DEFAULT 'unchecked',
                reciprocal_checked_at TEXT    NULL
            )"
        );
    }

    public function testAffiliateLinkStatusMovedMapsToRedirect(): void
    {
        $src = $this->makePdo();
        $tgt = $this->makePdo();
        $this->seedFanaticallySource($src);
        $this->seedTarget($tgt);
        $this->seedSourceAffiliates($src);

        $src->exec(
            "INSERT INTO affiliates (subject, url, listing_ids, link_status, link_redirect_url)
             VALUES ('Test Aff', 'https://aff.example.com', '[]', 'moved', 'https://aff-new.example.com')"
        );

        $opts = $this->makeOptions(['import_affiliates' => '1']);
        $this->adapter()->run($src, $tgt, $opts);

        $row = $tgt->query('SELECT link_status, link_redirect_url FROM lstr_affiliates LIMIT 1')->fetch();
        self::assertNotFalse($row, 'Affiliate should be imported');
        self::assertSame('redirect', $row['link_status'],
            "Fanatically 'moved' should map to Luster 'redirect'");
        self::assertSame('https://aff-new.example.com', $row['link_redirect_url']);
    }

    public function testAffiliateReciprocalStatusOkMapsToFound(): void
    {
        $src = $this->makePdo();
        $tgt = $this->makePdo();
        $this->seedFanaticallySource($src);
        $this->seedTarget($tgt);
        $this->seedSourceAffiliates($src);

        $src->exec(
            "INSERT INTO affiliates (subject, url, listing_ids, reciprocal_status)
             VALUES ('Test Aff', 'https://aff.example.com', '[]', 'ok')"
        );

        $opts = $this->makeOptions(['import_affiliates' => '1']);
        $this->adapter()->run($src, $tgt, $opts);

        $row = $tgt->query('SELECT reciprocal_status FROM lstr_affiliates LIMIT 1')->fetch();
        self::assertNotFalse($row, 'Affiliate should be imported');
        self::assertSame('found', $row['reciprocal_status'],
            "Fanatically reciprocal 'ok' should map to Luster 'found'");
    }

    public function testAffiliateLinkStatusPassThroughForOkAndDead(): void
    {
        $src = $this->makePdo();
        $tgt = $this->makePdo();
        $this->seedFanaticallySource($src);
        $this->seedTarget($tgt);
        $this->seedSourceAffiliates($src);

        $src->exec(
            "INSERT INTO affiliates (id, subject, url, listing_ids, link_status) VALUES (1, 'A', 'https://a.example.com', '[]', 'ok')"
        );
        $src->exec(
            "INSERT INTO affiliates (id, subject, url, listing_ids, link_status) VALUES (2, 'B', 'https://b.example.com', '[]', 'dead')"
        );

        $opts = $this->makeOptions(['import_affiliates' => '1']);
        $this->adapter()->run($src, $tgt, $opts);

        $rows = $tgt->query('SELECT url, link_status FROM lstr_affiliates ORDER BY id ASC')->fetchAll();
        self::assertCount(2, $rows);
        self::assertSame('ok',   $rows[0]['link_status']);
        self::assertSame('dead', $rows[1]['link_status']);
    }

    // -----------------------------------------------------------------------
    // Joined link status mapping
    // -----------------------------------------------------------------------

    public function testJoinedLinkStatusMovedMapsToRedirect(): void
    {
        $src = $this->makePdo();
        $tgt = $this->makePdo();
        $this->seedFanaticallySource($src);
        $this->seedTarget($tgt);

        // Add link_status and link_redirect_url to the source joined table
        $src->exec(
            'CREATE TABLE IF NOT EXISTS joined (
                id               INTEGER PRIMARY KEY AUTOINCREMENT,
                subject          TEXT NOT NULL DEFAULT \'\',
                url              TEXT NOT NULL DEFAULT \'\',
                status           TEXT NOT NULL DEFAULT \'current\',
                image_file       TEXT NOT NULL DEFAULT \'\',
                added_at         TEXT NULL,
                link_status      TEXT NOT NULL DEFAULT \'unchecked\',
                link_checked_at  TEXT NULL,
                link_redirect_url TEXT NOT NULL DEFAULT \'\'
            )'
        );
        $src->exec(
            "INSERT INTO joined (subject, url, status, link_status, link_redirect_url)
             VALUES ('Joined FL', 'https://j.example.com', 'current', 'moved', 'https://j-new.example.com')"
        );

        $opts = $this->makeOptions(['import_joined' => '1']);
        $this->adapter()->run($src, $tgt, $opts);

        $row = $tgt->query('SELECT link_status, link_redirect_url FROM lstr_joined LIMIT 1')->fetch();
        self::assertNotFalse($row, 'Joined listing should be imported');
        self::assertSame('redirect', $row['link_status'],
            "Fanatically joined 'moved' should map to Luster 'redirect'");
        self::assertSame('https://j-new.example.com', $row['link_redirect_url']);
    }

    // -----------------------------------------------------------------------
    // Site settings counting
    // -----------------------------------------------------------------------

    public function testSiteSettingsSkipCountsExistingAsSkipped(): void
    {
        $src = $this->makePdo();
        $tgt = $this->makePdo();
        $this->seedFanaticallySource($src, [
            'collective_name' => 'My Collective',
            'owner_name'      => 'The Owner',
        ]);
        $this->seedTarget($tgt);

        // Pre-seed site_name in target so it counts as a skip
        $tgt->exec("INSERT INTO lstr_site_settings (setting_key, setting_value) VALUES ('site_name', 'Existing Name')");

        $opts = $this->makeOptions(['fn_import_site_settings' => '1']);
        $result = $this->adapter()->run($src, $tgt, $opts);

        // Find the Site Settings row in result
        $settingsRow = null;
        foreach ($result->rows as $r) {
            if (($r['type'] ?? '') === 'Site Settings') {
                $settingsRow = $r;
                break;
            }
        }
        self::assertNotNull($settingsRow, 'Site Settings row should be in result');
        self::assertSame(1, (int) ($settingsRow['imported'] ?? -1),
            'owner_name (new key) should count as imported=1');
        self::assertSame(1, (int) ($settingsRow['skipped'] ?? -1),
            'collective_name (existing) should count as skipped=1 with skip strategy');

        // Existing value must not be overwritten
        $row = $tgt->query("SELECT setting_value FROM lstr_site_settings WHERE setting_key='site_name'")->fetch();
        self::assertSame('Existing Name', $row['setting_value'],
            'Skip strategy must not overwrite the existing site_name');
    }

    public function testSiteSettingsOverwriteUpdatesExistingValue(): void
    {
        $src = $this->makePdo();
        $tgt = $this->makePdo();
        $this->seedFanaticallySource($src, ['collective_name' => 'New Name']);
        $this->seedTarget($tgt);

        $tgt->exec("INSERT INTO lstr_site_settings (setting_key, setting_value) VALUES ('site_name', 'Old Name')");

        $opts = $this->makeOptions(['fn_import_site_settings' => '1', 'conflict_strategy' => 'overwrite']);
        $this->adapter()->run($src, $tgt, $opts);

        $row = $tgt->query("SELECT setting_value FROM lstr_site_settings WHERE setting_key='site_name'")->fetch();
        self::assertSame('New Name', $row['setting_value'],
            'Overwrite strategy should update the existing site_name value');
    }

    // -----------------------------------------------------------------------
    // enth_ schema support
    // -----------------------------------------------------------------------

    /** Target schema with enth_fanlistings instead of owned (triggers enthusiast_enth path). */
    private function seedEnthTarget(PDO $pdo): void
    {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS enth_fanlistings (
                id              INTEGER PRIMARY KEY AUTOINCREMENT,
                dbprefix        TEXT    NOT NULL DEFAULT \'\',
                name            TEXT    NOT NULL DEFAULT \'\',
                subject         TEXT    NOT NULL DEFAULT \'\',
                url             TEXT    NOT NULL DEFAULT \'\',
                catid           INTEGER NULL,
                additional_info TEXT    NULL,
                upcoming        INTEGER NOT NULL DEFAULT 0,
                trouble         INTEGER NOT NULL DEFAULT 0,
                opened          TEXT    NULL
            )'
        );
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS lstr_fanlisting_settings (
                fanlisting_id          INTEGER PRIMARY KEY,
                last_updated           DATE    NULL,
                approved_by            TEXT    NOT NULL DEFAULT \'\',
                queue_updates          INTEGER NOT NULL DEFAULT 0,
                auto_approve_joins     INTEGER NOT NULL DEFAULT 0,
                join_confirmation_email INTEGER NULL,
                for_adoption           INTEGER NOT NULL DEFAULT 0,
                members_per_page       INTEGER NOT NULL DEFAULT 25,
                members_template       TEXT    NULL,
                stats_template         TEXT    NULL,
                image                  TEXT    NULL,
                description            TEXT    NULL
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
            'CREATE TABLE IF NOT EXISTS lstr_joined (
                id                INTEGER PRIMARY KEY AUTOINCREMENT,
                name              TEXT    NOT NULL DEFAULT \'\',
                url               TEXT    NOT NULL DEFAULT \'\',
                image             TEXT    NULL,
                category          TEXT    NOT NULL DEFAULT \'\',
                categories_json   TEXT    NULL,
                category_id       INTEGER NULL,
                date_joined       DATE    NULL,
                active            INTEGER NOT NULL DEFAULT 1,
                status            TEXT    NOT NULL DEFAULT \'joined\',
                sort_order        INTEGER NOT NULL DEFAULT 0,
                link_status       TEXT    NOT NULL DEFAULT \'unchecked\',
                link_checked_at   TEXT    NULL,
                link_redirect_url TEXT    NULL
            )'
        );
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS lstr_affiliates (
                id                    INTEGER PRIMARY KEY AUTOINCREMENT,
                fanlisting_id         INTEGER NULL,
                name                  TEXT    NOT NULL DEFAULT \'\',
                url                   TEXT    NOT NULL DEFAULT \'\',
                email                 TEXT    NOT NULL DEFAULT \'\',
                image                 TEXT    NULL,
                pending               INTEGER NOT NULL DEFAULT 0,
                added_at              TEXT    NULL,
                sort_order            INTEGER NOT NULL DEFAULT 0,
                active                INTEGER NOT NULL DEFAULT 1,
                status                TEXT    NOT NULL DEFAULT \'approved\',
                link_status           TEXT    NOT NULL DEFAULT \'unchecked\',
                link_checked_at       TEXT    NULL,
                link_redirect_url     TEXT    NOT NULL DEFAULT \'\',
                reciprocal_status     TEXT    NOT NULL DEFAULT \'unchecked\',
                reciprocal_checked_at TEXT    NULL
            )'
        );
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS lstr_site_settings (
                setting_key   TEXT NOT NULL PRIMARY KEY,
                setting_value TEXT NOT NULL DEFAULT \'\'
            )'
        );
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS lstr_codes (
                id            INTEGER PRIMARY KEY AUTOINCREMENT,
                fanlisting_id INTEGER NOT NULL,
                size_id       INTEGER NULL,
                donor_id      INTEGER NULL,
                name          TEXT    NOT NULL DEFAULT \'\',
                filename      TEXT    NOT NULL DEFAULT \'\',
                status        TEXT    NOT NULL DEFAULT \'approved\',
                width         INTEGER NOT NULL DEFAULT 0,
                height        INTEGER NOT NULL DEFAULT 0
            )'
        );
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS lstr_code_sizes (
                id            INTEGER PRIMARY KEY AUTOINCREMENT,
                fanlisting_id INTEGER NOT NULL,
                label         TEXT    NULL,
                width         INTEGER NOT NULL DEFAULT 0,
                height        INTEGER NOT NULL DEFAULT 0
            )'
        );
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS lstr_code_donors (
                id            INTEGER PRIMARY KEY AUTOINCREMENT,
                fanlisting_id INTEGER NOT NULL,
                name          TEXT    NOT NULL DEFAULT \'\',
                url           TEXT    NULL,
                email         TEXT    NULL
            )'
        );
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS lstr_listing_categories (
                fanlisting_id INTEGER NOT NULL,
                category_id   INTEGER NOT NULL,
                PRIMARY KEY (fanlisting_id, category_id)
            )'
        );
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS lstr_listing_external_refs (
                id          INTEGER PRIMARY KEY AUTOINCREMENT,
                listing_id  INTEGER NOT NULL,
                source      TEXT    NOT NULL,
                external_id TEXT    NOT NULL,
                external_slug_or_url TEXT NOT NULL DEFAULT \'\',
                metadata_json TEXT NULL,
                UNIQUE (source, external_id)
            )'
        );
    }

    public function testEnthSchemaCustomFieldsRegisteredInAdditionalInfo(): void
    {
        $src = $this->makePdo();
        $tgt = $this->makePdo();
        $this->seedFanaticallySource($src);
        $this->seedEnthTarget($tgt);

        // Listing with custom_fields JSON
        $src->exec('ALTER TABLE listings ADD COLUMN custom_fields TEXT NULL');
        $customFields = json_encode([['name' => 'fave_song', 'label' => 'Fave Song', 'type' => 'text', 'required' => false]]);
        $src->exec(
            "INSERT INTO listings (id, title, subject, url, status, approved_by, for_adoption, custom_fields)
             VALUES (1, 'FL', 'Subj', 'https://example.com/fl/', 'current', '', 0, '{$customFields}')"
        );

        $src->exec(
            'CREATE TABLE IF NOT EXISTS members (
                id                  INTEGER PRIMARY KEY AUTOINCREMENT,
                listing_id          INTEGER NOT NULL,
                name                TEXT    NOT NULL DEFAULT \'\',
                email               TEXT    NOT NULL DEFAULT \'\',
                url                 TEXT    NOT NULL DEFAULT \'\',
                country             TEXT    NOT NULL DEFAULT \'\',
                password_hash       TEXT    NOT NULL DEFAULT \'\',
                custom_field_values TEXT    NULL,
                is_approved         INTEGER NOT NULL DEFAULT 1,
                joined_at           TEXT    NULL
            )'
        );
        $src->exec(
            "INSERT INTO members (listing_id, name, email, is_approved)
             VALUES (1, 'Fan', 'fan@example.com', 1)"
        );

        $opts = $this->makeOptions(['import_listings' => '1', 'import_members' => '1']);
        $this->adapter()->run($src, $tgt, $opts);

        $row = $tgt->query('SELECT additional_info FROM enth_fanlistings LIMIT 1')->fetch();
        self::assertNotNull($row, 'enth_fanlistings row should exist');
        $decoded = unserialize((string) $row['additional_info']);
        self::assertIsArray($decoded, 'additional_info should be a serialized PHP array');
        $names = array_column($decoded, 'name');
        self::assertContains('fave_song', $names,
            'additional_info should contain the imported custom field name');
    }

    public function testEnthSchemaCustomFieldWithOptionsPersistedAsDropdown(): void
    {
        $src = $this->makePdo();
        $tgt = $this->makePdo();
        $this->seedFanaticallySource($src);
        $this->seedEnthTarget($tgt);

        $src->exec('ALTER TABLE listings ADD COLUMN custom_fields TEXT NULL');
        $customFields = json_encode([[
            'name'     => 'favorite_movie',
            'label'    => 'Favorite Movie',
            'required' => true,
            'options'  => ['John Wick', 'John Wick: Chapter 2', 'All of them'],
        ]]);
        $src->exec(
            "INSERT INTO listings (id, title, subject, url, status, approved_by, for_adoption, custom_fields)
             VALUES (1, 'FL', 'Subj', 'https://example.com/fl/', 'current', '', 0, '{$customFields}')"
        );

        $src->exec(
            'CREATE TABLE IF NOT EXISTS members (
                id                  INTEGER PRIMARY KEY AUTOINCREMENT,
                listing_id          INTEGER NOT NULL,
                name                TEXT    NOT NULL DEFAULT \'\',
                email               TEXT    NOT NULL DEFAULT \'\',
                url                 TEXT    NOT NULL DEFAULT \'\',
                country             TEXT    NOT NULL DEFAULT \'\',
                password_hash       TEXT    NOT NULL DEFAULT \'\',
                custom_field_values TEXT    NULL,
                is_approved         INTEGER NOT NULL DEFAULT 1,
                joined_at           TEXT    NULL
            )'
        );
        $src->exec(
            "INSERT INTO members (listing_id, name, email, is_approved)
             VALUES (1, 'Fan', 'fan@example.com', 1)"
        );

        $opts = $this->makeOptions(['import_listings' => '1', 'import_members' => '1']);
        $this->adapter()->run($src, $tgt, $opts);

        $row = $tgt->query('SELECT additional_info FROM enth_fanlistings LIMIT 1')->fetch();
        self::assertNotNull($row, 'enth_fanlistings row should exist');
        $decoded = unserialize((string) $row['additional_info']);
        self::assertIsArray($decoded, 'additional_info should be a serialized PHP array');
        self::assertSame('favorite_movie', $decoded[0]['name']);
        self::assertSame('Favorite Movie', $decoded[0]['label']);
        self::assertSame('dropdown', $decoded[0]['type'], 'A field with real options must import as a dropdown, not text.');
        self::assertTrue($decoded[0]['required']);
        self::assertSame('John Wick, John Wick: Chapter 2, All of them', $decoded[0]['options']);

        // Round-trip through the actual public-form rendering path — proves the
        // three choices really render as three distinct <option>s, not one
        // pipe-joined blob (FormFieldBuilder/FieldRenderer split options on
        // commas, not pipes).
        $field = new AdditionalField(
            name:     $decoded[0]['name'],
            label:    $decoded[0]['label'],
            type:     $decoded[0]['type'],
            required: $decoded[0]['required'],
            options:  $decoded[0]['options'],
        );
        $fb = new FormFieldBuilder(new FieldRenderer(), new FieldWrapper());
        $descriptors = $fb->descriptorsFromAdditionalFields([$field], []);
        // Dropdown descriptors get an extra leading blank '' => '' placeholder
        // option (the "choose one" row), so 3 real choices means 4 total entries.
        self::assertCount(4, $descriptors[0]['options'] ?? [],
            'Rendered dropdown must have the blank placeholder plus 3 distinct options, not one pipe-joined string.');
        self::assertArrayHasKey('John Wick', $descriptors[0]['options']);
        self::assertArrayHasKey('John Wick: Chapter 2', $descriptors[0]['options']);
        self::assertArrayHasKey('All of them', $descriptors[0]['options']);
    }

    public function testEnthSchemaMemberTableNamedWithMembersSuffix(): void
    {
        $src = $this->makePdo();
        $tgt = $this->makePdo();
        $this->seedFanaticallySource($src);
        $this->seedEnthTarget($tgt);

        // Listing with custom_fields JSON
        $src->exec('ALTER TABLE listings ADD COLUMN custom_fields TEXT NULL');
        $customFields = json_encode([['name' => 'fave_song', 'label' => 'Fave Song', 'type' => 'text', 'required' => false]]);
        $src->exec(
            "INSERT INTO listings (id, title, subject, url, status, approved_by, for_adoption, custom_fields)
             VALUES (1, 'FL', 'Subj', 'https://example.com/fl/', 'current', '', 0, '{$customFields}')"
        );

        $src->exec(
            'CREATE TABLE IF NOT EXISTS members (
                id                  INTEGER PRIMARY KEY AUTOINCREMENT,
                listing_id          INTEGER NOT NULL,
                name                TEXT    NOT NULL DEFAULT \'\',
                email               TEXT    NOT NULL DEFAULT \'\',
                url                 TEXT    NOT NULL DEFAULT \'\',
                country             TEXT    NOT NULL DEFAULT \'\',
                password_hash       TEXT    NOT NULL DEFAULT \'\',
                custom_field_values TEXT    NOT NULL DEFAULT \'\',
                is_approved         INTEGER NOT NULL DEFAULT 1,
                joined_at           TEXT    NULL
            )'
        );
        $cfJson = json_encode(['fave_song' => 'Bohemian Rhapsody']);
        $src->exec(
            "INSERT INTO members (listing_id, name, email, is_approved, custom_field_values)
             VALUES (1, 'Fan', 'fan@example.com', 1, '{$cfJson}')"
        );

        $opts = $this->makeOptions(['import_listings' => '1', 'import_members' => '1']);
        $this->adapter()->run($src, $tgt, $opts);

        // Retrieve the dbprefix that was inserted into enth_fanlistings
        $flRow = $tgt->query('SELECT dbprefix FROM enth_fanlistings LIMIT 1')->fetch();
        self::assertNotNull($flRow, 'enth_fanlistings row should exist');
        $dbprefix = (string) $flRow['dbprefix'];

        // Member table should be {dbprefix}members, not {dbprefix}
        $memberTable = $dbprefix . 'members';
        $tables = $tgt->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(\PDO::FETCH_COLUMN);
        self::assertContains($memberTable, $tables,
            "Member table should be named '{$memberTable}' (dbprefix + 'members') for enth_ schema");
        self::assertNotContains($dbprefix, array_diff($tables, [$memberTable]),
            "Member table should NOT be created as bare '{$dbprefix}' slug for enth_ schema");

        // Column names should use additional_ prefix
        $cols = $tgt->query("PRAGMA table_info(`{$memberTable}`)")->fetchAll(\PDO::FETCH_COLUMN, 1);
        self::assertContains('additional_fave_song', $cols,
            "Column should be 'additional_fave_song' in enth_ schema member table");
        self::assertNotContains('fave_song', $cols,
            "Bare 'fave_song' column should not exist in enth_ schema member table");

        // Member data should be readable from the correctly named column
        $memberRow = $tgt->query("SELECT additional_fave_song FROM `{$memberTable}` WHERE email='fan@example.com'")->fetch();
        self::assertSame('Bohemian Rhapsody', (string) ($memberRow['additional_fave_song'] ?? ''),
            'Custom field value should be stored in the additional_-prefixed column');
    }

    // -----------------------------------------------------------------------
    // Phase 3 — external ref storage
    // -----------------------------------------------------------------------

    public function testListingImportStoresFanaticallyExternalRef(): void
    {
        $src = $this->makePdo();
        $tgt = $this->makePdo();
        $this->seedFanaticallySource($src);
        $this->seedTarget($tgt);

        $fanId = $this->insertListing($src, 'https://example.com/fl/');

        $opts = $this->makeOptions(['import_listings' => '1']);
        $this->adapter()->run($src, $tgt, $opts);

        $ref = $tgt->query(
            "SELECT listing_id, source, external_id FROM lstr_listing_external_refs
             WHERE source = 'fanatically'"
        )->fetch();

        self::assertNotFalse($ref, 'A fanatically external ref should be recorded');
        self::assertSame('fanatically', (string) $ref['source']);
        self::assertSame((string) $fanId, (string) $ref['external_id']);
        self::assertGreaterThan(0, (int) $ref['listing_id']);
    }

    public function testListingImportStoresFansFansFansRefWhenPresent(): void
    {
        $src = $this->makePdo();
        $tgt = $this->makePdo();
        $this->seedFanaticallySource($src);
        $this->seedTarget($tgt);

        // Add the optional fansfansfans_id column and seed a row with it
        $src->exec("ALTER TABLE listings ADD COLUMN fansfansfans_id TEXT NULL");
        $src->prepare(
            'INSERT INTO listings (title, subject, url, status, approved_by,
             for_adoption, opened_date, last_updated, image_file, fansfansfans_id)
             VALUES (:t,:s,:u,\'current\',\'\',0,NULL,NULL,\'\',\'FFF-42\')'
        )->execute(['t' => 'Test FL', 's' => 'Test Subject', 'u' => 'https://example.com/fl/']);

        $opts = $this->makeOptions(['import_listings' => '1']);
        $this->adapter()->run($src, $tgt, $opts);

        $fansFansRef = $tgt->query(
            "SELECT listing_id FROM lstr_listing_external_refs
             WHERE source = 'fansfansfans' AND external_id = 'FFF-42'"
        )->fetch();

        self::assertNotFalse($fansFansRef, 'FansFansFans ref should be stored when fansfansfans_id is set');
    }

    public function testSkipModeStillBackfillsExternalRef(): void
    {
        $src = $this->makePdo();
        $tgt = $this->makePdo();
        $this->seedFanaticallySource($src);
        $this->seedTarget($tgt);

        $fanId = $this->insertListing($src, 'https://example.com/fl/');

        // First run: import listing (creates the owned row)
        $this->adapter()->run($src, $tgt, $this->makeOptions(['import_listings' => '1']));

        // Remove the ref so we can verify the second run backfills it
        $tgt->exec("DELETE FROM lstr_listing_external_refs");

        // Second run in skip mode: listing already exists — should still record the ref
        $this->adapter()->run($src, $tgt, $this->makeOptions([
            'import_listings'    => '1',
            'conflict_strategy'  => 'skip',
        ]));

        $ref = $tgt->query(
            "SELECT external_id FROM lstr_listing_external_refs WHERE source = 'fanatically'"
        )->fetch();

        self::assertNotFalse($ref, 'Skip mode should backfill the external ref before continuing');
        self::assertSame((string) $fanId, (string) $ref['external_id']);
    }

    public function testStaleMappingIsRepairedOnReimport(): void
    {
        $src = $this->makePdo();
        $tgt = $this->makePdo();
        $this->seedFanaticallySource($src);
        $this->seedTarget($tgt);

        $fanId = $this->insertListing($src, 'https://example.com/fl/');

        // First run
        $this->adapter()->run($src, $tgt, $this->makeOptions(['import_listings' => '1']));

        // Manually corrupt the mapping with a wrong listing_id
        $tgt->exec("UPDATE lstr_listing_external_refs SET listing_id = 999 WHERE source = 'fanatically'");

        // Second run in overwrite mode: should repair the stale mapping
        $this->adapter()->run($src, $tgt, $this->makeOptions([
            'import_listings'    => '1',
            'conflict_strategy'  => 'overwrite',
        ]));

        $ref = $tgt->query(
            "SELECT listing_id FROM lstr_listing_external_refs WHERE source = 'fanatically'"
        )->fetch();

        self::assertNotFalse($ref, 'Ref should still exist after overwrite');
        self::assertNotSame('999', (string) $ref['listing_id'], 'Stale listing_id 999 should be repaired');
    }
}
