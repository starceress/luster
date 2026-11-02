<?php

declare(strict_types=1);

namespace Luster\Tests\Unit;

use Luster\Models\JoinedListing;
use Luster\Support\JoinedCategories;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Tests for JoinedCategories: how joined listings store their categories,
 * which categories a listing is in, how the admin form's category rows are
 * paired up, and the repair Migration 107 runs.
 */
final class JoinedCategoriesTest extends TestCase
{
    private const TAFL_CHARACTERS = 'Anime/Manga: Characters 0-M';

    // -----------------------------------------------------------------------
    // encode
    // -----------------------------------------------------------------------

    public function testEncodeKeepsSlashesAndAccentedLettersAsTyped(): void
    {
        $json = JoinedCategories::encode([['network' => 'TAFL', 'name' => 'Anime/Manga: Pokémon']]);

        self::assertSame('[{"network":"TAFL","name":"Anime/Manga: Pokémon"}]', $json);
    }

    public function testEncodeReturnsNullForNoEntries(): void
    {
        self::assertNull(JoinedCategories::encode([]));
    }

    // -----------------------------------------------------------------------
    // names
    // -----------------------------------------------------------------------

    public function testNamesComeFromJsonEachOnceAndInOrder(): void
    {
        $json = JoinedCategories::encode([
            ['network' => 'TAFL',   'name' => self::TAFL_CHARACTERS],
            ['network' => 'TFL',    'name' => 'Comics'],
            ['network' => 'Custom', 'name' => 'Comics'],
            ['network' => 'TFL',    'name' => ''],
        ]);

        self::assertSame([self::TAFL_CHARACTERS, 'Comics'], JoinedCategories::names($json, 'Ignored'));
    }

    public function testNamesReadOldEscapedJson(): void
    {
        // How the importers and the admin form used to save it: "\/" and accent escape codes.
        $json = json_encode([['network' => 'Custom', 'name' => 'Pokémon/Digimon']]);
        self::assertStringContainsString('\/', (string) $json);

        self::assertSame(['Pokémon/Digimon'], JoinedCategories::names($json, ''));
    }

    public function testNamesFallBackToThePlainCategoryWhenJsonHasNone(): void
    {
        self::assertSame(['Music'], JoinedCategories::names(null, 'Music'));
        self::assertSame(['Music'], JoinedCategories::names('', 'Music'));
        self::assertSame(['Music'], JoinedCategories::names('[]', 'Music'));
        self::assertSame(['Music'], JoinedCategories::names('not json', 'Music'));
        self::assertSame([], JoinedCategories::names(null, ''));
    }

    public function testNamesAreComparedExactly(): void
    {
        $json = JoinedCategories::encode([
            ['network' => 'Custom', 'name' => 'Cafe'],
            ['network' => 'Custom', 'name' => 'Café'],
            ['network' => 'Custom', 'name' => 'cafe'],
        ]);

        self::assertSame(['Cafe', 'Café', 'cafe'], JoinedCategories::names($json, ''));
    }

    // -----------------------------------------------------------------------
    // primaryFor
    // -----------------------------------------------------------------------

    public function testPrimaryIsTheFirstNamedEntry(): void
    {
        $json = JoinedCategories::encode([
            ['network' => 'TAFL', 'name' => ''],
            ['network' => 'TAFL', 'name' => self::TAFL_CHARACTERS],
            ['network' => 'TFL',  'name' => 'Comics'],
        ]);

        self::assertSame(self::TAFL_CHARACTERS, JoinedCategories::primaryFor($json));
    }

    public function testPrimaryIsEmptyWithoutCategoriesOnAdd(): void
    {
        self::assertSame('', JoinedCategories::primaryFor(null));
        self::assertSame('', JoinedCategories::primaryFor('not json'));
    }

    public function testEditKeepsAPlainCategoryTheFormHadNoRowFor(): void
    {
        // Older rows can have a plain category but no categories_json. The edit
        // form shows no category row for them, so saving must not wipe it.
        $existing = $this->listing(category: 'Comics', categoriesJson: null);

        self::assertSame('Comics', JoinedCategories::primaryFor(null, $existing));
    }

    public function testEditClearsTheCategoryWhenTheOwnerRemovesEveryRow(): void
    {
        $existing = $this->listing(
            category: 'Comics',
            categoriesJson: JoinedCategories::encode([['network' => 'TFL', 'name' => 'Comics']]),
        );

        self::assertSame('', JoinedCategories::primaryFor(null, $existing));
    }

