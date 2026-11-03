<?php

declare(strict_types=1);

namespace Luster\Tests\Unit;

use Luster\Controllers\Admin\LinkCheckerController;
use Luster\Core\BackupService;
use Luster\Core\Config;
use Luster\Core\Database;
use Luster\Models\AdditionalField;
use Luster\Repositories\CollectiveStatsRepo;
use Luster\Repositories\FieldRepo;
use Luster\Repositories\ListingRepo;
use Luster\Tests\Unit\Support\SqliteDatabase;
use PHPUnit\Framework\TestCase;

/**
 * On the standard Enthusiast layout (enth_table_prefix = 'enth_') the listing
 * registry is enth_fanlistings, the table the importers write and Migration 8
 * alters. Every reader has to find it there: ListingRepo, FieldRepo,
 * CollectiveStatsRepo, BackupService, the Link Checker's affiliate sort, and
 * Migrations 51 and 106. They used to build "enth_listings", which nothing
 * creates.
 */
final class EnthRegistryTableTest extends TestCase
{
    private const ENTH_DDL = <<<SQL
CREATE TABLE enth_fanlistings (
    id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL DEFAULT '', subject TEXT NOT NULL DEFAULT '',
    url TEXT NOT NULL DEFAULT '', email TEXT NOT NULL DEFAULT '', dbprefix TEXT NOT NULL DEFAULT '',
    additional_info TEXT NOT NULL DEFAULT '', category_id INTEGER NULL,
    upcoming INTEGER NOT NULL DEFAULT 0, trouble INTEGER NOT NULL DEFAULT 0, opened TEXT NULL
);
CREATE TABLE fl_moon_members (
    email TEXT NOT NULL PRIMARY KEY, name TEXT NOT NULL DEFAULT '', country TEXT NOT NULL DEFAULT '',
    url TEXT NOT NULL DEFAULT '', approved INTEGER NOT NULL DEFAULT 0, password TEXT NOT NULL DEFAULT ''
);
CREATE TABLE lstr_affiliates (
    id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL DEFAULT '', fanlisting_id INTEGER NULL
);
CREATE TABLE unrelated (id INTEGER)
SQL;

    private Database $db;

