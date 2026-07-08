<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Searches TFL and TAFL for the approval-page URL of a fanlisting, given its
 subject and the listing's assigned network category. Both networks require
 browsing into a specific category before any individual fanlisting entries
 are visible — there is no site-wide index or search endpoint on either
 network — so the listing's category (matched against a hardcoded name→slug
 table verified against each network's live site) picks which page(s) get
 crawled. Results are cached per (network, slug) pair in lstr_site_settings
 for 24 hours. Returns up to 10 candidate matches for the admin to review;
 the admin must explicitly save any URL they want to keep.
*/

declare(strict_types=1);

namespace Luster\Core;

use Luster\Repositories\SiteSettingsRepo;

/**
 * TFL/TAFL approval URL lookup helper.
 *
 * TFL ("thefanlistings.org"): each top-level category page
 * (`browse.php?show={slug}`) either lists entries directly or links out to
 * further subcategory pages (`browse.php?show={slug}&start=N`). Individual
 * fanlisting entries are rendered as `<span class="two"><a>...</a></span>`.
 * We crawl the category page plus every subcategory page it links to.
 *
 * TAFL ("animefanlistings.com"): each category has a dedicated
 * `browse/{slug}/all` view that lists every entry in that category in one
 * page, rendered as plain `<a href="..." target="_blank">Title</a>` links to
 * external sites (as opposed to TAFL's own internal navigation links).
 *
 * Both networks require picking a category before any fanlisting names are
 * visible at all — there's no site-wide index page or public search endpoint
 * that lists them — so a match is only possible when the listing has a
 * recognised TFL/TAFL category assigned.
 */
final class TflLookupService
{
    private const CACHE_TTL = 86400; // 24 hours in seconds

    /**
     * Luster category name (as seeded by CategorySeedCatalog::tfl()) → TFL's
     * real `browse.php?show=` slug. Verified directly against TFL's live
     * category index; categories with no matching entry there (e.g.
     * "Anime/Manga", "Magazines/Newspapers", "Radio") are intentionally
     * omitted rather than guessed.
     */
    private const TFL_CATEGORY_SLUGS = [
        'Academia'                          => 'academia',
        'Actors'                            => 'actors',
        'Actresses'                         => 'actresses',
        'Adult'                             => 'adult',
        'Advertising/TV Channels'           => 'advertisingtvchannels',
        'Albums'                            => 'albums',
        'Animals'                           => 'animals',
        'Animation'                         => 'animation',
        'Arts and Design'                   => 'artsdesign',
        'Authors/Writers'                   => 'authorswriters',
        'Calendar Events'                   => 'calendarevents',
        'Characters: Book/Movie'            => 'charactersbookmovie',
        'Characters: TV'                    => 'characterstv',
        'Comics'                            => 'comics',
        'Companies'                         => 'companies',
        'Computer Miscellany and Internet'  => 'computermiscellanyinternet',
        'Directors/Producers'               => 'directorsproducers',
        'Episodes'                          => 'episodes',
        'Fan Works'                         => 'fanworks',
        'Fashion/Beauty'                    => 'fashionbeauty',
        'Food/Drinks'                       => 'fooddrinks',
        'Games'                             => 'games',
        'History/Royalty'                   => 'historyroyalty',
        'Hobbies and Recreation'            => 'hobbiesandrecreation',
        'Literature'                        => 'literature',
        'Miscellaneous'                     => 'miscellaneous',
        'Models'                            => 'models',
        'Movies'                            => 'movies',
        'Music Miscellany'                  => 'musicmiscellany',
        'Musicians: Bands/Groups'           => 'musiciansbandsgroups',
        'Musicians: Female'                 => 'musiciansfemale',
        'Musicians: Male'                   => 'musiciansmale',
        'Mythology/Religion'                => 'mythologyreligion',
        'Nature'                            => 'nature',
        'Objects'                           => 'objects',
        'People Miscellany'                 => 'peoplemiscellany',
        'Personalities'                     => 'personalities',
        'Places'                            => 'places',
        'Politics and Organisations'        => 'politicsorganisations',
        'Relationships: Book/Movie'         => 'relationshipsbookmovie',
        'Relationships: Real Life'          => 'relationshipsreallife',
        'Relationships: TV'                 => 'relationshipstv',
        'Songs: Bands/Groups 0-M'           => 'songsbandsgroups0m',
        'Songs: Bands/Groups N-Z'           => 'songsbandsgroupsnz',
        'Songs: Female Solo'                => 'songsfemalesolo',
        'Songs: Male Solo'                  => 'songsmalesolo',
        'Songs: Various'                    => 'songsvarious',
        'Sports'                            => 'sports',
        'Sports Entertainment'              => 'sportsentertainment',
        'Stage/Theatre'                     => 'stagetheatre',
        'Toys/Collectibles'                 => 'toyscollectibles',
        'Transportation'                    => 'transportation',
        'TV Shows'                          => 'tvshows',
        'TV/Movie/Book Miscellany'          => 'tvmoviebookmiscellany',
        'Webmasters'                        => 'webmasters',
        'Websites'                          => 'websites',
    ];

