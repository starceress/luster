<?php

declare(strict_types=1);

namespace Luster\Core;

/**
 * Wraps a rendered field input (from FieldRenderer) in its surrounding
 * label/hint/error/required markup, via an admin-editable {token} template.
 *
 * Instance methods (not static): FormFieldBuilder receives this through its
 * constructor and calls it as a collaborator, per the Public Template System
 * plan (docs/PUBLIC-TEMPLATES-PLAN.md "Form Field Wrapper").
 *
 * `{input}` is the one raw-token exception documented in TemplateEngine's
 * allow-list — it carries pre-built trusted HTML from FieldRenderer (or a
 * caller-supplied raw_input such as Countries::select()), never a value that
 * needs escaping.
 */
final class FieldWrapper
{
    private const DEFAULT_TEMPLATE = <<<'HTML'
        <div class="fl-field"{if_field_id} id="wrap-{field_id}"{/if_field_id}>
          <label class="fl-label"{if_field_id} for="{field_id}"{/if_field_id}>{label}{required_indicator}</label>
          {if_error}<p class="fl-field-error">{error}</p>{/if_error}
          {input}
          {if_hint}<small class="fl-field-hint">{hint}</small>{/if_hint}
        </div>
        HTML;

    /**
     * @param string $fieldId empty string for checkbox/radio groups — no single
     *                        input id exists to point a wrapper id/label-for at
     */
    public function render(
        string $template,
        string $input,
        string $label,
        string $fieldId,
        bool $required,
        string $hint = '',
        string $error = '',
        string $lostPasswordUrl = '',
    ): string {
        $tpl = $template !== '' ? $template : self::DEFAULT_TEMPLATE;

        return TemplateEngine::render(
            $tpl,
            escaped: [
                'label'               => $label,
                'field_id'            => $fieldId,
                'required_indicator'  => $required ? '*' : '',
                'hint'                => $hint,
                'error'               => $error,
                'lost_password_url'   => $lostPasswordUrl,
            ],
            raw: [
                'input' => $input,
            ],
            conditions: [
                'field_id'           => $fieldId !== '',
                'no_field_id'        => $fieldId === '',
                'hint'               => $hint !== '',
                'error'              => $error !== '',
                'lost_password_url'  => $lostPasswordUrl !== '',
            ],
        );
    }
}
