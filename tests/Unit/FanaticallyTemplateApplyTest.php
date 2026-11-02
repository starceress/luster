<?php

declare(strict_types=1);

namespace Luster\Tests\Unit;

use Luster\Controllers\Admin\ImportController;
use Luster\Core\Application;
use Luster\Core\Database;
use Luster\Core\Request;
use Luster\Core\Response;
use Luster\Tests\Unit\Support\TracingPdo;
use PHPUnit\Framework\TestCase;

/**
 * ImportController::templateApply() must not trust a template preview saved
 * before an update. A preview lasts an hour; if Luster's translation rules
 * changed in between (Fanatically's lost-password email became manual-only),
 * the stale "importable" flag must not let it through.
 *
 * Runs in a separate process because it defines the global LUSTER_BASE (the
 * preview cache lives under LUSTER_BASE/storage/cache).
 */
final class FanaticallyTemplateApplyTest extends TestCase
{
    private const HANDLE = '0123456789abcdef0123456789abcdef';

    private string $base = '';
    private TracingPdo $pdo;

    /** Builds the app, an empty email/revision store, and the fake base dir. */
    private function makeController(): ImportController
    {
        $this->base = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'lstr_tpl_apply_test_' . uniqid('', true);
        mkdir($this->base . '/storage/cache', 0777, true);
        // admin/layout.php stamps these with filemtime() for cache-busting.
        mkdir($this->base . '/public/assets/css', 0777, true);
        mkdir($this->base . '/public/assets/js', 0777, true);
        foreach (['theme-init.js', 'filter-panel-init.js', 'admin.js'] as $script) {
            touch($this->base . '/public/assets/js/' . $script);
        }
        define('LUSTER_BASE', $this->base);

        $cfgPath = $this->base . '/app.php';
        file_put_contents($cfgPath, '<?php return ' . var_export(['app_url' => 'https://luster.test'], true) . ';');
        // Real repo root, so the admin views render; the preview cache lives under LUSTER_BASE.
        $app = new Application(dirname(__DIR__, 2), $cfgPath);

        // TracingPdo records every statement, so the tests can prove the email
        // table is never touched. (Checking row counts alone wouldn't: SQLite
        // can't run the MySQL-only email save, so no row would appear anyway.)
        $this->pdo = TracingPdo::memory();
        $this->pdo->exec("CREATE TABLE lstr_email_templates (
            id INTEGER PRIMARY KEY AUTOINCREMENT, fanlisting_id INTEGER NOT NULL, type TEXT NOT NULL,
            enabled INTEGER NOT NULL DEFAULT 1, subject TEXT NOT NULL DEFAULT '', body TEXT NOT NULL DEFAULT '')");
        $this->pdo->exec("CREATE TABLE owned (listingid INTEGER PRIMARY KEY AUTOINCREMENT, title TEXT NOT NULL DEFAULT '',
            url TEXT NOT NULL DEFAULT '', email TEXT NOT NULL DEFAULT '', subject TEXT NOT NULL DEFAULT '',
            status INTEGER NOT NULL DEFAULT 2, dbtable TEXT NOT NULL DEFAULT '', additional TEXT NOT NULL DEFAULT '',
            catid TEXT NOT NULL DEFAULT '', category_id INTEGER NULL)");
        $this->pdo->exec("CREATE TABLE lstr_template_revisions (
            id INTEGER PRIMARY KEY AUTOINCREMENT, fanlisting_id INTEGER NOT NULL, template_key TEXT NOT NULL,
            value TEXT NULL, saved_by_admin_id INTEGER NOT NULL DEFAULT 0, saved_at TEXT NULL)");

        $prop = new \ReflectionProperty(Database::class, 'pdo');
        $prop->setAccessible(true);
        $prop->setValue($app->getDatabase(), $this->pdo);

        return new ImportController($app);
    }

