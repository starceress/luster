<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Value object representing a single custom field definition for a listing's
 member table. Listings can define extra fields like "Favorite song" or
 "Favorite character" beyond the standard name/email/country. FieldRepo
 parses the raw field config from the database and returns an array of
 these objects, which templates then loop over to build form inputs and
 member display rows dynamically.
*/

declare(strict_types=1);

namespace Luster\Models;

/**
 * Parsed definition of a single custom field attached to a listing's member table.
 *
 * Listings can define extra fields ("Favorite song", "Favorite character", etc.)
 * beyond the built-in name/email/country set. FieldRepo reads the raw field
 * config string from the database and returns an array of these objects.
 * Templates loop over that array to build form inputs and member display rows
 * dynamically — field names are never hardcoded anywhere.
 *
 * In the standard Enthusiast schema the member-table column is named
 * "additional_{$name}" (e.g. "additional_fave_song"). In the unprefixed
 * schema the column is the bare $name. The corresponding embed template
 * token is "{fan_{$name}}" in both cases.
 */
final class AdditionalField
{
    /**
     * Names a new field can't have: the standard member columns (so it can't clash
     * with one) and the member-template tokens built from them ({fan_email},
     * {fan_additional_fields}...). Lowercase, see isReservedName().
     */
    public const RESERVED_NAMES = [
        'id', 'email', 'name', 'country', 'url', 'pending', 'approved', 'password',
        'showemail', 'showurl', 'added', 'updated', 'additional_fields', 'additional_field_cells',
    ];

    /**
     * True when a new field can't be called $name: anything in RESERVED_NAMES,
     * and anything starting with additional_, which is how a field's column is
     * named on the standard schema. Ignores case, as MySQL column names do.
     */
    public static function isReservedName(string $name): bool
    {
        $lower = strtolower($name);

        return in_array($lower, self::RESERVED_NAMES, true) || str_starts_with($lower, 'additional_');
    }

    /**
     * @param string $name     Bare field identifier used as the DB column suffix and
     *                         template token name (e.g. "fave_song").
     * @param string $label    Human-readable label shown in forms and admin views.
     * @param string $type     Field input type (e.g. "text", "select").
     * @param bool   $required Whether the field must be filled in on the join/update form.
     * @param string $options  Comma-separated option list for dropdown/checkbox/radio fields
     *                         (a checkbox field with options renders as a multi-select group
     *                         rather than a single boolean checkbox — see
     *                         FormFieldBuilder::descriptorsFromAdditionalFields()). Matches
     *                         FormFieldBuilder/FieldRenderer's explode(',', ...) convention
     *                         and the admin Fields UI's own "Option A, Option B" placeholder;
     *                         empty for text fields.
     */
    public function __construct(
        public readonly string $name,
        public readonly string $label,
        public readonly string $type,
        public readonly bool   $required,
        public readonly string $options = '',
    ) {}

    /**
     * The field's choices: $options split on commas, trimmed, blanks dropped
     * (the same parsing as FormFieldBuilder::descriptorsFromAdditionalFields()).
     *
     * @return list<string>
     */
    public function optionList(): array
    {
        return array_values(array_filter(
            array_map('trim', explode(',', $this->options)),
            static fn(string $o): bool => $o !== '',
        ));
    }

    /** What to call this field in a message: its label, or its name if it has none. */
    public function displayName(): string
    {
        return $this->label !== '' ? $this->label : $this->name;
    }

    /**
     * One posted answer as the text that gets stored, or null when it's malformed.
     * A string is trimmed. A flat list is only accepted for a checkbox field, whose
     * controls post field_x[]: trimmed, blanks dropped, joined with ", ". Anything
     * else is null, an array posted to a text, radio or dropdown field included.
     */
    public function flatAnswer(mixed $raw): ?string
    {
        if (is_string($raw)) {
            return trim($raw);
        }
        if ($this->type !== 'checkbox' || !is_array($raw) || !array_is_list($raw)) {
            return null;
        }

        $picks = [];
        foreach ($raw as $pick) {
            if (!is_string($pick)) {
                return null;
            }
            $pick = trim($pick);
            if ($pick !== '') {
                $picks[] = $pick;
            }
        }

        return implode(', ', $picks);
    }

