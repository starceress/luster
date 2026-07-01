<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)
*/

declare(strict_types=1);

namespace Luster\Import;

/**
 * Immutable result of translating one Fanatically template entry.
 *
 * importable is true only when all of the following hold:
 *  - unmappedTokens is empty
 *  - containsPhp is false
 *  - unsupportedStructure is false
 *  - targetTemplateKey is not null
 *  - translatedContent is within the TEXT byte limit
 *
 * preview_only entries may be viewed and downloaded but must never be written
 * to any Luster template storage.
 */
final class TranslationResult
{
    public function __construct(
        public readonly string  $translatedContent,
        /** @var list<string> */
        public readonly array   $unmappedTokens,
        public readonly bool    $containsPhp,
        public readonly bool    $unsupportedStructure,
        public readonly ?string $targetTemplateKey,
        public readonly bool    $importable,
    ) {}
}
