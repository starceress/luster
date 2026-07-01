<?php

declare(strict_types=1);

namespace Luster\Tests\Unit;

use Luster\Import\FanaticallyTemplateTranslator;
use Luster\Import\TranslationResult;
use PHPUnit\Framework\TestCase;

/**
 * Tests for FanaticallyTemplateTranslator.
 *
 * Verifies token mapping, PHP detection, unsupported-structure detection, and
 * importability logic. No I/O — pure string transformation.
 */
final class FanaticallyTemplateTranslatorTest extends TestCase
{
    private FanaticallyTemplateTranslator $t;

    protected function setUp(): void
    {
        $this->t = new FanaticallyTemplateTranslator();
    }

    private function translate(string $path, string $category, string $content): TranslationResult
    {
        return $this->t->translate($path, $category, $content);
    }

    // -----------------------------------------------------------------------
    // Stats template
    // -----------------------------------------------------------------------

    public function testStatsTranslatesMemberCount(): void
    {
        $r = $this->translate('templates/display/stats.php', 'display', '{member_count} members');
        self::assertStringContainsString('{stat_approved}', $r->translatedContent);
        self::assertStringNotContainsString('{member_count}', $r->translatedContent);
    }

    public function testStatsTranslatesTitle(): void
    {
        $r = $this->translate('templates/display/stats.php', 'display', '<h1>{title}</h1>');
        self::assertStringContainsString('{listing_title}', $r->translatedContent);
    }

    public function testStatsTranslatesApprovedBy(): void
    {
        $r = $this->translate('templates/display/stats.php', 'display', 'Network: {approved_by}');
        self::assertStringContainsString('{network_status}', $r->translatedContent);
        self::assertStringNotContainsString('{approved_by}', $r->translatedContent);
    }

    public function testStatsImportableWhenAllTokensMapped(): void
    {
        $content = '<p>{member_count} / {pending_count} / {countries_count}</p>';
        $r = $this->translate('templates/display/stats.php', 'display', $content);
        self::assertTrue($r->importable);
        self::assertEmpty($r->unmappedTokens);
        self::assertSame('stats_template', $r->targetTemplateKey);
    }

    public function testStatsNotImportableWithUnmappedToken(): void
    {
        $r = $this->translate('templates/display/stats.php', 'display', '<p>{description}</p>');
        self::assertFalse($r->importable);
        self::assertContains('{description}', $r->unmappedTokens);
    }

    // -----------------------------------------------------------------------
    // Collective stats template
    // -----------------------------------------------------------------------

    public function testCollectiveStatsTranslatesOwnedCount(): void
    {
        $r = $this->translate('templates/display/collective-stats.php', 'display', '{owned_count}');
        self::assertStringContainsString('{owned_active}', $r->translatedContent);
    }

    public function testCollectiveStatsTranslatesAffiliatesToSitewide(): void
    {
        $r = $this->translate('templates/display/collective-stats.php', 'display', '{affiliate_count}');
        self::assertStringContainsString('{affiliates_sitewide}', $r->translatedContent);
    }

    public function testCollectiveStatsTargetKey(): void
    {
        $r = $this->translate('templates/display/collective-stats.php', 'display', '{owned_count}');
        self::assertSame('collective_stats_template', $r->targetTemplateKey);
    }

    // -----------------------------------------------------------------------
    // Owned listings template
    // -----------------------------------------------------------------------

    public function testOwnedListingsTranslatesContentToCurrentContent(): void
    {
        $r = $this->translate('templates/display/owned-listings.php', 'display', '{content}');
        self::assertStringContainsString('{current_content}', $r->translatedContent);
    }

    public function testOwnedListingsTranslatesClosedCount(): void
    {
        $r = $this->translate('templates/display/owned-listings.php', 'display', '{closed_count}');
        self::assertStringContainsString('{closed_owned_count}', $r->translatedContent);
    }

    public function testOwnedListingsTargetKey(): void
    {
        $r = $this->translate('templates/display/owned-listings.php', 'display', '{content}');
        self::assertSame('owned_template', $r->targetTemplateKey);
    }

    // -----------------------------------------------------------------------
    // Email templates
    // -----------------------------------------------------------------------

    public function testEmailJoinTranslatesName(): void
    {
        $r = $this->translate('templates/email/join.txt', 'email', 'Dear {name},');
        self::assertStringContainsString('{fan_name}', $r->translatedContent);
        self::assertStringNotContainsString('{name}', $r->translatedContent);
    }

