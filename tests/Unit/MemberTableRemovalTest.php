<?php

declare(strict_types=1);

namespace Luster\Tests\Unit;

use Luster\Core\Config;
use Luster\Core\Database;
use Luster\Repositories\ListingRepo;
use Luster\Support\MemberTableRemoval;
use Luster\Support\ProtectedTables;
use Luster\Tests\Unit\Support\SqliteDatabase;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Deleting a listing can't destroy someone else's members. The decision is made before
 * anything is deleted, from the physical table, and a failed check stops the delete.
 */
final class MemberTableRemovalTest extends TestCase
{
    private function config(string $prefix): Config
    {
        $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'lstr_removal_' . uniqid('', true) . '.php';
        file_put_contents($path, '<?php return ' . var_export(['enth_table_prefix' => $prefix], true) . ';');
        $config = new Config($path);
        unlink($path);
        return $config;
    }

    private function ownedDb(): Database
    {
        return SqliteDatabase::make("CREATE TABLE owned (listingid INTEGER PRIMARY KEY, dbtable TEXT NOT NULL DEFAULT '')");
    }

    private function enthDb(): Database
    {
        return SqliteDatabase::make("CREATE TABLE enth_fanlistings (id INTEGER PRIMARY KEY, dbprefix TEXT NOT NULL DEFAULT '')");
    }

    public function testATableOnlyThisListingUsesIsDropped(): void
    {
        $db = $this->ownedDb();
        $db->pdo()->exec("INSERT INTO owned VALUES (1, 'moon'), (2, 'star')");

        $removal = MemberTableRemoval::decide(new ListingRepo($db, $this->config('')), 1, 'moon');

        self::assertSame(MemberTableRemoval::DROP, $removal->action);
        self::assertSame('moon', $removal->table);
        self::assertSame('', $removal->note());
    }

    /** @return array<string, array{0: string}> */
    public static function sharedSpellings(): array
    {
        return ['same spelling' => ['moon'], 'different capitals' => ['MOON']];
    }

    /** @dataProvider sharedSpellings */
    public function testATableAnotherListingUsesIsKept(string $otherSpelling): void
    {
        $db = $this->ownedDb();
        $db->pdo()->prepare("INSERT INTO owned VALUES (1, 'moon'), (2, :t)")->execute(['t' => $otherSpelling]);

        $removal = MemberTableRemoval::decide(new ListingRepo($db, $this->config('')), 1, 'moon');

        self::assertSame(MemberTableRemoval::KEEP, $removal->action);
        self::assertSame([2], $removal->otherListings);
        self::assertStringContainsString('#2', $removal->note());
    }

    public function testOnTheEnthLayoutItIsTheRealTableThatCounts(): void
    {
        $db = $this->enthDb();
        $db->pdo()->exec("INSERT INTO enth_fanlistings VALUES (1, 'fl_moon_'), (2, 'fl_star_')");

        $removal = MemberTableRemoval::decide(new ListingRepo($db, $this->config('enth_')), 1, 'fl_moon_');

        self::assertSame(MemberTableRemoval::DROP, $removal->action);
        self::assertSame('fl_moon_members', $removal->table, 'never the bare prefix');
    }

    public function testOnTheEnthLayoutSharingIsFoundThroughTheRealTable(): void
    {
        $db = $this->enthDb();
        $db->pdo()->exec("INSERT INTO enth_fanlistings VALUES (1, 'fl_moon_'), (2, 'FL_MOON_')");

        $removal = MemberTableRemoval::decide(new ListingRepo($db, $this->config('enth_')), 1, 'fl_moon_');

        self::assertSame(MemberTableRemoval::KEEP, $removal->action);
    }

    /** @return array<string, array{0: string}> */
    public static function protectedNames(): array
    {
        return [
            'a Luster table' => ['lstr_admin_users'],
            'capitals'       => ['LSTR_ADMIN_USERS'],
            'the registry'   => ['owned'],
            'categories'     => ['category'],
            'joined'         => ['joined'],
            'settings'       => ['settings'],
            'old registry'   => ['enth_fanlistings'],
        ];
    }

    /** @dataProvider protectedNames */
    public function testAProtectedNameRefusesTheWholeDelete(string $table): void
    {
        $db = $this->ownedDb();
        $db->pdo()->prepare("INSERT INTO owned VALUES (1, :t)")->execute(['t' => $table]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Nothing was deleted');
        MemberTableRemoval::decide(new ListingRepo($db, $this->config('')), 1, $table);
    }

    /** @dataProvider protectedNames */
    public function testProtectedNamesAreKnown(string $table): void
    {
        self::assertTrue(ProtectedTables::contains($table));
    }

    public function testARealMemberTableIsNotProtected(): void
    {
        self::assertFalse(ProtectedTables::contains('fl_moon'));
        self::assertFalse(ProtectedTables::contains('owned_things'));
        self::assertFalse(ProtectedTables::contains('xlstr_moon'));
    }

    public function testAFailedCheckRefusesTheWholeDelete(): void
    {
        $db = $this->ownedDb();
        $repo = new ListingRepo($db, $this->config(''));
        $db->pdo()->exec('DROP TABLE owned');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('nothing was deleted');
        MemberTableRemoval::decide($repo, 1, 'moon');
    }

    /** @return array<string, array{0: string}> */
    public static function noTableAtAll(): array
    {
        return ['blank' => [''], 'not a valid name' => ['bad name!']];
    }

    /** @dataProvider noTableAtAll */
    public function testNoUsableTableMeansNothingToDrop(string $prefix): void
    {
        $db = $this->ownedDb();

        self::assertSame(MemberTableRemoval::NOTHING, MemberTableRemoval::decide(new ListingRepo($db, $this->config('')), 1, $prefix)->action);
    }
}