    public function testEditReplacesThePlainCategoryWithTheNewFirstRow(): void
    {
        $existing = $this->listing(category: 'Comics', categoriesJson: null);
        $json     = JoinedCategories::encode([['network' => 'TAFL', 'name' => self::TAFL_CHARACTERS]]);

        self::assertSame(self::TAFL_CHARACTERS, JoinedCategories::primaryFor($json, $existing));
    }

    public function testPrimaryIsCutToTheColumnLength(): void
    {
        $json = JoinedCategories::encode([['network' => 'Custom', 'name' => str_repeat('é', 120)]]);

        self::assertSame(str_repeat('é', 100), JoinedCategories::primaryFor($json));
    }

    // -----------------------------------------------------------------------
    // networkLabel
    // -----------------------------------------------------------------------

    public function testNetworkLabelMatchesTheFormsLabels(): void
    {
        self::assertSame('TFL', JoinedCategories::networkLabel('tfl'));
        self::assertSame('TFL', JoinedCategories::networkLabel(' TFL '));
        self::assertSame('TAFL', JoinedCategories::networkLabel('tafl'));
        self::assertSame('Custom', JoinedCategories::networkLabel('custom'));
        self::assertSame('Fanlistings.org', JoinedCategories::networkLabel('Fanlistings.org'));
    }

    // -----------------------------------------------------------------------
    // fromFormRows
    // -----------------------------------------------------------------------

    public function testBlankRowsBeforeBetweenAndAfterDontMoveCategories(): void
    {
        // admin.js makes every row send one value for each array, blank or not.
        $rows = JoinedCategories::fromFormRows(
            ['', 'TFL', '', 'TAFL', ''],
            ['', 'Comics', '', self::TAFL_CHARACTERS, ''],
            ['', '', '', '', ''],
        );

        self::assertSame([
            ['network' => 'TFL',  'name' => 'Comics'],
            ['network' => 'TAFL', 'name' => self::TAFL_CHARACTERS],
        ], $rows['entries']);
        self::assertSame([], $rows['new_networks']);
    }

    public function testOtherRowsUseTheTypedNetworkName(): void
    {
        $rows = JoinedCategories::fromFormRows(
            ['other', 'other', 'other', 'TFL'],
            ['Huntrix', '', 'Ignored', 'Comics'],
            [' My Network ', 'Second Network', '', 'leftover text'],
        );

        self::assertSame([
            ['network' => 'My Network', 'name' => 'Huntrix'],
            ['network' => 'TFL',        'name' => 'Comics'],
        ], $rows['entries']);
        // A typed network is still added to the list when its row had no category name.
        self::assertSame(['My Network', 'Second Network'], $rows['new_networks']);
    }

    public function testTheSamePairPickedTwiceIsKeptOnce(): void
    {
        $rows = JoinedCategories::fromFormRows(
            ['TFL', 'TFL', 'Custom'],
            ['Comics', 'Comics', 'Comics'],
            ['', '', ''],
        );

        self::assertSame([
            ['network' => 'TFL',    'name' => 'Comics'],
            ['network' => 'Custom', 'name' => 'Comics'],
        ], $rows['entries']);
    }

    public function testNonTextFormValuesAreIgnored(): void
    {
        $rows = JoinedCategories::fromFormRows([['TFL'], 'TFL'], ['Comics', ['x']], ['', '']);

        self::assertSame([], $rows['entries']);
    }

    // -----------------------------------------------------------------------
    // repairStoredRows (Migration 107)
    // -----------------------------------------------------------------------

