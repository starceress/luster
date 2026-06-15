<?php

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
