<?php

declare(strict_types=1);

namespace Luster\Tests\Unit;

use Luster\Models\AdditionalField;
use Luster\Models\Member;
use Luster\Support\FieldAnswers;
use Luster\Support\FieldColumnMap;
use PHPUnit\Framework\TestCase;

/**
 * Member::fromRow() no longer guesses what a column means, and answers() reads
 * through the same map that saving uses. FieldAnswers::place() and
 * AdditionalField::flatAnswer() are the rules every save form shares.
 */
final class MemberAnswersTest extends TestCase
{
    private const OWNED_ROW = ['email' => 'ann@example.com', 'name' => 'Ann', 'country' => 'Canada', 'url' => '',
        'pending' => '0', 'password' => 'hash', 'showemail' => '1', 'showurl' => '1', 'added' => '2020-01-01'];
    private const ENTH_ROW = ['id' => '7', 'email' => 'ann@example.com', 'name' => 'Ann', 'country' => 'Canada', 'url' => '',
        'approved' => '1', 'password' => 'hash', 'showemail' => '1', 'showurl' => '1', 'added' => '2020-01-01', 'updated' => '2021-02-03'];

    private static function field(string $name, string $type = 'text'): AdditionalField
    {
        return new AdditionalField($name, ucfirst($name), $type, false);
    }

    // ------------------------------------------------------------------
    // What fromRow() takes from a row
    // ------------------------------------------------------------------

    public function testExtraColumnsKeepTheirRealNamesUntouched(): void
    {
        $owned = Member::fromRow(self::OWNED_ROW + ['Fave' => 'x', 'additional_y' => 'y', 123 => 'z']);
        self::assertSame(['Fave' => 'x', 'additional_y' => 'y', '123' => 'z'], $owned->extraColumns);

        $enth = Member::fromRow(self::ENTH_ROW + ['additional_fave' => 'x', 'stray' => 'y']);
        self::assertSame(['additional_fave' => 'x', 'stray' => 'y'], $enth->extraColumns);
    }

    public function testBuiltInColumnsAreBuiltInWhateverTheirCapitals(): void
    {
        $member = Member::fromRow(['email' => 'a@example.com', 'pending' => '0', 'ShowEmail' => '1', 'Added' => '2020-01-01', 'Fave' => 'x']);

        self::assertSame(['Fave' => 'x'], $member->extraColumns);
    }

    public function testTheSampleMembersAnswerTheSampleField(): void
    {
        $member = \Luster\Support\SampleTemplateData::members()[0];

        self::assertSame(['favorite_character' => 'Sailor Moon'], $member->answers(\Luster\Support\SampleTemplateData::additionalFields()));
    }

    public function testNullColumnsReadAsBlankText(): void
    {
        $member = Member::fromRow(self::OWNED_ROW + ['fave' => null]);

        self::assertSame(['fave' => ''], $member->extraColumns);
        self::assertSame(['fave' => ''], $member->answers([self::field('fave')]));
    }

    /** @dataProvider statusProvider */
    public function testStatusComesFromPendingWheneverTheRowHasIt(array $row, bool $approved, bool $rejected): void
    {
        $member = Member::fromRow($row);

        self::assertSame($approved, $member->approved);
        self::assertSame($rejected, $member->rejected);
    }

    /** @return array<string, array{0: array<string, mixed>, 1: bool, 2: bool}> */
    public static function statusProvider(): array
    {
        return [
            'pending 0'                         => [['pending' => '0'], true, false],
            'pending 1'                         => [['pending' => '1'], false, false],
            'pending 2 is rejected'             => [['pending' => '2'], false, true],
            'approved 1'                        => [['approved' => '1'], true, false],
            'approved 0'                        => [['approved' => '0'], false, false],
            'neither'                           => [['email' => 'a@example.com'], false, false],
            'a custom approved loses to pending' => [['pending' => '1', 'approved' => '1'], false, false],
            'a custom approved of 0 loses too'  => [['pending' => '0', 'approved' => '0'], true, false],
        ];
    }

    public function testOnARowWithPendingIdApprovedAndUpdatedAreCustomColumns(): void
    {
        $member = Member::fromRow(self::OWNED_ROW + ['id' => '42', 'approved' => 'custom approved', 'updated' => 'custom updated']);

        self::assertSame(0, $member->id);
        self::assertSame('', $member->updated);
        self::assertSame(['id' => '42', 'approved' => 'custom approved', 'updated' => 'custom updated'], $member->extraColumns);
        self::assertSame(
            ['id' => '42', 'approved' => 'custom approved', 'updated' => 'custom updated'],
            $member->answers([self::field('id'), self::field('approved'), self::field('updated')]),
        );
    }

