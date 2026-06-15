<?php

declare(strict_types=1);

namespace Luster\Tests\Unit;

use Luster\Import\EnthusiastImportSource;
use Luster\Import\ImportOptions;
use Luster\Core\NetworkAddressGuard;
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

    public function testNetworkAddressGuardRejectsLoopbackAndPrivateHosts(): void
    {
        $this->assertNotNull(NetworkAddressGuard::validatePublicHost('127.0.0.1'));
        $this->assertNotNull(NetworkAddressGuard::validatePublicHost('::1'));
        $this->assertNotNull(NetworkAddressGuard::validatePublicHost('localhost'));
        $this->assertNotNull(NetworkAddressGuard::validatePublicHost('192.168.1.10'));
    }

    public function testNetworkAddressGuardAllowsPublicIpAddresses(): void
    {
        $this->assertNull(NetworkAddressGuard::validatePublicHost('8.8.8.8'));
        $this->assertNull(NetworkAddressGuard::validatePublicHost('1.1.1.1'));
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

        $preview = $this->adapter()->preview($src, $src, $this->makeOptions());

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

        $preview = $this->adapter()->preview($src, $src, $this->makeOptions());

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

        $preview = $this->adapter()->preview($src, $src, $this->makeOptions());

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
        $this->assertStringContainsString('already reading them directly', implode(' ', $membersRow['warnings']));
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

    // -----------------------------------------------------------------------
    // Category source detection (TFL / TAFL / custom)
    // -----------------------------------------------------------------------

    /** Creates a minimal source + target setup for category + joined-listing tests. */
    private function seedForCategoryTests(PDO $src, PDO $tgt): void
    {
        // Source: unprefixed Enthusiast schema — note singular 'category' table name
        // and 'parent' (not 'catparent') as the hierarchy column
        $src->exec('CREATE TABLE IF NOT EXISTS category (catid INTEGER PRIMARY KEY, catname TEXT NOT NULL, parent INTEGER NOT NULL DEFAULT 0)');
        $src->exec("CREATE TABLE IF NOT EXISTS `owned` (listingid INTEGER PRIMARY KEY, dbtable TEXT NOT NULL DEFAULT '', title TEXT NOT NULL DEFAULT '', subject TEXT NOT NULL DEFAULT '', email TEXT NOT NULL DEFAULT '', url TEXT NOT NULL DEFAULT '', catid TEXT NOT NULL DEFAULT '', additional TEXT NOT NULL DEFAULT '', status INTEGER NOT NULL DEFAULT 2)");
        $src->exec("CREATE TABLE IF NOT EXISTS joined (joinedid INTEGER PRIMARY KEY, url TEXT NOT NULL, subject TEXT NOT NULL DEFAULT '', catid TEXT NOT NULL DEFAULT '', pending INTEGER NOT NULL DEFAULT 0, added TEXT NULL, imagefile TEXT NOT NULL DEFAULT '')");

        // Target: needs lstr_categories + lstr_joined
        $tgt->exec('CREATE TABLE IF NOT EXISTS lstr_categories (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, slug TEXT NOT NULL DEFAULT \'\', parent_id INTEGER NULL, source TEXT NOT NULL DEFAULT \'custom\')');
        $tgt->exec('CREATE TABLE IF NOT EXISTS lstr_joined (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL DEFAULT \'\', url TEXT NOT NULL DEFAULT \'\', image TEXT NULL, category TEXT NOT NULL DEFAULT \'\', categories_json TEXT NULL, category_id INTEGER NULL, date_joined TEXT NULL, active INTEGER NOT NULL DEFAULT 1, status TEXT NOT NULL DEFAULT \'approved\', sort_order INTEGER NOT NULL DEFAULT 0, description TEXT NULL, comments TEXT NULL)');
    }

    public function testRunCategoriesAssignsTflSourceToKnownTflCategories(): void
    {
        $src = $this->makePdo();
        $tgt = $this->makePdo();
        $this->seedForCategoryTests($src, $tgt);

        $src->exec("INSERT INTO category (catid, catname) VALUES (1, 'Academia'), (2, 'Actors'), (3, 'Animals')");

        $result = $this->adapter()->run($src, $tgt, ImportOptions::fromPost([
            'source'             => 'enthusiast',
            'conflict_strategy'  => 'skip',
            'import_categories'  => '1',
        ]));

        $rows = $tgt->query("SELECT name, source FROM lstr_categories ORDER BY id")->fetchAll();
        $this->assertCount(3, $rows);
        foreach ($rows as $r) {
            $this->assertSame('tfl', $r['source'], "'{$r['name']}' should be source=tfl");
        }
    }

    public function testRunCategoriesAssignsTaflSourceToKnownTaflCategories(): void
    {
        $src = $this->makePdo();
        $tgt = $this->makePdo();
        $this->seedForCategoryTests($src, $tgt);

        $src->exec("INSERT INTO category (catid, catname) VALUES (1, 'Anime/Manga: Series'), (2, 'Anime/Manga: Characters 0-M')");

        $this->adapter()->run($src, $tgt, ImportOptions::fromPost([
            'source'            => 'enthusiast',
            'conflict_strategy' => 'skip',
            'import_categories' => '1',
        ]));

        $rows = $tgt->query("SELECT source FROM lstr_categories")->fetchAll();
        foreach ($rows as $r) {
            $this->assertSame('tafl', $r['source']);
        }
    }

    public function testRunCategoriesAssignsCustomSourceToUnknownCategories(): void
    {
        $src = $this->makePdo();
        $tgt = $this->makePdo();
        $this->seedForCategoryTests($src, $tgt);

        $src->exec("INSERT INTO category (catid, catname) VALUES (1, 'My Site Categories'), (2, 'Fanfiction')");

        $this->adapter()->run($src, $tgt, ImportOptions::fromPost([
            'source'            => 'enthusiast',
            'conflict_strategy' => 'skip',
            'import_categories' => '1',
        ]));

        $rows = $tgt->query("SELECT source FROM lstr_categories")->fetchAll();
        foreach ($rows as $r) {
            $this->assertSame('custom', $r['source']);
        }
    }

    public function testRunJoinedUsesCorrectNetworkInCategoriesJson(): void
    {
        $src = $this->makePdo();
        $tgt = $this->makePdo();
        $this->seedForCategoryTests($src, $tgt);

        // Pre-seed a TFL category in target (simulating categories already imported)
        $tgt->exec("INSERT INTO lstr_categories (name, source) VALUES ('Actors', 'tfl')");
        $tgt->exec("INSERT INTO lstr_categories (name, source) VALUES ('Anime/Manga: Series', 'tafl')");
        $tgt->exec("INSERT INTO lstr_categories (name, source) VALUES ('My Custom', 'custom')");

        // Source joined listings referencing each category by name
        $src->exec("INSERT INTO category (catid, catname) VALUES (1, 'Actors'), (2, 'Anime/Manga: Series'), (3, 'My Custom')");
        $src->exec("INSERT INTO joined (joinedid, url, subject, catid) VALUES (1, 'http://a.com', 'A', '1')");
        $src->exec("INSERT INTO joined (joinedid, url, subject, catid) VALUES (2, 'http://b.com', 'B', '2')");
        $src->exec("INSERT INTO joined (joinedid, url, subject, catid) VALUES (3, 'http://c.com', 'C', '3')");

        $this->adapter()->run($src, $tgt, ImportOptions::fromPost([
            'source'            => 'enthusiast',
            'conflict_strategy' => 'skip',
            'import_joined'     => '1',
        ]));

        $rows = $tgt->query(
            "SELECT url, categories_json FROM lstr_joined ORDER BY url ASC"
        )->fetchAll();

        $byUrl = array_column($rows, 'categories_json', 'url');

        $aJson = json_decode($byUrl['http://a.com'], true);
        $bJson = json_decode($byUrl['http://b.com'], true);
        $cJson = json_decode($byUrl['http://c.com'], true);

        $this->assertSame('TFL',    $aJson[0]['network'], 'TFL category should use TFL network');
        $this->assertSame('TAFL',   $bJson[0]['network'], 'TAFL category should use TAFL network');
        $this->assertSame('Custom', $cJson[0]['network'], 'Unknown category should use Custom network');
    }

    public function testRunJoinedFullPipelineCategoriesToNetwork(): void
    {
        // Import categories AND joined in one run — network detection must work end-to-end
        $src = $this->makePdo();
        $tgt = $this->makePdo();
        $this->seedForCategoryTests($src, $tgt);

        $src->exec("INSERT INTO category (catid, catname) VALUES (1, 'Albums')");
        $src->exec("INSERT INTO joined (joinedid, url, subject, catid) VALUES (1, 'http://fl.com', 'Test FL', '1')");

        $this->adapter()->run($src, $tgt, ImportOptions::fromPost([
            'source'            => 'enthusiast',
            'conflict_strategy' => 'skip',
            'import_categories' => '1',
            'import_joined'     => '1',
        ]));

        // Category must have source=tfl
        $src_ = $tgt->query("SELECT source FROM lstr_categories WHERE name='Albums'")->fetchColumn();
        $this->assertSame('tfl', $src_);

        // Joined listing must have network=TFL in categories_json
        $json = json_decode(
            $tgt->query("SELECT categories_json FROM lstr_joined WHERE url='http://fl.com'")->fetchColumn(),
            true
        );
        $this->assertSame('TFL', $json[0]['network']);
        $this->assertSame('Albums', $json[0]['name']);
    }

    // -----------------------------------------------------------------------
    // Helpers for settings / templates / affiliates tests
    // -----------------------------------------------------------------------

    /** Creates the lstr_fanlisting_settings table in $pdo (simplified for SQLite). */
    private function createSettingsTable(PDO $pdo): void
    {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS lstr_fanlisting_settings (
                fanlisting_id INTEGER PRIMARY KEY,
                members_per_page INTEGER NOT NULL DEFAULT 25,
                queue_updates INTEGER NOT NULL DEFAULT 0,
                members_template TEXT NULL,
                stats_template TEXT NULL,
                image TEXT NULL,
                listing_type TEXT NULL,
                form_url_join TEXT NOT NULL DEFAULT \'\',
                form_url_update TEXT NOT NULL DEFAULT \'\',
                form_url_reset TEXT NOT NULL DEFAULT \'\',
                description TEXT NULL,
                last_updated DATE NULL
            )'
        );
    }

    /** Creates the lstr_email_templates table in $pdo. */
    private function createEmailTemplatesTable(PDO $pdo): void
    {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS lstr_email_templates (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                fanlisting_id INTEGER NOT NULL,
                type TEXT NOT NULL,
                enabled INTEGER NOT NULL DEFAULT 1,
                subject TEXT NOT NULL DEFAULT \'\',
                body TEXT NOT NULL DEFAULT \'\'
            )'
        );
    }

    /** Creates the lstr_affiliates table in $pdo. */
    private function createAffiliatesTable(PDO $pdo): void
    {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS lstr_affiliates (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                fanlisting_id INTEGER NULL,
                name TEXT NOT NULL DEFAULT \'\',
                url TEXT NOT NULL DEFAULT \'\',
                email TEXT NOT NULL DEFAULT \'\',
                image TEXT NULL,
                sort_order INTEGER NOT NULL DEFAULT 0,
                active INTEGER NOT NULL DEFAULT 1,
                status TEXT NOT NULL DEFAULT \'approved\'
            )'
        );
    }

    /** Creates the owned table in $pdo (target). */
    private function createOwnedTable(PDO $pdo): void
    {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS owned (
                listingid INTEGER PRIMARY KEY AUTOINCREMENT,
                dbtable TEXT NOT NULL DEFAULT \'\',
                title TEXT NOT NULL DEFAULT \'\',
                subject TEXT NOT NULL DEFAULT \'\',
                email TEXT NOT NULL DEFAULT \'\',
                url TEXT NOT NULL DEFAULT \'\',
                catid TEXT NOT NULL DEFAULT \'\',
                additional TEXT NOT NULL DEFAULT \'\',
                status INTEGER NOT NULL DEFAULT 2
            )'
        );
    }

    /** Seed an Enthusiast unprefixed source with the named listing and email template columns. */
    private function seedUnprefixedSourceWithTemplates(
        PDO $pdo,
        int $listingId,
        string $dbtable,
        string $url,
        string $emailsignup   = '',
        string $emailapproved = '',
        string $emailupdate   = '',
        string $emaillostpass = ''
    ): void {
        $pdo->exec("CREATE TABLE IF NOT EXISTS categories (catid INTEGER PRIMARY KEY, catname TEXT)");
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS owned (
                listingid INTEGER PRIMARY KEY,
                dbtable TEXT NOT NULL DEFAULT \'\',
                title TEXT NOT NULL DEFAULT \'\',
                subject TEXT NOT NULL DEFAULT \'\',
                email TEXT NOT NULL DEFAULT \'\',
                url TEXT NOT NULL DEFAULT \'\',
                catid TEXT NOT NULL DEFAULT \'\',
                additional TEXT NOT NULL DEFAULT \'\',
                status INTEGER NOT NULL DEFAULT 2,
                emailsignup TEXT NOT NULL DEFAULT \'\',
                emailapproved TEXT NOT NULL DEFAULT \'\',
                emailupdate TEXT NOT NULL DEFAULT \'\',
                emaillostpass TEXT NOT NULL DEFAULT \'\',
                perpage INTEGER NOT NULL DEFAULT 25,
                holdupdate INTEGER NOT NULL DEFAULT 0
            )'
        );
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS `{$dbtable}` (
                email TEXT PRIMARY KEY, name TEXT NOT NULL DEFAULT '', country TEXT NOT NULL DEFAULT '',
                url TEXT NOT NULL DEFAULT '', pending INTEGER NOT NULL DEFAULT 0,
                password TEXT NOT NULL DEFAULT '', showemail INTEGER NOT NULL DEFAULT 1,
                showurl INTEGER NOT NULL DEFAULT 1, added TEXT NULL
            )"
        );
        $stmt = $pdo->prepare(
            'INSERT INTO owned (listingid, dbtable, url, emailsignup, emailapproved, emailupdate, emaillostpass)
             VALUES (:id, :dbtable, :url, :es, :ea, :eu, :el)'
        );
        $stmt->execute([
            'id' => $listingId, 'dbtable' => $dbtable, 'url' => $url,
            'es' => $emailsignup, 'ea' => $emailapproved, 'eu' => $emailupdate, 'el' => $emaillostpass,
        ]);
    }

    // -----------------------------------------------------------------------
    // Fix 1: Run order — per-listing affiliates imported when listings run first
    // -----------------------------------------------------------------------

    public function testRunAffiliatesImportsPerListingAffiliatesWhenListingsRunFirst(): void
    {
        $src = $this->makePdo();
        $tgt = $this->makePdo();

        // Source: one listing + two affiliates (one per-listing, one collective)
        $this->seedUnprefixedSource($src, 'huntrix');
        $src->exec(
            'CREATE TABLE affiliates (
                affiliateid INTEGER PRIMARY KEY AUTOINCREMENT,
                listingid INTEGER NOT NULL DEFAULT 0,
                title TEXT NOT NULL DEFAULT \'\',
                url TEXT NOT NULL DEFAULT \'\',
                email TEXT NOT NULL DEFAULT \'\',
                imagefile TEXT NOT NULL DEFAULT \'\'
            )'
        );
        $src->exec("INSERT INTO affiliates (listingid, title, url) VALUES (1, 'Per-listing Aff', 'http://aff1.com')");
        $src->exec("INSERT INTO affiliates (listingid, title, url) VALUES (0, 'Collective Aff', 'http://aff2.com')");

        // Target: needs owned + lstr_affiliates tables
        $this->createOwnedTable($tgt);
        $this->createAffiliatesTable($tgt);

        $result = $this->adapter()->run($src, $tgt, ImportOptions::fromPost([
            'source'               => 'enthusiast',
            'conflict_strategy'    => 'skip',
            'import_owned_listings' => '1',
            'import_affiliates'    => '1',
        ]));

        $affRow = null;
        foreach ($result->rows as $row) {
            if ($row['type'] === 'Affiliates') { $affRow = $row; break; }
        }

        $this->assertNotNull($affRow);
        $this->assertSame(2, $affRow['imported'], 'Both affiliates should be imported');
        $this->assertSame(0, $affRow['skipped']);
        $this->assertEmpty($affRow['errors']);

        // The per-listing affiliate must reference listing 1 in target
        $stmt = $tgt->query("SELECT fanlisting_id FROM lstr_affiliates WHERE url = 'http://aff1.com'");
        $this->assertSame(1, (int) $stmt->fetchColumn());

        // The collective affiliate must have NULL fanlisting_id
        $stmt = $tgt->query("SELECT fanlisting_id FROM lstr_affiliates WHERE url = 'http://aff2.com'");
        $this->assertNull($stmt->fetchColumn());
    }

    // -----------------------------------------------------------------------
    // Fix 2: listingIdMap — settings attach to mapped target ID on ID collision
    // -----------------------------------------------------------------------

    public function testRunSettingsUsesListingIdMapWhenIdCollides(): void
    {
        $src = $this->makePdo();
        $tgt = $this->makePdo();

        // Source has listing at id=5
        $this->seedUnprefixedSource($src, 'huntrix');
        $src->exec(
            'ALTER TABLE owned RENAME TO owned_old; CREATE TABLE owned AS SELECT * FROM owned_old;
             DROP TABLE owned_old;'
        );
        // Easier: just re-create with explicit id
        $src->exec('DROP TABLE IF EXISTS owned');
        $src->exec(
            'CREATE TABLE owned (
                listingid INTEGER PRIMARY KEY, dbtable TEXT NOT NULL DEFAULT \'\',
                title TEXT NOT NULL DEFAULT \'\', subject TEXT NOT NULL DEFAULT \'\',
                email TEXT NOT NULL DEFAULT \'\', url TEXT NOT NULL DEFAULT \'\',
                catid TEXT NOT NULL DEFAULT \'\', additional TEXT NOT NULL DEFAULT \'\',
                status INTEGER NOT NULL DEFAULT 2,
                perpage INTEGER NOT NULL DEFAULT 25, holdupdate INTEGER NOT NULL DEFAULT 0
            )'
        );
        $src->exec("INSERT INTO owned (listingid, dbtable, url) VALUES (5, 'huntrix', 'http://src5.com')");

        // Target: pre-existing listing at id=5 with a DIFFERENT URL so new listing gets auto-ID
        $this->createOwnedTable($tgt);
        $tgt->exec("INSERT INTO owned (listingid, dbtable, url) VALUES (5, 'existing', 'http://other.com')");
        $this->createSettingsTable($tgt);

        $result = $this->adapter()->run($src, $tgt, ImportOptions::fromPost([
            'source'               => 'enthusiast',
            'conflict_strategy'    => 'skip',
            'import_owned_listings' => '1',
            'import_settings'      => '1',
        ]));

        $ownedRow = null;
        $settRow  = null;
        foreach ($result->rows as $row) {
            if ($row['type'] === 'Owned listings')   { $ownedRow = $row; }
            if ($row['type'] === 'Listing settings') { $settRow  = $row; }
        }

        $this->assertNotNull($ownedRow);
        $this->assertSame(1, $ownedRow['imported'], 'New listing should be imported');
        $this->assertNotEmpty($ownedRow['warnings'], 'Should warn about ID reassignment');

        // Find the auto-assigned target ID
        $stmt     = $tgt->query("SELECT listingid FROM owned WHERE url = 'http://src5.com'");
        $targetId = (int) $stmt->fetchColumn();
        $this->assertNotSame(5, $targetId, 'New listing should have received a different ID');

        $this->assertNotNull($settRow);
        $this->assertSame(1, $settRow['imported']);

        // Settings must be attached to the NEW (auto-assigned) target ID, not the source ID 5
        $stmt = $tgt->query("SELECT fanlisting_id FROM lstr_fanlisting_settings WHERE fanlisting_id = {$targetId}");
        $this->assertSame($targetId, (int) $stmt->fetchColumn(), 'Settings row must use the mapped target ID');

        $stmt = $tgt->query('SELECT COUNT(*) FROM lstr_fanlisting_settings WHERE fanlisting_id = 5');
        $this->assertSame(0, (int) $stmt->fetchColumn(), 'No settings row should reference the old source ID 5');
    }

    // -----------------------------------------------------------------------
    // Fix 2: listingIdMap — templates attach to mapped target ID on ID collision
    // -----------------------------------------------------------------------

    public function testRunTemplatesUsesListingIdMapWhenIdCollides(): void
    {
        $src = $this->makePdo();
        $tgt = $this->makePdo();

        // Source listing at id=7 with a join email template
        $src->exec('CREATE TABLE IF NOT EXISTS categories (catid INTEGER PRIMARY KEY, catname TEXT)');
        $src->exec(
            'CREATE TABLE owned (
                listingid INTEGER PRIMARY KEY, dbtable TEXT NOT NULL DEFAULT \'\',
                title TEXT NOT NULL DEFAULT \'\', subject TEXT NOT NULL DEFAULT \'\',
                email TEXT NOT NULL DEFAULT \'\', url TEXT NOT NULL DEFAULT \'\',
                catid TEXT NOT NULL DEFAULT \'\', additional TEXT NOT NULL DEFAULT \'\',
                status INTEGER NOT NULL DEFAULT 2,
                emailsignup TEXT NOT NULL DEFAULT \'\', emailapproved TEXT NOT NULL DEFAULT \'\',
                emailupdate TEXT NOT NULL DEFAULT \'\', emaillostpass TEXT NOT NULL DEFAULT \'\'
            )'
        );
        $src->exec("INSERT INTO owned (listingid, dbtable, url, emailsignup)
                    VALUES (7, 't1', 'http://src7.com', 'Welcome to the listing!')");
        $src->exec('CREATE TABLE t1 (email TEXT PRIMARY KEY)');

        // Target: pre-existing listing at id=7 with a different URL
        $this->createOwnedTable($tgt);
        $tgt->exec("INSERT INTO owned (listingid, dbtable, url) VALUES (7, 'other', 'http://other7.com')");
        $this->createEmailTemplatesTable($tgt);

        $result = $this->adapter()->run($src, $tgt, ImportOptions::fromPost([
            'source'               => 'enthusiast',
            'conflict_strategy'    => 'skip',
            'import_owned_listings' => '1',
            'import_templates'     => '1',
        ]));

        // Find the auto-assigned target ID
        $stmt     = $tgt->query("SELECT listingid FROM owned WHERE url = 'http://src7.com'");
        $targetId = (int) $stmt->fetchColumn();
        $this->assertNotSame(7, $targetId);

        // Template must be attached to the new target ID
        $stmt = $tgt->query("SELECT fanlisting_id, body FROM lstr_email_templates WHERE type = 'join'");
        $tplRow = $stmt->fetch();
        $this->assertNotFalse($tplRow, 'Template should have been imported');
        $this->assertSame($targetId, (int) $tplRow['fanlisting_id'], 'Template must use mapped target ID');

        $stmt = $tgt->query('SELECT COUNT(*) FROM lstr_email_templates WHERE fanlisting_id = 7');
        $this->assertSame(0, (int) $stmt->fetchColumn(), 'No template should reference old source ID 7');
    }

    // -----------------------------------------------------------------------
    // Fix 3: Enthusiast $$token$$ translation in email templates
    // -----------------------------------------------------------------------

    public function testRunTemplatesTranslatesEnthusiastDoubledollarTokens(): void
    {
        $src = $this->makePdo();
        $tgt = $this->makePdo();

        $body = 'Hi $$fan_name$$, welcome to $$fanlisting_title$$ at $$fanlisting_url$$. '
              . 'Your email is $$fan_email$$. Reset: $$fan_password$$. Owner: $$owner_name$$.';

        $this->seedUnprefixedSourceWithTemplates(
            $src, 1, 'huntrix', 'http://test.com', emailsignup: $body
        );
        $this->createOwnedTable($tgt);
        $this->createEmailTemplatesTable($tgt);

        $result = $this->adapter()->run($src, $tgt, ImportOptions::fromPost([
            'source'               => 'enthusiast',
            'conflict_strategy'    => 'skip',
            'import_owned_listings' => '1',
            'import_templates'     => '1',
        ]));

        $stmt = $tgt->query("SELECT body FROM lstr_email_templates WHERE type = 'join' LIMIT 1");
        $imported = $stmt->fetchColumn();

        $this->assertStringContainsString('{fan_name}',       $imported);
        $this->assertStringContainsString('{listing_title}',   $imported);
        $this->assertStringContainsString('{listing_url}',    $imported);
        $this->assertStringContainsString('{fan_email}',      $imported);
        $this->assertStringContainsString('{reset_url}',      $imported);
        $this->assertStringContainsString('{owner_name}',     $imported);

        $this->assertStringNotContainsString('$$fan_name$$',       $imported);
        $this->assertStringNotContainsString('$$fanlisting_title$$', $imported);
        $this->assertStringNotContainsString('$$fan_password$$',   $imported);
    }

    public function testRunTemplatesTranslatesAllTokenVariants(): void
    {
        $src = $this->makePdo();
        $tgt = $this->makePdo();

        // Covers every mapping in the translation table, including legacy {password}
        $body = '$$fan_url$$ $$fan_country$$ $$fanlisting_subject$$ $$owner_email$$ $$password$$';

        $this->seedUnprefixedSourceWithTemplates(
            $src, 1, 'huntrix', 'http://test.com', emailsignup: $body
        );
        $this->createOwnedTable($tgt);
        $this->createEmailTemplatesTable($tgt);

        $this->adapter()->run($src, $tgt, ImportOptions::fromPost([
            'source'               => 'enthusiast',
            'conflict_strategy'    => 'skip',
            'import_owned_listings' => '1',
            'import_templates'     => '1',
        ]));

        $stmt     = $tgt->query("SELECT body FROM lstr_email_templates WHERE type = 'join' LIMIT 1");
        $imported = $stmt->fetchColumn();

        $this->assertStringContainsString('{fan_url}',          $imported);
        $this->assertStringContainsString('{fan_country}',      $imported);
        $this->assertStringContainsString('{listing_subject}',  $imported);
        $this->assertStringContainsString('{owner_email}',      $imported);
        $this->assertStringContainsString('{reset_url}',        $imported);

        // No raw Enthusiast tokens remain
        $this->assertStringNotContainsString('$$', $imported);
    }

    public function testRunTemplatesTranslatesLegacyCurlyPasswordToken(): void
    {
        $src = $this->makePdo();
        $tgt = $this->makePdo();

        $this->seedUnprefixedSourceWithTemplates(
            $src, 1, 'huntrix', 'http://test.com',
            emailsignup: 'Your password is {password}.'
        );
        $this->createOwnedTable($tgt);
        $this->createEmailTemplatesTable($tgt);

        $this->adapter()->run($src, $tgt, ImportOptions::fromPost([
            'source'               => 'enthusiast',
            'conflict_strategy'    => 'skip',
            'import_owned_listings' => '1',
            'import_templates'     => '1',
        ]));

        $stmt     = $tgt->query("SELECT body FROM lstr_email_templates WHERE type = 'join' LIMIT 1");
        $imported = $stmt->fetchColumn();

        $this->assertStringContainsString('{reset_url}', $imported);
        $this->assertStringNotContainsString('{password}', $imported);
    }

    // -----------------------------------------------------------------------
    // Fix 1 regression: unlinked affiliate warning text is clear
    // -----------------------------------------------------------------------

    public function testRunAffiliatesReportsUnlinkedCountWithClearMessage(): void
    {
        $src = $this->makePdo();
        $tgt = $this->makePdo();

        // Source: affiliate pointing to listing 99 which does NOT exist in target
        $src->exec('CREATE TABLE IF NOT EXISTS categories (catid INTEGER PRIMARY KEY, catname TEXT)');
        $src->exec(
            'CREATE TABLE owned (listingid INTEGER PRIMARY KEY, dbtable TEXT NOT NULL DEFAULT \'\',
             title TEXT NOT NULL DEFAULT \'\', subject TEXT NOT NULL DEFAULT \'\',
             email TEXT NOT NULL DEFAULT \'\', url TEXT NOT NULL DEFAULT \'\',
             catid TEXT NOT NULL DEFAULT \'\', additional TEXT NOT NULL DEFAULT \'\',
             status INTEGER NOT NULL DEFAULT 2)'
        );
        $src->exec(
            'CREATE TABLE affiliates (
                affiliateid INTEGER PRIMARY KEY AUTOINCREMENT,
                listingid INTEGER NOT NULL DEFAULT 0,
                title TEXT NOT NULL DEFAULT \'\',
                url TEXT NOT NULL DEFAULT \'\',
                email TEXT NOT NULL DEFAULT \'\',
                imagefile TEXT NOT NULL DEFAULT \'\'
            )'
        );
        $src->exec("INSERT INTO affiliates (listingid, title, url) VALUES (99, 'Orphan', 'http://orphan.com')");

        $this->createOwnedTable($tgt);
        $this->createAffiliatesTable($tgt);

        $result = $this->adapter()->run($src, $tgt, ImportOptions::fromPost([
            'source'            => 'enthusiast',
            'conflict_strategy' => 'skip',
            'import_affiliates' => '1',
        ]));

        $affRow = null;
        foreach ($result->rows as $row) {
            if ($row['type'] === 'Affiliates') { $affRow = $row; break; }
        }

        $this->assertNotNull($affRow);
        $this->assertSame(0, $affRow['imported']);
        $this->assertNotEmpty($affRow['warnings']);
        $warningText = implode(' ', $affRow['warnings']);
        $this->assertStringContainsString('1', $warningText);
        $this->assertStringNotContainsString('Import owned listings first', $warningText);
    }

    // -----------------------------------------------------------------------
    // Saved email templates (Erin's fork emailtemplate table)
    // -----------------------------------------------------------------------

    /** Creates the lstr_saved_emails table and the source emailtemplate table. */
    private function seedForSavedTemplateTests(PDO $src, PDO $tgt): void
    {
        $src->exec('CREATE TABLE IF NOT EXISTS category (catid INTEGER PRIMARY KEY, catname TEXT NOT NULL, parent INTEGER NOT NULL DEFAULT 0)');
        $src->exec("CREATE TABLE IF NOT EXISTS `owned` (listingid INTEGER PRIMARY KEY, dbtable TEXT NOT NULL DEFAULT '')");
        $src->exec(
            'CREATE TABLE emailtemplate (
                templateid   INTEGER PRIMARY KEY AUTOINCREMENT,
                templatename TEXT    NOT NULL DEFAULT \'\',
                subject      TEXT    NOT NULL DEFAULT \'\',
                content      TEXT    NOT NULL DEFAULT \'\',
                deletable    INTEGER NOT NULL DEFAULT 1
            )'
        );
        $tgt->exec(
            'CREATE TABLE lstr_saved_emails (
                id         INTEGER PRIMARY KEY AUTOINCREMENT,
                name       TEXT    NOT NULL DEFAULT \'\',
                subject    TEXT    NOT NULL DEFAULT \'\',
                body       TEXT    NOT NULL DEFAULT \'\',
                created_at TEXT    NOT NULL DEFAULT CURRENT_TIMESTAMP
            )'
        );
    }

    public function testSavedTemplatesImportedFromEmailtemplateTable(): void
    {
        $src = $this->makePdo();
        $tgt = $this->makePdo();
        $this->seedForSavedTemplateTests($src, $tgt);

        $src->exec("INSERT INTO emailtemplate (templatename, subject, content)
                    VALUES ('Welcome', 'Welcome!', 'Hi \$\$fan_name\$\$, welcome to \$\$fanlisting_title\$\$.')");
        $src->exec("INSERT INTO emailtemplate (templatename, subject, content)
                    VALUES ('Reminder', 'Just a reminder', 'Your listing is \$\$fanlisting_url\$\$.')");

        $result = $this->adapter()->run($src, $tgt, ImportOptions::fromPost([
            'source'                  => 'enthusiast',
            'conflict_strategy'       => 'skip',
            'import_saved_templates'  => '1',
        ]));

        $savedRow = null;
        foreach ($result->rows as $r) {
            if ($r['type'] === 'Saved email templates') { $savedRow = $r; break; }
        }
        $this->assertNotNull($savedRow);
        $this->assertSame(2, $savedRow['imported']);
        $this->assertSame(0, $savedRow['skipped']);
        $this->assertEmpty($savedRow['errors']);

        $rows = $tgt->query('SELECT name, subject, body FROM lstr_saved_emails ORDER BY id')->fetchAll();
        $this->assertCount(2, $rows);
        $this->assertSame('Welcome',  $rows[0]['name']);
        $this->assertSame('Welcome!', $rows[0]['subject']);
        // Tokens should be translated
        $this->assertStringContainsString('{fan_name}',    $rows[0]['body']);
        $this->assertStringContainsString('{listing_title}', $rows[0]['body']);
        $this->assertStringNotContainsString('$$fan_name$$',       $rows[0]['body']);
        $this->assertStringNotContainsString('$$fanlisting_title$$', $rows[0]['body']);
    }

    public function testSavedTemplatesSkipsExistingNameOnSkipStrategy(): void
    {
        $src = $this->makePdo();
        $tgt = $this->makePdo();
        $this->seedForSavedTemplateTests($src, $tgt);

        $src->exec("INSERT INTO emailtemplate (templatename, subject, content) VALUES ('Existing', 'Subj', 'Body')");
        $tgt->exec("INSERT INTO lstr_saved_emails (name, subject, body) VALUES ('Existing', 'Old Subj', 'Old Body')");

        $result = $this->adapter()->run($src, $tgt, ImportOptions::fromPost([
            'source'                 => 'enthusiast',
            'conflict_strategy'      => 'skip',
            'import_saved_templates' => '1',
        ]));

        $savedRow = null;
        foreach ($result->rows as $r) {
            if ($r['type'] === 'Saved email templates') { $savedRow = $r; break; }
        }
        $this->assertSame(0, $savedRow['imported']);
        $this->assertSame(1, $savedRow['skipped']);

        // Name should be unchanged
        $subj = $tgt->query("SELECT subject FROM lstr_saved_emails WHERE name='Existing'")->fetchColumn();
        $this->assertSame('Old Subj', $subj);
    }

    public function testSavedTemplatesGracefullySkipsWhenTableMissing(): void
    {
        // Standard Enthusiast without the emailtemplate table — should not crash
        $src = $this->makePdo();
        $tgt = $this->makePdo();
        // Do NOT create emailtemplate table in source
        $src->exec('CREATE TABLE IF NOT EXISTS category (catid INTEGER PRIMARY KEY, catname TEXT NOT NULL, parent INTEGER NOT NULL DEFAULT 0)');
        $src->exec("CREATE TABLE IF NOT EXISTS `owned` (listingid INTEGER PRIMARY KEY, dbtable TEXT NOT NULL DEFAULT '')");
        $tgt->exec('CREATE TABLE lstr_saved_emails (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL DEFAULT \'\', subject TEXT NOT NULL DEFAULT \'\', body TEXT NOT NULL DEFAULT \'\', created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)');

        $result = $this->adapter()->run($src, $tgt, ImportOptions::fromPost([
            'source'                 => 'enthusiast',
            'conflict_strategy'      => 'skip',
            'import_saved_templates' => '1',
        ]));

        $savedRow = null;
        foreach ($result->rows as $r) {
            if ($r['type'] === 'Saved email templates') { $savedRow = $r; break; }
        }
        $this->assertNotNull($savedRow);
        $this->assertSame(0, $savedRow['imported']);
        $this->assertEmpty($savedRow['errors']);
        $this->assertNotEmpty($savedRow['warnings'], 'Should warn that table was not found');
    }

    public function testSavedTemplatesTranslatesAllEnthusiastTokens(): void
    {
        $src = $this->makePdo();
        $tgt = $this->makePdo();
        $this->seedForSavedTemplateTests($src, $tgt);

        $body = '$$fan_name$$ $$fan_email$$ $$fan_url$$ $$fan_country$$ '
              . '$$fanlisting_title$$ $$fanlisting_subject$$ $$fanlisting_url$$ '
              . '$$owner_name$$ $$owner_email$$ $$fan_password$$ $$password$$ {password}';

        $src->exec("INSERT INTO emailtemplate (templatename, subject, content) VALUES ('All Tokens', 'Sub', " . $src->quote($body) . ")");

        $this->adapter()->run($src, $tgt, ImportOptions::fromPost([
            'source'                 => 'enthusiast',
            'conflict_strategy'      => 'skip',
            'import_saved_templates' => '1',
        ]));

        $imported = $tgt->query("SELECT body FROM lstr_saved_emails WHERE name='All Tokens'")->fetchColumn();
        $this->assertStringContainsString('{fan_name}',       $imported);
        $this->assertStringContainsString('{fan_email}',      $imported);
        $this->assertStringContainsString('{fan_url}',        $imported);
        $this->assertStringContainsString('{fan_country}',    $imported);
        $this->assertStringContainsString('{listing_title}',   $imported);
        $this->assertStringContainsString('{listing_subject}', $imported);
        $this->assertStringContainsString('{listing_url}',    $imported);
        $this->assertStringContainsString('{owner_name}',     $imported);
        $this->assertStringContainsString('{owner_email}',    $imported);
        $this->assertStringContainsString('{reset_url}',      $imported);
        $this->assertStringNotContainsString('$$', $imported);
        $this->assertStringNotContainsString('{password}', $imported);
    }

    // -----------------------------------------------------------------------
    // opened date and last_updated import
    // -----------------------------------------------------------------------

    /** Creates a target owned table that includes the Luster-extended `opened` column. */
    private function makeTargetWithOpened(): PDO
    {
        $tgt = $this->makePdo();
        $tgt->exec(
            'CREATE TABLE owned (
                listingid  INTEGER PRIMARY KEY AUTOINCREMENT,
                dbtable    TEXT    NOT NULL DEFAULT \'\',
                title      TEXT    NOT NULL DEFAULT \'\',
                subject    TEXT    NOT NULL DEFAULT \'\',
                email      TEXT    NOT NULL DEFAULT \'\',
                url        TEXT    NOT NULL DEFAULT \'\',
                catid      TEXT    NOT NULL DEFAULT \'\',
                additional TEXT    NOT NULL DEFAULT \'\',
                status     INTEGER NOT NULL DEFAULT 2,
                opened     DATE    NULL
            )'
        );
        $tgt->exec('CREATE TABLE IF NOT EXISTS lstr_categories (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, slug TEXT NOT NULL DEFAULT \'\', parent_id INTEGER NULL, source TEXT NOT NULL DEFAULT \'custom\')');
        $tgt->exec('CREATE TABLE IF NOT EXISTS lstr_fanlisting_settings (fanlisting_id INTEGER PRIMARY KEY, members_per_page INTEGER NOT NULL DEFAULT 25, queue_updates INTEGER NOT NULL DEFAULT 0, members_template TEXT NULL, stats_template TEXT NULL, image TEXT NULL, listing_type TEXT NULL, form_url_join TEXT NOT NULL DEFAULT \'\', form_url_update TEXT NOT NULL DEFAULT \'\', form_url_reset TEXT NOT NULL DEFAULT \'\', description TEXT NULL, last_updated DATE NULL)');
        return $tgt;
    }

    public function testRunOwnedListingsImportsOpenedDate(): void
    {
        $src = $this->makePdo();
        $tgt = $this->makeTargetWithOpened();

        // Seed source with unprefixed Enthusiast schema including opened date
        $src->exec('CREATE TABLE IF NOT EXISTS category (catid INTEGER PRIMARY KEY, catname TEXT NOT NULL, parent INTEGER NOT NULL DEFAULT 0)');
        $src->exec(
            'CREATE TABLE owned (
                listingid INTEGER PRIMARY KEY,
                dbtable   TEXT    NOT NULL DEFAULT \'\',
                title     TEXT    NOT NULL DEFAULT \'\',
                subject   TEXT    NOT NULL DEFAULT \'\',
                email     TEXT    NOT NULL DEFAULT \'\',
                url       TEXT    NOT NULL DEFAULT \'\',
                catid     TEXT    NOT NULL DEFAULT \'\',
                additional TEXT   NOT NULL DEFAULT \'\',
                status    INTEGER NOT NULL DEFAULT 2,
                opened    DATE    NULL
            )'
        );
        $src->exec("INSERT INTO owned (listingid, dbtable, url, title, subject, opened)
                    VALUES (1, 'huntrix', 'http://huntrix.example.com', 'HxH', 'HxH', '2019-04-01')");
        $src->exec("CREATE TABLE huntrix (email TEXT PRIMARY KEY)");

        $result = $this->adapter()->run($src, $tgt, ImportOptions::fromPost([
            'source'               => 'enthusiast',
            'conflict_strategy'    => 'skip',
            'import_owned_listings' => '1',
        ]));

        $ownedRow = null;
        foreach ($result->rows as $r) {
            if ($r['type'] === 'Owned listings') { $ownedRow = $r; break; }
        }
        $this->assertNotNull($ownedRow);
        $this->assertSame(1, $ownedRow['imported']);

        $stmt = $tgt->query("SELECT opened FROM owned WHERE url = 'http://huntrix.example.com'");
        $this->assertSame('2019-04-01', $stmt->fetchColumn(), 'opened date must be imported');
    }

    public function testRunOwnedListingsNullOpenedWhenSourceHasNone(): void
    {
        $src = $this->makePdo();
        $tgt = $this->makeTargetWithOpened();

        // Source table without an opened column (standard Enthusiast)
        $src->exec('CREATE TABLE IF NOT EXISTS category (catid INTEGER PRIMARY KEY, catname TEXT NOT NULL, parent INTEGER NOT NULL DEFAULT 0)');
        $src->exec(
            'CREATE TABLE owned (
                listingid INTEGER PRIMARY KEY, dbtable TEXT NOT NULL DEFAULT \'\',
                title TEXT NOT NULL DEFAULT \'\', subject TEXT NOT NULL DEFAULT \'\',
                email TEXT NOT NULL DEFAULT \'\', url TEXT NOT NULL DEFAULT \'\',
                catid TEXT NOT NULL DEFAULT \'\', additional TEXT NOT NULL DEFAULT \'\',
                status INTEGER NOT NULL DEFAULT 2
            )'
        );
        $src->exec("INSERT INTO owned (listingid, dbtable, url, title, subject)
                    VALUES (1, 'fl', 'http://fl.example.com', 'FL', 'FL')");
        $src->exec("CREATE TABLE fl (email TEXT PRIMARY KEY)");

        $this->adapter()->run($src, $tgt, ImportOptions::fromPost([
            'source'               => 'enthusiast',
            'conflict_strategy'    => 'skip',
            'import_owned_listings' => '1',
        ]));

        $stmt = $tgt->query("SELECT opened FROM owned WHERE url = 'http://fl.example.com'");
        $this->assertNull($stmt->fetchColumn(), 'opened must be NULL when source has no date');
    }

    public function testRunSettingsDerivesLastUpdatedFromMemberTable(): void
    {
        $src = $this->makePdo();
        $tgt = $this->makeTargetWithOpened();

        // Source: one listing with member whose most recent join date is 2023-06-15
        $src->exec('CREATE TABLE IF NOT EXISTS category (catid INTEGER PRIMARY KEY, catname TEXT NOT NULL, parent INTEGER NOT NULL DEFAULT 0)');
        $src->exec(
            'CREATE TABLE owned (
                listingid INTEGER PRIMARY KEY, dbtable TEXT NOT NULL DEFAULT \'\',
                title TEXT NOT NULL DEFAULT \'\', subject TEXT NOT NULL DEFAULT \'\',
                email TEXT NOT NULL DEFAULT \'\', url TEXT NOT NULL DEFAULT \'\',
                catid TEXT NOT NULL DEFAULT \'\', additional TEXT NOT NULL DEFAULT \'\',
                status INTEGER NOT NULL DEFAULT 2,
                perpage INTEGER NOT NULL DEFAULT 25, holdupdate INTEGER NOT NULL DEFAULT 0
            )'
        );
        $src->exec("INSERT INTO owned (listingid, dbtable, url, title, subject) VALUES (1, 'fl', 'http://fl.example.com', 'FL', 'FL')");
        $src->exec("CREATE TABLE fl (email TEXT PRIMARY KEY, name TEXT NOT NULL DEFAULT '', added DATE NULL)");
        $src->exec("INSERT INTO fl (email, added) VALUES ('early@test.com', '2021-01-10')");
        $src->exec("INSERT INTO fl (email, added) VALUES ('recent@test.com', '2023-06-15')");

        // Pre-seed a listing in the target so settings can attach to it
        $tgt->exec("INSERT INTO owned (listingid, dbtable, url, title, subject, status)
                    VALUES (1, 'fl', 'http://fl.example.com', 'FL', 'FL', 2)");

        $this->adapter()->run($src, $tgt, ImportOptions::fromPost([
            'source'            => 'enthusiast',
            'conflict_strategy' => 'skip',
            'import_settings'   => '1',
        ]));

        $stmt = $tgt->query('SELECT last_updated FROM lstr_fanlisting_settings WHERE fanlisting_id = 1');
        $lastUpdated = $stmt->fetchColumn();
        $this->assertSame('2023-06-15', $lastUpdated, 'last_updated must be the most recent member join date');
    }

    public function testRunSettingsUsesTodayWhenNoSourceDate(): void
    {
        $src = $this->makePdo();
        $tgt = $this->makeTargetWithOpened();

        $src->exec('CREATE TABLE IF NOT EXISTS category (catid INTEGER PRIMARY KEY, catname TEXT NOT NULL, parent INTEGER NOT NULL DEFAULT 0)');
        $src->exec(
            'CREATE TABLE owned (
                listingid INTEGER PRIMARY KEY, dbtable TEXT NOT NULL DEFAULT \'\',
                url TEXT NOT NULL DEFAULT \'\', title TEXT NOT NULL DEFAULT \'\',
                subject TEXT NOT NULL DEFAULT \'\', email TEXT NOT NULL DEFAULT \'\',
                catid TEXT NOT NULL DEFAULT \'\', additional TEXT NOT NULL DEFAULT \'\',
                status INTEGER NOT NULL DEFAULT 2,
                perpage INTEGER NOT NULL DEFAULT 25, holdupdate INTEGER NOT NULL DEFAULT 0
            )'
        );
        $src->exec("INSERT INTO owned (listingid, dbtable, url, title, subject) VALUES (1, 'empty_fl', 'http://empty.example.com', 'FL', 'FL')");
        $src->exec("CREATE TABLE empty_fl (email TEXT PRIMARY KEY, added DATE NULL)"); // empty table

        $tgt->exec("INSERT INTO owned (listingid, dbtable, url, title, subject, status)
                    VALUES (1, 'empty_fl', 'http://empty.example.com', 'FL', 'FL', 2)");

        $this->adapter()->run($src, $tgt, ImportOptions::fromPost([
            'source'            => 'enthusiast',
            'conflict_strategy' => 'skip',
            'import_settings'   => '1',
        ]));

        $lastUpdated = $tgt->query('SELECT last_updated FROM lstr_fanlisting_settings WHERE fanlisting_id = 1')->fetchColumn();
        // No source date available — must fall back to today so the dashboard staleness
        // queue is never confused by a NULL.
        $this->assertSame(date('Y-m-d'), $lastUpdated,
            'last_updated must be today when no real source date exists (never NULL after import)');
    }

    /**
     * Re-import in skip mode must preserve an existing last_updated value — it should
     * only backfill when the target row has no date at all.
     */
    public function testRunSettingsSkipPreservesExistingDate(): void
    {
        $src = $this->makePdo();
        $tgt = $this->makeTargetWithOpened();

        $src->exec('CREATE TABLE IF NOT EXISTS category (catid INTEGER PRIMARY KEY, catname TEXT NOT NULL, parent INTEGER NOT NULL DEFAULT 0)');
        $src->exec(
            'CREATE TABLE owned (
                listingid INTEGER PRIMARY KEY, dbtable TEXT NOT NULL DEFAULT \'\',
                title TEXT NOT NULL DEFAULT \'\', subject TEXT NOT NULL DEFAULT \'\',
                email TEXT NOT NULL DEFAULT \'\', url TEXT NOT NULL DEFAULT \'\',
                catid TEXT NOT NULL DEFAULT \'\', additional TEXT NOT NULL DEFAULT \'\',
                status INTEGER NOT NULL DEFAULT 2,
                perpage INTEGER NOT NULL DEFAULT 25, holdupdate INTEGER NOT NULL DEFAULT 0
            )'
        );
        $src->exec("INSERT INTO owned (listingid, dbtable, url, title, subject) VALUES (1, 'fl', 'http://fl.example.com', 'FL', 'FL')");
        $src->exec("CREATE TABLE fl (email TEXT PRIMARY KEY, added DATE NULL)");
        $src->exec("INSERT INTO fl (email, added) VALUES ('fan@test.com', '2022-09-01')");

        $tgt->exec("INSERT INTO owned (listingid, dbtable, url, title, subject, status)
                    VALUES (1, 'fl', 'http://fl.example.com', 'FL', 'FL', 2)");
        // Pre-seed a settings row with an existing date (e.g. set by admin or a prior import).
        $tgt->exec("INSERT INTO lstr_fanlisting_settings (fanlisting_id, members_per_page, queue_updates, last_updated)
                    VALUES (1, 25, 0, '2024-05-24')");

        $this->adapter()->run($src, $tgt, ImportOptions::fromPost([
            'source'            => 'enthusiast',
            'conflict_strategy' => 'skip',
            'import_settings'   => '1',
        ]));

        $lastUpdated = $tgt->query('SELECT last_updated FROM lstr_fanlisting_settings WHERE fanlisting_id = 1')->fetchColumn();
        // Skip mode must not overwrite an existing date — the existing value is preserved.
        $this->assertSame('2024-05-24', $lastUpdated,
            'Skip mode must preserve an existing last_updated; only backfill when target is NULL');
    }

    // -----------------------------------------------------------------------
    // Settings — affiliates_section_template wrapping (Migration 53 guard)
    // -----------------------------------------------------------------------

    public function testSettingsAffiliatesTemplateWrappedInEachBlockWhenSectionColumnExists(): void
    {
        $src = $this->makePdo();

        $src->exec('CREATE TABLE IF NOT EXISTS category (catid INTEGER PRIMARY KEY, catname TEXT NOT NULL, parent INTEGER NOT NULL DEFAULT 0)');
        $src->exec(
            'CREATE TABLE owned (
                listingid INTEGER PRIMARY KEY, dbtable TEXT NOT NULL DEFAULT \'\',
                url TEXT NOT NULL DEFAULT \'\', title TEXT NOT NULL DEFAULT \'\',
                subject TEXT NOT NULL DEFAULT \'\', email TEXT NOT NULL DEFAULT \'\',
                catid TEXT NOT NULL DEFAULT \'\', additional TEXT NOT NULL DEFAULT \'\',
                status INTEGER NOT NULL DEFAULT 2,
                perpage INTEGER NOT NULL DEFAULT 25, holdupdate INTEGER NOT NULL DEFAULT 0,
                affiliatestemplate TEXT NOT NULL DEFAULT \'\'
            )'
        );
        $src->exec("INSERT INTO owned (listingid, dbtable, url, affiliatestemplate)
                    VALUES (1, 'fl', 'http://fl.example.com', '<a href=\"\$\$aff_url\$\$\">\$\$aff_image\$\$</a>')");
        $src->exec("CREATE TABLE fl (email TEXT PRIMARY KEY, added DATE NULL)");

        // Target WITH affiliates_section_template column (Migration 53+)
        $tgt = $this->makePdo();
        $tgt->exec(
            'CREATE TABLE owned (
                listingid INTEGER PRIMARY KEY AUTOINCREMENT,
                dbtable TEXT NOT NULL DEFAULT \'\', url TEXT NOT NULL DEFAULT \'\',
                title TEXT NOT NULL DEFAULT \'\', subject TEXT NOT NULL DEFAULT \'\',
                email TEXT NOT NULL DEFAULT \'\', catid TEXT NOT NULL DEFAULT \'\',
                additional TEXT NOT NULL DEFAULT \'\', status INTEGER NOT NULL DEFAULT 2
            )'
        );
        $tgt->exec("INSERT INTO owned (listingid, dbtable, url) VALUES (1, 'fl', 'http://fl.example.com')");
        $tgt->exec(
            'CREATE TABLE lstr_fanlisting_settings (
                fanlisting_id INTEGER PRIMARY KEY,
                members_per_page INTEGER NOT NULL DEFAULT 25,
                queue_updates INTEGER NOT NULL DEFAULT 0,
                members_template TEXT NULL,
                stats_template TEXT NULL,
                image TEXT NULL,
                listing_type TEXT NULL,
                form_url_join TEXT NOT NULL DEFAULT \'\',
                form_url_update TEXT NOT NULL DEFAULT \'\',
                form_url_reset TEXT NOT NULL DEFAULT \'\',
                description TEXT NULL,
                affiliates_section_template TEXT NULL
            )'
        );

        $this->adapter()->run($src, $tgt, ImportOptions::fromPost([
            'source'            => 'enthusiast',
            'conflict_strategy' => 'skip',
            'import_settings'   => '1',
        ]));

        $row = $tgt->query('SELECT affiliates_section_template FROM lstr_fanlisting_settings WHERE fanlisting_id = 1')
                   ->fetch(\PDO::FETCH_ASSOC);

        $this->assertStringContainsString('{each_affiliate}', (string) $row['affiliates_section_template'],
            'affiliates_section_template must be wrapped in {each_affiliate} block');
        $this->assertStringContainsString('{/each_affiliate}', (string) $row['affiliates_section_template']);
        $this->assertStringContainsString('{aff_url}', (string) $row['affiliates_section_template'],
            'Enthusiast $$aff_url$$ must be translated to {aff_url}');
        $this->assertStringContainsString('{aff_image}', (string) $row['affiliates_section_template'],
            'Enthusiast $$aff_image$$ must be translated to {aff_image}');
    }

    public function testAffImageUrlInSrcReplacedBeforeTokenSubstitution(): void
    {
        // Enthusiast's default affiliate row template puts $$aff_image$$ inside an <img> src=""
        // attribute: <img src="$$aff_url$$images/$$aff_image$$" width="$$aff_width$$" ...>.
        // Simple token-by-token replacement would inject a full <img> element into the src
        // attribute, whose quotes break the surrounding HTML. The pre-processing regex in
        // translateDisplayTokens() must replace the entire <img> element first.
        $src = $this->makePdo();
        $src->exec('CREATE TABLE IF NOT EXISTS category (catid INTEGER PRIMARY KEY, catname TEXT NOT NULL, parent INTEGER NOT NULL DEFAULT 0)');
        $src->exec(
            'CREATE TABLE owned (
                listingid INTEGER PRIMARY KEY, dbtable TEXT NOT NULL DEFAULT \'\',
                url TEXT NOT NULL DEFAULT \'\', title TEXT NOT NULL DEFAULT \'\',
                subject TEXT NOT NULL DEFAULT \'\', email TEXT NOT NULL DEFAULT \'\',
                catid TEXT NOT NULL DEFAULT \'\', additional TEXT NOT NULL DEFAULT \'\',
                status INTEGER NOT NULL DEFAULT 2,
                perpage INTEGER NOT NULL DEFAULT 25, holdupdate INTEGER NOT NULL DEFAULT 0,
                affiliatestemplate TEXT NOT NULL DEFAULT \'\'
            )'
        );
        $enthTpl = '<a href="$$aff_url$$" title="$$aff_title$$">'
                 . '<img src="$$aff_url$$images/$$aff_image$$" width="$$aff_width$$" height="$$aff_height$$" border="0" alt=" $$aff_title$$" />'
                 . '</a>';
        $stmt = $src->prepare("INSERT INTO owned (listingid, dbtable, url, affiliatestemplate) VALUES (1, 'fl', 'http://fl.example.com', ?)");
        $stmt->execute([$enthTpl]);
        $src->exec("CREATE TABLE fl (email TEXT PRIMARY KEY, added DATE NULL)");

        $tgt = $this->makePdo();
        $tgt->exec(
            'CREATE TABLE owned (
                listingid INTEGER PRIMARY KEY AUTOINCREMENT,
                dbtable TEXT NOT NULL DEFAULT \'\', url TEXT NOT NULL DEFAULT \'\',
                title TEXT NOT NULL DEFAULT \'\', subject TEXT NOT NULL DEFAULT \'\',
                email TEXT NOT NULL DEFAULT \'\', catid TEXT NOT NULL DEFAULT \'\',
                additional TEXT NOT NULL DEFAULT \'\', status INTEGER NOT NULL DEFAULT 2
            )'
        );
        $tgt->exec("INSERT INTO owned (listingid, dbtable, url) VALUES (1, 'fl', 'http://fl.example.com')");
        $tgt->exec(
            'CREATE TABLE lstr_fanlisting_settings (
                fanlisting_id INTEGER PRIMARY KEY,
                members_per_page INTEGER NOT NULL DEFAULT 25,
                queue_updates INTEGER NOT NULL DEFAULT 0,
                members_template TEXT NULL,
                stats_template TEXT NULL,
                image TEXT NULL,
                listing_type TEXT NULL,
                form_url_join TEXT NOT NULL DEFAULT \'\',
                form_url_update TEXT NOT NULL DEFAULT \'\',
                form_url_reset TEXT NOT NULL DEFAULT \'\',
                description TEXT NULL,
                affiliates_section_template TEXT NULL
            )'
        );

        $this->adapter()->run($src, $tgt, ImportOptions::fromPost([
            'source'            => 'enthusiast',
            'conflict_strategy' => 'skip',
            'import_settings'   => '1',
        ]));

        $tpl = (string) $tgt->query('SELECT affiliates_section_template FROM lstr_fanlisting_settings WHERE fanlisting_id = 1')
                             ->fetch(\PDO::FETCH_ASSOC)['affiliates_section_template'];

        $this->assertStringContainsString('{aff_image}', $tpl,
            'Entire <img> with $$aff_image$$ in src must be replaced with {aff_image}');
        $this->assertStringNotContainsString('$$aff_width$$', $tpl,
            '$$aff_width$$ must be removed (no Luster equivalent)');
        $this->assertStringNotContainsString('$$aff_height$$', $tpl,
            '$$aff_height$$ must be removed (no Luster equivalent)');
        // {aff_image} (the whole <img> tag token) must NOT appear inside a src="" attribute
        $this->assertDoesNotMatchRegularExpression(
            '/<img\b[^>]*src=["\'][^"\']*\{aff_image\}/i',
            $tpl,
            '{aff_image} must not appear inside an <img> src attribute',
        );
    }

    public function testAffTitleTokenTranslatedOnOverwrite(): void
    {
        // Enthusiast's default affiliates template uses $$aff_title$$ for the site name.
        // On an overwrite import, translateDisplayTokens() must convert it to {aff_name}.
        $src = $this->makePdo();
        $src->exec('CREATE TABLE IF NOT EXISTS category (catid INTEGER PRIMARY KEY, catname TEXT NOT NULL, parent INTEGER NOT NULL DEFAULT 0)');
        $src->exec(
            'CREATE TABLE owned (
                listingid INTEGER PRIMARY KEY, dbtable TEXT NOT NULL DEFAULT \'\',
                url TEXT NOT NULL DEFAULT \'\', title TEXT NOT NULL DEFAULT \'\',
                subject TEXT NOT NULL DEFAULT \'\', email TEXT NOT NULL DEFAULT \'\',
                catid TEXT NOT NULL DEFAULT \'\', additional TEXT NOT NULL DEFAULT \'\',
                status INTEGER NOT NULL DEFAULT 2,
                perpage INTEGER NOT NULL DEFAULT 25, holdupdate INTEGER NOT NULL DEFAULT 0,
                affiliatestemplate TEXT NOT NULL DEFAULT \'\'
            )'
        );
        $src->exec("INSERT INTO owned (listingid, dbtable, url, affiliatestemplate)
                    VALUES (1, 'fl', 'http://fl.example.com/', '<a href=\"\$\$aff_url\$\$\">\$\$aff_title\$\$</a>')");
        $src->exec("CREATE TABLE fl (email TEXT PRIMARY KEY, added DATE NULL)");

        $tgt = $this->makePdo();
        $tgt->exec(
            'CREATE TABLE owned (
                listingid INTEGER PRIMARY KEY AUTOINCREMENT,
                dbtable TEXT NOT NULL DEFAULT \'\', url TEXT NOT NULL DEFAULT \'\',
                title TEXT NOT NULL DEFAULT \'\', subject TEXT NOT NULL DEFAULT \'\',
                email TEXT NOT NULL DEFAULT \'\', catid TEXT NOT NULL DEFAULT \'\',
                additional TEXT NOT NULL DEFAULT \'\', status INTEGER NOT NULL DEFAULT 2
            )'
        );
        $tgt->exec("INSERT INTO owned (listingid, dbtable, url) VALUES (1, 'fl', 'http://fl.example.com/')");
        $tgt->exec(
            'CREATE TABLE lstr_fanlisting_settings (
                fanlisting_id INTEGER PRIMARY KEY,
                members_per_page INTEGER NOT NULL DEFAULT 25,
                queue_updates INTEGER NOT NULL DEFAULT 0,
                members_template TEXT NULL,
                stats_template TEXT NULL,
                image TEXT NULL,
                listing_type TEXT NULL,
                form_url_join TEXT NOT NULL DEFAULT \'\',
                form_url_update TEXT NOT NULL DEFAULT \'\',
                form_url_reset TEXT NOT NULL DEFAULT \'\',
                description TEXT NULL,
                affiliates_section_template TEXT NULL
            )'
        );
        // Pre-insert an existing row so the overwrite path is exercised.
        $tgt->exec("INSERT INTO lstr_fanlisting_settings (fanlisting_id) VALUES (1)");

        $this->adapter()->run($src, $tgt, ImportOptions::fromPost([
            'source'            => 'enthusiast',
            'conflict_strategy' => 'overwrite',
            'import_settings'   => '1',
        ]));

        $row = $tgt->query('SELECT affiliates_section_template FROM lstr_fanlisting_settings WHERE fanlisting_id = 1')
                   ->fetch(\PDO::FETCH_ASSOC);

        $tpl = (string) $row['affiliates_section_template'];
        $this->assertStringContainsString('{aff_name}', $tpl,
            'Enthusiast $$aff_title$$ must be translated to {aff_name} on overwrite');
        $this->assertStringNotContainsString('$$aff_title$$', $tpl,
            'Raw $$aff_title$$ must not survive the overwrite import');
        $this->assertStringContainsString('{aff_url}', $tpl,
            'Enthusiast $$aff_url$$ must be translated to {aff_url} on overwrite');
    }
}
