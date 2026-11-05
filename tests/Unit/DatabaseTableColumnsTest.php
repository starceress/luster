<?php

declare(strict_types=1);

namespace Luster\Tests\Unit;

use Luster\Core\Config;
use Luster\Core\Database;
use Luster\Tests\Unit\Support\SqliteDatabase;
use Luster\Tests\Unit\Support\TracingPdo;
use PDOException;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use RuntimeException;

/**
 * Database::tableColumns() must never let "couldn't read" look like "that
 * column isn't there". (The MySQL branch is exercised on real MariaDB by hand;
 * this suite has no MySQL server.)
 */
final class DatabaseTableColumnsTest extends TestCase
{
    public function testItListsColumnsInOrderWithTheirTypesAndLimits(): void
    {
        $db = SqliteDatabase::make('CREATE TABLE fl_moon (email VARCHAR(64) NOT NULL PRIMARY KEY, code CHAR(5), fave TEXT NULL, pending INTEGER, `123` TEXT)');

        self::assertSame([
            ['name' => 'email',   'type' => 'varchar', 'chars' => 64,   'bytes' => null, 'charset' => null, 'charset_bytes' => null],
            ['name' => 'code',    'type' => 'char',    'chars' => 5,    'bytes' => null, 'charset' => null, 'charset_bytes' => null],
            ['name' => 'fave',    'type' => 'text',    'chars' => null, 'bytes' => null, 'charset' => null, 'charset_bytes' => null],
            ['name' => 'pending', 'type' => 'integer', 'chars' => null, 'bytes' => null, 'charset' => null, 'charset_bytes' => null],
            ['name' => '123',     'type' => 'text',    'chars' => null, 'bytes' => null, 'charset' => null, 'charset_bytes' => null],
        ], $db->tableColumns('fl_moon'));
    }

    public function testAMissingTableThrowsInsteadOfLookingEmpty(): void
    {
        $db = SqliteDatabase::make('CREATE TABLE fl_moon (email TEXT)');

        $this->expectException(RuntimeException::class);
        $db->tableColumns('fl_gone');
    }

    public function testAFailedReadThrows(): void
    {
        $pdo = TracingPdo::memory();
        $pdo->exec('CREATE TABLE fl_moon (email TEXT)');
        $pdo->failOn = '/PRAGMA table_info/';

        $this->expectException(PDOException::class);
        $this->databaseOn($pdo)->tableColumns('fl_moon');
    }

    public function testAnUnsafeTableNameIsRefusedBeforeAnyQuery(): void
    {
        $pdo = TracingPdo::memory();

        try {
            $this->databaseOn($pdo)->tableColumns('fl_moon`; DROP TABLE x');
            self::fail('Expected a refusal');
        } catch (RuntimeException) {
            self::assertSame([], $pdo->statements);
        }
    }

    private function databaseOn(TracingPdo $pdo): Database
    {
        $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'lstr_table_columns_' . uniqid('', true) . '.php';
        file_put_contents($path, '<?php return [];');
        $config = new Config($path);
        unlink($path);

        $db   = new Database($config);
        $prop = new ReflectionProperty(Database::class, 'pdo');
        $prop->setAccessible(true);
        $prop->setValue($db, $pdo);

        return $db;
    }
}
