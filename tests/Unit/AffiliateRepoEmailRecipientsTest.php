<?php

declare(strict_types=1);

namespace Luster\Tests\Unit;

use Luster\Repositories\AffiliateRepo;
use Luster\Tests\Unit\Support\SqliteDatabase;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for AffiliateRepo::getEmailRecipients().
 *
 * Covers:
 *  - scope='all'        returns all active non-pending affiliates with email
 *  - scope='collective' returns only fanlisting_id IS NULL rows
 *  - scope=int          returns only rows for that fanlisting_id
 *  - deduplication      by email, case-insensitive (first row by id wins)
 *  - inactive excluded  (active = 0)
 *  - pending excluded   (pending = 1)
 *  - blank email excluded
 *  - null email excluded
 */
final class AffiliateRepoEmailRecipientsTest extends TestCase
{
    // SQLite substitutes: TEXT for VARCHAR, INTEGER for TINYINT, no ENUM.
    private const DDL = <<<SQL
CREATE TABLE lstr_affiliates (
    id                   INTEGER PRIMARY KEY AUTOINCREMENT,
    fanlisting_id        INTEGER NULL,
    name                 TEXT    NOT NULL DEFAULT '',
    url                  TEXT    NOT NULL DEFAULT '',
    email                TEXT    NULL,
    image                TEXT    NULL,
    sort_order           INTEGER NOT NULL DEFAULT 0,
    active               INTEGER NOT NULL DEFAULT 1,
    created_at           TEXT    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at           TEXT    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    link_status          TEXT    NOT NULL DEFAULT 'unchecked',
    link_checked_at      TEXT    NULL,
    link_redirect_url    TEXT    NULL,
    reciprocal_status    TEXT    NOT NULL DEFAULT 'unchecked',
    reciprocal_checked_at TEXT   NULL,
    pending              INTEGER NOT NULL DEFAULT 0,
    application_message  TEXT    NULL
)
SQL;

    private AffiliateRepo $repo;
    private \PDO $pdo;

