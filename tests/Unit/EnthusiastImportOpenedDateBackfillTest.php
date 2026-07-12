<?php

declare(strict_types=1);

namespace Luster\Tests\Unit;

use Luster\Import\EnthusiastImportSource;
use Luster\Import\ImportOptions;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Regression test for the "skip" conflict-strategy opened-date backfill in
 * runOwnedListings(). The backfill's WHERE clause guards against legacy junk
 * values ('' or '0000-00-00') sitting in owned.opened from before Luster
 * validated dates on write. Under MySQL strict SQL mode, comparing a DATE
 * column directly against those string literals (`opened = ''`) throws
 * SQLSTATE[22007] ("Truncated incorrect datetime value") instead of just
 * evaluating false — this fatally aborted the entire import for anyone
 * re-running it against an existing listing. Fixed by comparing
 * CAST(opened AS CHAR) instead, which sidesteps the invalid string→date
 * conversion while preserving the original intent.
 */
final class EnthusiastImportOpenedDateBackfillTest extends TestCase
{
    private function makePdo(): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE,            PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        return $pdo;
    }

    private function makeOptions(): ImportOptions
    {
        return ImportOptions::fromPost([
            'source'                => 'enthusiast',
            'conflict_strategy'     => 'skip',
            'import_owned_listings' => '1',
        ]);
    }

    public function testBackfillDoesNotThrowWhenExistingOpenedIsLegacyJunk(): void
    {
        $src = $this->makePdo();
        $src->exec(
            "CREATE TABLE `owned` (
                listingid INTEGER PRIMARY KEY, dbtable TEXT NOT NULL, title TEXT NOT NULL DEFAULT '',
                subject TEXT NOT NULL DEFAULT '', email TEXT NOT NULL DEFAULT '', url TEXT NOT NULL DEFAULT '',
                catid TEXT NOT NULL DEFAULT '', additional TEXT NOT NULL DEFAULT '', status INTEGER NOT NULL DEFAULT 2,
                opened TEXT NULL
            )"
        );
        $src->exec(
            "INSERT INTO `owned`
                (listingid, dbtable, title, subject, email, url, catid, additional, status, opened)
             VALUES
                (1, 'wmmp_members', 'Jewel Eyes', 'Who Made Me a Princess', 'owner@test.com',
                 'http://test.com', '', '', 2, '2020-05-01')"
        );

        $tgt = $this->makePdo();
        $tgt->exec(
            "CREATE TABLE `owned` (
                listingid INTEGER PRIMARY KEY, dbtable TEXT NOT NULL, title TEXT NOT NULL DEFAULT '',
                subject TEXT NOT NULL DEFAULT '', email TEXT NOT NULL DEFAULT '', url TEXT NOT NULL DEFAULT '',
                catid TEXT NOT NULL DEFAULT '', additional TEXT NOT NULL DEFAULT '', status INTEGER NOT NULL DEFAULT 2,
                opened TEXT NULL
            )"
        );
        // Existing target row with the exact legacy junk value the WHERE clause
        // is written to detect ('' — same as what MySQL strict mode rejects when
        // compared directly against a DATE column).
        $tgt->exec(
            "INSERT INTO `owned`
                (listingid, dbtable, title, subject, email, url, catid, additional, status, opened)
             VALUES
                (5, 'wmmp_members', 'Jewel Eyes', 'Who Made Me a Princess', 'owner@test.com',
                 'http://test.com', '', '', 2, '')"
        );

        (new EnthusiastImportSource())->run($src, $tgt, $this->makeOptions());

        $stmt = $tgt->prepare('SELECT opened FROM owned WHERE listingid = 5');
        $stmt->execute();
        self::assertSame('2020-05-01', $stmt->fetchColumn(), 'Legacy blank opened value should be backfilled from the source date.');
    }
}
