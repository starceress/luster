<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 The single authoritative list of canonical TFL and TAFL category names.
 Migrations, the installer, and all import adapters pull from here so
 category names are never duplicated across the codebase. Also provides
 a normalizer that resolves legacy misspellings and variant prefixes
 back to their current canonical form during imports.
*/

declare(strict_types=1);

namespace Luster\Support;

/**
 * Canonical TFL and TAFL category name lists.
 *
 * TAFL categories are stored WITH the "Anime/Manga: " prefix so they are
 * distinguishable from TFL categories without inspecting the `source` column.
 * The prefix matches the historical Enthusiast convention.
 *
 * This is the single authoritative source used by:
 *   - public/migrate/index.php  (Migrations 7, 9, 42, 48, 49, 63)
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
     * Stored with the "Anime/Manga: " prefix so they display with network
     * context and remain distinct from same-named TFL categories.
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
            'Anime/Manga: Adult',
            'Anime/Manga: Characters 0-M',
            'Anime/Manga: Characters N-Z',
            'Anime/Manga: Companies',
            'Anime/Manga: Episodes',
            'Anime/Manga: Fanstuff',
            'Anime/Manga: General',
            'Anime/Manga: Items/Locations',
            'Anime/Manga: Magazines',
            'Anime/Manga: Manga-ka & Directors',
            'Anime/Manga: Movies/OVAs',
            'Anime/Manga: Music & Songs',
            'Anime/Manga: Relationships',
            'Anime/Manga: Rivalries',
            'Anime/Manga: Seiyuu',
            'Anime/Manga: Series',
            'Anime/Manga: Toys/Collectibles',
            'Anime/Manga: Websites',
        ];
    }

    /**
     * Maps old/variant category name spellings to their current canonical form.
     *
     * Keys are lowercase.  Values are the exact string as it appears in tfl()
     * or tafl().  Used by import adapters and Migration 48.
     *
     * Does NOT include the standard "Anime/Manga: X" → "Anime/Manga: X"
     * pass-through — those are handled by normalizeImportName() automatically.
     *
     * @return array<string, string>
     */
    public static function legacyAliases(): array
    {
        return [
            // TFL: renamed
            'tv/stage personalities'                   => 'Personalities',
            // TAFL: "Manga-ka & Directors" had slash and spelling variants
            'anime/manga: manga-ka/directors'          => 'Anime/Manga: Manga-ka & Directors',
            'anime/manga: mangaka & directors'         => 'Anime/Manga: Manga-ka & Directors',
            'anime/manga: mangaka/directors'           => 'Anime/Manga: Manga-ka & Directors',
            'manga-ka/directors'                       => 'Anime/Manga: Manga-ka & Directors',
            'mangaka & directors'                      => 'Anime/Manga: Manga-ka & Directors',
            'mangaka/directors'                        => 'Anime/Manga: Manga-ka & Directors',
            // TAFL: "Music & Songs" was two separate categories
            'anime/manga: music'                       => 'Anime/Manga: Music & Songs',
            'anime/manga: songs'                       => 'Anime/Manga: Music & Songs',
            'anime/manga: music and songs'             => 'Anime/Manga: Music & Songs',
            'music and songs'                          => 'Anime/Manga: Music & Songs',
            // TAFL: Characters sub-ranges were sometimes parenthesised
            'anime/manga: characters (0-m)'            => 'Anime/Manga: Characters 0-M',
            'anime/manga: characters (n-z)'            => 'Anime/Manga: Characters N-Z',
            'characters (0-m)'                         => 'Anime/Manga: Characters 0-M',
            'characters (n-z)'                         => 'Anime/Manga: Characters N-Z',
            // TAFL: Items/Locations had variant spellings
            'anime/manga: items'                       => 'Anime/Manga: Items/Locations',
            'anime/manga: locations'                   => 'Anime/Manga: Items/Locations',
            'anime/manga: items & locations'           => 'Anime/Manga: Items/Locations',
            'items'                                    => 'Anime/Manga: Items/Locations',
            'locations'                                => 'Anime/Manga: Items/Locations',
            'items & locations'                        => 'Anime/Manga: Items/Locations',
            // TAFL: Movies/OVAs variants
            'anime/manga: movies'                      => 'Anime/Manga: Movies/OVAs',
            'anime/manga: movies & ovas'               => 'Anime/Manga: Movies/OVAs',
            'anime/manga: movies and ovas'             => 'Anime/Manga: Movies/OVAs',
            'movies & ovas'                            => 'Anime/Manga: Movies/OVAs',
            'movies and ovas'                          => 'Anime/Manga: Movies/OVAs',
        ];
    }

    /**
     * Normalises a raw category name from an Enthusiast/LA import.
     *
     * Resolution order:
     *  1. Apply legacyAliases (misspellings / renamed categories).
     *  2. If name starts with "Anime/Manga: " — it is TAFL; strip prefix and
     *     look up canonical casing via bare lookup.  Unknown subcategories
     *     are kept with the prefix intact and classified as tafl.
     *  3. Look up in tfl() (TFL checked first for bare ambiguous names).
     *  4. Look up in tafl() — first by full prefixed name (after step 1 alias),
     *     then by bare name (for imports that omit the prefix).
     *  5. Fall through → source='custom', name unchanged.
     *
     * @return array{0: string, 1: string}  [canonical_name, source]
     */
    public static function normalizeImportName(string $raw): array
    {
        static $tflCatalog     = null;
        static $taflCatalog    = null;
        static $tflLookup      = null;
        static $taflLookup     = null;
        static $taflBareLookup = null;
        static $aliases        = null;

        if ($tflCatalog === null) {
            $tflCatalog  = self::tfl();
            $taflCatalog = self::tafl();
            $tflLookup   = array_flip(array_map('strtolower', $tflCatalog));
            $taflLookup  = array_flip(array_map('strtolower', $taflCatalog));
            $aliases     = self::legacyAliases();

            // Maps bare lowercase name → canonical prefixed name, e.g. "series" → "Anime/Manga: Series"
            $taflBareLookup = [];
            foreach ($taflCatalog as $prefixed) {
                $bare = strtolower(trim((string) preg_replace('#^Anime/Manga:\s*#i', '', $prefixed)));
                $taflBareLookup[$bare] = $prefixed;
            }
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
            $name      = $taflBareLookup[$bareLower]             // canonical via bare lookup
                ?? (isset($taflLookup[$lower])
                    ? $taflCatalog[$taflLookup[$lower]]          // already a full canonical name
                    : $raw);                                      // unknown subcategory — keep as-is
            return [$name, 'tafl'];
        }

        // Step 3: TFL lookup
        if (isset($tflLookup[$lower])) {
            return [$tflCatalog[$tflLookup[$lower]], 'tfl'];
        }

        // Step 4: TAFL lookup — full prefixed name (from step 1 alias) then bare name
        if (isset($taflLookup[$lower])) {
            return [$taflCatalog[$taflLookup[$lower]], 'tafl'];
        }
        if (isset($taflBareLookup[$lower])) {
            return [$taflBareLookup[$lower], 'tafl'];
        }

        // Step 5: custom
        return [$raw, 'custom'];
    }
}
