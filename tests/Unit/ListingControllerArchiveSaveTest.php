<?php

declare(strict_types=1);

namespace Luster\Tests\Unit;

use Luster\Controllers\Admin\ListingController;
use Luster\Core\Application;
use Luster\Core\Database;
use Luster\Core\Request;
use Luster\Core\Response;
use Luster\Tests\Unit\Support\TracingPdo;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Runs the real ListingController Add and Edit saves for a Closed listing with
 * "Date unknown" ticked and a brand-new network and category picked, and checks
 * that a refused save writes nothing at all: no listing change, no lifecycle row,
 * and no new category or network. Categories used to be created while the form
 * was still being read, so they were left behind by any refused save.
 *
 * Before Migration 108 (archived_at still NOT NULL) ticking "Date unknown" is
 * refused. The successful saves need MySQL (the settings writes use ON
 * DUPLICATE KEY UPDATE), so they're checked against MariaDB outside the suite.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class ListingControllerArchiveSaveTest extends TestCase
{
    private PDO $pdo;
    private Application $app;

    // -----------------------------------------------------------------------
    // Add
    // -----------------------------------------------------------------------

    public function testAddIsRefusedBeforeMigration108WithoutWritingAnything(): void
    {
        $this->boot(migrated: false);
        $before = $this->snapshot();

        $response = $this->call('add', $this->closedUnknownWithNewCategory());

        self::assertFalse($response->isRedirect(), 'The form comes back with an error');
        self::assertStringContainsString('Luster needs Migration 108', $response->getBody());
        self::assertSame($before, $this->snapshot(), 'No listing, lifecycle row, category or network');
    }

    public function testAddWithAListingTableThatCantBeReadWritesNothingAtAll(): void
    {
        $this->boot(migrated: true, pdo: TracingPdo::memory());
        $this->logToAFile();
        $before = $this->snapshot();
        $this->pdo->failOn = '/PRAGMA table_info\(`owned`\)/';

        $response = $this->call('add', $this->closedUnknownWithNewCategory());
        $this->pdo->failOn = null;

        self::assertFalse($response->isRedirect(), 'The form comes back with an error');
        self::assertStringContainsString("Couldn&#039;t check the listing table", $response->getBody());
        self::assertSame($before, $this->snapshot(), 'No listing, lifecycle row, category or network');
    }

    /** @return array<string,string> */
    private function newListing(): array
    {
        return ['title' => 'Moon FL', 'subject' => 'Moon', 'url' => 'https://fans.test/moon', 'email' => 'owner@example.com', 'status' => 'upcoming'];
    }

    public function testAddThatTheDatabaseRefusesShowsTheFormRatherThanAnErrorPage(): void
    {
        $this->boot(migrated: true, pdo: TracingPdo::memory());
        $this->logToAFile();
        $existing = $this->existingListing();
        $this->pdo->failOn = '/INSERT INTO owned/';

        $response = $this->call('add', $this->newListing());
        $this->pdo->failOn = null;

        self::assertFalse($response->isRedirect());
        self::assertStringContainsString('so it wasn&#039;t added', $response->getBody());
        self::assertSame([(string) $existing], array_map('strval', $this->pdo->query('SELECT listingid FROM owned')->fetchAll(PDO::FETCH_COLUMN)));
    }

    public function testAddThatFailsAfterTheRowIsStoredNeverSaysItWasntAdded(): void
    {
        $this->boot(migrated: true, pdo: TracingPdo::memory());
        $this->logToAFile();
        // RAISE(FAIL) keeps what the statement already did, like MyISAM failing partway.
        $this->pdo->exec("CREATE TRIGGER owned_boom AFTER INSERT ON owned BEGIN SELECT RAISE(FAIL, 'boom'); END");

        $response = $this->call('add', $this->newListing());

        self::assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM owned')->fetchColumn(), 'The row really is still there');
        self::assertTrue($response->isRedirect(), 'Off to the list, where they can see it');
        self::assertStringEndsWith('/admin/listings', (string) $response->getRedirectUrl());
        self::assertStringContainsString("can't be sure whether it was added", (string) $this->app->getSession()->get('lstr_flash_error'));
    }

    public function testAddThatCantReadTheTableBackDoesntAssumeAnything(): void
    {
        $this->boot(migrated: true, pdo: TracingPdo::memory());
        $this->logToAFile();
        $this->pdo->failOn = '/INSERT INTO owned|SELECT COUNT\(\*\) FROM owned WHERE listingid >/';

        $response = $this->call('add', $this->newListing());
        $this->pdo->failOn = null;

        self::assertTrue($response->isRedirect());
        self::assertStringContainsString("can't be sure whether it was added", (string) $this->app->getSession()->get('lstr_flash_error'));
    }

    public function testAddThatCantReadTheTableFirstNeverReachesTheInsert(): void
    {
        $this->boot(migrated: true, pdo: TracingPdo::memory());
        $this->logToAFile();
        $this->existingListing();
        $this->pdo->statements = [];
        $this->pdo->failOn = '/COALESCE\(MAX\(listingid\)/';

        $response = $this->call('add', $this->newListing());
        $this->pdo->failOn = null;

        self::assertFalse($response->isRedirect());
        self::assertStringContainsString('so it wasn&#039;t added', $response->getBody());
        self::assertSame([], $this->pdo->statementsMatching('/INSERT INTO owned/'));
    }

    public function testAddWithAnyOtherErrorLeavesNoNewCategoryEither(): void
    {
        $this->boot(migrated: true);
        $before = $this->snapshot();

        $response = $this->call('add', ['email' => 'not an email'] + $this->closedUnknownWithNewCategory());

        self::assertFalse($response->isRedirect());
        self::assertSame($before, $this->snapshot());
    }

    // -----------------------------------------------------------------------
    // Edit
    // -----------------------------------------------------------------------

    public function testEditIsRefusedBeforeMigration108WithoutWritingAnything(): void
    {
        $this->boot(migrated: false);
        $id     = $this->existingListing();
        $before = $this->snapshot();

        $response = $this->call('edit', $this->closedUnknownWithNewCategory(), ['id' => (string) $id]);

        self::assertFalse($response->isRedirect(), 'The form comes back with an error');
        self::assertStringContainsString('Luster needs Migration 108', $response->getBody());
        self::assertSame($before, $this->snapshot(), 'The listing, its lifecycle and every category are untouched');
    }

    public function testEditWithAnyOtherErrorLeavesNoNewCategoryEither(): void
    {
        $this->boot(migrated: true);
        $id     = $this->existingListing();
        $before = $this->snapshot();

        $response = $this->call('edit', ['email' => 'not an email'] + $this->closedUnknownWithNewCategory(), ['id' => (string) $id]);

        self::assertFalse($response->isRedirect());
        self::assertSame($before, $this->snapshot());
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    /** @return array<string,mixed> */
    private function closedUnknownWithNewCategory(): array
    {
        return [
            'subject'             => 'Moon',
            'title'               => 'Moon FL',
            'url'                 => 'https://fans.test/moon',
            'email'               => 'owner@example.com',
            'status'              => 'closed',
            'archived_at'         => '',
            'archived_at_unknown' => '1',
            'archive_note'        => '',
            'cat_network'         => ['other'],
            'cat_network_other'   => ['My Network'],
            'cat_name'            => ['Brand New'],
        ];
    }

    /**
     * @param array<string,mixed>  $post
     * @param array<string,string> $params
     */
    private function call(string $action, array $post, array $params = []): Response
    {
        $request = new Request([], $post + ['_csrf' => 'token'], [
            'REQUEST_METHOD' => 'POST', 'REMOTE_ADDR' => '127.0.0.1', 'HTTP_HOST' => 'luster.test',
        ], [], []);

        return (new ListingController($this->app))->{$action}($request, $params);
    }

    /** An active listing already in Luster, with one category. */
    private function existingListing(): int
    {
        $this->pdo->exec("INSERT INTO lstr_categories (id, name, slug, source) VALUES (1, 'Comics', 'comics', 'tfl')");
        $this->pdo->exec("INSERT INTO owned (listingid, title, subject, url, email, status, dbtable, catid, category_id)
            VALUES (1, 'Moon FL', 'Moon', 'https://fans.test/moon', 'owner@example.com', 2, 'moon', '|1|', 1)");
        $this->pdo->exec('INSERT INTO lstr_listing_categories (fanlisting_id, category_id) VALUES (1, 1)');
        $this->pdo->exec('INSERT INTO lstr_fanlisting_settings (fanlisting_id) VALUES (1)');
        return 1;
    }

    /** @return array<string, list<array<string,mixed>>> */
    private function snapshot(): array
    {
        $snap = [];
        foreach (['owned', 'lstr_listing_lifecycle', 'lstr_categories', 'lstr_listing_categories', 'lstr_fanlisting_settings', 'lstr_previous_owners'] as $table) {
            $snap[$table] = $this->pdo->query("SELECT * FROM `{$table}`")->fetchAll(PDO::FETCH_ASSOC);
        }
        return $snap;
    }

    /** These tests run in their own process, and anything it logs to the screen fails them. */
    private function logToAFile(): void
    {
        $log = tempnam(sys_get_temp_dir(), 'lstr_listing_log_');
        ini_set('error_log', (string) $log);
        register_shutdown_function(static fn() => is_file((string) $log) && unlink((string) $log));
    }

    private function boot(bool $migrated, ?PDO $pdo = null): void
    {
        $root = dirname(__DIR__, 2);
        if (!defined('LUSTER_BASE')) {
            define('LUSTER_BASE', $root);
        }

        $cfgPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'lstr_listing_ctl_' . uniqid('', true) . '.php';
        file_put_contents($cfgPath, '<?php return ' . var_export([
            'enth_table_prefix' => '',
            'app_url'           => 'https://luster.test',
            'site_url'          => 'https://fans.test',
            'site_name'         => 'Test Collective',
        ], true) . ';');
        $this->app = new Application($root, $cfgPath);
        unlink($cfgPath);

        $pdo ??= new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->sqliteCreateFunction('NOW', static fn(): string => date('Y-m-d H:i:s'));
        $pdo->sqliteCreateFunction('CURDATE', static fn(): string => date('Y-m-d'));
        $prop = new \ReflectionProperty(Database::class, 'pdo');
        $prop->setAccessible(true);
        $prop->setValue($this->app->getDatabase(), $pdo);
        $this->pdo = $pdo;

        foreach ($this->schema($migrated ? 'NULL' : 'NOT NULL') as $sql) {
            $pdo->exec($sql);
        }

        $_SESSION = ['lstr_admin_id' => 1, 'lstr_admin_username' => 'admin'];
    }

    /** @return list<string> */
    private function schema(string $archivedAtNull): array
    {
        $intSettings  = ['members_per_page', 'country_field_enabled', 'queue_updates', 'contact_enabled',
            'affiliate_apply_enabled', 'codes_submit_enabled', 'code_approval_email', 'stat_newmembers_limit'];
        $textSettings = ['description', 'stats_template', 'members_template', 'affiliates_section_template', 'image', 'listing_type',
            'redirect_url_join', 'redirect_url_update', 'form_url_join', 'form_url_update', 'form_url_reset', 'form_url_contact',
            'redirect_url_reset', 'last_updated', 'contact_recipient', 'form_url_codes_submit', 'approved_by',
            'form_url_affiliate_apply', 'redirect_url_affiliate_apply', 'db_host', 'db_name', 'db_user', 'db_pass',
            'pagination_template', 'field_wrapper_template', 'success_template', 'contact_sent_template', 'contact_form_template',
            'affiliate_apply_sent_template', 'codes_template', 'newsfeed_template', 'adoption_intro_template', 'join_form_template',
            'codes_submit_form_template', 'affiliate_apply_form_template', 'adopt_form_template', 'update_form_template',
            'reset_form_template', 'affiliate_apply_style', 'codes_submit_style', 'affiliate_apply_toggle_label',
            'codes_submit_toggle_label', 'approval_url', 'updated_at'];
        $settingsCols = array_merge(
            array_map(static fn(string $c): string => "{$c} INTEGER NULL", $intSettings),
            array_map(static fn(string $c): string => "{$c} TEXT NULL", $textSettings),
        );

        return [
            "CREATE TABLE owned (
                listingid INTEGER PRIMARY KEY AUTOINCREMENT, title TEXT NOT NULL DEFAULT '', subject TEXT NOT NULL DEFAULT '',
                url TEXT NOT NULL DEFAULT '', email TEXT NOT NULL DEFAULT '', status INTEGER NOT NULL DEFAULT 2,
                imagefile TEXT NULL, category_id INTEGER NULL, opened TEXT NULL, catid TEXT NOT NULL DEFAULT '',
                additional TEXT NOT NULL DEFAULT '', dbtable TEXT NOT NULL DEFAULT '', dbserver TEXT NOT NULL DEFAULT '',
                dbuser TEXT NOT NULL DEFAULT '', dbpassword TEXT NOT NULL DEFAULT '', dbdatabase TEXT NOT NULL DEFAULT '',
                listingtype TEXT NULL)",
            "CREATE TABLE moon (
                email TEXT NOT NULL PRIMARY KEY, name TEXT NOT NULL DEFAULT '', country TEXT NOT NULL DEFAULT '',
                url TEXT NOT NULL DEFAULT '', pending INTEGER NOT NULL DEFAULT 0, password TEXT NOT NULL DEFAULT '',
                showemail INTEGER NOT NULL DEFAULT 1, showurl INTEGER NOT NULL DEFAULT 1, added TEXT NULL)",
            "CREATE TABLE lstr_categories (
                id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, slug TEXT NOT NULL DEFAULT '',
                parent_id INTEGER NULL, source TEXT NOT NULL DEFAULT 'custom',
                created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, UNIQUE (source, name), UNIQUE (source, slug))",
            'CREATE TABLE lstr_listing_categories (fanlisting_id INTEGER NOT NULL, category_id INTEGER NOT NULL,
                PRIMARY KEY (fanlisting_id, category_id))',
            "CREATE TABLE lstr_listing_lifecycle (
                fanlisting_id INTEGER NOT NULL PRIMARY KEY, lifecycle_status TEXT NOT NULL,
                archived_at TEXT {$archivedAtNull}, legacy_upcoming INTEGER NOT NULL DEFAULT 0,
                legacy_trouble INTEGER NOT NULL DEFAULT 0, adopted_to_name TEXT NULL, adopted_to_url TEXT NULL,
                archive_note TEXT NULL)",
            'CREATE TABLE lstr_fanlisting_settings (fanlisting_id INTEGER PRIMARY KEY, ' . implode(', ', $settingsCols) . ')',
            "CREATE TABLE lstr_activity_log (
                id INTEGER PRIMARY KEY AUTOINCREMENT, admin_user_id INTEGER NULL, action TEXT NOT NULL DEFAULT '',
                description TEXT NULL, ip_address TEXT NULL, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)",
            'CREATE TABLE lstr_site_settings (setting_key TEXT PRIMARY KEY, setting_value TEXT NULL, updated_at TEXT NULL)',
            "CREATE TABLE lstr_previous_owners (
                id INTEGER PRIMARY KEY AUTOINCREMENT, fanlisting_id INTEGER NOT NULL, name TEXT NOT NULL DEFAULT '',
                url TEXT NULL, sort_order INTEGER NOT NULL DEFAULT 0)",
            "CREATE TABLE lstr_affiliates (
                id INTEGER PRIMARY KEY AUTOINCREMENT, fanlisting_id INTEGER NULL, name TEXT NOT NULL DEFAULT '',
                url TEXT NOT NULL DEFAULT '', email TEXT NULL, image TEXT NULL, status TEXT NOT NULL DEFAULT 'approved',
                active INTEGER NOT NULL DEFAULT 1, application_message TEXT NULL, pending INTEGER NOT NULL DEFAULT 0,
                added_at TEXT NULL, sort_order INTEGER NOT NULL DEFAULT 0, link_status TEXT NOT NULL DEFAULT 'unchecked',
                link_checked_at TEXT NULL, link_redirect_url TEXT NOT NULL DEFAULT '',
                reciprocal_status TEXT NOT NULL DEFAULT 'unchecked', reciprocal_checked_at TEXT NULL)",
            "CREATE TABLE lstr_codes (
                id INTEGER PRIMARY KEY AUTOINCREMENT, fanlisting_id INTEGER NULL, filename TEXT NOT NULL DEFAULT '',
                name TEXT NULL, width INTEGER NOT NULL DEFAULT 0, height INTEGER NOT NULL DEFAULT 0,
                size_id INTEGER NULL, donor_id INTEGER NULL, category_id INTEGER NULL, status TEXT NOT NULL DEFAULT 'approved',
                submitter_name TEXT NULL, submitter_email TEXT NULL, submitter_url TEXT NULL,
                sort_order INTEGER NOT NULL DEFAULT 0, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                import_ref TEXT NULL, import_source_file TEXT NULL)",
            'CREATE TABLE lstr_code_sizes (id INTEGER PRIMARY KEY AUTOINCREMENT, fanlisting_id INTEGER NULL,
                label TEXT NULL, width INTEGER NOT NULL DEFAULT 0, height INTEGER NOT NULL DEFAULT 0, sort_order INTEGER NOT NULL DEFAULT 0)',
            "CREATE TABLE lstr_code_donors (id INTEGER PRIMARY KEY AUTOINCREMENT, fanlisting_id INTEGER NULL,
                name TEXT NOT NULL DEFAULT '', url TEXT NULL, email TEXT NULL)",
            "CREATE TABLE lstr_code_categories (id INTEGER PRIMARY KEY AUTOINCREMENT, fanlisting_id INTEGER NULL,
                name TEXT NOT NULL DEFAULT '', parent_id INTEGER NULL, sort_order INTEGER NOT NULL DEFAULT 0)",
            'CREATE TABLE lstr_code_category_map (code_id INTEGER NOT NULL, category_id INTEGER NOT NULL,
                PRIMARY KEY (code_id, category_id))',
        ];
    }
}
