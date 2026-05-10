<?php

declare(strict_types=1);

namespace Luster\Tests\Unit;

use Luster\Core\FeedbackReport;
use PHPUnit\Framework\TestCase;

final class FeedbackReportTest extends TestCase
{
    // -----------------------------------------------------------------------
    // formatSysInfo()
    // -----------------------------------------------------------------------

    public function testSysInfoContainsAllFields(): void
    {
        $out = FeedbackReport::formatSysInfo($this->sysInfoData());

        $this->assertStringContainsString('1.0.0',            $out);
        $this->assertStringContainsString('8.2.0',            $out);
        $this->assertStringContainsString('8.0.31',           $out);
        $this->assertStringContainsString('No',               $out);
        $this->assertStringContainsString('Configured',       $out);
        $this->assertStringContainsString('36',               $out);
        $this->assertStringContainsString('luster/public',    $out);
        $this->assertStringContainsString('testadmin',        $out);
        $this->assertStringContainsString('Kim, Updates',     $out);
    }

    public function testSysInfoNullMigrationRendersUnknown(): void
    {
        $data = $this->sysInfoData(['migration_level' => null]);
        $out  = FeedbackReport::formatSysInfo($data);

        $this->assertStringContainsString('unknown', $out);
    }

    public function testSysInfoEmptyAddonsRendersNone(): void
    {
        $data = $this->sysInfoData(['addons' => []]);
        $out  = FeedbackReport::formatSysInfo($data);

        $this->assertStringContainsString('None', $out);
    }

    public function testSysInfoDebugEnabledRendersYes(): void
    {
        $data = $this->sysInfoData(['debug_enabled' => true]);
        $out  = FeedbackReport::formatSysInfo($data);

        $this->assertStringContainsString('Yes', $out);
    }

    public function testSysInfoSmtpNotConfiguredRendersNotConfigured(): void
    {
        $data = $this->sysInfoData(['smtp_configured' => false]);
        $out  = FeedbackReport::formatSysInfo($data);

        $this->assertStringContainsString('Not configured', $out);
    }

    // -----------------------------------------------------------------------
    // formatErrorLog()
    // -----------------------------------------------------------------------

    public function testErrorLogWithEntriesFormatsEachRow(): void
    {
        $rows = [
            ['created_at' => '2026-04-24 23:56:00', 'source' => 'View.php:154', 'message' => 'Unknown icon: refresh'],
            ['created_at' => '2026-04-22 13:07:00', 'source' => 'View.php:154', 'message' => 'Unknown icon: trash'],
        ];
        $out = FeedbackReport::formatErrorLog($rows);

        $this->assertStringContainsString('2026-04-24 23:56:00', $out);
        $this->assertStringContainsString('Unknown icon: refresh', $out);
        $this->assertStringContainsString('2026-04-22 13:07:00', $out);
        $this->assertStringContainsString('Unknown icon: trash', $out);
    }

    public function testErrorLogEmptyRendersNoRecentErrors(): void
    {
        $out = FeedbackReport::formatErrorLog([]);

        $this->assertStringContainsString('No recent errors', $out);
    }

    // -----------------------------------------------------------------------
    // compose()
    // -----------------------------------------------------------------------

    public function testComposeIncludesBothBlocksWhenBothChecked(): void
    {
        $sysInfo  = "SYS INFO BLOCK\n";
        $errorLog = "ERROR LOG BLOCK\n";

        $out = FeedbackReport::compose($this->formData([
            'include_sysinfo'  => true,
            'include_errorlog' => true,
        ]), $sysInfo, $errorLog);

        $this->assertStringContainsString('SYS INFO BLOCK',  $out);
        $this->assertStringContainsString('ERROR LOG BLOCK', $out);
    }

    public function testComposeOmitsSysInfoWhenUnchecked(): void
    {
        $sysInfo  = "SYS INFO BLOCK\n";
        $errorLog = "ERROR LOG BLOCK\n";

        $out = FeedbackReport::compose($this->formData([
            'include_sysinfo'  => false,
            'include_errorlog' => true,
        ]), $sysInfo, $errorLog);

        $this->assertStringNotContainsString('SYS INFO BLOCK',  $out);
        $this->assertStringContainsString('ERROR LOG BLOCK', $out);
    }

    public function testComposeOmitsErrorLogWhenUnchecked(): void
    {
        $sysInfo  = "SYS INFO BLOCK\n";
        $errorLog = "ERROR LOG BLOCK\n";

        $out = FeedbackReport::compose($this->formData([
            'include_sysinfo'  => true,
            'include_errorlog' => false,
        ]), $sysInfo, $errorLog);

        $this->assertStringContainsString('SYS INFO BLOCK',      $out);
        $this->assertStringNotContainsString('ERROR LOG BLOCK', $out);
    }

    public function testComposeOmitsBothWhenBothUnchecked(): void
    {
        $sysInfo  = "SYS INFO BLOCK\n";
        $errorLog = "ERROR LOG BLOCK\n";

        $out = FeedbackReport::compose($this->formData([
            'include_sysinfo'  => false,
            'include_errorlog' => false,
        ]), $sysInfo, $errorLog);

        $this->assertStringNotContainsString('SYS INFO BLOCK',  $out);
        $this->assertStringNotContainsString('ERROR LOG BLOCK', $out);
    }

    public function testComposeContainsSubjectAndMessage(): void
    {
        $out = FeedbackReport::compose($this->formData(), '', '');

        $this->assertStringContainsString('Test subject', $out);
        $this->assertStringContainsString('Test message body', $out);
    }

    public function testComposeUsesTypeLabelNotKey(): void
    {
        $out = FeedbackReport::compose($this->formData(['type' => 'bug']), '', '');
        $this->assertStringContainsString('Bug Report', $out);

        $out = FeedbackReport::compose($this->formData(['type' => 'feature']), '', '');
        $this->assertStringContainsString('Feature Request', $out);
    }

    public function testComposeContactEmailShownWhenProvided(): void
    {
        $out = FeedbackReport::compose($this->formData(['contact_email' => 'fan@example.com']), '', '');
        $this->assertStringContainsString('fan@example.com', $out);
    }

    public function testComposeContactEmailShowsNotProvidedWhenEmpty(): void
    {
        $out = FeedbackReport::compose($this->formData(['contact_email' => '']), '', '');
        $this->assertStringContainsString('Not provided', $out);
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    private function sysInfoData(array $overrides = []): array
    {
        return array_merge([
            'luster_version'   => '1.0.0',
            'base_url'         => 'luster/public',
            'report_timestamp' => '2026-04-26 12:00:00',
            'php_version'      => '8.2.0',
            'db_version'       => '8.0.31',
            'debug_enabled'    => false,
            'smtp_configured'  => true,
            'admin_username'   => 'testadmin',
            'addons'           => ['Kim', 'Updates'],
            'migration_level'  => 36,
        ], $overrides);
    }

    private function formData(array $overrides = []): array
    {
        return array_merge([
            'type'             => 'bug',
            'subject'          => 'Test subject',
            'message'          => 'Test message body',
            'contact_email'    => '',
            'include_sysinfo'  => true,
            'include_errorlog' => false,
        ], $overrides);
    }
}
