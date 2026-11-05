<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Checks a member row against what its real table can hold, for the forms that write one.
 Real member tables are often narrower than the forms allow, and on a non-strict server
 a value that's too long is silently cut short, which for an email address means the
 member can't be found by it anymore.
*/

declare(strict_types=1);

namespace Luster\Support;

use Luster\Models\AdditionalField;
use Luster\Models\Listing;
use Luster\Repositories\MemberRepo;

/**
 * This runs before anything is written and before any credential is used, so a value
 * that won't fit can't leave half a change behind. If the table can't be read, that's a
 * refusal too, because the forms' own limits aren't known to be safe.
 */
final class MemberWidths
{
    /**
     * Dates Luster stamps itself, which this check leaves out. Whoever's filling in the
     * form can't do anything about them, and every member table Luster creates gives them
     * a DATE or DATETIME column, which has no text limit anyway. It only matters for a
     * hand-made text column too short for the time: a non-strict server saves just the
     * day, and a strict one stops the write with an error.
     */
    private const OWN_STAMPS = ['added' => true, 'updated' => true];

    private function __construct() {}

    /**
     * @param array<string, mixed> $row Column => value, everything about to be written.
     * @return list<string>|null The columns that are too long, or null if the table can't be read.
     */
    public static function tooLong(MemberRepo $repo, Listing $listing, array $row, string $form): ?array
    {
        try {
            $widths = $repo->columnWidths($listing->dbprefix);
        } catch (\RuntimeException | \InvalidArgumentException $e) {
            error_log("[Luster] {$form}: couldn't read the member table for listing #{$listing->id}: " . $e->getMessage());
            return null;
        }

        return MemberRepo::tooLong($widths, array_diff_key($row, self::OWN_STAMPS));
    }

    /**
     * What a public form should say if this row can't be written, or null if it can.
     * A fan can shorten what they typed. They can't do anything about a password column
     * that's too narrow for the hash, so that one's logged for the owner to find.
     *
     * @param array<string, mixed> $row
     * @param AdditionalField[]    $fields
     * @param string               $where What to call the listing, for a form covering several.
     */
    public static function publicProblem(MemberRepo $repo, Listing $listing, array $row, array $fields, string $form, string $where = 'this listing'): ?string
    {
        $tooLong = self::tooLong($repo, $listing, $row, $form);
        if ($tooLong === null) {
            return FieldAnswers::PUBLIC_REFUSAL;
        }
        if ($tooLong === []) {
            return null;
        }

        if (in_array('password', $tooLong, true)) {
            error_log("[Luster] {$form}: the password column on listing #{$listing->id} is too narrow to hold a password.");
            return FieldAnswers::PUBLIC_REFUSAL;
        }

        return match ($tooLong[0]) {
            'email' => 'Your email address',
            'name'  => 'Your name',
            'url'   => 'Your website address',
            'country' => 'Your country',
            default => self::fieldLabel($tooLong[0], $fields),
        } . " is too long for {$where}. Nothing was changed.";
    }

    /** What an admin form should say about a column that's too long. @param AdditionalField[] $fields */
    public static function adminProblem(string $column, array $fields): string
    {
        return match ($column) {
            'password' => "This listing's password column is too narrow to hold a password, so nothing was changed.",
            'email'    => "Email address is too long for this listing's member table.",
            'name'     => "Name is too long for this listing's member table.",
            'url'      => "Website address is too long for this listing's member table.",
            'country'  => "Country is too long for this listing's member table.",
            default    => self::fieldLabel($column, $fields) . " is too long for this listing's member table.",
        };
    }

    /**
     * The field a column belongs to, going by the two ways a column is named after one.
     * It only decides which label an error shows.
     *
     * @param AdditionalField[] $fields
     */
    public static function fieldFor(string $column, array $fields): ?AdditionalField
    {
        foreach ($fields as $field) {
            if (strcasecmp($column, (string) $field->name) === 0 || strcasecmp($column, 'additional_' . $field->name) === 0) {
                return $field;
            }
        }

        return null;
    }

    /** @param AdditionalField[] $fields */
    private static function fieldLabel(string $column, array $fields): string
    {
        return self::fieldFor($column, $fields)?->displayName() ?? $column;
    }
}
