<?php

declare(strict_types=1);

namespace Luster\Tests\Unit;

use Luster\Core\Config;
use Luster\Repositories\MemberRepo;
use Luster\Tests\Unit\Support\SqliteDatabase;
use PHPUnit\Framework\TestCase;

/**
 * Real member tables are often narrower than the forms allow, and on a non-strict server
 * a longer value is silently cut. So the check goes by what the table really says.
 */
final class MemberRepoWidthsTest extends TestCase
{
    private const WIDTHS = [
        'email'   => ['type' => 'varchar', 'chars' => 64, 'bytes' => 256],
        'code'    => ['type' => 'char', 'chars' => 4, 'bytes' => 16],
        'bio'     => ['type' => 'tinytext', 'chars' => 255, 'bytes' => 255],
        'essay'   => ['type' => 'text', 'chars' => 65535, 'bytes' => 65535],
        'showurl' => ['type' => 'tinyint', 'chars' => null, 'bytes' => null],
        'open'    => ['type' => 'text', 'chars' => null, 'bytes' => null],
    ];

    private static function repo(string $ddl): MemberRepo
    {
        $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'lstr_widths_cfg_' . uniqid('', true) . '.php';
        file_put_contents($path, "<?php return ['enth_table_prefix' => ''];");
        $config = new Config($path);
        unlink($path);

        return new MemberRepo(SqliteDatabase::make($ddl), $config);
    }

    public function testVarcharAndCharLimitsAreInCharacters(): void
    {
        self::assertSame([], MemberRepo::tooLong(self::WIDTHS, ['email' => str_repeat('x', 64)]));
        self::assertSame(['email'], MemberRepo::tooLong(self::WIDTHS, ['email' => str_repeat('x', 65)]));
        self::assertSame([], MemberRepo::tooLong(self::WIDTHS, ['code' => 'éééé']), 'Four characters, eight bytes');
        self::assertSame(['code'], MemberRepo::tooLong(self::WIDTHS, ['code' => 'ééééé']));
    }

    public function testTextLimitsAreInBytes(): void
    {
        self::assertSame([], MemberRepo::tooLong(self::WIDTHS, ['bio' => str_repeat('x', 255)]));
        self::assertSame(['bio'], MemberRepo::tooLong(self::WIDTHS, ['bio' => str_repeat('x', 256)]));
        self::assertSame(['bio'], MemberRepo::tooLong(self::WIDTHS, ['bio' => str_repeat('é', 128)]), '128 characters, 256 bytes');
        self::assertSame(['essay'], MemberRepo::tooLong(self::WIDTHS, ['essay' => str_repeat('x', 65536)]));
    }

    public function testTextLimitsGoByHowTheColumnStoresEachCharacter(): void
    {
        $width = static fn(string $charset, int $bytesEach): array => ['type' => 'tinytext', 'chars' => 255, 'bytes' => 255, 'charset' => $charset, 'charset_bytes' => $bytesEach];

        // One byte for each character here, while PHP holds each é in two.
        $latin1 = ['bio' => $width('latin1', 1)];
        self::assertSame([], MemberRepo::tooLong($latin1, ['bio' => str_repeat('é', 128)]), '128 characters, 128 bytes in latin1');
        self::assertSame([], MemberRepo::tooLong($latin1, ['bio' => str_repeat('é', 255)]));
        self::assertSame(['bio'], MemberRepo::tooLong($latin1, ['bio' => str_repeat('é', 256)]));

        // The UTF-8 family is measured exactly, whichever name the server gives it.
        foreach (['utf8', 'utf8mb3', 'utf8mb4'] as $charset) {
            $utf8 = ['bio' => $width($charset, $charset === 'utf8mb4' ? 4 : 3)];
            self::assertSame([], MemberRepo::tooLong($utf8, ['bio' => str_repeat('x', 255)]), $charset);
            self::assertSame([], MemberRepo::tooLong($utf8, ['bio' => str_repeat('é', 127)]), $charset);
            self::assertSame(['bio'], MemberRepo::tooLong($utf8, ['bio' => str_repeat('é', 128)]), $charset);
        }

        // Anything else gets the most it could take, so a doubtful value is refused, not cut.
        $wide = ['bio' => $width('utf16', 4)];
        self::assertSame([], MemberRepo::tooLong($wide, ['bio' => str_repeat('x', 63)]), '63 characters, at most 252 bytes');
        self::assertSame(['bio'], MemberRepo::tooLong($wide, ['bio' => str_repeat('x', 64)]), '64 characters, at most 256 bytes');
    }

