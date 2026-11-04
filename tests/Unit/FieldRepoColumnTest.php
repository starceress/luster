<?php

declare(strict_types=1);

namespace Luster\Tests\Unit;

use InvalidArgumentException;
use Luster\Core\Config;
use Luster\Core\Database;
use Luster\Models\AdditionalField;
use Luster\Repositories\FieldRepo;
use Luster\Tests\Unit\Support\SqliteDatabase;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * FieldRepo's column changes on both schemas, including a field named with only
 * digits. Enthusiast allows a field called "123", and on the unprefixed schema
 * that's the column name too, which MySQL (and SQLite) only accept quoted.
 *
 * On the standard schema a listing's dbprefix is "fl_moon_" and its member table
 * is "fl_moon_members". Adding or dropping a field used to alter the dbprefix
 * itself, a table that doesn't exist.
 */
final class FieldRepoColumnTest extends TestCase
{
    /** @return array<string, array{0: string, 1: string, 2: string, 3: string, 4: string}> enth_table_prefix, dbprefix, member table, field name, column */
    public static function columnProvider(): array
    {
        return [
            'unprefixed schema, digits-only name' => ['', 'fl_moon', 'fl_moon', '123', '123'],
            'unprefixed schema, ordinary name'    => ['', 'fl_moon', 'fl_moon', 'fave', 'fave'],
            'standard schema, digits-only name'   => ['enth_', 'fl_moon_', 'fl_moon_members', '123', 'additional_123'],
            'standard schema, ordinary name'      => ['enth_', 'fl_moon_', 'fl_moon_members', 'fave', 'additional_fave'],
        ];
    }

    /** @dataProvider columnProvider */
    public function testAddColumnThenDropColumn(string $enthTablePrefix, string $dbprefix, string $table, string $field, string $column): void
    {
        $db   = SqliteDatabase::make("CREATE TABLE {$table} (email TEXT NOT NULL PRIMARY KEY, name TEXT NOT NULL DEFAULT '')");
        $repo = new FieldRepo($db, $this->config($enthTablePrefix));

        $repo->addColumn($dbprefix, $field);
        self::assertSame(['email', 'name', $column], self::columns($db, $table));

        $db->pdo()->prepare("INSERT INTO {$table} (email, `{$column}`) VALUES (?, ?)")->execute(['ann@example.com', '42']);
        self::assertSame('42', $db->pdo()->query("SELECT `{$column}` FROM {$table}")->fetchColumn());

        $found = $repo->fieldColumns($dbprefix, [self::field($field)])[$field];
        self::assertSame($column, $found);

        $repo->dropColumn($dbprefix, $found);
        self::assertSame(['email', 'name'], self::columns($db, $table));
        self::assertSame('ann@example.com', $db->pdo()->query("SELECT email FROM {$table}")->fetchColumn(), 'The member stays');
    }

    /** A column made before the unprefixed schema used bare names is still found and removed. */
    public function testAnOldAdditionalColumnIsFoundForItsField(): void
    {
        $db   = SqliteDatabase::make('CREATE TABLE fl_moon (email TEXT NOT NULL PRIMARY KEY, additional_fave TEXT NULL)');
        $repo = new FieldRepo($db, $this->config(''));

        $column = $repo->fieldColumns('fl_moon', [self::field('fave')])['fave'];
        self::assertSame('additional_fave', $column);

        $repo->dropColumn('fl_moon', $column);
        self::assertSame(['email'], self::columns($db, 'fl_moon'));
    }

    /** @return array<string, array{0: string}> */
    public static function builtInColumnProvider(): array
    {
        $names = [];
        foreach (['email', 'name', 'country', 'url', 'pending', 'password', 'showemail', 'showurl', 'added', 'Email', 'NAME', 'Pending'] as $name) {
            $names[$name] = [$name];
        }
        return $names;
    }

