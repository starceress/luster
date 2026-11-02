<?php

declare(strict_types=1);

namespace Luster\Tests\Unit;

use Luster\Models\JoinedListing;
use Luster\Repositories\JoinedListingRepo;
use Luster\Support\JoinedCategories;
use Luster\Tests\Unit\Support\SqliteDatabase;
use PHPUnit\Framework\TestCase;

/**
 * The public joined page's category view and its dropdown count must agree.
 *
 * Regressions covered: a listing saved through the admin form vanished from
 * its "Anime/Manga: ..." category while the dropdown still counted it; a long
 * name cut short in the plain column matched a shorter category; Cafe and
 * Café matched each other in MySQL; and a listing with the same category
 * twice was counted twice.
 */
final class JoinedListingCategoryFilterTest extends TestCase
{
    private const DDL = <<<SQL
CREATE TABLE lstr_joined (
    id                INTEGER PRIMARY KEY AUTOINCREMENT,
    name              TEXT    NOT NULL DEFAULT '',
    url               TEXT    NOT NULL DEFAULT '',
    image             TEXT    NULL,
    category          TEXT    NOT NULL DEFAULT '',
    categories_json   TEXT    NULL,
    date_joined       TEXT    NULL,
    active            INTEGER NOT NULL DEFAULT 1,
    status            TEXT    NOT NULL DEFAULT 'approved',
    created_at        TEXT    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    link_status       TEXT    NOT NULL DEFAULT 'unchecked',
    link_checked_at   TEXT    NULL,
    link_redirect_url TEXT    NULL,
    description       TEXT    NULL,
    comments          TEXT    NULL
)
SQL;

    private const TAFL_CHARACTERS = 'Anime/Manga: Characters 0-M';

    private JoinedListingRepo $repo;

    protected function setUp(): void
    {
        $this->repo = new JoinedListingRepo(SqliteDatabase::make(self::DDL));
    }

    // -----------------------------------------------------------------------
    // Original bug: slash categories
    // -----------------------------------------------------------------------

    public function testListingSavedByTheFormShowsInItsSlashCategory(): void
    {
        $this->insertViaForm('Fuu', [['TAFL', self::TAFL_CHARACTERS]]);

        self::assertSame(['Fuu'], $this->namesInCategory(self::TAFL_CHARACTERS));
    }

    public function testOldEscapedJsonIsFoundBeforeMigrationsRun(): void
    {
        // Saved by the old admin form: empty plain category, "\/" in the JSON.
        $this->insertRaw('Fuu', '', json_encode([['network' => 'TAFL', 'name' => self::TAFL_CHARACTERS]]));

        self::assertSame(['Fuu'], $this->namesInCategory(self::TAFL_CHARACTERS));
        $this->assertCountsMatchCategoryViews();
    }

    public function testSecondaryJsonCategoriesAreFound(): void
    {
        $this->insertRaw('Fuu', 'Comics', JoinedCategories::encode([
            ['network' => 'TFL',  'name' => 'Comics'],
            ['network' => 'TAFL', 'name' => self::TAFL_CHARACTERS],
            ['network' => 'TFL',  'name' => 'Characters: Book/Movie'],
        ]));

        self::assertSame(['Fuu'], $this->namesInCategory(self::TAFL_CHARACTERS));
        self::assertSame(['Fuu'], $this->namesInCategory('Characters: Book/Movie'));
        $this->assertCountsMatchCategoryViews();
    }

    public function testRowsWithOnlyAPlainCategoryAreFound(): void
    {
        $this->insertRaw('Legacy', 'Music', null);
        $this->insertRaw('Empty list', 'Music', '[]');

        self::assertSame(['Empty list', 'Legacy'], $this->namesInCategory('Music'));
        $this->assertCountsMatchCategoryViews();
    }

    // -----------------------------------------------------------------------
    // Review finding 1: a shortened plain category must not match another one
    // -----------------------------------------------------------------------