    public function testEmailJoinTranslatesPassword(): void
    {
        $r = $this->translate('templates/email/join.txt', 'email', 'Your password: {password}');
        self::assertStringContainsString('{fan_password}', $r->translatedContent);
    }

    public function testEmailJoinTargetKey(): void
    {
        $r = $this->translate('templates/email/join.txt', 'email', 'Dear {name},');
        self::assertSame('email:join', $r->targetTemplateKey);
    }

    public function testEmailJoinImportableWhenMapped(): void
    {
        $r = $this->translate('templates/email/join.txt', 'email',
            "Dear {name},\nYou joined {listing_title}.\n{owner_name}");
        self::assertTrue($r->importable);
    }

    public function testEmailApproveTargetKey(): void
    {
        $r = $this->translate('templates/email/approved.txt', 'email', 'Dear {name},');
        self::assertSame('email:approve', $r->targetTemplateKey);
    }

    public function testEmailAdminNewMemberTargetKey(): void
    {
        $r = $this->translate('templates/email/admin-new-member.txt', 'email', '{name}');
        self::assertSame('email:notify_join', $r->targetTemplateKey);
    }

    // -----------------------------------------------------------------------
    // PHP detection
    // -----------------------------------------------------------------------

    public function testDetectsPhpOpenTag(): void
    {
        $r = $this->translate('templates/display/stats.php', 'display', '<?php echo "hi"; ?>');
        self::assertTrue($r->containsPhp);
        self::assertFalse($r->importable);
    }

    public function testDetectsShortEchoTag(): void
    {
        $r = $this->translate('templates/display/stats.php', 'display', '<?= $foo ?>');
        self::assertTrue($r->containsPhp);
        self::assertFalse($r->importable);
    }

    public function testDoesNotFlagXmlDeclaration(): void
    {
        $r = $this->translate('templates/display/stats.php', 'display', '<?xml version="1.0"?><root/>');
        self::assertFalse($r->containsPhp);
    }

    // -----------------------------------------------------------------------
    // Unsupported structure detection
    // -----------------------------------------------------------------------

    public function testJoinFormWithFormTokenIsUnsupported(): void
    {
        $r = $this->translate('templates/display/join.php', 'display', '<div>{form}</div>');
        self::assertTrue($r->unsupportedStructure);
        self::assertFalse($r->importable);
    }

    public function testAffiliatesWithAffiliatesBlockIsUnsupported(): void
    {
        $r = $this->translate('templates/display/affiliates.php', 'display', '{affiliates}');
        self::assertTrue($r->unsupportedStructure);
        self::assertFalse($r->importable);
    }

    public function testCodesWithCodesListIsUnsupported(): void
    {
        $r = $this->translate('templates/display/codes.php', 'display', '{codes_list}');
        self::assertTrue($r->unsupportedStructure);
        self::assertFalse($r->importable);
    }

    // -----------------------------------------------------------------------
    // Unknown path / no target key
    // -----------------------------------------------------------------------

    public function testUnknownPathIsNotImportable(): void
    {
        $r = $this->translate('some/unknown/path.php', 'display', '<p>Hello</p>');
        self::assertFalse($r->importable);
        self::assertNull($r->targetTemplateKey);
    }

    public function testMembersRowHasNullTargetKey(): void
    {
        $r = $this->translate('templates/display/members.php', 'display', '<tr></tr>');
        self::assertNull($r->targetTemplateKey);
        self::assertFalse($r->importable);
    }

    public function testHeadTemplateHasNullTargetKey(): void
    {
        $r = $this->translate('templates/display/head.php', 'display', '<link rel="stylesheet">');
        self::assertNull($r->targetTemplateKey);
        self::assertFalse($r->importable);
    }

    // -----------------------------------------------------------------------
    // Per-listing category
    // -----------------------------------------------------------------------

    public function testPerListingStatsIsImportable(): void
    {
        $r = $this->translate('listing-slug/templates/stats.php', 'per-listing', '{member_count}');
        self::assertSame('stats_template', $r->targetTemplateKey);
        self::assertStringContainsString('{stat_approved}', $r->translatedContent);
    }

    // -----------------------------------------------------------------------
    // TranslationResult value object
    // -----------------------------------------------------------------------

    public function testTranslationResultIsReadonly(): void
    {
        $r = $this->translate('templates/display/stats.php', 'display', '{member_count}');
        self::assertInstanceOf(TranslationResult::class, $r);
        self::assertIsString($r->translatedContent);
        self::assertIsArray($r->unmappedTokens);
        self::assertIsBool($r->containsPhp);
        self::assertIsBool($r->unsupportedStructure);
        self::assertIsBool($r->importable);
    }
}