    /**
     * Luster category name (as seeded by CategorySeedCatalog::tafl()) →
     * TAFL's real `browse/{slug}` path segment. TAFL was rebuilt on a
     * different platform than TFL and only these categories were verified
     * against its live site; others fall back to a "not supported yet"
     * message rather than guessing a URL.
     */
    private const TAFL_CATEGORY_SLUGS = [
        'Anime/Manga: Characters 0-M'      => 'characters_0m',
        'Anime/Manga: Characters N-Z'      => 'characters_nz',
        'Anime/Manga: Episodes'            => 'episodes',
        'Anime/Manga: General'             => 'general',
        'Anime/Manga: Items/Locations'     => 'items',
        'Anime/Manga: Manga-ka & Directors' => 'mangaka',
        'Anime/Manga: Movies/OVAs'         => 'movies',
        'Anime/Manga: Music & Songs'       => 'musicandsongs',
        'Anime/Manga: Relationships'       => 'relationships',
        'Anime/Manga: Rivalries'           => 'rivalries',
        'Anime/Manga: Series'              => 'series',
        'Anime/Manga: Seiyuu'              => 'seiyuu',
    ];

    /**
     * Searches TFL or TAFL for a fanlisting's approval URL by subject.
     *
     * The listing's own category name (matching CategorySeedCatalog::tfl()
     * or ::tafl()) must be supplied — neither network exposes a page listing
     * every approved fanlisting, so there's nothing to search without one.
     *
     * @return array{url: ?string, candidates: list<array{text: string, url: string}>, error: ?string, cache_age: ?int}
     */
    public static function lookup(string $subject, string $network, SiteSettingsRepo $siteRepo, ?string $categoryName = null): array
    {
        if (!in_array($network, ['tfl', 'tafl'], true)) {
            return ['url' => null, 'candidates' => [], 'error' => 'Unknown network.', 'cache_age' => null];
        }

        $label = $network === 'tfl' ? 'TFL' : 'TAFL';

        $categoryName = trim((string) $categoryName);
        if ($categoryName === '') {
            return [
                'url'        => null,
                'candidates' => [],
                'error'      => "This listing has no {$label} category assigned. Assign one under Categories, then try Look Up again, or enter the URL manually.",
                'cache_age'  => null,
            ];
        }

        $slugMap = $network === 'tfl' ? self::TFL_CATEGORY_SLUGS : self::TAFL_CATEGORY_SLUGS;
        $slug    = $slugMap[$categoryName] ?? null;

        if ($slug === null || !preg_match('/^[a-z0-9_]+$/', $slug)) {
            return [
                'url'        => null,
                'candidates' => [],
                'error'      => "Automatic {$label} lookup isn't available yet for the \"{$categoryName}\" category. Enter the URL manually.",
                'cache_age'  => null,
            ];
        }

        [$links, $fetchError, $cacheAge] = self::getCachedLinksForSlug($network, $slug, $siteRepo);

        if ($links === null) {
            return ['url' => null, 'candidates' => [], 'error' => $fetchError ?? 'Could not fetch the network page.', 'cache_age' => null];
        }

        return self::searchLinks($links, $subject) + ['error' => null, 'cache_age' => $cacheAge];
    }

