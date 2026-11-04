<?php

declare(strict_types=1);

namespace Luster\Tests\Unit;

use Luster\Core\Config;
use Luster\Repositories\MemberRepo;
use Luster\Tests\Unit\Support\SqliteDatabase;
use PHPUnit\Framework\TestCase;

/**
 * approvedColumns() (Add Member's Approved status) and pendingJoinColumns() (the
 * join form's, and Add Member's Pending) save a member each schema reads back as
 * approved and as pending.
 */
final class MemberRepoApprovedColumnsTest extends TestCase
{
    /** @return array<string, array{0: string, 1: string, 2: string}> enth_table_prefix, dbprefix, DDL */
    public static function schemaProvider(): array
    {
        return [
            'unprefixed schema' => ['', 'fl_moon', "CREATE TABLE fl_moon (
                email TEXT NOT NULL PRIMARY KEY, name TEXT NOT NULL DEFAULT '', pending INTEGER NOT NULL DEFAULT 0)"],
            'standard schema'   => ['enth_', 'fl_moon_', "CREATE TABLE fl_moon_members (
                id INTEGER PRIMARY KEY AUTOINCREMENT, email TEXT NOT NULL DEFAULT '', name TEXT NOT NULL DEFAULT '',
                approved INTEGER NOT NULL DEFAULT 0, updated TEXT NULL)"],
        ];
    }

    /** @dataProvider schemaProvider */
    public function testApprovedColumnsSaveAnApprovedMemberAndJoinColumnsAPendingOne(string $enthTablePrefix, string $dbprefix, string $ddl): void
    {
        $repo = new MemberRepo(SqliteDatabase::make($ddl), $this->config($enthTablePrefix));

        $repo->insert($dbprefix, ['email' => 'approved@example.com', 'name' => 'A'] + $repo->approvedColumns());
        $repo->insert($dbprefix, ['email' => 'pending@example.com', 'name' => 'P'] + $repo->pendingJoinColumns());

        self::assertSame(1, $repo->countApproved($dbprefix));
        self::assertSame(1, $repo->countPending($dbprefix));
        self::assertTrue($repo->getByEmail($dbprefix, 'approved@example.com')?->approved);
        self::assertFalse($repo->getByEmail($dbprefix, 'pending@example.com')?->approved);
        self::assertFalse($repo->getByEmail($dbprefix, 'pending@example.com')?->rejected);
    }

    private function config(string $enthTablePrefix): Config
    {
        $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'lstr_approved_cols_' . uniqid('', true) . '.php';
        file_put_contents($path, '<?php return ' . var_export(['enth_table_prefix' => $enthTablePrefix], true) . ';');
        $config = new Config($path);
        unlink($path);
        return $config;
    }
}
