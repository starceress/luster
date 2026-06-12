<?php

declare(strict_types=1);

use Luster\Support\ListingTemplateResolver;
use PHPUnit\Framework\TestCase;

final class ListingTemplateResolverTest extends TestCase
{
    private const BUILT_IN = 'built-in default';
    private const GLOBAL   = 'global default';
    private const CUSTOM   = 'custom override';

    public function testBuiltInReturnedWhenAllNull(): void
    {
        $result = ListingTemplateResolver::resolve(null, null, self::BUILT_IN);
        self::assertSame(self::BUILT_IN, $result['value']);
        self::assertSame('built_in',     $result['source']);
        self::assertFalse($result['should_render_template']);
    }

    public function testBuiltInReturnedWhenBothEmpty(): void
    {
        $result = ListingTemplateResolver::resolve('', '', self::BUILT_IN);
        self::assertSame(self::BUILT_IN, $result['value']);
        self::assertSame('built_in',     $result['source']);
        self::assertFalse($result['should_render_template']);
    }

    public function testGlobalReturnedWhenPerListingNullOrEmpty(): void
    {
        foreach ([null, '', '   '] as $perListing) {
            $result = ListingTemplateResolver::resolve($perListing, self::GLOBAL, self::BUILT_IN);
            self::assertSame(self::GLOBAL, $result['value'],  "Failed for perListing=" . json_encode($perListing));
            self::assertSame('global',     $result['source'], "Failed for perListing=" . json_encode($perListing));
            self::assertTrue($result['should_render_template']);
        }
    }

    public function testCustomReturnedWhenPerListingSet(): void
    {
        $result = ListingTemplateResolver::resolve(self::CUSTOM, self::GLOBAL, self::BUILT_IN);
        self::assertSame(self::CUSTOM, $result['value']);
        self::assertSame('custom',     $result['source']);
        self::assertTrue($result['should_render_template']);
    }

    public function testCustomTakesPrecedenceEvenWhenGlobalNull(): void
    {
        $result = ListingTemplateResolver::resolve(self::CUSTOM, null, self::BUILT_IN);
        self::assertSame(self::CUSTOM, $result['value']);
        self::assertSame('custom',     $result['source']);
        self::assertTrue($result['should_render_template']);
    }

    public function testWhitespaceOnlyPerListingFallsToGlobal(): void
    {
        $result = ListingTemplateResolver::resolve("  \n  ", self::GLOBAL, self::BUILT_IN);
        self::assertSame(self::GLOBAL, $result['value']);
        self::assertSame('global',     $result['source']);
    }

    public function testWhitespaceOnlyGlobalFallsToBuiltIn(): void
    {
        $result = ListingTemplateResolver::resolve(null, "  \n  ", self::BUILT_IN);
        self::assertSame(self::BUILT_IN, $result['value']);
        self::assertSame('built_in',     $result['source']);
    }
}
