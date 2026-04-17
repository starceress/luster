<?php

declare(strict_types=1);

namespace Luster\Tests\Unit;

use Luster\Core\Config;
use Luster\Core\Database;
use Luster\Repositories\MemberRepo;
use Luster\Tests\Unit\Support\SqliteDatabase;
use PDO;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * Tests MemberRepo::getMemberStats() for both Enthusiast schema variants.
 *
 * Uses in-memory SQLite via SqliteDatabase; injects a Config with the
 * correct enth_table_prefix for each variant.
 */
final class MemberStatsTest extends TestCase
{
    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    /**
     * Builds a Database backed by in-memory SQLite with the given DDL,
     * and a Config that has $configValues as its data.
     *
     * @param  array<string,mixed> $configValues
     */
    private static function makeWithConfig(string $ddl, array $configValues): array
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        foreach (array_filter(array_map('trim', explode(';', $ddl))) as $stmt) {
            $pdo->exec($stmt);
        }

        // Build a Config from a temp file containing $configValues
        $export  = var_export($configValues, true);
        $tmpCfg  = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'lstr_test_cfg_' . uniqid('', true) . '.php';
        file_put_contents($tmpCfg, "<?php return {$export};");
        $config  = new Config($tmpCfg);
        unlink($tmpCfg);

        $tmpCfg2 = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'lstr_test_db_' . uniqid('', true) . '.php';
        file_put_contents($tmpCfg2, '<?php return [];');
        $dbConfig = new Config($tmpCfg2);
        unlink($tmpCfg2);

        $db   = new Database($dbConfig);
        $prop = new ReflectionProperty(Database::class, 'pdo');
        $prop->setAccessible(true);
        $prop->setValue($db, $pdo);

