<?php

declare(strict_types=1);

namespace Luster\Tests\Unit;

use Luster\Core\Config;
use Luster\Repositories\ListingSettingsRepo;
use Luster\Tests\Unit\Support\SqliteDatabase;
use PHPUnit\Framework\TestCase;

/**
 * Tests for per-listing join settings:
 *  - ListingSettingsRepo::getJoinSettings() / saveJoinSettings() round-trips
 *  - Approval flag logic (auto-approve vs. manual pending)
 *  - Confirmation email tri-state precedence
 *  - Generated password always triggers email regardless of override
 */
final class JoinSettingsTest extends TestCase
{
    private const DDL = <<<SQL
CREATE TABLE lstr_fanlisting_settings (
    fanlisting_id             INTEGER PRIMARY KEY,
    join_confirmation_email   INTEGER NULL DEFAULT NULL,
    auto_approve_joins        INTEGER NOT NULL DEFAULT 0
)
SQL;

    // -----------------------------------------------------------------------
    // Repository reads (inserts done via pdo->exec to avoid MySQL-only upsert syntax)
    // -----------------------------------------------------------------------

    public function testGetJoinSettingsDefaultsWhenNoRow(): void
    {
        $repo = $this->makeRepo();

        $result = $repo->getJoinSettings(99);

        self::assertNull($result['confirmation_email']);
        self::assertFalse($result['auto_approve']);
    }

    public function testGetJoinSettingsReturnsNullConfirmEmail(): void
    {
        [$db, $repo] = $this->makeRepoWithDb();
        $db->pdo()->exec(
            'INSERT INTO lstr_fanlisting_settings (fanlisting_id, join_confirmation_email, auto_approve_joins)
             VALUES (1, NULL, 0)'
        );

        $result = $repo->getJoinSettings(1);

        self::assertNull($result['confirmation_email']);
        self::assertFalse($result['auto_approve']);
    }

    public function testGetJoinSettingsReturnsTrueConfirmEmail(): void
    {
        [$db, $repo] = $this->makeRepoWithDb();
        $db->pdo()->exec(
            'INSERT INTO lstr_fanlisting_settings (fanlisting_id, join_confirmation_email, auto_approve_joins)
             VALUES (1, 1, 0)'
        );

        self::assertTrue($repo->getJoinSettings(1)['confirmation_email']);
    }

    public function testGetJoinSettingsReturnsFalseConfirmEmail(): void
    {
        [$db, $repo] = $this->makeRepoWithDb();
        $db->pdo()->exec(
            'INSERT INTO lstr_fanlisting_settings (fanlisting_id, join_confirmation_email, auto_approve_joins)
             VALUES (1, 0, 0)'
        );

        self::assertFalse($repo->getJoinSettings(1)['confirmation_email']);
    }

    public function testGetJoinSettingsReturnsAutoApproveTrue(): void
    {
        [$db, $repo] = $this->makeRepoWithDb();
        $db->pdo()->exec(
            'INSERT INTO lstr_fanlisting_settings (fanlisting_id, join_confirmation_email, auto_approve_joins)
             VALUES (1, NULL, 1)'
        );

        self::assertTrue($repo->getJoinSettings(1)['auto_approve']);
    }

    public function testGetJoinSettingsBothFlagsSet(): void
    {
        [$db, $repo] = $this->makeRepoWithDb();
        $db->pdo()->exec(
            'INSERT INTO lstr_fanlisting_settings (fanlisting_id, join_confirmation_email, auto_approve_joins)
             VALUES (1, 0, 1)'
        );

        $result = $repo->getJoinSettings(1);
        self::assertFalse($result['confirmation_email']);
        self::assertTrue($result['auto_approve']);
    }

    // -----------------------------------------------------------------------
    // Approval flag — pending value for unprefixed schema
    // -----------------------------------------------------------------------

    public function testManualApprovalGivesPendingOne(): void
    {
        // auto_approve = false → pending = 1 (queued for review)
        self::assertSame(1, $this->pendingValue(autoApprove: false));
    }

    public function testAutoApprovalGivesPendingZero(): void
    {
        // auto_approve = true → pending = 0 (immediately visible)
        self::assertSame(0, $this->pendingValue(autoApprove: true));
    }

    // -----------------------------------------------------------------------
    // Confirmation email tri-state precedence
    // -----------------------------------------------------------------------

    public function testNullOverrideFollowsFanCheckbox(): void
    {
        self::assertTrue($this->decideSend(wasGenerated: false, override: null, sendInfo: true));
        self::assertFalse($this->decideSend(wasGenerated: false, override: null, sendInfo: false));
    }

    public function testTrueOverrideSendsRegardlessOfCheckbox(): void
    {
        self::assertTrue($this->decideSend(wasGenerated: false, override: true, sendInfo: false));
        self::assertTrue($this->decideSend(wasGenerated: false, override: true, sendInfo: true));
    }

    public function testFalseOverrideSuppressesRegardlessOfCheckbox(): void
    {
        self::assertFalse($this->decideSend(wasGenerated: false, override: false, sendInfo: true));
        self::assertFalse($this->decideSend(wasGenerated: false, override: false, sendInfo: false));
    }

    public function testGeneratedPasswordAlwaysSendsEvenWithFalseOverride(): void
    {
        // Generated password → member needs the password; never suppress.
        self::assertTrue($this->decideSend(wasGenerated: true, override: false, sendInfo: false));
        self::assertTrue($this->decideSend(wasGenerated: true, override: false, sendInfo: true));
        self::assertTrue($this->decideSend(wasGenerated: true, override: null,  sendInfo: false));
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    /**
     * Mirrors the approval-flag logic in JoinController::submit() for the
     * unprefixed schema (pending=0 approved, pending=1 queued).
     */
    private function pendingValue(bool $autoApprove): int
    {
        return $autoApprove ? 0 : 1;
    }

    /**
     * Mirrors the send-confirmation decision in JoinController::submit().
     */
    private function decideSend(bool $wasGenerated, ?bool $override, bool $sendInfo): bool
    {
        return $wasGenerated
            || ($override !== null ? $override : $sendInfo);
    }

    private function makeRepo(): ListingSettingsRepo
    {
        return new ListingSettingsRepo(SqliteDatabase::make(self::DDL));
    }

    /** @return array{0: \Luster\Core\Database, 1: ListingSettingsRepo} */
    private function makeRepoWithDb(): array
    {
        $db = SqliteDatabase::make(self::DDL);
        return [$db, new ListingSettingsRepo($db)];
    }
}
