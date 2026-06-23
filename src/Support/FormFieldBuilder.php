<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Builds the HTML for every field on every public-facing form (join, update,
 reset, codes-submit, contact, KIM, etc.). Controllers describe their fields
 as simple descriptor arrays, and this class turns each one into a fully
 rendered, wrapper-enclosed HTML string. It also converts dynamic additional
 fields from the database into the same descriptor format so they can be
 mixed into any form seamlessly.
*/

declare(strict_types=1);

namespace Luster\Support;

use InvalidArgumentException;
use Luster\Core\FieldRenderer;
use Luster\Core\FieldWrapper;
use Luster\Core\View;
use Luster\Models\AdditionalField;

/**
 * Builds wrapped form field HTML for all public forms.
 *
 * Centralises field rendering across ~12 controllers and ~12 embed files —
 * a controller-private helper can't be reached from embed files (which render
 * via View::renderFragment() without going through any controller).
 *
 * Constructed once in Application, exposed via getFormFieldBuilder().
 *
 * @see docs/PUBLIC-TEMPLATES-PLAN.md "Phase 5 — Form Field Wrapper"
 */
final class FormFieldBuilder
{
    /** Types that dispatch to FieldRenderer methods (excluding 'raw'). */
    private const KNOWN_TYPES = [
        'text', 'email', 'url', 'password', 'textarea',
        'select', 'file', 'checkboxGroup', 'radioGroup',
    ];

    public function __construct(
        private readonly FieldRenderer $renderer,
        private readonly FieldWrapper  $wrapper,
    ) {}

    /**
     * Build wrapped HTML for a list of field descriptors.
     *
     * Each descriptor shape (all keys optional except name, label, type):
     *   type       string   text|email|url|password|textarea|select|file|checkboxGroup|radioGroup
     *   name       string   form field name; also the returned-map key
     *   id         string   input id; empty string for checkbox/radio groups (no single id)
     *   label      string   visible label text
     *   required   bool     default false
     *   value      string   repopulation value (ignored for password/file/checkboxGroup)
     *   options    array    [value => label] for select/checkboxGroup/radioGroup
     *   selected   array    checked values for checkboxGroup; for radioGroup use value
     *   hint       string   optional hint text below the field
     *   error      string   field-level error message (currently: captcha only)
     *   attrs      array    extra HTML attributes [attr => value]
     *   raw_input  ?string  pre-built trusted HTML — bypasses FieldRenderer entirely
     *   lost_password_url  string  per-descriptor override for the lost-password token
     *
     * @param  array<int, array>  $descriptors
     * @param  string             $wrapperTemplate  '' = default FieldWrapper template
     * @param  string             $lostPasswordUrl  applied to all password-type descriptors
     * @return array<string, string>  field name => wrapped HTML
     * @throws InvalidArgumentException on malformed descriptor
     */
    public function build(
        array  $descriptors,
        string $wrapperTemplate,
        string $lostPasswordUrl = '',
    ): array {
        $result = [];

        foreach ($descriptors as $idx => $d) {
            $this->validateDescriptor($d, $idx);

            $name      = (string) $d['name'];
            $type      = (string) ($d['type']     ?? 'text');
            $id        = (string) ($d['id']        ?? '');
            $label     = (string) ($d['label']     ?? '');
            $required  = (bool)   ($d['required']  ?? false);
            $hint      = (string) ($d['hint']      ?? '');
            $error     = (string) ($d['error']     ?? '');
            $attrs     = (array)  ($d['attrs']     ?? []);
            $value     = (string) ($d['value']     ?? '');
            $options   = (array)  ($d['options']   ?? []);
            $selected  = (array)  ($d['selected']  ?? []);
            $rawInput  = isset($d['raw_input']) && $d['raw_input'] !== null
                            ? (string) $d['raw_input'] : null;

            // Per-descriptor lost_password_url wins; fall back to build()-level param for password fields
            $fieldLostUrl = (string) ($d['lost_password_url'] ?? '');
            if ($fieldLostUrl === '' && $type === 'password') {
                $fieldLostUrl = $lostPasswordUrl;
            }

            $inputHtml = ($rawInput !== null)
                ? $rawInput
                : $this->renderInput($type, $id, $name, $value, $required, $options, $selected, $attrs);

            $result[$name . '_input'] = ($type === 'file' && $rawInput === null)
                ? $this->fileDropZone($id, $name, $required, (string) ($attrs['accept'] ?? ''))
                : $inputHtml;

            $result[$name] = $this->wrapper->render(
                $wrapperTemplate,
                $inputHtml,
                $label,
                $id,
                $required,
                $hint,
                $error,
                $fieldLostUrl,
            );
        }

        return $result;
    }

