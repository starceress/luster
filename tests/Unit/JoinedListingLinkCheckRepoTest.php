<?php

declare(strict_types=1);

namespace Luster\Tests\Unit;

use Luster\Repositories\JoinedListingRepo;
use Luster\Tests\Unit\Support\SqliteDatabase;
use PHPUnit\Framework\TestCase;

final class JoinedListingLinkCheckRepoTest extends TestCase
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
    link_status        TEXT    NOT NULL DEFAULT 'unchecked',
    link_checked_at    TEXT    NULL,
    link_redirect_url  TEXT    NULL,
    listed_status      TEXT    NOT NULL DEFAULT 'unchecked',
    listed_checked_at  TEXT    NULL,
    listed_members_url TEXT    NULL,
    description        TEXT    NULL,
    comments           TEXT    NULL
)
SQL;

    private JoinedListingRepo $repo;

    protected function setUp(): void
    {
        $this->repo = new JoinedListingRepo(SqliteDatabase::make(self::DDL));
    }

    // -----------------------------------------------------------------------
    // Helper
    // -----------------------------------------------------------------------

    private function insertListing(string $name, string $url): int
    {
        return $this->repo->insert(['name' => $name, 'url' => $url]);
    }

    // -----------------------------------------------------------------------
    // getAllForLinkCheck
    // -----------------------------------------------------------------------

    public function testGetAllForLinkCheckReturnsEmptyArrayWhenNone(): void
    {
        self::assertSame([], $this->repo->getAllForLinkCheck());
    }

    public function testGetAllForLinkCheckReturnsAllRows(): void
    {
        $this->insertListing('Alpha Fanlisting', 'https://alpha.test');
        $this->insertListing('Beta Fanlisting',  'https://beta.test');

        $items = $this->repo->getAllForLinkCheck();
        self::assertCount(2, $items);
    }

    public function testGetAllForLinkCheckInitialStatusIsUnchecked(): void
    {
        $this->insertListing('Alpha Fanlisting', 'https://alpha.test');

        $items = $this->repo->getAllForLinkCheck();
        self::assertSame('unchecked', $items[0]->linkStatus);
        self::assertNull($items[0]->linkCheckedAt);
        self::assertNull($items[0]->linkRedirectUrl);
    }

    // -----------------------------------------------------------------------
    // updateLinkStatus
    // -----------------------------------------------------------------------

    public function testUpdateLinkStatusSetsOk(): void
    {
        $id = $this->insertListing('Alpha Fanlisting', 'https://alpha.test');

        $this->repo->updateLinkStatus($id, 'ok', null, '2025-06-01 12:00:00');

        $item = $this->repo->getById($id);
        self::assertNotNull($item);
        self::assertSame('ok', $item->linkStatus);
        self::assertSame('2025-06-01 12:00:00', $item->linkCheckedAt);
        self::assertNull($item->linkRedirectUrl);
    }

    public function testUpdateLinkStatusStoresRedirectUrl(): void
    {
        $id = $this->insertListing('Alpha Fanlisting', 'https://alpha.test');

        $this->repo->updateLinkStatus($id, 'redirect', 'https://new.alpha.test/', '2025-06-01 12:00:00');

        $item = $this->repo->getById($id);
        self::assertNotNull($item);
        self::assertSame('redirect', $item->linkStatus);
        self::assertSame('https://new.alpha.test/', $item->linkRedirectUrl);
    }

    public function testUpdateLinkStatusSetsDead(): void
    {
        $id = $this->insertListing('Alpha Fanlisting', 'https://alpha.test');

        $this->repo->updateLinkStatus($id, 'dead', null, '2025-06-01 12:00:00');

        $item = $this->repo->getById($id);
        self::assertNotNull($item);
        self::assertSame('dead', $item->linkStatus);
    }

    // -----------------------------------------------------------------------
    // resetLinkStatus
    // -----------------------------------------------------------------------

    public function testResetLinkStatusClearsAllStatuses(): void
    {
        $id1 = $this->insertListing('Alpha Fanlisting', 'https://alpha.test');
        $id2 = $this->insertListing('Beta Fanlisting',  'https://beta.test');

        $this->repo->updateLinkStatus($id1, 'ok',       null,                   '2025-06-01 12:00:00');
        $this->repo->updateLinkStatus($id2, 'redirect', 'https://new.beta.test', '2025-06-01 12:00:00');
        $this->repo->updateListedStatus($id1, 'listed',    'https://alpha.test/members');
        $this->repo->updateListedStatus($id2, 'not_found', null);

        $this->repo->resetLinkStatus();

        foreach ([$this->repo->getById($id1), $this->repo->getById($id2)] as $item) {
            self::assertNotNull($item);
            self::assertSame('unchecked', $item->linkStatus);
            self::assertNull($item->linkCheckedAt);
            self::assertNull($item->linkRedirectUrl);
            self::assertSame('unchecked', $item->listedStatus);
            self::assertNull($item->listedCheckedAt);
            self::assertNull($item->listedMembersUrl);
        }
    }

    public function testResetLinkStatusOnEmptyTableDoesNotThrow(): void
    {
        $this->repo->resetLinkStatus(); // must not throw
        self::assertTrue(true);
    }

    // -----------------------------------------------------------------------
    // Verify model fields survive round-trip through getAllForLinkCheck
    // -----------------------------------------------------------------------

    public function testGetAllForLinkCheckReturnsUpdatedStatus(): void
    {
        $id = $this->insertListing('Alpha Fanlisting', 'https://alpha.test');
        $this->repo->updateLinkStatus($id, 'ok', null, '2025-06-01 12:00:00');

        $items = $this->repo->getAllForLinkCheck();
        self::assertCount(1, $items);
        self::assertSame('ok', $items[0]->linkStatus);
        self::assertSame('2025-06-01 12:00:00', $items[0]->linkCheckedAt);
    }

    // -----------------------------------------------------------------------
    // getByLinkStatus
    // -----------------------------------------------------------------------

    public function testGetByLinkStatusReturnsEmptyWhenNone(): void
    {
        self::assertSame([], $this->repo->getByLinkStatus('redirect'));
    }

    public function testGetByLinkStatusFiltersCorrectly(): void
    {
        $id1 = $this->insertListing('Alpha Fanlisting', 'https://alpha.test');
        $id2 = $this->insertListing('Beta Fanlisting',  'https://beta.test');
        $id3 = $this->insertListing('Gamma Fanlisting', 'https://gamma.test');

        $this->repo->updateLinkStatus($id1, 'ok',       null,                     '2025-06-01 12:00:00');
        $this->repo->updateLinkStatus($id2, 'redirect', 'https://new.beta.test/', '2025-06-01 12:00:00');
        $this->repo->updateLinkStatus($id3, 'dead',     null,                     '2025-06-01 12:00:00');

        $redirects = $this->repo->getByLinkStatus('redirect');
        self::assertCount(1, $redirects);
        self::assertSame($id2, $redirects[0]->id);

        $dead = $this->repo->getByLinkStatus('dead');
        self::assertCount(1, $dead);
        self::assertSame($id3, $dead[0]->id);
    }

    public function testGetByLinkStatusReturnsMultiple(): void
    {
        $id1 = $this->insertListing('Alpha Fanlisting', 'https://alpha.test');
        $id2 = $this->insertListing('Beta Fanlisting',  'https://beta.test');

        $this->repo->updateLinkStatus($id1, 'dead', null, '2025-06-01 12:00:00');
        $this->repo->updateLinkStatus($id2, 'dead', null, '2025-06-01 12:00:00');

        self::assertCount(2, $this->repo->getByLinkStatus('dead'));
        self::assertCount(0, $this->repo->getByLinkStatus('redirect'));
    }

    // -----------------------------------------------------------------------
    // applyRedirectUrl
    // -----------------------------------------------------------------------

    public function testApplyRedirectUrlUpdatesUrlAndResetsLinkState(): void
    {
        $id = $this->insertListing('Alpha Fanlisting', 'https://alpha.test');
        $this->repo->updateLinkStatus($id, 'redirect', 'https://new.alpha.test/', '2025-06-01 12:00:00');

        $result = $this->repo->applyRedirectUrl($id);
        self::assertTrue($result);

        $item = $this->repo->getById($id);
        self::assertNotNull($item);
        self::assertSame('https://new.alpha.test/', $item->url);
        self::assertSame('unchecked', $item->linkStatus);
        self::assertNull($item->linkRedirectUrl);
        self::assertNull($item->linkCheckedAt);
    }

    public function testApplyRedirectUrlReturnsFalseWhenStatusIsNotRedirect(): void
    {
        $id = $this->insertListing('Alpha Fanlisting', 'https://alpha.test');
        $this->repo->updateLinkStatus($id, 'ok', null, '2025-06-01 12:00:00');

        $result = $this->repo->applyRedirectUrl($id);
        self::assertFalse($result);

        $item = $this->repo->getById($id);
        self::assertNotNull($item);
        self::assertSame('https://alpha.test', $item->url);
    }

    public function testApplyRedirectUrlReturnsFalseWhenRedirectUrlIsNull(): void
    {
        $id = $this->insertListing('Alpha Fanlisting', 'https://alpha.test');
        $this->repo->updateLinkStatus($id, 'redirect', null, '2025-06-01 12:00:00');

        self::assertFalse($this->repo->applyRedirectUrl($id));
    }

    public function testApplyRedirectUrlIsIdempotentOnDoubleSubmit(): void
    {
        $id = $this->insertListing('Alpha Fanlisting', 'https://alpha.test');
        $this->repo->updateLinkStatus($id, 'redirect', 'https://new.alpha.test/', '2025-06-01 12:00:00');

        $first  = $this->repo->applyRedirectUrl($id);
        $second = $this->repo->applyRedirectUrl($id);

        self::assertTrue($first);
        self::assertFalse($second);
    }

    public function testApplyRedirectUrlReturnsFalseForUnknownId(): void
    {
        self::assertFalse($this->repo->applyRedirectUrl(9999));
    }

    // -----------------------------------------------------------------------
    // applyCustomUrl
    // -----------------------------------------------------------------------

    public function testApplyCustomUrlUpdatesUrl(): void
    {
        $id = $this->insertListing('Alpha Fanlisting', 'https://alpha.test');

        self::assertTrue($this->repo->applyCustomUrl($id, 'https://new-home.alpha.test/'));

        $item = $this->repo->getById($id);
        self::assertNotNull($item);
        self::assertSame('https://new-home.alpha.test/', $item->url);
    }

    public function testApplyCustomUrlResetsLinkStatusToUnchecked(): void
    {
        $id = $this->insertListing('Alpha Fanlisting', 'https://alpha.test');
        $this->repo->updateLinkStatus($id, 'dead', null, '2025-06-01 12:00:00');

        $this->repo->applyCustomUrl($id, 'https://new-home.alpha.test/');

        $item = $this->repo->getById($id);
        self::assertNotNull($item);
        self::assertSame('unchecked', $item->linkStatus);
    }

    public function testApplyCustomUrlClearsLinkRedirectUrl(): void
    {
        $id = $this->insertListing('Alpha Fanlisting', 'https://alpha.test');
        $this->repo->updateLinkStatus($id, 'redirect', 'https://old-redirect.alpha.test/', '2025-06-01 12:00:00');

        $this->repo->applyCustomUrl($id, 'https://new-home.alpha.test/');

        $item = $this->repo->getById($id);
        self::assertNotNull($item);
        self::assertNull($item->linkRedirectUrl);
    }

    public function testApplyCustomUrlClearsLinkCheckedAt(): void
    {
        $id = $this->insertListing('Alpha Fanlisting', 'https://alpha.test');
        $this->repo->updateLinkStatus($id, 'ok', null, '2025-06-01 12:00:00');

        $this->repo->applyCustomUrl($id, 'https://new-home.alpha.test/');

        $item = $this->repo->getById($id);
        self::assertNotNull($item);
        self::assertNull($item->linkCheckedAt);
    }

    public function testApplyCustomUrlReturnsFalseForMissingRow(): void
    {
        self::assertFalse($this->repo->applyCustomUrl(9999, 'https://example.com'));
    }

    public function testApplyCustomUrlRejectsNonHttpScheme(): void
    {
        $id = $this->insertListing('Alpha Fanlisting', 'https://alpha.test');

        self::assertFalse($this->repo->applyCustomUrl($id, 'ftp://files.alpha.test/'));

        $item = $this->repo->getById($id);
        self::assertNotNull($item);
        self::assertSame('https://alpha.test', $item->url, 'URL must be unchanged after rejected update');
    }

    public function testApplyCustomUrlRejectsMalformedUrls(): void
    {
        $id = $this->insertListing('Alpha Fanlisting', 'https://alpha.test');

        self::assertFalse($this->repo->applyCustomUrl($id, 'https://'), 'bare scheme must be rejected');
        self::assertFalse($this->repo->applyCustomUrl($id, 'not-a-url'), 'random string must be rejected');
        self::assertFalse($this->repo->applyCustomUrl($id, ''), 'empty string must be rejected');

        $item = $this->repo->getById($id);
        self::assertNotNull($item);
        self::assertSame('https://alpha.test', $item->url, 'URL must be unchanged after all rejected updates');
    }

    public function testApplyCustomUrlAcceptsHttpScheme(): void
    {
        $id = $this->insertListing('Alpha Fanlisting', 'https://alpha.test');

        self::assertTrue($this->repo->applyCustomUrl($id, 'http://legacy.alpha.test/'));

        $item = $this->repo->getById($id);
        self::assertNotNull($item);
        self::assertSame('http://legacy.alpha.test/', $item->url);
    }
}
