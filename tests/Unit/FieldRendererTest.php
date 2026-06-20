<?php

declare(strict_types=1);

namespace Luster\Tests\Unit;

use Luster\Core\FieldRenderer;
use Luster\Models\AdditionalField;
use PHPUnit\Framework\TestCase;

final class FieldRendererTest extends TestCase
{
    private FieldRenderer $renderer;

    protected function setUp(): void
    {
        $this->renderer = new FieldRenderer();
    }

    public function testTextRendersEscapedInputWithValueAndId(): void
    {
        $html = $this->renderer->text('join-name', 'name', '<script>"x"</script>', true);

        self::assertStringContainsString('type="text"', $html);
        self::assertStringContainsString('id="join-name"', $html);
        self::assertStringContainsString('name="name"', $html);
        self::assertStringContainsString('value="&lt;script&gt;&quot;x&quot;&lt;/script&gt;"', $html);
        self::assertStringContainsString('required', $html);
    }

    public function testTextOmitsRequiredAttributeWhenNotRequired(): void
    {
        $html = $this->renderer->text('join-name', 'name', '', false);

        self::assertStringNotContainsString('required', $html);
    }

    public function testTextAppliesExtraAttributes(): void
    {
        $html = $this->renderer->text('join-name', 'name', '', false, ['maxlength' => '255', 'autocomplete' => 'name']);

        self::assertStringContainsString('maxlength="255"', $html);
        self::assertStringContainsString('autocomplete="name"', $html);
    }

    public function testEmailAndUrlUseTheirRespectiveInputTypes(): void
    {
        self::assertStringContainsString('type="email"', $this->renderer->email('id', 'email', '', true));
        self::assertStringContainsString('type="url"', $this->renderer->url('id', 'url', '', false));
    }

    public function testPasswordOmitsValueAttribute(): void
    {
        $html = $this->renderer->password('join-password', 'password', true, ['autocomplete' => 'current-password']);

        self::assertStringContainsString('type="password"', $html);
        self::assertStringContainsString('autocomplete="current-password"', $html);
        self::assertStringNotContainsString('value=', $html);
    }

    public function testTextareaEscapesValueAndAppliesAttributes(): void
    {
        $html = $this->renderer->textarea('f-bio', 'field_bio', '<b>hi</b>', true, ['maxlength' => '255']);

        self::assertStringContainsString('<textarea', $html);
        self::assertStringContainsString('id="f-bio"', $html);
        self::assertStringContainsString('maxlength="255"', $html);
        self::assertStringContainsString('>&lt;b&gt;hi&lt;/b&gt;</textarea>', $html);
    }

    public function testSelectMarksMatchingOptionAsSelected(): void
    {
        $html = $this->renderer->select('f-fav', 'field_fav', ['a' => 'Apple', 'b' => 'Banana'], 'b', true);

        self::assertStringContainsString('<option value="a">Apple</option>', $html);
        self::assertStringContainsString('<option value="b" selected>Banana</option>', $html);
    }

    public function testFileIncludesAcceptOnlyWhenProvided(): void
    {
        $withAccept    = $this->renderer->file('f-img', 'image', false, 'image/png');
        $withoutAccept = $this->renderer->file('f-img', 'image');

        self::assertStringContainsString('accept="image/png"', $withAccept);
        self::assertStringNotContainsString('accept=', $withoutAccept);
    }

    public function testFileEmitsRequiredAttributeOnlyWhenRequired(): void
    {
        $required    = $this->renderer->file('f-code', 'code_image', true);
        $notRequired = $this->renderer->file('f-code', 'code_image', false);

        self::assertStringContainsString('<input type="file" class="fl-input" id="f-code" name="code_image" required', $required);
        self::assertStringNotContainsString('required', $notRequired);
    }

    public function testCheckboxGroupRendersSingleInlineCheckboxForOneOption(): void
    {
        $html = $this->renderer->checkboxGroup('field_subscribe', ['1' => 'Subscribe to updates'], ['1'], true);

        self::assertSame(1, substr_count($html, '<label class="fl-check-label">'));
        self::assertStringContainsString('name="field_subscribe"', $html);
        self::assertStringNotContainsString('field_subscribe[]', $html);
        self::assertStringContainsString('checked', $html);
        self::assertStringContainsString('required', $html);
        self::assertStringContainsString('Subscribe to updates', $html);
    }

