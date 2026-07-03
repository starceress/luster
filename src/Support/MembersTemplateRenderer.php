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
        string $sortLinkAll = '',
        string $sortLinkName = '',
        string $sortLinkCountry = '',
    ): string {
        $e = static fn(string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        // Not in country-sort mode: keep {if_no_country_sort} content, remove {if_country_sort} blocks
        $template = preg_replace('/\{if_country_sort\}.*?\{\/if_country_sort\}/s', '', $template) ?? $template;
        $template = str_replace(['{if_no_country_sort}', '{/if_no_country_sort}'], '', $template);

        $buildTokens = function (Member $member) use ($e, $fields): array {
            $tokens = [
                '{fan_name}'    => $e($member->name),
                '{fan_url}'     => $e($member->url     ?? ''),
                '{fan_email}'   => $e($member->email),
                '{fan_country}' => $e($member->country ?? ''),
            ];
            foreach ($fields as $field) {
                $val = (string) ($member->additionalFields[$field->name] ?? '');
                $tokens['{fan_' . $field->name . '}'] = $e($val);
            }
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

            // Page-level conditionals
            if ($total > 0) {
                $template = preg_replace('/\{if_no_members\}.*?\{\/if_no_members\}/s', '', $template) ?? $template;
                $template = str_replace(['{if_members}', '{/if_members}'], '', $template);
            } else {
                $template = preg_replace('/\{if_members\}.*?\{\/if_members\}/s', '', $template) ?? $template;
                $template = str_replace(['{if_no_members}', '{/if_no_members}'], '', $template);
            }

            // Page-level tokens
            return str_replace(
                ['{join_url}', '{member_count}', '{stats_line}', '{sort_link_all}', '{sort_link_name}', '{sort_link_country}'],
                [$e($joinUrl), (string) $total, $e($statsLine), $sortLinkAll, $sortLinkName, $sortLinkCountry],
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
        string $sortLinkAll = '',
        string $sortLinkName = '',
        string $sortLinkCountry = '',
    ): string {
        $e = static fn(string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

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
            ['{join_url}', '{member_count}', '{stats_line}', '{sort_link_all}', '{sort_link_name}', '{sort_link_country}'],
            [$e($joinUrl), (string) $total, $e($statsLine), $sortLinkAll, $sortLinkName, $sortLinkCountry],
            $template,
        );
    }

    /**
     * @param AdditionalField[] $fields
     */
    private static function applyRowConditionals(string $row, Member $member, array $fields = []): string
    {
        $hasUrl     = ($member->url     ?? '') !== '';
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

        foreach ($fields as $field) {
            $val = (string) ($member->additionalFields[$field->name] ?? '');
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
