<?php

declare(strict_types=1);

namespace Luster\Tests\Unit;

use Luster\Core\RateLimit;
use Luster\Tests\Unit\Support\SqliteDatabase;
use PHPUnit\Framework\TestCase;

final class RateLimitTest extends TestCase
{
    private const DDL = <<<SQL
CREATE TABLE lstr_rate_limits (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    identifier    TEXT    NOT NULL,
    action        TEXT    NOT NULL,
    attempts      INTEGER NOT NULL DEFAULT 0,
    last_attempt  TEXT    NOT NULL,
    blocked_until TEXT    NULL,
    UNIQUE (identifier, action)
)
SQL;

    private RateLimit $rl;

    protected function setUp(): void
    {
        $this->rl = new RateLimit(SqliteDatabase::make(self::DDL));
    }

    // -----------------------------------------------------------------------
    // hit() — insert path
    // -----------------------------------------------------------------------

    public function testHitInsertsRowOnFirstAttempt(): void
    {
        $this->rl->hit('1.2.3.4', 'test', 5, 3600);

        self::assertFalse($this->rl->isBlocked('1.2.3.4', 'test'));
    }

    public function testHitIncrementsAttemptsWithinWindow(): void
    {
        $this->rl->hit('1.2.3.4', 'test', 5, 3600);
        $this->rl->hit('1.2.3.4', 'test', 5, 3600);
        $this->rl->hit('1.2.3.4', 'test', 5, 3600);

        // 3 of 5 — still not blocked
        self::assertFalse($this->rl->isBlocked('1.2.3.4', 'test'));
    }

    // -----------------------------------------------------------------------
    // hit() — window reset
    // -----------------------------------------------------------------------

    public function testHitResetsAttemptsWhenOutsideWindow(): void
    {
        $db = SqliteDatabase::make(self::DDL);
        $rl = new RateLimit($db);

        // Seed a stale row: 3 attempts with last_attempt 2 hours ago (PHP timestamp = local tz)
        $twoHoursAgo = (new \DateTimeImmutable())->modify('-2 hours')->format('Y-m-d H:i:s');
        $db->pdo()->exec(
            "INSERT INTO lstr_rate_limits (identifier, action, attempts, last_attempt, blocked_until)
             VALUES ('1.2.3.4', 'test', 3, '{$twoHoursAgo}', NULL)"
        );

        // hit() with a 1-hour window — the row is stale, should reset to 1
        $rl->hit('1.2.3.4', 'test', 5, 3600);

        $row = $db->pdo()
            ->query("SELECT attempts FROM lstr_rate_limits WHERE identifier='1.2.3.4' AND action='test'")
            ->fetch();

        self::assertSame(1, (int) $row['attempts']);
    }

    public function testHitResetsBlockedUntilWhenOutsideWindow(): void
    {
        $db = SqliteDatabase::make(self::DDL);
        $rl = new RateLimit($db);

        // Seed a stale blocked row (block has also expired); use PHP timestamps for local tz
        $twoHoursAgo = (new \DateTimeImmutable())->modify('-2 hours')->format('Y-m-d H:i:s');
        $oneHourAgo  = (new \DateTimeImmutable())->modify('-1 hour')->format('Y-m-d H:i:s');
        $db->pdo()->exec(
            "INSERT INTO lstr_rate_limits (identifier, action, attempts, last_attempt, blocked_until)
             VALUES ('1.2.3.4', 'test', 5, '{$twoHoursAgo}', '{$oneHourAgo}')"
        );

        $rl->hit('1.2.3.4', 'test', 10, 3600);

        $row = $db->pdo()
            ->query("SELECT attempts, blocked_until FROM lstr_rate_limits WHERE identifier='1.2.3.4' AND action='test'")
            ->fetch();

        self::assertSame(1, (int) $row['attempts']);
        self::assertNull($row['blocked_until']);
    }

    // -----------------------------------------------------------------------
    // hit() — blocking
    // -----------------------------------------------------------------------

    public function testHitBlocksWhenMaxReached(): void
    {
        // hit() 5 times with max=5 — should become blocked on the 5th call
        for ($i = 0; $i < 5; $i++) {
            $this->rl->hit('1.2.3.4', 'block_test', 5, 3600);
        }

        self::assertTrue($this->rl->isBlocked('1.2.3.4', 'block_test'));
    }

