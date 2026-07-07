<?php

declare(strict_types=1);

namespace Luster\Tests\Unit;

use Luster\Repositories\EmailTemplateRepo;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the collective_update email template type — the verification
 * email sent when a fan requests a secure link to update their info across
 * every fanlisting they belong to (public/embed/collective-update.php).
 */
final class EmailTemplateRepoCollectiveUpdateTest extends TestCase
{
    public function testTypeIsRegisteredInTypesArray(): void
    {
        self::assertContains(EmailTemplateRepo::TYPE_COLLECTIVE_UPDATE, EmailTemplateRepo::TYPES);
    }

    public function testTypeIsCollectiveOnly(): void
    {
        self::assertContains(EmailTemplateRepo::TYPE_COLLECTIVE_UPDATE, EmailTemplateRepo::COLLECTIVE_ONLY_TYPES);
        self::assertContains(EmailTemplateRepo::TYPE_COLLECTIVE_UPDATE, EmailTemplateRepo::COLLECTIVE_TYPES);
    }

    public function testNotDisabledByDefault(): void
    {
        self::assertNotContains(EmailTemplateRepo::TYPE_COLLECTIVE_UPDATE, EmailTemplateRepo::DISABLED_BY_DEFAULT);
    }

    public function testCollectiveDefaultSubjectIsNonEmptyAndUsesSiteName(): void
    {
        $subject = EmailTemplateRepo::collectiveDefaultSubject(EmailTemplateRepo::TYPE_COLLECTIVE_UPDATE);

        self::assertNotSame('', $subject);
        self::assertStringContainsString('{site_name}', $subject);
    }

    public function testCollectiveDefaultBodyContainsRequiredTokens(): void
    {
        $body = EmailTemplateRepo::collectiveDefaultBody(EmailTemplateRepo::TYPE_COLLECTIVE_UPDATE);

        self::assertStringContainsString('{verify_url}', $body);
        self::assertStringContainsString('{membership_list}', $body);
        self::assertStringContainsString('{site_name}', $body);
    }

    public function testBuildCollectiveUpdateTokensContainsAllRequiredKeys(): void
    {
        $tokens = EmailTemplateRepo::buildCollectiveUpdateTokens();

        foreach (['{site_name}', '{site_url}', '{owner_name}', '{owner_email}', '{verify_url}', '{membership_list}'] as $key) {
            self::assertArrayHasKey($key, $tokens, "Missing token key: {$key}");
        }
    }

    public function testBuildCollectiveUpdateTokensMapsValuesCorrectly(): void
    {
        $tokens = EmailTemplateRepo::buildCollectiveUpdateTokens(
            siteName:       'My Site',
            ownerName:      'Owner',
            ownerEmail:     'owner@example.test',
            verifyUrl:      'https://example.test/update?token=abc',
            membershipList: "- Listing One\n- Listing Two",
            siteUrl:        'https://example.test',
        );

        self::assertSame('My Site',                              $tokens['{site_name}']);
        self::assertSame('https://example.test',                 $tokens['{site_url}']);
        self::assertSame('Owner',                                $tokens['{owner_name}']);
        self::assertSame('owner@example.test',                   $tokens['{owner_email}']);
        self::assertSame('https://example.test/update?token=abc', $tokens['{verify_url}']);
        self::assertSame("- Listing One\n- Listing Two",         $tokens['{membership_list}']);
    }

    public function testCollectiveDefaultsWithTokensAppliedProduceExpectedEmail(): void
    {
        $tokens = EmailTemplateRepo::buildCollectiveUpdateTokens(
            siteName:  'My Site',
            verifyUrl: 'https://example.test/update?token=abc',
        );

        $subject = EmailTemplateRepo::applyTokens(
            EmailTemplateRepo::collectiveDefaultSubject(EmailTemplateRepo::TYPE_COLLECTIVE_UPDATE),
            $tokens,
        );
        $body = EmailTemplateRepo::applyTokens(
            EmailTemplateRepo::collectiveDefaultBody(EmailTemplateRepo::TYPE_COLLECTIVE_UPDATE),
            $tokens,
        );

        self::assertSame('Update your memberships at My Site', $subject);
        self::assertStringContainsString('https://example.test/update?token=abc', $body);
        self::assertStringNotContainsString('{site_name}', $body);
        self::assertStringNotContainsString('{verify_url}', $body);
    }
}
