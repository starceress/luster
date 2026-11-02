<?php

declare(strict_types=1);

namespace Luster\Tests\Unit;

use Luster\Core\Config;
use Luster\Repositories\ListingSettingsRepo;
use Luster\Repositories\MemberRepo;
use Luster\Tests\Unit\Support\SqliteDatabase;
use PHPUnit\Framework\TestCase;

/**
 * Tests for per-listing join settings:
 *  - ListingSettingsRepo::getJoinSettings() round-trips
 *  - MemberRepo::pendingJoinColumns(): every new join waits for approval, on
 *    both schemas (JoinController::submit() merges these into the new row)
 *  - Confirmation email tri-state precedence
 *  - Generated password always triggers email regardless of override
 */
final class JoinSettingsTest extends TestCase
{
    private const DDL = <<<SQL
CREATE TABLE lstr_fanlisting_settings (
    fanlisting_id             INTEGER PRIMARY KEY,
    join_confirmation_email   INTEGER NULL DEFAULT NULL
)
SQL;

    /** Unprefixed schema: the listing's dbtable is the member table itself. */
    private const OWNED_MEMBERS_DDL = <<<SQL
CREATE TABLE fl_test (
    email    TEXT    NOT NULL PRIMARY KEY,
    name     TEXT    NOT NULL DEFAULT '',
    country  TEXT    NOT NULL DEFAULT '',
    url      TEXT    NOT NULL DEFAULT '',
    password TEXT    NOT NULL DEFAULT '',
    showurl  INTEGER NOT NULL DEFAULT 1,
    added    TEXT    NULL,
    pending  INTEGER NOT NULL DEFAULT 0
)
SQL;

    /** Standard schema: the member table is {dbprefix}members. */
    private const ENTH_MEMBERS_DDL = <<<SQL
CREATE TABLE fl_test_members (
    id       INTEGER PRIMARY KEY AUTOINCREMENT,
    email    TEXT    NOT NULL DEFAULT '',
    name     TEXT    NOT NULL DEFAULT '',
    country  TEXT    NOT NULL DEFAULT '',
    url      TEXT    NOT NULL DEFAULT '',
    password TEXT    NOT NULL DEFAULT '',
    showurl  INTEGER NOT NULL DEFAULT 1,
    added    TEXT    NULL,
    approved INTEGER NOT NULL DEFAULT 1,
    updated  TEXT    NULL
)
SQL;

    // -----------------------------------------------------------------------
    // Repository reads (inserts done via pdo->exec to avoid MySQL-only upsert syntax)
    // -----------------------------------------------------------------------

    public function testGetJoinSettingsDefaultsWhenNoRow(): void
    {
        $repo = $this->makeRepo();

        $result = $repo->getJoinSettings(99);

        self::assertSame(['confirmation_email' => null], $result);
    }

    public function testGetJoinSettingsReturnsNullConfirmEmail(): void
    {
        [$db, $repo] = $this->makeRepoWithDb();
        $db->pdo()->exec(
            'INSERT INTO lstr_fanlisting_settings (fanlisting_id, join_confirmation_email)
             VALUES (1, NULL)'
        );

        self::assertSame(['confirmation_email' => null], $repo->getJoinSettings(1));
    }

    public function testGetJoinSettingsReturnsTrueConfirmEmail(): void
    {
        [$db, $repo] = $this->makeRepoWithDb();
        $db->pdo()->exec(
            'INSERT INTO lstr_fanlisting_settings (fanlisting_id, join_confirmation_email)
             VALUES (1, 1)'
        );

        self::assertTrue($repo->getJoinSettings(1)['confirmation_email']);
    }

    public function testGetJoinSettingsReturnsFalseConfirmEmail(): void
    {
        [$db, $repo] = $this->makeRepoWithDb();
        $db->pdo()->exec(
            'INSERT INTO lstr_fanlisting_settings (fanlisting_id, join_confirmation_email)
             VALUES (1, 0)'
        );

        self::assertFalse($repo->getJoinSettings(1)['confirmation_email']);
    }

    // -----------------------------------------------------------------------
    // New joins always wait for approval (no auto-approve), on both schemas
    // -----------------------------------------------------------------------

    public function testNewJoinIsPendingOnUnprefixedSchema(): void
    {
        $repo = $this->makeMemberRepo(self::OWNED_MEMBERS_DDL, '');

        self::assertSame(['pending' => 1], $repo->pendingJoinColumns());

        $repo->insert('fl_test', $this->newJoinRow($repo));

        self::assertCount(1, $repo->getPending('fl_test'));
        self::assertCount(0, $repo->getApproved('fl_test'));
    }

    public function testNewJoinIsPendingOnStandardSchema(): void
    {
        $repo = $this->makeMemberRepo(self::ENTH_MEMBERS_DDL, 'enth_');

        $columns = $repo->pendingJoinColumns();
        self::assertSame(0, $columns['approved']);
        self::assertArrayHasKey('updated', $columns);
        self::assertArrayNotHasKey('pending', $columns);

        $repo->insert('fl_test_', $this->newJoinRow($repo));

        self::assertCount(1, $repo->getPending('fl_test_'));
        self::assertCount(0, $repo->getApproved('fl_test_'));
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
     * Mirrors the send-confirmation decision in JoinController::submit().
     */
    private function decideSend(bool $wasGenerated, ?bool $override, bool $sendInfo): bool
    {
        return $wasGenerated
            || ($override !== null ? $override : $sendInfo);
    }

    /**
     * The row JoinController::submit() builds for a new member: the form's
     * columns plus MemberRepo::pendingJoinColumns().
     *
     * @return array<string, int|string>
     */
    private function newJoinRow(MemberRepo $repo): array
    {
        return array_merge([
            'name'     => 'New Fan',
            'email'    => 'new@example.com',
            'url'      => '',
            'country'  => 'Canada',
            'showurl'  => 0,
            'password' => 'hash',
            'added'    => date('Y-m-d H:i:s'),
        ], $repo->pendingJoinColumns());
    }

    private function makeMemberRepo(string $ddl, string $enthTablePrefix): MemberRepo
    {
        $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'lstr_test_cfg_' . uniqid('', true) . '.php';
        file_put_contents($path, '<?php return ' . var_export(['enth_table_prefix' => $enthTablePrefix], true) . ';');
        $config = new Config($path);
        unlink($path);

        return new MemberRepo(SqliteDatabase::make($ddl), $config);
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
