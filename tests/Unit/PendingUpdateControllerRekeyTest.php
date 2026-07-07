<?php

declare(strict_types=1);

namespace Luster\Tests\Unit;

use Luster\Controllers\Admin\PendingUpdateController;
use Luster\Core\Config;
use Luster\Repositories\FanPasswordResetRepo;
use Luster\Repositories\MemberLinkCheckRepo;
use Luster\Repositories\PendingUpdateRepo;
use Luster\Tests\Unit\Support\SqliteDatabase;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * PendingUpdateController::rekeyOnEmailChange() re-keys every side table that
 * references a member by email string (no FK) whenever a pending update's
 * approval changes that email -- otherwise queued updates, link-check
 * history, and password-reset tokens are silently orphaned under the old
 * address. Mirrors MemberController::edit()'s admin-side email change.
 */
final class PendingUpdateControllerRekeyTest extends TestCase
{
    private const DDL = <<<SQL
CREATE TABLE lstr_pending_updates (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    fanlisting_id INTEGER NOT NULL,
    member_email  TEXT    NOT NULL,
    changes       TEXT    NOT NULL,
    submitted_at  TEXT    NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE lstr_member_link_checks (
    id                INTEGER PRIMARY KEY AUTOINCREMENT,
    listing_id        INTEGER NOT NULL,
    member_email      TEXT    NOT NULL,
    member_name       TEXT    NOT NULL DEFAULT '',
    member_url        TEXT    NOT NULL,
    link_status       TEXT    NOT NULL DEFAULT 'unchecked',
    link_redirect_url TEXT    NULL,
    link_checked_at   TEXT    NULL,
    backlink_status     TEXT  NOT NULL DEFAULT 'unchecked',
    backlink_checked_at TEXT  NULL,
    created_at        TEXT    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (listing_id, member_email)
);
CREATE TABLE lstr_fan_password_resets (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    fanlisting_id INTEGER NOT NULL,
    email         TEXT    NOT NULL,
    token_hash    TEXT    NOT NULL,
    expires_at    TEXT    NOT NULL
)
SQL;

    private ReflectionMethod $rekey;

    protected function setUp(): void
    {
        $this->rekey = new ReflectionMethod(PendingUpdateController::class, 'rekeyOnEmailChange');
        $this->rekey->setAccessible(true);
    }

    private function makeConfig(): Config
    {
        $tmp = sys_get_temp_dir() . '/lstr_test_cfg_' . uniqid('', true) . '.php';
        file_put_contents($tmp, "<?php return ['enth_table_prefix' => ''];");
        $cfg = new Config($tmp);
        unlink($tmp);
        return $cfg;
    }

    public function testRenamesQueuedUpdatesLinkChecksAndClearsResetTokens(): void
    {
        $db     = SqliteDatabase::make(self::DDL);
        $config = $this->makeConfig();

        // Another queued update still sitting under the old email for the same listing
        $pendingRepo = new PendingUpdateRepo($db);
        $otherPendingId = $pendingRepo->insert(1, 'old@test.com', ['url' => 'https://a.test']);

        // Existing link-check history and an outstanding reset token under the old email
        (new MemberLinkCheckRepo($db, $config))->upsertMember(1, 'old@test.com', 'Alice', 'https://a.test');
        (new FanPasswordResetRepo($db))->create(1, 'old@test.com', hash('sha256', 'sometoken'), '2099-01-01 00:00:00');

        $pending = (object) [
            'fanlisting_id' => 1,
            'member_email'  => 'old@test.com',
            'changes'       => ['email' => 'new@test.com', 'name' => 'Alice'],
        ];

        $this->rekey->invoke(null, $db, $config, $pending);

        self::assertSame('new@test.com', $pendingRepo->getById($otherPendingId)->member_email);

        $checkRow = $db->pdo()->query("SELECT member_email FROM lstr_member_link_checks WHERE listing_id = 1")->fetch();
        self::assertSame('new@test.com', $checkRow['member_email']);

        $resetCount = (int) $db->pdo()->query(
            "SELECT COUNT(*) AS c FROM lstr_fan_password_resets WHERE fanlisting_id = 1 AND email = 'old@test.com'"
        )->fetch()['c'];
        self::assertSame(0, $resetCount);
    }

    public function testNoOpWhenEmailUnchanged(): void
    {
        $db     = SqliteDatabase::make(self::DDL);
        $config = $this->makeConfig();

        $pendingRepo = new PendingUpdateRepo($db);
        $id = $pendingRepo->insert(1, 'same@test.com', ['url' => 'https://a.test']);

        $pending = (object) [
            'fanlisting_id' => 1,
            'member_email'  => 'same@test.com',
            'changes'       => ['name' => 'Alice'],
        ];

        $this->rekey->invoke(null, $db, $config, $pending);

        self::assertSame('same@test.com', $pendingRepo->getById($id)->member_email);
    }
}
