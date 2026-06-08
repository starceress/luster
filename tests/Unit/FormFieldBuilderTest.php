<?php

declare(strict_types=1);

namespace Luster\Tests\Unit;

use InvalidArgumentException;
use Luster\Core\FieldRenderer;
use Luster\Core\FieldWrapper;
use Luster\Models\AdditionalField;
use Luster\Support\FormFieldBuilder;
use PHPUnit\Framework\TestCase;

final class FormFieldBuilderTest extends TestCase
{
    private FormFieldBuilder $fb;

    protected function setUp(): void
    {
        $this->fb = new FormFieldBuilder(new FieldRenderer(), new FieldWrapper());
    }

    // -------------------------------------------------------------------------
    // build() — descriptor dispatch
    // -------------------------------------------------------------------------

    public function testTextFieldRendersInput(): void
    {
        $result = $this->fb->build([
            ['type' => 'text', 'name' => 'my_name', 'id' => 'f-name', 'label' => 'Name', 'required' => true, 'value' => 'Alice'],
        ], '');

        $this->assertArrayHasKey('my_name', $result);
        $this->assertStringContainsString('type="text"', $result['my_name']);
        $this->assertStringContainsString('value="Alice"', $result['my_name']);
        $this->assertStringContainsString('required', $result['my_name']);
    }

    public function testEmailFieldRendersInput(): void
    {
        $result = $this->fb->build([
            ['type' => 'email', 'name' => 'addr', 'id' => 'f-addr', 'label' => 'Email', 'required' => false, 'value' => 'a@b.com'],
        ], '');

        $this->assertStringContainsString('type="email"', $result['addr']);
        $this->assertStringContainsString('value="a@b.com"', $result['addr']);
    }

    public function testUrlFieldRendersInput(): void
    {
        $result = $this->fb->build([
            ['type' => 'url', 'name' => 'site', 'id' => 'f-site', 'label' => 'URL', 'value' => 'https://x.com'],
        ], '');

        $this->assertStringContainsString('type="url"', $result['site']);
    }

    public function testPasswordFieldRendersInput(): void
    {
        $result = $this->fb->build([
            ['type' => 'password', 'name' => 'pw', 'id' => 'f-pw', 'label' => 'Password'],
        ], '');

        $this->assertStringContainsString('type="password"', $result['pw']);
        $this->assertStringNotContainsString('value=', $result['pw']);
    }

    public function testTextareaFieldRendersTextarea(): void
    {
        $result = $this->fb->build([
            ['type' => 'textarea', 'name' => 'msg', 'id' => 'f-msg', 'label' => 'Message', 'value' => 'hello'],
        ], '');

        $this->assertStringContainsString('<textarea', $result['msg']);
        $this->assertStringContainsString('hello', $result['msg']);
    }

    public function testSelectFieldRendersSelect(): void
    {
        $result = $this->fb->build([
            ['type' => 'select', 'name' => 'col', 'id' => 'f-col', 'label' => 'Colour', 'options' => ['r' => 'Red', 'b' => 'Blue'], 'value' => 'b'],
        ], '');

        $this->assertStringContainsString('<select', $result['col']);
        $this->assertStringContainsString('selected', $result['col']);
    }

    public function testFileFieldRendersFileInput(): void
    {
        $result = $this->fb->build([
            ['type' => 'file', 'name' => 'img', 'id' => 'f-img', 'label' => 'Image', 'attrs' => ['accept' => 'image/*']],
        ], '');

        $this->assertStringContainsString('type="file"', $result['img']);
    }

    public function testCheckboxGroupRendersCheckboxes(): void
    {
        $result = $this->fb->build([
            ['type' => 'checkboxGroup', 'name' => 'fave', 'id' => '', 'label' => 'Faves',
             'options' => ['cats' => 'Cats', 'dogs' => 'Dogs'], 'selected' => ['cats']],
        ], '');

        $this->assertStringContainsString('type="checkbox"', $result['fave']);
        $this->assertStringContainsString('checked', $result['fave']);
    }

    public function testRadioGroupRendersRadios(): void
    {
        $result = $this->fb->build([
            ['type' => 'radioGroup', 'name' => 'mood', 'id' => '', 'label' => 'Mood',
             'options' => ['happy' => 'Happy', 'sad' => 'Sad'], 'value' => 'happy'],
        ], '');

        $this->assertStringContainsString('type="radio"', $result['mood']);
    }

    // -------------------------------------------------------------------------
    // build() — raw_input bypass
    // -------------------------------------------------------------------------

    public function testRawInputBypassesRenderer(): void
    {
        $raw = '<select><option>custom</option></select>';
        $result = $this->fb->build([
            ['type' => 'select', 'name' => 'custom', 'id' => '', 'label' => 'Custom', 'raw_input' => $raw],
        ], '');

        $this->assertStringContainsString('<select><option>custom</option></select>', $result['custom']);
    }

    // -------------------------------------------------------------------------
    // build() — validation errors
    // -------------------------------------------------------------------------