    public function testCheckboxGroupUsesArrayNameAndOmitsRequiredForMultipleOptions(): void
    {
        $html = $this->renderer->checkboxGroup('field_colors', ['r' => 'Red', 'g' => 'Green'], ['g'], true);

        self::assertSame(2, substr_count($html, 'name="field_colors[]"'));
        self::assertStringNotContainsString('required', $html);
        self::assertStringContainsString('value="g" checked', $html);
        self::assertStringNotContainsString('value="r" checked', $html);
    }

    public function testRadioGroupMarksSelectedValueChecked(): void
    {
        $html = $this->renderer->radioGroup('field_size', ['s' => 'Small', 'l' => 'Large'], 'l', true);

        self::assertStringContainsString('value="s"', $html);
        self::assertStringContainsString('value="l" checked required', $html);
        self::assertSame(2, substr_count($html, 'type="radio"'));
    }

    public function testAdditionalFieldTextareaDelegatesToTextarea(): void
    {
        $field = new AdditionalField('bio', 'Bio', 'textarea', false);
        $html  = $this->renderer->additionalField($field, 'hello');

        self::assertStringContainsString('<textarea', $html);
        self::assertStringContainsString('id="f-bio"', $html);
        self::assertStringContainsString('name="field_bio"', $html);
        self::assertStringContainsString('>hello</textarea>', $html);
    }

    public function testAdditionalFieldDropdownProducesSelectWithLeadingBlankOption(): void
    {
        $field = new AdditionalField('color', 'Favorite Color', 'dropdown', true, 'Red, Green, Blue');
        $html  = $this->renderer->additionalField($field, 'Green');

        self::assertStringContainsString('<select', $html);
        self::assertStringContainsString('<option value=""></option>', $html);
        self::assertStringContainsString('<option value="Red">Red</option>', $html);
        self::assertStringContainsString('<option value="Green" selected>Green</option>', $html);
    }

    public function testAdditionalFieldCheckboxWithoutOptionsRendersBooleanCheckbox(): void
    {
        $field = new AdditionalField('newsletter', 'Subscribe to the newsletter', 'checkbox', false);

        $checked   = $this->renderer->additionalField($field, '1');
        $unchecked = $this->renderer->additionalField($field, '');

        self::assertStringContainsString('name="field_newsletter"', $checked);
        self::assertStringContainsString('value="1" checked', $checked);
        self::assertStringContainsString('Subscribe to the newsletter', $checked);
        self::assertStringNotContainsString('checked', $unchecked);
    }

    public function testAdditionalFieldCheckboxWithOptionsRendersGroup(): void
    {
        $field = new AdditionalField('hobbies', 'Hobbies', 'checkbox', false, 'Reading, Gaming, Hiking');
        $html  = $this->renderer->additionalField($field, 'Gaming, Hiking');

        self::assertStringContainsString('name="field_hobbies[]"', $html);
        self::assertStringContainsString('value="Reading"', $html);
        self::assertStringContainsString('value="Gaming" checked', $html);
        self::assertStringContainsString('value="Hiking" checked', $html);
        self::assertStringNotContainsString('value="Reading" checked', $html);
    }

    public function testAdditionalFieldRadioDelegatesToRadioGroup(): void
    {
        $field = new AdditionalField('size', 'Size', 'radio', true, 'Small, Medium, Large');
        $html  = $this->renderer->additionalField($field, 'Medium');

        self::assertStringContainsString('type="radio"', $html);
        self::assertStringContainsString('value="Medium" checked required', $html);
    }

    public function testAdditionalFieldDefaultsToTextInput(): void
    {
        $field = new AdditionalField('nickname', 'Nickname', 'text', true);
        $html  = $this->renderer->additionalField($field, 'Bobby');

        self::assertStringContainsString('type="text"', $html);
        self::assertStringContainsString('id="f-nickname"', $html);
        self::assertStringContainsString('value="Bobby"', $html);
    }
}
