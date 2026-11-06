<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Writes out what a fan just changed on a public form, for the {changes} token in
 the emails that tell the owner about it. Passwords are only ever "changed".
*/

declare(strict_types=1);

namespace Luster\Support;

/**
 * Turns the columns a form is about to write into lines of plain text, such as
 * "Name: Old -> New". Only the owner gets these, so a fan's address can be in them.
 */
final class MemberChangeList
{
    public const REMOVED_MEMBERSHIP = 'Removed their membership';
    public const REMOVED_KIM_ENTRY  = 'Removed their entry';

    private const WAITING = ' (waiting for your approval)';

    private const LABELS = ['name' => 'Name', 'email' => 'Email', 'url' => 'Website', 'country' => 'Country'];

    /** Never written out: the password's hash, and switches that follow from another change. */
    private const NOT_SHOWN = ['password', 'showurl', 'showemail'];

    /**
     * One line per changed column, then one for the password if it changed. A change
     * of password never waits for approval, so its line never says it's waiting.
     *
     * @param array<string, mixed>  $changes     Column => new value.
     * @param array<string, mixed>  $before      Column => what it was.
     * @param array<string, string> $fieldLabels Column => the label of an additional field.
     * @return list<string>
     */
    public static function lines(
        array $changes,
        array $before,
        array $fieldLabels = [],
        bool $passwordChanged = false,
        bool $waiting = false,
    ): array {
        $lines = [];
        foreach ($changes as $column => $value) {
            $column = (string) $column;
            if (in_array($column, self::NOT_SHOWN, true)) {
                continue;
            }
            $label   = self::LABELS[$column] ?? $fieldLabels[$column] ?? $column;
            $lines[] = $label . ': ' . self::shown($before[$column] ?? '') . ' -> ' . self::shown($value)
                . ($waiting ? self::WAITING : '');
        }
        if ($passwordChanged) {
            $lines[] = 'Password: changed';
        }

        return $lines;
    }

    /**
     * The {changes} text for a notice about one listing.
     *
     * @param list<string> $lines
     */
    public static function forOneListing(array $lines): string
    {
        return implode("\n", array_map(static fn(string $line): string => '  ' . $line, $lines));
    }

    /**
     * The {changes} text for a notice about several listings.
     *
     * @param list<array{heading: string, lines: list<string>}> $groups
     */
    public static function forSeveralListings(array $groups): string
    {
        $text = [];
        foreach ($groups as $group) {
            $text[] = '- ' . $group['heading'];
            foreach ($group['lines'] as $line) {
                $text[] = '    ' . $line;
            }
        }

        return implode("\n", $text);
    }

    private static function shown(mixed $value): string
    {
        $text = is_scalar($value) ? trim((string) $value) : '';

        return $text !== '' ? $text : '(blank)';
    }
}
