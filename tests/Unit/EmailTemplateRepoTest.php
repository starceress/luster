<?php

declare(strict_types=1);

namespace Luster\Tests\Unit;

use Luster\Repositories\EmailTemplateRepo;
use PHPUnit\Framework\TestCase;

final class EmailTemplateRepoTest extends TestCase
{
    public function testDefaultJoinBodyIncludesPasswordAndResetTokens(): void
    {
        $body = EmailTemplateRepo::defaultBody(EmailTemplateRepo::TYPE_JOIN);

        self::assertStringContainsString('{fan_password}', $body,
            'Default join email should include {fan_password} so members receive their password');
        self::assertStringContainsString('{reset_url}', $body,
            'Default join email should include {reset_url} for password resets');
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

        self::assertSame('https://example.test/join.php',   $tokens['{join_url}']);
        self::assertSame('https://example.test/update.php', $tokens['{update_url}']);
        self::assertSame($tokens['{join_url}'],   $tokens['{form_url_join}'],   'Legacy alias must match');
        self::assertSame($tokens['{update_url}'], $tokens['{form_url_update}'], 'Legacy alias must match');
        self::assertSame('https://example.test/reset.php', $tokens['{form_url_reset}'], 'Legacy reset alias');
    }

    public function testBuildTokensFormUrlsFallBackToListingUrl(): void
    {
        $tokens = EmailTemplateRepo::buildTokens(
            listingName: 'Example',
            listingUrl:  'https://example.test/listing',
            siteName:    'Luster',
        );

        self::assertSame('https://example.test/listing', $tokens['{join_url}']);
        self::assertSame('https://example.test/listing', $tokens['{update_url}']);
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

        self::assertSame('https://example.test/fanlisting/join.php',   $tokens['{join_url}']);
        self::assertSame('https://example.test/fanlisting/update.php', $tokens['{update_url}']);
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

        self::assertSame('https://other.example/join',   $tokens['{join_url}']);
        self::assertSame('http://other.example/update',  $tokens['{update_url}']);
    }
}
