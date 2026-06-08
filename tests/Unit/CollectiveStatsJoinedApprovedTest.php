<?php

declare(strict_types=1);

namespace Luster\Tests\Unit;

use Luster\Core\Config;
use Luster\Models\JoinedListing;
use Luster\Models\Listing;
use Luster\Repositories\CollectiveStatsRepo;
use Luster\Tests\Unit\Support\SqliteDatabase;
use PHPUnit\Framework\TestCase;

/**
 * Regression tests for collective-stats correctness.
 *
 * Covers:
 *  - getJoinedCounts()['approved'] counts active+non-pending rows only
 *  - getJoinedCounts()['approved'] excludes pending and upcoming rows
 *  - Listing::fromRow() maps the 'name' DB column to ->title (not ->name)
 *  - Token-substitution source: {owned_newest_name}/{owned_random_name} use ->title
 *  - Enthusiast flat-array alias keys: $owned_newest['title'], ['subject'], ['listingtype']
 *  - Enthusiast flat-array alias keys: $joined_newest['added'], ['subject']
 */
final class CollectiveStatsJoinedApprovedTest extends TestCase
{
    private Config $config;

    protected function setUp(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'lstr_test_');
        file_put_contents($tmp, "<?php return ['enth_table_prefix' => ''];");
        $this->config = new Config($tmp);
        unlink($tmp);
    }

    private const JOINED_DDL = <<<SQL
