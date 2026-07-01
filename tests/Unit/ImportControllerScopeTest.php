<?php

declare(strict_types=1);

namespace Luster\Tests\Unit;

use Luster\Controllers\Admin\ImportController;
use PHPUnit\Framework\TestCase;
use ReflectionClassConstant;

/**
 * Regression tests for ImportController::COLLECTIVE_SCOPE_KEYS.
 *
 * When FanaticallyTemplateTranslator gains a new collective-scoped TYPE_TO_TARGET_KEY
 * entry, the matching key must also appear here — otherwise getTemplateKeyScope() would
 * route it to ListingSettingsRepo, which throws InvalidArgumentException at apply-time.
 */
final class ImportControllerScopeTest extends TestCase
{
    /** @return list<string> */
    private function collectiveKeys(): array
    {
        $rc = new ReflectionClassConstant(ImportController::class, 'COLLECTIVE_SCOPE_KEYS');
        return $rc->getValue();
    }

    // -----------------------------------------------------------------------
    // Known collective keys
    // -----------------------------------------------------------------------

    public function testCollectiveStatsMappedAsCollective(): void
    {
        self::assertContains('collective_stats_template', $this->collectiveKeys());
    }

    public function testOwnedTemplateMappedAsCollective(): void
    {
        self::assertContains('owned_template', $this->collectiveKeys());
    }

    public function testJoinedTemplateMappedAsCollective(): void
    {
        self::assertContains('joined_template', $this->collectiveKeys());
    }

    public function testWishlistTemplateMappedAsCollective(): void
    {
        // wishlist_template is stored in SiteSettingsRepo (collective), not
        // ListingSettingsRepo. Routing it to the listing repo would throw
        // InvalidArgumentException because 'wishlist_template' is not in the
        // saveTemplateColumnStrict() allowlist.
        self::assertContains('wishlist_template', $this->collectiveKeys());
    }

    // -----------------------------------------------------------------------
    // Sanity: no per-listing keys accidentally included
    // -----------------------------------------------------------------------

    public function testStatsTemplateNotInCollectiveKeys(): void
    {
        // stats_template is per-listing (ListingSettingsRepo), not collective.
        self::assertNotContains('stats_template', $this->collectiveKeys());
    }

    public function testEmailKeyNotInCollectiveKeys(): void
    {
        // email:* keys go to EmailTemplateRepo, never collective.
        self::assertNotContains('email:join', $this->collectiveKeys());
    }
}
