<?php

declare(strict_types=1);

namespace Luster\Tests\Unit;

use Luster\Repositories\EmailTemplateRepo;
use PHPUnit\Framework\TestCase;

final class EmailTemplateRepoTest extends TestCase
{
    public function testDefaultJoinBodyNoLongerContainsPasswordLine(): void
    {
        $body = EmailTemplateRepo::defaultBody(EmailTemplateRepo::TYPE_JOIN);

        self::assertStringNotContainsString('Password:', $body);
        self::assertStringContainsString('password reset form', $body);
    }

    public function testBuildTokensStillIncludesResetUrl(): void
    {
        $tokens = EmailTemplateRepo::buildTokens(
            listingName: 'Example',
            listingUrl: 'https://example.test/listing',
            siteName: 'Luster',
            resetUrl: 'https://example.test/reset/token',
        );

        self::assertSame('https://example.test/reset/token', $tokens['{reset_url}']);
        self::assertArrayNotHasKey('{fan_password}', $tokens);
    }
}