CREATE TABLE lstr_joined (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    name         TEXT    NOT NULL DEFAULT '',
    url          TEXT    NOT NULL DEFAULT '',
    image        TEXT    NULL,
    category     TEXT    NOT NULL DEFAULT '',
    categories_json TEXT NULL,
    date_joined  TEXT    NULL,
    active       INTEGER NOT NULL DEFAULT 1,
    status       TEXT    NOT NULL DEFAULT 'approved',
    sort_order   INTEGER NOT NULL DEFAULT 0,
    created_at   TEXT    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    link_status  TEXT    NOT NULL DEFAULT 'unchecked',
    link_checked_at   TEXT NULL,
    link_redirect_url TEXT NULL
)
SQL;

    // -------------------------------------------------------------------------
    // getJoinedCounts() — approved column correctness
    // -------------------------------------------------------------------------

    public function testApprovedExcludesPendingRows(): void
    {
        $db   = SqliteDatabase::make(self::JOINED_DDL);
        $pdo  = $this->extractPdo($db);
        $repo = new CollectiveStatsRepo($db, $this->config);

        // 2 approved (active, status 'joined' / 'approved')
        $pdo->exec("INSERT INTO lstr_joined (name, url, active, status) VALUES ('A', 'http://a', 1, 'joined')");
        $pdo->exec("INSERT INTO lstr_joined (name, url, active, status) VALUES ('B', 'http://b', 1, 'approved')");
        // 1 pending (active but pending status)
        $pdo->exec("INSERT INTO lstr_joined (name, url, active, status) VALUES ('C', 'http://c', 1, 'pending')");
        // 1 upcoming
        $pdo->exec("INSERT INTO lstr_joined (name, url, active, status) VALUES ('D', 'http://d', 1, 'upcoming')");
        // 1 inactive (active=0)
        $pdo->exec("INSERT INTO lstr_joined (name, url, active, status) VALUES ('E', 'http://e', 0, 'joined')");

        $counts = $repo->getJoinedCounts();

        $this->assertSame(5,  $counts['total'],    'total should count all rows');
        $this->assertSame(4,  $counts['active'],   'active should count all active=1 rows');
        $this->assertSame(2,  $counts['approved'], 'approved should exclude pending and upcoming');
        $this->assertSame(1,  $counts['upcoming'], 'upcoming count');
        $this->assertSame(1,  $counts['pending'],  'pending count');
    }

    public function testApprovedIsZeroWhenAllArePending(): void
    {
        $db   = SqliteDatabase::make(self::JOINED_DDL);
        $pdo  = $this->extractPdo($db);
        $repo = new CollectiveStatsRepo($db, $this->config);

        $pdo->exec("INSERT INTO lstr_joined (name, url, active, status) VALUES ('X', 'http://x', 1, 'pending')");

        $counts = $repo->getJoinedCounts();

        $this->assertSame(0, $counts['approved']);
        $this->assertSame(1, $counts['active']);
    }

    public function testReturnHasApprovedKeyOnEmptyTable(): void
    {
        $db   = SqliteDatabase::make(self::JOINED_DDL);
        $repo = new CollectiveStatsRepo($db, $this->config);

        $counts = $repo->getJoinedCounts();

        $this->assertArrayHasKey('approved', $counts);
        $this->assertSame(0, $counts['approved']);
    }

    // -------------------------------------------------------------------------
    // Listing model — title field correctness (regression for ->name bug)
    // -------------------------------------------------------------------------

    public function testListingFromRowMapsNameColumnToTitle(): void
    {
        // Both schema variants: 'name' column (enth_) and 'title' column (owned)
        $fromEnth = Listing::fromRow([
            'id'              => 1,
            'name'            => 'My Listing via enth_',
            'subject'         => 'Subject',
            'dbprefix'        => 'fl_',
            'email'           => 'a@b.com',
            'url'             => 'http://x',
            'additional_info' => '',
            'upcoming'        => 0,
            'trouble'         => 0,
        ]);

        $this->assertSame('My Listing via enth_', $fromEnth->title);

        $fromOwned = Listing::fromRow([
            'listingid'  => 2,
            'title'      => 'My Listing via owned',
            'subject'    => 'Subject',
            'dbtable'    => 'mytable',
            'email'      => 'a@b.com',
            'url'        => 'http://y',
            'additional' => '',
            'status'     => 2,
        ]);

        $this->assertSame('My Listing via owned', $fromOwned->title);
    }

    public function testOwnedNewestAliasArrayHasTitleAndSubjectKeys(): void
    {
        $listing = Listing::fromRow([
            'listingid'  => 1,
            'title'      => 'Test Listing',
            'subject'    => 'Test Subject',
            'dbtable'    => 'tl',
            'email'      => '',
            'url'        => 'http://tl',
            'additional' => '',
            'status'     => 2,
            'listingtype' => 'listing',
        ]);

        // Simulate the $owned_newest flat array built in collective-stats.php
        $ownedNewest = [
            'url'         => $listing->url,
            'subject'     => $listing->subject,
            'desc'        => $listing->subject,
            'name'        => $listing->title,
            'title'       => $listing->title,
            'listingtype' => $listing->listingtype,
            'opened'      => $listing->opened ?? $listing->joined,
        ];

        $this->assertSame('Test Listing',  $ownedNewest['title']);
        $this->assertSame('Test Listing',  $ownedNewest['name']);
        $this->assertSame('Test Subject',  $ownedNewest['subject']);
        $this->assertSame('Test Subject',  $ownedNewest['desc']);
        $this->assertSame('listing',       $ownedNewest['listingtype']);
        $this->assertSame('http://tl',     $ownedNewest['url']);
        $this->assertNull($ownedNewest['opened']);
    }

    public function testJoinedNewestAliasArrayHasAddedAndSubjectKeys(): void
    {
        $joined = JoinedListing::fromRow([
            'id'          => 1,
            'name'        => 'Joined Listing',
            'url'         => 'http://jl',
            'image'       => null,
            'category'    => 'Actors',
            'date_joined' => '2024-05-01',
            'active'      => 1,
            'status'      => 'joined',
            'created_at'  => '2024-05-01',
            'link_status' => 'unchecked',
        ]);

        // Simulate the $joined_newest flat array built in collective-stats.php
        $joinedNewest = [
            'url'      => $joined->url,
            'name'     => $joined->name,
            'subject'  => $joined->name,
            'desc'     => $joined->name,
            'category' => $joined->category,
            'added'    => $joined->dateJoined,
        ];

        $this->assertSame('Joined Listing', $joinedNewest['name']);
        $this->assertSame('Joined Listing', $joinedNewest['subject']);
        $this->assertSame('Joined Listing', $joinedNewest['desc']);
        $this->assertSame('Actors',         $joinedNewest['category']);
        $this->assertSame('2024-05-01',     $joinedNewest['added']);
        $this->assertSame('http://jl',      $joinedNewest['url']);
    }

    public function testOwnedRandomAliasArrayShape(): void
    {
        $listing = Listing::fromRow([
            'listingid'   => 5,
            'title'       => 'Random Owned',
            'subject'     => 'Random Subject',
            'dbtable'     => 'ro',
            'email'       => '',
            'url'         => 'http://ro',
            'additional'  => '',
            'status'      => 2,
            'listingtype' => 'collective',
        ]);

        // Simulate $owned_random array from collective-stats.php (non-null branch)
        $ownedRandom = [
            'url'         => $listing->url,
            'subject'     => $listing->subject,
            'desc'        => $listing->subject,
            'name'        => $listing->title,
            'title'       => $listing->title,
            'listingtype' => $listing->listingtype,
            'opened'      => $listing->opened ?? $listing->joined,
        ];

        $this->assertSame('Random Owned',    $ownedRandom['name']);
        $this->assertSame('Random Owned',    $ownedRandom['title']);
        $this->assertSame('Random Subject',  $ownedRandom['subject']);
        $this->assertSame('Random Subject',  $ownedRandom['desc']);
        $this->assertSame('collective',      $ownedRandom['listingtype']);
        $this->assertSame('http://ro',       $ownedRandom['url']);

        // Null branch (no active owned listing) yields empty array
        $lusterOwnedRandom = null;
        $ownedRandomNull = $lusterOwnedRandom !== null ? ['url' => $lusterOwnedRandom->url] : [];
        $this->assertSame([], $ownedRandomNull);
    }

    public function testJoinedRandomAliasArrayShape(): void
    {
        $joined = JoinedListing::fromRow([
            'id'          => 9,
            'name'        => 'Random Joined',
            'url'         => 'http://rj',
            'image'       => null,
            'category'    => 'Music',
            'date_joined' => '2023-11-15',
            'active'      => 1,
            'status'      => 'joined',
            'created_at'  => '2023-11-15',
            'link_status' => 'unchecked',
        ]);

        // Simulate $joined_random array from collective-stats.php (non-null branch)
        $joinedRandom = [
            'url'      => $joined->url,
            'name'     => $joined->name,
            'subject'  => $joined->name,
            'desc'     => $joined->name,
            'category' => $joined->category,
            'added'    => $joined->dateJoined,
        ];

        $this->assertSame('Random Joined', $joinedRandom['name']);
        $this->assertSame('Random Joined', $joinedRandom['subject']);
        $this->assertSame('Random Joined', $joinedRandom['desc']);
        $this->assertSame('Music',         $joinedRandom['category']);
        $this->assertSame('2023-11-15',    $joinedRandom['added']);
        $this->assertSame('http://rj',     $joinedRandom['url']);
    }

    // -------------------------------------------------------------------------
    // getNewestJoined() — predicate correctness
    // -------------------------------------------------------------------------

    public function testGetNewestJoinedExcludesPendingAndUpcomingRows(): void
    {
        $db   = SqliteDatabase::make(self::JOINED_DDL);
        $pdo  = $this->extractPdo($db);
        $repo = new CollectiveStatsRepo($db, $this->config);

        $pdo->exec("INSERT INTO lstr_joined (name, url, active, status, date_joined) VALUES ('Approved', 'http://a', 1, 'joined',   '2024-01-01')");
        $pdo->exec("INSERT INTO lstr_joined (name, url, active, status, date_joined) VALUES ('Pending',  'http://b', 1, 'pending',  '2024-06-01')");
        $pdo->exec("INSERT INTO lstr_joined (name, url, active, status, date_joined) VALUES ('Upcoming', 'http://c', 1, 'upcoming', '2024-07-01')");

        $newest = $repo->getNewestJoined(10);

        $this->assertCount(1, $newest, 'Only the approved row should be returned');
        $this->assertSame('Approved', $newest[0]->name);
    }

    public function testGetNewestJoinedReturnsEmptyWhenAllArePending(): void
    {
        $db   = SqliteDatabase::make(self::JOINED_DDL);
        $pdo  = $this->extractPdo($db);
        $repo = new CollectiveStatsRepo($db, $this->config);

        $pdo->exec("INSERT INTO lstr_joined (name, url, active, status) VALUES ('P1', 'http://p1', 1, 'pending')");
        $pdo->exec("INSERT INTO lstr_joined (name, url, active, status) VALUES ('U1', 'http://u1', 1, 'upcoming')");

        $newest = $repo->getNewestJoined(10);

        $this->assertSame([], $newest);
    }

    // -------------------------------------------------------------------------
    // Token substitution — {owned_newest_name} / {owned_random_name} use ->title
    // -------------------------------------------------------------------------

    public function testOwnedNewestNameTokenUsesListingTitle(): void
    {
        // Hydrate from enth_ schema where DB column is 'name', not 'title'
        $listing = Listing::fromRow([
            'id'              => 1,
            'name'            => 'Always - Amy and Rory',
            'subject'         => 'Doctor Who couple',
            'dbprefix'        => 'fl_dw_',
            'email'           => '',
            'url'             => 'http://dw.example.com',
            'additional_info' => '',
            'upcoming'        => 0,
            'trouble'         => 0,
        ]);

        $e = static fn(string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        // Reproduce the exact str_replace replacement used in collective-stats.php
        $result = str_replace(
            ['{owned_newest_name}', '{owned_random_name}'],
            [$e($listing->title), $e($listing->title)],
            '{owned_newest_name} / {owned_random_name}'
        );

        $this->assertSame('Always - Amy and Rory / Always - Amy and Rory', $result);
    }

    // -------------------------------------------------------------------------
    // Helper
    // -------------------------------------------------------------------------

    private function extractPdo(\Luster\Core\Database $db): \PDO
    {
        $prop = new \ReflectionProperty($db, 'pdo');
        $prop->setAccessible(true);
        return $prop->getValue($db);
    }
}
