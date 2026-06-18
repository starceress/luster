<?php

declare(strict_types=1);

namespace Luster\Tests\Unit;

use Luster\Core\Config;
use Luster\Core\Database;
use Luster\Repositories\ListingRepo;
use Luster\Repositories\ListingSettingsRepo;
use Luster\Repositories\SiteSettingsRepo;
use Luster\Tests\Unit\Support\SqliteDatabase;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the collective Adoption Form (PLAN-collective-templates-selector.md, Phase B):
 *  - ListingSettingsRepo::getAdoptableListings()
 *  - SiteSettingsRepo::defaultCollectiveAdoptionFormTemplate()
 *  - SiteSettingsRepo::collectiveDefaults()['collective_adoption_form_template']
 *  - SiteSettingsRepo::get/set('collective_adoption_form_template')
 */
final class CollectiveAdoptionFormTemplateTest extends TestCase
{
    private const OWNED_AND_SETTINGS_DDL = <<<SQL
CREATE TABLE owned (
    listingid   INTEGER PRIMARY KEY AUTOINCREMENT,
    title       TEXT    NOT NULL DEFAULT '',
    url         TEXT    NOT NULL DEFAULT 'https://example.com',
    email       TEXT    NOT NULL DEFAULT '',
    subject     TEXT    NOT NULL DEFAULT '',
    status      INTEGER NOT NULL DEFAULT 2,
    dbtable     TEXT    NOT NULL DEFAULT '',
    additional  TEXT    NOT NULL DEFAULT ''
);
CREATE TABLE lstr_fanlisting_settings (
    fanlisting_id INTEGER PRIMARY KEY,
    for_adoption  INTEGER NOT NULL DEFAULT 0
)
SQL;

    private const SITE_SETTINGS_DDL = <<<SQL
CREATE TABLE lstr_site_settings (
    setting_key   TEXT NOT NULL PRIMARY KEY,
    setting_value TEXT NOT NULL DEFAULT ''
)
SQL;

    // -----------------------------------------------------------------------
    // ListingSettingsRepo::getAdoptableListings()
    // -----------------------------------------------------------------------

    public function testReturnsEmptyArrayWhenNoneFlagged(): void
    {
        [$db, $settingsRepo, $listingRepo] = $this->makeRepos();
        $this->insertListing($db, 'Archeology');

        self::assertSame([], $settingsRepo->getAdoptableListings($listingRepo));
    }

    public function testReturnsFlaggedActiveListing(): void
    {
        [$db, $settingsRepo, $listingRepo] = $this->makeRepos();
        $id = $this->insertListing($db, 'Archeology');
        $this->flagForAdoption($db, $id);

        $result = $settingsRepo->getAdoptableListings($listingRepo);

        self::assertCount(1, $result);
        self::assertSame($id, $result[0]['id']);
        self::assertSame('Archeology', $result[0]['title']);
    }

    public function testExcludesUnflaggedListings(): void
    {
        [$db, $settingsRepo, $listingRepo] = $this->makeRepos();
        $flagged = $this->insertListing($db, 'Flagged');
        $this->insertListing($db, 'Not Flagged');
        $this->flagForAdoption($db, $flagged);

        $result = $settingsRepo->getAdoptableListings($listingRepo);

        self::assertCount(1, $result);
        self::assertSame('Flagged', $result[0]['title']);
    }

    public function testExcludesTroubleListings(): void
    {
        [$db, $settingsRepo, $listingRepo] = $this->makeRepos();
        // status = 0 -> trouble/inactive in the unprefixed owned schema
        $id = $this->insertListing($db, 'Inactive', status: 0);
        $this->flagForAdoption($db, $id);

        self::assertSame([], $settingsRepo->getAdoptableListings($listingRepo));
    }

    public function testIncludesUpcomingListings(): void
    {
        [$db, $settingsRepo, $listingRepo] = $this->makeRepos();
        // status = 1 -> upcoming, not trouble
        $id = $this->insertListing($db, 'Upcoming', status: 1);
        $this->flagForAdoption($db, $id);

        $result = $settingsRepo->getAdoptableListings($listingRepo);

        self::assertCount(1, $result);
        self::assertSame('Upcoming', $result[0]['title']);
    }

