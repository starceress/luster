<?php

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
