<?php

declare(strict_types=1);

namespace Luster\Tests\Unit;

use Luster\Addons\Kim\KimPasswordResetRepo;
use Luster\Tests\Unit\Support\SqliteDatabase;
use PHPUnit\Framework\TestCase;

final class KimPasswordResetRepoTest extends TestCase
{
    private const DDL = <<<SQL
CREATE TABLE lstr_kim_password_resets (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    kim_member_id INTEGER NOT NULL,
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

    // --- verifyAndConsume (atomic one-time claim)

    public function testVerifyAndConsumeReturnsRowAndConsumesToken(): void
    {
        $db   = SqliteDatabase::make(self::DDL);
        $repo = new KimPasswordResetRepo($db);

        $repo->create(3, 'hash123', $this->future());

        $row = $repo->verifyAndConsume('hash123');

        self::assertNotNull($row);
        self::assertSame(3, (int) $row['kim_member_id']);

        // Consumed - no longer findable or re-consumable
        self::assertNull($repo->findValidByToken('hash123'));
        self::assertNull($repo->verifyAndConsume('hash123'));
    }

    public function testVerifyAndConsumeReturnsNullForUnknownToken(): void
    {
        $db   = SqliteDatabase::make(self::DDL);
        $repo = new KimPasswordResetRepo($db);

        self::assertNull($repo->verifyAndConsume('nope'));
    }

    public function testVerifyAndConsumeReturnsNullForExpiredToken(): void
    {
        $db   = SqliteDatabase::make(self::DDL);
        $repo = new KimPasswordResetRepo($db);

        $repo->create(3, 'hash123', $this->past());

        self::assertNull($repo->verifyAndConsume('hash123'));
    }

    public function testVerifyAndConsumeOnlyOneOfTwoRacingCallsSucceeds(): void
    {
        // Simulates the TOCTOU scenario from LUSTER-SEC-06: two concurrent
        // callers both hold the same raw token. Only one may claim it, even
        // though both would have passed a plain SELECT-based check.
        $db   = SqliteDatabase::make(self::DDL);
        $repo = new KimPasswordResetRepo($db);

        $repo->create(3, 'hash123', $this->future());

        $first  = $repo->verifyAndConsume('hash123');
        $second = $repo->verifyAndConsume('hash123');

        self::assertNotNull($first);
        self::assertNull($second, 'A second concurrent consumption of the same token must fail');
    }
}
