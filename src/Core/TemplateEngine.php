<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Centralised {token} template processor for the public template system.
 Handles flat token replacement, conditional {if_*}...{/if_*} blocks, and
 {each_*}...{/each_*} loops in a single stateless utility. Enforces an
 escaping contract where values are auto-escaped via View::e() unless
 explicitly passed as trusted raw HTML through a separate parameter map.
*/

declare(strict_types=1);

namespace Luster\Core;

use InvalidArgumentException;

/**
 * Centralized {token} template processor for the Public Template System.
 *
 * Replaces the ad-hoc preg_replace()/str_replace() patterns that were scattered
 * across public controllers and embed files with one tested, stateless utility.
 * Stateless and called directly throughout the codebase — the same way View::e()
 * is — so every method is static.
 *
 * Token format is always single curly braces: {token_name}. Conditional blocks
 * use {if_name}...{/if_name}; loop blocks use {each_name}...{/each_name}.
 *
 * Processing order in render(): loops, then conditionals, then flat tokens
 * (escaped first, then raw). This order matters — loop bodies and conditional
 * blocks may themselves contain flat tokens that must survive until the final
 * tokens() pass, and conditional blocks may wrap loop output.
 *
 * Escaping contract (see docs/PUBLIC-TEMPLATES-PLAN.md "Escaping & Trusted-HTML
 * Convention"): tokens() takes two separate maps so callers never have to
 * remember to pre-escape. $escaped values are run through View::e(); $raw values
 * are inserted verbatim and must already be trusted HTML. Any $raw key must end
 * in `_html` (or appear in the documented allow-list) — tokens() enforces this at
 * runtime so a typo'd key like {listing_image} (instead of {listing_image_html})
 * fails loudly instead of silently shipping an unescaped value.
 */
final class TemplateEngine
{
    /**
     * Raw-token keys that are inherently markup but do not carry the `_html`
     * suffix — documented here as the single allow-list the engine enforces.
     *
     * `input` — rendered <input>/<select>/<textarea> markup from FieldRenderer,
     * passed straight through a form-field wrapper template.
     *
     */
    private const RAW_TOKEN_ALLOWLIST = ['input', 'pagination', 'network_status', 'stat_newmembers'];

    private function __construct()
    {
    }

    /**
     * Replaces {if_name}...{/if_name} blocks based on $conditions.
     *
     * $conditions maps a bare condition name (no `if_` prefix, no braces) to a
     * boolean. When true, the wrapper tags are stripped and the inner content is
     * kept; when false, the entire block (tags and content) is removed. Pass
     * both a name and its negated counterpart (e.g. 'affiliates' => $hasAny,
     * 'no_affiliates' => !$hasAny) to support {if_x}/{if_no_x} pairs — the engine
     * treats every name independently.
     *
     * @param array<string, bool> $conditions
     */
    public static function conditionals(string $tpl, array $conditions): string
    {
        foreach ($conditions as $name => $isTrue) {
            $tag     = preg_quote((string) $name, '/');
            $pattern = '/\{if_' . $tag . '\}(.*?)\{\/if_' . $tag . '\}/s';

            $tpl = (string) preg_replace_callback(
                $pattern,
                static fn(array $m): string => $isTrue ? $m[1] : '',
                $tpl,
            );
        }

        return $tpl;
    }