    // -----------------------------------------------------------------------
    // Private helpers
    // -----------------------------------------------------------------------

    /**
     * @return array{0: list<array{text: string, url: string}>|null, 1: ?string, 2: ?int}
     *   [links or null, error message or null, cache age in seconds or null]
     */
    private static function getCachedLinksForSlug(string $network, string $slug, SiteSettingsRepo $siteRepo): array
    {
        $keyLinks = "{$network}_link_map_{$slug}_json";
        $keyAt    = "{$network}_link_map_{$slug}_fetched_at";

        $fetchedAt = $siteRepo->get($keyAt);
        $cached    = $siteRepo->get($keyLinks);

        if ($fetchedAt !== null && $cached !== null && (time() - (int) $fetchedAt) < self::CACHE_TTL) {
            $links = json_decode($cached, true);
            if (is_array($links)) {
                return [$links, null, max(0, time() - (int) $fetchedAt)];
            }
        }

        $links = $network === 'tfl' ? self::fetchTflLinks($slug) : self::fetchTaflLinks($slug);

        if ($links === null) {
            return [null, 'Could not connect to the network. The site may be down or temporarily unreachable.', null];
        }

        self::storeCache($keyLinks, $keyAt, $links, $siteRepo);
        return [$links, null, 0];
    }

    /**
     * Fetches a TFL category page and every subcategory page it links to
     * (`browse.php?show={slug}&start=N`), aggregating every fanlisting entry
     * found across all of them.
     *
     * @return list<array{text: string, url: string}>|null
     */
    private static function fetchTflLinks(string $slug): ?array
    {
        $baseUrl = 'https://thefanlistings.org/browse.php?show=' . $slug;
        $html    = LinkChecker::safeGet($baseUrl);

        if ($html === null) {
            return null;
        }

        $links = self::parseTflEntries($html);

        $starts = [];
        if (preg_match_all('/browse\.php\?show=' . preg_quote($slug, '/') . '(?:&amp;|&)start=(\d+)/', $html, $m)) {
            $starts = array_unique(array_map('intval', $m[1]));
        }

        foreach ($starts as $start) {
            $subHtml = LinkChecker::safeGet($baseUrl . '&start=' . $start);
            if ($subHtml !== null) {
                $links = array_merge($links, self::parseTflEntries($subHtml));
            }
        }

        return $links;
    }

    /**
     * Extracts fanlisting entries from a TFL browse page. Real entries are
     * rendered as `<span class="two"><a href="...">Title</a></span>`, which
     * distinguishes them from ordinary navigation/subcategory links elsewhere
     * on the same page.
     *
     * @return list<array{text: string, url: string}>
     */
    private static function parseTflEntries(string $html): array
    {
        $links = [];

        if (!preg_match_all('/<span class="two"><a\s+href="([^"]+)"[^>]*>(.*?)<\/a><\/span>/is', $html, $matches, PREG_SET_ORDER)) {
            return $links;
        }

        foreach ($matches as $m) {
            $href = html_entity_decode(trim($m[1]), ENT_QUOTES | ENT_HTML5);
            $text = trim(strip_tags(html_entity_decode($m[2], ENT_QUOTES | ENT_HTML5)));
            if ($href === '' || $text === '') {
                continue;
            }
            $links[] = ['text' => $text, 'url' => $href];
        }

        return $links;
    }

