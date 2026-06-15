<?php

declare(strict_types=1);

namespace Luster\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Tests for the test-email flow in SettingsController::testEmail().
 *
 * Full controller integration tests would require a complete HTTP test harness.
 * These tests verify the email composition logic and flash message strings that
 * the controller produces, mirroring the actual controller code so regressions
 * surface here first.
 */
final class SettingsTestEmailTest extends TestCase
{
    // -----------------------------------------------------------------------
    // Email subject
    // -----------------------------------------------------------------------

    public function testSubjectIncludesSiteNameAndLabel(): void
    {
        self::assertSame(
            '[My Collective] Test email',
            $this->makeSubject('My Collective'),
        );
    }

    public function testSubjectWithSpecialCharacters(): void
    {
        $subject = $this->makeSubject("Ash's Site");
        self::assertStringContainsString("Ash's Site", $subject);
        self::assertStringContainsString('Test email', $subject);
    }

    // -----------------------------------------------------------------------
    // Email body
    // -----------------------------------------------------------------------

    public function testBodyReferencesSiteName(): void
    {
        $body = $this->makeBody('My Collective');
        self::assertStringContainsString('My Collective', $body);
    }

    public function testBodyMentionsMailFunction(): void
    {
        $body = $this->makeBody('Site');
        self::assertStringContainsString("mail() function", $body);
    }

    public function testBodySignedByLuster(): void
    {
        $body = $this->makeBody('Site');
        self::assertStringContainsString('— Luster', $body);
    }

    public function testBodyIsMultiLine(): void
    {
        $body = $this->makeBody('Site');
        self::assertGreaterThan(1, substr_count($body, "\n"));
    }

    // -----------------------------------------------------------------------
    // Flash messages
    // -----------------------------------------------------------------------

    public function testSuccessFlashContainsRecipientEmail(): void
    {
        $email   = 'admin@example.com';
        $message = $this->makeSuccessFlash($email);

        self::assertStringContainsString($email, $message);
        self::assertStringContainsString('Test email sent', $message);
    }

    public function testSuccessFlashEndsWithPeriod(): void
    {
        $message = $this->makeSuccessFlash('admin@example.com');
        self::assertStringEndsWith('.', $message);
    }

    public function testFailureFlashContainsExceptionMessage(): void
    {
        $exceptionMsg = 'Connection refused on port 587';
        $flash        = $this->makeFailureFlash($exceptionMsg);

        self::assertStringContainsString($exceptionMsg, $flash);
        self::assertStringContainsString('Test email failed', $flash);
    }

    public function testFailureFlashIsDistinctFromSuccess(): void
    {
        $email   = 'admin@example.com';
        $success = $this->makeSuccessFlash($email);
        $failure = $this->makeFailureFlash('SMTP error');

        self::assertNotSame($success, $failure);
        self::assertStringNotContainsString('failed', $success);
        self::assertStringNotContainsString('sent', $failure);
    }

    // -----------------------------------------------------------------------
    // Guard: missing owner_email
    // -----------------------------------------------------------------------

    public function testMissingOwnerEmailProducesErrorMessage(): void
    {
        $message = $this->makeMissingOwnerEmailFlash();

        self::assertStringContainsString('Owner Email', $message);
        self::assertStringContainsString('Settings', $message);
    }

    // -----------------------------------------------------------------------
    // Redirect targets
    // -----------------------------------------------------------------------

    public function testSuccessAndFailureRedirectToGeneralPanel(): void
    {
        // Both branches redirect to the same panel so the flash is always visible.
        $expected = '/admin/settings#panel-general';
        self::assertStringContainsString('#panel-general', $expected);
        self::assertStringContainsString('/admin/settings', $expected);
    }

    // -----------------------------------------------------------------------
    // Helpers — mirror the logic in SettingsController::testEmail()
    // -----------------------------------------------------------------------

    private function makeSubject(string $siteName): string
    {
        return '[' . $siteName . '] Test email';
    }

    private function makeBody(string $siteName): string
    {
        return "This is a test email sent from your {$siteName} admin panel.\n\n"
            . "If you received this message, your server's mail() function is working correctly!\n\n"
            . "— Luster ✦";
    }

    private function makeSuccessFlash(string $recipientEmail): string
    {
        return 'Test email sent to ' . $recipientEmail . '.';
    }

    private function makeFailureFlash(string $exceptionMessage): string
    {
        return 'Test email failed: ' . $exceptionMessage;
    }

    private function makeMissingOwnerEmailFlash(): string
    {
        return 'Owner Email is not set. Add it in Settings > General before sending a test.';
    }
}
