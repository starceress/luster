<?php

declare(strict_types=1);

namespace Luster\Tests\Unit;

use Luster\Repositories\EmailTemplateRepo;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the Adoption and KIM email template types added to EmailTemplateRepo.
 */
final class EmailTemplateRepoAdoptionKimTest extends TestCase
{
    // -----------------------------------------------------------------------
    // TYPES registry
    // -----------------------------------------------------------------------

    public function testAllFiveNewTypesAreRegisteredInTypesArray(): void
    {
        self::assertContains(EmailTemplateRepo::TYPE_ADOPTION_APPLIED,  EmailTemplateRepo::TYPES);
        self::assertContains(EmailTemplateRepo::TYPE_ADOPTION_APPROVED, EmailTemplateRepo::TYPES);
        self::assertContains(EmailTemplateRepo::TYPE_ADOPTION_REJECTED, EmailTemplateRepo::TYPES);
        self::assertContains(EmailTemplateRepo::TYPE_KIM_APPLIED,       EmailTemplateRepo::TYPES);
        self::assertContains(EmailTemplateRepo::TYPE_KIM_APPROVED,      EmailTemplateRepo::TYPES);
    }

    public function testTypesArrayHasEighteenEntries(): void
    {
        self::assertCount(18, EmailTemplateRepo::TYPES);
    }

    // -----------------------------------------------------------------------
    // Default subjects
    // -----------------------------------------------------------------------

    #[DataProvider('newTypeProvider')]
    public function testDefaultSubjectIsNonEmptyForNewTypes(string $type): void
    {
        $subject = EmailTemplateRepo::defaultSubject($type);
        self::assertNotSame('', $subject, "Default subject for {$type} must not be empty");
    }

    // -----------------------------------------------------------------------
    // Default bodies
    // -----------------------------------------------------------------------

    #[DataProvider('newTypeProvider')]
    public function testDefaultBodyIsNonEmptyForNewTypes(string $type): void
    {
        $body = EmailTemplateRepo::defaultBody($type);
        self::assertNotSame('', $body, "Default body for {$type} must not be empty");
    }

    public function testAdoptionAppliedDefaultSubjectContainsListingSubjectToken(): void
    {
        $subject = EmailTemplateRepo::defaultSubject(EmailTemplateRepo::TYPE_ADOPTION_APPLIED);
        self::assertStringContainsString('{listing_subject}', $subject);
    }

    public function testKimAppliedDefaultSubjectContainsListingSubjectToken(): void
    {
        $subject = EmailTemplateRepo::defaultSubject(EmailTemplateRepo::TYPE_KIM_APPLIED);
        self::assertStringContainsString('{listing_subject}', $subject);
    }

    // -----------------------------------------------------------------------
    // buildAdoptionTokens
    // -----------------------------------------------------------------------

    public function testBuildAdoptionTokensContainsAllRequiredKeys(): void
    {
        $tokens = EmailTemplateRepo::buildAdoptionTokens();

        foreach ([
            '{listing_title}', '{listing_url}',
            '{site_name}', '{owner_name}', '{owner_email}',
            '{applicant_name}', '{applicant_email}', '{applicant_url}', '{applicant_message}',
        ] as $key) {
            self::assertArrayHasKey($key, $tokens, "Missing token key: {$key}");
        }
    }

    public function testBuildAdoptionTokensMapsValuesCorrectly(): void
    {
        $tokens = EmailTemplateRepo::buildAdoptionTokens(
            listingName:      'Test Listing',
            listingUrl:       'https://example.test/listing',
            siteName:         'My Site',
            ownerName:        'Owner',
            ownerEmail:       'owner@example.test',
            applicantName:    'Alice',
            applicantEmail:   'alice@example.test',
            applicantUrl:     'https://alice.test',
            applicantMessage: '',
        );

        self::assertSame('Test Listing',               $tokens['{listing_title}']);
        self::assertSame('https://example.test/listing', $tokens['{listing_url}']);
        self::assertSame('My Site',                    $tokens['{site_name}']);
        self::assertSame('Owner',                      $tokens['{owner_name}']);
        self::assertSame('owner@example.test',         $tokens['{owner_email}']);
        self::assertSame('Alice',                      $tokens['{applicant_name}']);
        self::assertSame('alice@example.test',         $tokens['{applicant_email}']);
        self::assertSame('https://alice.test',         $tokens['{applicant_url}']);
        self::assertSame('',                           $tokens['{applicant_message}']);
    }