    protected function setUp(): void
    {
        $this->db = SqliteDatabase::make(self::ENTH_DDL);
        $pdo      = $this->db->pdo();
        $pdo->exec("INSERT INTO enth_fanlistings (id, name, subject, url, dbprefix, additional_info, upcoming, trouble)
                    VALUES (1, 'Moon FL', 'The Moon', 'https://fans.test/moon/', 'fl_moon_', 'song,Favourite song,text,0', 0, 0),
                           (2, 'Sun FL', 'The Sun', 'https://fans.test/sun/', 'fl_sun_', '', 1, 0)");
        $pdo->exec("INSERT INTO fl_moon_members (email, approved) VALUES ('a@fans.test', 1), ('b@fans.test', 1), ('c@fans.test', 0)");
    }

    private function config(string $enthTablePrefix): Config
    {
        $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'lstr_enth_cfg_' . uniqid('', true) . '.php';
        file_put_contents($path, '<?php return ' . var_export(['enth_table_prefix' => $enthTablePrefix], true) . ';');
        $config = new Config($path);
        unlink($path);
        return $config;
    }

    public function testListingRepoReadsTheListings(): void
    {
        $repo = new ListingRepo($this->db, $this->config('enth_'));

        self::assertSame('Moon FL', $repo->getById(1)?->title);
        self::assertSame(['The Moon', 'The Sun'], array_map(static fn($l): string => $l->subject, $repo->getAll()));
    }

    public function testFieldRepoReadsAndSavesTheFieldDefinitions(): void
    {
        $repo = new FieldRepo($this->db, $this->config('enth_'));

        $fields = $repo->getFieldsForListing(1);
        self::assertSame([['song', 'Favourite song']], array_map(static fn(AdditionalField $f): array => [$f->name, $f->label], $fields));
        self::assertSame([1, 2], array_keys($repo->getAllFields()));

        $repo->saveFields(2, [['name' => 'ray', 'label' => 'Ray', 'type' => 'text', 'required' => false]]);
        self::assertSame(['ray'], array_map(static fn(AdditionalField $f): string => $f->name, $repo->getFieldsForListing(2)));
        self::assertStringContainsString('ray', (string) $this->db->pdo()->query('SELECT additional_info FROM enth_fanlistings WHERE id = 2')->fetchColumn(),
            'Saved where it was read from');
    }

    public function testCollectiveStatsCountTheListingsAndTheirMembers(): void
    {
        $repo = new CollectiveStatsRepo($this->db, $this->config('enth_'));

        self::assertSame(['total' => 2, 'active' => 1, 'upcoming' => 1, 'trouble' => 0], $repo->getOwnedCounts());
        self::assertSame(['approved' => 2, 'pending' => 1, 'total' => 3], $repo->getMemberTotals(),
            "Found through the registry's dbprefix (fl_sun_members doesn't exist and is skipped)");
    }

    public function testBackupsIncludeTheRegistryAndItsMemberTables(): void
    {
        $this->db->pdo()->exec('CREATE TABLE lstr_site_settings (id INTEGER)');

        self::assertSame(['enth_fanlistings', 'fl_moon_members', 'lstr_affiliates', 'lstr_site_settings'], $this->backupTables('enth_'));
    }

    public function testBackupsStillUseOwnedOnTheUnprefixedLayout(): void
    {
        $db = SqliteDatabase::make("CREATE TABLE owned (listingid INTEGER PRIMARY KEY, dbtable TEXT NOT NULL DEFAULT '');
                                    CREATE TABLE moon (email TEXT); CREATE TABLE enth_fanlistings (id INTEGER)");
        $db->pdo()->exec("INSERT INTO owned (listingid, dbtable) VALUES (1, 'moon')");

        self::assertSame(['moon', 'owned'], $this->backupTables('', $db));
    }

    public function testTheLinkCheckerSortsAffiliatesByTheirListing(): void
    {
        $pdo = $this->db->pdo();
        $pdo->exec("INSERT INTO lstr_affiliates (name, fanlisting_id) VALUES ('Starry', 2), ('Lunar', 1)");

        $sub = $this->affiliateListingSubject('enth_');
        self::assertStringContainsString('`enth_fanlistings`', $sub);
        self::assertSame(['Lunar', 'Starry'], $pdo->query("SELECT name FROM lstr_affiliates ORDER BY {$sub} ASC")->fetchAll(\PDO::FETCH_COLUMN),
            'The Moon before The Sun');

        $owned = SqliteDatabase::make("CREATE TABLE owned (listingid INTEGER PRIMARY KEY, subject TEXT);
                                       CREATE TABLE lstr_affiliates (id INTEGER PRIMARY KEY, name TEXT, fanlisting_id INTEGER)")->pdo();
        $owned->exec("INSERT INTO owned VALUES (1, 'B'), (2, 'A'); INSERT INTO lstr_affiliates (name, fanlisting_id) VALUES ('one', 1), ('two', 2)");
        $sub = $this->affiliateListingSubject('');
        self::assertSame(['two', 'one'], $owned->query("SELECT name FROM lstr_affiliates ORDER BY {$sub} ASC")->fetchAll(\PDO::FETCH_COLUMN));
    }

    public function testMigrations51And106ReadTheSameRegistry(): void
    {
        // The migration page is a standalone script, so its source is checked:
        // its enth_ branches name enth_fanlistings and never build the old name.
        $source = (string) file_get_contents(__DIR__ . '/../../public/migrate/index.php');
        foreach ([['// Migration 51:', '// Migration 52:'], ['// --- Migration 106:', '// --- Migration 107:']] as [$from, $to]) {
            $start = strpos($source, $from);
            $end   = strpos($source, $to);
            self::assertNotFalse($start, "{$from} not found");
            self::assertNotFalse($end, "{$to} not found");
            $block = substr($source, (int) $start, (int) $end - (int) $start);
            self::assertStringContainsString('`enth_fanlistings`', $block, $from);
        }
        self::assertDoesNotMatchRegularExpression("/(Prefix|prefix) \\. 'listings'|\\{\\\$[a-zA-Z]*[Pp]refix\\}listings/", $source);
    }

    /** @return list<string> */
    private function backupTables(string $enthTablePrefix, ?Database $db = null): array
    {
        $db    ??= $this->db;
        $service = new BackupService($db, sys_get_temp_dir(), $enthTablePrefix);
        $method  = new \ReflectionMethod($service, 'lusterTableNames');
        $method->setAccessible(true);
        return $method->invoke($service, $db->pdo());
    }

    private function affiliateListingSubject(string $enthTablePrefix): string
    {
        $method = new \ReflectionMethod(LinkCheckerController::class, 'affiliateListingSubject');
        $method->setAccessible(true);
        return (string) $method->invoke(null, $enthTablePrefix);
    }
}
