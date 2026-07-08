<?php

declare(strict_types=1);

namespace Luster\Tests\Unit;

use Luster\Core\Config;
use Luster\Core\Mailer;
use PHPMailer\PHPMailer\PHPMailer;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Unit tests for Mailer invariants that don't require a live SMTP server.
 *
 * Tests use ReflectionMethod to invoke private methods and verify:
 *  1. smtp_secure string → PHPMailer encryption constant mapping
 *  2. CRLF stripping still applies in the new PHPMailer-backed dispatch
 *  3. smtp_enabled flag selects transport mode
 *  4. From-address fallback chain (smtp_from → owner_email)
 *  5. SMTPAutoTLS disabled when secure = 'none'
 *  6. SMTPAuth conditional on username/password presence
 */
final class MailerTest extends TestCase
{
    // -----------------------------------------------------------------------
    // smtp_secure normalization
    // -----------------------------------------------------------------------

    public function testTlsMapsToStarttls(): void
    {
        $mailer = $this->makeMailer(['smtp_secure' => 'tls']);
        self::assertSame(
            PHPMailer::ENCRYPTION_STARTTLS,
            $this->callResolveSmtpSecure($mailer, 'tls'),
        );
    }

    public function testSslMapsToSmtps(): void
    {
        $mailer = $this->makeMailer(['smtp_secure' => 'ssl']);
        self::assertSame(
            PHPMailer::ENCRYPTION_SMTPS,
            $this->callResolveSmtpSecure($mailer, 'ssl'),
        );
    }

    public function testNoneMapsToEmptyString(): void
    {
        $mailer = $this->makeMailer(['smtp_secure' => 'none']);
        self::assertSame(
            '',
            $this->callResolveSmtpSecure($mailer, 'none'),
        );
    }

    public function testUnknownValueFallsBackToStarttls(): void
    {
        $mailer = $this->makeMailer(['smtp_secure' => 'invalid']);
        self::assertSame(
            PHPMailer::ENCRYPTION_STARTTLS,
            $this->callResolveSmtpSecure($mailer, 'invalid'),
        );
    }

    // -----------------------------------------------------------------------
    // CRLF stripping preserved
    // -----------------------------------------------------------------------

    public function testStripCrlfRemovesCarriageReturn(): void
    {
        $mailer = $this->makeMailer([]);
        self::assertSame('inject', $this->callStripCrlf($mailer, "inject\r"));
    }

    public function testStripCrlfRemovesNewline(): void
    {
        $mailer = $this->makeMailer([]);
        self::assertSame('inject', $this->callStripCrlf($mailer, "inject\n"));
    }

    public function testStripCrlfRemovesNullByte(): void
    {
        $mailer = $this->makeMailer([]);
        self::assertSame('inject', $this->callStripCrlf($mailer, "inject\0"));
    }

    public function testStripCrlfRemovesAll(): void
    {
        $mailer = $this->makeMailer([]);
        self::assertSame(
            'headerinjection',
            $this->callStripCrlf($mailer, "header\r\ninjection\0"),
        );
    }

    // -----------------------------------------------------------------------
    // smtp_enabled transport selection
    // -----------------------------------------------------------------------

    public function testSmtpDisabledWhenBlank(): void
    {
        $mailer = $this->makeMailer(['smtp_enabled' => '']);
        self::assertFalse($this->callIsSmtpEnabled($mailer));
    }

    public function testSmtpDisabledWhenZero(): void
    {
        $mailer = $this->makeMailer(['smtp_enabled' => '0']);
        self::assertFalse($this->callIsSmtpEnabled($mailer));
    }

    public function testSmtpEnabledWhenOne(): void
    {
        $mailer = $this->makeMailer(['smtp_enabled' => '1']);
        self::assertTrue($this->callIsSmtpEnabled($mailer));
    }

    // -----------------------------------------------------------------------
    // From-address fallback chain
    // -----------------------------------------------------------------------

    public function testSmtpFromUsedWhenSet(): void
    {
        $mailer = $this->makeMailer([
            'smtp_from'    => 'smtp@example.com',
            'owner_email'  => 'owner@example.com',
        ]);
        self::assertSame('smtp@example.com', $this->getFromEmail($mailer));
    }

    public function testOwnerEmailFallsBackWhenSmtpFromBlank(): void
    {
        $mailer = $this->makeMailer([
            'smtp_from'   => '',
            'owner_email' => 'owner@example.com',
        ]);
        self::assertSame('owner@example.com', $this->getFromEmail($mailer));
    }

    public function testSmtpFromNameUsedWhenSet(): void
    {
        $mailer = $this->makeMailer([
            'smtp_from_name' => 'SMTP Name',
            'owner_name'     => 'Owner Name',
        ]);
        self::assertSame('SMTP Name', $this->getFromName($mailer));
    }

    public function testOwnerNameFallsBackWhenSmtpFromNameBlank(): void
    {
        $mailer = $this->makeMailer([
            'smtp_from_name' => '',
            'owner_name'     => 'Owner Name',
        ]);
        self::assertSame('Owner Name', $this->getFromName($mailer));
    }

    // -----------------------------------------------------------------------
    // SMTPAutoTLS disabled for 'none' encryption
    // -----------------------------------------------------------------------

