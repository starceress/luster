<?php

declare(strict_types=1);

namespace Luster\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Guards against regressing the flash-message cross-contamination bug: when
 * two Luster embeds sit on the same host page, a GET reload re-runs every
 * embed's PHP, and each independently read-and-cleared the SAME unscoped
 * session keys (lstr_flash_success_html, lstr_flash_error, lstr_flash_old,
 * lstr_flash_old_email) — so whichever embed ran first "stole" the flash
 * meant for a different form, and toggle-hidden forms (affiliate apply,
 * codes submit) never revealed their own success/error message at all.
 *
 * The fix scopes every flash session key by a per-embed-instance marker
 * (the same string already used for the `_luster_embed_form` hidden field
 * and CSS form classes). This test statically asserts no embed or public
 * controller file reintroduces a bare, unscoped flash key literal.
 */
final class EmbedFlashKeyScopingTest extends TestCase
{
    private const UNSCOPED_PATTERNS = [
        "/'lstr_flash_success_html'/",
        "/'lstr_flash_error'/",
        "/'lstr_flash_old'/",
        "/'lstr_flash_old_email'/",
    ];

    private const EMBED_FILES = [
        'adopt.php',
        'affiliate-apply.php',
        'affiliates.php',
        'codes-submit.php',
        'codes.php',
        'collective-codes-submit.php',
        'collective-contact.php',
        'collective-update.php',
        'contact.php',
        'join.php',
        'kim-reset.php',
        'kim-signup.php',
        'kim-update.php',
        'reset.php',
        'rules-gate.php',
        'update.php',
    ];

    private const CONTROLLER_FILES = [
        'Controllers/Public/AffiliateApplyController.php',
        'Controllers/Public/CodesSubmitController.php',
        'Controllers/Public/CollectiveUpdateController.php',
        'Controllers/Public/ContactController.php',
        'Controllers/Public/JoinController.php',
        'Controllers/Public/ResetController.php',
        'Controllers/Public/UpdateController.php',
        'Addons/Kim/Controllers/KimResetController.php',
        'Addons/Kim/Controllers/KimSignupController.php',
        'Addons/Kim/Controllers/KimUpdateController.php',
        'Addons/Adoption/Controllers/AdoptPublicController.php',
    ];

    public function testEmbedFilesNeverUseUnscopedFlashKeys(): void
    {
        $base = dirname(__DIR__, 2) . '/public/embed/';
        foreach (self::EMBED_FILES as $file) {
            $path = $base . $file;
            self::assertFileExists($path);
            $contents = (string) file_get_contents($path);
            foreach (self::UNSCOPED_PATTERNS as $pattern) {
                self::assertDoesNotMatchRegularExpression(
                    $pattern,
                    $contents,
                    "public/embed/{$file} contains an unscoped flash key literal ({$pattern}) — "
                        . 'flash keys must be suffixed with the embed instance marker '
                        . "(e.g. 'lstr_flash_error:' . \$marker) to avoid cross-contamination "
                        . 'when multiple embeds share a page.',
                );
            }
        }
    }

    public function testPublicControllersNeverUseUnscopedFlashKeys(): void
    {
        $base = dirname(__DIR__, 2) . '/src/';
        foreach (self::CONTROLLER_FILES as $file) {
            $path = (string) realpath($base . $file);
            self::assertNotSame('', $path, "src/{$file} does not exist");
            $contents = (string) file_get_contents($path);
            foreach (self::UNSCOPED_PATTERNS as $pattern) {
                self::assertDoesNotMatchRegularExpression(
                    $pattern,
                    $contents,
                    "src/{$file} contains an unscoped flash key literal ({$pattern}) — "
                        . 'flash keys must be suffixed with the marker read from the '
                        . "_luster_embed_form POST field (e.g. 'lstr_flash_error:' . \$marker).",
                );
            }
        }
    }
}
