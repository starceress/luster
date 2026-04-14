<?php

declare(strict_types=1);

namespace Luster\Tests\Unit;

use Luster\Import\EnthusiastImportSource;
use Luster\Import\ImportOptions;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Tests for EnthusiastImportSource member migration.
 *
 * Uses two in-memory SQLite PDO connections (source and target) so no live
 * MySQL instance is required.  MySQL-specific DDL (ENGINE=InnoDB, CHARSET)
 * is omitted via the driver-detection logic in createMemberTable().
 *
 * isSameDatabase() runs `SELECT DATABASE()` which is not valid SQLite;
 * the resulting exception is caught and returns false — meaning the
 * member-import path always runs, which is what the tests need.
 */
final class EnthusiastMemberImportTest extends TestCase
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

    /**
     * Builds an ImportOptions value object with sensible defaults.
     * Only the flags passed in $overrides are non-default.
     *
     * @param array<string,mixed> $overrides
     */
    private function makeOptions(array $overrides = []): ImportOptions
    {
        return ImportOptions::fromPost(array_merge([
            'source'           => 'enthusiast',
            'conflict_strategy' => 'skip',
            'import_members'   => '1',
        ], $overrides));
    }

    private function adapter(): EnthusiastImportSource
    {
        return new EnthusiastImportSource();
    }

    // -----------------------------------------------------------------------
    // Source database setup helpers
    // -----------------------------------------------------------------------

    /** Creates unprefixed Enthusiast schema in $pdo with one listing and a member table. */
    private function seedUnprefixedSource(PDO $pdo, string $dbtable, array $additional = []): void
    {
        $addStr   = implode(',', $additional);
        $extraDdl = '';
        foreach ($additional as $f) {
            $extraDdl .= ", `{$f}` TEXT NOT NULL DEFAULT ''";
        }

        $pdo->exec("CREATE TABLE IF NOT EXISTS `categories` (catid INTEGER PRIMARY KEY, catname TEXT)");
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS `owned` (
                listingid INTEGER PRIMARY KEY,
                dbtable   TEXT NOT NULL,
                title     TEXT NOT NULL DEFAULT '',
                subject   TEXT NOT NULL DEFAULT '',
                email     TEXT NOT NULL DEFAULT '',
                url       TEXT NOT NULL DEFAULT '',
                catid     TEXT NOT NULL DEFAULT '',
                additional TEXT NOT NULL DEFAULT '',
                status    INTEGER NOT NULL DEFAULT 2
            )"
        );
        $pdo->exec(
            "INSERT INTO `owned` (listingid, dbtable, title, subject, email, url, catid, additional, status)
             VALUES (1, '{$dbtable}', 'Test Listing', 'Testing', 'owner@test.com', 'http://test.com', '', '{$addStr}', 2)"
        );
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS `{$dbtable}` (
                email     TEXT NOT NULL PRIMARY KEY,
                name      TEXT NOT NULL DEFAULT '',
                country   TEXT NOT NULL DEFAULT '',
                url       TEXT NOT NULL DEFAULT '',
                pending   INTEGER NOT NULL DEFAULT 0,
                password  TEXT NOT NULL DEFAULT '',
                showemail INTEGER NOT NULL DEFAULT 1,
                showurl   INTEGER NOT NULL DEFAULT 1,
                added     TEXT NULL
                {$extraDdl}
            )"
        );
    }

    /** Creates enth_ Enthusiast schema in $pdo with one listing and a member table. */
    private function seedEnthSource(PDO $pdo, string $dbprefix, array $additionalInfo = []): void
    {
        // additionalInfo: [['name' => 'fave_song', 'label' => 'Fave Song'], ...]
        $addInfoStr = '';
        $extraDdl   = '';
        foreach ($additionalInfo as $f) {
            $addInfoStr .= ($addInfoStr !== '' ? '|' : '') . "{$f['name']},{$f['label']},text,0";
            $col = 'additional_' . $f['name'];
            $extraDdl .= ", `{$col}` TEXT NOT NULL DEFAULT ''";
        }

        $dbtable = $dbprefix . 'members';

        $pdo->exec("CREATE TABLE IF NOT EXISTS `enth_category` (catid INTEGER PRIMARY KEY, catname TEXT)");
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS `enth_fanlistings` (
                id              INTEGER PRIMARY KEY,
                dbprefix        TEXT NOT NULL,
                name            TEXT NOT NULL DEFAULT '',
                subject         TEXT NOT NULL DEFAULT '',
                email           TEXT NOT NULL DEFAULT '',
                url             TEXT NOT NULL DEFAULT '',
                catid           TEXT NOT NULL DEFAULT '',
                additional_info TEXT NOT NULL DEFAULT '',
                trouble         INTEGER NOT NULL DEFAULT 0,
                upcoming        INTEGER NOT NULL DEFAULT 0
            )"
        );
        $pdo->exec(
            "INSERT INTO `enth_fanlistings` (id, dbprefix, name, subject, email, url, catid, additional_info)
             VALUES (1, '{$dbprefix}', 'Test Listing', 'Testing', 'owner@test.com', 'http://test.com', '', '{$addInfoStr}')"
        );
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS `{$dbtable}` (
                email     TEXT NOT NULL PRIMARY KEY,
                name      TEXT NOT NULL DEFAULT '',
                country   TEXT NOT NULL DEFAULT '',
                url       TEXT NOT NULL DEFAULT '',
                pending   INTEGER NOT NULL DEFAULT 0,
                password  TEXT NOT NULL DEFAULT '',
                showemail INTEGER NOT NULL DEFAULT 1,
                showurl   INTEGER NOT NULL DEFAULT 1,
                added     TEXT NULL
                {$extraDdl}
            )"
        );
    }

    private function insertMember(PDO $pdo, string $dbtable, array $data): void
    {
        $data = array_merge([
            'name' => 'Test Fan', 'country' => 'US', 'url' => '',
            'pending' => 0, 'password' => '', 'showemail' => 1, 'showurl' => 1, 'added' => '2024-01-01',
        ], $data);

        $cols  = array_keys($data);
        $ph    = implode(', ', array_map(fn($c) => ':' . $c, $cols));
        $cList = implode(', ', array_map(fn($c) => "`{$c}`", $cols));
        $stmt  = $pdo->prepare("INSERT INTO `{$dbtable}` ({$cList}) VALUES ({$ph})");
        $stmt->execute($data);
    }

    // -----------------------------------------------------------------------
    // previewMembers — unprefixed schema
    // -----------------------------------------------------------------------

    public function testPreviewCountsMembersAcrossListings(): void
    {
        $src = $this->makePdo();
        $this->seedUnprefixedSource($src, 'huntrix');
        $this->insertMember($src, 'huntrix', ['email' => 'a@test.com']);
        $this->insertMember($src, 'huntrix', ['email' => 'b@test.com']);

        // Second listing
        $src->exec(
            "INSERT INTO `owned` (listingid, dbtable, title, subject, email, url, catid, additional, status)
             VALUES (2, 'gachia', 'Gachia', 'Testing', 'o@test.com', 'http://test2.com', '', '', 2)"
        );
        $src->exec("CREATE TABLE `gachia` (email TEXT PRIMARY KEY, name TEXT NOT NULL DEFAULT '',
            country TEXT NOT NULL DEFAULT '', url TEXT NOT NULL DEFAULT '',
            pending INTEGER NOT NULL DEFAULT 0, password TEXT NOT NULL DEFAULT '',
            showemail INTEGER NOT NULL DEFAULT 1, showurl INTEGER NOT NULL DEFAULT 1, added TEXT NULL)");
        $this->insertMember($src, 'gachia', ['email' => 'c@test.com']);

        $preview = $this->adapter()->preview($src, $this->makeOptions());

        $membersRow = null;
        foreach ($preview->rows as $row) {
            if ($row['type'] === 'Members') {
                $membersRow = $row;
                break;
            }
        }

        $this->assertNotNull($membersRow, 'Preview should include a Members row');
        $this->assertSame(3, $membersRow['found']);
        $this->assertSame(3, $membersRow['import']);
        $this->assertSame(0, $membersRow['skip']);
    }

    public function testPreviewWarnsWhenMemberTableMissing(): void
    {
        $src = $this->makePdo();
        $this->seedUnprefixedSource($src, 'huntrix');
        // Don't create the member table

        // Drop the table the seed created
        $src->exec('DROP TABLE huntrix');

        $preview = $this->adapter()->preview($src, $this->makeOptions());

        $membersRow = null;
        foreach ($preview->rows as $row) {
            if ($row['type'] === 'Members') {
                $membersRow = $row;
                break;
            }
        }

        $this->assertNotNull($membersRow);
        $this->assertSame(0, $membersRow['found']);
        $this->assertStringContainsString('not found', implode(' ', $membersRow['warnings']));
    }

    // -----------------------------------------------------------------------
    // previewMembers — enth_ schema
    // -----------------------------------------------------------------------

    public function testPreviewCountsMembersEnthSchema(): void
    {
        $src = $this->makePdo();
        $this->seedEnthSource($src, 'fl_hunt_');
        $this->insertMember($src, 'fl_hunt_members', ['email' => 'fan@test.com']);
        $this->insertMember($src, 'fl_hunt_members', ['email' => 'fan2@test.com']);

        $preview = $this->adapter()->preview($src, $this->makeOptions());

        $membersRow = null;
        foreach ($preview->rows as $row) {
            if ($row['type'] === 'Members') {
                $membersRow = $row;
                break;
            }
        }

        $this->assertNotNull($membersRow);
        $this->assertSame(2, $membersRow['found']);
    }

    // -----------------------------------------------------------------------
    // runMembers — same-DB short-circuit
    // -----------------------------------------------------------------------

    public function testRunMembersShortCircuitsWhenSameDatabase(): void
    {
        // Passing the same PDO object as both source and target triggers isSameDatabase() === true
        $pdo = $this->makePdo();
        $this->seedUnprefixedSource($pdo, 'huntrix');
        $this->insertMember($pdo, 'huntrix', ['email' => 'fan@test.com']);

        $result = $this->adapter()->run($pdo, $pdo, $this->makeOptions());

        $membersRow = null;
        foreach ($result->rows as $row) {
            if ($row['type'] === 'Members') {
                $membersRow = $row;
                break;
            }
        }

        $this->assertNotNull($membersRow);
        $this->assertSame(0, $membersRow['imported']);
        $this->assertStringContainsString('already accessible', implode(' ', $membersRow['warnings']));
    }

    // -----------------------------------------------------------------------
    // runMembers — basic copy (unprefixed schema)
    // -----------------------------------------------------------------------

    public function testRunMembersCopiesAllMembersUnprefixed(): void
    {
        $src = $this->makePdo();
        $tgt = $this->makePdo();

        $this->seedUnprefixedSource($src, 'huntrix');
        $this->insertMember($src, 'huntrix', ['email' => 'alice@test.com', 'name' => 'Alice', 'country' => 'GB']);
        $this->insertMember($src, 'huntrix', ['email' => 'bob@test.com',   'name' => 'Bob',   'country' => 'US']);

        $result = $this->adapter()->run($src, $tgt, $this->makeOptions());

        $membersRow = null;
        foreach ($result->rows as $row) {
            if ($row['type'] === 'Members') { $membersRow = $row; break; }
        }

        $this->assertNotNull($membersRow);
        $this->assertSame(2, $membersRow['imported']);
        $this->assertSame(0, $membersRow['skipped']);
        $this->assertEmpty($membersRow['errors']);

        // Verify data landed correctly in target
        $stmt = $tgt->query("SELECT * FROM `huntrix` ORDER BY email ASC");
        $rows = $stmt->fetchAll();
        $this->assertCount(2, $rows);
        $this->assertSame('alice@test.com', $rows[0]['email']);
        $this->assertSame('Alice',          $rows[0]['name']);
        $this->assertSame('GB',             $rows[0]['country']);
        $this->assertSame('bob@test.com',   $rows[1]['email']);
    }

    // -----------------------------------------------------------------------
    // runMembers — basic copy (enth_ schema)
    // -----------------------------------------------------------------------

    public function testRunMembersCopiesAllMembersEnthSchema(): void
    {
        $src = $this->makePdo();
        $tgt = $this->makePdo();

        $this->seedEnthSource($src, 'fl_hunt_');
        $this->insertMember($src, 'fl_hunt_members', ['email' => 'fan@test.com', 'name' => 'Fan']);

        $result = $this->adapter()->run($src, $tgt, $this->makeOptions());

        $membersRow = null;
        foreach ($result->rows as $row) {
            if ($row['type'] === 'Members') { $membersRow = $row; break; }
        }

        $this->assertNotNull($membersRow);
        $this->assertSame(1, $membersRow['imported']);
        $this->assertEmpty($membersRow['errors']);

        $stmt = $tgt->query("SELECT * FROM `fl_hunt_members`");
        $rows = $stmt->fetchAll();
        $this->assertCount(1, $rows);
        $this->assertSame('fan@test.com', $rows[0]['email']);
        $this->assertSame('Fan',          $rows[0]['name']);
    }

    // -----------------------------------------------------------------------
    // runMembers — conflict strategy: skip
    // -----------------------------------------------------------------------

    public function testRunMembersSkipsExistingEmailWhenStrategyIsSkip(): void
    {
        $src = $this->makePdo();
        $tgt = $this->makePdo();

        $this->seedUnprefixedSource($src, 'huntrix');
        $this->insertMember($src, 'huntrix', ['email' => 'alice@test.com', 'name' => 'Alice']);

        // Pre-seed the target with a stale row
        $tgt->exec(
            "CREATE TABLE `huntrix` (email TEXT PRIMARY KEY, name TEXT NOT NULL DEFAULT '',
             country TEXT NOT NULL DEFAULT '', url TEXT NOT NULL DEFAULT '',
             pending INTEGER NOT NULL DEFAULT 0, password TEXT NOT NULL DEFAULT '',
             showemail INTEGER NOT NULL DEFAULT 1, showurl INTEGER NOT NULL DEFAULT 1, added TEXT NULL)"
        );
        $tgt->exec("INSERT INTO `huntrix` (email, name) VALUES ('alice@test.com', 'Old Name')");

        $result = $this->adapter()->run($src, $tgt, $this->makeOptions(['conflict_strategy' => 'skip']));

        $membersRow = null;
        foreach ($result->rows as $row) {
            if ($row['type'] === 'Members') { $membersRow = $row; break; }
        }

        $this->assertSame(0, $membersRow['imported']);
        $this->assertSame(1, $membersRow['skipped']);

        // Name should NOT have changed
        $stmt = $tgt->query("SELECT name FROM `huntrix` WHERE email = 'alice@test.com'");
        $this->assertSame('Old Name', $stmt->fetchColumn());
    }

    // -----------------------------------------------------------------------
    // runMembers — conflict strategy: overwrite
    // -----------------------------------------------------------------------

    public function testRunMembersOverwritesExistingEmail(): void
    {
        $src = $this->makePdo();
        $tgt = $this->makePdo();

        $this->seedUnprefixedSource($src, 'huntrix');
        $this->insertMember($src, 'huntrix', ['email' => 'alice@test.com', 'name' => 'Alice New', 'country' => 'DE']);

        $tgt->exec(
            "CREATE TABLE `huntrix` (email TEXT PRIMARY KEY, name TEXT NOT NULL DEFAULT '',
             country TEXT NOT NULL DEFAULT '', url TEXT NOT NULL DEFAULT '',
             pending INTEGER NOT NULL DEFAULT 0, password TEXT NOT NULL DEFAULT '',
             showemail INTEGER NOT NULL DEFAULT 1, showurl INTEGER NOT NULL DEFAULT 1, added TEXT NULL)"
        );
        $tgt->exec("INSERT INTO `huntrix` (email, name, country) VALUES ('alice@test.com', 'Old Name', 'US')");

        $result = $this->adapter()->run($src, $tgt, $this->makeOptions(['conflict_strategy' => 'overwrite']));

        $membersRow = null;
        foreach ($result->rows as $row) {
            if ($row['type'] === 'Members') { $membersRow = $row; break; }
        }

        $this->assertSame(1, $membersRow['imported']);
        $this->assertSame(0, $membersRow['skipped']);

        $stmt = $tgt->query("SELECT name, country FROM `huntrix` WHERE email = 'alice@test.com'");
        $row  = $stmt->fetch();
        $this->assertSame('Alice New', $row['name']);
        $this->assertSame('DE',        $row['country']);
    }

    // -----------------------------------------------------------------------
    // runMembers — additional fields (unprefixed schema)
    // -----------------------------------------------------------------------

    public function testRunMembersCopiesAdditionalFieldsUnprefixed(): void
    {
        $src = $this->makePdo();
        $tgt = $this->makePdo();

        $this->seedUnprefixedSource($src, 'huntrix', ['favorite']);
        $this->insertMember($src, 'huntrix', ['email' => 'fan@test.com', 'favorite' => 'Hunters']);

        $result = $this->adapter()->run($src, $tgt, $this->makeOptions());

        $membersRow = null;
        foreach ($result->rows as $row) {
            if ($row['type'] === 'Members') { $membersRow = $row; break; }
        }

        $this->assertSame(1, $membersRow['imported']);
        $this->assertEmpty($membersRow['errors']);

        $stmt = $tgt->query("SELECT * FROM `huntrix` WHERE email = 'fan@test.com'");
        $row  = $stmt->fetch();
        $this->assertArrayHasKey('favorite', $row);
        $this->assertSame('Hunters', $row['favorite']);
    }

    // -----------------------------------------------------------------------
    // runMembers — additional fields (enth_ schema)
    // -----------------------------------------------------------------------

    public function testRunMembersCopiesAdditionalFieldsEnthSchema(): void
    {
        $src = $this->makePdo();
        $tgt = $this->makePdo();

        $this->seedEnthSource($src, 'fl_hunt_', [['name' => 'fave_song', 'label' => 'Favourite Song']]);
        $this->insertMember($src, 'fl_hunt_members', [
            'email'                   => 'fan@test.com',
            'additional_fave_song'    => 'Hunters Theme',
        ]);

        $result = $this->adapter()->run($src, $tgt, $this->makeOptions());

        $membersRow = null;
        foreach ($result->rows as $row) {
            if ($row['type'] === 'Members') { $membersRow = $row; break; }
        }

        $this->assertSame(1, $membersRow['imported']);
        $this->assertEmpty($membersRow['errors']);

        $stmt = $tgt->query("SELECT * FROM `fl_hunt_members` WHERE email = 'fan@test.com'");
        $row  = $stmt->fetch();
        $this->assertArrayHasKey('additional_fave_song', $row);
        $this->assertSame('Hunters Theme', $row['additional_fave_song']);
    }

    // -----------------------------------------------------------------------
    // runMembers — invalid dbtable is skipped with an error
    // -----------------------------------------------------------------------

    public function testRunMembersSkipsListingWithInvalidDbtable(): void
    {
        $src = $this->makePdo();
        $tgt = $this->makePdo();

        $src->exec("CREATE TABLE IF NOT EXISTS `categories` (catid INTEGER PRIMARY KEY, catname TEXT)");
        $src->exec(
            "CREATE TABLE `owned` (
                listingid INTEGER PRIMARY KEY, dbtable TEXT NOT NULL,
                title TEXT NOT NULL DEFAULT '', subject TEXT NOT NULL DEFAULT '',
                email TEXT NOT NULL DEFAULT '', url TEXT NOT NULL DEFAULT '',
                catid TEXT NOT NULL DEFAULT '', additional TEXT NOT NULL DEFAULT '',
                status INTEGER NOT NULL DEFAULT 2
            )"
        );
        // Insert a listing with an invalid dbtable name (contains spaces / special chars)
        $src->exec("INSERT INTO `owned` (listingid, dbtable, url) VALUES (1, 'bad table!', 'http://test.com')");

        $result = $this->adapter()->run($src, $tgt, $this->makeOptions());

        $membersRow = null;
        foreach ($result->rows as $row) {
            if ($row['type'] === 'Members') { $membersRow = $row; break; }
        }

        $this->assertNotNull($membersRow);
        $this->assertSame(0, $membersRow['imported']);
        $this->assertNotEmpty($membersRow['errors']);
        $this->assertStringContainsString('invalid', $membersRow['errors'][0]);
    }

    // -----------------------------------------------------------------------
    // runMembers — chunked reading (> CHUNK rows)
    // -----------------------------------------------------------------------

    public function testRunMembersHandlesMoreThanOneChunk(): void
    {
        $src = $this->makePdo();
        $tgt = $this->makePdo();

        $this->seedUnprefixedSource($src, 'huntrix');

        // Insert 502 members (CHUNK = 500, so needs 2 DB reads)
        $src->exec('BEGIN');
        for ($i = 1; $i <= 502; $i++) {
            $this->insertMember($src, 'huntrix', ['email' => "fan{$i}@test.com", 'name' => "Fan {$i}"]);
        }
        $src->exec('COMMIT');

        $result = $this->adapter()->run($src, $tgt, $this->makeOptions());

        $membersRow = null;
        foreach ($result->rows as $row) {
            if ($row['type'] === 'Members') { $membersRow = $row; break; }
        }

        $this->assertSame(502, $membersRow['imported']);
        $this->assertEmpty($membersRow['errors']);

        $count = (int) $tgt->query('SELECT COUNT(*) FROM `huntrix`')->fetchColumn();
        $this->assertSame(502, $count);
    }

    // -----------------------------------------------------------------------
    // parseAdditionalFieldNames — unit tests (via the run path indirectly)
    // -----------------------------------------------------------------------

    public function testUnprefixedAdditionalFieldsAreUsedBareInTargetTable(): void
    {
        $src = $this->makePdo();
        $tgt = $this->makePdo();

        // Two additional fields
        $this->seedUnprefixedSource($src, 'huntrix', ['fave', 'country2']);
        $this->insertMember($src, 'huntrix', [
            'email'    => 'fan@test.com',
            'fave'     => 'Hisoka',
            'country2' => 'Japan',
        ]);

        $this->adapter()->run($src, $tgt, $this->makeOptions());

        $stmt = $tgt->query("SELECT * FROM `huntrix`");
        $row  = $stmt->fetch();
        $this->assertSame('Hisoka', $row['fave']);
        $this->assertSame('Japan',  $row['country2']);
    }

    public function testEnthAdditionalFieldsAreStoredWithAdditionalPrefix(): void
    {
        $src = $this->makePdo();
        $tgt = $this->makePdo();

        $this->seedEnthSource($src, 'fl_g_', [
            ['name' => 'fave_char', 'label' => 'Fave Character'],
            ['name' => 'fave_arc',  'label' => 'Fave Arc'],
        ]);
        $this->insertMember($src, 'fl_g_members', [
            'email'                => 'fan@test.com',
            'additional_fave_char' => 'Gabimaru',
            'additional_fave_arc'  => 'Flower Garden',
        ]);

        $this->adapter()->run($src, $tgt, $this->makeOptions());

        $stmt = $tgt->query("SELECT * FROM `fl_g_members`");
        $row  = $stmt->fetch();
        $this->assertSame('Gabimaru',     $row['additional_fave_char']);
        $this->assertSame('Flower Garden', $row['additional_fave_arc']);
    }

    // -----------------------------------------------------------------------
    // runMembers — pending flag is preserved
    // -----------------------------------------------------------------------

    public function testRunMembersPreservesPendingFlag(): void
    {
        $src = $this->makePdo();
        $tgt = $this->makePdo();

        $this->seedUnprefixedSource($src, 'huntrix');
        $this->insertMember($src, 'huntrix', ['email' => 'pending@test.com', 'pending' => 1]);
        $this->insertMember($src, 'huntrix', ['email' => 'approved@test.com', 'pending' => 0]);

        $this->adapter()->run($src, $tgt, $this->makeOptions());

        $stmt = $tgt->query("SELECT email, pending FROM `huntrix` ORDER BY email");
        $rows = $stmt->fetchAll();

        $this->assertSame(2,                  count($rows));
        $this->assertSame('approved@test.com', $rows[0]['email']);
        $this->assertSame(0,                  (int) $rows[0]['pending']);
        $this->assertSame('pending@test.com',  $rows[1]['email']);
        $this->assertSame(1,                  (int) $rows[1]['pending']);
    }

    // -----------------------------------------------------------------------
    // runMembers — multiple listings
    // -----------------------------------------------------------------------

    public function testRunMembersCopiesAcrossMultipleListings(): void
    {
        $src = $this->makePdo();
        $tgt = $this->makePdo();

        $this->seedUnprefixedSource($src, 'huntrix');
        $this->insertMember($src, 'huntrix', ['email' => 'a@test.com']);

        // Second listing
        $src->exec(
            "INSERT INTO `owned` (listingid, dbtable, title, subject, email, url, catid, additional, status)
             VALUES (2, 'gachia', 'Gachia', 'Test', 'o@t.com', 'http://t2.com', '', '', 2)"
        );
        $src->exec(
            "CREATE TABLE `gachia` (
                email TEXT PRIMARY KEY, name TEXT NOT NULL DEFAULT '',
                country TEXT NOT NULL DEFAULT '', url TEXT NOT NULL DEFAULT '',
                pending INTEGER NOT NULL DEFAULT 0, password TEXT NOT NULL DEFAULT '',
                showemail INTEGER NOT NULL DEFAULT 1, showurl INTEGER NOT NULL DEFAULT 1, added TEXT NULL
            )"
        );
        $this->insertMember($src, 'gachia', ['email' => 'b@test.com']);
        $this->insertMember($src, 'gachia', ['email' => 'c@test.com']);

        $result = $this->adapter()->run($src, $tgt, $this->makeOptions());

        $membersRow = null;
        foreach ($result->rows as $row) {
            if ($row['type'] === 'Members') { $membersRow = $row; break; }
        }

        $this->assertSame(3, $membersRow['imported']);
        $this->assertEmpty($membersRow['errors']);

        $this->assertSame(1, (int) $tgt->query('SELECT COUNT(*) FROM `huntrix`')->fetchColumn());
        $this->assertSame(2, (int) $tgt->query('SELECT COUNT(*) FROM `gachia`')->fetchColumn());
    }
}
