<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Renders the Previous Owners public tokens ({previous_owners}, {previous_owners_count},
 {if_previous_owners}, {each_previous_owner}) against the Stats template. Shared by the
 live public embed (public/embed/stats.php) and both admin Stats preview surfaces
 (ListingController::ltplPreviewStats()/gtplPreviewStats(), via renderStatsTemplate())
 so all three stay in sync — the same reason ListingRowRenderer centralises owned-listing
 tokens.

 Must be used as a shield()-before / resolve()-after pair bracketing the normal stats
 token render, never as a single before-or-after pass. The risk runs both directions:
 splicing owner-derived HTML in before the stats pass lets a literal "{stat_approved}"
 in an owner's name get rewritten by that pass; running a single previous-owner pass
 strictly after the stats render instead lets a literal "{previous_owners}" in a listing
 title or member name get wrongly matched by THIS pass. shield() swaps only the
 previous-owner delimiter/flat-token strings for internal sentinels *before* the stats
 pass runs — leaving block bodies (and any normal stats tokens nested inside them, e.g.
 {if_previous_owners}{listing_title}...{/if_previous_owners}) live for that pass — so
 resolve() can safely recover and finish them afterward without either direction of
 re-scan corruption.
*/

declare(strict_types=1);

namespace Luster\Support;

final class PreviousOwnersRenderer
{
    // Control-character-wrapped sentinels: cannot be typed through a normal browser
    // text field, so they cannot collide with real listing/member/owner data during
    // the stats pass that runs between shield() and resolve().
    private const IF_OPEN     = "\x01LSTR_PO_IF_OPEN\x01";
    private const IF_CLOSE    = "\x01LSTR_PO_IF_CLOSE\x01";
    private const EACH_OPEN   = "\x01LSTR_PO_EACH_OPEN\x01";
    private const EACH_CLOSE  = "\x01LSTR_PO_EACH_CLOSE\x01";
    private const LIST_MARKER  = "\x01LSTR_PO_LIST\x01";
    private const COUNT_MARKER = "\x01LSTR_PO_COUNT\x01";

    private function __construct() {}

    /**
     * Phase 0 — call BEFORE the normal stats token render, on the raw template
     * shell. Swaps only the previous-owner delimiter/flat-token strings for
     * sentinels; everything between a shielded {if_previous_owners}/
     * {each_previous_owner} pair — including any normal stats tokens a site
     * owner nested there — is left untouched, still live template text.
     */
    public static function shield(string $template): string
    {
        return str_replace(
            [
                '{if_previous_owners}', '{/if_previous_owners}',
                '{each_previous_owner}', '{/each_previous_owner}',
                '{previous_owners}', '{previous_owners_count}',
            ],
            [
                self::IF_OPEN, self::IF_CLOSE,
                self::EACH_OPEN, self::EACH_CLOSE,
                self::LIST_MARKER, self::COUNT_MARKER,
            ],
            $template,
        );
    }

    /**
     * Phase 3 — call AFTER the normal stats token render has fully completed,
     * as the true final step. Nothing may run on $html after this.
     *
     * @param array<int, array{owner_name: string, owner_url: string}> $owners
     */
    public static function resolve(string $html, array $owners): string
    {
        $hasOwners = $owners !== [];

        // a. {if_previous_owners}...{/if_previous_owners} — the inner span was
        // already fully stats-rendered by the pass that ran between shield()
        // and resolve(), so any {listing_title} etc. nested inside it already
        // resolved normally.
        $html = (string) preg_replace_callback(
            '/' . preg_quote(self::IF_OPEN, '/') . '(.*?)' . preg_quote(self::IF_CLOSE, '/') . '/s',
            static fn(array $m): string => $hasOwners ? $m[1] : '',
            $html,
        );

        // b. {previous_owners_count} — plain integer, no owner text involved.
        $html = str_replace(self::COUNT_MARKER, (string) count($owners), $html);

        // c. {each_previous_owner}...{/each_previous_owner} — the recovered
        // inner span is the row template (also already stats-rendered, but
        // {previous_owner_*} tokens survived untouched since they aren't
        // stats token names). Render it once per owner, reusing the same
        // captured row template for every row.
        $html = (string) preg_replace_callback(
            '/' . preg_quote(self::EACH_OPEN, '/') . '(.*?)' . preg_quote(self::EACH_CLOSE, '/') . '/s',
            static function (array $m) use ($owners): string {
                $rowTpl = $m[1];
                $parts  = [];
                foreach ($owners as $owner) {
                    $parts[] = self::renderRow($rowTpl, $owner);
                }
                return implode('', $parts);
            },
            $html,
        );

        // d. {previous_owners} — flat comma-separated list, built the same way
        // as each loop row: linked when a URL is present, plain escaped text
        // otherwise.
        $html = str_replace(self::LIST_MARKER, self::buildFlatList($owners), $html);

        return $html;
    }

    /**
     * @param array{owner_name: string, owner_url: string} $owner
     */
    private static function renderRow(string $rowTpl, array $owner): string
    {
        $name = (string) $owner['owner_name'];
        $url  = (string) $owner['owner_url'];

        // (i) Paired conditional first — a single strtr() can't express "strip
        // this block when false" — resolved on the row's raw (owner-data-free)
        // template text.
        $rowTpl = (string) preg_replace_callback(
            '/\{if_previous_owner_url\}(.*?)\{\/if_previous_owner_url\}/s',
            static fn(array $m): string => $url !== '' ? $m[1] : '',
            $rowTpl,
        );

        // (ii) THEN the flat tokens, via one strtr() call — the last thing done
        // to this row, so owner text is never re-scanned afterward.
        $label = $name !== '' ? $name : $url;

        return strtr($rowTpl, [
            '{previous_owner_name}' => htmlspecialchars($label, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            '{previous_owner_url}'  => htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
        ]);
    }

    /**
     * @param array<int, array{owner_name: string, owner_url: string}> $owners
     */
    private static function buildFlatList(array $owners): string
    {
        $parts = [];
        foreach ($owners as $owner) {
            $name  = (string) $owner['owner_name'];
            $url   = (string) $owner['owner_url'];
            $label = htmlspecialchars($name !== '' ? $name : $url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

            if ($url !== '') {
                $parts[] = '<a href="' . htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '">' . $label . '</a>';
            } else {
                $parts[] = $label;
            }
        }

        return implode(', ', $parts);
    }
}
