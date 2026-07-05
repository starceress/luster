<?php

declare(strict_types=1);

namespace Luster\Tests\Unit;

use Luster\Repositories\FanPasswordResetRepo;
use Luster\Tests\Unit\Support\SqliteDatabase;
use PHPUnit\Framework\TestCase;

final class FanPasswordResetRepoTest extends TestCase
{
    private const DDL = <<<SQL
CREATE TABLE lstr_fan_password_resets (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    fanlisting_id INTEGER NOT NULL,
    email         TEXT    NOT NULL,
    token_hash    TEXT    NOT NULL,
    expires_at    TEXT    NOT NULL,
    created_at    TEXT    NOT NULL DEFAULT CURRENT_TIMESTAMP
)
SQL;

    private function future(int $seconds = 3600): string
    {
        return (new \DateTimeImmutable())->modify("+{$seconds} seconds")->format('Y-m-d H:i:s');
    }

    private function past(int $seconds = 3600): string
    {
        return (new \DateTimeImmutable())->modify("-{$seconds} seconds")->format('Y-m-d H:i:s');
    }

    // --- findValidByToken

    public function testFindValidByTokenReturnsRowForUnexpiredToken(): void
    {
        $db   = SqliteDatabase::make(self::DDL);
        $repo = new FanPasswordResetRepo($db);

        $repo->create(1, 'a@test.com', 'hash123', $this->future());

        $row = $repo->findValidByToken('hash123');

        self::assertNotNull($row);
        self::assertSame(1, (int) $row['fanlisting_id']);
        self::assertSame('a@test.com', $row['email']);
    }

    public function testFindValidByTokenReturnsNullForUnknownToken(): void
    {
        $db   = SqliteDatabase::make(self::DDL);
        $repo = new FanPasswordResetRepo($db);

        self::assertNull($repo->findValidByToken('nope'));
    }

    public function testFindValidByTokenReturnsNullForExpiredToken(): void
    {
        $db   = SqliteDatabase::make(self::DDL);
        $repo = new FanPasswordResetRepo($db);

        $repo->create(1, 'a@test.com', 'hash123', $this->past());

        self::assertNull($repo->findValidByToken('hash123'));
    }

    public function testFindValidByTokenCleansUpExpiredRows(): void
    {
        $db   = SqliteDatabase::make(self::DDL);
        $repo = new FanPasswordResetRepo($db);

        $repo->create(1, 'a@test.com', 'expired', $this->past());
        $repo->create(1, 'b@test.com', 'fresh', $this->future());

        // Any lookup opportunistically purges expired rows
        $repo->findValidByToken('fresh');

        self::assertNull($repo->findValidByToken('expired'));
    }

    // --- deleteByEmail (invalidates outstanding tokens on rename)

    public function testDeleteByEmailRemovesMatchingTokens(): void
    {
        $db   = SqliteDatabase::make(self::DDL);
        $repo = new FanPasswordResetRepo($db);

        $repo->create(1, 'old@test.com', 'hash123', $this->future());

        $repo->deleteByEmail(1, 'old@test.com');

        self::assertNull($repo->findValidByToken('hash123'));
    }

    public function testDeleteByEmailDoesNotAffectOtherEmailsOrListings(): void
    {
        $db   = SqliteDatabase::make(self::DDL);
        $repo = new FanPasswordResetRepo($db);

        $repo->create(1, 'old@test.com', 'hash-target', $this->future());
        $repo->create(1, 'other@test.com', 'hash-other', $this->future());
        $repo->create(2, 'old@test.com', 'hash-other-listing', $this->future());

        $repo->deleteByEmail(1, 'old@test.com');

        self::assertNull($repo->findValidByToken('hash-target'));
        self::assertNotNull($repo->findValidByToken('hash-other'));
        self::assertNotNull($repo->findValidByToken('hash-other-listing'));
    }

    // --- deleteByToken (one-time consumption)

    public function testDeleteByTokenRemovesTheToken(): void
    {
        $db   = SqliteDatabase::make(self::DDL);
        $repo = new FanPasswordResetRepo($db);

        $repo->create(1, 'a@test.com', 'hash123', $this->future());

        $repo->deleteByToken('hash123');

        self::assertNull($repo->findValidByToken('hash123'));
    }
}
