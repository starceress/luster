<?php

declare(strict_types=1);

namespace Luster\Tests\Unit;

use Luster\Repositories\AffiliateRepo;
use Luster\Tests\Unit\Support\SqliteDatabase;
use PHPUnit\Framework\TestCase;

/**
 * Tests for AffiliateRepo::getPendingForScope() and getPendingIdsWithUrlConflict().
 *
 * Covers:
 *  - getPendingForScope('all')         — returns every pending row
 *  - getPendingForScope('collective')  — only fanlisting_id IS NULL rows
 *  - getPendingForScope('{int}')       — only the specified listing ID
 *  - getPendingForScope('all')         — falls through for unknown string
 *  - getPendingIdsWithUrlConflict()    — IDs of pending affiliates whose URL already
 *                                        exists as an active affiliate in the same scope
 *  - getPendingIdsWithUrlConflict()    — collective and per-listing scopes are isolated
 */
final class AffiliatePendingFilterTest extends TestCase
{
    private const DDL = <<<SQL
CREATE TABLE lstr_affiliates (
    id                   INTEGER PRIMARY KEY AUTOINCREMENT,
    fanlisting_id        INTEGER NULL,
    name                 TEXT    NOT NULL DEFAULT '',
    url                  TEXT    NOT NULL DEFAULT '',
    email                TEXT    NULL,
    image                TEXT    NULL,
    sort_order           INTEGER NOT NULL DEFAULT 1,
    active               INTEGER NOT NULL DEFAULT 0,
    status               TEXT    NOT NULL DEFAULT 'approved',
    created_at           TEXT    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at           TEXT    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    link_status          TEXT    NOT NULL DEFAULT 'unchecked',
    link_checked_at      TEXT    NULL,
    link_redirect_url    TEXT    NULL,
    reciprocal_status    TEXT    NOT NULL DEFAULT 'unchecked',
    reciprocal_checked_at TEXT   NULL,
    pending              INTEGER NOT NULL DEFAULT 1,
    application_message  TEXT    NULL
)
SQL;

    private AffiliateRepo $repo;
    private \PDO $pdo;