    public function testOnTheStandardRowIdAndUpdatedAreRealAndNotExtras(): void
    {
        $member = Member::fromRow(self::ENTH_ROW);

        self::assertSame(7, $member->id);
        self::assertSame('2021-02-03', $member->updated);
        self::assertSame([], $member->extraColumns);
    }

    // ------------------------------------------------------------------
    // answers()
    // ------------------------------------------------------------------

    public function testAnswersFollowTheColumnRulesOnBothLayouts(): void
    {
        $owned = Member::fromRow(self::OWNED_ROW + ['fave' => 'bare', 'additional_fave' => 'prefixed', 'additional_mood' => 'fallback', 'additional_x' => 'own']);
        self::assertSame(
            ['fave' => 'bare', 'mood' => 'fallback', 'additional_x' => 'own', 'gone' => ''],
            $owned->answers([self::field('fave'), self::field('mood'), self::field('additional_x'), self::field('gone')]),
        );

        $enth = Member::fromRow(self::ENTH_ROW + ['fave' => 'bare', 'additional_fave' => 'prefixed', 'additional_additional_x' => 'doubled']);
        self::assertSame(
            ['fave' => 'prefixed', 'additional_x' => 'doubled'],
            $enth->answers([self::field('fave'), self::field('additional_x')]),
            'a bare column means nothing on the standard layout',
        );
    }

    public function testACoreNamedFieldNeverReadsTheCoreColumn(): void
    {
        $member = Member::fromRow(self::OWNED_ROW);
        self::assertSame(['name' => '', 'email' => '', 'password' => ''], $member->answers([self::field('name'), self::field('email'), self::field('password')]));

        $withColumn = Member::fromRow(self::OWNED_ROW + ['additional_name' => 'my answer']);
        self::assertSame(['name' => 'my answer'], $withColumn->answers([self::field('name')]));
        self::assertSame('Ann', $withColumn->name);
    }

    public function testCapitalsInADefinitionReachTheRealColumn(): void
    {
        $member = Member::fromRow(self::OWNED_ROW + ['fave' => 'lower']);
        self::assertSame(['Fave' => 'lower'], $member->answers([self::field('Fave')]));

        $upperColumn = Member::fromRow(self::OWNED_ROW + ['FAVE' => 'upper']);
        self::assertSame(['fave' => 'upper'], $upperColumn->answers([self::field('fave')]));
    }

    public function testNumericFieldNames(): void
    {
        $member = Member::fromRow(self::OWNED_ROW + [123 => 'bare', 'additional_456' => 'prefixed']);

        self::assertSame(['123' => 'bare', '456' => 'prefixed'], $member->answers([self::field('123'), self::field('456')]));
    }

    public function testTwoDefinitionsClaimingOneColumnBothReadBlank(): void
    {
        $member = Member::fromRow(self::OWNED_ROW + ['fave' => 'whose?']);

        self::assertSame(['Fave' => '', 'fave' => ''], $member->answers([self::field('Fave'), self::field('fave')]));
    }

    /**
     * Reads work from a row's keys and saves from a fresh column list. They have to
     * agree, or a form would show one column and save to another.
     *
     * @dataProvider mapAgreementProvider
     */
    public function testTheReadAndWriteMapsAgree(array $row, bool $bareLayout, array $fieldNames): void
    {
        $fields = array_map(static fn(string $n): AdditionalField => self::field($n), $fieldNames);

        $writeMap = FieldColumnMap::resolve($fields, array_keys($row), $bareLayout);
        $answers  = Member::fromRow($row)->answers($fields);

        foreach ($fieldNames as $name) {
            $column = $writeMap[$name];
            self::assertSame($column !== null ? (string) $row[$column] : '', $answers[$name], "field {$name}");
        }
    }

    /** @return array<string, array{0: array<int|string, string>, 1: bool, 2: list<string>}> */
    public static function mapAgreementProvider(): array
    {
        $owned = self::OWNED_ROW;
        $enth  = self::ENTH_ROW;
        return [
            'owned, plain'         => [$owned + ['fave' => 'a'], true, ['fave', 'gone']],
            'owned, core names'    => [$owned + ['additional_name' => 'a'], true, ['name', 'email', 'password', 'pending', 'added', 'showemail']],
            'owned, side by side'  => [$owned + ['fave' => 'a', 'additional_fave' => 'b'], true, ['fave', 'additional_fave']],
            'owned, custom core'   => [$owned + ['id' => 'a', 'approved' => 'b', 'updated' => 'c'], true, ['id', 'approved', 'updated']],
            'owned, numeric'       => [$owned + [123 => 'a', 'additional_456' => 'b'], true, ['123', '456']],
            'owned, capitals'      => [$owned + ['Fave' => 'a'], true, ['fave']],
            'enth, plain'          => [$enth + ['additional_fave' => 'a'], false, ['fave', 'gone']],
            'enth, core names'     => [$enth + ['additional_name' => 'a'], false, ['name', 'email', 'id', 'approved', 'updated']],
            'enth, stray bare'     => [$enth + ['fave' => 'a'], false, ['fave']],
            'enth, doubled'        => [$enth + ['additional_additional_x' => 'a'], false, ['additional_x']],
        ];
    }