    /**
     * Converts AdditionalField[] into descriptor arrays that can be merged with
     * a form's built-in descriptor list and passed to build() in one call.
     *
     * Additional-field types are normalised to the shared vocabulary:
     *   dropdown → select (with a leading blank option)
     *   checkbox → checkboxGroup (boolean when no options defined; multi otherwise)
     *   radio    → radioGroup
     *
     * @param  AdditionalField[] $fields
     * @param  array             $old    repopulation values keyed 'field_{name}'
     * @return array<int, array>
     */
    public function descriptorsFromAdditionalFields(array $fields, array $old): array
    {
        $descriptors = [];

        foreach ($fields as $field) {
            $optsList   = array_values(array_filter(
                array_map('trim', explode(',', $field->options)),
                static fn(string $o): bool => $o !== '',
            ));
            $optionsMap = $optsList !== [] ? array_combine($optsList, $optsList) : [];
            $rawOldVal = $old['field_' . $field->name] ?? '';
            $oldVal    = is_array($rawOldVal)
                ? implode(',', array_map('strval', $rawOldVal))
                : (string) $rawOldVal;

            switch ($field->type) {
                case 'dropdown':
                    $type     = 'select';
                    $id       = 'f-' . $field->name;
                    $options  = ['' => ''] + $optionsMap;
                    $selected = [];
                    $attrs    = [];
                    break;

                case 'checkbox':
                    $type = 'checkboxGroup';
                    $id   = '';
                    if ($optionsMap === []) {
                        // Boolean single-checkbox
                        $options  = ['1' => $field->label];
                        $selected = $oldVal === '1' ? ['1'] : [];
                    } else {
                        $options  = $optionsMap;
                        $selected = array_values(array_filter(
                            array_map('trim', explode(',', $oldVal)),
                            static fn(string $v): bool => $v !== '',
                        ));
                    }
                    $attrs = [];
                    break;

                case 'radio':
                    $type     = 'radioGroup';
                    $id       = '';
                    $options  = $optionsMap;
                    $selected = [];
                    $attrs    = [];
                    break;

                case 'textarea':
                    $type     = 'textarea';
                    $id       = 'f-' . $field->name;
                    $options  = [];
                    $selected = [];
                    $attrs    = ['maxlength' => '255'];
                    break;

                default: // 'text' and any unrecognised type
                    $type     = 'text';
                    $id       = 'f-' . $field->name;
                    $options  = [];
                    $selected = [];
                    $attrs    = ['maxlength' => '255'];
            }

            $descriptors[] = [
                'type'      => $type,
                'name'      => 'field_' . $field->name,
                'id'        => $id,
                'label'     => $field->label,
                'required'  => $field->required,
                'value'     => $oldVal,
                'options'   => $options,
                'selected'  => $selected,
                'hint'      => '',
                'error'     => '',
                'attrs'     => $attrs,
                'raw_input' => null,
            ];
        }

        return $descriptors;
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    private function validateDescriptor(array $d, int $idx): void
    {
        if (!isset($d['name']) || (string) $d['name'] === '') {
            throw new InvalidArgumentException(
                "FormFieldBuilder: descriptor #{$idx} is missing required key 'name'.",
            );
        }

        if (!array_key_exists('label', $d)) {
            throw new InvalidArgumentException(
                "FormFieldBuilder: descriptor #{$idx} ('{$d['name']}') is missing required key 'label'.",
            );
        }

        $hasRaw = isset($d['raw_input']) && $d['raw_input'] !== null;
        $type   = (string) ($d['type'] ?? '');

        if (!$hasRaw && !in_array($type, self::KNOWN_TYPES, true)) {
            throw new InvalidArgumentException(
                "FormFieldBuilder: descriptor #{$idx} ('{$d['name']}') has unknown type '{$type}'. " .
                'Set raw_input to a trusted HTML string to bypass FieldRenderer for custom markup.',
            );
        }
    }

    private function renderInput(
        string $type,
        string $id,
        string $name,
        string $value,
        bool   $required,
        array  $options,
        array  $selected,
        array  $attrs,
    ): string {
        return match ($type) {
            'text'          => $this->renderer->text($id, $name, $value, $required, $attrs),
            'email'         => $this->renderer->email($id, $name, $value, $required, $attrs),
            'url'           => $this->renderer->url($id, $name, $value, $required, $attrs),
            'password'      => $this->renderer->password($id, $name, $required, $attrs),
            'textarea'      => $this->renderer->textarea($id, $name, $value, $required, $attrs),
            'select'        => $this->renderer->select($id, $name, $options, $value, $required, $attrs),
            'file'          => $this->renderer->file(
                                   $id,
                                   $name,
                                   $required,
                                   (string) ($attrs['accept'] ?? ''),
                                   array_diff_key($attrs, ['accept' => '']),
                               ),
            'checkboxGroup' => $this->renderer->checkboxGroup($name, $options, $selected, $required),
            'radioGroup'    => $this->renderer->radioGroup($name, $options, $value, $required),
            default         => '',
        };
    }

    /**
     * Wraps a file input in a clickable drop-zone label for use as a field's
     * '_input' token (e.g. {field_image_input}).
     */
    private function fileDropZone(string $id, string $name, bool $required, string $accept): string
    {
        $inputTag = $this->renderer->file($id, $name, $required, $accept);
        $idAttr   = $id !== '' ? ' for="' . View::e($id) . '"' : '';

        return '<label class="fl-file-zone"' . $idAttr . '>'
             . $inputTag
             . '<span class="fl-file-prompt">Click to choose a file</span>'
             . '</label>';
    }
}
