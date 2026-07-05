<?php

declare(strict_types=1);

namespace Luster\Tests\Unit;

use Luster\Controllers\Admin\FieldController;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Verifies FieldController::isReservedFieldName() blocks the exact field
 * names that would collide with MembersTemplateRenderer's aggregate
 * {fan_additional_fields} / {fan_additional_field_cells} tokens, silently
 * shadowing that field's own {fan_<name>} value in member templates.
 */
final class FieldControllerReservedNameTest extends TestCase
{
    private ReflectionMethod $isReserved;

    protected function setUp(): void
    {
        $this->isReserved = new ReflectionMethod(FieldController::class, 'isReservedFieldName');
        $this->isReserved->setAccessible(true);
    }

    public function testAdditionalFieldsNameIsReserved(): void
    {
        self::assertTrue($this->invoke('additional_fields'));
    }

    public function testAdditionalFieldCellsNameIsReserved(): void
    {
        self::assertTrue($this->invoke('additional_field_cells'));
    }

    public function testOrdinaryFieldNameIsNotReserved(): void
    {
        self::assertFalse($this->invoke('fave_song'));
    }

    public function testAdditionalFieldHeadersIsNotReserved(): void
    {
        // {additional_field_headers} is a page-level token without the "fan_"
        // prefix, so a field named "field_headers" (or similar) can't collide
        // with it the way additional_fields/additional_field_cells do.
        self::assertFalse($this->invoke('field_headers'));
    }

    public function testMatchIsCaseSensitive(): void
    {
        self::assertFalse($this->invoke('Additional_Fields'));
    }

    private function invoke(string $name): bool
    {
        return (bool) $this->isReserved->invoke(null, $name);
    }
}
