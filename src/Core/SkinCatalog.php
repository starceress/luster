<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Canonical registry of the bundled admin panel skins. Each entry defines
 the skin's slug, display name, description, and preview colours used by
 the Settings appearance panel. Adding a new bundled skin requires one PHP
 entry here and one CSS block in skins.css. Community drop-in skins are
 detected at runtime and do not appear in this registry.
*/

declare(strict_types=1);

namespace Luster\Core;

/**
 * Canonical registry of bundled admin skins.
 *
 * To add a new bundled skin:
 *   1. Add token blocks and class overrides to public/assets/css/skins.css
 *      ([data-skin="x"], [data-skin="x"][data-theme="dark"],
 *       [data-skin="x"][data-theme="light"],
 *       [data-skin="x"][data-theme="light"] .sidebar, etc.)
 *   2. Add one entry to self::all() below.
 *
 * Community (drop-in) skins live in public/assets/css/skins/ and are
 * detected at runtime by SettingsController; they do not appear here.
 */
final class SkinCatalog
{
    /**
     * Every bundled skin, in display order.
     * The first entry (slug = '') is the default Velvet Geode theme.
     *
     * @return list<array{slug:string,name:string,light:string,dark:string,desc:string,colors:list<string>}>
     */
    public static function all(): array
    {
        return [
            [
                'slug'   => '',
                'name'   => 'Velvet Geode',
                'light'  => 'Opal Shard',
                'dark'   => 'Iolite Cavern',
                'desc'   => 'Where fandoms can shine.',
                'colors' => ['#ff7eb3', '#78e8d8', '#ffd166'],
            ],
            [
                'slug'   => 'prism-power',
                'name'   => 'Prism Power',
                'light'  => 'Moon Princess',
                'dark'   => 'Sailor Scout',
                'desc'   => 'Fighting evil by moonlight, winning love by daylight.',
                'colors' => ['#ee5e9e', '#4a6dd6', '#f7d440'],
            ],
            [
                'slug'   => 'victory-road',
                'name'   => 'Victory Road',
                'light'  => 'Pokemon Center',
                'dark'   => 'Elite Four',
                'desc'   => 'I wanna be the very best, like no one ever was.',
                'colors' => ['#e3350d', '#4dad5b', '#316cd9'],
            ],
            [
                'slug'   => 'the-force',
                'name'   => 'The Force',
                'light'  => 'Jedi Knight',
                'dark'   => 'Sith Lord',
                'desc'   => 'A long time ago in a galaxy far, far away...',
                'colors' => ['#ff2a2a', '#990000', '#444444'],
            ],
            [
                'slug'   => 'floating-lights',
                'name'   => 'Floating Lights',
                'light'  => 'Flower Gleam',
                'dark'   => 'Lantern Glow',
                'desc'   => 'At last I see the light, and it\'s like the fog has lifted.',
                'colors' => ['#ffca28', '#d884cb', '#ff77a8'],
            ],
        ];
    }

    /**
     * Non-empty slugs of all bundled skins (excludes the default '').
     * Used to gate CSS file injection: bundled skins load from tokens.css,
     * community skins need an extra <link>.
     *
     * @return list<string>
     */
    public static function bundledSlugs(): array
    {
        return array_values(array_filter(array_column(self::all(), 'slug')));
    }

    /**
     * Returns true when $slug refers to a bundled skin (or the default '').
     */
    public static function isBundled(string $slug): bool
    {
        return $slug === '' || in_array($slug, self::bundledSlugs(), true);
    }
}
