<?php

declare(strict_types=1);

namespace Luster\Support;

/**
 * Canonical TFL and TAFL category name lists.
 *
 * TAFL categories are stored WITHOUT the historical "Anime/Manga: " prefix
 * that Enthusiast used.  Disambiguation between TFL and TAFL categories with
 * the same bare name (Adult, Companies, Episodes, Toys/Collectibles, Websites)
 * is done entirely by the `source` column in lstr_categories.
 *
 * This is the single authoritative source used by:
 *   - public/migrate/index.php  (Migrations 7, 9, 42, 48, 49)
 *   - public/install/index.php  (fresh-install seed)
 *   - Import adapters            (EnthusiastImportSource, ListingAdminImportSource)
 *
 * Lists verified against the live TFL/TAFL category registries (2026).
 * Add new official categories here; never duplicate them elsewhere.
 */
final class CategorySeedCatalog
{
    /** @return list<string> Official The Fanlistings Network categories plus legacy import aliases still used by Enthusiast dumps. */
    public static function tfl(): array
    {
        return [
            'Academia',
            'Actors',
            'Actresses',
            'Adult',
            'Advertising/TV Channels',
            'Albums',
            'Animals',
            'Animation',
            'Anime/Manga',
            'Arts and Design',
            'Authors/Writers',
            'Calendar Events',
            'Characters: Book/Movie',
            'Characters: TV',
            'Comics',
            'Companies',
            'Computer Miscellany and Internet',
            'Directors/Producers',
            'Episodes',
            'Fan Works',
            'Fashion/Beauty',
            'Food/Drinks',
            'Games',
            'History/Royalty',
            'Hobbies and Recreation',
            'Literature',
            'Magazines/Newspapers',
            'Miscellaneous',
            'Models',
            'Movies',
            'Music Miscellany',
            'Musicians: Bands/Groups',
            'Musicians: Female',
            'Musicians: Male',
            'Mythology/Religion',
            'Nature',
            'Objects',
            'People Miscellany',
            'Personalities',
            'Places',
            'Politics and Organisations',
            'Radio',
            'Relationships: Book/Movie',
            'Relationships: Real Life',
            'Relationships: TV',
            'Songs: Bands/Groups 0-M',
            'Songs: Bands/Groups N-Z',
            'Songs: Female Solo',
            'Songs: Male Solo',
            'Songs: Various',
            'Sports',
            'Sports Entertainment',
            'Stage/Theatre',
            'Toys/Collectibles',
            'Transportation',
            'TV Shows',
            'TV/Movie/Book Miscellany',
            'Webmasters',
            'Websites',
        ];
    }

    /**
     * The 18 official The Anime Fanlistings Network categories.
     *
     * Stored as bare names (no "Anime/Manga: " prefix); the `source='tafl'`
     * column in lstr_categories provides the network context.
     *
     * Includes the 14 main browseable categories plus the 4 subcategories
     * listed under "General" on animefanlistings.com (Companies, Magazines,
     * Toys/Collectibles, Websites).
     *
     * @return list<string>
     */
    public static function tafl(): array
    {
        return [
            'Adult',
            'Characters 0-M',
            'Characters N-Z',
            'Companies',
            'Episodes',
            'Fanstuff',
            'General',
            'Items/Locations',
            'Magazines',
            'Manga-ka & Directors',
            'Movies/OVAs',
            'Music & Songs',
            'Relationships',
            'Rivalries',
            'Seiyuu',
            'Series',
            'Toys/Collectibles',
            'Websites',
        ];
    }

