<?php

declare(strict_types=1);

namespace Luster\Tests\Unit;

use Luster\Core\Config;
use Luster\Repositories\MemberRepo;
use Luster\Tests\Unit\Support\SqliteDatabase;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * MemberRepo::update() on both schemas. It filters what it writes to columns the
 * table really has, which needs the table's columns. That used to be a MySQL-only
 * DESCRIBE, so no test could run update() at all.
 */
final class MemberRepoUpdateTest extends TestCase
{
    public function testItWritesRealColumnsAndSkipsAStaleOneOnTheUnprefixedSchema(): void
    {
        $db   = SqliteDatabase::make("CREATE TABLE fl_moon (email TEXT NOT NULL PRIMARY KEY, name TEXT NOT NULL DEFAULT '', pending INTEGER NOT NULL DEFAULT 0, fave TEXT NULL, `123` TEXT NULL);
            INSERT INTO fl_moon (email, name) VALUES ('ann@example.com', 'Ann'), ('bob@example.com', 'Bob')");
        $repo = new MemberRepo($db, $this->config(''));

        $repo->update('fl_moon', 'ann@example.com', ['name' => 'Annie', 'fave' => 'Tea', '123' => '42', 'gone_field' => 'x']);

        self::assertSame(
            ['email' => 'ann@example.com', 'name' => 'Annie', 'pending' => 0, 'fave' => 'Tea', '123' => '42'],
            $db->pdo()->query("SELECT * FROM fl_moon WHERE email = 'ann@example.com'")->fetch(),
        );
        self::assertSame('Bob', $db->pdo()->query("SELECT name FROM fl_moon WHERE email = 'bob@example.com'")->fetchColumn(), 'Only that member changes');
    }

    public function testItWritesAndStampsOnTheStandardSchema(): void
    {
        $db   = SqliteDatabase::make("CREATE TABLE fl_moon_members (id INTEGER PRIMARY KEY AUTOINCREMENT, email TEXT NOT NULL DEFAULT '', name TEXT NOT NULL DEFAULT '', approved INTEGER NOT NULL DEFAULT 1, additional_fave TEXT NULL, updated TEXT NULL);
            INSERT INTO fl_moon_members (email, name) VALUES ('ann@example.com', 'Ann')");
        $repo = new MemberRepo($db, $this->config('enth_'));

        $repo->update('fl_moon_', 'ann@example.com', ['additional_fave' => 'Tea', 'fave' => 'dropped']);

        $row = $db->pdo()->query('SELECT additional_fave, updated FROM fl_moon_members')->fetch();
        self::assertSame('Tea', $row['additional_fave']);
        self::assertNotNull($row['updated']);
    }

    public function testAMissingTableThrowsRatherThanLookingLikeNothingToWrite(): void
    {
        $repo = new MemberRepo(SqliteDatabase::make('CREATE TABLE fl_other (email TEXT)'), $this->config(''));

        $this->expectException(RuntimeException::class);
        $repo->update('fl_moon', 'ann@example.com', ['name' => 'Annie']);
    }

    private function config(string $enthTablePrefix): Config
    {
        $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'lstr_member_update_' . uniqid('', true) . '.php';
        file_put_contents($path, '<?php return ' . var_export(['enth_table_prefix' => $enthTablePrefix], true) . ';');
        $config = new Config($path);
        unlink($path);
        return $config;
    }
}
