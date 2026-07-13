<?php

declare(strict_types=1);

namespace Luster\Tests\Unit;

use Luster\Core\Config;
use Luster\Core\Database;
use Luster\Repositories\FieldRepo;
use Luster\Tests\Unit\Support\SqliteDatabase;
use PHPUnit\Framework\TestCase;

/**
 * getAllFields() previously called parseAdditionalInfo() directly, skipping the
 * unserialize-detection getFieldsForListing() already had. A listing whose
 * `additional` column holds a properly serialized field definition (written by
 * saveFields(), or by any import source that preserves type/label/options)
 * would get misparsed as legacy bare-name text — explode(',', ...) run
 * directly against a PHP-serialized byte string produces garbage. Both read
 * methods now share the same parseFieldInfo() entry point.
 */
final class FieldRepoAllFieldsTest extends TestCase
{
    private const DDL = <<<SQL
CREATE TABLE owned (
    listingid  INTEGER PRIMARY KEY AUTOINCREMENT,
    title      TEXT    NOT NULL DEFAULT '',
    additional TEXT    NOT NULL DEFAULT ''
)
SQL;

    private Database $db;
    private FieldRepo $repo;

    protected function setUp(): void
    {
        $this->db   = SqliteDatabase::make(self::DDL);
        $this->repo = new FieldRepo($this->db, $this->makeConfig(['enth_table_prefix' => '']));
    }

    private function makeConfig(array $values): Config
    {
        $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'lstr_test_cfg_' . uniqid('', true) . '.php';
        file_put_contents($path, '<?php return ' . var_export($values, true) . ';');
        $config = new Config($path);
        unlink($path);
        return $config;
    }

    private function pdo(): \PDO
    {
        $prop = new \ReflectionProperty($this->db, 'pdo');
        $prop->setAccessible(true);
        return $prop->getValue($this->db);
    }

    public function testGetAllFieldsPreservesSerializedDropdownType(): void
    {
        $pdo = $this->pdo();

        $serialized = serialize([
            ['name' => 'favorite', 'label' => 'Favorite Character', 'type' => 'dropdown', 'required' => false, 'options' => ''],
        ]);
        $pdo->prepare('INSERT INTO owned (listingid, title, additional) VALUES (1, :t, :a)')
            ->execute(['t' => 'Serialized listing', 'a' => $serialized]);

        $pdo->prepare('INSERT INTO owned (listingid, title, additional) VALUES (2, :t, :a)')
            ->execute(['t' => 'Legacy bare-name listing', 'a' => 'favorite_movie']);

        $all = $this->repo->getAllFields();

        self::assertCount(1, $all[1]);
        self::assertSame('favorite', $all[1][0]->name);
        self::assertSame('dropdown', $all[1][0]->type, 'Serialized type must survive getAllFields(), not be misparsed as bare-name text.');
        self::assertSame('Favorite Character', $all[1][0]->label);

        self::assertCount(1, $all[2]);
        self::assertSame('favorite_movie', $all[2][0]->name);
        self::assertSame('text', $all[2][0]->type, 'A genuinely bare legacy name still defaults to text.');
    }

    public function testGetAllFieldsReturnsEmptyArrayForBlankColumn(): void
    {
        $this->pdo()
            ->prepare('INSERT INTO owned (listingid, title, additional) VALUES (3, :t, :a)')
            ->execute(['t' => 'No fields', 'a' => '']);

        $all = $this->repo->getAllFields();

        self::assertSame([], $all[3]);
    }
}
