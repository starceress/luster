<?php

declare(strict_types=1);

namespace Luster\Tests\Unit;

use Luster\Core\Config;
use Luster\Core\Database;
use Luster\Repositories\MemberRepo;
use PDO;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

final class MemberRepoPasswordTest extends TestCase
{
    /**
     * Creates an in-memory SQLite repo wired to the given schema DDL.
     *
     * @param array<string,string> $configValues
     */
    private static function makeRepo(string $ddl, array $configValues = []): array
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        foreach (array_filter(array_map('trim', explode(';', $ddl))) as $stmt) {
            $pdo->exec($stmt);
        }

        $defaults = ['enth_table_prefix' => ''];
        $merged   = array_merge($defaults, $configValues);

        $tmpCfg = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'lstr_pwtest_cfg_' . uniqid('', true) . '.php';
        file_put_contents($tmpCfg, '<?php return ' . var_export($merged, true) . ';');
        $config = new Config($tmpCfg);
        unlink($tmpCfg);

        $tmpDb = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'lstr_pwtest_db_' . uniqid('', true) . '.php';
        file_put_contents($tmpDb, '<?php return [];');
        $dbConfig = new Config($tmpDb);
        unlink($tmpDb);

        $db   = new Database($dbConfig);
        $prop = new ReflectionProperty(Database::class, 'pdo');
        $prop->setAccessible(true);
        $prop->setValue($db, $pdo);

