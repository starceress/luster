<?php

declare(strict_types=1);

namespace Luster\Tests\Unit;

use Luster\Core\Config;
use Luster\Repositories\CollectiveStatsRepo;
use Luster\Tests\Unit\Support\SqliteDatabase;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Tests for affiliate count methods in CollectiveStatsRepo.
 *
 * Verifies that getSitewideAffiliateCount() returns only fanlisting_id IS NULL rows,
 * getTotalAffiliateCount() returns all active rows regardless of scope, and that
 * the deprecated getAffiliateCount() alias now delegates to the site-wide method.
 */
final class CollectiveStatsAffiliateCountTest extends TestCase
{
    private const DDL = <<<SQL
CREATE TABLE lstr_affiliates (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    fanlisting_id INTEGER NULL,
    name          TEXT    NOT NULL DEFAULT '',
    url           TEXT    NOT NULL DEFAULT '',
    active        INTEGER NOT NULL DEFAULT 1,
    pending       INTEGER NOT NULL DEFAULT 0
)
SQL;

    /** @return array{CollectiveStatsRepo, PDO} */
    private function makeRepo(): array
    {
        $db = SqliteDatabase::make(self::DDL);

        $prop = new \ReflectionProperty($db, 'pdo');
        $prop->setAccessible(true);
        $pdo = $prop->getValue($db);

        $tmp = tempnam(sys_get_temp_dir(), 'lstr_test_');
        file_put_contents($tmp, "<?php return ['enth_table_prefix' => ''];");
        $config = new Config($tmp);
        unlink($tmp);

        return [new CollectiveStatsRepo($db, $config), $pdo];
    }

    private function insert(PDO $pdo, ?int $fanlistingId, int $active = 1): void
    {
        $pdo->prepare(
            'INSERT INTO lstr_affiliates (fanlisting_id, name, url, active) VALUES (:fid, :name, :url, :active)'
        )->execute(['fid' => $fanlistingId, 'name' => 'Test', 'url' => 'https://example.com', 'active' => $active]);
    }

    // -----------------------------------------------------------------------
    // getSitewideAffiliateCount — only fanlisting_id IS NULL and active = 1
    // -----------------------------------------------------------------------

    public function testSitewideCountIsZeroWhenTableEmpty(): void
    {
        [$repo] = $this->makeRepo();
        $this->assertSame(0, $repo->getSitewideAffiliateCount());
    }

    public function testSitewideCountExcludesPerListingAffiliates(): void
    {
        [$repo, $pdo] = $this->makeRepo();
        $this->insert($pdo, fanlistingId: 1);
        $this->insert($pdo, fanlistingId: 2);
        $this->assertSame(0, $repo->getSitewideAffiliateCount());
    }

    public function testSitewideCountIncludesNullScopeAffiliates(): void
    {
        [$repo, $pdo] = $this->makeRepo();
        $this->insert($pdo, fanlistingId: null);
        $this->insert($pdo, fanlistingId: null);
        $this->assertSame(2, $repo->getSitewideAffiliateCount());
    }

    public function testSitewideCountExcludesInactiveNullAffiliates(): void
    {
        [$repo, $pdo] = $this->makeRepo();
        $this->insert($pdo, fanlistingId: null, active: 0);
        $this->assertSame(0, $repo->getSitewideAffiliateCount());
    }

    public function testSitewideCountWithMixedScopes(): void
    {
        [$repo, $pdo] = $this->makeRepo();
        $this->insert($pdo, fanlistingId: null, active: 1); // counted
        $this->insert($pdo, fanlistingId: null, active: 1); // counted
        $this->insert($pdo, fanlistingId: null, active: 0); // inactive — excluded
        $this->insert($pdo, fanlistingId: 1,    active: 1); // per-listing — excluded
        $this->insert($pdo, fanlistingId: 2,    active: 1); // per-listing — excluded
        $this->assertSame(2, $repo->getSitewideAffiliateCount());
    }

    // -----------------------------------------------------------------------
    // getTotalAffiliateCount — all active affiliates across all scopes
    // -----------------------------------------------------------------------

    public function testTotalCountIsZeroWhenTableEmpty(): void
    {
        [$repo] = $this->makeRepo();
        $this->assertSame(0, $repo->getTotalAffiliateCount());
    }

    public function testTotalCountIncludesPerListingAffiliates(): void
    {
        [$repo, $pdo] = $this->makeRepo();
        $this->insert($pdo, fanlistingId: null, active: 1); // sitewide
        $this->insert($pdo, fanlistingId: 1,    active: 1); // per-listing
        $this->insert($pdo, fanlistingId: 2,    active: 1); // per-listing
        $this->assertSame(3, $repo->getTotalAffiliateCount());
    }

    public function testTotalCountExcludesInactiveAffiliates(): void
    {
        [$repo, $pdo] = $this->makeRepo();
        $this->insert($pdo, fanlistingId: null, active: 1); // counted
        $this->insert($pdo, fanlistingId: 1,    active: 0); // inactive — excluded
        $this->assertSame(1, $repo->getTotalAffiliateCount());
    }

    public function testTotalAndSitewideAreDistinctWhenPerListingAffiliatesExist(): void
    {
        [$repo, $pdo] = $this->makeRepo();
        $this->insert($pdo, fanlistingId: null, active: 1);
        $this->insert($pdo, fanlistingId: 1,    active: 1);
        $this->insert($pdo, fanlistingId: 1,    active: 1);

        $this->assertSame(1, $repo->getSitewideAffiliateCount());
        $this->assertSame(3, $repo->getTotalAffiliateCount());
    }

    // -----------------------------------------------------------------------
    // getAffiliateCount — deprecated alias must now return sitewide count
    // -----------------------------------------------------------------------

    public function testDeprecatedAliasReturnsSitewideCount(): void
    {
        [$repo, $pdo] = $this->makeRepo();
        $this->insert($pdo, fanlistingId: null, active: 1); // sitewide
        $this->insert($pdo, fanlistingId: 5,    active: 1); // per-listing

        // Deprecated alias must return 1 (sitewide only), not 2 (all active).
        $this->assertSame(1, $repo->getAffiliateCount());
    }
}
