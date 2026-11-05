<?php

declare(strict_types=1);

namespace Luster\Tests\Unit;

use Luster\Core\Validator;
use Luster\Models\AdditionalField;
use Luster\Support\FieldAnswers;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * What a field's control is, which answers it accepts, and what a form keeps to show
 * again after an error. These read and write nothing.
 */
final class AdditionalFieldAnswerTest extends TestCase
{
    private static function field(string $type, string $options = '', bool $required = false): AdditionalField
    {
        return new AdditionalField('a', 'Thing', $type, $required, $options);
    }

    /** @return array<string, array{0: string, 1: string, 2: string}> */
    public static function kinds(): array
    {
        return [
            'text'                => ['text', '', 'text'],
            'textarea'            => ['textarea', '', 'textarea'],
            'dropdown'            => ['dropdown', 'A, B', 'select'],
            'dropdown, none'      => ['dropdown', '', 'text'],
            'dropdown, only gaps' => ['dropdown', ' , ,', 'text'],
            'select'              => ['select', 'A, B', 'select'],
            'select, none'        => ['select', '', 'text'],
            'radio'               => ['radio', 'A, B', 'radio'],
            'radio, none'         => ['radio', '', 'text'],
            'checkbox group'      => ['checkbox', 'A, B', 'checkboxes'],
            'yes box'             => ['checkbox', '', 'checkbox'],
            'anything else'       => ['carrier-pigeon', 'A, B', 'text'],
        ];
    }

    #[DataProvider('kinds')]
    public function testControlKind(string $type, string $options, string $kind): void
    {
        self::assertSame($kind, self::field($type, $options)->controlKind());
    }

    /** @return array<string, array{0: string, 1: string, 2: string}> */
    public static function labels(): array
    {
        return [
            'text'           => ['text', '', 'Text Field'],
            'textarea'       => ['textarea', '', 'Text Area'],
            'dropdown'       => ['dropdown', 'A', 'Dropdown'],
            'dropdown, none' => ['dropdown', '', 'Dropdown (no choices yet)'],
            'select'         => ['select', 'A', 'Dropdown'],
            'select, none'   => ['select', '', 'Dropdown (no choices yet)'],
            'radio'          => ['radio', 'A', 'Radio Button'],
            'radio, none'    => ['radio', '', 'Radio Button (no choices yet)'],
            'group'          => ['checkbox', 'A', 'Checkbox'],
            'yes box'        => ['checkbox', '', 'Checkbox'],
        ];
    }

    #[DataProvider('labels')]
    public function testTypeLabel(string $type, string $options, string $label): void
    {
        self::assertSame($label, self::field($type, $options)->typeLabel());
    }