    /** @dataProvider builtInColumnProvider */
    public function testAnUnprefixedBuiltInColumnCanNeverBeDropped(string $column): void
    {
        $db   = SqliteDatabase::make("CREATE TABLE fl_moon (email TEXT NOT NULL PRIMARY KEY, name TEXT NOT NULL DEFAULT '', country TEXT NOT NULL DEFAULT '',
            url TEXT NOT NULL DEFAULT '', pending INTEGER NOT NULL DEFAULT 0, password TEXT NOT NULL DEFAULT '',
            showemail INTEGER NOT NULL DEFAULT 1, showurl INTEGER NOT NULL DEFAULT 1, added TEXT NULL)");
        $repo = new FieldRepo($db, $this->config(''));
        $before = self::columns($db, 'fl_moon');

        try {
            $repo->dropColumn('fl_moon', $column);
            self::fail('Expected a refusal');
        } catch (InvalidArgumentException) {
            self::assertSame($before, self::columns($db, 'fl_moon'));
        }
    }

    /** @return array<string, array{0: string}> */
    public static function standardBuiltInProvider(): array
    {
        return ['email' => ['email'], 'approved' => ['approved'], 'id' => ['id'], 'fave' => ['fave']];
    }

    /**
     * On the standard schema every field's column has the prefix, so anything
     * without it is a built-in, even a name that looks custom.
     *
     * @dataProvider standardBuiltInProvider
     */
    public function testOnTheStandardSchemaOnlyPrefixedColumnsCanBeDropped(string $column): void
    {
        $db   = SqliteDatabase::make('CREATE TABLE fl_moon_members (id INTEGER PRIMARY KEY, email TEXT, approved INTEGER, fave TEXT, additional_fave TEXT)');
        $repo = new FieldRepo($db, $this->config('enth_'));
        $before = self::columns($db, 'fl_moon_members');

        try {
            $repo->dropColumn('fl_moon_', $column);
            self::fail('Expected a refusal');
        } catch (InvalidArgumentException) {
            self::assertSame($before, self::columns($db, 'fl_moon_members'));
        }

        $repo->dropColumn('fl_moon_', 'additional_fave');
        self::assertNotContains('additional_fave', self::columns($db, 'fl_moon_members'));
    }

    /** An empty dbprefix must not become a table called just "members". */
    public function testAnEmptyDbprefixIsRefusedOnTheStandardSchema(): void
    {
        $db   = SqliteDatabase::make('CREATE TABLE members (email TEXT NOT NULL PRIMARY KEY, additional_fave TEXT NULL)');
        $repo = new FieldRepo($db, $this->config('enth_'));

        foreach ([
            static fn() => $repo->addColumn('', 'other'),
            static fn() => $repo->dropColumn('', 'additional_fave'),
            static fn() => $repo->fieldColumns('', [self::field('fave')]),
        ] as $call) {
            try {
                $call();
                self::fail('Expected a refusal');
            } catch (InvalidArgumentException) {
                self::assertSame(['email', 'additional_fave'], self::columns($db, 'members'));
            }
        }
    }

    /** "Couldn't read" isn't "no column": a missing table throws, it doesn't answer null. */
    public function testFieldColumnsThrowsWhenTheTableCantBeRead(): void
    {
        $repo = new FieldRepo(SqliteDatabase::make('CREATE TABLE fl_other (email TEXT)'), $this->config(''));

        $this->expectException(RuntimeException::class);
        $repo->fieldColumns('fl_moon', [self::field('fave')]);
    }

    public function testFieldColumnsWithNoFieldsDoesNotNeedTheTable(): void
    {
        $repo = new FieldRepo(SqliteDatabase::make('CREATE TABLE fl_other (email TEXT)'), $this->config(''));

        self::assertSame([], $repo->fieldColumns('fl_moon', []));
    }

    private static function field(string $name): AdditionalField
    {
        return new AdditionalField($name, ucfirst($name), 'text', false);
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
