<?php

declare(strict_types=1);

namespace Luster\Tests\Unit;

use Luster\Controllers\Admin\FieldController;
use Luster\Core\Application;
use Luster\Core\Database;
use Luster\Core\Request;
use Luster\Repositories\FieldRepo;
use Luster\Repositories\MemberRepo;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * The admin's Add Field and Delete Field with a field named with only digits,
 * on the unprefixed schema, where the field name is the column name. Both
 * used to build unquoted DDL ("ADD COLUMN 123"), which MySQL rejects.
 */
final class FieldControllerNumericNameTest extends TestCase
{
    private const FIELDS_URL = 'https://luster.test/admin/listings/1/fields';

    /** @return array{0: Application, 1: PDO} */
    private function ownedApp(): array
    {
        $cfgPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'lstr_field_numeric_' . uniqid('', true) . '.php';
        file_put_contents($cfgPath, '<?php return ' . var_export(['enth_table_prefix' => '', 'app_url' => 'https://luster.test'], true) . ';');
        $app = new Application(dirname(__DIR__, 2), $cfgPath);
        unlink($cfgPath);

        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec("CREATE TABLE owned (
            listingid INTEGER PRIMARY KEY AUTOINCREMENT, title TEXT NOT NULL DEFAULT '', url TEXT NOT NULL DEFAULT '',
            email TEXT NOT NULL DEFAULT '', subject TEXT NOT NULL DEFAULT '', status INTEGER NOT NULL DEFAULT 2,
            dbtable TEXT NOT NULL DEFAULT '', additional TEXT NOT NULL DEFAULT '', catid TEXT NOT NULL DEFAULT '',
            category_id INTEGER NULL, opened TEXT NULL)");
        $pdo->exec("INSERT INTO owned (listingid, title, subject, url, dbtable) VALUES (1, 'Moon FL', 'The Moon', 'https://fans.test/moon/', 'fl_moon')");
        $pdo->exec("CREATE TABLE fl_moon (
            email TEXT NOT NULL PRIMARY KEY, name TEXT NOT NULL DEFAULT '', country TEXT NOT NULL DEFAULT '',
            url TEXT NOT NULL DEFAULT '', pending INTEGER NOT NULL DEFAULT 0, password TEXT NOT NULL DEFAULT '',
            showemail INTEGER NOT NULL DEFAULT 1, showurl INTEGER NOT NULL DEFAULT 1, added TEXT NULL)");

        $prop = new \ReflectionProperty(Database::class, 'pdo');
        $prop->setAccessible(true);
        $prop->setValue($app->getDatabase(), $pdo);
        return [$app, $pdo];
    }

    public function testAFieldCalled123CanBeAddedFilledInAndDeleted(): void
    {
        [$app, $pdo] = $this->ownedApp();
        $controller  = new FieldController($app);
        $fieldRepo   = new FieldRepo($app->getDatabase(), $app->getConfig());
        $memberRepo  = new MemberRepo($app->getDatabase(), $app->getConfig());

        $added = $controller->add(new Request([], ['name' => '123', 'label' => 'Lucky number', 'type' => 'text', 'required' => '0'],
            ['REQUEST_METHOD' => 'POST'], [], []), ['fid' => '1']);
        self::assertSame(self::FIELDS_URL . '?success=' . urlencode('Field added successfully.'), $added->getRedirectUrl());
        self::assertContains('123', self::columns($pdo));
        self::assertSame(['123'], array_map(static fn($f): string => $f->name, $fieldRepo->getFieldsForListing(1)));

        // The new field takes a member's answer like any other.
        $memberRepo->insert('fl_moon', ['email' => 'ann@example.com', 'name' => 'Ann', '123' => '42'] + $memberRepo->approvedColumns());
        self::assertSame('42', $memberRepo->getByEmail('fl_moon', 'ann@example.com')?->additionalFields['123']);

        $deleted = $controller->delete(new Request([], [], ['REQUEST_METHOD' => 'POST'], [], []), ['fid' => '1', 'name' => '123']);
        self::assertSame(self::FIELDS_URL . '?success=' . urlencode('Field deleted successfully.'), $deleted->getRedirectUrl());
        self::assertNotContains('123', self::columns($pdo));
        self::assertSame([], $fieldRepo->getFieldsForListing(1));
        self::assertSame('Ann', $memberRepo->getByEmail('fl_moon', 'ann@example.com')?->name, 'The member stays');
    }

    /** @return list<string> */
    private static function columns(PDO $pdo): array
    {
        return array_map('strval', array_column($pdo->query('PRAGMA table_info(`fl_moon`)')->fetchAll(PDO::FETCH_ASSOC), 'name'));
    }
}
