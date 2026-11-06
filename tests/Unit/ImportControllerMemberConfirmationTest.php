<?php

declare(strict_types=1);

namespace Luster\Tests\Unit;

use Luster\Controllers\Admin\ImportController;
use Luster\Core\Application;
use Luster\Core\Database;
use Luster\Core\Request;
use Luster\Import\EnthusiastImportSource;
use Luster\Import\ImportOptions;
use Luster\Tests\Unit\Support\EnthusiastImportFixture;
use Luster\Tests\Unit\Support\TracingPdo;
use PHPUnit\Framework\TestCase;

/**
 * The wizard's handling of the member location tick, through the real controller with an
 * uploaded file's tables: where a yes can come from, and what happens when it no longer
 * matches. A file's tables live in Luster's own database, so one connection does it all.
 */
final class ImportControllerMemberConfirmationTest extends TestCase
{
    use EnthusiastImportFixture;

    private const PREFIX = 'lstr_tmp_abc_';

    /** @var array<string,mixed>|null */
    private ?array $sessionBefore = null;

    protected function setUp(): void
    {
        $this->sessionBefore = $_SESSION ?? null;
        $_SESSION = [];
        defined('LUSTER_BASE') || define('LUSTER_BASE', dirname(__DIR__, 2));
    }

    protected function tearDown(): void
    {
        if ($this->sessionBefore === null) {
            unset($_SESSION);
        } else {
            $_SESSION = $this->sessionBefore;
        }
    }

    /** @return array{0: Application, 1: TracingPdo} */
    private function app(): array
    {
        $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'lstr_confirm_' . uniqid('', true) . '.php';
        file_put_contents($path, '<?php return ' . var_export(['enth_table_prefix' => '', 'app_url' => 'https://luster.test'], true) . ';');
        $app = new Application(dirname(__DIR__, 2), $path);
        unlink($path);

        $pdo = $this->targetDb();
        $prop = new \ReflectionProperty(Database::class, 'pdo');
        $prop->setAccessible(true);
        $prop->setValue($app->getDatabase(), $pdo);

        // An uploaded file's tables, already loaded, naming the database they came from.
        $pdo->exec(str_replace('CREATE TABLE owned', 'CREATE TABLE ' . self::PREFIX . 'owned', self::ownedSourceDdl()));
        $pdo->exec('CREATE TABLE ' . self::PREFIX . 'category (catid INTEGER PRIMARY KEY, catname TEXT, parent INTEGER DEFAULT 0)');
        $pdo->exec('INSERT INTO ' . self::PREFIX . "owned (listingid, dbserver, dbdatabase, dbtable, url, title) VALUES (1, 'localhost', 'old_name', 'moon', 'http://a.test/moon', 'Moon')");
        $this->createSourceMemberTable($pdo, self::PREFIX . 'moon');
        $this->addFan($pdo, self::PREFIX . 'moon', ['email' => 'one@example.test']);

        $_SESSION['lstr_import_dump_prefix'] = self::PREFIX;
        $_SESSION['lstr_import_dump_tables'] = ['owned', 'category', 'moon', '_schema' => 'enthusiast_unprefixed'];

        return [$app, $pdo];
    }

    /** @return array<string, string> */
    private function scope(): array
    {
        return [
            'source' => 'enthusiast', 'conflict_strategy' => 'skip', 'import_owned_listings' => '1', 'import_members' => '1',
            '_dump_table_prefix' => self::PREFIX, '_dump_schema_variant' => 'enthusiast_unprefixed',
        ];
    }

    private function fingerprint(TracingPdo $pdo): string
    {
        $preview = (new EnthusiastImportSource())->preview($pdo, $pdo, ImportOptions::fromPost($this->scope()));
        return (string) $preview->memberConfirmation['fingerprint'];
    }

    /** @param array<string, string> $post */
    private function post(array $post): Request
    {
        return new Request([], $post, ['REQUEST_METHOD' => 'POST'], [], []);
    }

    private function location(\Luster\Core\Response $response): string
    {
        return (string) $response->getRedirectUrl();
    }

    public function testATickWithTheRightFingerprintCopiesThem(): void
    {
        [$app, $pdo] = $this->app();
        $_SESSION['lstr_import_scope'] = $this->scope();

        $response = (new ImportController($app))->run(
            $this->post(['confirm_member_location' => '1', 'member_location_fingerprint' => $this->fingerprint($pdo)]),
            ['source' => 'enthusiast'],
        );

        self::assertStringEndsWith('/admin/import/enthusiast/result', $this->location($response));
        self::assertSame(1, $this->rowCount($pdo, 'moon'));
    }

    public function testNoTickLeavesThoseMembersBehindAndStillImports(): void
    {
        [$app, $pdo] = $this->app();
        $_SESSION['lstr_import_scope'] = $this->scope();

        $response = (new ImportController($app))->run($this->post([]), ['source' => 'enthusiast']);

        self::assertStringEndsWith('/admin/import/enthusiast/result', $this->location($response));
        self::assertSame(1, $this->rowCount($pdo, 'owned'));
        self::assertSame(0, $this->rowCount($pdo, 'moon'));
    }

