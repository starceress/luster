<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Formats date and datetime strings for use in embed template tokens. The
 default format is "Month Dth Year" (e.g. "March 1st 2026") with correct
 English ordinal suffixes. Also provides a configurable format mode and a
 helper that picks the most recent of two nullable date strings.
*/

declare(strict_types=1);

namespace Luster\Support;

/**
 * Formats date/datetime strings for embed template tokens.
 */
final class DateFormatter
{
    /**
     * Formats a date or datetime string as "Month Dth Year" (e.g. "March 1st 2026").
     * Returns the original string unchanged if it cannot be parsed.
     */
    public static function format(string $dateStr): string
    {
        $ts = strtotime($dateStr);

        if ($ts === false) {
            return $dateStr;
        }

        $day    = (int) date('j', $ts);
        $suffix = match (true) {
            $day >= 11 && $day <= 13 => 'th',   // 11th, 12th, 13th
            $day % 10 === 1          => 'st',
            $day % 10 === 2          => 'nd',
            $day % 10 === 3          => 'rd',
            default                  => 'th',
        };

        return date('F', $ts) . ' ' . $day . $suffix . ' ' . date('Y', $ts);
    }

    /**
     * Formats a date string using a caller-supplied format string.
     *
     * - ''        → returns $dateStr as-is (no formatting)
     * - 'ordinal' → delegates to self::format() (e.g. "March 1st 2026")
     * - any other → passed to date() as a PHP format string
     *
     * Falls back to the raw string if the date cannot be parsed.
     */
    public static function formatWith(string $dateStr, string $phpFormat): string
    {
        if ($phpFormat === '') {
            return $dateStr;
        }

        if ($phpFormat === 'ordinal') {
            return self::format($dateStr);
        }

        $ts = strtotime($dateStr);

        return $ts === false ? $dateStr : date($phpFormat, $ts);
    }

    /**
     * Returns whichever of two nullable date/datetime strings is chronologically
     * later. If one is null or unparseable, returns the other. Returns null if
     * both are null or unparseable.
     */
    public static function mostRecent(?string $a, ?string $b): ?string
    {
        $aTs = $a !== null ? strtotime($a) : false;
        $bTs = $b !== null ? strtotime($b) : false;

        if ($aTs === false) {
            return $bTs === false ? null : $b;
        }

        if ($bTs === false) {
            return $a;
        }

        return $bTs > $aTs ? $b : $a;
    }
}
