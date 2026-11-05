<?php

declare(strict_types=1);

namespace Luster\Tests\Unit;

use Luster\Repositories\PendingUpdateRepo;
use Luster\Tests\Unit\Support\SqliteDatabase;
use PHPUnit\Framework\TestCase;

final class PendingUpdateRepoTest extends TestCase
{
    private const DDL = <<<SQL
CREATE TABLE lstr_pending_updates (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    fanlisting_id INTEGER NOT NULL,
    member_email  TEXT    NOT NULL,
    changes       TEXT    NOT NULL,
    submitted_at  TEXT    NOT NULL DEFAULT CURRENT_TIMESTAMP
)
SQL;

    // --- renameMemberEmail

    public function testRenameMemberEmailUpdatesMatchingRow(): void
    {
        $db   = SqliteDatabase::make(self::DDL);
        $repo = new PendingUpdateRepo($db);

        $id = $repo->insert(1, 'old@test.com', ['url' => 'https://a.test']);

        $repo->renameMemberEmail(1, 'old@test.com', 'new@test.com');

        self::assertSame('new@test.com', $repo->getById($id)->member_email);
    }

    public function testRenameMemberEmailIsNoOpWhenNoMatchingRow(): void
    {
        $db   = SqliteDatabase::make(self::DDL);
        $repo = new PendingUpdateRepo($db);

        // Should not throw
        $repo->renameMemberEmail(1, 'nobody@test.com', 'new@test.com');

        self::assertSame(0, $repo->countByListing(1));
    }

    public function testRenameMemberEmailDoesNotAffectOtherListings(): void
    {
        $db   = SqliteDatabase::make(self::DDL);
        $repo = new PendingUpdateRepo($db);

        $id1 = $repo->insert(1, 'old@test.com', ['url' => 'https://a.test']);
        $id2 = $repo->insert(2, 'old@test.com', ['url' => 'https://a.test']);

        $repo->renameMemberEmail(1, 'old@test.com', 'new@test.com');

        self::assertSame('new@test.com', $repo->getById($id1)->member_email);
        self::assertSame('old@test.com', $repo->getById($id2)->member_email);
    }

    public function testRenameMemberEmailDoesNotAffectOtherEmailsInSameListing(): void
    {
        $db   = SqliteDatabase::make(self::DDL);
        $repo = new PendingUpdateRepo($db);

        $id1 = $repo->insert(1, 'old@test.com',   ['url' => 'https://a.test']);
        $id2 = $repo->insert(1, 'other@test.com', ['url' => 'https://b.test']);

        $repo->renameMemberEmail(1, 'old@test.com', 'new@test.com');

        self::assertSame('new@test.com',   $repo->getById($id1)->member_email);
        self::assertSame('other@test.com', $repo->getById($id2)->member_email);
    }

    // --- Reading a row

    private const DDL_WITH_REVIEW_STATE = <<<SQL
CREATE TABLE lstr_pending_updates (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    fanlisting_id INTEGER NOT NULL,
    member_email  TEXT    NOT NULL,
    changes       TEXT    NOT NULL,
    submitted_at  TEXT    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    review_state  VARCHAR(32) NULL
)
SQL;

