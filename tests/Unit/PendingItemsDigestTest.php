<?php

declare(strict_types=1);

namespace Luster\Tests\Unit;

use Luster\Core\Config;
use Luster\Core\Database;
use Luster\Core\PendingItemsDigest;
use Luster\Tests\Unit\Support\SqliteDatabase;
use PDO;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * Tests PendingItemsDigest::gather() and renderEmailBody().
 *
 * Uses in-memory SQLite via SqliteDatabase; injects a Config with
 * enth_table_prefix = '' (unprefixed schema: pending=1 means queued).
 */
final class PendingItemsDigestTest extends TestCase
{
    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    private static string $baseDdl = <<<'SQL'
CREATE TABLE owned (
    listingid INTEGER PRIMARY KEY,
    title     TEXT NOT NULL DEFAULT '',
    subject   TEXT NOT NULL DEFAULT '',
    dbtable   TEXT NOT NULL DEFAULT '',
    email     TEXT NOT NULL DEFAULT '',
    url       TEXT NOT NULL DEFAULT '',
    catid     TEXT NOT NULL DEFAULT '0',
    additional TEXT NOT NULL DEFAULT '',
    status    INTEGER NOT NULL DEFAULT 2,
    listingtype TEXT NOT NULL DEFAULT 'listing'
);
CREATE TABLE lstr_affiliates (
    id     INTEGER PRIMARY KEY,
    status TEXT NOT NULL DEFAULT 'approved'
);
CREATE TABLE lstr_codes (
    id            INTEGER PRIMARY KEY,
    fanlisting_id INTEGER,
    status        TEXT NOT NULL DEFAULT 'approved'
);
CREATE TABLE lstr_pending_updates (
    id            INTEGER PRIMARY KEY,
    fanlisting_id INTEGER NOT NULL,
    member_email  TEXT NOT NULL,
    changes       TEXT NOT NULL DEFAULT '{}',
    submitted_at  TEXT NOT NULL DEFAULT ''
);
SQL;

    private static string $kimDdl = <<<'SQL'
CREATE TABLE lstr_kim_members (
    id           INTEGER PRIMARY KEY,
    fanlisting_id INTEGER NOT NULL,
    pending      INTEGER NOT NULL DEFAULT 0
)
SQL;

    private static string $adoptionDdl = <<<'SQL'
CREATE TABLE lstr_adoption_applications (
    id           INTEGER PRIMARY KEY,
    fanlisting_id INTEGER NOT NULL,
    status       TEXT NOT NULL DEFAULT 'pending'
)
SQL;

    /**
     * Builds a Database + Config + PDO tuple backed by in-memory SQLite.
     *
     * @param  string   $ddl          Semicolon-separated CREATE TABLE statements
     * @param  string[] $memberTables Bare table names to create as member tables
     * @return array{Database, Config, PDO}
     */
    private static function makeDb(string $ddl, array $memberTables = []): array
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        foreach (array_filter(array_map('trim', explode(';', $ddl))) as $stmt) {
            $pdo->exec($stmt);
        }

        foreach ($memberTables as $tbl) {
            $pdo->exec("CREATE TABLE {$tbl} (
                email   TEXT NOT NULL,
                name    TEXT NOT NULL DEFAULT '',
                country TEXT NOT NULL DEFAULT '',
                pending INTEGER NOT NULL DEFAULT 0
            )");
        }

        $cfgExport = var_export(['enth_table_prefix' => ''], true);
        $tmpCfg    = sys_get_temp_dir() . '/lstr_digest_cfg_' . uniqid('', true) . '.php';
        file_put_contents($tmpCfg, "<?php return {$cfgExport};");
        $config = new Config($tmpCfg);
        unlink($tmpCfg);

        $tmpDb = sys_get_temp_dir() . '/lstr_digest_db_' . uniqid('', true) . '.php';
        file_put_contents($tmpDb, '<?php return [];');
        $db = new Database(new Config($tmpDb));
        unlink($tmpDb);

        $prop = new ReflectionProperty(Database::class, 'pdo');
        $prop->setAccessible(true);
        $prop->setValue($db, $pdo);

