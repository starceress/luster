<?php

declare(strict_types=1);

namespace Luster\Tests\Unit;

use Luster\Controllers\Admin\FeedbackController;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Verifies that FeedbackController::validateFields() only accepts http/https
 * URLs in the optional contact_url field and rejects dangerous schemes.
 */
final class FeedbackControllerUrlValidationTest extends TestCase
{
    private FeedbackController $controller;
    private ReflectionMethod $validate;

    protected function setUp(): void
    {
        $rc = new ReflectionClass(FeedbackController::class);
        $this->controller = $rc->newInstanceWithoutConstructor();

        $this->validate = new ReflectionMethod(FeedbackController::class, 'validateFields');
        $this->validate->setAccessible(true);
    }

    // -----------------------------------------------------------------------
    // Rejected schemes
    // -----------------------------------------------------------------------

    public function testFtpUrlIsRejected(): void
    {
        $errors = $this->invokeValidate('ftp://example.com/files');
        self::assertArrayHasKey('contact_url', $errors);
    }

    public function testFileUrlIsRejected(): void
    {
        $errors = $this->invokeValidate('file:///etc/passwd');
        self::assertArrayHasKey('contact_url', $errors);
    }

    public function testJavascriptUrlIsRejected(): void
    {
        $errors = $this->invokeValidate('javascript://evil');
        self::assertArrayHasKey('contact_url', $errors);
    }

    public function testDataUrlIsRejected(): void
    {
        $errors = $this->invokeValidate('data:text/html,<script>alert(1)</script>');
        self::assertArrayHasKey('contact_url', $errors);
    }

    // -----------------------------------------------------------------------
    // Accepted schemes
    // -----------------------------------------------------------------------

    public function testHttpsUrlIsAccepted(): void
    {
        $errors = $this->invokeValidate('https://example.com');
        self::assertArrayNotHasKey('contact_url', $errors);
    }

    public function testHttpUrlIsAccepted(): void
    {
        $errors = $this->invokeValidate('http://example.com');
        self::assertArrayNotHasKey('contact_url', $errors);
    }

    public function testUppercaseSchemeIsAccepted(): void
    {
        $errors = $this->invokeValidate('HTTPS://example.com');
        self::assertArrayNotHasKey('contact_url', $errors);
    }

    public function testMixedCaseSchemeIsAccepted(): void
    {
        $errors = $this->invokeValidate('Http://example.com');
        self::assertArrayNotHasKey('contact_url', $errors);
    }

    public function testEmptyUrlIsAccepted(): void
    {
        $errors = $this->invokeValidate('');
        self::assertArrayNotHasKey('contact_url', $errors);
    }

    // -----------------------------------------------------------------------
    // Helper
    // -----------------------------------------------------------------------

    private function invokeValidate(string $contactUrl): array
    {
        return (array) $this->validate->invoke(
            $this->controller,
            'bug',
            'Test subject',
            'Test message body',
            'Test User',
            'test@example.com',
            $contactUrl,
        );
    }
}
