<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Renders the raw HTML input element for a single form field (text, email,
 URL, textarea, select, etc.). Produces only the bare <input> or equivalent
 element -- surrounding label, hint, and error markup is handled by
 FieldWrapper. All output values are escaped via View::e().
*/

declare(strict_types=1);

namespace Luster\Core;

use Luster\Models\AdditionalField;

/**
 * Renders the raw <input>/<select>/<textarea>/... markup for a single form
 * field. Produces only the input element itself — surrounding label/hint/
 * error markup is the job of FieldWrapper.
 *
 * Instance methods (not static): FormFieldBuilder receives this through its
 * constructor and calls it as a collaborator.
 *
 * Every value is escaped via View::e() — callers pass raw scalars, never
 * pre-escaped strings.
 */
final class FieldRenderer
{
    /** Renders a plain text <input> with fl-input class. */
    public function text(string $id, string $name, string $value, bool $required, array $attrs = []): string
    {
        return $this->input('text', $id, $name, $value, $required, $attrs);
    }

    /** Renders an email <input> with fl-input class. */
    public function email(string $id, string $name, string $value, bool $required, array $attrs = []): string
    {
        return $this->input('email', $id, $name, $value, $required, $attrs);
    }

    /** Renders a URL <input> with fl-input class. */
    public function url(string $id, string $name, string $value, bool $required, array $attrs = []): string
    {
        return $this->input('url', $id, $name, $value, $required, $attrs);
    }

    /** Renders a password <input> with fl-input class. Value is never populated (no repopulation for password fields). */
    public function password(string $id, string $name, bool $required, array $attrs = []): string
    {
        return '<input type="password"'
             . ' id="' . View::e($id) . '"'
             . ' name="' . View::e($name) . '"'
             . ' class="fl-input"'
             . ($required ? ' required' : '')
             . $this->renderAttrs($attrs)
             . '>';
    }

    /** Renders a <textarea> with fl-textarea class. */
    public function textarea(string $id, string $name, string $value, bool $required, array $attrs = []): string
    {
        return '<textarea'
             . ' id="' . View::e($id) . '"'
             . ' name="' . View::e($name) . '"'
             . ' class="fl-textarea"'
             . ($required ? ' required' : '')
             . $this->renderAttrs($attrs)
             . '>' . View::e($value) . '</textarea>';
    }

    /**
     * Renders a <select> element with fl-select class.
     *
     * @param array<int|string, string> $options [value => label]
     */
    public function select(string $id, string $name, array $options, string $selected, bool $required, array $attrs = []): string
    {
        $optionsHtml = '';
        foreach ($options as $value => $label) {
            $value = (string) $value;
            $optionsHtml .= '<option value="' . View::e($value) . '"'
                          . ($value === $selected ? ' selected' : '')
                          . '>' . View::e((string) $label) . '</option>';
        }

        return '<select'
             . ' id="' . View::e($id) . '"'
             . ' name="' . View::e($name) . '"'
             . ' class="fl-select"'
             . ($required ? ' required' : '')
             . $this->renderAttrs($attrs)
             . '>' . $optionsHtml . '</select>';
    }

    /**
     * Renders a file <input> with fl-input class.
     *
     * @param string $accept MIME-type or extension accept filter (e.g. 'image/*'), empty to omit.
     */
    public function file(string $id, string $name, bool $required = false, string $accept = '', array $attrs = []): string
    {
        $html = '<input type="file" class="fl-input"'
              . ' id="' . View::e($id) . '"'
              . ' name="' . View::e($name) . '"'
              . ($required ? ' required' : '');

        if ($accept !== '') {
            $html .= ' accept="' . View::e($accept) . '"';
        }

        return $html . $this->renderAttrs($attrs) . '>';
    }

    /**
     * Renders a group of checkboxes, one <label><input>...</label> per option.
     *
     * A single-entry $options map naturally renders as one inline checkbox —
     * the caller decides whether $name should carry the `[]` array suffix by
     * passing it (or not) in $name; this method does not infer it, since
     * AdditionalField's "boolean" checkboxes and genuine multi-option groups
     * use different name conventions (`field_x` vs `field_x[]`).
     *
     * @param array<int|string, string> $options  [value => label]
     * @param string[]                  $selected checked values
     */
    public function checkboxGroup(string $name, array $options, array $selected, bool $required): string
    {
        $multi = count($options) > 1;
        $html  = '';

        foreach ($options as $value => $label) {
            $value   = (string) $value;
            $checked = in_array($value, $selected, true);

            $html .= '<label class="fl-check-label"><input type="checkbox" class="fl-checkbox"'
                   . ' name="' . View::e($multi ? $name . '[]' : $name) . '"'
                   . ' value="' . View::e($value) . '"'
                   . ($checked ? ' checked' : '')
                   . (!$multi && $required ? ' required' : '')
                   . '> ' . View::e((string) $label) . '</label>';
        }

        return $html;
    }

