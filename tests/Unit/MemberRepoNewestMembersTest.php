<?php

declare(strict_types=1);

namespace Luster\Tests\Unit;

use Luster\Core\Config;
use Luster\Core\Database;
use Luster\Repositories\MemberRepo;
use PDO;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

final class MemberRepoNewestMembersTest extends TestCase
{
    private static function makeRepo(string $ddl): MemberRepo
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        foreach (array_filter(array_map('trim', explode(';', $ddl))) as $stmt) {
            $pdo->exec($stmt);
        }

        $tmpCfg = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'lstr_test_cfg_' . uniqid('', true) . '.php';
        file_put_contents($tmpCfg, "<?php return ['enth_table_prefix' => ''];");
        $config = new Config($tmpCfg);
        unlink($tmpCfg);

        $tmpDb = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'lstr_test_db_' . uniqid('', true) . '.php';
        file_put_contents($tmpDb, '<?php return [];');
        $dbConfig = new Config($tmpDb);
        unlink($tmpDb);

        $db = new Database($dbConfig);
        $prop = new ReflectionProperty(Database::class, 'pdo');
        $prop->setAccessible(true);
        $prop->setValue($db, $pdo);

        return new MemberRepo($db, $config);
    }

    private const DDL = "CREATE TABLE testfl (
        email VARCHAR(255) PRIMARY KEY,
        name VARCHAR(255) NOT NULL DEFAULT '',
        country VARCHAR(2) NOT NULL DEFAULT '',
        url VARCHAR(255) NOT NULL DEFAULT '',
        pending INT NOT NULL DEFAULT 0,
        password VARCHAR(255) NOT NULL DEFAULT '',
        showemail INT NOT NULL DEFAULT 1,
        showurl INT NOT NULL DEFAULT 1,
        added DATE
    );
    INSERT INTO testfl (email, name, showurl, added, pending) VALUES ('a@x.com', 'Alice',   1, '2026-01-01', 0);
    INSERT INTO testfl (email, name, showurl, added, pending) VALUES ('b@x.com', 'Bob',     1, '2026-02-01', 0);
    INSERT INTO testfl (email, name, showurl, added, pending) VALUES ('c@x.com', 'Charlie', 1, '2026-06-21', 0);
    INSERT INTO testfl (email, name, showurl, added, pending) VALUES ('d@x.com', 'Dana',    1, '2026-06-21', 0);
    INSERT INTO testfl (email, name, showurl, added, pending) VALUES ('e@x.com', 'Eve',     1, '2026-06-21', 0);
    INSERT INTO testfl (email, name, showurl, added, pending) VALUES ('f@x.com', 'Frank',   1, '2026-01-15', 1)";

    public function testLimitZeroReturnsOnlyMembersFromMostRecentJoinDate(): void
    {
        $repo = self::makeRepo(self::DDL);

        $newest = $repo->getNewestMembers('testfl', 0);

        // Charlie, Dana, and Eve all joined 2026-06-21 — the most recent
        // approved join date. Alice and Bob joined earlier and must not
        // appear; Frank is pending and must not appear either.
        $names = array_column($newest, 'name');
        sort($names);
        $this->assertSame(['Charlie', 'Dana', 'Eve'], $names);
    }

    public function testLimitZeroWithNoApprovedMembersReturnsEmpty(): void
    {
        $repoWithOnlyPending = self::makeRepo(
            "CREATE TABLE testfl (
                email VARCHAR(255) PRIMARY KEY,
                name VARCHAR(255) NOT NULL DEFAULT '',
                country VARCHAR(2) NOT NULL DEFAULT '',
                url VARCHAR(255) NOT NULL DEFAULT '',
                pending INT NOT NULL DEFAULT 0,
                password VARCHAR(255) NOT NULL DEFAULT '',
                showemail INT NOT NULL DEFAULT 1,
                showurl INT NOT NULL DEFAULT 1,
                added DATE
            );
            INSERT INTO testfl (email, name, showurl, added, pending) VALUES ('f@x.com', 'Frank', 1, '2026-01-15', 1)"
        );

        $this->assertSame([], $repoWithOnlyPending->getNewestMembers('testfl', 0));
    }

    public function testPositiveLimitStillReturnsMostRecentNRegardlessOfDate(): void
    {
        $repo = self::makeRepo(self::DDL);

        $newest = $repo->getNewestMembers('testfl', 2);

        $this->assertCount(2, $newest);
    }
}
