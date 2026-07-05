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
}
