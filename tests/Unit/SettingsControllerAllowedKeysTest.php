<?php

declare(strict_types=1);

namespace Luster\Tests\Unit;

use Luster\Controllers\Admin\SettingsController;
use PHPUnit\Framework\TestCase;
use ReflectionClassConstant;

/**
 * Verifies the SettingsController ALLOWED_KEYS constant contains the expected
 * smtp_* entries and does NOT contain dangerous keys that should never be
 * editable via the settings form (e.g. database credentials, debug flag).
 */
final class SettingsControllerAllowedKeysTest extends TestCase
{
    /** @var list<string> */
    private array $allowedKeys;

    protected function setUp(): void
    {
        $const = new ReflectionClassConstant(SettingsController::class, 'ALLOWED_KEYS');
        $this->allowedKeys = $const->getValue();
    }

    // -----------------------------------------------------------------------
    // smtp_* keys present
    // -----------------------------------------------------------------------

    public function testSmtpEnabledIsAllowed(): void
    {
        self::assertContains('smtp_enabled', $this->allowedKeys);
    }

    public function testSmtpHostIsAllowed(): void
    {
        self::assertContains('smtp_host', $this->allowedKeys);
    }

    public function testSmtpPortIsAllowed(): void
    {
        self::assertContains('smtp_port', $this->allowedKeys);
    }

    public function testSmtpUserIsAllowed(): void
    {
        self::assertContains('smtp_user', $this->allowedKeys);
    }

    public function testSmtpPassIsAllowed(): void
    {
        self::assertContains('smtp_pass', $this->allowedKeys);
    }

    public function testSmtpSecureIsAllowed(): void
    {
        self::assertContains('smtp_secure', $this->allowedKeys);
    }

    public function testSmtpFromIsAllowed(): void
    {
        self::assertContains('smtp_from', $this->allowedKeys);
    }

    public function testSmtpFromNameIsAllowed(): void
    {
        self::assertContains('smtp_from_name', $this->allowedKeys);
    }

    // -----------------------------------------------------------------------
    // Dangerous keys NOT allowed
    // -----------------------------------------------------------------------

    public function testDbHostIsNotAllowed(): void
    {
        self::assertNotContains('db_host', $this->allowedKeys);
    }

    public function testDbPassIsNotAllowed(): void
    {
        self::assertNotContains('db_pass', $this->allowedKeys);
    }

    public function testDbNameIsNotAllowed(): void
    {
        self::assertNotContains('db_name', $this->allowedKeys);
    }

    public function testAppDebugIsNotAllowed(): void
    {
        self::assertNotContains('app_debug', $this->allowedKeys);
    }

    public function testAppUrlIsNotAllowed(): void
    {
        self::assertNotContains('app_url', $this->allowedKeys);
    }
}
