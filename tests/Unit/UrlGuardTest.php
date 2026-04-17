<?php

declare(strict_types=1);

namespace Luster\Tests\Unit;

use Luster\Core\UrlGuard;
use PHPUnit\Framework\TestCase;

final class UrlGuardTest extends TestCase
{
    public function testIsSameOriginUrlAcceptsMatchingOrigin(): void
    {
        self::assertTrue(UrlGuard::isSameOriginUrl(
            'https://example.test/admin/emails?tab=bulk',
            'https://example.test',
        ));
    }

    public function testIsSameOriginUrlRejectsLookalikeAndRelativeUrls(): void
    {
        self::assertFalse(UrlGuard::isSameOriginUrl(
            'https://example.test.evil.test/admin/emails',
            'https://example.test',
        ));
        self::assertFalse(UrlGuard::isSameOriginUrl(
            '/admin/emails?tab=bulk',
            'https://example.test',
        ));
    }
}
