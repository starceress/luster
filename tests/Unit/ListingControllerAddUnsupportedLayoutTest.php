<?php

declare(strict_types=1);

namespace Luster\Tests\Unit;

use Luster\Controllers\Admin\ListingController;
use Luster\Core\Application;
use Luster\Core\Config;
use Luster\Core\Database;
use Luster\Core\Request;
use Luster\Core\View;
use Luster\Repositories\ListingRepo;
use Luster\Tests\Unit\Support\SqliteDatabase;
use Luster\Tests\Unit\Support\TracingPdo;
use PHPUnit\Framework\TestCase;

/**
 * Adding a listing from the admin panel only works on the unprefixed layout
 * (ListingRepo::insert() writes `owned`'s columns). On the enth_ layout the Add
 * Listing form and its save both stop before anything happens, so a banner
 * upload or a brand-new category isn't left behind by an insert that can't
 * work, and Owned Listings tells the admin why.
 */
final class ListingControllerAddUnsupportedLayoutTest extends TestCase
{
    private const ENTH_DDL = "CREATE TABLE enth_fanlistings (
        id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL DEFAULT '', subject TEXT NOT NULL DEFAULT '',
        url TEXT NOT NULL DEFAULT '', email TEXT NOT NULL DEFAULT '', dbprefix TEXT NOT NULL DEFAULT '',
        additional_info TEXT NOT NULL DEFAULT '', category_id INTEGER NULL,
        upcoming INTEGER NOT NULL DEFAULT 0, trouble INTEGER NOT NULL DEFAULT 0, opened TEXT NULL)";

    private const MESSAGE =
        "This Luster database uses the older Enthusiast table layout (enth_ tables), and adding a listing from the admin panel doesn't work on that layout yet. Nothing was saved.";

    /** @var array<string,mixed>|null */
    private ?array $sessionBefore = null;

    /** The fake LUSTER_BASE for the rendering test, removed afterwards. */
    private string $base = '';

    protected function setUp(): void
    {
        $this->sessionBefore = $_SESSION ?? null;
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        if ($this->sessionBefore === null) {
            unset($_SESSION);
        } else {
            $_SESSION = $this->sessionBefore;
        }
        if ($this->base !== '' && is_dir($this->base)) {
            $items = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->base, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST,
            );
            foreach ($items as $item) {
                $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
            }
            rmdir($this->base);
        }
    }

    private function config(string $enthTablePrefix): string
    {
        $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'lstr_add_layout_' . uniqid('', true) . '.php';
        file_put_contents($path, '<?php return ' . var_export([
            'enth_table_prefix' => $enthTablePrefix,
            'app_url'           => 'https://luster.test',
        ], true) . ';');
        return $path;
    }

    /** @return array<string, array{0: string}> */
    public static function entryPointProvider(): array
    {
        return ['the form' => ['addForm'], 'the save' => ['add']];
    }

    /**
     * The app on the enth_ layout, with an in-memory database holding its
     * registry and the categories table, and the statement log cleared.
     *
     * @return array{0: Application, 1: TracingPdo}
     */
    private function enthApp(): array
    {
        $cfgPath = $this->config('enth_');
        $app     = new Application(dirname(__DIR__, 2), $cfgPath);
        unlink($cfgPath);
        $pdo = TracingPdo::memory();
        $pdo->exec(self::ENTH_DDL);
        $pdo->exec("CREATE TABLE lstr_categories (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL DEFAULT '', source TEXT NOT NULL DEFAULT '')");
        $prop = new \ReflectionProperty(Database::class, 'pdo');
        $prop->setAccessible(true);
        $prop->setValue($app->getDatabase(), $pdo);
        $pdo->statements = [];
        return [$app, $pdo];
    }

    /** A full Add Listing post, a new category and a banner image included. */
    private function fullPost(string $method, string $upload): Request
    {
        return new Request([], [
            'title' => 'Moon FL', 'subject' => 'The Moon', 'url' => 'https://fans.test/moon/', 'status' => 'current',
            'cat_picks' => ['new:tfl:Brand New Category'],
        ], ['REQUEST_METHOD' => $method === 'add' ? 'POST' : 'GET'], [
            'image' => ['name' => 'banner.png', 'type' => 'image/png', 'tmp_name' => $upload, 'error' => UPLOAD_ERR_OK, 'size' => 10],
        ], []);
    }

    /** @dataProvider entryPointProvider */
    public function testOnTheEnthLayoutAddListingStopsBeforeAnythingHappens(string $method): void
    {
        [$app, $pdo] = $this->enthApp();
        $upload      = tempnam(sys_get_temp_dir(), 'lstr_banner_');

        $response = (new ListingController($app))->{$method}($this->fullPost($method, $upload), []);
        unlink($upload);

        self::assertTrue($response->isRedirect());
        self::assertSame($app->getBaseUrl() . '/admin/listings', $response->getRedirectUrl());
        self::assertSame(self::MESSAGE, $_SESSION['lstr_flash_error'] ?? null);
        self::assertSame([], $pdo->statements, 'Nothing read or written: no category, no listing');
    }

    /**
     * Followed past the redirect: Owned Listings shows why, escaped, in an error
     * alert, and only the once. Runs in a separate process because the admin
     * layout needs the global LUSTER_BASE (it stamps the scripts with filemtime()).
     *
     * @runInSeparateProcess
     */
    public function testOwnedListingsSaysWhyOnce(): void
    {
        $this->base = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'lstr_add_layout_base_' . uniqid('', true);
        mkdir($this->base . '/public/assets/css', 0777, true);
        mkdir($this->base . '/public/assets/js', 0777, true);
        foreach (['theme-init.js', 'filter-panel-init.js', 'admin.js'] as $script) {
            touch($this->base . '/public/assets/js/' . $script);
        }
        define('LUSTER_BASE', $this->base);

        [$app]      = $this->enthApp();
        $controller = new ListingController($app);
        $upload     = tempnam(sys_get_temp_dir(), 'lstr_banner_');
        $controller->add($this->fullPost('add', $upload), []);
        unlink($upload);

        $listings = new Request([], [], ['REQUEST_METHOD' => 'GET'], [], []);
        $page     = $controller->index($listings, [])->getBody();
        self::assertStringContainsString('<div class="alert alert-error">' . View::e(self::MESSAGE) . '</div>', $page);
        self::assertArrayNotHasKey('lstr_flash_error', $_SESSION, 'Read once, then cleared');

        self::assertStringNotContainsString(View::e(self::MESSAGE), $controller->index($listings, [])->getBody(), 'Not shown again');
    }

    public function testInsertRefusesOnTheEnthLayoutBeforeWriting(): void
    {
        $db   = SqliteDatabase::make(self::ENTH_DDL);
        $repo = new ListingRepo($db, $this->makeConfig('enth_'));

        self::assertFalse($repo->supportsInsert());
        try {
            $repo->insert('Moon FL', 'The Moon', 'https://fans.test/moon/', '', 2, null, null, null);
            self::fail('insert() should refuse');
        } catch (\RuntimeException $e) {
            self::assertSame("Adding a listing isn't supported on the enth_ table layout.", $e->getMessage());
        }
        self::assertSame(0, (int) $db->pdo()->query('SELECT COUNT(*) FROM enth_fanlistings')->fetchColumn());
    }

    public function testTheUnprefixedLayoutStillAddsListings(): void
    {
        $db = SqliteDatabase::make("CREATE TABLE owned (
            listingid INTEGER PRIMARY KEY AUTOINCREMENT, title TEXT NOT NULL DEFAULT '', subject TEXT NOT NULL DEFAULT '',
            url TEXT NOT NULL DEFAULT '', email TEXT NOT NULL DEFAULT '', status INTEGER NOT NULL DEFAULT 2,
            imagefile TEXT NULL, category_id INTEGER NULL, opened TEXT NULL, catid TEXT NOT NULL DEFAULT '',
            additional TEXT NOT NULL DEFAULT '', dbtable TEXT NOT NULL DEFAULT '', dbserver TEXT NOT NULL DEFAULT '',
            dbuser TEXT NOT NULL DEFAULT '', dbpassword TEXT NOT NULL DEFAULT '', dbdatabase TEXT NOT NULL DEFAULT '')");
        $repo = new ListingRepo($db, $this->makeConfig(''));

        self::assertTrue($repo->supportsInsert());
        $id = $repo->insert('Moon FL', 'The Moon', 'https://fans.test/moon/', '', 2, null, null, null);
        self::assertSame('Moon FL', $repo->getById($id)?->title);
    }

    private function makeConfig(string $enthTablePrefix): Config
    {
        $path   = $this->config($enthTablePrefix);
        $config = new Config($path);
        unlink($path);
        return $config;
    }
}
