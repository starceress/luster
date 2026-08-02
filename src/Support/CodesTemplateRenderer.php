<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Renders an admin-defined codes display template against a set of code records.
 Supports two layout modes: grouped (codes organized by dimensions inside
 {each_size_group} blocks) and flat (a single {each_code} loop with
 {if_size_changed} markers). Handles per-code tokens for URL, dimensions, donor
 info, and category, plus page-level conditionals for empty/non-empty states.
 Shared by the public codes embed and the admin Templates-tab preview.
*/

declare(strict_types=1);

namespace Luster\Support;

use Luster\Core\TemplateEngine;

final class CodesTemplateRenderer
{
    /**
     * @param object[]                                              $codes
     * @param array<string, array{label: string, codes: object[]}> $grouped
     * @param array<int, object>                                    $donorMap
     * @param array<int, int[]>                                     $catMap      code_id => [cat_id, ...]
     * @param array<int, object>                                    $categoryMap cat_id => category object
     * @param string                                                $listingName listing title for alt fallback
     *
     * @return ?string null when the template contains neither {each_size_group}
     *                  nor {each_code} — i.e. it isn't a recognised codes template
     */
    public static function render(
        string $tpl,
        array $codes,
        array $grouped,
        array $donorMap,
        string $uploadsUrl,
        array $catMap = [],
        array $categoryMap = [],
        string $listingName = '',
    ): ?string {
        if (!str_contains($tpl, '{each_size_group}') && !str_contains($tpl, '{each_code}')) {
            return null;
        }

        $prevSizeKey  = null;
        $isGrouped   = str_contains($tpl, '{each_size_group}');

        $renderCode = static function (
            string $inner,
            object $code,
        ) use ($donorMap, $uploadsUrl, $catMap, $categoryMap, $listingName, &$prevSizeKey, $isGrouped): string {
            $dnrObj    = ($code->donorId !== null) ? ($donorMap[$code->donorId] ?? null) : null;
            $donorName = $code->submitterName ?? ($dnrObj !== null ? $dnrObj->name : null);
            $donorUrl  = '';
            if (($code->submitterUrl ?? '') !== '') {
                $donorUrl = (string) $code->submitterUrl;
            } elseif ($dnrObj !== null && ($dnrObj->url ?? '') !== '') {
                $donorUrl = (string) $dnrObj->url;
            }

            $hasSize   = $code->width > 0 && $code->height > 0;
            $sizeLabel = $hasSize ? ((int) $code->width . '×' . (int) $code->height) : '';
            $sizeKey   = $hasSize ? ((int) $code->width . 'x' . (int) $code->height) : 'unknown';

            $sizeChanged   = !$isGrouped && $sizeKey !== $prevSizeKey;
            $prevSizeKey   = $sizeKey;

            return TemplateEngine::render(
                $inner,
                [
                    'code_url'        => $uploadsUrl . '/' . $code->filename,
                    'code_width'      => $hasSize ? (string) (int) $code->width : '',
                    'code_height'     => $hasSize ? (string) (int) $code->height : '',
                    'code_size'       => $sizeLabel,
                    'code_name'       => (string) ($code->name ?? ''),
                    'code_donor_name' => (string) ($donorName ?? ''),
                    'code_donor_url'  => $donorUrl,
                ],
                [],
                [
                    'size'         => $hasSize,
                    'size_changed' => $sizeChanged,
                    'donor'        => $donorName !== null,
                    'no_donor'     => $donorName === null,
                    'donor_url'    => $donorUrl !== '',
                    'no_donor_url' => $donorUrl === '',
                    'code_name'    => ($code->name ?? '') !== '',
                ],
            );
        };

        // conditionals() runs BEFORE loop() - 'codes'/'no_codes' don't depend
        // on loop output, and evaluating them first means the {if_codes}/
        // {if_no_codes} regex never scans a string that already has
        // attacker-controlled donor names spliced into it. Running it after
        // the loop instead would let a crafted code_donor_name (from the
        // public codes-submission form) contain literal {if_codes}/
        // {/if_codes} text that hijacks the real block boundary.
        $tpl = TemplateEngine::conditionals($tpl, [
            'codes'    => count($codes) > 0,
            'no_codes' => count($codes) === 0,
        ]);

        if (str_contains($tpl, '{each_size_group}')) {
            $html = TemplateEngine::loop(
                $tpl,
                'size_group',
                array_values($grouped),
                static function (string $innerGroupTpl, array $group) use ($renderCode): string {
                    $processed = TemplateEngine::tokens($innerGroupTpl, [
                        'group_size'       => $group['label'],
                        'group_code_count' => (string) count($group['codes']),
                    ]);
                    return TemplateEngine::loop(
                        $processed,
                        'code',
                        $group['codes'],
                        static fn(string $inner, object $code): string => $renderCode($inner, $code),
                    );
                },
            );
        } else {
            $prevSizeKey = null;
            $html = TemplateEngine::loop(
                $tpl,
                'code',
                $codes,
                static fn(string $inner, object $code): string => $renderCode($inner, $code),
            );
        }

        return $html;
    }
}