    public function testRepairFixesOldRowsAndLeavesTheRestAlone(): void
    {
        $pdo = $this->joinedTable();
        $ids = $this->seedRowsNeedingRepair($pdo);

        $result = JoinedCategories::repairStoredRows($pdo);

        // Filled: fuu, fanatically, long. Rewritten: fuu, imported, fanatically.
        self::assertSame(['categories_filled' => 3, 'json_rewritten' => 3], $result);

        $rows = $pdo->query('SELECT id, category, categories_json FROM lstr_joined')
            ->fetchAll(PDO::FETCH_UNIQUE | PDO::FETCH_ASSOC);

        // Saved in the admin: escaped slash, empty plain category.
        self::assertSame(self::TAFL_CHARACTERS, $rows[$ids['fuu']]['category']);
        self::assertSame('[{"network":"TAFL","name":"Anime/Manga: Characters 0-M"}]', $rows[$ids['fuu']]['categories_json']);

        // Imported: plain category kept, accented secondary category unescaped.
        self::assertSame('Comics', $rows[$ids['imported']]['category']);
        self::assertSame(
            '[{"network":"TFL","name":"Comics"},{"network":"Custom","name":"Pokémon/Digimon"}]',
            $rows[$ids['imported']]['categories_json'],
        );

        // Fanatically: lowercase networks get the form's labels.
        self::assertSame(
            '[{"name":"Comics","network":"TFL"},{"name":"Anime/Manga: Series","network":"TAFL"},{"name":"Fan Art","network":"Custom"}]',
            $rows[$ids['fanatically']]['categories_json'],
        );
        self::assertSame('Comics', $rows[$ids['fanatically']]['category']);

        // Long name: plain copy cut to the column, JSON keeps the full name.
        self::assertSame(str_repeat('x', 100), $rows[$ids['long']]['category']);
        self::assertStringContainsString(str_repeat('x', 130), $rows[$ids['long']]['categories_json']);

        // Left alone.
        self::assertSame('[{"network":', $rows[$ids['broken']]['categories_json']);
        self::assertSame('', $rows[$ids['broken']]['category']);
        self::assertNull($rows[$ids['plain']]['categories_json']);
        self::assertSame('Music', $rows[$ids['plain']]['category']);
        self::assertSame('[{"network":"TFL","name":"Comics"}]', $rows[$ids['tidy']]['categories_json']);
    }

    public function testRepairRunTwiceChangesNothingTheSecondTime(): void
    {
        $pdo = $this->joinedTable();
        $this->seedRowsNeedingRepair($pdo);

        JoinedCategories::repairStoredRows($pdo);
        $after = $pdo->query('SELECT * FROM lstr_joined ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);

        self::assertSame(['categories_filled' => 0, 'json_rewritten' => 0], JoinedCategories::repairStoredRows($pdo));
        self::assertSame($after, $pdo->query('SELECT * FROM lstr_joined ORDER BY id')->fetchAll(PDO::FETCH_ASSOC));
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    private function joinedTable(): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec(
            "CREATE TABLE lstr_joined (
                id              INTEGER PRIMARY KEY AUTOINCREMENT,
                name            TEXT NOT NULL DEFAULT '',
                category        TEXT NOT NULL DEFAULT '',
                categories_json TEXT NULL
            )"
        );

        return $pdo;
    }

    /**
     * Rows the way older code saved them. JSON with "\/" and accent escape
     * codes is built with json_encode()'s default flags, like that code did.
     *
     * @return array<string, int> label => row id
     */
    private function seedRowsNeedingRepair(PDO $pdo): array
    {
        $rows = [
            'fuu' => ['', json_encode([['network' => 'TAFL', 'name' => self::TAFL_CHARACTERS]])],
            'imported' => ['Comics', json_encode([
                ['network' => 'TFL',    'name' => 'Comics'],
                ['network' => 'Custom', 'name' => 'Pokémon/Digimon'],
            ])],
            'fanatically' => ['', json_encode([
                ['name' => 'Comics',              'network' => 'tfl'],
                ['name' => 'Anime/Manga: Series', 'network' => 'tafl'],
                ['name' => 'Fan Art',             'network' => 'custom'],
            ], JSON_UNESCAPED_UNICODE)],
            'long'   => ['', JoinedCategories::encode([['network' => 'Custom', 'name' => str_repeat('x', 130)]])],
            'broken' => ['', '[{"network":'],
            'plain'  => ['Music', null],
            'tidy'   => ['Comics', JoinedCategories::encode([['network' => 'TFL', 'name' => 'Comics']])],
        ];

        $insert = $pdo->prepare('INSERT INTO lstr_joined (name, category, categories_json) VALUES (?, ?, ?)');
        $ids    = [];
        foreach ($rows as $label => [$category, $json]) {
            $insert->execute([$label, $category, $json]);
            $ids[$label] = (int) $pdo->lastInsertId();
        }

        return $ids;
    }

    private function listing(string $category, ?string $categoriesJson): JoinedListing
    {
        return JoinedListing::fromRow([
            'id'              => 1,
            'name'            => 'Existing',
            'url'             => 'https://existing.test',
            'category'        => $category,
            'categories_json' => $categoriesJson,
        ]);
    }
}
