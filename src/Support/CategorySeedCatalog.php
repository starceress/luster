<?php

declare(strict_types=1);

namespace Luster\Support;

/**
 * Canonical TFL and TAFL category name lists.
 *
 * This is the single authoritative source used by:
 *   - public/migrate.php  (Migrations 7, 9, and 42)
 *   - public/install.php  (fresh-install seed)
 *
 * Both files are standalone (no Composer autoloader), so they
 * require_once this file directly before calling these methods.
 *
 * Lists match the live TFL/TAFL category registry as of 2025.
 * Add new official categories here; never duplicate them elsewhere.
 */
final class CategorySeedCatalog
{
    /** @return list<string> The 57 official The Fanlistings Network categories. */
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
     * @return list<string>
     * The 14 official The Anime Fanlistings Network categories,
     * stored in Luster as "Anime/Manga: {name}" flat strings.
     */
    public static function tafl(): array
    {
        return [
            'Anime/Manga: Adult',
            'Anime/Manga: Characters 0-M',
            'Anime/Manga: Characters N-Z',
            'Anime/Manga: Episodes',
            'Anime/Manga: Fanstuff',
            'Anime/Manga: General',
            'Anime/Manga: Items/Locations',
            'Anime/Manga: Manga-ka & Directors',
            'Anime/Manga: Movies/OVAs',
            'Anime/Manga: Music & Songs',
            'Anime/Manga: Relationships',
            'Anime/Manga: Rivalries',
            'Anime/Manga: Seiyuu',
            'Anime/Manga: Series',
        ];
    }
}