    protected function setUp(): void
    {
        $db         = SqliteDatabase::make(self::DDL);
        $this->repo = new AffiliateRepo($db);

        // Grab the raw PDO for direct inserts
        $prop = new \ReflectionProperty($db, 'pdo');
        $prop->setAccessible(true);
        $this->pdo = $prop->getValue($db);
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    private function insert(
        string  $name,
        string  $email,
        ?int    $fanlistingId = null,
        bool    $active       = true,
        bool    $pending      = false,
    ): void {
        $this->pdo->prepare(
            "INSERT INTO lstr_affiliates
             (name, url, email, fanlisting_id, active, pending)
             VALUES (:name, '', :email, :fid, :active, :pending)"
        )->execute([
            'name'    => $name,
            'email'   => $email,
            'fid'     => $fanlistingId,
            'active'  => (int) $active,
            'pending' => (int) $pending,
        ]);
    }

    // -----------------------------------------------------------------------
    // scope = 'all'
    // -----------------------------------------------------------------------

    public function testAllScopeReturnsActiveNonPendingWithEmail(): void
    {
        $this->insert('Alpha', 'alpha@example.com', 1);
        $this->insert('Beta',  'beta@example.com',  null);

        $results = $this->repo->getEmailRecipients('all');

        self::assertCount(2, $results);
        $emails = array_column($results, 'email');
        self::assertContains('alpha@example.com', $emails);
        self::assertContains('beta@example.com',  $emails);
    }

    public function testAllScopeReturnsEmptyWhenNoAffiliates(): void
    {
        self::assertSame([], $this->repo->getEmailRecipients('all'));
    }

    // -----------------------------------------------------------------------
    // scope = 'collective'
    // -----------------------------------------------------------------------

    public function testCollectiveScopeReturnsOnlyNullListingId(): void
    {
        $this->insert('Collective', 'coll@example.com', null);
        $this->insert('Listed',     'listed@example.com', 7);

        $results = $this->repo->getEmailRecipients('collective');

        self::assertCount(1, $results);
        self::assertSame('coll@example.com', $results[0]['email']);
        self::assertNull($results[0]['listing_id']);
    }

    public function testCollectiveScopeReturnsEmptyWhenNoCollectiveAffiliates(): void
    {
        $this->insert('Listed', 'listed@example.com', 3);

        self::assertSame([], $this->repo->getEmailRecipients('collective'));
    }

    // -----------------------------------------------------------------------
    // scope = int (listing-scoped)
    // -----------------------------------------------------------------------

    public function testIntScopeReturnsOnlyMatchingListingId(): void
    {
        $this->insert('ForListing2', 'l2@example.com', 2);
        $this->insert('ForListing3', 'l3@example.com', 3);
        $this->insert('Collective',  'coll@example.com', null);

        $results = $this->repo->getEmailRecipients(2);

        self::assertCount(1, $results);
        self::assertSame('l2@example.com', $results[0]['email']);
        self::assertSame(2,                $results[0]['listing_id']);
    }

    public function testIntScopeReturnsEmptyForUnknownListingId(): void
    {
        $this->insert('ForListing1', 'l1@example.com', 1);

        self::assertSame([], $this->repo->getEmailRecipients(99));
    }

    // -----------------------------------------------------------------------
    // Exclusion rules
    // -----------------------------------------------------------------------

    public function testInactiveAffiliatesExcluded(): void
    {
        $this->insert('Active',   'active@example.com', null, active: true,  pending: false);
        $this->insert('Inactive', 'inact@example.com',  null, active: false, pending: false);

        $results = $this->repo->getEmailRecipients('all');

        self::assertCount(1, $results);
        self::assertSame('active@example.com', $results[0]['email']);
    }

    public function testPendingAffiliatesExcluded(): void
    {
        $this->insert('Approved', 'approved@example.com', null, active: true, pending: false);
        $this->insert('Pending',  'pending@example.com',  null, active: true, pending: true);

        $results = $this->repo->getEmailRecipients('all');

        self::assertCount(1, $results);
        self::assertSame('approved@example.com', $results[0]['email']);
    }

    public function testBlankEmailExcluded(): void
    {
        $this->insert('NoEmail',  '', null);
        $this->insert('HasEmail', 'has@example.com', null);

        $results = $this->repo->getEmailRecipients('all');

        self::assertCount(1, $results);
        self::assertSame('has@example.com', $results[0]['email']);
    }

    public function testNullEmailExcluded(): void
    {
        // Insert row with NULL email directly (helper always passes a string)
        $this->pdo->prepare(
            "INSERT INTO lstr_affiliates (name, url, email, active, pending)
             VALUES ('NullEmail', '', NULL, 1, 0)"
        )->execute();
        $this->insert('HasEmail', 'has@example.com', null);

        $results = $this->repo->getEmailRecipients('all');

        self::assertCount(1, $results);
        self::assertSame('has@example.com', $results[0]['email']);
    }

    public function testWhitespaceOnlyEmailExcluded(): void
    {
        $this->insert('Spaces', '   ', null);
        $this->insert('HasEmail', 'has@example.com', null);

        $results = $this->repo->getEmailRecipients('all');

        self::assertCount(1, $results);
        self::assertSame('has@example.com', $results[0]['email']);
    }

    public function testEmailIsTrimmedInReturnedRow(): void
    {
        $this->insert('Padded', '  padded@example.com  ', null);

        $results = $this->repo->getEmailRecipients('all');

        self::assertCount(1, $results);
        self::assertSame('padded@example.com', $results[0]['email']);
    }

    // -----------------------------------------------------------------------
    // Deduplication (first row by id wins)
    // -----------------------------------------------------------------------

    public function testDuplicateEmailsDeduplicatedFirstWins(): void
    {
        $this->insert('First',  'dup@example.com', 1);
        $this->insert('Second', 'dup@example.com', 2);

        $results = $this->repo->getEmailRecipients('all');

        self::assertCount(1, $results);
        self::assertSame('First', $results[0]['name']);
        self::assertSame(1,       $results[0]['listing_id']);
    }

    public function testDeduplicationIsCaseInsensitive(): void
    {
        $this->insert('Lower', 'dup@example.com',   null);
        $this->insert('Upper', 'DUP@EXAMPLE.COM',   null);
        $this->insert('Mixed', 'Dup@Example.Com',   null);

        $results = $this->repo->getEmailRecipients('all');

        self::assertCount(1, $results);
        self::assertSame('Lower', $results[0]['name']);
    }

    // -----------------------------------------------------------------------
    // Return shape
    // -----------------------------------------------------------------------

    public function testReturnShapeContainsRequiredKeys(): void
    {
        $this->insert('Site', 'site@example.com', 5);

        $results = $this->repo->getEmailRecipients('all');

        self::assertCount(1, $results);
        $row = $results[0];
        self::assertArrayHasKey('name',       $row);
        self::assertArrayHasKey('email',      $row);
        self::assertArrayHasKey('url',        $row);
        self::assertArrayHasKey('listing_id', $row);
        self::assertSame('Site',              $row['name']);
        self::assertSame('site@example.com',  $row['email']);
        self::assertSame(5,                   $row['listing_id']);
    }
}