    /** @return array<string, array{0: AdditionalField, 1: mixed, 2: string, 3: ?string}> */
    public static function answers(): array
    {
        $bad  = "Thing: that answer isn't valid.";
        $one  = 'Thing: pick one of the listed options.';
        $from = 'Thing: pick from the listed options.';

        return [
            'text'                 => [self::field('text'), '  hello ', 'hello', null],
            'text, blank'          => [self::field('text'), '', '', null],
            'text, zero'           => [self::field('text'), '0', '0', null],
            'text, a list'         => [self::field('text'), ['a'], '', $bad],
            'textarea, a list'     => [self::field('textarea'), ['a'], '', $bad],
            'text, a number'       => [self::field('text'), 5, '', $bad],
            'dropdown'             => [self::field('dropdown', 'Cat, Dog'), ' Dog ', 'Dog', null],
            'dropdown, blank'      => [self::field('dropdown', 'Cat, Dog'), '', '', null],
            'dropdown, off list'   => [self::field('dropdown', 'Cat, Dog'), 'Fish', '', $one],
            'dropdown, capitals'   => [self::field('dropdown', 'Cat, Dog'), 'dog', '', $one],
            'dropdown, a list'     => [self::field('dropdown', 'Cat, Dog'), ['Cat'], '', $bad],
            'dropdown, zero'       => [self::field('dropdown', '0, 1'), '0', '0', null],
            'dropdown, no choices' => [self::field('dropdown'), 'Anything', 'Anything', null],
            'select'               => [self::field('select', 'Cat, Dog'), 'Cat', 'Cat', null],
            'select, off list'     => [self::field('select', 'Cat, Dog'), 'Fish', '', $one],
            'select, no choices'   => [self::field('select'), 'Anything', 'Anything', null],
            'radio'                => [self::field('radio', 'Cat, Dog'), 'Cat', 'Cat', null],
            'radio, off list'      => [self::field('radio', 'Cat, Dog'), 'Fish', '', $one],
            'radio, a list'        => [self::field('radio', 'Cat, Dog'), ['Cat'], '', $bad],
            'radio, no choices'    => [self::field('radio'), 'Anything', 'Anything', null],
            'group'                => [self::field('checkbox', 'R, G, B'), ['R', ' B '], 'R, B', null],
            'group, none'          => [self::field('checkbox', 'R, G, B'), [], '', null],
            'group, nothing sent'  => [self::field('checkbox', 'R, G, B'), '', '', null],
            'group, blanks'        => [self::field('checkbox', 'R, G, B'), ['', '  '], '', null],
            'group, twice'         => [self::field('checkbox', 'R, G, B'), ['R', 'R'], 'R', null],
            'group, one as text'   => [self::field('checkbox', 'R, G, B'), 'R', 'R', null],
            'group, zero'          => [self::field('checkbox', '0, 1'), ['0'], '0', null],
            'group, off list'      => [self::field('checkbox', 'R, G, B'), ['R', 'X'], '', $from],
            'group, text off list' => [self::field('checkbox', 'R, G, B'), 'X', '', $from],
            'group, nested'        => [self::field('checkbox', 'R, G, B'), [['R']], '', $bad],
            'group, named keys'    => [self::field('checkbox', 'R, G, B'), ['x' => 'R'], '', $bad],
            'yes box, ticked'      => [self::field('checkbox'), '1', '1', null],
            'yes box, unticked'    => [self::field('checkbox'), '', '', null],
            'yes box, other'       => [self::field('checkbox'), 'yes', '', $bad],
            'yes box, a list'      => [self::field('checkbox'), ['1'], '', $bad],
        ];
    }

    #[DataProvider('answers')]
    public function testAnswerFrom(AdditionalField $field, mixed $raw, string $value, ?string $error): void
    {
        self::assertSame(['value' => $value, 'error' => $error], $field->answerFrom($raw));
    }

    public function testAnAnswerCanBeAtMost255CharactersCountedInLetters(): void
    {
        $fits = str_repeat('é', 255);

        self::assertNull(self::field('text')->answerFrom($fits)['error']);
        self::assertSame('Thing can be at most 255 characters.', self::field('text')->answerFrom($fits . 'x')['error']);

        $choices = implode(', ', array_map(static fn(int $i): string => sprintf('%03d', $i) . str_repeat('x', 57), range(1, 5)));
        $picks   = array_map('trim', explode(',', $choices));
        self::assertSame('Thing can be at most 255 characters.', self::field('checkbox', $choices)->answerFrom($picks)['error'], 'measured once joined');
    }

    /** @return array<string, array{0: AdditionalField, 1: string, 2: list<string>}> */
    public static function offered(): array
    {
        return [
            'no saved answer'    => [self::field('dropdown', 'Cat, Dog'), '', ['Cat', 'Dog']],
            'on the list'        => [self::field('dropdown', 'Cat, Dog'), 'Dog', ['Cat', 'Dog']],
            'retired'            => [self::field('dropdown', 'Cat, Dog'), 'Fish', ['Cat', 'Dog', 'Fish']],
            'retired, radio'     => [self::field('radio', 'Cat, Dog'), 'Fish', ['Cat', 'Dog', 'Fish']],
            'retired, spaces'    => [self::field('radio', 'Cat, Dog'), ' Fish ', ['Cat', 'Dog', 'Fish']],
            'a comma in one'     => [self::field('dropdown', 'Cat, Dog'), 'Fish, Chips', ['Cat', 'Dog', 'Fish, Chips']],
            'a group splits'     => [self::field('checkbox', 'R, G'), 'R, X, Y', ['R', 'G', 'X', 'Y']],
            'a group, no spaces' => [self::field('checkbox', 'R, G'), 'R,X', ['R', 'G', 'X']],
            'text has none'      => [self::field('text'), 'Fish', []],
            'no choices at all'  => [self::field('dropdown'), 'Fish', []],
            'zero'               => [self::field('radio', 'Cat'), '0', ['Cat', '0']],
        ];
    }