        return [new MemberRepo($db, $config), $pdo];
    }

    // -----------------------------------------------------------------------
    // updatePassword() — basic correctness
    // -----------------------------------------------------------------------

    public function testUpdatePasswordChangesHash(): void
    {
        $ddl = "CREATE TABLE testfl (
            email   VARCHAR(255) PRIMARY KEY,
            name    VARCHAR(255) NOT NULL DEFAULT '',
            country VARCHAR(2)   NOT NULL DEFAULT '',
            url     VARCHAR(255) NOT NULL DEFAULT '',
            pending INT          NOT NULL DEFAULT 0,
            password VARCHAR(255) NOT NULL DEFAULT '',
            showemail INT NOT NULL DEFAULT 1,
            showurl   INT NOT NULL DEFAULT 1,
            added DATE
        );
        INSERT INTO testfl (email, name, password, added)
            VALUES ('tifa@7thheaven.bar', 'Tifa', 'old_hash', '2026-01-01')";

        /** @var MemberRepo $repo */
        [$repo, $pdo] = self::makeRepo($ddl);

        $newHash = password_hash('secret', PASSWORD_ARGON2ID);
        $repo->updatePassword('testfl', 'tifa@7thheaven.bar', $newHash);

        $row = $pdo->query("SELECT password FROM testfl WHERE email = 'tifa@7thheaven.bar'")->fetch();
        $this->assertSame($newHash, $row['password']);
        $this->assertTrue(password_verify('secret', $row['password']));
    }

    // -----------------------------------------------------------------------
    // updatePassword() vs update() — timestamp side-effect
    //
    // On the standard enth_ schema, update() appends ', updated = NOW()' to
    // its SQL. updatePassword() never touches updated, so a silent hash rehash
    // (MD5 → Argon2id, bcrypt → Argon2id) does not register as a profile edit.
    // -----------------------------------------------------------------------

    public function testUpdatePasswordDoesNotTouchUpdatedColumn_EnthSchema(): void
    {
        // enth_ schema: table name = prefix + 'members', approved column, updated column.
        $ddl = "CREATE TABLE test_members (
            id       INTEGER PRIMARY KEY AUTOINCREMENT,
            email    VARCHAR(255) NOT NULL UNIQUE,
            name     VARCHAR(255) NOT NULL DEFAULT '',
            country  VARCHAR(2)   NOT NULL DEFAULT '',
            url      VARCHAR(255) NOT NULL DEFAULT '',
            approved INT          NOT NULL DEFAULT 1,
            password VARCHAR(255) NOT NULL DEFAULT '',
            showemail INT NOT NULL DEFAULT 1,
            showurl   INT NOT NULL DEFAULT 1,
            added    DATE,
            updated  DATETIME NOT NULL DEFAULT '2020-01-01 00:00:00'
        );
        INSERT INTO test_members (email, name, password, added)
            VALUES ('aerith@flowers.net', 'Aerith', 'old_hash', '2026-01-01')";

        /** @var MemberRepo $repo */
        [$repo, $pdo] = self::makeRepo($ddl, ['enth_table_prefix' => 'enth_']);

        $before = $pdo->query("SELECT updated FROM test_members WHERE email = 'aerith@flowers.net'")->fetchColumn();

        $newHash = password_hash('newpassword', PASSWORD_ARGON2ID);
        $repo->updatePassword('test_', 'aerith@flowers.net', $newHash);

        $after = $pdo->query("SELECT updated FROM test_members WHERE email = 'aerith@flowers.net'")->fetchColumn();

        // Password must be changed
        $row = $pdo->query("SELECT password FROM test_members WHERE email = 'aerith@flowers.net'")->fetch();
        $this->assertSame($newHash, $row['password']);

        // updated must not have been touched
        $this->assertSame($before, $after, 'updatePassword() must not touch the updated timestamp');
    }

    // -----------------------------------------------------------------------
    // verifyAndUpgrade() — MD5 upgrade path
    //
    // Seeds a 32-char hex (MD5-shaped) hash and calls the real repo method.
    // If verifyAndUpgrade() loses its MD5 branch, this test fails.
    // -----------------------------------------------------------------------

    public function testVerifyAndUpgradeMd5HashToArgon2id(): void
    {
        $rawPassword = 'hunter2';
        $md5Hash     = md5($rawPassword);

        $ddl = "CREATE TABLE flmembers (
            email    VARCHAR(255) PRIMARY KEY,
            name     VARCHAR(255) NOT NULL DEFAULT '',
            country  VARCHAR(2)   NOT NULL DEFAULT '',
            url      VARCHAR(255) NOT NULL DEFAULT '',
            pending  INT          NOT NULL DEFAULT 0,
            password VARCHAR(255) NOT NULL DEFAULT '',
            showemail INT NOT NULL DEFAULT 1,
            showurl   INT NOT NULL DEFAULT 1,
            added DATE
        );
        INSERT INTO flmembers (email, name, password, added)
            VALUES ('cloud@avalanche.net', 'Cloud', '{$md5Hash}', '2026-01-01')";

        /** @var MemberRepo $repo */
        [$repo, $pdo] = self::makeRepo($ddl);

        $verified = $repo->verifyAndUpgrade('flmembers', 'cloud@avalanche.net', $rawPassword, $md5Hash);

        $this->assertTrue($verified, 'correct password must return true');

        $upgraded = (string) $pdo->query("SELECT password FROM flmembers WHERE email = 'cloud@avalanche.net'")->fetchColumn();
        $this->assertNotSame($md5Hash, $upgraded, 'hash must have changed');
        $this->assertTrue(password_verify($rawPassword, $upgraded), 'upgraded hash must verify the same password');
        $this->assertStringStartsWith('$argon2id', $upgraded, 'upgraded hash must be Argon2id');
    }

    public function testVerifyAndUpgradeReturnsFalseForWrongMd5Password(): void
    {
        $md5Hash = md5('correctpassword');
        $ddl     = "CREATE TABLE md5wrongfl (
            email    VARCHAR(255) PRIMARY KEY, name VARCHAR(255) NOT NULL DEFAULT '',
            country  VARCHAR(2)   NOT NULL DEFAULT '', url VARCHAR(255) NOT NULL DEFAULT '',
            pending  INT NOT NULL DEFAULT 0, password VARCHAR(255) NOT NULL DEFAULT '',
            showemail INT NOT NULL DEFAULT 1, showurl INT NOT NULL DEFAULT 1, added DATE
        );
        INSERT INTO md5wrongfl (email, name, password, added)
            VALUES ('test@example.com', 'Test', '{$md5Hash}', '2026-01-01')";

        [$repo, $pdo] = self::makeRepo($ddl);

        $result = $repo->verifyAndUpgrade('md5wrongfl', 'test@example.com', 'wrongpassword', $md5Hash);

        $this->assertFalse($result);
        // Hash must not have changed
        $stored = $pdo->query("SELECT password FROM md5wrongfl WHERE email = 'test@example.com'")->fetchColumn();
        $this->assertSame($md5Hash, $stored, 'hash must be unchanged after failed verify');
    }

    // -----------------------------------------------------------------------
    // verifyAndUpgrade() — bcrypt upgrade path
    //
    // Imported members from Fanatically have PASSWORD_BCRYPT hashes.
    // Calls the real repo method; if verifyAndUpgrade() loses its
    // password_needs_rehash() guard, this test fails.
    // -----------------------------------------------------------------------

    public function testVerifyAndUpgradeBcryptHashToArgon2id(): void
    {
        $rawPassword = 'chocobo';
        $bcryptHash  = password_hash($rawPassword, PASSWORD_BCRYPT);

        $ddl = "CREATE TABLE fxmembers (
            email    VARCHAR(255) PRIMARY KEY,
            name     VARCHAR(255) NOT NULL DEFAULT '',
            country  VARCHAR(2)   NOT NULL DEFAULT '',
            url      VARCHAR(255) NOT NULL DEFAULT '',
            pending  INT          NOT NULL DEFAULT 0,
            password VARCHAR(255) NOT NULL DEFAULT '',
            showemail INT NOT NULL DEFAULT 1,
            showurl   INT NOT NULL DEFAULT 1,
            added DATE
        );
        INSERT INTO fxmembers (email, name, password, added)
            VALUES ('yuna@spira.net', 'Yuna', '{$bcryptHash}', '2026-01-01')";

        /** @var MemberRepo $repo */
        [$repo, $pdo] = self::makeRepo($ddl);

        $verified = $repo->verifyAndUpgrade('fxmembers', 'yuna@spira.net', $rawPassword, $bcryptHash);

        $this->assertTrue($verified, 'correct password must return true');

        $upgraded = (string) $pdo->query("SELECT password FROM fxmembers WHERE email = 'yuna@spira.net'")->fetchColumn();
        $this->assertNotSame($bcryptHash, $upgraded, 'hash must have changed from bcrypt');
        $this->assertTrue(password_verify($rawPassword, $upgraded), 'upgraded hash must still verify the same password');
        $this->assertStringStartsWith('$argon2id', $upgraded, 'upgraded hash must be Argon2id');
        $this->assertFalse(password_needs_rehash($upgraded, PASSWORD_ARGON2ID), 'upgraded hash must not need rehash again');
    }

    public function testVerifyAndUpgradeReturnsFalseForWrongBcryptPassword(): void
    {
        $bcryptHash = password_hash('correctpassword', PASSWORD_BCRYPT);
        $ddl        = "CREATE TABLE bcryptwrongfl (
            email    VARCHAR(255) PRIMARY KEY, name VARCHAR(255) NOT NULL DEFAULT '',
            country  VARCHAR(2)   NOT NULL DEFAULT '', url VARCHAR(255) NOT NULL DEFAULT '',
            pending  INT NOT NULL DEFAULT 0, password VARCHAR(255) NOT NULL DEFAULT '',
            showemail INT NOT NULL DEFAULT 1, showurl INT NOT NULL DEFAULT 1, added DATE
        );
        INSERT INTO bcryptwrongfl (email, name, password, added)
            VALUES ('test@example.com', 'Test', " . var_export($bcryptHash, true) . ", '2026-01-01')";

        [$repo, $pdo] = self::makeRepo($ddl);

        $result = $repo->verifyAndUpgrade('bcryptwrongfl', 'test@example.com', 'wrongpassword', $bcryptHash);

        $this->assertFalse($result);
        $stored = $pdo->query("SELECT password FROM bcryptwrongfl WHERE email = 'test@example.com'")->fetchColumn();
        $this->assertSame($bcryptHash, $stored, 'hash must be unchanged after failed verify');
    }

    // -----------------------------------------------------------------------
    // verifyAndUpgrade() — Argon2id is a no-op (no redundant rehash)
    // -----------------------------------------------------------------------

    public function testVerifyAndUpgradeArgon2idHashIsNotRehashed(): void
    {
        $rawPassword  = 'stayonargon2id';
        $argon2idHash = password_hash($rawPassword, PASSWORD_ARGON2ID);

        $ddl = "CREATE TABLE norehashfl (
            email    VARCHAR(255) PRIMARY KEY, name VARCHAR(255) NOT NULL DEFAULT '',
            country  VARCHAR(2)   NOT NULL DEFAULT '', url VARCHAR(255) NOT NULL DEFAULT '',
            pending  INT NOT NULL DEFAULT 0, password VARCHAR(255) NOT NULL DEFAULT '',
            showemail INT NOT NULL DEFAULT 1, showurl INT NOT NULL DEFAULT 1, added DATE
        );
        INSERT INTO norehashfl (email, name, password, added)
            VALUES ('test@example.com', 'Test', " . var_export($argon2idHash, true) . ", '2026-01-01')";

        [$repo, $pdo] = self::makeRepo($ddl);

        $verified = $repo->verifyAndUpgrade('norehashfl', 'test@example.com', $rawPassword, $argon2idHash);

        $this->assertTrue($verified);
        $stored = $pdo->query("SELECT password FROM norehashfl WHERE email = 'test@example.com'")->fetchColumn();
        $this->assertSame($argon2idHash, $stored, 'Argon2id hash must not be rewritten');
    }
}