    /** @return array<string, array{0: array<string, string>}> */
    public static function badConfirmations(): array
    {
        return [
            'a tick with no fingerprint' => [['confirm_member_location' => '1']],
            'a blank fingerprint'        => [['confirm_member_location' => '1', 'member_location_fingerprint' => '  ']],
            'the wrong fingerprint'      => [['confirm_member_location' => '1', 'member_location_fingerprint' => 'abc123']],
        ];
    }

    /**
     * @dataProvider badConfirmations
     * @param array<string, string> $post
     */
    public function testABadConfirmationGoesBackToThePreviewWithNothingWritten(array $post): void
    {
        [$app, $pdo] = $this->app();
        $_SESSION['lstr_import_scope'] = $this->scope();

        $response = (new ImportController($app))->run($this->post($post), ['source' => 'enthusiast']);

        self::assertStringEndsWith('/admin/import/enthusiast/preview', $this->location($response));
        self::assertStringContainsString('Check it and confirm again', (string) $_SESSION['lstr_flash_error']);
        self::assertSame(0, $this->rowCount($pdo, 'owned'));
        self::assertFalse($this->tableExists($pdo, 'moon'));
        self::assertTrue($this->tableExists($pdo, self::PREFIX . 'moon'), "the uploaded file's tables stay, to look at again");
        self::assertSame(self::PREFIX, $_SESSION['lstr_import_dump_prefix']);
        self::assertSame($this->scope(), $_SESSION['lstr_import_scope']);
    }

    /**
     * The wizard's script posts the Run form in the background, stops at the redirect
     * and then always loads /result, so that has to send the owner back as well.
     */
    public function testTheResultStepAlsoSendsThemBackToThePreview(): void
    {
        [$app] = $this->app();
        $_SESSION['lstr_import_scope'] = $this->scope();
        $controller = new ImportController($app);
        $controller->run($this->post(['confirm_member_location' => '1', 'member_location_fingerprint' => 'abc123']), ['source' => 'enthusiast']);

        $first = $controller->result($this->post([]), ['source' => 'enthusiast']);
        self::assertStringEndsWith('/admin/import/enthusiast/preview', (string) $first->getRedirectUrl());
        self::assertStringContainsString('Check it and confirm again', (string) $_SESSION['lstr_flash_error'], 'still waiting to be shown');

        $second = $controller->result($this->post([]), ['source' => 'enthusiast']);
        self::assertStringEndsWith('/admin/import/enthusiast/connect', (string) $second->getRedirectUrl(), 'only once');
    }

    public function testAStaleReportIsNotShownInsteadOfGoingBack(): void
    {
        [$app] = $this->app();
        $_SESSION['lstr_import_scope']  = $this->scope();
        $_SESSION['lstr_import_result'] = 'an earlier report';
        $controller = new ImportController($app);
        $controller->run($this->post(['confirm_member_location' => '1']), ['source' => 'enthusiast']);

        $response = $controller->result($this->post([]), ['source' => 'enthusiast']);

        self::assertStringEndsWith('/admin/import/enthusiast/preview', (string) $response->getRedirectUrl());
    }

    public function testAFingerprintWithoutTheTickDoesNothing(): void
    {
        [$app, $pdo] = $this->app();
        $_SESSION['lstr_import_scope'] = $this->scope();

        (new ImportController($app))->run(
            $this->post(['member_location_fingerprint' => $this->fingerprint($pdo)]),
            ['source' => 'enthusiast'],
        );

        self::assertSame(0, $this->rowCount($pdo, 'moon'));
    }

    public function testAYesKeptInTheStoredOptionsCountsForNothing(): void
    {
        [$app, $pdo] = $this->app();
        $_SESSION['lstr_import_scope'] = $this->scope() + ['_member_consent' => $this->fingerprint($pdo)];

        (new ImportController($app))->run($this->post([]), ['source' => 'enthusiast']);

        self::assertSame(0, $this->rowCount($pdo, 'moon'));
    }

    public function testTheOptionsStepCannotSetTheHostOrAYes(): void
    {
        [$app, $pdo] = $this->app();

        (new ImportController($app))->scope(
            $this->post(['import_members' => '1', '_source_host' => 'localhost', '_member_consent' => 'abc123']),
            ['source' => 'enthusiast'],
        );

        self::assertSame('1', $_SESSION['lstr_import_scope']['import_members']);
        self::assertArrayNotHasKey('_source_host', $_SESSION['lstr_import_scope']);
        self::assertArrayNotHasKey('_member_consent', $_SESSION['lstr_import_scope']);
    }

    public function testAStoredHostIsReplacedBeforeARun(): void
    {
        [$app, $pdo] = $this->app();
        $fingerprint = $this->fingerprint($pdo);
        // A file has no host, so a planted one would change the fingerprint if it were used.
        $_SESSION['lstr_import_scope'] = $this->scope() + ['_source_host' => 'planted.test'];

        $response = (new ImportController($app))->run(
            $this->post(['confirm_member_location' => '1', 'member_location_fingerprint' => $fingerprint]),
            ['source' => 'enthusiast'],
        );

        self::assertStringEndsWith('/admin/import/enthusiast/result', $this->location($response));
    }
}
