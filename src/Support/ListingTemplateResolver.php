<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Resolves a template string through the three-level cascade used by all embed
 template surfaces: per-listing override, site-wide global default, then built-in
 code default. Returns the resolved value, which level it came from, and whether
 the template engine should render it (custom and global templates are rendered;
 built-in defaults are handled by the hardcoded PHP fragments instead). Pure
 function with no DB calls, making it trivially unit-testable.
*/

declare(strict_types=1);

namespace Luster\Support;

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
