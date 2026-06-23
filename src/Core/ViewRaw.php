<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Wrapper for trusted, pre-escaped HTML strings that should be inserted
 verbatim by View::substituteTokens() without double-escaping. Only used
 for server-generated HTML like country selects and extra field markup --
 never for user-supplied input.
*/

declare(strict_types=1);

namespace Luster\Core;

/**
 * Wraps a trusted, already-escaped HTML string so that View::substituteTokens()
 * inserts it verbatim instead of running it through htmlspecialchars().
 *
 * Only use for HTML produced server-side (e.g. buildExtraFieldsHtml(),
 * Countries::buildSelect()). Never wrap user-supplied strings in ViewRaw.
 */
final class ViewRaw
{
    public function __construct(public readonly string $html) {}
}