    /**
     * Replaces every {each_name}...{/each_name} block with $rowFn() applied to
     * each entry in $rows, concatenated in order.
     *
     * $rowFn receives the loop's inner template string and the current row, and
     * returns the rendered HTML for that row — typically a TemplateEngine::render()
     * or ::tokens() call. Nesting is supported: an inner {each_other}...{/each_other}
     * block is passed through untouched inside $innerTpl for a subsequent loop()
     * call (e.g. {each_size_group} wrapping {each_code}).
     *
     * Row output is concatenated with no separator — any whitespace/newlines
     * between rows are the responsibility of the row template itself, so the
     * same engine works for both inline (pagination) and block-level (listings)
     * loops without imposing a one-size-fits-all join character.
     *
     * @param mixed[]                          $rows
     * @param callable(string, mixed): string  $rowFn
     */
    public static function loop(string $tpl, string $name, array $rows, callable $rowFn): string
    {
        $tag     = preg_quote($name, '/');
        $pattern = '/\{each_' . $tag . '\}(.*?)\{\/each_' . $tag . '\}/s';

        return (string) preg_replace_callback(
            $pattern,
            static function (array $m) use ($rows, $rowFn): string {
                $inner = $m[1];
                $parts = [];

                foreach ($rows as $row) {
                    $parts[] = $rowFn($inner, $row);
                }

                return implode('', $parts);
            },
            $tpl,
        );
    }

    /**
     * Replaces flat {token} placeholders.
     *
     * $escaped values are run through View::e() automatically — pass raw scalars,
     * never pre-escaped strings. $raw values are inserted verbatim and must
     * already be trusted HTML (e.g. nl2br(View::e($body))); every key must end in
     * `_html` or appear in self::RAW_TOKEN_ALLOWLIST, enforced via
     * InvalidArgumentException so an unescaped value can never ship under a
     * plain-looking token name.
     *
     * @param array<string, scalar|null> $escaped
     * @param array<string, string>      $raw
     *
     * @throws InvalidArgumentException when a $raw key is missing the `_html`
     *         suffix and is not in the documented allow-list
     */
    public static function tokens(string $tpl, array $escaped, array $raw = []): string
    {
        foreach (array_keys($raw) as $key) {
            $key = (string) $key;
            if (!str_ends_with($key, '_html') && !in_array($key, self::RAW_TOKEN_ALLOWLIST, true)) {
                throw new InvalidArgumentException(
                    "TemplateEngine::tokens(): raw token '{$key}' must end in '_html' "
                    . "or be in the documented allow-list (" . implode(', ', self::RAW_TOKEN_ALLOWLIST) . ")",
                );
            }
        }

        // strtr() performs a single simultaneous pass over $tpl — unlike
        // str_replace() with array arguments, which applies each pair in
        // sequence against the progressively-modified result. That sequential
        // behaviour lets an earlier replacement value containing literal
        // "{token}" text be re-matched and substituted by a later pair,
        // breaking the "$escaped values stay plain text" contract (e.g. an
        // escaped listing title of "{input}" could pull in trusted raw HTML
        // from the $raw map). strtr() replaces every key against the
        // *original* string in one simultaneous scan, so no cascading is
        // possible — it is the documented fix for this exact class of bug.
        $map = [];

        foreach ($escaped as $key => $value) {
            $map['{' . $key . '}'] = View::e((string) $value);
        }

        foreach ($raw as $key => $value) {
            $map['{' . $key . '}'] = $value;
        }

        return strtr($tpl, $map);
    }

    /**
     * Convenience wrapper: applies loops, then conditionals, then flat tokens —
     * always in that order, matching the contract documented on the class.
     *
     * $loops maps a loop name to ['rows' => mixed[], 'rowFn' => callable(string, mixed): string],
     * i.e. the exact ($rows, $rowFn) pair that loop() expects.
     *
     * @param array<string, scalar|null>                                  $escaped
     * @param array<string, string>                                       $raw
     * @param array<string, bool>                                         $conditions
     * @param array<string, array{rows: mixed[], rowFn: callable(string, mixed): string}> $loops
     */
    public static function render(
        string $tpl,
        array $escaped = [],
        array $raw = [],
        array $conditions = [],
        array $loops = [],
    ): string {
        foreach ($loops as $name => $loop) {
            $tpl = self::loop($tpl, (string) $name, $loop['rows'], $loop['rowFn']);
        }

        $tpl = self::conditionals($tpl, $conditions);

        return self::tokens($tpl, $escaped, $raw);
    }
}