    /**
     * Renders a group of radio buttons, one <label><input> pair per option.
     *
     * @param array<int|string, string> $options [value => label]
     */
    public function radioGroup(string $name, array $options, string $selected, bool $required): string
    {
        $html = '';

        foreach ($options as $value => $label) {
            $value   = (string) $value;
            $checked = $value === $selected;

            $html .= '<label class="fl-check-label"><input type="radio" class="fl-radio"'
                   . ' name="' . View::e($name) . '"'
                   . ' value="' . View::e($value) . '"'
                   . ($checked ? ' checked' : '')
                   . ($required ? ' required' : '')
                   . '> ' . View::e((string) $label) . '</label>';
        }

        return $html;
    }

    /**
     * Renders an AdditionalField according to its declared type, replicating
     * the type → markup mapping in templates/default/join.php: textarea,
     * dropdown/select → <select> with a leading blank option, checkbox →
     * either a single boolean checkbox or a checkbox group (depending on
     * whether options are defined), radio → radio group, anything else
     * (text and the join.php legacy quirk where 'select' falls through) →
     * plain text input.
     *
     * $oldValue is always a string here — multi-value repopulation (checkbox
     * groups) is handled by the caller building the options/selected arrays
     * from $old before reaching this method is out of scope for this single-
     * value signature, so checkbox groups compare against a comma-free single
     * stored value, matching how single-select additional fields persist.
     */
    public function additionalField(AdditionalField $field, string $oldValue): string
    {
        $id      = 'f-' . $field->name;
        $name    = 'field_' . $field->name;
        $options = $this->parseOptionsMap($field->options);

        return match ($field->type) {
            'textarea' => $this->textarea($id, $name, $oldValue, $field->required, ['maxlength' => '255']),

            'select', 'dropdown' => $this->select(
                $id,
                $name,
                ['' => ''] + $options,
                $oldValue,
                $field->required,
            ),

            'checkbox' => $options !== []
                ? $this->checkboxGroup($name, $options, $this->splitOldValue($oldValue), $field->required)
                : $this->checkboxGroup($name, ['1' => $field->label], $oldValue === '1' ? ['1'] : [], $field->required),

            'radio' => $this->radioGroup($name, $options, $oldValue, $field->required),

            default => $this->text($id, $name, $oldValue, $field->required, ['maxlength' => '255']),
        };
    }

    private function input(string $type, string $id, string $name, string $value, bool $required, array $attrs): string
    {
        return '<input type="' . View::e($type) . '"'
             . ' id="' . View::e($id) . '"'
             . ' name="' . View::e($name) . '"'
             . ' value="' . View::e($value) . '"'
             . ' class="fl-input"'
             . ($required ? ' required' : '')
             . $this->renderAttrs($attrs)
             . '>';
    }

    /**
     * @param array<string, string> $attrs
     */
    private function renderAttrs(array $attrs): string
    {
        $html = '';

        foreach ($attrs as $attrName => $attrValue) {
            $html .= ' ' . View::e((string) $attrName) . '="' . View::e((string) $attrValue) . '"';
        }

        return $html;
    }

    /**
     * Parses a comma-separated AdditionalField::$options string into a
     * [value => label] map, trimming whitespace and dropping empty entries —
     * the same convention templates/default/join.php uses for dropdown/radio.
     *
     * @return array<string, string>
     */
    private function parseOptionsMap(string $options): array
    {
        $map = [];

        foreach (array_filter(array_map('trim', explode(',', $options)), static fn(string $o): bool => $o !== '') as $opt) {
            $map[$opt] = $opt;
        }

        return $map;
    }

    /**
     * Splits a stored multi-value field value (comma-separated) back into
     * an array of selected values for checkbox-group repopulation.
     *
     * @return string[]
     */
    private function splitOldValue(string $oldValue): array
    {
        return array_filter(array_map('trim', explode(',', $oldValue)), static fn(string $v): bool => $v !== '');
    }
}