    public function testAutoTlsDisabledWhenSecureIsNone(): void
    {
        $mailer = $this->makeMailer([
            'smtp_enabled' => '1',
            'smtp_secure'  => 'none',
        ]);
        $mail = new PHPMailer();
        // Simulate configureSMTP by invoking it via reflection
        $m = new ReflectionMethod(Mailer::class, 'configureSMTP');
        $m->invoke($mailer, $mail);
        self::assertFalse($mail->SMTPAutoTLS);
    }

    public function testAutoTlsNotDisabledForTls(): void
    {
        $mailer = $this->makeMailer([
            'smtp_enabled' => '1',
            'smtp_secure'  => 'tls',
        ]);
        $mail = new PHPMailer();
        $m = new ReflectionMethod(Mailer::class, 'configureSMTP');
        $m->invoke($mailer, $mail);
        // SMTPAutoTLS should remain at its PHPMailer default (true)
        self::assertTrue($mail->SMTPAutoTLS);
    }

    // -----------------------------------------------------------------------
    // SMTPAuth conditional on credentials
    // -----------------------------------------------------------------------

    public function testSmtpAuthTrueWhenUsernameProvided(): void
    {
        $mailer = $this->makeMailer([
            'smtp_enabled' => '1',
            'smtp_user'    => 'user@example.com',
            'smtp_pass'    => '',
        ]);
        $mail = new PHPMailer();
        $m = new ReflectionMethod(Mailer::class, 'configureSMTP');
        $m->invoke($mailer, $mail);
        self::assertTrue($mail->SMTPAuth);
    }

    public function testSmtpAuthTrueWhenPasswordProvided(): void
    {
        $mailer = $this->makeMailer([
            'smtp_enabled' => '1',
            'smtp_user'    => '',
            'smtp_pass'    => 'secret',
        ]);
        $mail = new PHPMailer();
        $m = new ReflectionMethod(Mailer::class, 'configureSMTP');
        $m->invoke($mailer, $mail);
        self::assertTrue($mail->SMTPAuth);
    }

    public function testSmtpAuthFalseWhenNoCredentials(): void
    {
        $mailer = $this->makeMailer([
            'smtp_enabled' => '1',
            'smtp_user'    => '',
            'smtp_pass'    => '',
        ]);
        $mail = new PHPMailer();
        $m = new ReflectionMethod(Mailer::class, 'configureSMTP');
        $m->invoke($mailer, $mail);
        self::assertFalse($mail->SMTPAuth);
    }

    // -----------------------------------------------------------------------
    // Attachment path filtering
    // -----------------------------------------------------------------------

    public function testFilterValidAttachmentsKeepsExistingReadableFiles(): void
    {
        $mailer = $this->makeMailer([]);
        $path   = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'lstr_mailer_attach_' . uniqid('', true) . '.txt';
        file_put_contents($path, 'test');

        self::assertSame([$path], $this->callFilterValidAttachments($mailer, [$path]));

        unlink($path);
    }

    public function testFilterValidAttachmentsDropsMissingFile(): void
    {
        $mailer = $this->makeMailer([]);
        $missing = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'lstr_mailer_does_not_exist_' . uniqid('', true) . '.txt';

        self::assertSame([], $this->callFilterValidAttachments($mailer, [$missing]));
    }

    public function testFilterValidAttachmentsDropsEmptyAndNonStringEntries(): void
    {
        $mailer = $this->makeMailer([]);
        self::assertSame([], $this->callFilterValidAttachments($mailer, ['', null, 123]));
    }

    public function testFilterValidAttachmentsHandlesEmptyInput(): void
    {
        $mailer = $this->makeMailer([]);
        self::assertSame([], $this->callFilterValidAttachments($mailer, []));
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    private function makeMailer(array $configValues): Mailer
    {
        $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'lstr_mailer_test_' . uniqid('', true) . '.php';
        file_put_contents($path, '<?php return ' . var_export($configValues, true) . ';');
        $config = new Config($path);
        unlink($path);
        return new Mailer($config);
    }

    private function callResolveSmtpSecure(Mailer $mailer, string $value): string
    {
        $m = new ReflectionMethod(Mailer::class, 'resolveSmtpSecure');
        return $m->invoke($mailer, $value);
    }

    private function callStripCrlf(Mailer $mailer, string $value): string
    {
        $m = new ReflectionMethod(Mailer::class, 'stripCrlf');
        return $m->invoke($mailer, $value);
    }

    private function callIsSmtpEnabled(Mailer $mailer): bool
    {
        $m = new ReflectionMethod(Mailer::class, 'isSmtpEnabled');
        return $m->invoke($mailer);
    }

    private function getFromEmail(Mailer $mailer): string
    {
        $p = new \ReflectionProperty(Mailer::class, 'fromEmail');
        return $p->getValue($mailer);
    }

    private function getFromName(Mailer $mailer): string
    {
        $p = new \ReflectionProperty(Mailer::class, 'fromName');
        return $p->getValue($mailer);
    }

    private function callFilterValidAttachments(Mailer $mailer, array $paths): array
    {
        $m = new ReflectionMethod(Mailer::class, 'filterValidAttachments');
        return $m->invoke($mailer, $paths);
    }
}
