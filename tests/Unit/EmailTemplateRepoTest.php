<?php

declare(strict_types=1);

namespace Luster\Tests\Unit;

use Luster\Repositories\EmailTemplateRepo;
use PHPUnit\Framework\TestCase;

final class EmailTemplateRepoTest extends TestCase
{
    public function testDefaultJoinBodyOmitsSensitiveTokens(): void
    {
        $body = EmailTemplateRepo::defaultBody(EmailTemplateRepo::TYPE_JOIN);

        self::assertStringNotContainsString('{reset_url}', $body,
            'Default join email should not embed {reset_url} — blank for pending joins');
        self::assertStringNotContainsString('{fan_password}', $body,
            'Default join email should not include plaintext password per NIST/OWASP guidance');
        self::assertStringContainsString('password reset form', $body);
    }

    public function testBuildTokensIncludesResetUrlAndFanPassword(): void
    {
        $tokens = EmailTemplateRepo::buildTokens(
            listingName: 'Example',
            listingUrl: 'https://example.test/listing',
            siteName: 'Luster',
            resetUrl: 'https://example.test/reset/token',
            fanPassword: 'secret123',
        );

        self::assertSame('https://example.test/reset/token', $tokens['{reset_url}']);
        self::assertSame('secret123', $tokens['{fan_password}']);
    }

    public function testBuildTokensFanPasswordDefaultsToEmpty(): void
    {
        $tokens = EmailTemplateRepo::buildTokens(
            listingName: 'Example',
            listingUrl: 'https://example.test/listing',
            siteName: 'Luster',
        );

        self::assertSame('', $tokens['{fan_password}']);
    }
}