    /**
     * The control every form gives this field, which its save checks against: 'select'
     * (dropdown, or select as Enthusiast imports call it), 'radio', 'checkboxes' (a
     * checkbox field with options: pick any), 'checkbox' (one without options: a single
     * yes box), 'textarea' or 'text'. A dropdown or radio with no choices is 'text', since
     * otherwise a required one could never be answered and nobody could join.
     */
    public function controlKind(): string
    {
        $hasChoices = $this->optionList() !== [];

        return match ($this->type) {
            'dropdown', 'select' => $hasChoices ? 'select' : 'text',
            'radio'              => $hasChoices ? 'radio' : 'text',
            'checkbox'           => $hasChoices ? 'checkboxes' : 'checkbox',
            'textarea'           => 'textarea',
            default              => 'text',
        };
    }

    /** What to call this field's type in the Fields lists. */
    public function typeLabel(): string
    {
        $missingChoices = $this->optionList() === [] ? ' (no choices yet)' : '';

        return match ($this->type) {
            'dropdown', 'select' => 'Dropdown' . $missingChoices,
            'radio'              => 'Radio Button' . $missingChoices,
            'checkbox'           => 'Checkbox',
            'textarea'           => 'Text Area',
            default              => 'Text Field',
        };
    }

    /**
     * The choices to offer someone whose saved answer is $saved: the field's own, plus
     * any part of that answer that's no longer on the list. A control can't show what it
     * doesn't offer, so without this, saving the form unchanged would quietly lose it.
     *
     * @return list<string>
     */
    public function choicesFor(string $saved = ''): array
    {
        $choices = $this->optionList();
        if ($choices === []) {
            return [];
        }

        // Only a checkbox group's answer is several picks. Anything else is one answer,
        // whatever's in it.
        $picks = $this->controlKind() === 'checkboxes' ? explode(',', $saved) : [$saved];
        foreach ($picks as $pick) {
            $pick = trim($pick);
            if ($pick !== '' && !in_array($pick, $choices, true)) {
                $choices[] = $pick;
            }
        }

        return $choices;
    }

    /**
     * One posted answer, checked the way this field's control works. 'value' is the text
     * to store and is '' when there's an 'error'. $saved is the member's current answer
     * on a form that has one, which is accepted even if it's no longer a choice.
     *
     * @return array{value: string, error: ?string}
     */
    public function answerFrom(mixed $raw, string $saved = ''): array
    {
        $label = $this->displayName();
        $kind  = $this->controlKind();
        $flat  = $this->flatAnswer($raw);

        if ($flat === null || ($kind === 'checkbox' && !is_string($raw))) {
            return ['value' => '', 'error' => "{$label}: that answer isn't valid."];
        }

        if ($kind === 'checkboxes') {
            // flatAnswer() has checked these, so they're all strings.
            $picks = $flat === '' ? [] : array_map('trim', explode(', ', $flat));
            foreach ($picks as $pick) {
                if (!in_array($pick, $this->choicesFor($saved), true)) {
                    return ['value' => '', 'error' => "{$label}: pick from the listed options."];
                }
            }
            $flat = implode(', ', array_values(array_unique($picks)));
        } elseif (in_array($kind, ['select', 'radio'], true)) {
            if ($flat !== '' && !in_array($flat, $this->choicesFor($saved), true)) {
                return ['value' => '', 'error' => "{$label}: pick one of the listed options."];
            }
        } elseif ($kind === 'checkbox' && !in_array($flat, ['', '1', trim($saved)], true)) {
            return ['value' => '', 'error' => "{$label}: that answer isn't valid."];
        }

        if (mb_strlen($flat, 'UTF-8') > 255) {
            return ['value' => '', 'error' => "{$label} can be at most 255 characters."];
        }

        return ['value' => $flat, 'error' => null];
    }
}