    /**
     * Fetches TAFL's "view all" page for a category, which lists every
     * fanlisting entry in that category on one page.
     *
     * @return list<array{text: string, url: string}>|null
     */
    private static function fetchTaflLinks(string $slug): ?array
    {
        $html = LinkChecker::safeGet('https://www.animefanlistings.com/browse/' . $slug . '/all');

        if ($html === null) {
            return null;
        }

        return self::parseTaflEntries($html);
    }

    /**
     * Extracts fanlisting entries from a TAFL "view all" page. Real entries
     * are plain `<a href="..." target="_blank">Title</a>` links to external
     * sites; TAFL's own internal navigation links also use target="_blank"
     * in a few spots (social links in the header), so those are excluded by
     * filtering out any href pointing back at animefanlistings.com.
     *
     * @return list<array{text: string, url: string}>
     */
    private static function parseTaflEntries(string $html): array
    {
        $links = [];

        if (!preg_match_all('/<a\s+href="([^"]+)"[^>]*target="_blank"[^>]*>([^<]+)<\/a>/i', $html, $matches, PREG_SET_ORDER)) {
            return $links;
        }

        foreach ($matches as $m) {
            $href = html_entity_decode(trim($m[1]), ENT_QUOTES | ENT_HTML5);
            $text = trim(html_entity_decode($m[2], ENT_QUOTES | ENT_HTML5));
            if ($href === '' || $text === '' || self::isTaflChromeLink($href)) {
                continue;
            }
            $links[] = ['text' => $text, 'url' => $href];
        }

        return $links;
    }

    /**
     * True when $href points at TAFL's own site or one of its fixed header/footer
     * social links rather than a member's fanlisting — these also render as
     * target="_blank" anchors alongside the real listing entries.
     */
    private static function isTaflChromeLink(string $href): bool
    {
        foreach (['animefanlistings.com', 'twitter.com', 'livejournal.com', 'w3.org'] as $chromeDomain) {
            if (str_contains($href, $chromeDomain)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Stores a parsed link list in lstr_site_settings as compact JSON.
     *
     * @param list<array{text: string, url: string}> $links
     */
    private static function storeCache(string $keyLinks, string $keyAt, array $links, SiteSettingsRepo $siteRepo): void
    {
        $json = (string) json_encode($links, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        // TEXT column limit is 65 535 bytes; truncate the JSON array at a safe boundary
        if (strlen($json) > 60000) {
            for ($n = count($links); $n > 0; $n--) {
                $j = (string) json_encode(array_slice($links, 0, $n), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                if (strlen($j) <= 60000) {
                    $json = $j;
                    break;
                }
            }
        }
        $siteRepo->set($keyLinks, $json);
        $siteRepo->set($keyAt, (string) time());
    }

    /**
     * Searches the link list for entries whose text matches $subject.
     *
     * Returns an exact match when the normalised text is identical to the
     * normalised subject, otherwise up to 10 partial matches.
     *
     * @param list<array{text: string, url: string}> $links
     * @return array{url: ?string, candidates: list<array{text: string, url: string}>}
     */
    private static function searchLinks(array $links, string $subject): array
    {
        $subNorm    = self::norm($subject);
        $exactUrl   = null;
        $candidates = [];

        if ($subNorm === '') {
            return ['url' => null, 'candidates' => []];
        }

        foreach ($links as $entry) {
            $textNorm = self::norm($entry['text']);

            if ($textNorm === $subNorm) {
                $exactUrl = $entry['url'];
                break;
            }

            if (str_contains($textNorm, $subNorm) || str_contains($subNorm, $textNorm)) {
                $candidates[] = $entry;
            }
        }

        return [
            'url'        => $exactUrl,
            'candidates' => array_slice($candidates, 0, 10),
        ];
    }

    /** Normalises a string for case/punctuation-insensitive comparison. */
    private static function norm(string $s): string
    {
        $s = mb_strtolower(trim($s));
        $s = (string) preg_replace('/[^a-z0-9\s]/u', '', $s);
        return (string) preg_replace('/\s+/', ' ', $s);
    }
}
