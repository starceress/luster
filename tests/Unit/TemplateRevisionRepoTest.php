<?php

declare(strict_types=1);

namespace Luster\Tests\Unit;

use Luster\Repositories\TemplateRevisionRepo;
use Luster\Tests\Unit\Support\SqliteDatabase;
use PHPUnit\Framework\TestCase;

final class TemplateRevisionRepoTest extends TestCase
{
    private const DDL = <<<SQL
CREATE TABLE lstr_template_revisions (
    id                INTEGER PRIMARY KEY AUTOINCREMENT,
    fanlisting_id     INTEGER NOT NULL,
    template_key      TEXT    NOT NULL,
    value             TEXT    NULL,
    saved_at          TEXT    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    saved_by_admin_id INTEGER NULL
)
SQL;

    // --- scope isolation

    public function testGlobalDefaultsScopeIsIsolatedFromCollectiveScope(): void
    {
        $db   = SqliteDatabase::make(self::DDL);
        $repo = new TemplateRevisionRepo($db);

        $repo->record(TemplateRevisionRepo::COLLECTIVE_ID, 'owned_template', 'collective old value', 1);
        $repo->record(TemplateRevisionRepo::GLOBAL_DEFAULTS_ID, 'listing_default_members_template', 'global old value', 1);

        $collectiveHistory = $repo->getHistory(TemplateRevisionRepo::COLLECTIVE_ID, 'owned_template');
        $globalHistory     = $repo->getHistory(TemplateRevisionRepo::GLOBAL_DEFAULTS_ID, 'listing_default_members_template');

        self::assertCount(1, $collectiveHistory);
        self::assertSame('collective old value', $collectiveHistory[0]['value']);

        self::assertCount(1, $globalHistory);
        self::assertSame('global old value', $globalHistory[0]['value']);
    }

    public function testGlobalDefaultsScopeIsIsolatedFromARealListingId(): void
    {
        $db   = SqliteDatabase::make(self::DDL);
        $repo = new TemplateRevisionRepo($db);

        $repo->record(1, 'members_template', 'listing #1 old value', 1);
        $repo->record(TemplateRevisionRepo::GLOBAL_DEFAULTS_ID, 'listing_default_members_template', 'global old value', 1);

        self::assertCount(1, $repo->getHistory(1, 'members_template'));
        self::assertCount(1, $repo->getHistory(TemplateRevisionRepo::GLOBAL_DEFAULTS_ID, 'listing_default_members_template'));
    }

    // --- recordChanged only records actual changes

    public function testRecordChangedOnlyRecordsKeysThatActuallyChanged(): void
    {
        $db   = SqliteDatabase::make(self::DDL);
        $repo = new TemplateRevisionRepo($db);

        $old = ['listing_default_members_template' => 'same', 'listing_default_stats_template' => 'old stats'];
        $new = ['listing_default_members_template' => 'same', 'listing_default_stats_template' => 'new stats'];

        $repo->recordChanged(TemplateRevisionRepo::GLOBAL_DEFAULTS_ID, $old, $new, 1);

        self::assertCount(0, $repo->getHistory(TemplateRevisionRepo::GLOBAL_DEFAULTS_ID, 'listing_default_members_template'));
        self::assertCount(1, $repo->getHistory(TemplateRevisionRepo::GLOBAL_DEFAULTS_ID, 'listing_default_stats_template'));
    }

    // --- getById / deleteByKey scoping

    public function testGetByIdReturnsTheCorrectFanlistingIdForGlobalDefaults(): void
    {
        $db   = SqliteDatabase::make(self::DDL);
        $repo = new TemplateRevisionRepo($db);

        $repo->record(TemplateRevisionRepo::GLOBAL_DEFAULTS_ID, 'listing_default_members_template', 'old value', 1);
        $history = $repo->getHistory(TemplateRevisionRepo::GLOBAL_DEFAULTS_ID, 'listing_default_members_template');
        $id      = $history[0]['id'];

        $revision = $repo->getById($id);

        self::assertNotNull($revision);
        self::assertSame(TemplateRevisionRepo::GLOBAL_DEFAULTS_ID, $revision['fanlisting_id']);
        self::assertSame('old value', $revision['value']);
    }

    public function testDeleteByKeyOnlyClearsGlobalDefaultsScope(): void
    {
        $db   = SqliteDatabase::make(self::DDL);
        $repo = new TemplateRevisionRepo($db);

        $repo->record(TemplateRevisionRepo::COLLECTIVE_ID, 'listing_default_members_template', 'collective value', 1);
        $repo->record(TemplateRevisionRepo::GLOBAL_DEFAULTS_ID, 'listing_default_members_template', 'global value', 1);

        $repo->deleteByKey(TemplateRevisionRepo::GLOBAL_DEFAULTS_ID, 'listing_default_members_template');

        self::assertCount(0, $repo->getHistory(TemplateRevisionRepo::GLOBAL_DEFAULTS_ID, 'listing_default_members_template'));
        self::assertCount(1, $repo->getHistory(TemplateRevisionRepo::COLLECTIVE_ID, 'listing_default_members_template'));
    }
}
