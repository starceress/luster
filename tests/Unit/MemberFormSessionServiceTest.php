<?php

declare(strict_types=1);

namespace Luster\Tests\Unit;

use Luster\Core\Database;
use Luster\Core\MemberFormSessionService;
use Luster\Repositories\MemberFormSessionRepo;
use Luster\Tests\Unit\Support\SqliteDatabase;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Tests MemberFormSessionService (issue/consume) and MemberFormSessionRepo
 * together against an in-memory SQLite-backed lstr_member_form_sessions
 * table. Covers the fallback-auth contract: single-use, action-bound,
 * listing-bound, time-limited tokens.
 */
final class MemberFormSessionServiceTest extends TestCase
{
    private const STAMP = 'a-members-stamp';

    private const DDL = <<<SQL
CREATE TABLE lstr_member_form_sessions (
    id             INTEGER PRIMARY KEY AUTOINCREMENT,
    token_hash     TEXT    NOT NULL,
    fanlisting_id  INTEGER NOT NULL,
    member_email   TEXT    NOT NULL,
    allowed_action TEXT    NOT NULL,
    expires_at     TEXT    NOT NULL,
    used_at        TEXT    NULL,
    created_at     TEXT    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    member_stamp   TEXT    NULL
)
SQL;

    /** @return array{MemberFormSessionService, MemberFormSessionRepo, PDO} */
    private function make(): array
    {
        $db   = SqliteDatabase::make(self::DDL);
        $repo = new MemberFormSessionRepo($db);

        $prop = new \ReflectionProperty($db, 'pdo');
        $prop->setAccessible(true);
        $pdo = $prop->getValue($db);

        return [new MemberFormSessionService($repo), $repo, $pdo];
    }

    // -----------------------------------------------------------------------
    // issue() — action allowlist
    // -----------------------------------------------------------------------

    public function testIssueReturnsRawTokenForUpdateAction(): void
    {
        [$service] = $this->make();

        $token = $service->issue(1, 'fan@example.com', 'update', self::STAMP);

        self::assertNotNull($token);
        self::assertSame(64, strlen($token), 'Raw token should be 32 bytes hex-encoded (64 chars)');
    }

    public function testIssueReturnsRawTokenForDeleteAction(): void
    {
        [$service] = $this->make();

        self::assertNotNull($service->issue(1, 'fan@example.com', 'delete', self::STAMP));
    }

    public function testIssueRejectsActionNotInAllowlist(): void
    {
        [$service] = $this->make();

        self::assertNull($service->issue(1, 'fan@example.com', 'kim_update', self::STAMP));
        self::assertNull($service->issue(1, 'fan@example.com', 'anything', self::STAMP));
    }

    public function testIssueDoesNotPersistRawTokenOnlyItsHash(): void
    {
        [$service, , $pdo] = $this->make();

        $token = $service->issue(1, 'fan@example.com', 'update', self::STAMP);
        $row   = $pdo->query('SELECT token_hash FROM lstr_member_form_sessions LIMIT 1')->fetch();

        self::assertNotSame($token, $row['token_hash']);
        self::assertSame(hash('sha256', (string) $token), $row['token_hash']);
    }

    // -----------------------------------------------------------------------
    // consume() — happy path
    // -----------------------------------------------------------------------

    public function testConsumeReturnsEmailOnFullMatch(): void
    {
        [$service] = $this->make();

        $token = $service->issue(5, 'fan@example.com', 'update', self::STAMP);

        self::assertSame(['email' => 'fan@example.com', 'stamp' => self::STAMP], $service->consume(5, (string) $token, 'update'));
    }

    public function testPeekSaysWhatATokenWouldGiveWithoutUsingIt(): void
    {
        [$service] = $this->make();

        $token = $service->issue(5, 'fan@example.com', 'update', self::STAMP);

        self::assertSame(['email' => 'fan@example.com', 'stamp' => self::STAMP], $service->peek(5, (string) $token, 'update'));
        self::assertSame(['email' => 'fan@example.com', 'stamp' => self::STAMP], $service->peek(5, (string) $token, 'update'));
        self::assertNull($service->peek(99, (string) $token, 'update'), 'another listing');
        self::assertNull($service->peek(5, (string) $token, 'delete'), 'another action');
        self::assertNull($service->peek(5, '', 'update'));
        self::assertNotNull($service->consume(5, (string) $token, 'update'), 'peeking used nothing up');
        self::assertNull($service->peek(5, (string) $token, 'update'), 'and a used token is gone');
    }

    public function testATokenFromBeforeTheMigrationHasNoStamp(): void
    {
        [$service, , $pdo] = $this->make();

        $token = $service->issue(5, 'fan@example.com', 'update', self::STAMP);
        $pdo->exec('UPDATE lstr_member_form_sessions SET member_stamp = NULL');

        self::assertSame(['email' => 'fan@example.com', 'stamp' => ''], $service->consume(5, (string) $token, 'update'));
    }