    // ------------------------------------------------------------------
    // AdditionalField::flatAnswer()
    // ------------------------------------------------------------------

    public function testATrimmedStringIsTheAnswerForEveryType(): void
    {
        foreach (['text', 'textarea', 'radio', 'dropdown', 'select', 'checkbox'] as $type) {
            self::assertSame('hello', self::field('x', $type)->flatAnswer('  hello '), $type);
            self::assertSame('0', self::field('x', $type)->flatAnswer('0'), $type . ': "0" is an answer');
            self::assertSame('', self::field('x', $type)->flatAnswer('   '), $type);
        }
    }

    public function testOnlyACheckboxTakesAList(): void
    {
        self::assertSame('Red, Blue', self::field('x', 'checkbox')->flatAnswer(['Red', ' Blue ', '', '  ']));
        self::assertSame('', self::field('x', 'checkbox')->flatAnswer([]));

        foreach (['text', 'textarea', 'radio', 'dropdown', 'select'] as $type) {
            self::assertNull(self::field('x', $type)->flatAnswer(['Red']), $type);
            self::assertNull(self::field('x', $type)->flatAnswer([]), $type);
        }
    }

    public function testAnythingElseIsMalformed(): void
    {
        $checkbox = self::field('x', 'checkbox');

        self::assertNull($checkbox->flatAnswer([['nested']]));
        self::assertNull($checkbox->flatAnswer(['a' => 'b']), 'field_x[a]=b is not a flat list');
        self::assertNull($checkbox->flatAnswer([1]));
        self::assertNull($checkbox->flatAnswer(7));
        self::assertNull($checkbox->flatAnswer(null));
    }

    // ------------------------------------------------------------------
    // FieldAnswers::place()
    // ------------------------------------------------------------------

    public function testAnswersGoToTheirColumns(): void
    {
        $placed = FieldAnswers::place(
            [self::field('fave'), self::field('name'), self::field('123')],
            ['fave' => 'fave', 'name' => 'additional_name', '123' => '123'],
            ['fave' => 'a', 'name' => 'b', '123' => 'c'],
        );

        self::assertSame(['fave' => 'a', 'additional_name' => 'b', '123' => 'c'], $placed['values']);
        self::assertSame([], $placed['unplaceable']);
    }

    public function testABlankOrMissingAnswerStillClearsAColumn(): void
    {
        $placed = FieldAnswers::place([self::field('fave'), self::field('mood')], ['fave' => 'fave', 'mood' => 'mood'], ['fave' => '']);

        self::assertSame(['fave' => '', 'mood' => ''], $placed['values']);
    }

    public function testWithNoColumnBlankIsIgnoredAndARealAnswerIsReported(): void
    {
        $name = self::field('name');
        $zero = self::field('zero');

        $blank = FieldAnswers::place([$name], ['name' => null], ['name' => '']);
        self::assertSame(['values' => [], 'unplaceable' => []], $blank);

        $absent = FieldAnswers::place([$name], ['name' => null], []);
        self::assertSame(['values' => [], 'unplaceable' => []], $absent);

        $real = FieldAnswers::place([$name, $zero], ['name' => null, 'zero' => null], ['name' => 'x', 'zero' => '0']);
        self::assertSame([], $real['values']);
        self::assertSame([$name, $zero], $real['unplaceable'], '"0" is an answer');
    }

    public function testNoAnswerCanReachACoreColumn(): void
    {
        $fields  = array_map(static fn(string $n): AdditionalField => self::field($n), FieldColumnMap::CORE_COLUMNS);
        $columns = FieldColumnMap::resolve($fields, FieldColumnMap::CORE_COLUMNS, true);
        $answers = array_fill_keys(FieldColumnMap::CORE_COLUMNS, 'overwritten');

        $placed = FieldAnswers::place($fields, $columns, $answers);

        self::assertSame([], $placed['values']);
        self::assertCount(count(FieldColumnMap::CORE_COLUMNS), $placed['unplaceable']);
    }
}
