<?php

declare(strict_types=1);

namespace Luster\Core;

/**
 * Canonical registry of bundled admin skins.
 *
 * To add a new bundled skin:
 *   1. Add token blocks to public/assets/css/tokens.css
 *      ([data-skin="x"], [data-skin="x"][data-theme="dark"],
 *       [data-skin="x"][data-theme="light"],
 *       [data-skin="x"][data-theme="light"] .sidebar)
 *   2. Add one entry to self::all() below.
 *
 * Community (drop-in) skins live in public/assets/css/skins/ and are
 * detected at runtime by SettingsController — they do not appear here.
 */
final class SkinCatalog
{
    /**
     * Every bundled skin, in display order.
     * The first entry (slug = '') is the default Nebula theme.
     *
     * @return list<array{slug:string,name:string,desc:string,colors:list<string>}>
     */
    public static function all(): array
    {
        return [
            [
                'slug'   => '',
                'name'   => 'Nebula',
                'desc'   => 'Default — pink, teal & gold',
                'colors' => ['#ff7eb3', '#78e8d8', '#ffd166'],
            ],
            [
                'slug'   => 'aurora',
                'name'   => 'Aurora',
                'desc'   => 'Northern lights — cyan, sage & pale sky',
                'colors' => ['#79e8ff', '#6bcfa0', '#b3e5fc'],
            ],
            [
                'slug'   => 'sol',
                'name'   => 'Sol',
                'desc'   => 'Sunrise — amber, rose & bright gold',
                'colors' => ['#ffb06a', '#ff8fa8', '#ffe066'],
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