    public function testBeforeTheMigrationNoTokensAreIssuedOrAccepted(): void
    {
        $db   = SqliteDatabase::make(str_replace(",\n    member_stamp   TEXT    NULL", '', self::DDL));
        $repo = new MemberFormSessionRepo($db);
        $service = new MemberFormSessionService($repo);

        self::assertFalse($repo->supportsStamps());
        self::assertNull($service->issue(5, 'fan@example.com', 'update', self::STAMP));
        self::assertNull($service->peek(5, str_repeat('a', 64), 'update'));
        self::assertNull($service->consume(5, str_repeat('a', 64), 'update'));
    }

    public function testConsumeMarksTokenUsed(): void
    {
        [$service, , $pdo] = $this->make();

        $token = $service->issue(5, 'fan@example.com', 'update', self::STAMP);
        $service->consume(5, (string) $token, 'update');

        $row = $pdo->query('SELECT used_at FROM lstr_member_form_sessions LIMIT 1')->fetch();
        self::assertNotNull($row['used_at']);
    }

    // -----------------------------------------------------------------------
    // consume() — rejection cases
    // -----------------------------------------------------------------------

    public function testConsumeRejectsReuseOfAlreadyUsedToken(): void
    {
        [$service] = $this->make();

        $token = $service->issue(5, 'fan@example.com', 'update', self::STAMP);
        self::assertNotNull($service->consume(5, (string) $token, 'update'));

        // Second consume of the exact same raw token must fail.
        self::assertNull($service->consume(5, (string) $token, 'update'));
    }

    public function testConsumeRejectsWrongListing(): void
    {
        [$service] = $this->make();

        $token = $service->issue(5, 'fan@example.com', 'update', self::STAMP);

        self::assertNull($service->consume(99, (string) $token, 'update'));
    }

    public function testConsumeRejectsWrongAction(): void
    {
        [$service] = $this->make();

        $token = $service->issue(5, 'fan@example.com', 'update', self::STAMP);

        self::assertNull($service->consume(5, (string) $token, 'delete'));
    }

    public function testConsumeRejectsActionNotInAllowlist(): void
    {
        [$service] = $this->make();

        $token = $service->issue(5, 'fan@example.com', 'update', self::STAMP);

        self::assertNull($service->consume(5, (string) $token, 'kim_update'));
    }

    public function testConsumeRejectsUnknownToken(): void
    {
        [$service] = $this->make();

        self::assertNull($service->consume(5, str_repeat('a', 64), 'update'));
    }

    public function testConsumeRejectsEmptyToken(): void
    {
        [$service] = $this->make();

        self::assertNull($service->consume(5, '', 'update'));
    }

    public function testConsumeRejectsExpiredToken(): void
    {
        [$service, , $pdo] = $this->make();

        $token = $service->issue(5, 'fan@example.com', 'update', self::STAMP);
        // Force the row into the past.
        $pdo->exec("UPDATE lstr_member_form_sessions SET expires_at = datetime('now', '-1 hour')");

        self::assertNull($service->consume(5, (string) $token, 'update'));
    }

    public function testWrongListingDoesNotConsumeTheTokenForALegitimateRetry(): void
    {
        [$service] = $this->make();

        $token = $service->issue(5, 'fan@example.com', 'update', self::STAMP);

        // A mismatched attempt must not burn the token...
        self::assertNull($service->consume(99, (string) $token, 'update'));

        // ...so the legitimate listing can still consume it afterward.
        self::assertSame('fan@example.com', $service->consume(5, (string) $token, 'update')['email'] ?? null);
    }

    // -----------------------------------------------------------------------
    // Independent tokens per action
    // -----------------------------------------------------------------------

    public function testUpdateAndDeleteTokensAreIndependent(): void
    {
        [$service] = $this->make();

        $updateToken = $service->issue(5, 'fan@example.com', 'update', self::STAMP);
        $deleteToken = $service->issue(5, 'fan@example.com', 'delete', self::STAMP);

        self::assertNotSame($updateToken, $deleteToken);
        self::assertSame('fan@example.com', $service->consume(5, (string) $deleteToken, 'delete')['email'] ?? null);
        // The update token must remain valid and unaffected by consuming the delete token.
        self::assertSame('fan@example.com', $service->consume(5, (string) $updateToken, 'update')['email'] ?? null);
    }

    // -----------------------------------------------------------------------
    // MemberFormSessionRepo — deleteExpired()
    // -----------------------------------------------------------------------

    public function testDeleteExpiredRemovesOnlyExpiredRows(): void
    {
        [$service, $repo, $pdo] = $this->make();

        $service->issue(5, 'fan@example.com', 'update', self::STAMP);
        $pdo->exec("UPDATE lstr_member_form_sessions SET expires_at = datetime('now', '-1 hour')");
        $service->issue(5, 'other@example.com', 'update', self::STAMP);

        $repo->deleteExpired();

        $count = (int) $pdo->query('SELECT COUNT(*) FROM lstr_member_form_sessions')->fetchColumn();
        self::assertSame(1, $count);
    }
}
