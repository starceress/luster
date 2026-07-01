<?php

declare(strict_types=1);

namespace Luster\Tests\Unit;

use Luster\Controllers\Admin\TemplatePackController;
use Luster\Core\Version;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionClassConstant;
use ReflectionMethod;

/**
 * Tests for the ZIP export additions to TemplatePackController:
 * - TEMPLATE_KEYS and COLLECTIVE_TEMPLATE_KEYS constant completeness
 * - detectRequiredAddons() private method logic (via reflection)
 * - ZIP archive structure produced by the shared buildZipFile() helper (via reflection)
 */
final class TemplatePackZipExportTest extends TestCase
{
    // -------------------------------------------------------------------------
    // TEMPLATE_KEYS constant
    // -------------------------------------------------------------------------

    public function testTemplateKeysContainsStatsTemplate(): void
    {
        self::assertContains('stats_template', $this->getTemplateKeys());
    }

    public function testTemplateKeysContainsMembersTemplate(): void
    {
        self::assertContains('members_template', $this->getTemplateKeys());
    }

    public function testTemplateKeysContainsCodesTemplate(): void
    {
        self::assertContains('codes_template', $this->getTemplateKeys());
    }

    public function testTemplateKeysContainsJoinFormTemplate(): void
    {
        self::assertContains('join_form_template', $this->getTemplateKeys());
    }

    public function testTemplateKeysContainsUpdateFormTemplate(): void
    {
        self::assertContains('update_form_template', $this->getTemplateKeys());
    }

    public function testTemplateKeysContainsResetFormTemplate(): void
    {
        self::assertContains('reset_form_template', $this->getTemplateKeys());
    }

    // -------------------------------------------------------------------------
    // COLLECTIVE_TEMPLATE_KEYS constant
    // -------------------------------------------------------------------------

    public function testCollectiveTemplateKeysContainsOwnedTemplate(): void
    {
        self::assertContains('owned_template', $this->getCollectiveTemplateKeys());
    }

    public function testCollectiveTemplateKeysContainsJoinedTemplate(): void
    {
        self::assertContains('joined_template', $this->getCollectiveTemplateKeys());
    }

    public function testCollectiveTemplateKeysContainsCollectiveStatsTemplate(): void
    {
        self::assertContains('collective_stats_template', $this->getCollectiveTemplateKeys());
    }

    public function testCollectiveTemplateKeysContainsKimSignupFormTemplate(): void
    {
        self::assertContains('kim_signup_form_template', $this->getCollectiveTemplateKeys());
    }

    public function testCollectiveTemplateKeysContainsWishlistTemplate(): void
    {
        self::assertContains('wishlist_template', $this->getCollectiveTemplateKeys());
    }

    // -------------------------------------------------------------------------
    // detectRequiredAddons() via reflection
    // -------------------------------------------------------------------------

    public function testDetectRequiredAddonsFindsUpdatesAddon(): void
    {
        $addons = $this->callDetectRequiredAddons([
            'updates_template' => '{each_update}<li>{update_title}: {update_body}</li>{/each_update}',
        ]);
        self::assertContains('Updates', $addons);
    }

    public function testDetectRequiredAddonsFindsKimAddon(): void
    {
        $addons = $this->callDetectRequiredAddons([
            'kim_signup_form_template' => '<p>Hello {kim_name}, welcome to the KIM list.</p>',
        ]);
        self::assertContains('Kim', $addons);
    }

    public function testDetectRequiredAddonsFindsWishlistAddon(): void
    {
        $addons = $this->callDetectRequiredAddons([
            'wishlist_template' => '{wishlist_items}{/wishlist_items}',
        ]);
        self::assertContains('Wishlist', $addons);
    }

    public function testDetectRequiredAddonsFindsAdoptionAddon(): void
    {
        $addons = $this->callDetectRequiredAddons([
            'adopt_form_template' => '<p>adopt_form_template field present</p>',
        ]);
        self::assertContains('Adoption', $addons);
    }

