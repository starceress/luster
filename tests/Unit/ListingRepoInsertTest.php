<?php

declare(strict_types=1);

namespace Luster\Tests\Unit;

use Luster\Core\Config;
use Luster\Core\Database;
use Luster\Repositories\ListingRepo;
use Luster\Tests\Unit\Support\SqliteDatabase;
use PHPUnit\Framework\TestCase;

/**
 * Regression tests for ListingRepo::insert() and provisionMemberTable()
 * against the real `owned` schema (install/index.php).
 *
 * insert(): specifically `additional` TEXT NOT NULL with no DEFAULT. MySQL
 * in strict mode (the default on many modern hosts) rejects an INSERT that
 * omits a NOT NULL column with no default; SQLite enforces the same NOT
 * NULL constraint. A prior version of insert() omitted `additional` from
 * its column list entirely, which passed local testing only because other
 * ListingRepo test fixtures added a DEFAULT '' that the real installer
 * schema does not have.
 *
 * provisionMemberTable(): a listing created via insert() alone has
 * dbtable = '' — nowhere to store members — which crashes the join form
 * (Database::validatePrefix() rejects an empty string). Nothing else in
 * the codebase generated a table slug or created a member table for a
 * freshly created (non-imported) listing on the unprefixed schema.
 */
final class ListingRepoInsertTest extends TestCase
{
    private const DDL = <<<SQL
CREATE TABLE owned (
    listingid   INTEGER PRIMARY KEY AUTOINCREMENT,
    title       TEXT    NOT NULL DEFAULT '',
    subject     TEXT    NOT NULL DEFAULT '',
    url         TEXT    NOT NULL DEFAULT '',
    email       TEXT    NOT NULL DEFAULT '',
    status      INTEGER NOT NULL DEFAULT 2,
    imagefile   TEXT    NULL,
    catid       TEXT    NOT NULL DEFAULT '',
    category_id INTEGER NULL,
    opened      TEXT    NULL,
    dbtable     TEXT    NOT NULL DEFAULT '',
    dbserver    TEXT    NOT NULL DEFAULT '',
    dbuser      TEXT    NOT NULL DEFAULT '',
    dbpassword  TEXT    NOT NULL DEFAULT '',
    dbdatabase  TEXT    NOT NULL DEFAULT '',
    additional  TEXT    NOT NULL
)
SQL;

    private Database $db;
    private ListingRepo $repo;

    protected function setUp(): void
    {
        $this->db   = SqliteDatabase::make(self::DDL);
        $this->repo = new ListingRepo($this->db, $this->makeConfig([]));
    }

    public function testInsertSucceedsAgainstRealNoDefaultAdditionalColumn(): void
    {
        $newId = $this->repo->insert(
            'Ash Ketchum',
            'Ash Ketchum',
            'https://example.com',
            'demo@example.com',
            2,
            null,
            null,
            null,
        );

        self::assertGreaterThan(0, $newId);
    }

    // -----------------------------------------------------------------------
    // provisionMemberTable() — a fresh listing has nowhere to store members
    // until it has its own physical table; insert() alone leaves dbtable
    // blank, which crashes the join form (Database::validatePrefix() rejects
    // an empty string). provisionMemberTable() generates a slug, creates the
    // table, and points dbtable at it.
    // -----------------------------------------------------------------------

    public function testProvisionMemberTableSetsDbtableAndCreatesTable(): void
    {
        $newId = $this->repo->insert('Ash Ketchum', 'Ash Ketchum', 'https://example.com', 'demo@example.com', 2, null, null, null);
        $this->repo->provisionMemberTable($newId, 'Ash Ketchum');

        $pdo = $this->db->pdo();
        $dbtable = $pdo->query("SELECT dbtable FROM owned WHERE listingid = {$newId}")->fetchColumn();

        self::assertSame('ash_ketchum', $dbtable);

        $tableExists = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='ash_ketchum'")->fetchColumn();
        self::assertSame('ash_ketchum', $tableExists);

        // Table must actually accept a member row (email PK + expected columns)
        $pdo->exec("INSERT INTO ash_ketchum (email, name, country, url, pending, password, showemail, showurl, added) VALUES ('ash@example.com', 'Ash Ketchup', 'US', '', 0, '', 1, 1, NULL)");
        $count = $pdo->query("SELECT COUNT(*) FROM ash_ketchum")->fetchColumn();
        self::assertSame(1, (int) $count);
    }

    public function testProvisionMemberTableAvoidsCollisionOnDuplicateSubject(): void
    {
        $id1 = $this->repo->insert('Ash Ketchum', 'Ash Ketchum', 'https://example.com/1', 'a@example.com', 2, null, null, null);
        $this->repo->provisionMemberTable($id1, 'Ash Ketchum');

        $id2 = $this->repo->insert('Ash Ketchum', 'Ash Ketchum', 'https://example.com/2', 'b@example.com', 2, null, null, null);
        $this->repo->provisionMemberTable($id2, 'Ash Ketchum');

        $pdo = $this->db->pdo();
        $t1 = $pdo->query("SELECT dbtable FROM owned WHERE listingid = {$id1}")->fetchColumn();
        $t2 = $pdo->query("SELECT dbtable FROM owned WHERE listingid = {$id2}")->fetchColumn();

        self::assertSame('ash_ketchum', $t1);
        self::assertSame('ash_ketchum_2', $t2);
        self::assertNotSame($t1, $t2);
    }

    public function testProvisionMemberTableFallsBackWhenSubjectSanitizesToNothing(): void
    {
        $newId = $this->repo->insert('特撮', '特撮', 'https://example.com', 'demo@example.com', 2, null, null, null);
        $this->repo->provisionMemberTable($newId, '特撮');

        $pdo = $this->db->pdo();
        $dbtable = $pdo->query("SELECT dbtable FROM owned WHERE listingid = {$newId}")->fetchColumn();

        self::assertSame('listing', $dbtable);
    }

    public function testProvisionMemberTableIsNoOpForPrefixedSchema(): void
    {
        $prefixedRepo = new ListingRepo($this->db, $this->makeConfig(['enth_table_prefix' => 'enth_']));

        // No enth_fanlistings table exists in this fixture at all — if
        // provisionMemberTable() tried to UPDATE it, this would throw.
        // Calling it should simply no-op and return without error.
        $prefixedRepo->provisionMemberTable(1, 'Ash Ketchum');
        self::assertTrue(true);
    }

    private function makeConfig(array $values): Config
    {
        $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'lstr_test_cfg_' . uniqid('', true) . '.php';
        file_put_contents($path, '<?php return ' . var_export($values, true) . ';');
        $config = new Config($path);
        unlink($path);
        return $config;
    }
}
