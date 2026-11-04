<?php

declare(strict_types=1);

namespace Luster\Tests\Unit;

use Luster\Core\Config;
use Luster\Core\Database;
use Luster\Repositories\FieldRepo;
use Luster\Tests\Unit\Support\SqliteDatabase;
use PHPUnit\Framework\TestCase;

/**
 * FieldRepo::addColumn() and dropColumn() on both schemas, including a field
 * named with only digits. Enthusiast allows a field called "123", and on the
 * unprefixed schema that's the column name too, which MySQL (and SQLite) only
 * accept quoted.
 */
final class FieldRepoColumnTest extends TestCase
{
    /** @return array<string, array{0: string, 1: string, 2: string, 3: string}> enth_table_prefix, member table, field name, column */
    public static function columnProvider(): array
    {
        return [
            'unprefixed schema, digits-only name' => ['', 'fl_moon', '123', '123'],
            'unprefixed schema, ordinary name'    => ['', 'fl_moon', 'fave', 'fave'],
            'standard schema, digits-only name'   => ['enth_', 'fl_moon_members', '123', 'additional_123'],
            'standard schema, ordinary name'      => ['enth_', 'fl_moon_members', 'fave', 'additional_fave'],
        ];
    }

    /** @dataProvider columnProvider */
    public function testAddColumnThenDropColumn(string $enthTablePrefix, string $table, string $field, string $column): void
    {
        $db   = SqliteDatabase::make("CREATE TABLE {$table} (email TEXT NOT NULL PRIMARY KEY, name TEXT NOT NULL DEFAULT '')");
        $repo = new FieldRepo($db, $this->config($enthTablePrefix));

        $repo->addColumn($table, $field);
        self::assertSame(['email', 'name', $column], self::columns($db, $table));

        $db->pdo()->prepare("INSERT INTO {$table} (email, `{$column}`) VALUES (?, ?)")->execute(['ann@example.com', '42']);
        self::assertSame('42', $db->pdo()->query("SELECT `{$column}` FROM {$table}")->fetchColumn());

        $repo->dropColumn($table, $field);
        self::assertSame(['email', 'name'], self::columns($db, $table));
        self::assertSame('ann@example.com', $db->pdo()->query("SELECT email FROM {$table}")->fetchColumn(), 'The member stays');
    }

    /** A column made before the unprefixed schema used bare names is still removed. */
    public function testDropColumnFallsBackToTheOldAdditionalName(): void
    {
        $db   = SqliteDatabase::make("CREATE TABLE fl_moon (email TEXT NOT NULL PRIMARY KEY, additional_fave TEXT NULL)");
        $repo = new FieldRepo($db, $this->config(''));

        $repo->dropColumn('fl_moon', 'fave');
        self::assertSame(['email'], self::columns($db, 'fl_moon'));
    }

    /** @return list<string> */
    private static function columns(Database $db, string $table): array
    {
        return array_map('strval', array_column($db->pdo()->query("PRAGMA table_info(`{$table}`)")->fetchAll(\PDO::FETCH_ASSOC), 'name'));
    }

    private function config(string $enthTablePrefix): Config
    {
        $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'lstr_field_columns_' . uniqid('', true) . '.php';
        file_put_contents($path, '<?php return ' . var_export(['enth_table_prefix' => $enthTablePrefix], true) . ';');
        $config = new Config($path);
        unlink($path);
        return $config;
    }
}