    public function testHitDoesNotBlockBeforeMaxReached(): void
    {
        for ($i = 0; $i < 4; $i++) {
            $this->rl->hit('1.2.3.4', 'block_test', 5, 3600);
        }

        self::assertFalse($this->rl->isBlocked('1.2.3.4', 'block_test'));
    }

    public function testBlockedUntilIsInFutureAfterBlocking(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->rl->hit('1.2.3.4', 'block_test', 5, 3600);
        }

        $until = $this->rl->blockedUntil('1.2.3.4', 'block_test');
        self::assertNotNull($until);
        self::assertGreaterThan(new \DateTimeImmutable(), $until);
    }

    // -----------------------------------------------------------------------
    // isBlocked()
    // -----------------------------------------------------------------------

    public function testIsBlockedReturnsFalseWhenNoRow(): void
    {
        self::assertFalse($this->rl->isBlocked('9.9.9.9', 'nonexistent'));
    }

    public function testIsBlockedReturnsFalseWhenBlockedUntilIsNull(): void
    {
        $db  = SqliteDatabase::make(self::DDL);
        $rl  = new RateLimit($db);
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        $db->pdo()->exec(
            "INSERT INTO lstr_rate_limits (identifier, action, attempts, last_attempt, blocked_until)
             VALUES ('1.2.3.4', 'test', 2, '{$now}', NULL)"
        );

        self::assertFalse($rl->isBlocked('1.2.3.4', 'test'));
    }

    public function testIsBlockedReturnsTrueWhenBlockedUntilInFuture(): void
    {
        $db     = SqliteDatabase::make(self::DDL);
        $rl     = new RateLimit($db);
        $now    = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $future = (new \DateTimeImmutable())->modify('+1 hour')->format('Y-m-d H:i:s');

        $db->pdo()->exec(
            "INSERT INTO lstr_rate_limits (identifier, action, attempts, last_attempt, blocked_until)
             VALUES ('1.2.3.4', 'test', 5, '{$now}', '{$future}')"
        );

        self::assertTrue($rl->isBlocked('1.2.3.4', 'test'));
    }

    public function testIsBlockedReturnsFalseAndClearsWhenBlockExpired(): void
    {
        $db         = SqliteDatabase::make(self::DDL);
        $rl         = new RateLimit($db);
        $twoHoAgo   = (new \DateTimeImmutable())->modify('-2 hours')->format('Y-m-d H:i:s');
        $thirtyMAgo = (new \DateTimeImmutable())->modify('-30 minutes')->format('Y-m-d H:i:s');

        // Block expired 30 minutes ago
        $db->pdo()->exec(
            "INSERT INTO lstr_rate_limits (identifier, action, attempts, last_attempt, blocked_until)
             VALUES ('1.2.3.4', 'test', 5, '{$twoHoAgo}', '{$thirtyMAgo}')"
        );

        self::assertFalse($rl->isBlocked('1.2.3.4', 'test'));

        // Row should have been deleted by isBlocked()
        $row = $db->pdo()
            ->query("SELECT COUNT(*) AS cnt FROM lstr_rate_limits WHERE identifier='1.2.3.4' AND action='test'")
            ->fetch();
        self::assertSame(0, (int) $row['cnt']);
    }

    // -----------------------------------------------------------------------
    // blockedUntil()
    // -----------------------------------------------------------------------

    public function testBlockedUntilReturnsNullWhenNoRow(): void
    {
        self::assertNull($this->rl->blockedUntil('9.9.9.9', 'nonexistent'));
    }

    public function testBlockedUntilReturnsNullWhenExpired(): void
    {
        $db        = SqliteDatabase::make(self::DDL);
        $rl        = new RateLimit($db);
        $oneHoAgo  = (new \DateTimeImmutable())->modify('-1 hour')->format('Y-m-d H:i:s');
        $tenMinAgo = (new \DateTimeImmutable())->modify('-10 minutes')->format('Y-m-d H:i:s');

        $db->pdo()->exec(
            "INSERT INTO lstr_rate_limits (identifier, action, attempts, last_attempt, blocked_until)
             VALUES ('1.2.3.4', 'test', 5, '{$oneHoAgo}', '{$tenMinAgo}')"
        );

        self::assertNull($rl->blockedUntil('1.2.3.4', 'test'));
    }

    public function testBlockedUntilReturnsFutureDatetime(): void
    {
        $db     = SqliteDatabase::make(self::DDL);
        $rl     = new RateLimit($db);
        $now    = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $future = (new \DateTimeImmutable())->modify('+1 hour')->format('Y-m-d H:i:s');

        $db->pdo()->exec(
            "INSERT INTO lstr_rate_limits (identifier, action, attempts, last_attempt, blocked_until)
             VALUES ('1.2.3.4', 'test', 5, '{$now}', '{$future}')"
        );

        $until = $rl->blockedUntil('1.2.3.4', 'test');
        self::assertNotNull($until);
        self::assertGreaterThan(new \DateTimeImmutable(), $until);
    }

    // -----------------------------------------------------------------------
    // clear()
    // -----------------------------------------------------------------------

    public function testClearDeletesRow(): void
    {
        $this->rl->hit('1.2.3.4', 'test', 5, 3600);
        $this->rl->clear('1.2.3.4', 'test');

        self::assertFalse($this->rl->isBlocked('1.2.3.4', 'test'));
        self::assertNull($this->rl->blockedUntil('1.2.3.4', 'test'));
    }

    public function testClearIsNoOpWhenNoRow(): void
    {
        // Should not throw
        $this->rl->clear('9.9.9.9', 'nonexistent');
        self::assertTrue(true);
    }

    // -----------------------------------------------------------------------
    // hitDefault()
    // -----------------------------------------------------------------------

    public function testHitDefaultUsesConfiguredLimits(): void
    {
        // ACTION_PUBLIC_CODE_SUBMIT: max=5, window=3600
        // Hit 5 times — should become blocked
        for ($i = 0; $i < 5; $i++) {
            $this->rl->hitDefault('1.2.3.4', RateLimit::ACTION_PUBLIC_CODE_SUBMIT);
        }

        self::assertTrue($this->rl->isBlocked('1.2.3.4', RateLimit::ACTION_PUBLIC_CODE_SUBMIT));
    }

    public function testHitDefaultFallsBackToTenPerHourForUnknownAction(): void
    {
        // 9 hits on an unknown action should not block
        for ($i = 0; $i < 9; $i++) {
            $this->rl->hitDefault('1.2.3.4', 'unknown_action_xyz');
        }
        self::assertFalse($this->rl->isBlocked('1.2.3.4', 'unknown_action_xyz'));

        // 10th hit should block
        $this->rl->hitDefault('1.2.3.4', 'unknown_action_xyz');
        self::assertTrue($this->rl->isBlocked('1.2.3.4', 'unknown_action_xyz'));
    }

    // -----------------------------------------------------------------------
    // Constants
    // -----------------------------------------------------------------------

    public function testActionConstantsAreDefined(): void
    {
        self::assertSame('public_code_submit',     RateLimit::ACTION_PUBLIC_CODE_SUBMIT);
        self::assertSame('admin_login',            RateLimit::ACTION_ADMIN_LOGIN);
        self::assertSame('public_join',            RateLimit::ACTION_PUBLIC_JOIN);
        self::assertSame('public_affiliate_apply', RateLimit::ACTION_PUBLIC_AFFILIATE_APPLY);
    }

    // -----------------------------------------------------------------------
    // getActiveBlocks()
    // -----------------------------------------------------------------------

    public function testGetActiveBlocksReturnsEmptyWhenNothingBlocked(): void
    {
        self::assertSame([], $this->rl->getActiveBlocks());
    }

    public function testGetActiveBlocksIncludesCurrentlyBlockedRow(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->rl->hit('1.2.3.4', RateLimit::ACTION_PUBLIC_AFFILIATE_APPLY, 5, 3600);
        }

        $blocks = $this->rl->getActiveBlocks();

        self::assertCount(1, $blocks);
        self::assertSame('1.2.3.4', $blocks[0]['identifier']);
        self::assertSame(RateLimit::ACTION_PUBLIC_AFFILIATE_APPLY, $blocks[0]['action']);
        self::assertSame('Affiliate Application', $blocks[0]['action_label']);
        self::assertSame(5, $blocks[0]['attempts']);
        self::assertGreaterThan(0, $blocks[0]['id']);
    }

    public function testGetActiveBlocksExcludesRowsNotYetBlocked(): void
    {
        // 3 of 5 attempts — accumulating, but not blocked yet
        for ($i = 0; $i < 3; $i++) {
            $this->rl->hit('1.2.3.4', 'test', 5, 3600);
        }

        self::assertSame([], $this->rl->getActiveBlocks());
    }

    public function testGetActiveBlocksExcludesExpiredBlocks(): void
    {
        $db  = SqliteDatabase::make(self::DDL);
        $rl  = new RateLimit($db);
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $past = (new \DateTimeImmutable())->modify('-10 minutes')->format('Y-m-d H:i:s');

        $db->pdo()->exec(
            "INSERT INTO lstr_rate_limits (identifier, action, attempts, last_attempt, blocked_until)
             VALUES ('1.2.3.4', 'test', 5, '{$now}', '{$past}')"
        );

        self::assertSame([], $rl->getActiveBlocks());
    }

    // -----------------------------------------------------------------------
    // clearById()
    // -----------------------------------------------------------------------

    public function testClearByIdRemovesOnlyThatRow(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->rl->hit('1.2.3.4', 'action_a', 5, 3600);
        }
        for ($i = 0; $i < 5; $i++) {
            $this->rl->hit('5.6.7.8', 'action_b', 5, 3600);
        }

        $blocks = $this->rl->getActiveBlocks();
        self::assertCount(2, $blocks);

        $idToClear = $blocks[0]['identifier'] === '1.2.3.4' ? $blocks[0]['id'] : $blocks[1]['id'];
        $this->rl->clearById($idToClear);

        $remaining = $this->rl->getActiveBlocks();
        self::assertCount(1, $remaining);
        self::assertFalse($this->rl->isBlocked('1.2.3.4', 'action_a'));
        self::assertTrue($this->rl->isBlocked('5.6.7.8', 'action_b'));
    }

    public function testClearByIdIsNoOpForUnknownId(): void
    {
        // Should not throw
        $this->rl->clearById(99999);
        self::assertTrue(true);
    }

    // -----------------------------------------------------------------------
    // clearAllForIdentifier()
    // -----------------------------------------------------------------------

    public function testClearAllForIdentifierClearsEveryActionForThatIp(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->rl->hit('1.2.3.4', 'action_a', 5, 3600);
        }
        for ($i = 0; $i < 5; $i++) {
            $this->rl->hit('1.2.3.4', 'action_b', 5, 3600);
        }
        for ($i = 0; $i < 5; $i++) {
            $this->rl->hit('5.6.7.8', 'action_a', 5, 3600);
        }

        $this->rl->clearAllForIdentifier('1.2.3.4');

        self::assertFalse($this->rl->isBlocked('1.2.3.4', 'action_a'));
        self::assertFalse($this->rl->isBlocked('1.2.3.4', 'action_b'));
        // A different identifier's block must be untouched
        self::assertTrue($this->rl->isBlocked('5.6.7.8', 'action_a'));
    }

    // -----------------------------------------------------------------------
    // actionLabel()
    // -----------------------------------------------------------------------

    public function testActionLabelReturnsFriendlyNameForKnownAction(): void
    {
        self::assertSame('Affiliate Application', RateLimit::actionLabel(RateLimit::ACTION_PUBLIC_AFFILIATE_APPLY));
        self::assertSame('Admin Login', RateLimit::actionLabel(RateLimit::ACTION_ADMIN_LOGIN));
    }

    public function testActionLabelFallsBackToRawActionForUnknownAction(): void
    {
        self::assertSame('some_custom_action', RateLimit::actionLabel('some_custom_action'));
    }

    // -----------------------------------------------------------------------
    // Isolation — different actions for same identifier don't interfere
    // -----------------------------------------------------------------------

    public function testDifferentActionsAreIndependent(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->rl->hit('1.2.3.4', 'action_a', 5, 3600);
        }

        self::assertTrue($this->rl->isBlocked('1.2.3.4', 'action_a'));
        self::assertFalse($this->rl->isBlocked('1.2.3.4', 'action_b'));
    }

    public function testDifferentIdentifiersAreIndependent(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->rl->hit('1.2.3.4', 'test', 5, 3600);
        }

        self::assertTrue($this->rl->isBlocked('1.2.3.4', 'test'));
        self::assertFalse($this->rl->isBlocked('5.6.7.8', 'test'));
    }
}