    public function testLongNamesCutShortDoNotMatchAShorterCategory(): void
    {
        $short = str_repeat('A', 100);
        $long  = $short . 'B';
        $this->insertViaForm('Short', [['Custom', $short]]);
        $this->insertViaForm('Long',  [['Custom', $long]]);

        self::assertSame(['Short'], $this->namesInCategory($short));
        self::assertSame(['Long'], $this->namesInCategory($long));
        self::assertSame(1, $this->repo->countAllByStatusTab(filterCat: $short)['all']);
        self::assertSame(1, $this->repo->search('', 'Custom', perPage: 10, page: 1, filterCat: $short)['total']);
        $this->assertCountsMatchCategoryViews();
    }

    // -----------------------------------------------------------------------
    // Review finding 2: names are compared exactly, accents and case included
    // -----------------------------------------------------------------------

    public function testAccentAndCaseVariantsAreSeparateCategories(): void
    {
        $this->insertViaForm('Plain', [['Custom', 'Cafe']]);
        $this->insertViaForm('Accent', [['Custom', 'Café']]);
        $this->insertViaForm('Lower', [['TFL', 'Comics'], ['Custom', 'cafe']]);

        self::assertSame(['Plain'], $this->namesInCategory('Cafe'));
        self::assertSame(['Accent'], $this->namesInCategory('Café'));
        self::assertSame(['Lower'], $this->namesInCategory('cafe'));
        $this->assertCountsMatchCategoryViews();
    }

    // -----------------------------------------------------------------------
    // Review finding 5: a repeated name counts the listing once
    // -----------------------------------------------------------------------

    public function testTheSameNameTwiceInOneListingCountsOnce(): void
    {
        $this->insertRaw('Twice', '', JoinedCategories::encode([
            ['network' => 'TFL',       'name' => 'Shared'],
            ['network' => 'My Network', 'name' => 'Shared'],
        ]));
        $this->insertViaForm('Other listing', [['TFL', 'Shared']]);
        $this->insertRaw('Hidden', 'Shared', null, active: false);

        self::assertSame(2, $this->repo->getCategoryCountMap()['Shared']);
        self::assertSame(3, $this->repo->getCategoryCountMap(false)['Shared']);
        self::assertSame(['Other listing', 'Twice'], $this->namesInCategory('Shared'));
        $this->assertCountsMatchCategoryViews();
    }

    // -----------------------------------------------------------------------
    // Literal characters
    // -----------------------------------------------------------------------

    public function testWildcardsQuotesAndBackslashesMatchOnlyThemselves(): void
    {
        // Plain category deliberately empty, so only the JSON can match.
        $this->insertRaw('Underscore', '', JoinedCategories::encode([['network' => 'Custom', 'name' => 'Games_Series']]));
        $this->insertRaw('Letter', '', JoinedCategories::encode([['network' => 'Custom', 'name' => 'GamesXSeries']]));
        $this->insertRaw('Percent', '', JoinedCategories::encode([['network' => 'Custom', 'name' => '100% Fan']]));
        $this->insertRaw('Quoted', '', JoinedCategories::encode([['network' => 'Custom', 'name' => 'Pokémon: "Team Rocket"']]));
        $this->insertRaw('Unquoted', '', JoinedCategories::encode([['network' => 'Custom', 'name' => 'Pokémon: Team Rocket']]));
        $this->insertRaw('Backslash', '', JoinedCategories::encode([['network' => 'Custom', 'name' => 'Back\\slash']]));

        self::assertSame(['Underscore'], $this->namesInCategory('Games_Series'));
        self::assertSame(['Percent'], $this->namesInCategory('100% Fan'));
        self::assertSame([], $this->namesInCategory('%'));
        self::assertSame(['Quoted'], $this->namesInCategory('Pokémon: "Team Rocket"'));
        self::assertSame(['Backslash'], $this->namesInCategory('Back\\slash'));
        $this->assertCountsMatchCategoryViews();
    }

