<?php

declare(strict_types=1);

namespace Luster\Tests\Unit;

use Luster\Tests\Unit\Support\FanaticallyImportFixture;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Fanatically import into an `owned` table that began on Enthusiast, which has text
 * columns that are NOT NULL with no default. A strict server refuses an insert that leaves
 * them out, and SQLite always does. It runs on real MariaDB in the release checks too.
 */
final class FanaticallyOldOwnedTableImportTest extends TestCase
{
    use FanaticallyImportFixture;

    private const OLD_COLUMNS = ['emailsignup', 'emailapproved', 'emailupdate', 'emaillostpass', 'listtemplate'];

    /** Swaps the fixture's `owned` for one shaped like Enthusiast's. */
    private function useOldOwnedTable(PDO $tgt): void
    {
        $tgt->exec('DROP TABLE owned');
        $tgt->exec("CREATE TABLE owned (
            listingid INTEGER PRIMARY KEY AUTOINCREMENT, dbtable TEXT NOT NULL DEFAULT '', title TEXT NOT NULL DEFAULT '',
            subject TEXT NOT NULL DEFAULT '', email TEXT NOT NULL DEFAULT '', url TEXT NOT NULL DEFAULT '',
            catid TEXT NOT NULL DEFAULT '', category_id INTEGER NULL, opened TEXT NULL, status INTEGER NOT NULL DEFAULT 2,
            imagefile TEXT NOT NULL DEFAULT '', additional TEXT NOT NULL DEFAULT '',
            emailsignup TEXT NOT NULL, emailapproved TEXT NOT NULL, emailupdate TEXT NOT NULL,
            emaillostpass TEXT NOT NULL, listtemplate TEXT NOT NULL)");
    }

    private function source(): PDO
    {
        $src = $this->makeSource();
        $this->addSourceListing($src, 1, 'Moon FL', 'https://fans.test/moon');

        return $src;
    }

    public function testANewListingSavesWithTheOldColumnsEmpty(): void
    {
        $src = $this->source();
        $tgt = $this->makeOwnedTarget();
        $this->useOldOwnedTable($tgt);
        $options = $this->options(['import_listings' => '1']);

        $row = $this->resultRow($this->runImport($src, $tgt, $options), 'Owned Listings');

        self::assertSame([], $row['errors']);
        self::assertSame(1, $row['imported']);
        $owned = $tgt->query('SELECT * FROM owned')->fetch();
        self::assertSame('Moon FL', $owned['title']);
        foreach (self::OLD_COLUMNS as $column) {
            self::assertSame('', $owned[$column], $column);
        }
    }

    public function testTheFixturesOwnTableStillWorks(): void
    {
        $src = $this->source();
        $tgt = $this->makeOwnedTarget();

        $row = $this->resultRow($this->runImport($src, $tgt, $this->options(['import_listings' => '1'])), 'Owned Listings');

        self::assertSame([], $row['errors']);
        self::assertSame(1, $row['imported']);
    }

    public function testAFailedReadOfTheTableRefusesBeforeAnyWrite(): void
    {
        $src = $this->source();
        $tgt = $this->makeTracingPdo();
        $this->makeOwnedTarget($tgt);
        $this->useOldOwnedTable($tgt);
        $tgt->failOn = '/PRAGMA table_info\(`owned`\)/';
        $options = $this->options(['import_listings' => '1']);

        $warnings = $this->previewWarnings($src, $tgt, $options);
        self::assertSomeWarningContains($warnings, "Can't import");

        $result = $this->runImport($src, $tgt, $options);
        $tgt->failOn = null;

        self::assertSame(0, (int) $tgt->query('SELECT COUNT(*) FROM owned')->fetchColumn());
        $errors = [];
        foreach ($result->rows as $row) {
            $errors = array_merge($errors, $row['errors'] ?? []);
        }
        self::assertNotSame([], $errors, 'It says so');
    }
}
