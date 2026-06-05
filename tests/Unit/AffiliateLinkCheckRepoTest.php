<?php

declare(strict_types=1);

namespace Luster\Tests\Unit;

use Luster\Repositories\AffiliateRepo;
use Luster\Tests\Unit\Support\SqliteDatabase;
use PHPUnit\Framework\TestCase;

final class AffiliateLinkCheckRepoTest extends TestCase
{
    private const DDL = <<<SQL
CREATE TABLE lstr_affiliates (
    id                    INTEGER PRIMARY KEY AUTOINCREMENT,
    fanlisting_id         INTEGER NULL,
    name                  TEXT    NOT NULL DEFAULT '',
    url                   TEXT    NOT NULL DEFAULT '',
    email                 TEXT    NULL,
    image                 TEXT    NULL,
    sort_order            INTEGER NOT NULL DEFAULT 1,
    active                INTEGER NOT NULL DEFAULT 1,
    status                TEXT    NOT NULL DEFAULT 'approved',
    created_at            TEXT    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at            TEXT    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    link_status           TEXT    NOT NULL DEFAULT 'unchecked',
    link_checked_at       TEXT    NULL,
    link_redirect_url     TEXT    NULL,
    reciprocal_status     TEXT    NOT NULL DEFAULT 'unchecked',
    reciprocal_checked_at TEXT    NULL,
    pending               INTEGER NOT NULL DEFAULT 0,
    application_message   TEXT    NULL
)
SQL;

    private AffiliateRepo $repo;