        return [$db, $config, $pdo];
    }

    // -----------------------------------------------------------------------
    // Unprefixed schema (enth_table_prefix = '')
    // pending=0 means approved; table name = dbtable directly
    // -----------------------------------------------------------------------

    public function testUnprefixedSchemaCountsApprovedAndCountries(): void
    {
        $ddl = <<<SQL
CREATE TABLE huntrix (
    email   TEXT NOT NULL,
    name    TEXT NOT NULL DEFAULT '',
    country TEXT NOT NULL DEFAULT '',
    pending INTEGER NOT NULL DEFAULT 0
)
SQL;
        [$db, $config, $pdo] = self::makeWithConfig($ddl, ['enth_table_prefix' => '']);

        $pdo->exec("INSERT INTO huntrix VALUES ('a@test.com', 'Alice', 'US', 0)");  // approved
        $pdo->exec("INSERT INTO huntrix VALUES ('b@test.com', 'Bob',   'JP', 0)");  // approved
        $pdo->exec("INSERT INTO huntrix VALUES ('c@test.com', 'Carol', 'US', 0)");  // approved, same country as Alice
        $pdo->exec("INSERT INTO huntrix VALUES ('d@test.com', 'Dave',  'CA', 1)");  // pending — must NOT be counted

        $repo  = new MemberRepo($db, $config);
        $stats = $repo->getMemberStats('huntrix');

        self::assertSame(3, $stats['approved'],      'Approved count should be 3');
        self::assertSame(2, $stats['country_count'], 'Country count should be 2 (US, JP; CA is pending)');
    }

    public function testUnprefixedSchemaEmptyTable(): void
    {
        $ddl = 'CREATE TABLE empty_fl (email TEXT NOT NULL, name TEXT, country TEXT NOT NULL DEFAULT \'\', pending INTEGER NOT NULL DEFAULT 0)';
        [$db, $config] = self::makeWithConfig($ddl, ['enth_table_prefix' => '']);

        $repo  = new MemberRepo($db, $config);
        $stats = $repo->getMemberStats('empty_fl');

        self::assertSame(0, $stats['approved']);
        self::assertSame(0, $stats['country_count']);
    }

    public function testUnprefixedSchemaBlankCountriesNotCounted(): void
    {
        $ddl = 'CREATE TABLE noctry (email TEXT NOT NULL, name TEXT, country TEXT NOT NULL DEFAULT \'\', pending INTEGER NOT NULL DEFAULT 0)';
        [$db, $config, $pdo] = self::makeWithConfig($ddl, ['enth_table_prefix' => '']);

        $pdo->exec("INSERT INTO noctry VALUES ('a@test.com', 'Alice', '',   0)");  // blank country
        $pdo->exec("INSERT INTO noctry VALUES ('b@test.com', 'Bob',   '',   0)");  // blank country
        $pdo->exec("INSERT INTO noctry VALUES ('c@test.com', 'Carol', 'DE', 0)");  // one real country

        $repo  = new MemberRepo($db, $config);
        $stats = $repo->getMemberStats('noctry');

        self::assertSame(3, $stats['approved']);
        self::assertSame(1, $stats['country_count'], 'Blank countries must not be counted');
    }

    // -----------------------------------------------------------------------
    // Standard enth_ schema (enth_table_prefix = 'enth_')
    // approved=1 means approved; table name = dbprefix + 'members'
    // -----------------------------------------------------------------------

    public function testEnthPrefixedSchemaResolvesTableName(): void
    {
        // MemberRepo appends 'members' when enth_table_prefix is non-empty,
        // so dbprefix 'fl_music_' → table 'fl_music_members'
        $ddl = <<<SQL
CREATE TABLE fl_music_members (
    email    TEXT NOT NULL,
    name     TEXT NOT NULL DEFAULT '',
    country  TEXT NOT NULL DEFAULT '',
    approved INTEGER NOT NULL DEFAULT 0
)
SQL;
        [$db, $config, $pdo] = self::makeWithConfig($ddl, ['enth_table_prefix' => 'enth_']);

        $pdo->exec("INSERT INTO fl_music_members VALUES ('x@test.com', 'Xavier', 'FR', 1)");  // approved
        $pdo->exec("INSERT INTO fl_music_members VALUES ('y@test.com', 'Yuki',   'JP', 1)");  // approved
        $pdo->exec("INSERT INTO fl_music_members VALUES ('z@test.com', 'Zara',   'FR', 0)");  // not approved

        $repo  = new MemberRepo($db, $config);
        $stats = $repo->getMemberStats('fl_music_');

        self::assertSame(2, $stats['approved'],      'Only approved=1 rows count');
        self::assertSame(2, $stats['country_count'], 'FR and JP; Zara (FR, not approved) must not affect count');
    }

    public function testEnthPrefixedSchemaEmptyTable(): void
    {
        $ddl = 'CREATE TABLE fl_empty_members (email TEXT NOT NULL, name TEXT, country TEXT NOT NULL DEFAULT \'\', approved INTEGER NOT NULL DEFAULT 0)';
        [$db, $config] = self::makeWithConfig($ddl, ['enth_table_prefix' => 'enth_']);

        $repo  = new MemberRepo($db, $config);
        $stats = $repo->getMemberStats('fl_empty_');

        self::assertSame(0, $stats['approved']);
        self::assertSame(0, $stats['country_count']);
    }

    // -----------------------------------------------------------------------
    // Error resilience
    // -----------------------------------------------------------------------

    public function testMissingTableReturnsZeroes(): void
    {
        // No DDL — table does not exist
        $db = SqliteDatabase::make('CREATE TABLE dummy (id INTEGER)');

        $tmpCfg = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'lstr_test_cfg_' . uniqid('', true) . '.php';
        file_put_contents($tmpCfg, "<?php return ['enth_table_prefix' => ''];");
        $config = new Config($tmpCfg);
        unlink($tmpCfg);

        $repo  = new MemberRepo($db, $config);
        $stats = $repo->getMemberStats('nonexistent_table');

        self::assertSame(0, $stats['approved']);
        self::assertSame(0, $stats['country_count']);
    }
}