    protected function tearDown(): void
    {
        if ($this->base === '' || !is_dir($this->base)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->base, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($this->base);
    }

    /**
     * Writes a preview cache the way templatePreview() does, with a stored
     * translation that may no longer match today's rules.
     *
     * @param array<string, mixed> $storedTranslation
     */
    private function writePreview(string $path, string $content, array $storedTranslation): void
    {
        $payload = [
            'admin_id'     => 1,
            'created_at'   => time(),
            'entries'      => [['sourcePath' => $path, 'category' => 'email', 'content' => $content]],
            'rejected'     => [],
            'translations' => [$path => array_merge([
                'source_path'           => $path,
                'category'              => 'email',
                'unmapped_tokens'       => [],
                'contains_php'          => false,
                'unsupported_structure' => false,
                'changed_from_default'  => true,
                'key_scope'             => 'listing',
            ], $storedTranslation)],
        ];
        file_put_contents(
            $this->base . '/storage/cache/tpl_rescue_' . self::HANDLE . '.json',
            json_encode($payload, JSON_THROW_ON_ERROR),
        );

        $_SESSION = ['lstr_admin_id' => 1, 'lstr_tpl_rescue_handle' => self::HANDLE];
    }

    private function apply(ImportController $controller, string $path): Response
    {
        $this->pdo->statements = [];
        $request = new Request([], ['listing_id' => '1', 'selected' => [$path]], ['REQUEST_METHOD' => 'POST'], [], []);
        return $controller->templateApply($request, []);
    }

    /**
     * Statements the apply step ran against the email templates or revisions tables.
     *
     * @return list<string>
     */
    private function templateWrites(): array
    {
        return array_values(array_filter(
            $this->pdo->statements,
            static fn(string $sql): bool => str_contains($sql, 'lstr_email_templates') || str_contains($sql, 'lstr_template_revisions'),
        ));
    }

    /** @runInSeparateProcess */
    public function testPreviewFromBeforeTheUpdateCannotImportTheLostPasswordEmail(): void
    {
        $controller = $this->makeController();
        $path       = 'templates/email/lostpassword.txt';
        $source     = "Hi {name},\n\nYour password is: {password}\n\n{owner_name}";

        // What the old translator stored: importable, aimed at Luster's reset email.
        $this->writePreview($path, $source, [
            'translated_content'  => "Hi {fan_name},\n\nYour password is: {fan_password}\n\n{owner_name}",
            'target_template_key' => 'email:reset',
            'importable'          => true,
        ]);

        $response = $this->apply($controller, $path);

        self::assertSame([], $this->templateWrites(), 'The reset email must not be read or written');
        self::assertArrayNotHasKey('lstr_flash_success', $_SESSION);

        // Follow the redirect: the preview page has to tell the owner what to do.
        self::assertStringEndsWith('/admin/import/fanatically/templates/preview', (string) $response->getRedirectUrl());
        $page = $controller->templatePreview(new Request([], [], ['REQUEST_METHOD' => 'GET'], [], []), [])->getBody();
        self::assertStringContainsString('lostpassword.txt', $page);
        self::assertStringContainsString('Upload the ZIP again', $page);
        self::assertArrayNotHasKey('lstr_flash_error', $_SESSION, 'The message is shown once, then cleared');
    }

    /** @runInSeparateProcess */
    public function testPreviewWhoseTranslationNoLongerMatchesIsSkipped(): void
    {
        $controller = $this->makeController();
        $path       = 'templates/email/join.txt';

        // Still importable today, but the preview showed different output.
        $this->writePreview($path, 'Dear {name},', [
            'translated_content'  => 'Dear {name_from_an_older_mapping},',
            'target_template_key' => 'email:join',
            'importable'          => true,
        ]);

        $this->apply($controller, $path);

        self::assertSame([], $this->templateWrites());
        self::assertStringContainsString('join.txt', (string) ($_SESSION['lstr_flash_error'] ?? ''));
    }
}
