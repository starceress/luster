<?php

declare(strict_types=1);

namespace Luster\Support;

use Luster\Core\TemplateEngine;

/**
 * Renders a custom codes_template / collective_codes_template string against a
 * set of code records.
 *
 * Two layout modes, detected by which loop token the template contains:
 *   Grouped: {each_size_group}...{/each_size_group} wraps an inner
 *     {each_code}...{/each_code} — codes are grouped by detected dimensions.
 *   Flat: a single {each_code}...{/each_code} loop over all codes, with
 *     {if_size_changed}...{/if_size_changed} available per row.
 *
 * Per-code tokens (inside {each_code}):
 *   {code_url}, {code_alt}, {code_width}, {code_height}, {code_size},
 *   {code_name}, {code_categories}, {code_donor_name}, {code_donor_url}
 *
 * Per-code conditionals:
 *   {if_size}...{/if_size}, {if_size_changed}...{/if_size_changed},
 *   {if_donor}...{/if_donor}, {if_no_donor}...{/if_no_donor},
 *   {if_donor_url}...{/if_donor_url}, {if_no_donor_url}...{/if_no_donor_url},
 *   {if_categories}...{/if_categories}, {if_code_name}...{/if_code_name}
 *
 * Per-group tokens (grouped mode only, inside {each_size_group}):
 *   {group_size}, {group_code_count}
 *
 * Page-level conditionals:
 *   {if_codes}...{/if_codes} — shown when at least one code is present
 *   {if_no_codes}...{/if_no_codes} — shown when there are no codes
 *
 * Shared by public/embed/codes.php (live rendering, per-listing and
 * collective) and ListingController's Templates-tab preview, so the preview
 * always matches the live page.
 */
final class CodesTemplateRenderer
{
    /**
     * @param object[]                                              $codes
     * @param array<string, array{label: string, codes: object[]}> $grouped
     * @param array<int, int[]>                                     $catMap
     * @param array<int, object>                                    $categoryMap
     * @param array<int, object>                                    $donorMap
     *
     * @return ?string null when the template contains neither {each_size_group}
     *                  nor {each_code} — i.e. it isn't a recognised codes template
     */
    public static function render(
        string $tpl,
        array $codes,
        array $grouped,
        array $catMap,
        array $categoryMap,
        array $donorMap,
        string $uploadsUrl,
        string $listingName,
    ): ?string {
        if (!str_contains($tpl, '{each_size_group}') && !str_contains($tpl, '{each_code}')) {
            return null;
        }

        $renderCode = static function (
            string $inner,
            object $code,
            bool   $sizeChanged,
        ) use ($catMap, $categoryMap, $donorMap, $uploadsUrl, $listingName): string {
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
            $altText   = $donorName !== null
                ? 'Donated by ' . $donorName
                : ($sizeLabel !== '' ? $sizeLabel : $listingName . ' code');

            $catIds   = $catMap[$code->id] ?? [];
            $catNames = [];
            foreach ($catIds as $catId) {
                if (isset($categoryMap[$catId])) {
                    $catNames[] = $categoryMap[$catId]->name;
                }
            }

            return TemplateEngine::render(
                $inner,
                [
                    'code_url'        => $uploadsUrl . '/' . $code->filename,
                    'code_alt'        => $altText,
                    'code_width'      => $hasSize ? (string) (int) $code->width : '',
                    'code_height'     => $hasSize ? (string) (int) $code->height : '',
                    'code_size'       => $sizeLabel,
                    'code_name'       => (string) ($code->name ?? ''),
                    'code_categories' => implode(', ', $catNames),
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
                    'categories'   => count($catNames) > 0,
                    'code_name'    => ($code->name ?? '') !== '',
                ],
            );
        };

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
                        static fn(string $inner, object $code): string => $renderCode($inner, $code, false),
                    );
                },
            );
        } else {
            $prevSizeKey = null;
            $html = TemplateEngine::loop(
                $tpl,
                'code',
                $codes,
                static function (string $inner, object $code) use (&$prevSizeKey, $renderCode): string {
                    $sizeKey     = ($code->width > 0 && $code->height > 0)
                        ? ((int) $code->width . 'x' . (int) $code->height)
                        : 'unknown';
                    $sizeChanged = $sizeKey !== $prevSizeKey;
                    $prevSizeKey = $sizeKey;
                    return $renderCode($inner, $code, $sizeChanged);
                },
            );
        }

        return TemplateEngine::conditionals($html, [
            'codes'    => count($codes) > 0,
            'no_codes' => count($codes) === 0,
        ]);
    }
}
