<?php

declare(strict_types=1);

namespace Luster\Tests\Unit;

use Luster\Support\RequiredColumns;
use Luster\Tests\Unit\Support\TracingPdo;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Which columns an insert has to fill in on a table Luster doesn't control. The real
 * INFORMATION_SCHEMA version runs on MariaDB in the release checks.
 */
final class RequiredColumnsTest extends TestCase
{
    private function pdo(string $ddl): TracingPdo
    {
        $pdo = TracingPdo::memory();
        $pdo->exec($ddl);

        return $pdo;
    }

    public function testOnlyRequiredTextColumnsWithNoDefaultAreFilled(): void
    {
        $pdo = $this->pdo("CREATE TABLE t (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            required_text TEXT NOT NULL,
            required_varchar VARCHAR(50) NOT NULL,
            required_char CHAR(2) NOT NULL,
            with_default TEXT NOT NULL DEFAULT '',
            nullable_text TEXT NULL,
            required_number INTEGER NOT NULL,
            required_date DATE NOT NULL
        )");

        $fill = RequiredColumns::forInsert($pdo, 't', []);

        self::assertSame(', `required_text`, `required_varchar`, `required_char`', $fill['columns']);
        self::assertSame(", '', '', ''", $fill['values']);
    }

    public function testAColumnTheInsertAlreadyListsIsLeftOutWhateverItsCapitals(): void
    {
        $pdo = $this->pdo('CREATE TABLE t (a TEXT NOT NULL, Bb TEXT NOT NULL, c TEXT NOT NULL)');

        $fill = RequiredColumns::forInsert($pdo, 't', ['a', 'BB']);

        self::assertSame(', `c`', $fill['columns']);
        self::assertSame(", ''", $fill['values']);
    }

    public function testAKeyIsLeftOutBecauseAnEmptyOneWouldOnlyWorkOnce(): void
    {
        $pdo = $this->pdo('CREATE TABLE t (code TEXT NOT NULL PRIMARY KEY, a TEXT NOT NULL)');

        self::assertSame(', `a`', RequiredColumns::forInsert($pdo, 't', [])['columns']);
    }

    public function testATableThatNeedsNothingAddsNothing(): void
    {
        $pdo = $this->pdo("CREATE TABLE t (id INTEGER PRIMARY KEY, a TEXT NOT NULL DEFAULT '', b TEXT NULL)");

        self::assertSame(['columns' => '', 'values' => ''], RequiredColumns::forInsert($pdo, 't', ['id']));
    }

    public function testTheResultWorksInARealInsert(): void
    {
        $pdo  = $this->pdo('CREATE TABLE t (id INTEGER PRIMARY KEY, name TEXT NOT NULL, emailsignup TEXT NOT NULL)');
        $fill = RequiredColumns::forInsert($pdo, 't', ['name']);

        $pdo->prepare("INSERT INTO t (name{$fill['columns']}) VALUES (:name{$fill['values']})")->execute(['name' => 'Moon']);

        self::assertSame(['id' => 1, 'name' => 'Moon', 'emailsignup' => ''], $pdo->query('SELECT * FROM t')->fetch(PDO::FETCH_ASSOC));
    }

    public function testAColumnWithAnOddNameIsNeverPutInSql(): void
    {
        $pdo = $this->pdo('CREATE TABLE t (`odd name` TEXT NOT NULL, fine TEXT NOT NULL)');

        self::assertSame(', `fine`', RequiredColumns::forInsert($pdo, 't', [])['columns']);
    }

    public function testAFailedReadThrowsRatherThanMeaningThereAreNone(): void
    {
        $pdo = $this->pdo('CREATE TABLE t (a TEXT NOT NULL)');
        $pdo->failOn = '/table_info/';

        $this->expectException(RuntimeException::class);
        RequiredColumns::forInsert($pdo, 't', []);
    }

    public function testAMissingTableThrowsRatherThanMeaningThereAreNone(): void
    {
        $pdo = $this->pdo('CREATE TABLE t (a TEXT NOT NULL)');

        $this->expectException(RuntimeException::class);
        RequiredColumns::forInsert($pdo, 'nope', []);
    }

    public function testABadTableNameNeverReachesSql(): void
    {
        $pdo = $this->pdo('CREATE TABLE t (a TEXT NOT NULL)');
        $before = count($pdo->statements);

        try {
            RequiredColumns::forInsert($pdo, 't`; DROP TABLE t; --', []);
            self::fail('It should have refused');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('Invalid table name', $e->getMessage());
        }
        self::assertCount($before, $pdo->statements);
    }
}
