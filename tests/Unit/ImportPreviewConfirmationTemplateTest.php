<?php

declare(strict_types=1);

namespace Luster\Tests\Unit;

use Luster\Import\ImportOptions;
use Luster\Import\ImportPreview;
use PHPUnit\Framework\TestCase;

/**
 * The Preview step's box about where members are, through the real template. Values on
 * it come from an old database, so they have to come out escaped.
 */
final class ImportPreviewConfirmationTemplateTest extends TestCase
{
    /** @param array{fingerprint: string, listings: list<array{id: int, title: string, table: string, server: string, database: string}>}|null $confirmation */
    private function render(?array $confirmation, array $warnings = [], string $notice = ''): string
    {
        $base_url    = 'https://luster.test';
        $source      = 'enthusiast';
        $source_name = 'Enthusiast';
        $error       = '';
        $conn_type   = 'Live connection';
        $conn_host   = 'localhost';
        $conn_db     = 'enth';
        $csrf_token  = 'token';
        $options     = ImportOptions::fromPost(['source' => 'enthusiast', 'conflict_strategy' => 'skip', 'import_members' => '1']);
        $preview     = new ImportPreview(
            [['type' => 'Members', 'found' => 1, 'import' => 0, 'skip' => 0, 'warnings' => $warnings]],
            'enthusiast_unprefixed',
            false,
            $confirmation,
        );

        ob_start();
        try {
            include dirname(__DIR__, 2) . '/templates/admin/import/import-wizard/preview.php';
        } finally {
            $html = (string) ob_get_clean();
        }
        return $html;
    }

    private static function confirmation(): array
    {
        return ['fingerprint' => 'abc123', 'listings' => [[
            'id' => 7, 'title' => 'Moon', 'table' => 'fl_moon', 'server' => 'db.far.test', 'database' => 'far',
        ]]];
    }

    public function testItSaysWhyTheOwnerWasSentBack(): void
    {
        $html = $this->render(self::confirmation(), [], 'Something changed <since>');

        self::assertStringContainsString('Something changed &lt;since&gt;', $html);
        self::assertStringContainsString('id="js-import-run-form"', $html, 'and the preview is still there');
    }

    public function testThereIsNoBoxWhenNothingNeedsConfirming(): void
    {
        $html = $this->render(null);

        self::assertStringNotContainsString('confirm_member_location', $html);
        self::assertStringNotContainsString('member_location_fingerprint', $html);
    }

    public function testTheBoxIsOffAndCarriesItsFingerprintInsideTheRunForm(): void
    {
        $html = $this->render(self::confirmation());

        self::assertMatchesRegularExpression('/<input type="checkbox" name="confirm_member_location" value="1" id="ck-member-location">/', $html);
        self::assertStringNotContainsString('checked', substr($html, (int) strpos($html, 'confirm_member_location'), 80));
        self::assertStringContainsString('name="member_location_fingerprint" value="abc123"', $html);

        $form = substr($html, (int) strpos($html, 'id="js-import-run-form"'));
        $form = substr($form, 0, (int) strpos($form, '</form>'));
        self::assertStringContainsString('confirm_member_location', $form);
        self::assertStringContainsString('member_location_fingerprint', $form);
    }

    public function testItNamesEachListingItCovers(): void
    {
        $html = $this->render(self::confirmation());

        foreach (['#7', 'Moon', 'fl_moon', 'db.far.test', 'far'] as $piece) {
            self::assertStringContainsString($piece, $html);
        }
    }

    public function testValuesFromTheOldDatabaseAreEscaped(): void
    {
        $evil = '<script>alert(1)</script>';
        $html = $this->render([
            'fingerprint' => '"><script>alert(2)</script>',
            'listings'    => [['id' => 7, 'title' => $evil, 'table' => $evil, 'server' => $evil, 'database' => $evil]],
        ], ['"' . $evil . '" says its members are elsewhere']);

        self::assertStringNotContainsString('<script>', $html);
        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
    }
}
