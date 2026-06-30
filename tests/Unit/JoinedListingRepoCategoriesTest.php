<?php

declare(strict_types=1);

namespace Luster\Tests\Unit;

use Luster\Repositories\JoinedListingRepo;
use Luster\Tests\Unit\Support\SqliteDatabase;
use PHPUnit\Framework\TestCase;

/**
 * Tests for JoinedListingRepo::countAll() and getCategoryCountMap(),
 * covering pending/inactive joined listings.
 */
final class JoinedListingRepoCategoriesTest extends TestCase
{
    private const DDL = <<<SQL
CREATE TABLE lstr_joined (
    id                INTEGER PRIMARY KEY AUTOINCREMENT,
    name              TEXT    NOT NULL DEFAULT '',
    url               TEXT    NOT NULL DEFAULT '',
    image             TEXT    NULL,
    category          TEXT    NOT NULL DEFAULT '',
    categories_json   TEXT    NULL,
    date_joined       TEXT    NULL,
    active            INTEGER NOT NULL DEFAULT 1,
    status            TEXT    NOT NULL DEFAULT 'approved',
    created_at        TEXT    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    link_status       TEXT    NOT NULL DEFAULT 'unchecked',
    link_checked_at   TEXT    NULL,
    link_redirect_url TEXT    NULL,
    description       TEXT    NULL,
    comments          TEXT    NULL
)
SQL;

    private JoinedListingRepo $repo;

    protected function setUp(): void
    {
        $this->repo = new JoinedListingRepo(SqliteDatabase::make(self::DDL));
    }

    // -----------------------------------------------------------------------
    // countAll
    // -----------------------------------------------------------------------

    public function testCountAllReturnsZeroWhenEmpty(): void
    {
        self::assertSame(0, $this->repo->countAll());
    }

    public function testCountAllIncludesPendingAndInactiveListings(): void
    {
        $this->repo->insert(['name' => 'Active',  'url' => 'https://active.test',  'active' => true]);
        $this->repo->insert(['name' => 'Pending', 'url' => 'https://pending.test', 'active' => false]);

        self::assertSame(2, $this->repo->countAll());
    }

    // -----------------------------------------------------------------------
    // getCategoryCountMap
    // -----------------------------------------------------------------------

    public function testGetCategoryCountMapDefaultExcludesInactiveListings(): void
    {
        $this->repo->insert(['name' => 'Active',  'url' => 'https://active.test',  'category' => 'Anime', 'active' => true]);
        $this->repo->insert(['name' => 'Pending', 'url' => 'https://pending.test', 'category' => 'Anime', 'active' => false]);

        $counts = $this->repo->getCategoryCountMap();

        self::assertSame(1, $counts['Anime']);
    }

    public function testGetCategoryCountMapWithActiveOnlyFalseIncludesInactiveListings(): void
    {
        $this->repo->insert(['name' => 'Active',  'url' => 'https://active.test',  'category' => 'Anime', 'active' => true]);
        $this->repo->insert(['name' => 'Pending', 'url' => 'https://pending.test', 'category' => 'Anime', 'active' => false]);

        $counts = $this->repo->getCategoryCountMap(false);

        self::assertSame(2, $counts['Anime']);
    }

    // -----------------------------------------------------------------------
    // getCategories
    // -----------------------------------------------------------------------

    public function testGetCategoriesDefaultIncludesInactiveListings(): void
    {
        // Default (activeOnly=false) mirrors original behaviour — admin sees all categories.
        $this->repo->insert(['name' => 'Hidden', 'url' => 'https://hidden.test', 'category' => 'Music', 'active' => false]);

        self::assertSame(['Music'], $this->repo->getCategories());
    }

    public function testGetCategoriesActiveOnlyExcludesHiddenOnlyCategories(): void
    {
        // A category whose only listing is hidden must not appear publicly.
        $this->repo->insert(['name' => 'Active',  'url' => 'https://active.test',  'category' => 'Anime', 'active' => true]);
        $this->repo->insert(['name' => 'Hidden', 'url' => 'https://hidden.test', 'category' => 'Music', 'active' => false]);

        self::assertSame(['Anime'], $this->repo->getCategories(true));
    }

    // -----------------------------------------------------------------------
    // countAllByStatusTab — visible_pending
    // -----------------------------------------------------------------------

    public function testCountAllByStatusTabReturnsVisiblePendingCount(): void
    {
        $this->repo->insert(['name' => 'Pending Visible', 'url' => 'https://pv.test', 'active' => true,  'status' => 'pending']);
        $this->repo->insert(['name' => 'Pending Hidden',  'url' => 'https://ph.test', 'active' => false, 'status' => 'pending']);
        $this->repo->insert(['name' => 'Approved',        'url' => 'https://ap.test', 'active' => true,  'status' => 'approved']);

        $tabs = $this->repo->countAllByStatusTab();

        self::assertSame(2, $tabs['pending']);          // all pending regardless of active
        self::assertSame(1, $tabs['visible_pending']);  // only non-hidden pending
    }

    // -----------------------------------------------------------------------
    // getAll — visible_pending statusTab
    // -----------------------------------------------------------------------