    protected function setUp(): void
    {
        $this->repo = new AffiliateRepo(SqliteDatabase::make(self::DDL));
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    private function insertAffiliate(string $name, string $url, ?int $listingId = null): int
    {
        return $this->repo->insert(['name' => $name, 'url' => $url, 'fanlisting_id' => $listingId]);
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
        $this->insertAffiliate('Alpha', 'https://alpha.test');
        $this->insertAffiliate('Beta',  'https://beta.test');
        $this->insertAffiliate('Gamma', 'https://gamma.test');

        $items = $this->repo->getAllForLinkCheck();
        self::assertCount(3, $items);
    }

    public function testGetAllForLinkCheckInitialStatusIsUnchecked(): void
    {
        $this->insertAffiliate('Alpha', 'https://alpha.test');

        $items = $this->repo->getAllForLinkCheck();
        self::assertSame('unchecked', $items[0]->linkStatus);
        self::assertSame('unchecked', $items[0]->reciprocalStatus);
        self::assertNull($items[0]->linkCheckedAt);
        self::assertNull($items[0]->reciprocalCheckedAt);
        self::assertNull($items[0]->linkRedirectUrl);
    }

    // -----------------------------------------------------------------------
    // updateLinkStatus
    // -----------------------------------------------------------------------

    public function testUpdateLinkStatusSetsOk(): void
    {
        $id = $this->insertAffiliate('Alpha', 'https://alpha.test');

        $this->repo->updateLinkStatus($id, 'ok', null, '2025-06-01 12:00:00');

        $item = $this->repo->getById($id);
        self::assertNotNull($item);
        self::assertSame('ok', $item->linkStatus);
        self::assertSame('2025-06-01 12:00:00', $item->linkCheckedAt);
        self::assertNull($item->linkRedirectUrl);
    }

    public function testUpdateLinkStatusStoresRedirectUrl(): void
    {
        $id = $this->insertAffiliate('Alpha', 'https://alpha.test');

        $this->repo->updateLinkStatus($id, 'redirect', 'https://new.test/path', '2025-06-01 12:00:00');

        $item = $this->repo->getById($id);
        self::assertNotNull($item);
        self::assertSame('redirect', $item->linkStatus);
        self::assertSame('https://new.test/path', $item->linkRedirectUrl);
    }

    public function testUpdateLinkStatusSetsDead(): void
    {
        $id = $this->insertAffiliate('Alpha', 'https://alpha.test');

        $this->repo->updateLinkStatus($id, 'dead', null, '2025-06-01 12:00:00');

        $item = $this->repo->getById($id);
        self::assertNotNull($item);
        self::assertSame('dead', $item->linkStatus);
        self::assertNull($item->linkRedirectUrl);
    }

    public function testUpdateLinkStatusDoesNotTouchReciprocalStatus(): void
    {
        $id = $this->insertAffiliate('Alpha', 'https://alpha.test');
        $this->repo->updateReciprocalStatus($id, 'found', '2025-06-01 10:00:00');

        $this->repo->updateLinkStatus($id, 'ok', null, '2025-06-01 12:00:00');

        $item = $this->repo->getById($id);
        self::assertNotNull($item);
        // reciprocal_status must be unchanged
        self::assertSame('found', $item->reciprocalStatus);
    }

    // -----------------------------------------------------------------------
    // updateReciprocalStatus
    // -----------------------------------------------------------------------

    public function testUpdateReciprocalStatusSetsFound(): void
    {
        $id = $this->insertAffiliate('Alpha', 'https://alpha.test');

        $this->repo->updateReciprocalStatus($id, 'found', '2025-06-01 12:00:00');

        $item = $this->repo->getById($id);
        self::assertNotNull($item);
        self::assertSame('found', $item->reciprocalStatus);
        self::assertSame('2025-06-01 12:00:00', $item->reciprocalCheckedAt);
    }

    public function testUpdateReciprocalStatusSetsMissing(): void
    {
        $id = $this->insertAffiliate('Alpha', 'https://alpha.test');

        $this->repo->updateReciprocalStatus($id, 'missing', '2025-06-01 12:00:00');

        $item = $this->repo->getById($id);
        self::assertNotNull($item);
        self::assertSame('missing', $item->reciprocalStatus);
    }

    // -----------------------------------------------------------------------
    // resetLinkStatus
    // -----------------------------------------------------------------------

    public function testResetLinkStatusClearsAllStatuses(): void
    {
        $id1 = $this->insertAffiliate('Alpha', 'https://alpha.test');
        $id2 = $this->insertAffiliate('Beta',  'https://beta.test');

        $this->repo->updateLinkStatus($id1, 'ok',       null,                  '2025-06-01 12:00:00');
        $this->repo->updateLinkStatus($id2, 'redirect', 'https://new.test/p',  '2025-06-01 12:00:00');
        $this->repo->updateReciprocalStatus($id1, 'found',   '2025-06-01 12:00:00');
        $this->repo->updateReciprocalStatus($id2, 'missing', '2025-06-01 12:00:00');

        $this->repo->resetLinkStatus();

        foreach ([$this->repo->getById($id1), $this->repo->getById($id2)] as $item) {
            self::assertNotNull($item);
            self::assertSame('unchecked', $item->linkStatus);
            self::assertSame('unchecked', $item->reciprocalStatus);
            self::assertNull($item->linkCheckedAt);
            self::assertNull($item->linkRedirectUrl);
            self::assertNull($item->reciprocalCheckedAt);
        }
    }

    public function testResetLinkStatusOnEmptyTableDoesNotThrow(): void
    {
        $this->repo->resetLinkStatus(); // must not throw
        self::assertTrue(true);
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
        $id1 = $this->insertAffiliate('Alpha', 'https://alpha.test');
        $id2 = $this->insertAffiliate('Beta',  'https://beta.test');
        $id3 = $this->insertAffiliate('Gamma', 'https://gamma.test');

        $this->repo->updateLinkStatus($id1, 'ok',       null,                        '2025-06-01 12:00:00');
        $this->repo->updateLinkStatus($id2, 'redirect', 'https://new.beta.test/',    '2025-06-01 12:00:00');
        $this->repo->updateLinkStatus($id3, 'dead',     null,                        '2025-06-01 12:00:00');

        $redirects = $this->repo->getByLinkStatus('redirect');
        self::assertCount(1, $redirects);
        self::assertSame($id2, $redirects[0]->id);

        $dead = $this->repo->getByLinkStatus('dead');
        self::assertCount(1, $dead);
        self::assertSame($id3, $dead[0]->id);

        $ok = $this->repo->getByLinkStatus('ok');
        self::assertCount(1, $ok);
        self::assertSame($id1, $ok[0]->id);
    }

    public function testGetByLinkStatusReturnsMultiple(): void
    {
        $id1 = $this->insertAffiliate('Alpha', 'https://alpha.test');
        $id2 = $this->insertAffiliate('Beta',  'https://beta.test');

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
        $id = $this->insertAffiliate('Alpha', 'https://alpha.test');
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

    public function testApplyRedirectUrlDoesNotTouchReciprocalStatus(): void
    {
        $id = $this->insertAffiliate('Alpha', 'https://alpha.test');
        $this->repo->updateLinkStatus($id, 'redirect', 'https://new.alpha.test/', '2025-06-01 12:00:00');
        $this->repo->updateReciprocalStatus($id, 'found', '2025-06-01 10:00:00');

        $this->repo->applyRedirectUrl($id);

        $item = $this->repo->getById($id);
        self::assertNotNull($item);
        self::assertSame('found', $item->reciprocalStatus);
        self::assertSame('2025-06-01 10:00:00', $item->reciprocalCheckedAt);
    }

    public function testApplyRedirectUrlReturnsFalseWhenStatusIsNotRedirect(): void
    {
        $id = $this->insertAffiliate('Alpha', 'https://alpha.test');
        $this->repo->updateLinkStatus($id, 'ok', null, '2025-06-01 12:00:00');

        $result = $this->repo->applyRedirectUrl($id);
        self::assertFalse($result);

        $item = $this->repo->getById($id);
        self::assertNotNull($item);
        // url must be unchanged
        self::assertSame('https://alpha.test', $item->url);
    }

    public function testApplyRedirectUrlReturnsFalseWhenRedirectUrlIsNull(): void
    {
        $id = $this->insertAffiliate('Alpha', 'https://alpha.test');
        // Force link_status = 'redirect' with no stored redirect URL (edge case)
        $this->repo->updateLinkStatus($id, 'redirect', null, '2025-06-01 12:00:00');

        $result = $this->repo->applyRedirectUrl($id);
        self::assertFalse($result);
    }

    public function testApplyRedirectUrlIsIdempotentOnDoubleSubmit(): void
    {
        $id = $this->insertAffiliate('Alpha', 'https://alpha.test');
        $this->repo->updateLinkStatus($id, 'redirect', 'https://new.alpha.test/', '2025-06-01 12:00:00');

        $first  = $this->repo->applyRedirectUrl($id);
        $second = $this->repo->applyRedirectUrl($id);

        self::assertTrue($first);
        self::assertFalse($second); // already reset to 'unchecked', WHERE clause does not match
    }

    public function testApplyRedirectUrlReturnsFalseForUnknownId(): void
    {
        self::assertFalse($this->repo->applyRedirectUrl(9999));
    }
}
