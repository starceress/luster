<?php

declare(strict_types=1);

namespace Luster\Tests\Unit;

use Luster\Core\Config;
use Luster\Import\EnthusiastImportSource;
use Luster\Import\ImportOptions;
use Luster\Repositories\MemberRepo;
use Luster\Tests\Unit\Support\SqliteDatabase;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * A new member is saved with their email hidden unless a source really recorded a
 * choice. Older tables default showemail to "show", and nobody agreed to that. The
 * value doesn't change what public pages print, because they never print an address.
 * It keeps the table honest for anything else that reads it, such as Enthusiast's
 * own pages on a shared database.
 */
final class NewMemberEmailVisibilityTest extends TestCase
{
    private const BARE_TABLE = "CREATE TABLE fl_moon (email TEXT NOT NULL PRIMARY KEY, name TEXT NOT NULL DEFAULT '',
        pending INTEGER NOT NULL DEFAULT 0, showemail INTEGER NOT NULL DEFAULT 1)";

    private function config(string $enthTablePrefix): Config
    {
        $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'lstr_new_member_' . uniqid('', true) . '.php';
        file_put_contents($path, '<?php return ' . var_export(['enth_table_prefix' => $enthTablePrefix], true) . ';');
        $config = new Config($path);
        unlink($path);
        return $config;
    }

    public function testANewMemberIsHiddenWhateverTheTablesDefaultSays(): void
    {
        $db = SqliteDatabase::make(self::BARE_TABLE);

        (new MemberRepo($db, $this->config('')))->insert('fl_moon', ['email' => 'a@example.test', 'name' => 'A']);

        self::assertSame(0, (int) $db->pdo()->query('SELECT showemail FROM fl_moon')->fetchColumn());
    }

    public function testItWorksOnTheStandardSchemaToo(): void
    {
        $db = SqliteDatabase::make("CREATE TABLE fl_moon_members (id INTEGER PRIMARY KEY AUTOINCREMENT, email TEXT NOT NULL,
            approved INTEGER NOT NULL DEFAULT 0, showemail INTEGER NOT NULL DEFAULT 1)");

        (new MemberRepo($db, $this->config('enth_')))->insert('fl_moon_', ['email' => 'a@example.test']);

        self::assertSame(0, (int) $db->pdo()->query('SELECT showemail FROM fl_moon_members')->fetchColumn());
    }

    public function testAChoiceTheCallerReallyHasIsKept(): void
    {
        $db = SqliteDatabase::make(self::BARE_TABLE);

        (new MemberRepo($db, $this->config('')))->insert('fl_moon', ['email' => 'a@example.test', 'showemail' => 1]);

        self::assertSame(1, (int) $db->pdo()->query('SELECT showemail FROM fl_moon')->fetchColumn());
    }

    public function testATableWithoutTheColumnStillSaves(): void
    {
        $db = SqliteDatabase::make('CREATE TABLE fl_moon (email TEXT NOT NULL PRIMARY KEY, pending INTEGER NOT NULL DEFAULT 0)');

        (new MemberRepo($db, $this->config('')))->insert('fl_moon', ['email' => 'a@example.test']);

        self::assertSame('a@example.test', $db->pdo()->query('SELECT email FROM fl_moon')->fetchColumn());
    }

    public function testTheColumnIsFoundWhateverItsCapitals(): void
    {
        $db = SqliteDatabase::make('CREATE TABLE fl_moon (email TEXT NOT NULL PRIMARY KEY, ShowEmail INTEGER NOT NULL DEFAULT 1)');

        (new MemberRepo($db, $this->config('')))->insert('fl_moon', ['email' => 'a@example.test']);

        self::assertSame(0, (int) $db->pdo()->query('SELECT ShowEmail FROM fl_moon')->fetchColumn());
    }

    public function testAnUnreadableTableRefusesRatherThanGuessing(): void
    {
        $db = SqliteDatabase::make('CREATE TABLE fl_other (email TEXT)');

        $this->expectException(RuntimeException::class);
        (new MemberRepo($db, $this->config('')))->insert('fl_moon', ['email' => 'a@example.test']);
    }

    // -----------------------------------------------------------------------
    // The Enthusiast importer
    // -----------------------------------------------------------------------

    private function pdo(): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        return $pdo;
    }

    /** One source listing whose member table has $showEmailDdl as its showemail column ('' for none). */
    private function source(string $showEmailDdl): PDO
    {
        $src = $this->pdo();
        $src->exec('CREATE TABLE category (catid INTEGER PRIMARY KEY, catname TEXT)');
        $src->exec("CREATE TABLE owned (listingid INTEGER PRIMARY KEY, dbtable TEXT NOT NULL, additional TEXT NOT NULL DEFAULT '')");
        $src->exec("INSERT INTO owned (listingid, dbtable) VALUES (1, 'moon')");
        $column = $showEmailDdl === '' ? '' : ', ' . $showEmailDdl;
        $src->exec("CREATE TABLE moon (email TEXT NOT NULL PRIMARY KEY, name TEXT NOT NULL DEFAULT '',
            pending INTEGER NOT NULL DEFAULT 0, password TEXT NOT NULL DEFAULT ''{$column})");
        return $src;
    }

    private function import(PDO $src, PDO $tgt, string $strategy = 'skip'): void
    {
        $result = (new EnthusiastImportSource())->run($src, $tgt, ImportOptions::fromPost([
            'source' => 'enthusiast', 'conflict_strategy' => $strategy, 'import_members' => '1',
        ]));
        foreach ($result->rows as $row) {
            self::assertSame([], $row['errors'] ?? [], (string) ($row['type'] ?? ''));
        }
    }

    /** @return array<string, array{string, string, int}> */
    public static function sourceShapes(): array
    {
        return [
            'a fan who chose to show it' => ['showemail INTEGER', "'a@example.test', 1", 1],
            'a fan who chose to hide it' => ['showemail INTEGER', "'a@example.test', 0", 0],
            'a blank in the source'      => ['showemail INTEGER NULL', "'a@example.test', NULL", 0],
            'no such column'             => ['', "'a@example.test'", 0],
        ];
    }

    #[DataProvider('sourceShapes')]
    public function testANewImportedMemberKeepsARealChoiceAndIsOtherwiseHidden(string $ddl, string $values, int $expected): void
    {
        $src = $this->source($ddl);
        $src->exec('INSERT INTO moon (email' . ($ddl === '' ? '' : ', showemail') . ") VALUES ({$values})");
        $tgt = $this->pdo();

        $this->import($src, $tgt);

        self::assertSame($expected, (int) $tgt->query('SELECT showemail FROM moon')->fetchColumn());
    }

    /** @return array<string, array{int}> */
    public static function whatsAlreadyThere(): array
    {
        return ['showing' => [1], 'hidden' => [0]];
    }

    #[DataProvider('whatsAlreadyThere')]
    public function testOverwriteLeavesTheValueAloneWhenTheSourceHasNoChoice(int $existing): void
    {
        $src = $this->source('');
        $src->exec("INSERT INTO moon (email, name) VALUES ('a@example.test', 'New name')");
        $tgt = $this->pdo();
        $tgt->exec("CREATE TABLE moon (email TEXT PRIMARY KEY, name TEXT NOT NULL DEFAULT '', country TEXT NOT NULL DEFAULT '',
            url TEXT NOT NULL DEFAULT '', pending INTEGER NOT NULL DEFAULT 0, password TEXT NOT NULL DEFAULT '',
            showemail INTEGER NOT NULL DEFAULT 1, showurl INTEGER NOT NULL DEFAULT 1, added TEXT NULL)");
        $tgt->exec("INSERT INTO moon (email, name, showemail) VALUES ('a@example.test', 'Old name', {$existing})");

        $this->import($src, $tgt, 'overwrite');

        $row = $tgt->query('SELECT name, showemail FROM moon')->fetch();
        self::assertSame('New name', $row['name'], 'The rest of the row is still overwritten');
        self::assertSame($existing, (int) $row['showemail']);
    }

    public function testOverwriteTakesARealChoiceFromTheSource(): void
    {
        $src = $this->source('showemail INTEGER');
        $src->exec("INSERT INTO moon (email, showemail) VALUES ('a@example.test', 0)");
        $tgt = $this->pdo();
        $tgt->exec("CREATE TABLE moon (email TEXT PRIMARY KEY, name TEXT NOT NULL DEFAULT '', country TEXT NOT NULL DEFAULT '',
            url TEXT NOT NULL DEFAULT '', pending INTEGER NOT NULL DEFAULT 0, password TEXT NOT NULL DEFAULT '',
            showemail INTEGER NOT NULL DEFAULT 1, showurl INTEGER NOT NULL DEFAULT 1, added TEXT NULL)");
        $tgt->exec("INSERT INTO moon (email, showemail) VALUES ('a@example.test', 1)");

        $this->import($src, $tgt, 'overwrite');

        self::assertSame(0, (int) $tgt->query('SELECT showemail FROM moon')->fetchColumn());
    }
}
