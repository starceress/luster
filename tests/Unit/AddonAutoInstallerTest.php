<?php

declare(strict_types=1);

namespace Luster\Tests\Unit;

use Luster\Core\AddonInterface;
use Luster\Core\Router;
use Luster\Import\AddonAutoInstaller;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Tests for AddonAutoInstaller, the helper import sources use to create an
 * add-on's table on demand when add-on-gated data (Wishlist, KIM, Updates,
 * Adoption, Join Rules) is found during an import but the matching Luster
 * add-on isn't installed yet.
 *
 * Uses a minimal fake add-on with SQLite-compatible DDL rather than a real
 * add-on class — production add-ons (WishlistAddon, KimAddon, etc.) use
 * MySQL-only DDL (AUTO_INCREMENT, inline KEY clauses) that SQLite's PDO
 * driver rejects, so they cannot exercise the happy path under this in-memory
 * test harness. That DDL is exercised in production by the same install()
 * call the admin Add-ons panel already uses (AddonsController::install()).
 */
final class AddonAutoInstallerTest extends TestCase
{
    private function makePdo(): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE,            PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec('CREATE TABLE lstr_addons (addon_key TEXT PRIMARY KEY)');
        return $pdo;
    }

    public function testCreatesTableWhenMissing(): void
    {
        $pdo = $this->makePdo();

        $result = AddonAutoInstaller::ensureInstalled($pdo, FakeTestAddon::class);

        self::assertTrue($result);
        $exists = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='fake_addon_table'")->fetchColumn();
        self::assertNotFalse($exists, 'install() should have created the addon table');
    }

    public function testRegistersAddonKeyInLstrAddons(): void
    {
        $pdo = $this->makePdo();

        AddonAutoInstaller::ensureInstalled($pdo, FakeTestAddon::class);

        $key = $pdo->query("SELECT addon_key FROM lstr_addons WHERE addon_key = 'fake-test'")->fetchColumn();
        self::assertSame('fake-test', $key, 'The add-on should be registered in lstr_addons so it shows as installed in the admin panel');
    }

    public function testIsIdempotentOnSecondCall(): void
    {
        $pdo = $this->makePdo();

        AddonAutoInstaller::ensureInstalled($pdo, FakeTestAddon::class);
        $pdo->exec("INSERT INTO fake_addon_table (id) VALUES (1)");

        $result = AddonAutoInstaller::ensureInstalled($pdo, FakeTestAddon::class);

        self::assertTrue($result);
        $count = (int) $pdo->query('SELECT COUNT(*) FROM lstr_addons')->fetchColumn();
        self::assertSame(1, $count, 'A second call must not insert a duplicate lstr_addons row');
        $rowStillThere = (int) $pdo->query('SELECT COUNT(*) FROM fake_addon_table')->fetchColumn();
        self::assertSame(1, $rowStillThere, 'A second call must not drop or recreate existing data (install() uses CREATE TABLE IF NOT EXISTS)');
    }

    public function testReturnsTrueWhenAlreadyInstalled(): void
    {
        $pdo = $this->makePdo();
        AddonAutoInstaller::ensureInstalled($pdo, FakeTestAddon::class);

        $result = AddonAutoInstaller::ensureInstalled($pdo, FakeTestAddon::class);

        self::assertTrue($result);
    }

    public function testReturnsFalseWhenInstallThrows(): void
    {
        $pdo = $this->makePdo();

        $result = AddonAutoInstaller::ensureInstalled($pdo, FailingFakeTestAddon::class);

        self::assertFalse($result);
    }

    public function testReturnsFalseForUnknownClass(): void
    {
        $pdo = $this->makePdo();

        $result = AddonAutoInstaller::ensureInstalled($pdo, 'Luster\\Tests\\Unit\\NoSuchAddonClassXyz');

        self::assertFalse($result);
    }

    public function testSucceedsEvenWhenLstrAddonsTableIsMissing(): void
    {
        // Simulates a pre-migration-27 install: the add-on's own data table
        // must still be created so the import doesn't lose data, even though
        // the registry update can't be recorded.
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE,            PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        // Deliberately no lstr_addons table.

        $result = AddonAutoInstaller::ensureInstalled($pdo, FakeTestAddon::class);

        self::assertTrue($result);
        $exists = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='fake_addon_table'")->fetchColumn();
        self::assertNotFalse($exists);
    }
}

/** Minimal SQLite-compatible stand-in for a real AddonInterface implementation. */
final class FakeTestAddon implements AddonInterface
{
    public function getKey(): string { return 'fake-test'; }
    public function getName(): string { return 'Fake Test Addon'; }
    public function getDescription(): string { return 'Used only by AddonAutoInstallerTest.'; }

    public function install(PDO $pdo): void
    {
        $pdo->exec('CREATE TABLE IF NOT EXISTS fake_addon_table (id INTEGER PRIMARY KEY)');
    }

    public function uninstall(PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS fake_addon_table');
    }

    public function registerRoutes(Router $router): void {}
}

/** Stand-in whose install() always fails, to exercise the graceful-failure path. */
final class FailingFakeTestAddon implements AddonInterface
{
    public function getKey(): string { return 'failing-fake-test'; }
    public function getName(): string { return 'Failing Fake Test Addon'; }
    public function getDescription(): string { return 'Used only by AddonAutoInstallerTest.'; }

    public function install(PDO $pdo): void
    {
        throw new \RuntimeException('Simulated install failure.');
    }

    public function uninstall(PDO $pdo): void {}
    public function registerRoutes(Router $router): void {}
}
