<?php

declare(strict_types=1);

namespace Luster\Tests\Unit;

use Luster\Core\Config;
use Luster\Core\Database;
use Luster\Repositories\MemberLinkCheckRepo;
use Luster\Tests\Unit\Support\SqliteDatabase;
use PDO;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

final class MemberLinkCheckRepoTest extends TestCase
{
    // --- DDL

    private const CHECK_DDL = <<<SQL
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
)
SQL;

    /** Member table for the unprefixed schema — table name = dbprefix (no 'members' suffix). */
    private const MEMBER_DDL = <<<SQL
CREATE TABLE test_fl (
    email   TEXT    PRIMARY KEY,
    name    TEXT    NOT NULL DEFAULT '',
    url     TEXT    NOT NULL DEFAULT '',
    pending INTEGER NOT NULL DEFAULT 0
)
SQL;

    /** Member table for the standard enth_ schema — table name = dbprefix + 'members'. */
    private const ENTH_MEMBER_DDL = <<<SQL
CREATE TABLE enth_fl_members (
    email    TEXT    PRIMARY KEY,
    name     TEXT    NOT NULL DEFAULT '',
    url      TEXT    NOT NULL DEFAULT '',
    approved INTEGER NOT NULL DEFAULT 1
)
SQL;

    // --- Helpers

    private function makeDb(string $extraDdl = ''): Database
    {
        $ddl = self::CHECK_DDL;
        if ($extraDdl !== '') {
            $ddl .= ';' . $extraDdl;
        }
        return SqliteDatabase::make($ddl);
    }

    private function makeConfig(string $enthPrefix = ''): Config
    {
        $tmp = sys_get_temp_dir() . '/lstr_test_cfg_' . uniqid('', true) . '.php';
        file_put_contents($tmp, '<?php return ' . var_export(['enth_table_prefix' => $enthPrefix], true) . ';');
        $cfg = new Config($tmp);
        unlink($tmp);
        return $cfg;
    }

    private function makeRepo(Database $db, string $enthPrefix = ''): MemberLinkCheckRepo
    {
        return new MemberLinkCheckRepo($db, $this->makeConfig($enthPrefix));
    }

    /** Inserts a row into the unprefixed member table. */
    private function insertMember(Database $db, string $email, string $name, string $url, int $pending = 0): void
    {
        $db->pdo()->prepare('INSERT INTO test_fl (email, name, url, pending) VALUES (:e, :n, :u, :p)')
           ->execute(['e' => $email, 'n' => $name, 'u' => $url, 'p' => $pending]);
    }

    /** Inserts a check record directly (bypasses sync). */
    private function insertCheck(Database $db, int $listingId, string $email, string $name, string $url, string $status = 'unchecked'): int
    {
        $db->pdo()->prepare(
            'INSERT INTO lstr_member_link_checks (listing_id, member_email, member_name, member_url, link_status) VALUES (:l, :e, :n, :u, :s)'
        )->execute(['l' => $listingId, 'e' => $email, 'n' => $name, 'u' => $url, 's' => $status]);
        return (int) $db->pdo()->lastInsertId();
    }

    // --- syncFromListing (unprefixed schema)

    public function testSyncFromListingIncludesApprovedMembersWithoutUrl(): void
    {
        $db   = $this->makeDb(self::MEMBER_DDL);
        $repo = $this->makeRepo($db);

        $this->insertMember($db, 'a@test.com', 'Alice', '');

        self::assertSame(['synced' => 1, 'skipped' => []], $repo->syncFromListing(1, 'test_fl'));
        $items = $repo->paginate(1, '', '', 'member_name', 'asc', 100, 1)['items'];
        self::assertCount(1, $items);
        self::assertSame('no_url', $items[0]->link_status);
    }

    public function testSyncFromListingReturnsZeroWhenNoApprovedMembers(): void
    {
        $db   = $this->makeDb(self::MEMBER_DDL);
        $repo = $this->makeRepo($db);

        $this->insertMember($db, 'a@test.com', 'Alice', 'https://alice.test', 1); // pending

        self::assertSame(['synced' => 0, 'skipped' => []], $repo->syncFromListing(1, 'test_fl'));
    }

    public function testSyncFromListingInsertsApprovedMembersWithUrl(): void
    {
        $db   = $this->makeDb(self::MEMBER_DDL);
        $repo = $this->makeRepo($db);

        $this->insertMember($db, 'a@test.com', 'Alice', 'https://alice.test');
        $this->insertMember($db, 'b@test.com', 'Bob',   'https://bob.test');

        $count = $repo->syncFromListing(1, 'test_fl')['synced'];

        self::assertSame(2, $count);
        $items = $repo->paginate(1, '', '', 'member_name', 'asc', 100, 1)['items'];
        self::assertCount(2, $items);
    }

    public function testSyncFromListingSkipsPendingMembers(): void
    {
        $db   = $this->makeDb(self::MEMBER_DDL);
        $repo = $this->makeRepo($db);

        $this->insertMember($db, 'a@test.com', 'Alice', 'https://alice.test', 1); // pending
        $this->insertMember($db, 'b@test.com', 'Bob',   'https://bob.test',   0); // approved

        $count = $repo->syncFromListing(1, 'test_fl')['synced'];

        self::assertSame(1, $count);
    }

    public function testSyncFromListingClearsPreviousRecords(): void
    {
        $db   = $this->makeDb(self::MEMBER_DDL);
        $repo = $this->makeRepo($db);

        // First sync
        $this->insertMember($db, 'a@test.com', 'Alice', 'https://alice.test');
        $repo->syncFromListing(1, 'test_fl');
        self::assertSame(1, $repo->paginate(1, '', '', 'member_name', 'asc', 100, 1)['total']);

        // Remove Alice entirely, add Bob — second sync should give a fresh snapshot
        $db->pdo()->exec("DELETE FROM test_fl WHERE email = 'a@test.com'");
        $this->insertMember($db, 'b@test.com', 'Bob', 'https://bob.test');

        $count = $repo->syncFromListing(1, 'test_fl')['synced'];

        self::assertSame(1, $count);
        $items = $repo->paginate(1, '', '', 'member_name', 'asc', 100, 1)['items'];
        self::assertCount(1, $items);
        self::assertSame('Bob', $items[0]->member_name);
    }

    // --- syncFromListing (standard enth_ schema)

    public function testSyncFromListingStandardSchemaUsesApprovedColumn(): void
    {
        $db   = $this->makeDb(self::ENTH_MEMBER_DDL);
        $repo = $this->makeRepo($db, 'enth_');

        // enth_ schema: approved=1 means approved; table name = prefix + 'members'
        $db->pdo()->prepare(
            'INSERT INTO enth_fl_members (email, name, url, approved) VALUES (:e, :n, :u, :a)'
        )->execute(['e' => 'c@test.com', 'n' => 'Carol', 'u' => 'https://carol.test', 'a' => 1]);
        $db->pdo()->prepare(
            'INSERT INTO enth_fl_members (email, name, url, approved) VALUES (:e, :n, :u, :a)'
        )->execute(['e' => 'd@test.com', 'n' => 'Dave',  'u' => 'https://dave.test',  'a' => 0]); // not approved

        $count = $repo->syncFromListing(5, 'enth_fl_')['synced'];

        self::assertSame(1, $count);
        $items = $repo->paginate(5, '', '', 'member_name', 'asc', 100, 1)['items'];
        self::assertSame('Carol', $items[0]->member_name);
    }

    // --- syncFromListing: all or nothing

    public function testAFailedSyncLeavesEveryRowAsItWas(): void
    {
        $db   = $this->makeDb(self::MEMBER_DDL);
        $repo = $this->makeRepo($db);
        $pdo  = $db->pdo();

        // Earlier results for listing 1, and another listing's rows.
        $pdo->exec("INSERT INTO lstr_member_link_checks
            (listing_id, member_email, member_name, member_url, link_status, link_redirect_url, link_checked_at, backlink_status, backlink_checked_at)
            VALUES
            (1, 'amber@test.com', 'Amber', 'https://amber.test', 'ok', NULL, '2026-10-01 10:00:00', 'found', '2026-10-01 10:05:00'),
            (1, 'zoe@test.com', 'Zoe', 'https://zoe.test', 'redirect', 'https://zoe.new', '2026-10-01 10:00:00', 'missing', '2026-10-01 10:05:00'),
            (2, 'other@test.com', 'Other', 'https://other.test', 'dead', NULL, '2026-10-02 10:00:00', 'unchecked', NULL)");
        $before = $pdo->query('SELECT * FROM lstr_member_link_checks ORDER BY id')->fetchAll();

        // Amber is inserted first, then Bob's insert fails.
        $this->insertMember($db, 'amber@test.com', 'Amber', 'https://amber.test');
        $this->insertMember($db, 'bob@test.com', 'Bob', 'https://bob.test');
        $this->insertMember($db, 'zoe@test.com', 'Zoe', 'https://zoe.test');
        $pdo->exec("CREATE TRIGGER refuse_bob BEFORE INSERT ON lstr_member_link_checks
                    WHEN NEW.member_email = 'bob@test.com'
                    BEGIN SELECT RAISE(ABORT, 'insert refused'); END");

        try {
            $repo->syncFromListing(1, 'test_fl');
            self::fail('The sync should fail.');
        } catch (\PDOException $e) {
            self::assertStringContainsString('insert refused', $e->getMessage());
        }

        self::assertSame($before, $pdo->query('SELECT * FROM lstr_member_link_checks ORDER BY id')->fetchAll());
        self::assertFalse($pdo->inTransaction());
    }

    public function testAnAlreadyOpenTransactionIsLeftAlone(): void
    {
        $db   = $this->makeDb(self::MEMBER_DDL);
        $repo = $this->makeRepo($db);
        $pdo  = $db->pdo();
        $earlier = $this->insertCheck($db, 1, 'amber@test.com', 'Amber', 'https://amber.test', 'ok');
        $this->insertMember($db, 'zoe@test.com', 'Zoe', 'https://zoe.test');

        $pdo->beginTransaction();
        $this->insertCheck($db, 3, 'pending@test.com', 'Pending', 'https://pending.test');

        try {
            $repo->syncFromListing(1, 'test_fl');
            self::fail('The sync should refuse to run inside a transaction.');
        } catch (\LogicException) {
        }

        self::assertTrue($pdo->inTransaction(), "the caller's transaction is still open");
        self::assertSame(1, (int) $pdo->query("SELECT COUNT(*) FROM lstr_member_link_checks WHERE member_email = 'pending@test.com'")->fetchColumn());
        self::assertSame('ok', $repo->getById($earlier)->link_status);
        $pdo->rollBack();
    }

    public function testAnEmptyMemberTableClearsTheListing(): void
    {
        $db   = $this->makeDb(self::MEMBER_DDL);
        $repo = $this->makeRepo($db);
        $this->insertCheck($db, 1, 'gone@test.com', 'Gone', 'https://gone.test', 'ok');
        $other = $this->insertCheck($db, 2, 'other@test.com', 'Other', 'https://other.test', 'ok');

        self::assertSame(['synced' => 0, 'skipped' => []], $repo->syncFromListing(1, 'test_fl'));
        self::assertSame(0, $repo->paginate(1, '', '', 'member_name', 'asc', 100, 1)['total']);
        self::assertNotNull($repo->getById($other));
        self::assertFalse($db->pdo()->inTransaction());
    }

    // --- syncFromListing: members that can't be stored

    public function testEmailsDifferingOnlyInCaseKeepOneMember(): void
    {
        $db   = $this->makeDb(self::MEMBER_DDL);
        $repo = $this->makeRepo($db);
        $this->insertMember($db, 'jess@test.com', 'jess', 'https://jess2.test');
        $this->insertMember($db, 'Jess@test.com', 'Jess', 'https://jess.test');

        $result = $repo->syncFromListing(1, 'test_fl');

        self::assertSame(1, $result['synced']);
        self::assertSame([['email' => 'jess@test.com', 'reason' => 'Same email as another member']], $result['skipped']);
        $row = $repo->paginate(1, '', '', 'member_name', 'asc', 100, 1)['items'][0];
        self::assertSame(['Jess', 'Jess@test.com'], [$row->member_name, $row->member_email], '"Jess" sorts first byte by byte');
    }

    public function testEmailsDifferingOnlyInSurroundingSpacesKeepOneMember(): void
    {
        $db   = $this->makeDb(self::MEMBER_DDL);
        $repo = $this->makeRepo($db);
        $this->insertMember($db, ' jess@test.com', 'Jess', 'https://jess.test');
        $this->insertMember($db, 'jess@test.com', 'Jess', 'https://jess.test');

        $result = $repo->syncFromListing(1, 'test_fl');

        self::assertSame(1, $result['synced']);
        self::assertCount(1, $result['skipped']);
        self::assertSame('Same email as another member', $result['skipped'][0]['reason']);
    }

    public function testADuplicateWithAUrlIsKeptWithItsOwnEmail(): void
    {
        $db   = $this->makeDb(self::MEMBER_DDL);
        $repo = $this->makeRepo($db);
        $this->insertMember($db, 'jess@test.com', 'Amy', '');
        $this->insertMember($db, 'JESS@test.com', 'Zed', 'https://zed.test');

        $result = $repo->syncFromListing(1, 'test_fl');

        self::assertSame([['email' => 'jess@test.com', 'reason' => 'Same email as another member']], $result['skipped']);
        $row = $repo->paginate(1, '', '', 'member_name', 'asc', 100, 1)['items'][0];
        self::assertSame(['JESS@test.com', 'Zed', 'https://zed.test', 'unchecked'],
            [$row->member_email, $row->member_name, $row->member_url, $row->link_status]);
    }

    public function testATooLongDuplicateIsSkippedForItsLengthNotAsADuplicate(): void
    {
        $db   = $this->makeDb(self::MEMBER_DDL);
        $repo = $this->makeRepo($db);
        $this->insertMember($db, 'Jess@test.com', 'Amy', 'https://x.test/' . str_repeat('p', 486)); // 501 characters
        $this->insertMember($db, 'jess@test.com', 'Zed', 'https://zed.test');

        $result = $repo->syncFromListing(1, 'test_fl');

        self::assertSame(1, $result['synced']);
        self::assertSame([['email' => 'Jess@test.com', 'reason' => 'Website address too long to check']], $result['skipped']);
        self::assertSame('jess@test.com', $repo->paginate(1, '', '', 'member_name', 'asc', 100, 1)['items'][0]->member_email);
    }

    /** A legacy table with no key on email can hold the same name and email twice. */
    public function testTheSameNameAndEmailWithDifferentUrlsKeepsTheSameRowWhateverTheReadOrder(): void
    {
        foreach ([['https://b.test', 'https://a.test'], ['https://a.test', 'https://b.test']] as $order) {
            $db   = $this->makeDb('CREATE TABLE test_nokey (email TEXT, name TEXT NOT NULL DEFAULT \'\', url TEXT NOT NULL DEFAULT \'\', pending INTEGER NOT NULL DEFAULT 0)');
            $repo = $this->makeRepo($db);
            foreach ($order as $url) {
                $db->pdo()->prepare("INSERT INTO test_nokey (email, name, url) VALUES ('sam@test.com', 'Sam', ?)")->execute([$url]);
            }

            $result = $repo->syncFromListing(1, 'test_nokey');

            self::assertSame(1, $result['synced']);
            self::assertSame('https://a.test', $repo->paginate(1, '', '', 'member_name', 'asc', 100, 1)['items'][0]->member_url, implode(' then ', $order));
        }
    }

    public function testLengthLimitsAreCharactersNotBytes(): void
    {
        $db   = $this->makeDb(self::MEMBER_DDL);
        $repo = $this->makeRepo($db);
        $email255 = str_repeat('a', 246) . '@test.com';
        $email256 = str_repeat('b', 247) . '@test.com';
        $url500   = 'https://x.test/' . str_repeat('p', 485);
        $url501   = 'https://x.test/' . str_repeat('q', 486);
        $this->insertMember($db, $email255, 'Email 255', '');
        $this->insertMember($db, $email256, 'Email 256', '');
        $this->insertMember($db, 'url500@test.com', 'Url 500', $url500);
        $this->insertMember($db, 'url501@test.com', 'Url 501', $url501);
        $this->insertMember($db, 'name255@test.com', str_repeat('é', 255), '');
        $this->insertMember($db, 'name256@test.com', str_repeat('ü', 256), '');

        $result = $repo->syncFromListing(1, 'test_fl');

        self::assertSame(4, $result['synced']);
        self::assertEqualsCanonicalizing([
            ['email' => $email256, 'reason' => 'Email address too long to check'],
            ['email' => 'url501@test.com', 'reason' => 'Website address too long to check'],
        ], $result['skipped']);

        $names = [];
        foreach ($repo->paginate(1, '', '', 'member_name', 'asc', 100, 1)['items'] as $row) {
            $names[$row->member_email] = $row->member_name;
        }
        self::assertArrayHasKey($email255, $names);
        self::assertArrayHasKey('url500@test.com', $names);
        self::assertSame(str_repeat('é', 255), $names['name255@test.com']);
        self::assertSame(255, mb_strlen($names['name256@test.com'], 'UTF-8'));
        self::assertSame(str_repeat('ü', 255), $names['name256@test.com']);
    }

    public function testABlankEmailIsSkipped(): void
    {
        $db   = $this->makeDb(self::MEMBER_DDL);
        $repo = $this->makeRepo($db);
        $this->insertMember($db, '', 'Nobody', 'https://nobody.test');
        $this->insertMember($db, '   ', 'Spaces', 'https://spaces.test');
        $this->insertMember($db, 'real@test.com', 'Real', 'https://real.test');

        $result = $repo->syncFromListing(1, 'test_fl');

        self::assertSame(1, $result['synced']);
        self::assertEqualsCanonicalizing([
            ['email' => '', 'reason' => 'No email address'],
            ['email' => '   ', 'reason' => 'No email address'],
        ], $result['skipped']);
    }

    // --- getById

    public function testGetByIdReturnsNullWhenNotFound(): void
    {
        $db   = $this->makeDb();
        $repo = $this->makeRepo($db);

        self::assertNull($repo->getById(999));
    }

    public function testGetByIdReturnsRow(): void
    {
        $db   = $this->makeDb();
        $repo = $this->makeRepo($db);

        $id  = $this->insertCheck($db, 1, 'e@test.com', 'Eve', 'https://eve.test');
        $row = $repo->getById($id);

        self::assertNotNull($row);
        self::assertSame('Eve', $row->member_name);
        self::assertSame('https://eve.test', $row->member_url);
    }

    // --- paginate

    public function testPaginateReturnsEmptyWhenNoRows(): void
    {
        $db     = $this->makeDb();
        $repo   = $this->makeRepo($db);
        $result = $repo->paginate(1, '', '', 'member_name', 'asc', 10, 1);

        self::assertSame([], $result['items']);
        self::assertSame(0, $result['total']);
    }

    public function testPaginateFiltersToCorrectListing(): void
    {
        $db   = $this->makeDb();
        $repo = $this->makeRepo($db);

        $this->insertCheck($db, 1, 'a@test.com', 'Alice', 'https://a.test');
        $this->insertCheck($db, 2, 'b@test.com', 'Bob',   'https://b.test');

        $result = $repo->paginate(1, '', '', 'member_name', 'asc', 10, 1);

        self::assertSame(1, $result['total']);
        self::assertSame('Alice', $result['items'][0]->member_name);
    }

    public function testPaginateFiltersOnStatus(): void
    {
        $db   = $this->makeDb();
        $repo = $this->makeRepo($db);

        $this->insertCheck($db, 1, 'a@test.com', 'Alice', 'https://a.test', 'ok');
        $this->insertCheck($db, 1, 'b@test.com', 'Bob',   'https://b.test', 'dead');

        $result = $repo->paginate(1, 'ok', '', 'member_name', 'asc', 10, 1);

        self::assertSame(1, $result['total']);
        self::assertSame('Alice', $result['items'][0]->member_name);
    }

    public function testPaginateSearchMatchesName(): void
    {
        $db   = $this->makeDb();
        $repo = $this->makeRepo($db);

        $this->insertCheck($db, 1, 'a@test.com', 'Alice Wonder', 'https://a.test');
        $this->insertCheck($db, 1, 'b@test.com', 'Bob Builder',  'https://b.test');

        $result = $repo->paginate(1, '', 'Alice', 'member_name', 'asc', 10, 1);

        self::assertSame(1, $result['total']);
    }

    public function testPaginateSearchMatchesEmail(): void
    {
        $db   = $this->makeDb();
        $repo = $this->makeRepo($db);

        $this->insertCheck($db, 1, 'unique@test.com', 'Alice', 'https://a.test');
        $this->insertCheck($db, 1, 'other@test.com',  'Bob',   'https://b.test');

        $result = $repo->paginate(1, '', 'unique', 'member_name', 'asc', 10, 1);

        self::assertSame(1, $result['total']);
    }

    public function testPaginateRespectsPaging(): void
    {
        $db   = $this->makeDb();
        $repo = $this->makeRepo($db);

        for ($i = 1; $i <= 5; $i++) {
            $this->insertCheck($db, 1, "m{$i}@test.com", "Member{$i}", "https://m{$i}.test");
        }

        $page1 = $repo->paginate(1, '', '', 'member_name', 'asc', 3, 1);
        $page2 = $repo->paginate(1, '', '', 'member_name', 'asc', 3, 2);

        self::assertSame(5, $page1['total']);
        self::assertCount(3, $page1['items']);
        self::assertCount(2, $page2['items']);
    }

    // --- getLinkStats

    public function testGetLinkStatsReturnsZerosWhenEmpty(): void
    {
        $db   = $this->makeDb();
        $repo = $this->makeRepo($db);

        $stats = $repo->getLinkStats(1);

        self::assertSame(['ok' => 0, 'dead' => 0, 'redirect' => 0, 'unchecked' => 0, 'no_url' => 0], $stats);
    }

    public function testGetLinkStatsCountsByStatus(): void
    {
        $db   = $this->makeDb();
        $repo = $this->makeRepo($db);

        $this->insertCheck($db, 1, 'a@test.com', 'Alice', 'https://a.test', 'ok');
        $this->insertCheck($db, 1, 'b@test.com', 'Bob',   'https://b.test', 'dead');
        $this->insertCheck($db, 1, 'c@test.com', 'Carol', 'https://c.test', 'redirect');
        $this->insertCheck($db, 1, 'd@test.com', 'Dave',  'https://d.test', 'unchecked');
        $this->insertCheck($db, 2, 'e@test.com', 'Eve',   'https://e.test', 'dead'); // different listing

        $stats = $repo->getLinkStats(1);

        self::assertSame(1, $stats['ok']);
        self::assertSame(1, $stats['dead']);
        self::assertSame(1, $stats['redirect']);
        self::assertSame(1, $stats['unchecked']);
    }

    public function testGetLinkStatsCountsNoUrl(): void
    {
        $db   = $this->makeDb();
        $repo = $this->makeRepo($db);

        $this->insertCheck($db, 1, 'a@test.com', 'Alice', '', 'no_url');
        $this->insertCheck($db, 1, 'b@test.com', 'Bob',   'https://b.test', 'ok');

        $stats = $repo->getLinkStats(1);

        self::assertSame(1, $stats['no_url']);
        self::assertSame(1, $stats['ok']);
    }

    // --- getLinkStatsAll

    public function testGetLinkStatsAllCountsAcrossListings(): void
    {
        $db   = $this->makeDb();
        $repo = $this->makeRepo($db);

        $this->insertCheck($db, 1, 'a@test.com', 'Alice', 'https://a.test', 'ok');
        $this->insertCheck($db, 2, 'b@test.com', 'Bob',   '',               'no_url');
        $this->insertCheck($db, 2, 'c@test.com', 'Carol', 'https://c.test', 'dead');

        $stats = $repo->getLinkStatsAll();

        self::assertSame(1, $stats['ok']);
        self::assertSame(1, $stats['dead']);
        self::assertSame(1, $stats['no_url']);
    }

    // --- getPerListingSummary

    public function testGetPerListingSummaryCountsNoUrl(): void
    {
        $db   = $this->makeDb();
        $repo = $this->makeRepo($db);

        $this->insertCheck($db, 1, 'a@test.com', 'Alice', '',               'no_url');
        $this->insertCheck($db, 1, 'b@test.com', 'Bob',   'https://b.test', 'ok');

        $summary = $repo->getPerListingSummary();

        self::assertSame(2, $summary[1]['total']);
        self::assertSame(1, $summary[1]['no_url']);
        self::assertSame(1, $summary[1]['ok']);
    }

    public function testGetPerListingSummaryOmitsListingsWithNoRows(): void
    {
        $db   = $this->makeDb();
        $repo = $this->makeRepo($db);

        $summary = $repo->getPerListingSummary();

        self::assertArrayNotHasKey(1, $summary);
    }

    // --- getAllIds

    public function testGetAllIdsReturnsEmptyArrayWhenNone(): void
    {
        $db   = $this->makeDb();
        $repo = $this->makeRepo($db);

        self::assertSame([], $repo->getAllIds(1));
    }

    public function testGetAllIdsReturnsIdsForListing(): void
    {
        $db   = $this->makeDb();
        $repo = $this->makeRepo($db);

        $id1 = $this->insertCheck($db, 1, 'a@test.com', 'Alice', 'https://a.test');
        $id2 = $this->insertCheck($db, 1, 'b@test.com', 'Bob',   'https://b.test');
        $this->insertCheck($db, 2, 'c@test.com', 'Carol', 'https://c.test'); // different listing

        $ids = $repo->getAllIds(1);

        self::assertSame([$id1, $id2], array_values(array_map('intval', $ids)));
    }

    // --- getByLinkStatus

    public function testGetByLinkStatusReturnsMatchingRows(): void
    {
        $db   = $this->makeDb();
        $repo = $this->makeRepo($db);

        $this->insertCheck($db, 1, 'a@test.com', 'Alice', 'https://a.test', 'redirect');
        $this->insertCheck($db, 1, 'b@test.com', 'Bob',   'https://b.test', 'ok');

        $results = $repo->getByLinkStatus('redirect', 1);

        self::assertCount(1, $results);
        self::assertSame('Alice', $results[0]->member_name);
    }

    public function testGetByLinkStatusExcludesOtherListings(): void
    {
        $db   = $this->makeDb();
        $repo = $this->makeRepo($db);

        $this->insertCheck($db, 1, 'a@test.com', 'Alice', 'https://a.test', 'dead');
        $this->insertCheck($db, 2, 'b@test.com', 'Bob',   'https://b.test', 'dead');

        self::assertCount(1, $repo->getByLinkStatus('dead', 1));
    }

    // --- updateLinkStatus

    public function testUpdateLinkStatusChangesStatusAndSetsCheckedAt(): void
    {
        $db   = $this->makeDb();
        $repo = $this->makeRepo($db);

        $id = $this->insertCheck($db, 1, 'a@test.com', 'Alice', 'https://a.test');
        $repo->updateLinkStatus($id, 'ok', null);

        $row = $repo->getById($id);
        self::assertSame('ok', $row->link_status);
        self::assertNull($row->link_redirect_url);
        self::assertNotNull($row->link_checked_at);
    }

    public function testUpdateLinkStatusStoresRedirectUrl(): void
    {
        $db   = $this->makeDb();
        $repo = $this->makeRepo($db);

        $id = $this->insertCheck($db, 1, 'a@test.com', 'Alice', 'https://old.test');
        $repo->updateLinkStatus($id, 'redirect', 'https://new.test');

        $row = $repo->getById($id);
        self::assertSame('redirect', $row->link_status);
        self::assertSame('https://new.test', $row->link_redirect_url);
    }

    // --- applyRedirectUrl

    public function testApplyRedirectUrlReturnsFalseWhenNotRedirect(): void
    {
        $db   = $this->makeDb(self::MEMBER_DDL);
        $repo = $this->makeRepo($db);

        $id = $this->insertCheck($db, 1, 'a@test.com', 'Alice', 'https://a.test', 'ok');

        self::assertFalse($repo->applyRedirectUrl($id, 'test_fl'));
    }

    public function testApplyRedirectUrlReturnsFalseWhenNoRedirectUrlStored(): void
    {
        $db   = $this->makeDb(self::MEMBER_DDL);
        $repo = $this->makeRepo($db);

        $id = $this->insertCheck($db, 1, 'a@test.com', 'Alice', 'https://a.test', 'redirect');
        // link_redirect_url is NULL

        self::assertFalse($repo->applyRedirectUrl($id, 'test_fl'));
    }

    public function testApplyRedirectUrlUpdatesCheckTableAndMemberTable(): void
    {
        $db   = $this->makeDb(self::MEMBER_DDL);
        $repo = $this->makeRepo($db);

        $this->insertMember($db, 'a@test.com', 'Alice', 'https://old.test');
        $id = $this->insertCheck($db, 1, 'a@test.com', 'Alice', 'https://old.test', 'redirect');

        // Store redirect target
        $db->pdo()->prepare(
            "UPDATE lstr_member_link_checks SET link_redirect_url = 'https://new.test' WHERE id = :id"
        )->execute(['id' => $id]);

        $result = $repo->applyRedirectUrl($id, 'test_fl');

        self::assertTrue($result);

        // Check table should have new URL and 'unchecked' status
        $checkRow = $repo->getById($id);
        self::assertSame('https://new.test', $checkRow->member_url);
        self::assertSame('unchecked', $checkRow->link_status);
        self::assertNull($checkRow->link_redirect_url);
        self::assertNull($checkRow->link_checked_at);

        // Member table should also have the new URL
        $memberRow = $db->pdo()->query("SELECT url FROM test_fl WHERE email = 'a@test.com'")->fetch();
        self::assertSame('https://new.test', $memberRow['url']);
    }

    // --- resetLinkStatus

    public function testResetLinkStatusResetsAllToUnchecked(): void
    {
        $db   = $this->makeDb();
        $repo = $this->makeRepo($db);

        $id1 = $this->insertCheck($db, 1, 'a@test.com', 'Alice', 'https://a.test', 'ok');
        $id2 = $this->insertCheck($db, 1, 'b@test.com', 'Bob',   'https://b.test', 'dead');
        $id3 = $this->insertCheck($db, 2, 'c@test.com', 'Carol', 'https://c.test', 'ok'); // different listing

        // Set redirect URLs and checked times on id1 and id2
        $db->pdo()->exec("UPDATE lstr_member_link_checks SET link_redirect_url = 'x', link_checked_at = '2026-01-01' WHERE listing_id = 1");

        $repo->resetLinkStatus(1);

        $row1 = $repo->getById($id1);
        self::assertSame('unchecked', $row1->link_status);
        self::assertNull($row1->link_redirect_url);
        self::assertNull($row1->link_checked_at);

        $row2 = $repo->getById($id2);
        self::assertSame('unchecked', $row2->link_status);

        // Listing 2 should be untouched
        $row3 = $repo->getById($id3);
        self::assertSame('ok', $row3->link_status);
    }

    // --- clearListing

    public function testClearListingDeletesOnlyThatListing(): void
    {
        $db   = $this->makeDb();
        $repo = $this->makeRepo($db);

        $this->insertCheck($db, 1, 'a@test.com', 'Alice', 'https://a.test');
        $this->insertCheck($db, 1, 'b@test.com', 'Bob',   'https://b.test');
        $id3 = $this->insertCheck($db, 2, 'c@test.com', 'Carol', 'https://c.test');

        $repo->clearListing(1);

        self::assertSame(0, $repo->paginate(1, '', '', 'member_name', 'asc', 100, 1)['total']);
        self::assertNotNull($repo->getById($id3));
    }

    // --- upsertMember

    public function testUpsertMemberInsertsNewRow(): void
    {
        $db   = $this->makeDb();
        $repo = $this->makeRepo($db);

        $repo->upsertMember(1, 'a@test.com', 'Alice', 'https://a.test');

        $result = $repo->paginate(1, '', '', 'member_name', 'asc', 10, 1);
        self::assertSame(1, $result['total']);
        $row = $result['items'][0];
        self::assertSame('Alice', $row->member_name);
        self::assertSame('https://a.test', $row->member_url);
        self::assertSame('unchecked', $row->link_status);
    }

    public function testUpsertMemberUpdatesExistingRowName(): void
    {
        $db   = $this->makeDb();
        $repo = $this->makeRepo($db);

        $id = $this->insertCheck($db, 1, 'a@test.com', 'Alice', 'https://a.test', 'ok');

        $repo->upsertMember(1, 'a@test.com', 'Alice Updated', 'https://a.test');

        $row = $repo->getById($id);
        self::assertSame('Alice Updated', $row->member_name);
        self::assertSame('ok', $row->link_status); // URL unchanged — status preserved
    }

    public function testUpsertMemberResetsStatusWhenUrlChanges(): void
    {
        $db   = $this->makeDb();
        $repo = $this->makeRepo($db);

        $id = $this->insertCheck($db, 1, 'a@test.com', 'Alice', 'https://old.test', 'ok');
        // Manually set a redirect URL and checked_at
        $db->pdo()->exec("UPDATE lstr_member_link_checks SET link_redirect_url = 'https://r.test', link_checked_at = '2026-01-01' WHERE id = {$id}");

        $repo->upsertMember(1, 'a@test.com', 'Alice', 'https://new.test');

        $row = $repo->getById($id);
        self::assertSame('https://new.test', $row->member_url);
        self::assertSame('unchecked', $row->link_status);
        self::assertNull($row->link_redirect_url);
        self::assertNull($row->link_checked_at);
    }

    public function testUpsertMemberDoesNotAffectOtherListings(): void
    {
        $db   = $this->makeDb();
        $repo = $this->makeRepo($db);

        $id2 = $this->insertCheck($db, 2, 'a@test.com', 'Alice', 'https://a.test', 'ok');

        $repo->upsertMember(1, 'a@test.com', 'Alice', 'https://new.test');

        // Listing 2 row should be untouched
        $row2 = $repo->getById($id2);
        self::assertSame('https://a.test', $row2->member_url);
        self::assertSame('ok', $row2->link_status);

        // Listing 1 should have the new row
        $result1 = $repo->paginate(1, '', '', 'member_name', 'asc', 10, 1);
        self::assertSame(1, $result1['total']);
    }

    // --- removeMember

    public function testRemoveMemberDeletesTheRow(): void
    {
        $db   = $this->makeDb();
        $repo = $this->makeRepo($db);

        $id = $this->insertCheck($db, 1, 'a@test.com', 'Alice', 'https://a.test');

        $repo->removeMember(1, 'a@test.com');

        self::assertNull($repo->getById($id));
    }

    public function testRemoveMemberIsNoOpWhenRowMissing(): void
    {
        $db   = $this->makeDb();
        $repo = $this->makeRepo($db);

        // Should not throw
        $repo->removeMember(1, 'nobody@test.com');
        self::assertSame(0, $repo->paginate(1, '', '', 'member_name', 'asc', 10, 1)['total']);
    }

    public function testRemoveMemberDoesNotAffectOtherListings(): void
    {
        $db   = $this->makeDb();
        $repo = $this->makeRepo($db);

        $id1 = $this->insertCheck($db, 1, 'a@test.com', 'Alice', 'https://a.test');
        $id2 = $this->insertCheck($db, 2, 'a@test.com', 'Alice', 'https://a.test');

        $repo->removeMember(1, 'a@test.com');

        self::assertNull($repo->getById($id1));
        self::assertNotNull($repo->getById($id2));
    }

    // --- renameMemberEmail

    public function testRenameMemberEmailUpdatesTheRow(): void
    {
        $db   = $this->makeDb();
        $repo = $this->makeRepo($db);

        $id = $this->insertCheck($db, 1, 'old@test.com', 'Alice', 'https://a.test');

        $repo->renameMemberEmail(1, 'old@test.com', 'new@test.com');

        $row = $repo->getById($id);
        self::assertNotNull($row);
        self::assertSame('new@test.com', $row->member_email);
    }

    public function testRenameMemberEmailIsNoOpWhenRowMissing(): void
    {
        $db   = $this->makeDb();
        $repo = $this->makeRepo($db);

        // Should not throw
        $repo->renameMemberEmail(1, 'nobody@test.com', 'new@test.com');
        self::assertSame(0, $repo->paginate(1, '', '', 'member_name', 'asc', 10, 1)['total']);
    }

    public function testRenameMemberEmailDoesNotAffectOtherListings(): void
    {
        $db   = $this->makeDb();
        $repo = $this->makeRepo($db);

        $id1 = $this->insertCheck($db, 1, 'old@test.com', 'Alice', 'https://a.test');
        $id2 = $this->insertCheck($db, 2, 'old@test.com', 'Alice', 'https://a.test');

        $repo->renameMemberEmail(1, 'old@test.com', 'new@test.com');

        self::assertSame('new@test.com', $repo->getById($id1)->member_email);
        self::assertSame('old@test.com', $repo->getById($id2)->member_email);
    }
}