    public function testGetAllVisiblePendingExcludesHiddenPendingRows(): void
    {
        $this->repo->insert(['name' => 'Pending Visible', 'url' => 'https://pv.test', 'active' => true,  'status' => 'pending']);
        $this->repo->insert(['name' => 'Pending Hidden',  'url' => 'https://ph.test', 'active' => false, 'status' => 'pending']);

        $result = $this->repo->getAll(perPage: 9999, page: 1, statusTab: 'visible_pending');

        self::assertCount(1, $result['items']);
        self::assertSame('Pending Visible', $result['items'][0]->name);
    }

    // -----------------------------------------------------------------------
    // getActiveApproved
    // -----------------------------------------------------------------------

    public function testGetActiveApprovedReturnsEmptyArrayWhenNoneEligible(): void
    {
        $this->repo->insert(['name' => 'Pending',  'url' => 'https://pending.test',  'active' => true,  'status' => 'pending']);
        $this->repo->insert(['name' => 'Inactive', 'url' => 'https://inactive.test', 'active' => false, 'status' => 'approved']);

        self::assertSame([], $this->repo->getActiveApproved());
    }

    public function testGetActiveApprovedReturnsOnlyActiveApprovedOrderedByName(): void
    {
        $this->repo->insert(['name' => 'Zelda',   'url' => 'https://zelda.test',   'active' => true,  'status' => 'approved']);
        $this->repo->insert(['name' => 'Anime',   'url' => 'https://anime.test',   'active' => true,  'status' => 'approved']);
        $this->repo->insert(['name' => 'Pending', 'url' => 'https://pending.test', 'active' => true,  'status' => 'pending']);
        $this->repo->insert(['name' => 'Hidden',  'url' => 'https://hidden.test',  'active' => false, 'status' => 'approved']);

        $listings = $this->repo->getActiveApproved();

        self::assertCount(2, $listings);
        self::assertSame('Anime', $listings[0]->name);
        self::assertSame('Zelda', $listings[1]->name);
    }

    // -----------------------------------------------------------------------
    // getLatestJoined
    // -----------------------------------------------------------------------

    public function testGetLatestJoinedReturnsEmptyArrayWhenNoneEligible(): void
    {
        self::assertSame([], $this->repo->getLatestJoined());
    }

    public function testGetLatestJoinedOrdersByDateJoinedDescendingWithNullsLast(): void
    {
        $this->repo->insert(['name' => 'Oldest', 'url' => 'https://oldest.test', 'active' => true, 'status' => 'approved', 'date_joined' => '2024-01-01']);
        $this->repo->insert(['name' => 'Newest', 'url' => 'https://newest.test', 'active' => true, 'status' => 'approved', 'date_joined' => '2024-06-01']);
        $this->repo->insert(['name' => 'NoDate', 'url' => 'https://nodate.test', 'active' => true, 'status' => 'approved']);

        $listings = $this->repo->getLatestJoined(3);

        self::assertCount(3, $listings);
        self::assertSame('Newest', $listings[0]->name);
        self::assertSame('Oldest', $listings[1]->name);
        self::assertSame('NoDate', $listings[2]->name);
    }

    public function testGetLatestJoinedExcludesPendingAndInactiveListings(): void
    {
        $this->repo->insert(['name' => 'Approved', 'url' => 'https://approved.test', 'active' => true,  'status' => 'approved', 'date_joined' => '2024-01-01']);
        $this->repo->insert(['name' => 'Pending',  'url' => 'https://pending.test',  'active' => true,  'status' => 'pending',  'date_joined' => '2024-06-01']);
        $this->repo->insert(['name' => 'Inactive', 'url' => 'https://inactive.test', 'active' => false, 'status' => 'approved', 'date_joined' => '2024-12-01']);

        $listings = $this->repo->getLatestJoined(5);

        self::assertCount(1, $listings);
        self::assertSame('Approved', $listings[0]->name);
    }

    public function testGetLatestJoinedRespectsLimit(): void
    {
        $this->repo->insert(['name' => 'A', 'url' => 'https://a.test', 'active' => true, 'status' => 'approved', 'date_joined' => '2024-01-01']);
        $this->repo->insert(['name' => 'B', 'url' => 'https://b.test', 'active' => true, 'status' => 'approved', 'date_joined' => '2024-02-01']);
        $this->repo->insert(['name' => 'C', 'url' => 'https://c.test', 'active' => true, 'status' => 'approved', 'date_joined' => '2024-03-01']);

        $listings = $this->repo->getLatestJoined(1);

        self::assertCount(1, $listings);
        self::assertSame('C', $listings[0]->name);
    }

    public function testGetLatestJoinedTiesBrokenByIdDescending(): void
    {
        $this->repo->insert(['name' => 'First',  'url' => 'https://first.test',  'active' => true, 'status' => 'approved', 'date_joined' => '2024-01-01']);
        $this->repo->insert(['name' => 'Second', 'url' => 'https://second.test', 'active' => true, 'status' => 'approved', 'date_joined' => '2024-01-01']);

        $listings = $this->repo->getLatestJoined(2);

        self::assertCount(2, $listings);
        self::assertSame('Second', $listings[0]->name);
        self::assertSame('First', $listings[1]->name);
    }
}