    #[DataProvider('offered')]
    public function testChoicesForASavedAnswer(AdditionalField $field, string $saved, array $choices): void
    {
        self::assertSame($choices, $field->choicesFor($saved));
    }

    public function testASavedAnswerIsAcceptedEvenThoughItIsNoLongerAChoice(): void
    {
        self::assertSame(['value' => 'Fish', 'error' => null], self::field('dropdown', 'Cat')->answerFrom('Fish', 'Fish'));
        self::assertSame(['value' => 'Fish', 'error' => null], self::field('radio', 'Cat')->answerFrom('Fish', 'Fish'));
        self::assertSame(['value' => 'R, X', 'error' => null], self::field('checkbox', 'R')->answerFrom(['R', 'X'], 'R, X'));
        self::assertSame(['value' => 'Red, Blue', 'error' => null], self::field('checkbox')->answerFrom('Red, Blue', 'Red, Blue'));
        self::assertSame('Thing: pick one of the listed options.', self::field('dropdown', 'Cat')->answerFrom('Other', 'Fish')['error']);
        self::assertSame("Thing: that answer isn't valid.", self::field('checkbox')->answerFrom('Other', 'Red, Blue')['error']);
    }

    // --- FieldAnswers::fromPost()

    public function testFromPostGivesTheAnswersAndPutsProblemsOnTheValidatorUnderTheFormsKey(): void
    {
        $fields = [self::field('text'), new AdditionalField('b', 'Bee', 'radio', true, 'X, Y'), new AdditionalField('c', 'Sea', 'text', true), new AdditionalField('d', 'Dee', 'text', true)];
        $v      = new Validator([]);

        $answers = FieldAnswers::fromPost($fields, ['field_a' => ' fine ', 'field_b' => 'Z', 'field_d' => '0'], $v);

        self::assertSame(['a' => 'fine', 'b' => '', 'c' => '', 'd' => '0'], $answers);
        self::assertSame([
            'field_b' => ['Bee: pick one of the listed options.'],
            'field_c' => ['Sea is required.'],
        ], $v->errors());
    }

    public function testFromPostAcceptsWhatTheMemberHasSaved(): void
    {
        $v = new Validator([]);

        self::assertSame(['a' => 'Old'], FieldAnswers::fromPost([self::field('radio', 'New')], ['field_a' => 'Old'], $v, ['a' => 'Old']));
        self::assertTrue($v->passes());
    }

    public function testFromPostHandlesAFieldWithANumericName(): void
    {
        $v = new Validator([]);

        $answers = FieldAnswers::fromPost([new AdditionalField('123', 'Num', 'radio', false, 'A')], ['field_123' => 'Saved'], $v, ['123' => 'Saved']);

        self::assertSame('Saved', $answers['123']);
        self::assertTrue($v->passes());
    }

    // --- FieldAnswers::oldForm()

    public function testOldFormKeepsTextAndFlatListsAndDropsAnythingElse(): void
    {
        $fields = [self::field('text'), new AdditionalField('b', 'B', 'checkbox', false, 'X'), new AdditionalField('c', 'C', 'text', false), new AdditionalField('d', 'D', 'text', false), new AdditionalField('e', 'E', 'text', false)];

        $old = FieldAnswers::oldForm([
            'name'     => ' Ann ',
            'email'    => ['x'],
            'field_a'  => ' typed ',
            'field_b'  => ['X', ' Y '],
            'field_c'  => [['nested']],
            'field_d'  => ['k' => 'named'],
            'password' => 'hunter2hunter2',
        ], ['name', 'email', 'url'], $fields);

        self::assertSame([
            'name'    => 'Ann',
            'field_a' => 'typed',
            'field_b' => ['X', 'Y'],
            'field_e' => '',
        ], $old);
    }

    public function testOldFormOnlyEverHasTheNamedKeysAndTheFields(): void
    {
        $old = FieldAnswers::oldForm(['password' => 'p', 'new_password' => 'p', 'password_confirm' => 'p', 'captcha_answer' => '7', 'name' => 'A'], ['name'], []);

        self::assertSame(['name' => 'A'], $old);
    }
}