    public function testBuildAdoptionTokensAddsMessagePrefixWhenMessageNonEmpty(): void
    {
        $tokens = EmailTemplateRepo::buildAdoptionTokens(applicantMessage: 'Please adopt me!');

        self::assertStringStartsWith('Message: ', $tokens['{applicant_message}']);
        self::assertStringContainsString('Please adopt me!', $tokens['{applicant_message}']);
    }

    public function testBuildAdoptionTokensEmptyMessageProducesEmptyString(): void
    {
        $tokens = EmailTemplateRepo::buildAdoptionTokens(applicantMessage: '');
        self::assertSame('', $tokens['{applicant_message}']);
    }

    // -----------------------------------------------------------------------
    // buildKimTokens
    // -----------------------------------------------------------------------

    public function testBuildKimTokensContainsAllRequiredKeys(): void
    {
        $tokens = EmailTemplateRepo::buildKimTokens();

        foreach ([
            '{listing_title}', '{listing_url}',
            '{site_name}', '{owner_name}', '{owner_email}',
            '{kim_name}', '{kim_email}', '{kim_url}', '{kim_password}', '{kim_update_url}',
        ] as $key) {
            self::assertArrayHasKey($key, $tokens, "Missing token key: {$key}");
        }
    }

    public function testBuildKimTokensMapsValuesCorrectly(): void
    {
        $tokens = EmailTemplateRepo::buildKimTokens(
            listingName: 'Test Listing',
            listingUrl:  'https://example.test/listing',
            siteName:    'My Site',
            ownerName:   'Owner',
            ownerEmail:  'owner@example.test',
            kimName:     'Bob',
            kimEmail:    'bob@example.test',
            kimUrl:      'https://bob.test',
        );

        self::assertSame('Test Listing',                 $tokens['{listing_title}']);
        self::assertSame('https://example.test/listing', $tokens['{listing_url}']);
        self::assertSame('My Site',                      $tokens['{site_name}']);
        self::assertSame('Owner',                        $tokens['{owner_name}']);
        self::assertSame('owner@example.test',           $tokens['{owner_email}']);
        self::assertSame('Bob',                          $tokens['{kim_name}']);
        self::assertSame('bob@example.test',             $tokens['{kim_email}']);
        self::assertSame('https://bob.test',             $tokens['{kim_url}']);
    }

    public function testBuildKimTokensDefaultsAllToEmptyString(): void
    {
        $tokens = EmailTemplateRepo::buildKimTokens();

        foreach ($tokens as $key => $value) {
            self::assertSame('', $value, "Token {$key} should default to empty string");
        }
    }

    // -----------------------------------------------------------------------
    // Data providers
    // -----------------------------------------------------------------------

    /** @return array<string, array{string}> */
    public static function newTypeProvider(): array
    {
        return [
            'adoption_applied'  => [EmailTemplateRepo::TYPE_ADOPTION_APPLIED],
            'adoption_approved' => [EmailTemplateRepo::TYPE_ADOPTION_APPROVED],
            'adoption_rejected' => [EmailTemplateRepo::TYPE_ADOPTION_REJECTED],
            'kim_applied'       => [EmailTemplateRepo::TYPE_KIM_APPLIED],
            'kim_approved'      => [EmailTemplateRepo::TYPE_KIM_APPROVED],
        ];
    }
}