    public function testACharsetDoesNotChangeACharacterLimit(): void
    {
        $widths = ['email' => ['type' => 'varchar', 'chars' => 4, 'bytes' => 4, 'charset' => 'latin1', 'charset_bytes' => 1]];

        self::assertSame([], MemberRepo::tooLong($widths, ['email' => 'éééé']));
        self::assertSame(['email'], MemberRepo::tooLong($widths, ['email' => 'ééééé']));
    }

    public function testATypeWithNoTextLimitAndAnUnknownColumnAreLeftAlone(): void
    {
        self::assertSame([], MemberRepo::tooLong(self::WIDTHS, ['showurl' => 12345, 'open' => str_repeat('x', 100000), 'not_a_column' => str_repeat('x', 100000)]));
    }

    public function testEveryColumnTooLongIsListedAndANumericNameWorks(): void
    {
        $widths = self::WIDTHS + ['123' => ['type' => 'varchar', 'chars' => 1, 'bytes' => 4]];

        self::assertSame(['email', '123'], MemberRepo::tooLong($widths, ['email' => str_repeat('x', 65), 'code' => 'ok', 123 => 'xx']));
    }

    public function testColumnWidthsReadsTheRealTable(): void
    {
        $repo = self::repo('CREATE TABLE fl_moon (email VARCHAR(64) NOT NULL, name VARCHAR(128) NULL, bio TEXT NULL, pending INTEGER NOT NULL DEFAULT 0)');

        $widths = $repo->columnWidths('fl_moon');

        self::assertSame(['type' => 'varchar', 'chars' => 64, 'bytes' => null, 'charset' => null, 'charset_bytes' => null], $widths['email']);
        self::assertSame(128, $widths['name']['chars']);
        self::assertNull($widths['bio']['chars'], 'SQLite gives TEXT no limit');
        self::assertArrayHasKey('pending', $widths);
    }

    public function testAColumnReadThatFailsThrowsAndIsNotRemembered(): void
    {
        $repo = self::repo('CREATE TABLE other (id INTEGER)');

        try {
            $repo->columnWidths('fl_moon');
            self::fail('A table with no columns should throw');
        } catch (\RuntimeException) {
            self::addToAssertionCount(1);
        }

        // Still throws: it wasn't cached as "no columns".
        $this->expectException(\RuntimeException::class);
        $repo->columnWidths('fl_moon');
    }

    public function testUpdateWithNothingLeftToWriteRunsNoSql(): void
    {
        $repo = self::repo("CREATE TABLE fl_moon (email TEXT NOT NULL, name TEXT NOT NULL DEFAULT '', pending INTEGER NOT NULL DEFAULT 0);
            INSERT INTO fl_moon (email, name) VALUES ('a@x.test', 'Fan A')");

        self::assertFalse($repo->update('fl_moon', 'a@x.test', []));
        self::assertFalse($repo->update('fl_moon', 'a@x.test', ['not_a_column' => 'x']));
        self::assertTrue($repo->update('fl_moon', 'a@x.test', ['name' => 'New']));
    }

    public function testLockRawRowReadsTheWholeRow(): void
    {
        $repo = self::repo("CREATE TABLE fl_moon (email TEXT NOT NULL, name TEXT NOT NULL DEFAULT '', pending INTEGER NOT NULL DEFAULT 0);
            INSERT INTO fl_moon (email, name) VALUES ('a@x.test', 'Fan A')");

        self::assertSame($repo->getRawRow('fl_moon', 'a@x.test'), $repo->lockRawRow('fl_moon', 'a@x.test'));
        self::assertNull($repo->lockRawRow('fl_moon', 'nobody@x.test'));
    }
}
