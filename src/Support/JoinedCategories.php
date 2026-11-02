<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 The one place that knows how a joined listing's categories are stored in
 lstr_joined: how they're written (admin form and importers), which
 categories a listing counts as being in (public dropdown, category pages
 and admin filter), and how Migration 107 repairs rows saved the old way.
*/

declare(strict_types=1);

namespace Luster\Support;

use Luster\Models\JoinedListing;
use PDO;

/**
 * How lstr_joined stores categories.
 *
 * A joined listing keeps its categories in two columns:
 *   - categories_json: every network/name pair, for example
 *     [{"network":"TAFL","name":"Anime/Manga: Characters 0-M"}]
 *   - category: a plain copy of the first name, cut to the column's 100
 *     characters. Used for sorting, for the "All" view's grouping, and as the
 *     only category of rows saved before categories_json existed.
 *
 * names() decides which categories a listing is in, here in PHP, and both the
 * dropdown counts and the category pages use it. Matching in SQL instead let
 * those two disagree: MySQL's text comparison ignores accents and case (Cafe
 * matched Café), and a name cut short in the plain column matched a
 * different, shorter category.
 *
 * Depends on nothing but PDO, because public/migrate/index.php loads this file
 * by itself (it has no autoloader) for Migration 107.
 */
final class JoinedCategories
{
    /**
     * json_encode() flags for categories_json. By default json_encode() writes
     * "/" as "\/" and turns accented letters into backslash escape codes, so
     * names wouldn't be stored the way they were typed. These keep them as-is.
     */
    public const JSON_FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE;

    /** lstr_joined.category is VARCHAR(100). */
    public const PLAIN_COLUMN_LENGTH = 100;

    /**
     * Encodes ['network' => ..., 'name' => ...] entries for categories_json, or
     * returns null when there are none. The admin form and every importer save
     * categories through this.
     *
     * @param array<int, array<string, string>> $entries
     */
    public static function encode(array $entries): ?string
    {
        if ($entries === []) {
            return null;
        }

        $json = json_encode(array_values($entries), self::JSON_FLAGS);

        return $json !== false ? $json : null;
    }

    /**
     * The category names a listing is in, each listed once. These are the
     * categories_json names when it has any; otherwise the plain category
     * column (rows saved before categories_json existed). Names are compared
     * exactly, so "Cafe" and "Café" stay two different categories.
     *
     * @return string[]
     */
    public static function names(?string $categoriesJson, string $plainCategory): array
    {
        $names = self::jsonNames($categoriesJson);
        if ($names === [] && $plainCategory !== '') {
            $names = [$plainCategory];
        }

        return $names;
    }

    /**
     * Returns the plain category value to save next to $categoriesJson: the name
     * of its first category, cut to fit the column.
     *
     * When editing ($existing given), a listing that has no categories_json
     * names keeps its old plain category if the form also sends none. The edit
     * form only shows categories_json entries, so the owner never saw that old
     * value and can't have meant to remove it.
     */
    public static function primaryFor(?string $categoriesJson, ?JoinedListing $existing = null): string
    {
        $category = self::jsonNames($categoriesJson)[0] ?? '';

        if ($category === '' && $existing !== null && self::jsonNames($existing->categoriesJson) === []) {
            $category = $existing->category;
        }

        return mb_substr($category, 0, self::PLAIN_COLUMN_LENGTH);
    }

    /**
     * Network label as the admin form uses it. Category sources are stored in
     * lowercase ('tfl', 'tafl', 'custom'), but the form's network list says
     * "TFL" and "TAFL", and the Listing Admin and Enthusiast importers say
     * "Custom". Any other network name is kept as it is.
     */
    public static function networkLabel(string $network): string
    {
        $network = trim($network);

        return match (strtolower($network)) {
            'tfl'    => 'TFL',
            'tafl'   => 'TAFL',
            'custom' => 'Custom',
            default  => $network,
        };
    }

