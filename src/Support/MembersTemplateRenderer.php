<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Renders an admin-defined members list template against a set of member records.
 Supports two modes: new-style templates with an {each_member} loop block and
 page-level tokens (member count, sort links, stats line), and legacy templates
 where the entire string is repeated per member for backward compatibility.
 Per-member tokens cover name, URL, email, country, and any dynamic additional
 fields. Shared by the public members embed and the admin Templates-tab preview.
*/

declare(strict_types=1);

namespace Luster\Support;

use Luster\Models\AdditionalField;
use Luster\Models\Member;

final class MembersTemplateRenderer
{
    private function __construct()
    {
    }

    /**
     * @param Member[]          $members
     * @param AdditionalField[] $fields
     */
    public static function render(
        string $template,
        array  $members,
        array  $fields,
        string $joinUrl,
        int    $total,
        string $statsLine = '',
        string $sortLinkDate = '',
        string $sortLinkName = '',
        string $sortLinkCountry = '',
        bool   $showSearch = true,
        string $dateFormat = '',
        bool   $isDateSort = false,
    ): string {
        $e = static fn(string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        $template = self::applyShowSearchConditional($template, $showSearch);
        $template = self::applyDateSortConditional($template, $isDateSort);

        // Not in country-sort mode: keep {if_no_country_sort} content, remove {if_country_sort} blocks
        $template = preg_replace('/\{if_country_sort\}.*?\{\/if_country_sort\}/s', '', $template) ?? $template;
        $template = str_replace(['{if_no_country_sort}', '{/if_no_country_sort}'], '', $template);

        $buildTokens = function (Member $member) use ($e, $fields, $dateFormat): array {
            $tokens = [
                '{fan_name}'    => $e($member->name),
                '{fan_url}'     => $e($member->showurl ? ($member->url ?? '') : ''),
                '{fan_email}'   => $e($member->email),
                '{fan_country}' => $e($member->country ?? ''),
                // No explicit format ("Automatic") falls back to the ordinal style
                // (matching public/embed/stats.php's own no-override default) rather
                // than the raw stored datetime string.
                '{fan_added}'   => $e($dateFormat === ''
                    ? DateFormatter::format($member->added ?? '')
                    : DateFormatter::formatWith($member->added ?? '', $dateFormat)),
            ];
            $answers = $member->answers($fields);
            foreach ($fields as $field) {
                $tokens['{fan_' . $field->name . '}'] = $e($answers[$field->name] ?? '');
            }
            // These aggregate tokens always take the key, even for the rare
            // pre-existing/imported field literally named "additional_fields" or
            // "additional_field_cells" (FieldController blocks creating new fields
            // with those names, but can't retroactively rename old ones). That
            // field's value is not lost — renderAdditionalFieldsBlock()/
            // renderAdditionalFieldCells() below still include it as one of the
            // label:value pairs in the aggregate output, same as any other field.
            // Only its own standalone {fan_<name>} addressing is unavailable,
            // which is the whole point of the name being reserved.
            $tokens['{fan_additional_fields}']      = self::renderAdditionalFieldsBlock($member, $fields, $e);
            $tokens['{fan_additional_field_cells}'] = self::renderAdditionalFieldCells($member, $fields, $e);
            return $tokens;
        };

        if (preg_match('/\{each_member\}(.*?)\{\/each_member\}/s', $template, $m)) {
            // New-style: loop block extracted, outer wrapper rendered once
            $rowTpl   = $m[1];
            $rendered = '';
            foreach ($members as $member) {
                $row       = self::applyRowConditionals($rowTpl, $member, $fields);
                $rendered .= str_replace(array_keys($buildTokens($member)), array_values($buildTokens($member)), $row);
            }
            $template = (string) preg_replace('/\{each_member\}.*?\{\/each_member\}/s', $rendered, $template, 1);

            // Page-level conditionals — based on whether the current page actually
            // has rows, not the grand total, so an out-of-range ?page= shows the
            // empty state instead of a blank {each_member} block (matches the
            // no-custom-template fallback in templates/default/members.php).
            if (count($members) > 0) {
                $template = preg_replace('/\{if_no_members\}.*?\{\/if_no_members\}/s', '', $template) ?? $template;
                $template = str_replace(['{if_members}', '{/if_members}'], '', $template);
            } else {
                $template = preg_replace('/\{if_members\}.*?\{\/if_members\}/s', '', $template) ?? $template;
                $template = str_replace(['{if_no_members}', '{/if_no_members}'], '', $template);
            }

            // Page-level tokens
            return str_replace(
                ['{join_url}', '{member_count}', '{stats_line}', '{sort_link_date}', '{sort_link_name}', '{sort_link_country}', '{additional_field_headers}'],
                [$e($joinUrl), (string) $total, $e($statsLine), $sortLinkDate, $sortLinkName, $sortLinkCountry, self::renderFieldHeaders($fields, $e)],
                $template,
            );
        }

        // Legacy: entire template is per-row; concatenate rows
        $rows = [];
        foreach ($members as $member) {
            $row    = self::applyRowConditionals($template, $member, $fields);
            $tokens = $buildTokens($member);
            $rows[] = str_replace(array_keys($tokens), array_values($tokens), $row);
        }
        return implode("\n", $rows);
    }

    /**
     * Renders the template's page-level shell (sort links, conditionals, tokens)
     * with custom inner HTML in place of the {each_member} block.
     *
     * Used for the country summary view where there are no individual members
     * but the custom template's sort bar and layout should still apply.
     */
    public static function renderWithBody(
        string $template,
        string $bodyHtml,
        string $joinUrl,
        int    $total,
        string $statsLine = '',
        string $sortLinkDate = '',
        string $sortLinkName = '',
        string $sortLinkCountry = '',
        array  $fields = [],
        bool   $showSearch = true,
    ): string {
        $e = static fn(string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        $template = self::applyShowSearchConditional($template, $showSearch);
        // Country summary is never date-sorted — the summary rows have no
        // individual join dates to show, only per-country counts.
        $template = self::applyDateSortConditional($template, false);

        $template = (string) preg_replace('/\{each_member\}.*?\{\/each_member\}/s', $bodyHtml, $template, 1);

        // In country-sort mode: keep {if_country_sort} content, remove {if_no_country_sort} blocks
        $template = preg_replace('/\{if_no_country_sort\}.*?\{\/if_no_country_sort\}/s', '', $template) ?? $template;
        $template = str_replace(['{if_country_sort}', '{/if_country_sort}'], '', $template);

        if ($total > 0) {
            $template = preg_replace('/\{if_no_members\}.*?\{\/if_no_members\}/s', '', $template) ?? $template;
            $template = str_replace(['{if_members}', '{/if_members}'], '', $template);
        } else {
            $template = preg_replace('/\{if_members\}.*?\{\/if_members\}/s', '', $template) ?? $template;
            $template = str_replace(['{if_no_members}', '{/if_no_members}'], '', $template);
        }

        return str_replace(
            ['{join_url}', '{member_count}', '{stats_line}', '{sort_link_date}', '{sort_link_name}', '{sort_link_country}', '{additional_field_headers}'],
            [$e($joinUrl), (string) $total, $e($statsLine), $sortLinkDate, $sortLinkName, $sortLinkCountry, self::renderFieldHeaders($fields, $e)],
            $template,
        );
    }

    /**
     * Resolves {if_show_search}...{/if_show_search} /
     * {if_no_show_search}...{/if_no_show_search} blocks against the caller's
     * show-search setting, matching templates/default/members.php's
     * `$show_search` gate on the sort-controls markup so a custom or default
     * template can hide its own sort bar the same way the PHP fallback does.
     */
    private static function applyShowSearchConditional(string $template, bool $showSearch): string
    {
        if ($showSearch) {
            $template = preg_replace('/\{if_no_show_search\}.*?\{\/if_no_show_search\}/s', '', $template) ?? $template;
            return str_replace(['{if_show_search}', '{/if_show_search}'], '', $template);
        }

        $template = preg_replace('/\{if_show_search\}.*?\{\/if_show_search\}/s', '', $template) ?? $template;
        return str_replace(['{if_no_show_search}', '{/if_no_show_search}'], '', $template);
    }

    /**
     * Resolves {if_sort_date}...{/if_sort_date} / {if_no_sort_date}...{/if_no_sort_date}
     * blocks against whether the member list is currently sorted by join date.
     * Applied once to the whole template string before {each_member} is
     * extracted, so it works identically whether the wrapped content sits
     * inside or outside the loop (e.g. a table's "Joined" header outside the
     * loop and the matching {fan_added} cell inside it both resolve from the
     * same pass). The built-in defaults use this so the "Joined" column/date
     * only appears when Date Joined is the active sort — showing a join date
     * next to a Country- or Name-sorted list is meaningless clutter.
     */
    private static function applyDateSortConditional(string $template, bool $isDateSort): string
    {
        if ($isDateSort) {
            $template = preg_replace('/\{if_no_sort_date\}.*?\{\/if_no_sort_date\}/s', '', $template) ?? $template;
            return str_replace(['{if_sort_date}', '{/if_sort_date}'], '', $template);
        }

        $template = preg_replace('/\{if_sort_date\}.*?\{\/if_sort_date\}/s', '', $template) ?? $template;
        return str_replace(['{if_no_sort_date}', '{/if_no_sort_date}'], '', $template);
    }

    /**
     * Builds the {fan_additional_fields} block: one .fl-member-field span per
     * field with a non-empty value, matching the markup templates/default/members.php
     * uses for its built-in (no-custom-template) fallback rendering, so the two
     * paths look identical whether or not a template has been saved to the DB.
     *
     * @param AdditionalField[] $fields
     */
    private static function renderAdditionalFieldsBlock(Member $member, array $fields, callable $e): string
    {
        $blocks  = [];
        $answers = $member->answers($fields);
        foreach ($fields as $field) {
            $val = $answers[$field->name] ?? '';
            if ($val === '') {
                continue;
            }
            $blocks[] = '<span class="fl-member-field">'
                . '<span class="fl-member-field-label">' . $e($field->label) . ':</span> '
                . '<span class="fl-member-field-value">' . $e($val) . '</span>'
                . '</span>';
        }
        return implode("\n", $blocks);
    }

    /**
     * Builds the {fan_additional_field_cells} block for table-style templates:
     * one .fl-member-table-cell per field, value only (no inline label — the
     * label belongs in the header row, see renderFieldHeaders()). Blank values
     * render as "&ndash;" to match the existing name/country cell convention
     * so table columns stay aligned across rows.
     *
     * @param AdditionalField[] $fields
     */
    private static function renderAdditionalFieldCells(Member $member, array $fields, callable $e): string
    {
        $cells   = [];
        $answers = $member->answers($fields);
        foreach ($fields as $field) {
            $val     = $answers[$field->name] ?? '';
            $cells[] = '<span class="fl-member-table-cell">' . ($val !== '' ? $e($val) : '&ndash;') . '</span>';
        }
        return implode("\n", $cells);
    }

    /**
     * Builds the {additional_field_headers} page-level token: one .fl-member-table-th
     * per field, labelled from the field definition. Rendered once per template
     * (not per member) — pairs with renderAdditionalFieldCells() so table-style
     * templates get a proper header row instead of repeating the label on every row.
     *
     * @param AdditionalField[] $fields
     */
    private static function renderFieldHeaders(array $fields, callable $e): string
    {
        $headers = [];
        foreach ($fields as $field) {
            $headers[] = '<span class="fl-member-table-th">' . $e($field->label) . '</span>';
        }
        return implode("\n", $headers);
    }

    /**
     * @param AdditionalField[] $fields
     */
    private static function applyRowConditionals(string $row, Member $member, array $fields = []): string
    {
        $hasUrl     = $member->showurl && ($member->url ?? '') !== '';
        $hasCountry = ($member->country ?? '') !== '';

        if ($hasUrl) {
            $row = preg_replace('/\{if_no_fan_url\}.*?\{\/if_no_fan_url\}/s', '', $row) ?? $row;
            $row = str_replace(['{if_fan_url}', '{/if_fan_url}'], '', $row);
        } else {
            $row = preg_replace('/\{if_fan_url\}.*?\{\/if_fan_url\}/s', '', $row) ?? $row;
            $row = str_replace(['{if_no_fan_url}', '{/if_no_fan_url}'], '', $row);
        }

        if ($hasCountry) {
            $row = preg_replace('/\{if_no_fan_country\}.*?\{\/if_no_fan_country\}/s', '', $row) ?? $row;
            $row = str_replace(['{if_fan_country}', '{/if_fan_country}'], '', $row);
        } else {
            $row = preg_replace('/\{if_fan_country\}.*?\{\/if_fan_country\}/s', '', $row) ?? $row;
            $row = str_replace(['{if_no_fan_country}', '{/if_no_fan_country}'], '', $row);
        }

        $answers = $member->answers($fields);

        $hasAdditionalFields = false;
        foreach ($fields as $field) {
            if (($answers[$field->name] ?? '') !== '') {
                $hasAdditionalFields = true;
                break;
            }
        }

        if ($hasAdditionalFields) {
            $row = preg_replace('/\{if_no_fan_additional_fields\}.*?\{\/if_no_fan_additional_fields\}/s', '', $row) ?? $row;
            $row = str_replace(['{if_fan_additional_fields}', '{/if_fan_additional_fields}'], '', $row);
        } else {
            $row = preg_replace('/\{if_fan_additional_fields\}.*?\{\/if_fan_additional_fields\}/s', '', $row) ?? $row;
            $row = str_replace(['{if_no_fan_additional_fields}', '{/if_no_fan_additional_fields}'], '', $row);
        }

        foreach ($fields as $field) {
            $val = $answers[$field->name] ?? '';
            $tag = preg_quote($field->name, '/');
            if ($val !== '') {
                $row = preg_replace('/\{if_no_fan_' . $tag . '\}.*?\{\/if_no_fan_' . $tag . '\}/s', '', $row) ?? $row;
                $row = str_replace(['{if_fan_' . $field->name . '}', '{/if_fan_' . $field->name . '}'], '', $row);
            } else {
                $row = preg_replace('/\{if_fan_' . $tag . '\}.*?\{\/if_fan_' . $tag . '\}/s', '', $row) ?? $row;
                $row = str_replace(['{if_no_fan_' . $field->name . '}', '{/if_no_fan_' . $field->name . '}'], '', $row);
            }
        }

        return $row;
    }
}
