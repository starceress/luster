<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Decides which member column each extra-field answer gets saved to, for every
 form that saves answers. It's what stops an answer ever landing in a column
 that isn't that field's own, such as a member's real name or password.
*/

declare(strict_types=1);

namespace Luster\Support;

use Luster\Models\AdditionalField;
use Luster\Models\Listing;
use Luster\Repositories\FieldRepo;

/**
 * Places normalized answers into columns, using FieldColumnMap's rules.
 *
 * A field with nowhere safe to save has nothing to lose from a blank answer, so
 * that's ignored. A real answer ("0" counts) refuses the whole save, so it's
 * never silently dropped and the member's other details are never half-saved.
 * Callers do this before writing anything.
 */
final class FieldAnswers
{
    public const PUBLIC_REFUSAL = "We couldn't save this because of a problem with the form. Nothing was changed. Please let the listing's owner know.";

    private function __construct() {}

    /**
     * @param AdditionalField[]      $fields  Every definition on the listing.
     * @param array<string, ?string> $columns FieldRepo::fieldColumns() for those fields.
     * @param array<string, string>  $answers Field name => normalized answer. Missing means blank.
     * @return array{values: array<string, string>, unplaceable: list<AdditionalField>}
     *         Column => answer, and the fields with a real answer and no column.
     */
    public static function place(array $fields, array $columns, array $answers): array
    {
        $values      = [];
        $unplaceable = [];
        foreach ($fields as $field) {
            $answer = $answers[$field->name] ?? '';
            $column = $columns[$field->name] ?? null;
            if ($column !== null) {
                $values[$column] = $answer;
            } elseif ($answer !== '') {
                $unplaceable[] = $field;
            }
        }

        return ['values' => $values, 'unplaceable' => $unplaceable];
    }

    /** The admin forms' refusal for a real answer with no column. */
    public static function adminRefusal(AdditionalField $field): string
    {
        return $field->displayName() . " can't be saved: this field's column is missing or its name clashes with a built-in one. Fix it on the Fields page. Nothing was changed.";
    }

    /**
     * place() for a public form, with a fresh read of the table. Fans only ever see
     * PUBLIC_REFUSAL, so what went wrong goes to the log.
     *
     * @param AdditionalField[]     $fields
     * @param array<string, string> $answers
     * @return array<string, string>|null Column => answer, or null if the save has to be refused.
     */
    public static function forPublicForm(FieldRepo $fieldRepo, Listing $listing, array $fields, array $answers, string $form): ?array
    {
        try {
            $placed = self::place($fields, $fieldRepo->fieldColumns($listing->dbprefix, $fields), $answers);
        } catch (\RuntimeException | \InvalidArgumentException $e) {
            error_log("[Luster] {$form}: couldn't read the member table for listing #{$listing->id}: " . $e->getMessage());
            return null;
        }

        foreach ($placed['unplaceable'] as $field) {
            error_log("[Luster] {$form}: field '{$field->name}' on listing #{$listing->id} has nowhere safe to save its answer.");
        }

        return $placed['unplaceable'] === [] ? $placed['values'] : null;
    }
}
