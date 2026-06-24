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

    public function testBuildTokensListingEmailPopulated(): void
    {
        $tokens = EmailTemplateRepo::buildTokens(
            listingName:  'Example',
            listingUrl:   'https://example.test/listing',
            siteName:     'Luster',
            listingEmail: 'contact@example.test',
        );

        self::assertSame('contact@example.test', $tokens['{listing_email}']);
    }

    public function testBuildTokensListingEmailDefaultsToEmpty(): void
    {
        $tokens = EmailTemplateRepo::buildTokens(
            listingName: 'Example',
            listingUrl:  'https://example.test/listing',
            siteName:    'Luster',
        );

        self::assertSame('', $tokens['{listing_email}']);
    }

    public function testBuildTokensFormUrlsPopulated(): void
    {
        $tokens = EmailTemplateRepo::buildTokens(
            listingName:   'Example',
            listingUrl:    'https://example.test/listing',
            siteName:      'Luster',
            formUrlJoin:   'https://example.test/join.php',
            formUrlUpdate: 'https://example.test/update.php',
            formUrlReset:  'https://example.test/reset.php',
        );

        self::assertSame('https://example.test/join.php',   $tokens['{form_url_join}']);
        self::assertSame('https://example.test/update.php', $tokens['{form_url_update}']);
        self::assertSame('https://example.test/reset.php',  $tokens['{form_url_reset}']);
    }

    public function testBuildTokensFormUrlsFallBackToListingUrl(): void
    {
        $tokens = EmailTemplateRepo::buildTokens(
            listingName: 'Example',
            listingUrl:  'https://example.test/listing',
            siteName:    'Luster',
        );

        self::assertSame('https://example.test/listing', $tokens['{form_url_join}']);
        self::assertSame('https://example.test/listing', $tokens['{form_url_update}']);
        self::assertSame('https://example.test/listing', $tokens['{form_url_reset}']);
    }

    public function testBuildTokensResolvesRelativeFormUrls(): void
    {
        $tokens = EmailTemplateRepo::buildTokens(
            listingName:   'Example',
            listingUrl:    'https://example.test/fanlisting/',
            siteName:      'Luster',
            formUrlJoin:   'join.php',
            formUrlUpdate: 'update.php',
            formUrlReset:  'reset.php',
        );

        self::assertSame('https://example.test/fanlisting/join.php',   $tokens['{form_url_join}']);
        self::assertSame('https://example.test/fanlisting/update.php', $tokens['{form_url_update}']);
        self::assertSame('https://example.test/fanlisting/reset.php',  $tokens['{form_url_reset}']);
    }

    public function testBuildTokensKeepsAbsoluteFormUrls(): void
    {
        $tokens = EmailTemplateRepo::buildTokens(
            listingName:   'Example',
            listingUrl:    'https://example.test/fanlisting/',
            siteName:      'Luster',
            formUrlJoin:   'https://other.example/join',
            formUrlUpdate: 'http://other.example/update',
            formUrlReset:  'https://other.example/reset',
        );

        self::assertSame('https://other.example/join',   $tokens['{form_url_join}']);
        self::assertSame('http://other.example/update',  $tokens['{form_url_update}']);
        self::assertSame('https://other.example/reset',  $tokens['{form_url_reset}']);
    }
}
