<?php

declare(strict_types=1);

namespace Luster\Tests\Unit;

use Luster\Controllers\Public\AffiliateApplyController;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * AffiliateApplyController::resolveImagePath() maps the relative filename
 * stored by handleImageUpload() to the absolute path Mailer needs to attach
 * it to the admin notification email. Regression coverage for the bug where
 * a submitted application image was uploaded and stored but never actually
 * reached the admin's inbox as an attachment.
 */
final class AffiliateApplyControllerImagePathTest extends TestCase
{
    public function testResolvesFilenameToAbsoluteUploadsPath(): void
    {
        $path = $this->callResolveImagePath('aapp_deadbeef.jpg');

        self::assertNotNull($path);
        self::assertStringEndsWith(
            DIRECTORY_SEPARATOR === '\\' ? 'public/uploads/affiliates/aapp_deadbeef.jpg' : '/public/uploads/affiliates/aapp_deadbeef.jpg',
            str_replace('\\', '/', $path),
        );
    }

    public function testReturnsNullWhenNoImageWasUploaded(): void
    {
        self::assertNull($this->callResolveImagePath(null));
    }

    public function testReturnsNullForEmptyString(): void
    {
        self::assertNull($this->callResolveImagePath(''));
    }

    private function callResolveImagePath(?string $imagePath): ?string
    {
        $controller = (new \ReflectionClass(AffiliateApplyController::class))->newInstanceWithoutConstructor();
        $m = new ReflectionMethod(AffiliateApplyController::class, 'resolveImagePath');
        return $m->invoke($controller, $imagePath);
    }
}