    /**
     * Maps old/variant category name spellings to their current canonical form.
     *
     * Keys are lowercase.  Values are the exact string as it appears in tfl()
     * or tafl().  Used by import adapters and Migration 48.
     *
     * Does NOT include the standard "Anime/Manga: X" → "X" prefix-strip
     * aliases — those are handled by normalizeImportName() automatically.
     *
     * @return array<string, string>
     */
    public static function legacyAliases(): array
    {
        return [
            // TFL: renamed
            'tv/stage personalities'                   => 'Personalities',
            // TAFL: "Manga-ka & Directors" had slash and spelling variants
            'anime/manga: manga-ka/directors'          => 'Manga-ka & Directors',
            'anime/manga: mangaka & directors'         => 'Manga-ka & Directors',
            'anime/manga: mangaka/directors'           => 'Manga-ka & Directors',
            'manga-ka/directors'                       => 'Manga-ka & Directors',
            'mangaka & directors'                      => 'Manga-ka & Directors',
            'mangaka/directors'                        => 'Manga-ka & Directors',
            // TAFL: "Music & Songs" was two separate categories
            'anime/manga: music'                       => 'Music & Songs',
            'anime/manga: songs'                       => 'Music & Songs',
            'anime/manga: music and songs'             => 'Music & Songs',
            'music and songs'                          => 'Music & Songs',
            // TAFL: Characters sub-ranges were sometimes parenthesised
            'anime/manga: characters (0-m)'            => 'Characters 0-M',
            'anime/manga: characters (n-z)'            => 'Characters N-Z',
            'characters (0-m)'                         => 'Characters 0-M',
            'characters (n-z)'                         => 'Characters N-Z',
            // TAFL: Items/Locations had variant spellings
            'anime/manga: items'                       => 'Items/Locations',
            'anime/manga: locations'                   => 'Items/Locations',
            'anime/manga: items & locations'           => 'Items/Locations',
            'items'                                    => 'Items/Locations',
            'locations'                                => 'Items/Locations',
            'items & locations'                        => 'Items/Locations',
            // TAFL: Movies/OVAs variants
            'anime/manga: movies'                      => 'Movies/OVAs',
            'anime/manga: movies & ovas'               => 'Movies/OVAs',
            'anime/manga: movies and ovas'             => 'Movies/OVAs',
            'movies & ovas'                            => 'Movies/OVAs',
            'movies and ovas'                          => 'Movies/OVAs',
        ];
    }

    /**
     * Normalises a raw category name from an Enthusiast/LA import.
     *
     * Resolution order:
     *  1. Apply legacyAliases (misspellings / renamed categories).
     *  2. If name starts with "Anime/Manga: " — it is TAFL; strip prefix and
     *     look up canonical casing in tafl().  Unknown bare names are still
     *     classified as tafl so the prefix intent is preserved.
     *  3. Look up in tfl() (TFL checked first for bare ambiguous names).
     *  4. Look up in tafl().
     *  5. Fall through → source='custom', name unchanged.
     *
     * @return array{0: string, 1: string}  [canonical_name, source]
     */
    public static function normalizeImportName(string $raw): array
    {
        static $tflCatalog    = null;
        static $taflCatalog   = null;
        static $tflLookup     = null;
        static $taflLookup    = null;
        static $aliases       = null;

        if ($tflCatalog === null) {
            $tflCatalog  = self::tfl();
            $taflCatalog = self::tafl();
            $tflLookup   = array_flip(array_map('strtolower', $tflCatalog));
            $taflLookup  = array_flip(array_map('strtolower', $taflCatalog));
            $aliases     = self::legacyAliases();
        }

        $lower = strtolower(trim($raw));

        // Step 1: legacy aliases (also catches "Anime/Manga: old-name" variants)
        if (isset($aliases[$lower])) {
            $raw   = $aliases[$lower];
            $lower = strtolower($raw);
        }

        // Step 2: "Anime/Manga: X" prefix — always TAFL
        if (str_starts_with($lower, 'anime/manga: ')) {
            $bareLower = substr($lower, 13);
            $name      = isset($taflLookup[$bareLower])
                ? $taflCatalog[$taflLookup[$bareLower]]
                : substr($raw, 13);            // unknown subcategory — keep bare name
            return [$name, 'tafl'];
        }

        // Step 3: TFL lookup
        if (isset($tflLookup[$lower])) {
            return [$tflCatalog[$tflLookup[$lower]], 'tfl'];
        }

        // Step 4: TAFL lookup
        if (isset($taflLookup[$lower])) {
            return [$taflCatalog[$taflLookup[$lower]], 'tafl'];
        }

        // Step 5: custom
        return [$raw, 'custom'];
    }
}
