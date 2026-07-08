<?php

declare(strict_types=1);

namespace Luster\Tests\Unit;

use Luster\Repositories\AffiliateRepo;
use Luster\Tests\Unit\Support\SqliteDatabase;
use PHPUnit\Framework\TestCase;

/**
 * Regression tests for collective affiliate application support.
 *
 * Covers:
 *  - insertPending() stores fanlisting_id IS NULL (not 0) for collective applications
 *  - isDuplicateApplication() matches on URL only (not email), so one applicant
 *    can submit multiple different sites under the same email address
 *  - isDuplicateApplication() ignores rejected applications, so a rejected site
 *    can be resubmitted
 *  - isDuplicateApplication() with null scope is isolated from listing scope
 */
final class CollectiveAffiliateApplyTest extends TestCase
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
    // insertPending — fanlisting_id = null (collective)
    // -----------------------------------------------------------------------

    public function testInsertPendingStoresNullFanlistingId(): void
    {
        $id = $this->repo->insertPending([
            'fanlisting_id' => null,
            'name'          => 'Cool Site',
            'url'           => 'https://cool.example',
            'email'         => 'cool@example.com',
        ]);

        self::assertGreaterThan(0, $id);

        $row = $this->pdo->query("SELECT fanlisting_id FROM lstr_affiliates WHERE id = {$id}")->fetch();

        self::assertNotFalse($row, 'Row should exist');
        self::assertNull($row['fanlisting_id'], 'fanlisting_id must be SQL NULL, not 0');
    }

    public function testInsertPendingDoesNotConvertNullToZero(): void
    {
        $this->repo->insertPending([
            'fanlisting_id' => null,
            'name'          => 'Collective Applicant',
            'url'           => 'https://applicant.example',
        ]);

        $count = (int) $this->pdo
            ->query("SELECT COUNT(*) FROM lstr_affiliates WHERE fanlisting_id = 0")
            ->fetchColumn();

        self::assertSame(0, $count, 'No row should have fanlisting_id = 0');
    }

    // -----------------------------------------------------------------------
    // isDuplicateApplication — null scope
    // -----------------------------------------------------------------------

    public function testNullScopeDuplicateByUrl(): void
    {
        $this->repo->insertPending([
            'fanlisting_id' => null,
            'name'          => 'Existing',
            'url'           => 'https://existing.example',
        ]);

        self::assertTrue(
            $this->repo->isDuplicateApplication(null, 'https://existing.example'),
            'Duplicate URL in collective scope must be detected'
        );
    }

    public function testNullScopeSameEmailDifferentUrlIsNotDuplicate(): void
    {
        $this->repo->insertPending([
            'fanlisting_id' => null,
            'name'          => 'Existing',
            'url'           => 'https://existing.example',
            'email'         => 'owner@existing.example',
        ]);

        // Same applicant (same email), a different site -- must be allowed so
        // one person can affiliate multiple sites.
        self::assertFalse(
            $this->repo->isDuplicateApplication(null, 'https://other.example'),
            'Same email with a different URL must NOT be blocked as a duplicate'
        );
    }

    public function testNullScopeNoDuplicateWhenEmpty(): void
    {
        self::assertFalse(
            $this->repo->isDuplicateApplication(null, 'https://new.example')
        );
    }

    public function testNullScopeRejectedApplicationDoesNotBlockResubmission(): void
    {
        $this->pdo->exec(
            "INSERT INTO lstr_affiliates (fanlisting_id, name, url, email, status, pending, active)
             VALUES (NULL, 'Rejected Site', 'https://rejected.example', 'owner@rejected.example', 'rejected', 0, 0)"
        );

        self::assertFalse(
            $this->repo->isDuplicateApplication(null, 'https://rejected.example'),
            'A rejected application must not block resubmission of the same site'
        );
    }

    public function testIntScopeRejectedApplicationDoesNotBlockResubmission(): void
    {
        $this->pdo->exec(
            "INSERT INTO lstr_affiliates (fanlisting_id, name, url, email, status, pending, active)
             VALUES (7, 'Rejected Site', 'https://rejected-listing.example', 'owner@rejected.example', 'rejected', 0, 0)"
        );

        self::assertFalse(
            $this->repo->isDuplicateApplication(7, 'https://rejected-listing.example'),
            'A rejected application must not block resubmission in listing scope'
        );
    }

    public function testNullScopeIsolatedFromListingScope(): void
    {
        // An existing collective affiliate should NOT block a per-listing application
        // with the same URL, and vice versa.
        $this->repo->insertPending([
            'fanlisting_id' => null,
            'name'          => 'Collective Aff',
            'url'           => 'https://shared-url.example',
            'email'         => 'shared@example.com',
        ]);

        // Checking listing scope (id = 5) should NOT find the collective row
        self::assertFalse(
            $this->repo->isDuplicateApplication(5, 'https://shared-url.example'),
            'Collective-scope row must not block per-listing application'
        );

        // Insert a per-listing row
        $this->repo->insertPending([
            'fanlisting_id' => 5,
            'name'          => 'Listing Aff',
            'url'           => 'https://listing-url.example',
            'email'         => 'listing@example.com',
        ]);

        // Checking collective scope should NOT find the per-listing row
        self::assertFalse(
            $this->repo->isDuplicateApplication(null, 'https://listing-url.example'),
            'Per-listing row must not block collective application'
        );
    }

    public function testIntScopeStillWorksThroughNullBranchRefactor(): void
    {
        $this->repo->insertPending([
            'fanlisting_id' => 3,
            'name'          => 'Site A',
            'url'           => 'https://a.example',
            'email'         => 'a@example.com',
        ]);

        self::assertTrue(
            $this->repo->isDuplicateApplication(3, 'https://a.example'),
            'Listing-scope duplicate detection must still work after refactor'
        );

        self::assertFalse(
            $this->repo->isDuplicateApplication(3, 'https://new.example'),
            'Non-duplicate in listing scope must return false'
        );
    }
}
