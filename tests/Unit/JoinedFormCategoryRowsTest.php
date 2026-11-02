<?php

declare(strict_types=1);

namespace Luster\Tests\Unit;

use Luster\Models\JoinedListing;
use PHPUnit\Framework\TestCase;

/**
 * Renders templates/admin/joined/add.php and checks which category rows the
 * network/category widget starts with (its data-existing attribute).
 *
 * Regression: after removing every category row and failing validation (say,
 * an empty subject), the form brought the saved categories back, so fixing the
 * subject and saving again kept categories the owner had removed.
 */
final class JoinedFormCategoryRowsTest extends TestCase
{
    private const SAVED_JSON = '[{"network":"TFL","name":"Comics"}]';

    public function testEditPageStartsWithTheSavedCategories(): void
    {
        self::assertSame([['network' => 'TFL', 'name' => 'Comics']], $this->widgetRows($this->savedListing(), []));
    }

    public function testFailedSaveWithEveryRowRemovedComesBackWithNoCategories(): void
    {
        // Removing every row means the browser posts no cat_* arrays at all.
        $old = ['_csrf' => 'token', 'name' => '', 'url' => 'https://example.test', 'status' => 'approved'];

        self::assertSame([], $this->widgetRows($this->savedListing(), $old));
    }

    public function testFailedSaveWithOnlyABlankRowComesBackWithNoCategories(): void
    {
        $old = $this->failedPost(networks: [''], names: [''], others: ['']);

        self::assertSame([], $this->widgetRows($this->savedListing(), $old));
    }

    public function testFailedSaveKeepsTheReplacementCategories(): void
    {
        $old = $this->failedPost(
            networks: ['', 'TAFL', 'other'],
            names:    ['', 'Anime/Manga: Characters 0-M', 'Huntrix'],
            others:   ['', '', 'My Network'],
        );

        self::assertSame([
            ['network' => 'TAFL',       'name' => 'Anime/Manga: Characters 0-M'],
            ['network' => 'My Network', 'name' => 'Huntrix'],
        ], $this->widgetRows($this->savedListing(), $old));
    }

    public function testAddPageStartsEmptyAndKeepsPicksAfterAFailedSave(): void
    {
        self::assertSame([], $this->widgetRows(null, []));

        $old = $this->failedPost(networks: ['TFL'], names: ['Comics'], others: ['']);
        self::assertSame([['network' => 'TFL', 'name' => 'Comics']], $this->widgetRows(null, $old));
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    /**
     * @param array<string, mixed> $old The failed POST, or [] for a first page load
     * @return array<int, array<string, string>>
     */
    private function widgetRows(?JoinedListing $joined, array $old): array
    {
        $html = $this->render([
            'joined'          => $joined,
            'old'             => $old,
            'error'           => $old !== [] ? 'Subject is required.' : '',
            'field_errors'    => [],
            'tfl_cats'        => [['id' => 1, 'name' => 'Comics']],
            'tafl_cats'       => [['id' => 2, 'name' => 'Anime/Manga: Characters 0-M']],
            'custom_networks' => [],
            'custom_cats'     => [],
        ]);

        self::assertSame(1, preg_match("/data-existing='([^']*)'/", $html, $m), 'Widget not found in the rendered form');
        $rows = json_decode($m[1], true);
        self::assertIsArray($rows);

        return $rows;
    }

    /** @param array<string, mixed> $vars */
    private function render(array $vars): string
    {
        $vars += ['base_url' => 'https://luster.test', 'csrf_token' => 'token'];
        extract($vars);
        ob_start();
        try {
            include dirname(__DIR__, 2) . '/templates/admin/joined/add.php';
        } finally {
            $html = (string) ob_get_clean();
        }

        return $html;
    }

    /**
     * @param string[] $networks
     * @param string[] $names
     * @param string[] $others
     * @return array<string, mixed>
     */
    private function failedPost(array $networks, array $names, array $others): array
    {
        return [
            '_csrf'             => 'token',
            'name'              => '',
            'url'               => 'https://example.test',
            'status'            => 'approved',
            'cat_network'       => $networks,
            'cat_name'          => $names,
            'cat_network_other' => $others,
        ];
    }

    private function savedListing(): JoinedListing
    {
        return JoinedListing::fromRow([
            'id'              => 7,
            'name'            => 'Magic Knight Rayearth: Fuu Hououji',
            'url'             => 'https://fuu.test',
            'category'        => 'Comics',
            'categories_json' => self::SAVED_JSON,
        ]);
    }
}