    public function testUnknownTypeThrowsWithoutRawInput(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("unknown type 'bogus'");

        $this->fb->build([
            ['type' => 'bogus', 'name' => 'x', 'label' => 'X'],
        ], '');
    }

    public function testMissingNameThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("missing required key 'name'");

        $this->fb->build([['label' => 'Oops']], '');
    }

    public function testMissingLabelThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("missing required key 'label'");

        $this->fb->build([['name' => 'x', 'type' => 'text']], '');
    }

    // -------------------------------------------------------------------------
    // build() — wrapper template propagation
    // -------------------------------------------------------------------------

    public function testCustomWrapperTemplateIsUsed(): void
    {
        $tpl = '<span class="fw">{input}</span>';
        $result = $this->fb->build([
            ['type' => 'text', 'name' => 'n', 'id' => 'f-n', 'label' => 'N'],
        ], $tpl);

        $this->assertStringContainsString('class="fw"', $result['n']);
    }

    // -------------------------------------------------------------------------
    // descriptorsFromAdditionalFields() — type mapping
    // -------------------------------------------------------------------------

    public function testTextAdditionalField(): void
    {
        $field = new AdditionalField('fave', 'Favourite', 'text', false);
        $descs = $this->fb->descriptorsFromAdditionalFields([$field], ['field_fave' => 'Jazz']);

        $this->assertCount(1, $descs);
        $this->assertSame('text', $descs[0]['type']);
        $this->assertSame('field_fave', $descs[0]['name']);
        $this->assertSame('Jazz', $descs[0]['value']);
    }

    public function testDropdownAdditionalField(): void
    {
        $field = new AdditionalField('colour', 'Colour', 'dropdown', false, 'Red,Blue,Green');
        $descs = $this->fb->descriptorsFromAdditionalFields([$field], ['field_colour' => 'Blue']);

        $this->assertSame('select', $descs[0]['type']);
        $this->assertArrayHasKey('Blue', $descs[0]['options']);
        $this->assertSame('Blue', $descs[0]['value']);
    }

    public function testBooleanCheckboxAdditionalField(): void
    {
        $field = new AdditionalField('agree', 'I agree', 'checkbox', false);
        $descs = $this->fb->descriptorsFromAdditionalFields([$field], ['field_agree' => '1']);

        $this->assertSame('checkboxGroup', $descs[0]['type']);
        $this->assertSame(['1'], $descs[0]['selected']);
    }

    public function testMultiCheckboxStringOldValue(): void
    {
        $field = new AdditionalField('tags', 'Tags', 'checkbox', false, 'PHP,JS,CSS');
        $descs = $this->fb->descriptorsFromAdditionalFields([$field], ['field_tags' => 'PHP,CSS']);

        $this->assertSame(['PHP', 'CSS'], $descs[0]['selected']);
    }

    public function testMultiCheckboxArrayOldValue(): void
    {
        // Simulates a POST where field_tags[] arrives as a PHP array
        $field = new AdditionalField('tags', 'Tags', 'checkbox', false, 'PHP,JS,CSS');
        $descs = $this->fb->descriptorsFromAdditionalFields([$field], ['field_tags' => ['PHP', 'CSS']]);

        $this->assertSame(['PHP', 'CSS'], $descs[0]['selected']);
    }

    public function testRadioAdditionalField(): void
    {
        $field = new AdditionalField('mood', 'Mood', 'radio', false, 'Happy,Sad');
        $descs = $this->fb->descriptorsFromAdditionalFields([$field], ['field_mood' => 'Happy']);

        $this->assertSame('radioGroup', $descs[0]['type']);
        $this->assertSame('Happy', $descs[0]['value']);
    }

    public function testTextareaAdditionalField(): void
    {
        $field = new AdditionalField('bio', 'Bio', 'textarea', false);
        $descs = $this->fb->descriptorsFromAdditionalFields([$field], ['field_bio' => 'Hello world']);

        $this->assertSame('textarea', $descs[0]['type']);
        $this->assertSame('Hello world', $descs[0]['value']);
    }

    public function testEmptyOldValueDefaultsToEmptyString(): void
    {
        $field = new AdditionalField('note', 'Note', 'text', false);
        $descs = $this->fb->descriptorsFromAdditionalFields([$field], []);

        $this->assertSame('', $descs[0]['value']);
    }

    public function testMultipleFieldsReturnOneDescriptorEach(): void
    {
        $fields = [
            new AdditionalField('a', 'A', 'text', false),
            new AdditionalField('b', 'B', 'text', false),
            new AdditionalField('c', 'C', 'text', false),
        ];

        $descs = $this->fb->descriptorsFromAdditionalFields($fields, []);

        $this->assertCount(3, $descs);
        $this->assertSame('field_a', $descs[0]['name']);
        $this->assertSame('field_b', $descs[1]['name']);
        $this->assertSame('field_c', $descs[2]['name']);
    }
}
