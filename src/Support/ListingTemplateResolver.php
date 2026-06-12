<?php

declare(strict_types=1);

namespace Luster\Support;

/**
 * Resolves a per-listing template through the three-level cascade:
 *   1. Per-listing override  (lstr_fanlisting_settings column — custom)
 *   2. Site-wide global default (lstr_site_settings key — global)
 *   3. Built-in code default  (ListingSettingsRepo::default*() — built_in)
 *
 * Pure function — no DB calls, trivially unit-testable.
 */
final class ListingTemplateResolver
{
    /**
     * @return array{value: string, source: 'custom'|'global'|'built_in', should_render_template: bool}
     */
    public static function resolve(
        ?string $perListingValue,
        ?string $globalValue,
        string  $builtInDefault,
    ): array {
        if ($perListingValue !== null && trim($perListingValue) !== '') {
            return ['value' => $perListingValue, 'source' => 'custom', 'should_render_template' => true];
        }
        if ($globalValue !== null && trim($globalValue) !== '') {
            return ['value' => $globalValue, 'source' => 'global', 'should_render_template' => true];
        }
        return ['value' => $builtInDefault, 'source' => 'built_in', 'should_render_template' => false];
    }
}
