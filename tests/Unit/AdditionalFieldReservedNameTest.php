<?php

declare(strict_types=1);

namespace Luster\Tests\Unit;

use Luster\Models\AdditionalField;
use PHPUnit\Framework\TestCase;

/**
 * AdditionalField::isReservedName() blocks new field names that would clash with
 * a built-in member column, a {fan_...} template token, or the additional_ prefix
 * a field's column can have. FieldControllerColumnSafetyTest covers Add Field
 * using it.
 */
final class AdditionalFieldReservedNameTest extends TestCase
{
    public function testAdditionalFieldsNameIsReserved(): void
    {
        self::assertTrue(AdditionalField::isReservedName('additional_fields'));
    }

    public function testAdditionalFieldCellsNameIsReserved(): void
    {
        self::assertTrue(AdditionalField::isReservedName('additional_field_cells'));
    }

    public function testOrdinaryFieldNameIsNotReserved(): void
    {
        self::assertFalse(AdditionalField::isReservedName('fave_song'));
    }

    public function testAdditionalFieldHeadersIsNotReserved(): void
    {
        // {additional_field_headers} is a page-level token without the "fan_"
        // prefix, so a field named "field_headers" (or similar) can't collide
        // with it the way additional_fields/additional_field_cells do.
        self::assertFalse(AdditionalField::isReservedName('field_headers'));
    }

    public function testEveryNameInTheListIsReservedInAnyCase(): void
    {
        foreach (AdditionalField::RESERVED_NAMES as $name) {
            self::assertTrue(AdditionalField::isReservedName($name), $name);
            self::assertTrue(AdditionalField::isReservedName(strtoupper($name)), strtoupper($name));
            self::assertTrue(AdditionalField::isReservedName(ucfirst($name)), ucfirst($name));
        }
    }

    public function testAnythingWithTheAdditionalPrefixIsReserved(): void
    {
        self::assertTrue(AdditionalField::isReservedName('additional_x'));
        self::assertTrue(AdditionalField::isReservedName('Additional_Fields'));
        self::assertFalse(AdditionalField::isReservedName('additional'), 'No underscore, so not the prefix');
        self::assertFalse(AdditionalField::isReservedName('my_additional_x'));
    }

    public function testANameContainingAReservedWordIsFine(): void
    {
        self::assertFalse(AdditionalField::isReservedName('email_2'));
        self::assertFalse(AdditionalField::isReservedName('nickname'));
    }
}
