<?php

declare(strict_types=1);

namespace Luster\Tests\Unit;

use Luster\Models\AdditionalField;
use Luster\Support\FieldColumnMap;
use PHPUnit\Framework\TestCase;

/**
 * Which column belongs to which field. Every row here is a case where getting
 * it wrong would read another field's answer, or drop or overwrite real data.
 */
final class FieldColumnMapTest extends TestCase
{
    private const CORE = ['email', 'name', 'country', 'url', 'pending', 'password', 'showemail', 'showurl', 'added'];

    /**
     * @return array<string, array{0: list<string>, 1: list<string>, 2: bool, 3: array<string, ?string>}>
     *         definitions, extra columns beside the core ones, unprefixed layout?, expected map
     */
    public static function mapProvider(): array
    {
        return [
            // The standard schema: only additional_{name} is ever a field's.
            'standard: column exists'                  => [['fave'], ['additional_fave'], false, ['fave' => 'additional_fave']],
            'standard: column missing'                 => [['fave'], [], false, ['fave' => null]],
            'standard: a core word is fine, prefixed'  => [['name'], ['additional_name'], false, ['name' => 'additional_name']],
            'standard: a core word, column missing'    => [['name'], [], false, ['name' => null]],
            'standard: a bare column is never used'    => [['fave'], ['fave'], false, ['fave' => null]],
            'standard: repeated prefix'                => [['additional_x'], ['additional_additional_x', 'additional_x'], false, ['additional_x' => 'additional_additional_x']],
            'standard: capitals find the real column'  => [['Fave'], ['additional_fave'], false, ['Fave' => 'additional_fave']],
            'standard: two claim one column'           => [['Fave', 'fave'], ['additional_fave'], false, ['Fave' => null, 'fave' => null]],

            // A core word on the unprefixed schema never gets the real column.
            'core word: column ignored, none left'     => [['name'], [], true, ['name' => null]],
            'core word: in capitals'                   => [['Name'], [], true, ['Name' => null]],
            'core word: its additional_ column'        => [['name'], ['additional_name'], true, ['name' => 'additional_name']],
            'core word: in capitals, real spelling'    => [['EMAIL'], ['additional_email'], true, ['EMAIL' => 'additional_email']],
            'core word: its column is claimed'         => [['name', 'additional_name'], ['additional_name'], true, ['name' => null, 'additional_name' => 'additional_name']],
            'every core word is protected'             => [self::CORE, [], true, array_fill_keys(self::CORE, null)],

            // Anything else on the unprefixed schema.
            'bare column'                              => [['fave'], ['fave'], true, ['fave' => 'fave']],
            'bare column beats a fallback'             => [['fave'], ['fave', 'additional_fave'], true, ['fave' => 'fave']],
            'capitals find the real column'            => [['Fave'], ['fave'], true, ['Fave' => 'fave']],
            'capitals find the real fallback'          => [['Fave'], ['additional_fave'], true, ['Fave' => 'additional_fave']],
            'two claim one bare column'                => [['Fave', 'fave'], ['fave'], true, ['Fave' => null, 'fave' => null]],
            'two claim one fallback'                   => [['Fave', 'fave'], ['additional_fave'], true, ['Fave' => null, 'fave' => null]],
            'fallback, nobody else wants it'           => [['fave'], ['additional_fave'], true, ['fave' => 'additional_fave']],
            'fallback claimed by the field named that' => [['fave', 'additional_fave'], ['additional_fave'], true, ['fave' => null, 'additional_fave' => 'additional_fave']],
            'same, listed the other way round'         => [['additional_fave', 'fave'], ['additional_fave'], true, ['additional_fave' => 'additional_fave', 'fave' => null]],
            'bare and prefixed side by side'           => [['fave', 'additional_fave'], ['fave', 'additional_fave'], true, ['fave' => 'fave', 'additional_fave' => 'additional_fave']],
            'nothing at all'                           => [['fave'], [], true, ['fave' => null]],
            'repeated prefix, exact'                   => [['additional_x'], ['additional_x'], true, ['additional_x' => 'additional_x']],
            'repeated prefix, fallback'                => [['additional_x'], ['additional_additional_x'], true, ['additional_x' => 'additional_additional_x']],

            // Not real columns on the unprefixed table, so these are custom ones.
            'id, approved and updated are custom'      => [['id', 'approved', 'updated'], ['id', 'approved', 'updated'], true, ['id' => 'id', 'approved' => 'approved', 'updated' => 'updated']],

            // The same definition twice is one claim, not a clash.
            'a repeated definition still gets it'      => [['fave', 'fave'], ['fave'], true, ['fave' => 'fave']],
        ];
    }

    /**
     * @dataProvider mapProvider
     * @param list<string>          $definitions
     * @param list<string>          $extraColumns
     * @param array<string, ?string> $expected
     */
    public function testResolve(array $definitions, array $extraColumns, bool $bareLayout, array $expected): void
    {
        $fields  = array_map(static fn(string $name): AdditionalField => new AdditionalField($name, $name, 'text', false), $definitions);
        $core    = $bareLayout ? self::CORE : ['id', 'email', 'name', 'country', 'url', 'approved', 'password', 'showemail', 'showurl', 'added', 'updated'];
        $columns = array_merge($core, $extraColumns);

        self::assertSame($expected, FieldColumnMap::resolve($fields, $columns, $bareLayout));
    }

    public function testADigitsOnlyNameWorksEvenThoughPhpMakesItAnIntKey(): void
    {
        $field = new AdditionalField('123', 'Lucky', 'text', false);

        // A row fetched from the database gives the column as an int key.
        $map = FieldColumnMap::resolve([$field], array_keys(['email' => 'a', 123 => 'b']), true);
        self::assertSame('123', $map['123']);

        self::assertSame('additional_123', FieldColumnMap::resolve([$field], ['additional_123'], true)['123']);
        self::assertSame('additional_123', FieldColumnMap::resolve([$field], ['additional_123'], false)['123']);
        self::assertNull(FieldColumnMap::resolve([$field], ['email'], true)['123']);
    }

    public function testNoFieldsIsAnEmptyMap(): void
    {
        self::assertSame([], FieldColumnMap::resolve([], ['email'], true));
    }
}
