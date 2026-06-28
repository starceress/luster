<?php

declare(strict_types=1);

namespace Luster\Tests\Unit;

use Luster\Core\Config;
use Luster\Core\Database;
use Luster\Repositories\MemberRepo;
use PDO;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

final class MemberRepoSearchTest extends TestCase
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

    public function testSearchMatchesNameEmailUrl(): void
    {
        $ddl = "CREATE TABLE testfl (
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
        INSERT INTO testfl (email, name, country, url, pending, password, added)
            VALUES ('aerith@midgar.net', 'Aerith', 'US', 'https://flowers.net', 0, 'x', '2026-01-01');
        INSERT INTO testfl (email, name, country, url, pending, password, added)
            VALUES ('tifa@7thheaven.bar', 'Tifa', 'US', 'https://7thheaven.bar', 0, 'x', '2026-01-01');
        INSERT INTO testfl (email, name, country, url, pending, password, added)
            VALUES ('cloud@avalanche.org', 'Cloud', 'US', 'https://midgar-flowers.net', 0, 'x', '2026-01-01')";

        $repo = self::makeRepo($ddl);

        $byName = $repo->search('testfl', 'Aerith');
        $this->assertCount(1, $byName);
        $this->assertSame('aerith@midgar.net', $byName[0]->email);

        $byEmail = $repo->search('testfl', '7thheaven');
        $this->assertCount(1, $byEmail);
        $this->assertSame('Tifa', $byEmail[0]->name);

        $byUrl = $repo->search('testfl', 'midgar-flowers');
        $this->assertCount(1, $byUrl);
        $this->assertSame('Cloud', $byUrl[0]->name);

        $multi = $repo->search('testfl', 'midgar');
        $this->assertCount(2, $multi);
    }

    public function testSearchReturnsEmptyForNoMatch(): void
    {
        $ddl = "CREATE TABLE testfl (
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
        INSERT INTO testfl (email, name, country, url, pending, password, added)
            VALUES ('aerith@midgar.net', 'Aerith', 'US', 'https://flowers.net', 0, 'x', '2026-01-01')";

        $repo = self::makeRepo($ddl);
        $this->assertSame([], $repo->search('testfl', 'nonexistent'));
    }
}