    /**
     * Turns the joined form's parallel cat_network[] / cat_name[] /
     * cat_network_other[] POST arrays into category entries. Row N of each
     * array belongs together (admin.js makes every row send one value for
     * each). Rows missing a network or a name are skipped, the same pair
     * picked twice is kept once, and "other" rows use the typed network name.
     *
     * new_networks lists the typed "other" network names, so the caller can
     * add them to the network list even when the row had no category name.
     *
     * @param array<mixed> $networks
     * @param array<mixed> $names
     * @param array<mixed> $others
     * @return array{entries: array<int, array{network: string, name: string}>, new_networks: string[]}
     */
    public static function fromFormRows(array $networks, array $names, array $others): array
    {
        $networks = array_values($networks);
        $names    = array_values($names);
        $others   = array_values($others);

        $entries     = [];
        $newNetworks = [];
        $seen        = [];

        foreach ($networks as $i => $rawNetwork) {
            $network = is_string($rawNetwork) ? trim($rawNetwork) : '';
            $name    = is_string($names[$i] ?? null) ? trim($names[$i]) : '';

            if ($network === 'other') {
                $network = is_string($others[$i] ?? null) ? trim($others[$i]) : '';
                if ($network === '') {
                    continue;
                }
                $newNetworks[] = $network;
            }

            if ($network === '' || $name === '') {
                continue;
            }

            $key = $network . "\0" . $name;
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;

            $entries[] = ['network' => $network, 'name' => $name];
        }

        return ['entries' => $entries, 'new_networks' => array_values(array_unique($newNetworks))];
    }

    /**
     * Migration 107's repair for rows saved before this format was settled:
     * rewrites categories_json with plain slashes and letters and with network
     * labels from networkLabel(), and fills an empty plain category with the
     * first name. Rows whose JSON can't be read are left alone. Running it a
     * second time changes nothing.
     *
     * @return array{categories_filled: int, json_rewritten: int}
     */
    public static function repairStoredRows(PDO $pdo): array
    {
        $rows = $pdo->query(
            "SELECT id, category, categories_json FROM lstr_joined
              WHERE categories_json IS NOT NULL AND categories_json != ''"
        )->fetchAll(PDO::FETCH_ASSOC);

        $update = $pdo->prepare(
            'UPDATE lstr_joined SET category = :category, categories_json = :json WHERE id = :id'
        );

        $filled    = 0;
        $rewritten = 0;

        foreach ($rows as $row) {
            $oldCategory = (string) $row['category'];
            $oldJson     = (string) $row['categories_json'];

            $decoded = json_decode($oldJson, true);
            if (!is_array($decoded)) {
                continue;
            }

            foreach ($decoded as $i => $entry) {
                if (is_array($entry) && isset($entry['network']) && is_string($entry['network'])) {
                    $decoded[$i]['network'] = self::networkLabel($entry['network']);
                }
            }

            $newJson = json_encode($decoded, self::JSON_FLAGS);
            if ($newJson === false) {
                continue;
            }

            $newCategory = $oldCategory !== '' ? $oldCategory : self::primaryFor($newJson);

            if ($newJson === $oldJson && $newCategory === $oldCategory) {
                continue;
            }

            $update->execute(['category' => $newCategory, 'json' => $newJson, 'id' => (int) $row['id']]);

            if ($newCategory !== $oldCategory) {
                $filled++;
            }
            if ($newJson !== $oldJson) {
                $rewritten++;
            }
        }

        return ['categories_filled' => $filled, 'json_rewritten' => $rewritten];
    }

    /**
     * The non-empty names in a categories_json value, each once, in order.
     * Returns [] when there are none or the JSON can't be read.
     *
     * @return string[]
     */
    private static function jsonNames(?string $categoriesJson): array
    {
        if ($categoriesJson === null || $categoriesJson === '') {
            return [];
        }

        $decoded = json_decode($categoriesJson, true);
        if (!is_array($decoded)) {
            return [];
        }

        $names = [];
        foreach ($decoded as $entry) {
            if (is_array($entry) && isset($entry['name']) && is_scalar($entry['name']) && (string) $entry['name'] !== '') {
                $names[] = (string) $entry['name'];
            }
        }

        return array_values(array_unique($names));
    }
}