        return [$db, $config, $pdo];
    }

    // -----------------------------------------------------------------------
    // gather() — zero pending
    // -----------------------------------------------------------------------

    public function testGatherReturnsZeroWhenNothingIsPending(): void
    {
        [$db, $config, $pdo] = self::makeDb(self::$baseDdl, ['huntrix']);
        $pdo->exec("INSERT INTO owned VALUES (1,'Huntrix','Huntrix','huntrix','','',0,'',2,'listing')");
        $pdo->exec("INSERT INTO huntrix VALUES ('a@test.com','Alice','US',0)"); // approved

        $digest = new PendingItemsDigest($db, $config, false, false);
        $data   = $digest->gather();

        $this->assertSame(0, $data['grand_total']);
        $this->assertSame(0, $data['members']['total']);
        $this->assertSame([], $data['members']['by_listing']);
        $this->assertSame(0, $data['affiliates']['total']);
        $this->assertSame(0, $data['codes']['total']);
        $this->assertSame(0, $data['updates']['total']);
        $this->assertNull($data['kim']);
        $this->assertNull($data['adoption']);
    }

    // -----------------------------------------------------------------------
    // gather() — pending members
    // -----------------------------------------------------------------------

    public function testGatherCountsPendingMembersPerListing(): void
    {
        [$db, $config, $pdo] = self::makeDb(self::$baseDdl, ['fans_alpha', 'fans_beta']);

        $pdo->exec("INSERT INTO owned VALUES (1,'Alpha','Alpha','fans_alpha','','',0,'',2,'listing')");
        $pdo->exec("INSERT INTO owned VALUES (2,'Beta','Beta','fans_beta','','',0,'',2,'listing')");

        $pdo->exec("INSERT INTO fans_alpha VALUES ('a@x.com','A','US',1)"); // pending
        $pdo->exec("INSERT INTO fans_alpha VALUES ('b@x.com','B','US',1)"); // pending
        $pdo->exec("INSERT INTO fans_alpha VALUES ('c@x.com','C','US',0)"); // approved
        $pdo->exec("INSERT INTO fans_beta VALUES  ('d@x.com','D','US',1)"); // pending

        $digest = new PendingItemsDigest($db, $config, false, false);
        $data   = $digest->gather();

        $this->assertSame(3, $data['members']['total']);
        $this->assertSame(3, $data['grand_total']);
        $this->assertCount(2, $data['members']['by_listing']);

        $byTitle = array_column($data['members']['by_listing'], 'count', 'title');
        $this->assertSame(2, $byTitle['Alpha']);
        $this->assertSame(1, $byTitle['Beta']);
    }

    public function testGatherOmitsListingsWithNoPendingMembers(): void
    {
        [$db, $config, $pdo] = self::makeDb(self::$baseDdl, ['fans_only']);

        $pdo->exec("INSERT INTO owned VALUES (1,'Only','Only','fans_only','','',0,'',2,'listing')");
        $pdo->exec("INSERT INTO fans_only VALUES ('a@x.com','A','US',0)"); // approved only

        $digest = new PendingItemsDigest($db, $config, false, false);
        $data   = $digest->gather();

        $this->assertSame([], $data['members']['by_listing']);
        $this->assertSame(0, $data['members']['total']);
    }

    // -----------------------------------------------------------------------
    // gather() — affiliates
    // -----------------------------------------------------------------------

    public function testGatherCountsPendingAffiliates(): void
    {
        [$db, $config, $pdo] = self::makeDb(self::$baseDdl);

        $pdo->exec("INSERT INTO lstr_affiliates (status) VALUES ('pending')");
        $pdo->exec("INSERT INTO lstr_affiliates (status) VALUES ('pending')");
        $pdo->exec("INSERT INTO lstr_affiliates (status) VALUES ('approved')");

        $digest = new PendingItemsDigest($db, $config, false, false);
        $data   = $digest->gather();

        $this->assertSame(2, $data['affiliates']['total']);
        $this->assertSame(2, $data['grand_total']);
    }

    // -----------------------------------------------------------------------
    // gather() — codes and updates
    // -----------------------------------------------------------------------

    public function testGatherCountsPendingCodesAndUpdates(): void
    {
        [$db, $config, $pdo] = self::makeDb(self::$baseDdl);

        $pdo->exec("INSERT INTO lstr_codes (fanlisting_id, status) VALUES (1, 'pending')");
        $pdo->exec("INSERT INTO lstr_codes (fanlisting_id, status) VALUES (1, 'approved')");
        $pdo->exec("INSERT INTO lstr_pending_updates (fanlisting_id, member_email, changes, submitted_at) VALUES (1,'a@b.com','{}','2026-01-01')");
        $pdo->exec("INSERT INTO lstr_pending_updates (fanlisting_id, member_email, changes, submitted_at) VALUES (1,'b@c.com','{}','2026-01-02')");
        $pdo->exec("INSERT INTO lstr_pending_updates (fanlisting_id, member_email, changes, submitted_at) VALUES (1,'c@d.com','{}','2026-01-03')");

        $digest = new PendingItemsDigest($db, $config, false, false);
        $data   = $digest->gather();

        $this->assertSame(1, $data['codes']['total']);
        $this->assertSame(3, $data['updates']['total']);
        $this->assertSame(4, $data['grand_total']);
    }

    // -----------------------------------------------------------------------
    // gather() — KIM add-on
    // -----------------------------------------------------------------------

    public function testGatherIncludesKimWhenInstalled(): void
    {
        $ddl = self::$baseDdl . ';' . self::$kimDdl;
        [$db, $config, $pdo] = self::makeDb($ddl);

        $pdo->exec("INSERT INTO lstr_kim_members (fanlisting_id, pending) VALUES (1, 1)");
        $pdo->exec("INSERT INTO lstr_kim_members (fanlisting_id, pending) VALUES (1, 1)");
        $pdo->exec("INSERT INTO lstr_kim_members (fanlisting_id, pending) VALUES (2, 0)"); // approved

        $digest = new PendingItemsDigest($db, $config, kimInstalled: true, adoptionInstalled: false);
        $data   = $digest->gather();

        $this->assertNotNull($data['kim']);
        $this->assertSame(2, $data['kim']['total']);
        $this->assertSame(2, $data['grand_total']);
    }

    public function testGatherKimIsNullWhenNotInstalled(): void
    {
        [$db, $config] = self::makeDb(self::$baseDdl);

        $digest = new PendingItemsDigest($db, $config, kimInstalled: false, adoptionInstalled: false);
        $data   = $digest->gather();

        $this->assertNull($data['kim']);
    }

    // -----------------------------------------------------------------------
    // gather() — Adoption add-on
    // -----------------------------------------------------------------------

    public function testGatherIncludesAdoptionWhenInstalled(): void
    {
        $ddl = self::$baseDdl . ';' . self::$adoptionDdl;
        [$db, $config, $pdo] = self::makeDb($ddl);

        $pdo->exec("INSERT INTO lstr_adoption_applications (fanlisting_id, status) VALUES (1, 'pending')");
        $pdo->exec("INSERT INTO lstr_adoption_applications (fanlisting_id, status) VALUES (1, 'approved')");

        $digest = new PendingItemsDigest($db, $config, kimInstalled: false, adoptionInstalled: true);
        $data   = $digest->gather();

        $this->assertNotNull($data['adoption']);
        $this->assertSame(1, $data['adoption']['total']);
        $this->assertSame(1, $data['grand_total']);
    }

    public function testGatherAdoptionIsNullWhenNotInstalled(): void
    {
        [$db, $config] = self::makeDb(self::$baseDdl);

        $digest = new PendingItemsDigest($db, $config, kimInstalled: false, adoptionInstalled: false);
        $data   = $digest->gather();

        $this->assertNull($data['adoption']);
    }

    // -----------------------------------------------------------------------
    // gather() — grand total aggregates all sources
    // -----------------------------------------------------------------------

    public function testGatherGrandTotalSumsAllSources(): void
    {
        $ddl = self::$baseDdl . ';' . self::$kimDdl . ';' . self::$adoptionDdl;
        [$db, $config, $pdo] = self::makeDb($ddl, ['fans_main']);

        $pdo->exec("INSERT INTO owned VALUES (1,'Main','Main','fans_main','','',0,'',2,'listing')");
        $pdo->exec("INSERT INTO fans_main VALUES ('a@x.com','A','US',1)");          // 1 pending member
        $pdo->exec("INSERT INTO lstr_affiliates (status) VALUES ('pending')");      // 1 affiliate
        $pdo->exec("INSERT INTO lstr_codes (fanlisting_id, status) VALUES (1,'pending')"); // 1 code
        $pdo->exec("INSERT INTO lstr_pending_updates (fanlisting_id,member_email,changes,submitted_at) VALUES (1,'a@b.com','{}','2026-01-01')"); // 1 update
        $pdo->exec("INSERT INTO lstr_kim_members (fanlisting_id, pending) VALUES (1,1)"); // 1 KIM
        $pdo->exec("INSERT INTO lstr_adoption_applications (fanlisting_id, status) VALUES (1,'pending')"); // 1 adoption

        $digest = new PendingItemsDigest($db, $config, kimInstalled: true, adoptionInstalled: true);
        $data   = $digest->gather();

        $this->assertSame(6, $data['grand_total']);
        $this->assertSame(1, $data['members']['total']);
        $this->assertSame(1, $data['affiliates']['total']);
        $this->assertSame(1, $data['codes']['total']);
        $this->assertSame(1, $data['updates']['total']);
        $this->assertSame(1, $data['kim']['total']);
        $this->assertSame(1, $data['adoption']['total']);
    }

    // -----------------------------------------------------------------------
    // renderEmailBody()
    // -----------------------------------------------------------------------

    public function testRenderEmailBodyContainsAllPresentCategories(): void
    {
        [$db, $config] = self::makeDb(self::$baseDdl);

        $data = [
            'members'     => ['total' => 3, 'by_listing' => [
                ['title' => 'Alpha', 'count' => 2],
                ['title' => 'Beta',  'count' => 1],
            ]],
            'affiliates'  => ['total' => 2],
            'codes'       => ['total' => 1],
            'updates'     => ['total' => 4],
            'kim'         => ['total' => 5],
            'adoption'    => ['total' => 1],
            'grand_total' => 16,
        ];

        $digest = new PendingItemsDigest($db, $config, kimInstalled: true, adoptionInstalled: true);
        $body   = $digest->renderEmailBody($data, 'My Collective', 'https://example.com/admin');

        $this->assertStringContainsString('Members awaiting approval: 3', $body);
        $this->assertStringContainsString('Alpha: 2', $body);
        $this->assertStringContainsString('Beta: 1', $body);
        $this->assertStringContainsString('Affiliate applications awaiting review: 2', $body);
        $this->assertStringContainsString('Code submissions awaiting review: 1', $body);
        $this->assertStringContainsString('Member update requests awaiting review: 4', $body);
        $this->assertStringContainsString('Keep In Mind sign-ups awaiting approval: 5', $body);
        $this->assertStringContainsString('Adoption applications awaiting review: 1', $body);
        $this->assertStringContainsString('My Collective', $body);
        $this->assertStringContainsString('https://example.com/admin', $body);
        $this->assertStringContainsString('16', $body);
    }

    public function testRenderEmailBodyOmitsZeroCountCategories(): void
    {
        [$db, $config] = self::makeDb(self::$baseDdl);

        $data = [
            'members'     => ['total' => 0, 'by_listing' => []],
            'affiliates'  => ['total' => 2],
            'codes'       => ['total' => 0],
            'updates'     => ['total' => 0],
            'kim'         => null,
            'adoption'    => null,
            'grand_total' => 2,
        ];

        $digest = new PendingItemsDigest($db, $config, kimInstalled: false, adoptionInstalled: false);
        $body   = $digest->renderEmailBody($data, 'My Site', 'https://example.com/admin');

        $this->assertStringContainsString('Affiliate applications awaiting review: 2', $body);
        $this->assertStringNotContainsString('Members awaiting', $body);
        $this->assertStringNotContainsString('Code submissions', $body);
        $this->assertStringNotContainsString('Keep In Mind', $body);
        $this->assertStringNotContainsString('Adoption', $body);
    }

    public function testRenderEmailBodyOmitsKimAndAdoptionWhenNullEvenIfNonZeroWouldBePresent(): void
    {
        [$db, $config] = self::makeDb(self::$baseDdl);

        // null means add-on not installed — should never appear
        $data = [
            'members'     => ['total' => 0, 'by_listing' => []],
            'affiliates'  => ['total' => 0],
            'codes'       => ['total' => 0],
            'updates'     => ['total' => 0],
            'kim'         => null,
            'adoption'    => null,
            'grand_total' => 0,
        ];

        $digest = new PendingItemsDigest($db, $config, kimInstalled: false, adoptionInstalled: false);
        $body   = $digest->renderEmailBody($data, 'Site', 'https://example.com/admin');

        $this->assertStringNotContainsString('Keep In Mind', $body);
        $this->assertStringNotContainsString('Adoption', $body);
    }

    public function testRenderEmailBodyIncludesSiteNameAndAdminUrl(): void
    {
        [$db, $config] = self::makeDb(self::$baseDdl);

        $data = [
            'members'     => ['total' => 1, 'by_listing' => [['title' => 'FL', 'count' => 1]]],
            'affiliates'  => ['total' => 0],
            'codes'       => ['total' => 0],
            'updates'     => ['total' => 0],
            'kim'         => null,
            'adoption'    => null,
            'grand_total' => 1,
        ];

        $digest = new PendingItemsDigest($db, $config, kimInstalled: false, adoptionInstalled: false);
        $body   = $digest->renderEmailBody($data, 'Starlight Collective', 'https://star.example.com/luster/public/admin');

        $this->assertStringContainsString('Starlight Collective', $body);
        $this->assertStringContainsString('https://star.example.com/luster/public/admin', $body);
    }
}