    // -----------------------------------------------------------------------
    // Other filters combined with the category filter
    // -----------------------------------------------------------------------

    public function testStatusTabCountsAndSearchUseTheSameCategoryRule(): void
    {
        $this->insertRaw('Fuu', '', JoinedCategories::encode([['network' => 'TAFL', 'name' => self::TAFL_CHARACTERS]]));
        $this->insertRaw('Umi', '', JoinedCategories::encode([['network' => 'TAFL', 'name' => self::TAFL_CHARACTERS]]), status: 'pending');
        $this->insertViaForm('Elsewhere', [['TFL', 'Comics']]);

        $tabs = $this->repo->countAllByStatusTab(filterCat: self::TAFL_CHARACTERS);
        self::assertSame(2, $tabs['all']);
        self::assertSame(1, $tabs['pending']);

        self::assertSame(1, $this->repo->search('Fuu', 'TAFL', perPage: 10, page: 1, filterCat: self::TAFL_CHARACTERS)['total']);
        self::assertSame(1, $this->repo->search('Fuu', '', perPage: 10, page: 1, filterCat: self::TAFL_CHARACTERS)['total']);
        self::assertSame(1, $this->repo->getAll(perPage: 10, page: 1, filterCat: self::TAFL_CHARACTERS, statusTab: 'pending')['total']);
    }

    public function testACategoryNobodyIsInReturnsNothing(): void
    {
        $this->insertViaForm('Fuu', [['TAFL', self::TAFL_CHARACTERS]]);

        $result = $this->repo->getAll(perPage: 10, page: 1, filterCat: 'Nobody here');

        self::assertSame([], $result['items']);
        self::assertSame(0, $result['total']);
        self::assertSame(0, $this->repo->countAllByStatusTab(filterCat: 'Nobody here')['all']);
    }

    public function testPaginationWorksInsideACategory(): void
    {
        foreach (['A', 'B', 'C'] as $name) {
            $this->insertViaForm($name, [['TAFL', self::TAFL_CHARACTERS]]);
        }
        $this->insertViaForm('Elsewhere', [['TFL', 'Comics']]);

        $page2 = $this->repo->getAll(perPage: 2, page: 2, filterCat: self::TAFL_CHARACTERS);

        self::assertSame(3, $page2['total']);
        self::assertSame(2, $page2['pages']);
        self::assertSame(['C'], array_map(static fn(JoinedListing $j): string => $j->name, $page2['items']));
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    /**
     * Inserts a row the way the admin form saves it.
     *
     * @param array<int, array{0: string, 1: string}> $categories [network, name] pairs
     */
    private function insertViaForm(string $name, array $categories): void
    {
        $entries = [];
        foreach ($categories as [$network, $catName]) {
            $entries[] = ['network' => $network, 'name' => $catName];
        }
        $json = JoinedCategories::encode($entries);

        $this->insertRaw($name, JoinedCategories::primaryFor($json), $json);
    }

    private function insertRaw(string $name, string $category, ?string $json, bool $active = true, string $status = 'approved'): void
    {
        static $n = 0;
        $n++;

        $this->repo->insert([
            'name'            => $name,
            'url'             => "https://listing{$n}.test",
            'category'        => $category,
            'categories_json' => $json,
            'active'          => $active,
            'status'          => $status,
        ]);
    }

    /** @return string[] Listing names in the category, as the public page shows it */
    private function namesInCategory(string $category): array
    {
        $result = $this->repo->getAll(perPage: 9999, page: 1, filterCat: $category, statusTab: 'visible');
        $names  = array_map(static fn(JoinedListing $j): string => $j->name, $result['items']);
        sort($names);

        return $names;
    }

    /** Every dropdown count must equal the number of listings its category page shows. */
    private function assertCountsMatchCategoryViews(): void
    {
        foreach ($this->repo->getCategoryCountMap() as $category => $count) {
            self::assertCount($count, $this->namesInCategory((string) $category), "Category '{$category}'");
        }
    }
}