    protected function setUp(): void
    {
        $db         = SqliteDatabase::make(self::DDL);
        $this->repo = new AffiliateRepo($db);

        $prop = new \ReflectionProperty($db, 'pdo');
        $prop->setAccessible(true);
        $this->pdo = $prop->getValue($db);
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    private function insertPending(?int $fanlistingId, string $url): int
    {
        return $this->repo->insertPending([
            'fanlisting_id' => $fanlistingId,
            'name'          => 'Site ' . $url,
            'url'           => $url,
        ]);
    }

    /** Insert a fully-active (non-pending) affiliate directly via PDO. */
    private function insertActive(?int $fanlistingId, string $url): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO lstr_affiliates (fanlisting_id, name, url, sort_order, active, pending)
             VALUES (:fid, :name, :url, 1, 1, 0)'
        );
        $stmt->execute([
            'fid'  => $fanlistingId,
            'name' => 'Active ' . $url,
            'url'  => $url,
        ]);
    }

    // -----------------------------------------------------------------------
    // getPendingForScope
    // -----------------------------------------------------------------------

    public function testAllScopeReturnsEveryPendingRow(): void
    {
        $this->insertPending(null, 'https://collective.example');
        $this->insertPending(1,    'https://listing1.example');
        $this->insertPending(2,    'https://listing2.example');

        $result = $this->repo->getPendingForScope('all');

        self::assertCount(3, $result);
    }

    public function testCollectiveScopeReturnsOnlyNullFanlistingId(): void
    {
        $this->insertPending(null, 'https://collective.example');
        $this->insertPending(1,    'https://listing1.example');
        $this->insertPending(2,    'https://listing2.example');

        $result = $this->repo->getPendingForScope('collective');

        self::assertCount(1, $result);
        self::assertNull($result[0]->listingId);
    }

    public function testListingScopeFiltersToSingleListing(): void
    {
        $this->insertPending(null, 'https://collective.example');
        $this->insertPending(3,    'https://listing3a.example');
        $this->insertPending(3,    'https://listing3b.example');
        $this->insertPending(5,    'https://listing5.example');

        $result = $this->repo->getPendingForScope('3');

        self::assertCount(2, $result);
        foreach ($result as $aff) {
            self::assertSame(3, $aff->listingId);
        }
    }

    public function testUnknownScopeFallsBackToAll(): void
    {
        $this->insertPending(null, 'https://a.example');
        $this->insertPending(1,    'https://b.example');

        // 'xyz' is not a valid int-string, so falls through to 'all'
        $result = $this->repo->getPendingForScope('xyz');

        self::assertCount(2, $result);
    }

    public function testEmptyQueueReturnsEmptyArray(): void
    {
        self::assertSame([], $this->repo->getPendingForScope('all'));
        self::assertSame([], $this->repo->getPendingForScope('collective'));
        self::assertSame([], $this->repo->getPendingForScope('7'));
    }

    // -----------------------------------------------------------------------
    // getPendingIdsWithUrlConflict
    // -----------------------------------------------------------------------

    public function testNoConflictWhenNoActiveAffiliates(): void
    {
        $this->insertPending(null, 'https://new.example');
        $this->insertPending(1,    'https://new2.example');

        self::assertSame([], $this->repo->getPendingIdsWithUrlConflict());
    }

    public function testDetectsCollectiveScopeConflict(): void
    {
        $this->insertActive(null, 'https://existing.example');
        $conflictId = $this->insertPending(null, 'https://existing.example');
        $this->insertPending(null, 'https://unique.example');

        $conflicts = $this->repo->getPendingIdsWithUrlConflict();

        self::assertContains($conflictId, $conflicts);
        self::assertCount(1, $conflicts);
    }

    public function testDetectsPerListingConflict(): void
    {
        $this->insertActive(5, 'https://existing.example');
        $conflictId = $this->insertPending(5, 'https://existing.example');
        $cleanId    = $this->insertPending(5, 'https://new.example');

        $conflicts = $this->repo->getPendingIdsWithUrlConflict();

        self::assertContains($conflictId, $conflicts);
        self::assertNotContains($cleanId, $conflicts);
    }

    public function testCollectiveConflictDoesNotBleedIntoListing(): void
    {
        // Active collective affiliate with URL X
        $this->insertActive(null, 'https://shared.example');

        // Pending per-listing affiliate with same URL — NOT a conflict (different scope)
        $listingPendingId = $this->insertPending(7, 'https://shared.example');

        $conflicts = $this->repo->getPendingIdsWithUrlConflict();

        self::assertNotContains($listingPendingId, $conflicts);
    }

    public function testListingConflictDoesNotBleedIntoCollective(): void
    {
        // Active per-listing affiliate with URL X
        $this->insertActive(7, 'https://shared.example');

        // Pending collective affiliate with same URL — NOT a conflict (different scope)
        $collectivePendingId = $this->insertPending(null, 'https://shared.example');

        $conflicts = $this->repo->getPendingIdsWithUrlConflict();

        self::assertNotContains($collectivePendingId, $conflicts);
    }

    public function testInactiveActiveAffiliateDoesNotTriggerConflict(): void
    {
        // An inactive (active=0) affiliate should not count as a conflict
        $stmt = $this->pdo->prepare(
            'INSERT INTO lstr_affiliates (fanlisting_id, name, url, sort_order, active, pending)
             VALUES (NULL, :name, :url, 1, 0, 0)'
        );
        $stmt->execute(['name' => 'Inactive', 'url' => 'https://inactive.example']);

        $pendingId = $this->insertPending(null, 'https://inactive.example');

        self::assertNotContains($pendingId, $this->repo->getPendingIdsWithUrlConflict());
    }
}
