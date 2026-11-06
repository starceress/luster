<?php

declare(strict_types=1);

namespace Luster\Tests\Unit;

use Luster\Repositories\CategoryRepo;
use Luster\Tests\Unit\Support\SqliteDatabase;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Custom is a real network: it's what Add Category uses by default and what the
 * importers save. The pickers used to leave it out, so a custom category could be
 * created and never chosen.
 *
 * The forms say "Custom", "TFL" and "TAFL", and the table stores them in lowercase.
 * SQLite compares text exactly, so these tests also hold on a table that isn't
 * case-insensitive the way MySQL's usually is.
 */
final class CategoryCustomNetworkTest extends TestCase
{
    private const DDL = "
        CREATE TABLE lstr_categories (
            id         INTEGER PRIMARY KEY AUTOINCREMENT,
            name       TEXT    NOT NULL,
            slug       TEXT    NOT NULL DEFAULT '',
            parent_id  INTEGER NULL,
            source     TEXT    NOT NULL DEFAULT 'custom',
            created_at TEXT    NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE (source, name),
            UNIQUE (source, slug)
        )
    ";

    private CategoryRepo $repo;
    private PDO $pdo;

    protected function setUp(): void
    {
        $db         = SqliteDatabase::make(self::DDL);
        $this->pdo  = $db->pdo();
        $this->repo = new CategoryRepo($db);
    }

    private function sources(): array
    {
        return $this->pdo->query('SELECT source FROM lstr_categories ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
    }

    public function testThePickersOfferCustomOnceThereIsACustomCategory(): void
    {
        $this->repo->add('Games: Characters 0-M', 'custom');

        self::assertSame(['Custom'], $this->repo->getCustomNetworks());
    }

    public function testThePickersDontOfferCustomWithNothingInIt(): void
    {
        $this->repo->add('Comics', 'tfl');

        self::assertSame([], $this->repo->getCustomNetworks());
    }

    public function testCustomSitsBesideTheOtherNetworksAndTheBuiltInOnesStayOut(): void
    {
        $this->repo->add('Comics', 'tfl');
        $this->repo->add('Anime', 'tafl');
        $this->repo->add('Games', 'custom');
        $this->repo->add('Ships', 'my network');

        self::assertEqualsCanonicalizing(['Custom', 'my network'], $this->repo->getCustomNetworks());
    }

    public function testASourceSavedWithACapitalIsStillOneCustomChoice(): void
    {
        $this->repo->add('Games', 'custom');
        $this->repo->add('Ships', 'Custom');

        self::assertSame(['Custom'], $this->repo->getCustomNetworks());
    }

    public function testTheCategoriesPageFilterGetsTheStoredValues(): void
    {
        $this->repo->add('Comics', 'tfl');
        $this->repo->add('Games', 'custom');
        $this->repo->add('Ships', 'my network');

        self::assertEqualsCanonicalizing(['custom', 'my network'], $this->repo->getCustomSources());
    }

    public function testTheCustomLabelFindsTheCategoriesStoredInLowercase(): void
    {
        $id = $this->repo->add('Games: Characters 0-M', 'custom');

        self::assertSame([['id' => $id, 'name' => 'Games: Characters 0-M']], $this->repo->getBySource('Custom'));
    }

    public function testPickingACustomCategoryUsesTheOneThatsThere(): void
    {
        $id = $this->repo->add('Games: Characters 0-M', 'custom');

        self::assertSame($id, $this->repo->findOrCreate('Custom', 'Games: Characters 0-M'));
        self::assertSame(['custom'], $this->sources());
    }

    public function testLookingUpACategoryUnderTheCustomLabelFindsIt(): void
    {
        $id = $this->repo->add('Games: Characters 0-M', 'custom');

        self::assertSame($id, $this->repo->findBySourceAndName('Custom', 'Games: Characters 0-M')?->id);
    }

    public function testANewCategoryNamedUnderCustomIsStoredInLowercase(): void
    {
        $this->repo->findOrCreate('Custom', 'Brand New');
        $this->repo->findOrCreate('CUSTOM', 'Also New');

        self::assertSame(['custom', 'custom'], $this->sources());
        self::assertCount(2, $this->repo->getBySource('Custom'));
    }

    public function testTheBuiltInNetworksAreStoredInLowercaseToo(): void
    {
        $tfl  = $this->repo->add('Comics', 'tfl');
        $tafl = $this->repo->add('Anime', 'tafl');

        self::assertSame($tfl, $this->repo->findOrCreate('TFL', 'Comics'));
        self::assertSame($tafl, $this->repo->findOrCreate('TAFL', 'Anime'));
        self::assertSame(['tfl', 'tafl'], $this->sources());
    }

    public function testAnyOtherNetworkKeepsTheNameItWasGiven(): void
    {
        $this->repo->findOrCreate('My Network', 'Ships');

        self::assertSame(['My Network'], $this->sources());
        self::assertSame(['My Network'], $this->repo->getCustomNetworks());
    }

    public function testAddingACategoryCantMakeACustomNetworkOfItsOwn(): void
    {
        self::assertFalse(
            method_exists($this->repo, 'addCustomNetwork'),
            'It saved the network name as a category under Custom, which then showed up as a choice',
        );
    }
}
