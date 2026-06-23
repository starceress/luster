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
                'desc'   => 'Default crystal palette - pink, teal & gold',
                'colors' => ['#ff7eb3', '#78e8d8', '#ffd166'],
            ],
            [
                'slug'   => 'prism-power',
                'name'   => 'Prism Power',
                'light'  => 'Princess Serenity',
                'dark'   => 'Sailor Moon',
                'desc'   => 'Moon prism palette - ribbon pink, cobalt & gold',
                'colors' => ['#ee5e9e', '#4a6dd6', '#f7d440'],
            ],
            [
                'slug'   => 'victory-road',
                'name'   => 'Victory Road',
                'light'  => 'Pokemon Center',
                'dark'   => 'Elite Four',
                'desc'   => 'Pokemon palette - red, nature green & blue',
                'colors' => ['#e3350d', '#4dad5b', '#316cd9'],
            ],
            [
                'slug'   => 'the-force',
                'name'   => 'The Force',
                'light'  => 'Luke Skywalker',
                'dark'   => 'Darth Vader',
                'desc'   => 'Galactic palette - Jedi blue and Sith red',
                'colors' => ['#ff2a2a', '#990000', '#444444'],
            ],
            [
                'slug'   => 'floating-lights',
                'name'   => 'Floating Lights',
                'light'  => 'Rapunzel Gleam',
                'dark'   => 'Flynn Glow',
                'desc'   => 'Lantern palette - golden amber, wisteria & rose',
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