    public function testSortedByTitleCaseInsensitive(): void
    {
        [$db, $settingsRepo, $listingRepo] = $this->makeRepos();
        $b = $this->insertListing($db, 'bravo');
        $a = $this->insertListing($db, 'Alpha');
        $c = $this->insertListing($db, 'Charlie');
        foreach ([$a, $b, $c] as $id) {
            $this->flagForAdoption($db, $id);
        }

        $titles = array_map(
            static fn(array $row) => $row['title'],
            $settingsRepo->getAdoptableListings($listingRepo),
        );

        self::assertSame(['Alpha', 'bravo', 'Charlie'], $titles);
    }

    // -----------------------------------------------------------------------
    // SiteSettingsRepo::defaultCollectiveAdoptionFormTemplate()
    // -----------------------------------------------------------------------

    public function testDefaultTemplateIsNonEmpty(): void
    {
        self::assertNotSame('', SiteSettingsRepo::defaultCollectiveAdoptionFormTemplate());
    }

    public function testDefaultTemplateContainsRequiredTokens(): void
    {
        $tpl = SiteSettingsRepo::defaultCollectiveAdoptionFormTemplate();

        self::assertStringContainsString('{field_listing_id}', $tpl);
        self::assertStringContainsString('{field_name}',       $tpl);
        self::assertStringContainsString('{field_email}',      $tpl);
        self::assertStringContainsString('{field_url}',        $tpl);
        self::assertStringContainsString('{field_message}',    $tpl);
        self::assertStringContainsString('{captcha_block}',    $tpl);
        self::assertStringContainsString('{submit_button}',    $tpl);
        self::assertStringContainsString('{clear_button}',     $tpl);
    }

    public function testFieldListingIdIsPresent(): void
    {
        self::assertStringContainsString(
            '{field_listing_id}',
            SiteSettingsRepo::defaultCollectiveAdoptionFormTemplate(),
        );
    }

    // -----------------------------------------------------------------------
    // SiteSettingsRepo::collectiveDefaults()
    // -----------------------------------------------------------------------

    public function testCollectiveDefaultsIncludesAdoptionFormTemplate(): void
    {
        $defaults = SiteSettingsRepo::collectiveDefaults();

        self::assertArrayHasKey('collective_adoption_form_template', $defaults);
        self::assertSame(
            SiteSettingsRepo::defaultCollectiveAdoptionFormTemplate(),
            $defaults['collective_adoption_form_template'],
        );
    }

    // -----------------------------------------------------------------------
    // SiteSettingsRepo::get/set('collective_adoption_form_template')
    // -----------------------------------------------------------------------

    public function testGetReturnsNullWhenNotSet(): void
    {
        $repo = new SiteSettingsRepo(SqliteDatabase::make(self::SITE_SETTINGS_DDL));

        self::assertNull($repo->get('collective_adoption_form_template'));
    }

    public function testGetReturnsStoredValue(): void
    {
        $db   = SqliteDatabase::make(self::SITE_SETTINGS_DDL);
        $repo = new SiteSettingsRepo($db);
        $db->pdo()->exec(
            "INSERT INTO lstr_site_settings (setting_key, setting_value) VALUES ('collective_adoption_form_template', '{field_listing_id}{field_name}{captcha_block}')"
        );

        self::assertSame(
            '{field_listing_id}{field_name}{captcha_block}',
            $repo->get('collective_adoption_form_template'),
        );
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    /** @return array{0: Database, 1: ListingSettingsRepo, 2: ListingRepo} */
    private function makeRepos(): array
    {
        $db = SqliteDatabase::make(self::OWNED_AND_SETTINGS_DDL);

        return [$db, new ListingSettingsRepo($db), new ListingRepo($db, $this->makeConfig())];
    }

    private function insertListing(Database $db, string $title, int $status = 2): int
    {
        $pdo  = $db->pdo();
        $stmt = $pdo->prepare('INSERT INTO owned (title, status) VALUES (:title, :status)');
        $stmt->execute(['title' => $title, 'status' => $status]);

        return (int) $pdo->lastInsertId();
    }

    private function flagForAdoption(Database $db, int $listingId): void
    {
        $db->pdo()->exec(
            "INSERT INTO lstr_fanlisting_settings (fanlisting_id, for_adoption) VALUES ({$listingId}, 1)"
        );
    }

    private function makeConfig(): Config
    {
        $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'lstr_test_cfg_' . uniqid('', true) . '.php';
        file_put_contents($path, '<?php return ' . var_export([], true) . ';');
        $config = new Config($path);
        unlink($path);

        return $config;
    }
}