    public function testBadJsonIsReportedRatherThanLookingEmpty(): void
    {
        $db = SqliteDatabase::make(self::DDL_WITH_REVIEW_STATE);
        $db->pdo()->exec("INSERT INTO lstr_pending_updates (id, fanlisting_id, member_email, changes) VALUES
            (1, 1, 'a@x.test', '{not json'), (2, 1, 'a@x.test', '\"just text\"'), (3, 1, 'a@x.test', ''), (4, 1, 'a@x.test', '{}')");
        $repo = new PendingUpdateRepo($db);

        self::assertTrue($repo->getById(1)->malformed);
        self::assertSame([], $repo->getById(1)->changes);
        self::assertTrue($repo->getById(2)->malformed);
        self::assertTrue($repo->getById(3)->malformed);
        self::assertFalse($repo->getById(4)->malformed, 'An empty object is readable. It just changes nothing.');
    }

    public function testCoreValuesAreTidiedOnceSoLockingCheckingAndSavingAllSeeTheSameText(): void
    {
        $repo = new PendingUpdateRepo(SqliteDatabase::make(self::DDL));
        $id   = $repo->insert(1, 'fan@example.com', [
            'EMAIL'   => ' new@example.com ',
            'name'    => "\tNew Name\n",
            'Country' => ' Peru ',
            'url'     => ' https://new.test/ ',
            'showurl' => ' 1 ',
            'fave'    => ' keeps its spaces ',
            'list'    => ['nested'],
        ]);

        $row = $repo->getById($id);

        self::assertSame('new@example.com', $row->changes['email']);
        self::assertArrayNotHasKey('EMAIL', $row->changes);
        self::assertSame('New Name', $row->changes['name']);
        self::assertSame('Peru', $row->changes['country']);
        self::assertSame('https://new.test/', $row->changes['url']);
        self::assertSame('1', $row->changes['showurl']);
        self::assertSame(' keeps its spaces ', $row->changes['fave'], 'A field answer has no rules to pass, so it stays as written');
        self::assertSame(['nested'], $row->changes['list']);
    }

    public function testEveryWayOfReadingGivesTheSameRowShape(): void
    {
        $db = SqliteDatabase::make(self::DDL_WITH_REVIEW_STATE);
        $repo = new PendingUpdateRepo($db);
        $id = $repo->insert(1, 'a@x.test', ['name' => 'N']);

        $expected = $repo->getById($id);
        self::assertEquals($expected, $repo->lockById($id));
        self::assertEquals($expected, $repo->getAll()[0]);
        self::assertEquals($expected, $repo->getByListing(1)[0]);
        self::assertEquals($expected, $repo->getByIds([$id])[$id]);
        self::assertEquals($expected, $repo->lockAtAddress(1, 'a@x.test')[0]);
        self::assertEquals($expected, $repo->getAtAddress(1, 'a@x.test')[0]);
        self::assertEquals($expected, $repo->paginateAll(1, 10)['updates'][0]);
        self::assertEquals($expected, $repo->paginateByListing(1, 1, 10)['updates'][0]);
        self::assertNull($expected->review_state);
    }

    public function testATableWithNoReviewStateYetTreatsEveryRowAsQueuedBeforeTheChecks(): void
    {
        $repo = new PendingUpdateRepo(SqliteDatabase::make(self::DDL));
        $id = $repo->insert(1, 'a@x.test', ['name' => 'N']);

        self::assertSame(PendingUpdateRepo::QUEUED_BEFORE_CHECKS, $repo->getById($id)->review_state);
        self::assertFalse(PendingUpdateRepo::isHeld($repo->getById($id)), "That's a stricter check, not a hold");
    }

    // --- Order

    public function testIdsComeOldestFirstWithTheIdBreakingATie(): void
    {
        $db = SqliteDatabase::make(self::DDL_WITH_REVIEW_STATE);
        $db->pdo()->exec("INSERT INTO lstr_pending_updates (id, fanlisting_id, member_email, changes, submitted_at) VALUES
            (1, 1, 'a@x.test', '{}', '2026-06-20 00:00:00'),
            (2, 2, 'b@x.test', '{}', '2026-06-10 00:00:00'),
            (3, 1, 'c@x.test', '{}', '2026-06-15 00:00:00'),
            (4, 1, 'd@x.test', '{}', '2026-06-15 00:00:00')");
        $repo = new PendingUpdateRepo($db);

        self::assertSame([2, 3, 4, 1], $repo->idsOldestFirst());
        self::assertSame([3, 4, 1], $repo->idsOldestFirst(1));
        self::assertSame([2, 1], $repo->idsOldestFirst(null, [1, 2]));
        self::assertSame([], $repo->idsOldestFirst(null, []));
    }

    // --- Deleting

    public function testDeleteSaysWhetherItRemovedTheRow(): void
    {
        $repo = new PendingUpdateRepo(SqliteDatabase::make(self::DDL));
        $id = $repo->insert(1, 'a@x.test', ['name' => 'N']);

        self::assertTrue($repo->delete($id));
        self::assertFalse($repo->delete($id));
    }

    public function testDeleteStaleSparesHeldRowsAndClearsTheRest(): void
    {
        $db = SqliteDatabase::make(self::DDL_WITH_REVIEW_STATE);
        $repo = new PendingUpdateRepo($db);
        $ordinary = $repo->insert(1, 'a@x.test', ['name' => 'Ordinary']);
        $old      = $repo->insert(1, 'a@x.test', ['name' => 'Old']);
        $other    = $repo->insert(2, 'a@x.test', ['name' => 'Other listing']);
        $repo->holdRow($old, PendingUpdateRepo::QUEUED_BEFORE_CHECKS);
        $held = [];
        foreach (PendingUpdateRepo::HOLDS as $state) {
            $id = $repo->insert(1, 'a@x.test', ['name' => $state]);
            $repo->holdRow($id, $state);
            $held[] = $id;
        }

        $repo->deleteStale(1, 'a@x.test');

        self::assertNull($repo->getById($ordinary));
        self::assertNull($repo->getById($old), "queued_before_checks isn't a hold");
        self::assertNotNull($repo->getById($other), 'Another listing is left alone');
        foreach ($held as $id) {
            self::assertNotNull($repo->getById($id));
        }
    }

    public function testDeleteStaleIsAPlainDeleteBeforeTheMigration(): void
    {
        $repo = new PendingUpdateRepo(SqliteDatabase::make(self::DDL));
        $id = $repo->insert(1, 'a@x.test', ['name' => 'N']);

        $repo->deleteStale(1, 'a@x.test');

        self::assertNull($repo->getById($id));
    }

    // --- Holds

    public function testHoldRowMarksOneRowAndSaysSo(): void
    {
        $repo = new PendingUpdateRepo(SqliteDatabase::make(self::DDL_WITH_REVIEW_STATE));
        $one = $repo->insert(1, 'a@x.test', ['name' => 'One']);
        $two = $repo->insert(1, 'a@x.test', ['name' => 'Two']);

        self::assertSame(1, $repo->holdRow($one, PendingUpdateRepo::WRITE_UNCONFIRMED));

        self::assertSame(PendingUpdateRepo::WRITE_UNCONFIRMED, $repo->getById($one)->review_state);
        self::assertTrue(PendingUpdateRepo::isHeld($repo->getById($one)));
        self::assertNull($repo->getById($two)->review_state);
    }

    public function testHoldAddressMarksEveryRowAtThatAddressInThatListing(): void
    {
        $repo = new PendingUpdateRepo(SqliteDatabase::make(self::DDL_WITH_REVIEW_STATE));
        $one   = $repo->insert(1, 'a@x.test', ['name' => 'One']);
        $two   = $repo->insert(1, 'a@x.test', ['country' => 'Peru']);
        $other = $repo->insert(1, 'b@x.test', ['name' => 'Other member']);
        $away  = $repo->insert(2, 'a@x.test', ['name' => 'Other listing']);

        self::assertSame(2, $repo->holdAddress(1, 'a@x.test', PendingUpdateRepo::WRITE_UNCONFIRMED));

        self::assertTrue(PendingUpdateRepo::isHeld($repo->getById($one)));
        self::assertTrue(PendingUpdateRepo::isHeld($repo->getById($two)));
        self::assertFalse(PendingUpdateRepo::isHeld($repo->getById($other)));
        self::assertFalse(PendingUpdateRepo::isHeld($repo->getById($away)));
    }

    public function testARowAlreadyHeldKeepsItsFirstReasonAndCountsAsNothingNew(): void
    {
        $repo = new PendingUpdateRepo(SqliteDatabase::make(self::DDL_WITH_REVIEW_STATE));
        $id = $repo->insert(1, 'a@x.test', ['name' => 'N']);
        $repo->holdRow($id, PendingUpdateRepo::ADDRESS_MOVED);

        self::assertSame(0, $repo->holdRow($id, PendingUpdateRepo::WRITE_UNCONFIRMED));
        self::assertSame(PendingUpdateRepo::ADDRESS_MOVED, $repo->getById($id)->review_state);
    }

    public function testARowQueuedBeforeTheChecksCanBeHeld(): void
    {
        $repo = new PendingUpdateRepo(SqliteDatabase::make(self::DDL_WITH_REVIEW_STATE));
        $id = $repo->insert(1, 'a@x.test', ['name' => 'N']);
        $repo->holdRow($id, PendingUpdateRepo::QUEUED_BEFORE_CHECKS);

        self::assertSame(1, $repo->holdRow($id, PendingUpdateRepo::MAY_BE_OTHER_MEMBER));
        self::assertSame(PendingUpdateRepo::MAY_BE_OTHER_MEMBER, $repo->getById($id)->review_state);
    }

    public function testAHoldWithNoColumnYetCountsAsNoneAndThrowsNothing(): void
    {
        $repo = new PendingUpdateRepo(SqliteDatabase::make(self::DDL));
        $id = $repo->insert(1, 'a@x.test', ['name' => 'N']);

        $log      = tempnam(sys_get_temp_dir(), 'lstr_hold_log_');
        $previous = ini_set('error_log', (string) $log);
        try {
            self::assertSame(0, $repo->holdRow($id, PendingUpdateRepo::WRITE_UNCONFIRMED));
            self::assertSame(0, $repo->holdAddress(1, 'a@x.test', PendingUpdateRepo::WRITE_UNCONFIRMED));
            self::assertStringContainsString('Could not hold a queued update', (string) file_get_contents((string) $log));
        } finally {
            ini_set('error_log', (string) $previous);
            unlink((string) $log);
        }
    }
}