    public function testDetectRequiredAddonsReturnsEmptyForPlainHtml(): void
    {
        $addons = $this->callDetectRequiredAddons([
            'stats_template'   => '<p>Members: {stat_approved}</p>',
            'members_template' => '<li>{fan_name}</li>',
        ]);
        self::assertSame([], $addons);
    }

    public function testDetectRequiredAddonsDeduplicatesMultipleSignals(): void
    {
        $addons = $this->callDetectRequiredAddons([
            'updates_template' => '{each_update}{update_title}{update_body}{/each_update}',
        ]);
        self::assertSame(1, count(array_keys($addons, 'Updates')));
    }

    public function testDetectRequiredAddonsHandlesEmptyInput(): void
    {
        $addons = $this->callDetectRequiredAddons([]);
        self::assertSame([], $addons);
    }

    public function testDetectRequiredAddonsHandlesNullValues(): void
    {
        $addons = $this->callDetectRequiredAddons(['stats_template' => null]);
        self::assertSame([], $addons);
    }

    // -------------------------------------------------------------------------
    // buildZipFile() structure tests (via reflection — exercises real production code)
    // -------------------------------------------------------------------------

    public function testZipContainsManifestJson(): void
    {
        if (!class_exists(\ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive not available.');
        }

        $tempPath = $this->callBuildZipFile('listing-1', ['stats_template' => '<p>Stats</p>'], $this->makeManifest('listing'));

        $zip = new \ZipArchive();
        $zip->open($tempPath);
        self::assertNotFalse($zip->locateName('manifest.json'), 'manifest.json missing from ZIP');
        $zip->close();
        @unlink($tempPath);
    }

    public function testZipContainsPackJson(): void
    {
        if (!class_exists(\ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive not available.');
        }

        $tempPath = $this->callBuildZipFile('listing-1', ['stats_template' => '<p>Stats</p>'], $this->makeManifest('listing'));

        $zip = new \ZipArchive();
        $zip->open($tempPath);
        self::assertNotFalse($zip->locateName('pack.json'), 'pack.json missing from ZIP');
        $zip->close();
        @unlink($tempPath);
    }

    public function testPackJsonIsValidImportablePack(): void
    {
        if (!class_exists(\ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive not available.');
        }

        $html     = '<ul>{each_member}<li>{fan_name}</li>{/each_member}</ul>';
        $manifest = $this->makeManifest('listing');
        $tempPath = $this->callBuildZipFile('listing-1', ['members_template' => $html], $manifest);

        $zip = new \ZipArchive();
        $zip->open($tempPath);
        $pack = json_decode((string) $zip->getFromName('pack.json'), true);
        $zip->close();
        @unlink($tempPath);

        self::assertSame('1.0', $pack['luster_template_pack'] ?? null, 'pack.json missing version header');
        self::assertArrayHasKey('templates', $pack, 'pack.json missing templates key');
        self::assertSame($html, $pack['templates']['members_template'] ?? null);
    }

    public function testPackJsonTemplatesMatchPassedTemplates(): void
    {
        if (!class_exists(\ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive not available.');
        }

        $templates = [
            'stats_template'   => '<p>Stats</p>',
            'members_template' => '<li>{fan_name}</li>',
        ];
        $tempPath = $this->callBuildZipFile('listing-3', $templates, $this->makeManifest('listing'));

        $zip = new \ZipArchive();
        $zip->open($tempPath);
        $pack = json_decode((string) $zip->getFromName('pack.json'), true);
        $zip->close();
        @unlink($tempPath);

        self::assertSame($templates, $pack['templates'] ?? null);
    }

    public function testManifestJsonDoesNotContainTemplatesKey(): void
    {
        if (!class_exists(\ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive not available.');
        }

        $tempPath = $this->callBuildZipFile('listing-1', ['stats_template' => '<p>Stats</p>'], $this->makeManifest('listing'));

        $zip = new \ZipArchive();
        $zip->open($tempPath);
        $manifest = json_decode((string) $zip->getFromName('manifest.json'), true);
        $zip->close();
        @unlink($tempPath);

        self::assertArrayNotHasKey('templates', $manifest, 'manifest.json should not contain template bodies');
    }

    public function testListingZipManifestHasScopeListing(): void
    {
        if (!class_exists(\ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive not available.');
        }

        $tempPath = $this->callBuildZipFile('listing-1', ['stats_template' => '<p>Stats</p>'], $this->makeManifest('listing'));

        $zip = new \ZipArchive();
        $zip->open($tempPath);
        $manifest = json_decode((string) $zip->getFromName('manifest.json'), true);
        $zip->close();
        @unlink($tempPath);

        self::assertSame('listing', $manifest['scope'] ?? null);
    }

    public function testListingZipPlacesTemplatesInListingFolder(): void
    {
        if (!class_exists(\ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive not available.');
        }

        $tempPath = $this->callBuildZipFile('listing-7', ['stats_template' => '<p>Stats</p>'], $this->makeManifest('listing'));

        $zip = new \ZipArchive();
        $zip->open($tempPath);
        self::assertNotFalse($zip->locateName('listing-7/stats_template.html'));
        $zip->close();
        @unlink($tempPath);
    }

    public function testListingZipTemplateFileContentMatchesInput(): void
    {
        if (!class_exists(\ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive not available.');
        }

        $html     = '<ul>{each_member}<li>{fan_name}</li>{/each_member}</ul>';
        $tempPath = $this->callBuildZipFile('listing-1', ['members_template' => $html], $this->makeManifest('listing'));

        $zip = new \ZipArchive();
        $zip->open($tempPath);
        $content = $zip->getFromName('listing-1/members_template.html');
        $zip->close();
        @unlink($tempPath);

        self::assertSame($html, $content);
    }

    public function testZipOmitsNullTemplates(): void
    {
        if (!class_exists(\ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive not available.');
        }

        // buildZipFile() receives only non-null templates — callers pre-filter
        $tempPath = $this->callBuildZipFile('listing-1', ['stats_template' => '<p>Stats</p>'], $this->makeManifest('listing'));

        $zip = new \ZipArchive();
        $zip->open($tempPath);
        self::assertNotFalse($zip->locateName('listing-1/stats_template.html'));
        self::assertFalse($zip->locateName('listing-1/members_template.html'));
        $zip->close();
        @unlink($tempPath);
    }

    public function testGlobalZipPlacesTemplatesInGlobalFolder(): void
    {
        if (!class_exists(\ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive not available.');
        }

        $tempPath = $this->callBuildZipFile('global', ['stats_template' => '<p>Global stats</p>'], $this->makeManifest('global'));

        $zip = new \ZipArchive();
        $zip->open($tempPath);
        self::assertNotFalse($zip->locateName('global/stats_template.html'));
        $zip->close();
        @unlink($tempPath);
    }

    public function testGlobalZipManifestHasScopeGlobal(): void
    {
        if (!class_exists(\ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive not available.');
        }

        $tempPath = $this->callBuildZipFile('global', ['stats_template' => '<p>Stats</p>'], $this->makeManifest('global'));

        $zip = new \ZipArchive();
        $zip->open($tempPath);
        $manifest = json_decode((string) $zip->getFromName('manifest.json'), true);
        $zip->close();
        @unlink($tempPath);

        self::assertSame('global', $manifest['scope'] ?? null);
    }

    public function testCollectiveZipPlacesTemplatesInCollectiveFolder(): void
    {
        if (!class_exists(\ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive not available.');
        }

        $tempPath = $this->callBuildZipFile('collective', ['owned_template' => '<p>Owned</p>'], $this->makeManifest('collective'));

        $zip = new \ZipArchive();
        $zip->open($tempPath);
        self::assertNotFalse($zip->locateName('collective/owned_template.html'));
        $zip->close();
        @unlink($tempPath);
    }

    public function testCollectiveZipManifestHasScopeCollective(): void
    {
        if (!class_exists(\ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive not available.');
        }

        $tempPath = $this->callBuildZipFile('collective', ['joined_template' => '<p>Joined</p>'], $this->makeManifest('collective'));

        $zip = new \ZipArchive();
        $zip->open($tempPath);
        $manifest = json_decode((string) $zip->getFromName('manifest.json'), true);
        $zip->close();
        @unlink($tempPath);

        self::assertSame('collective', $manifest['scope'] ?? null);
    }

    public function testZipManifestContainsRequiredAddonsKey(): void
    {
        if (!class_exists(\ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive not available.');
        }

        $tempPath = $this->callBuildZipFile('listing-1', ['stats_template' => '<p>Stats</p>'], $this->makeManifest('listing'));

        $zip = new \ZipArchive();
        $zip->open($tempPath);
        $manifest = json_decode((string) $zip->getFromName('manifest.json'), true);
        $zip->close();
        @unlink($tempPath);

        self::assertArrayHasKey('required_addons', $manifest);
        self::assertIsArray($manifest['required_addons']);
    }

    public function testZipManifestIncludesLusterVersion(): void
    {
        if (!class_exists(\ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive not available.');
        }

        $tempPath = $this->callBuildZipFile('listing-1', ['stats_template' => '<p>Stats</p>'], $this->makeManifest('listing'));

        $zip = new \ZipArchive();
        $zip->open($tempPath);
        $manifest = json_decode((string) $zip->getFromName('manifest.json'), true);
        $zip->close();
        @unlink($tempPath);

        self::assertSame(Version::CURRENT, $manifest['luster_version'] ?? null);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /** @return list<string> */
    private function getTemplateKeys(): array
    {
        return (new ReflectionClassConstant(TemplatePackController::class, 'TEMPLATE_KEYS'))->getValue();
    }

    /** @return list<string> */
    private function getCollectiveTemplateKeys(): array
    {
        return (new ReflectionClassConstant(TemplatePackController::class, 'COLLECTIVE_TEMPLATE_KEYS'))->getValue();
    }

    /**
     * Calls the private detectRequiredAddons() method via reflection.
     *
     * @param  array<string, string|null> $templates
     * @return list<string>
     */
    private function callDetectRequiredAddons(array $templates): array
    {
        $controller = (new ReflectionClass(TemplatePackController::class))->newInstanceWithoutConstructor();
        $method     = new ReflectionMethod(TemplatePackController::class, 'detectRequiredAddons');
        $method->setAccessible(true);
        return $method->invoke($controller, $templates);
    }

    /**
     * Calls the private buildZipFile() helper directly — tests the real production
     * ZIP construction code, not a duplicate. Caller must @unlink the returned path.
     *
     * @param  array<string, string> $templates  Non-null templates only (callers pre-filter)
     * @param  array<string, mixed>  $manifest
     */
    private function callBuildZipFile(string $folder, array $templates, array $manifest): string
    {
        $controller = (new ReflectionClass(TemplatePackController::class))->newInstanceWithoutConstructor();
        $method     = new ReflectionMethod(TemplatePackController::class, 'buildZipFile');
        $method->setAccessible(true);
        return $method->invoke($controller, $folder, $templates, $manifest);
    }

    /** @return array<string, mixed> */
    private function makeManifest(string $scope): array
    {
        $m = [
            'luster_template_pack' => '1.0',
            'luster_version'       => Version::CURRENT,
            'exported_at'          => '2026-01-01T00:00:00Z',
            'scope'                => $scope,
            'required_addons'      => [],
        ];
        if ($scope === 'listing') {
            $m['listing_title']   = 'Test Listing';
            $m['listing_subject'] = 'Testing';
        }
        return $m;
    }
}
