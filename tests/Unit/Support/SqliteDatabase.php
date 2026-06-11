<?php

declare(strict_types=1);

namespace Luster\Tests\Unit\Support;

use Luster\Core\Config;
use Luster\Core\Database;
use PDO;
use ReflectionProperty;

/**
 * Injects an in-memory SQLite PDO into Database::$pdo via reflection.
 * Allows repository integration tests without a real MySQL connection.
 */
final class SqliteDatabase
{
    /**
     * Returns a Database instance backed by an in-memory SQLite connection
     * with the given DDL already executed.
     *
     * @param string $ddl  One or more CREATE TABLE statements (semicolon-separated)
     */
    public static function make(string $ddl): Database
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        // MySQL's NOW() has no SQLite equivalent — shim it for repos that use it.
        $pdo->sqliteCreateFunction('NOW', static fn(): string => date('Y-m-d H:i:s'));

        foreach (array_filter(array_map('trim', explode(';', $ddl))) as $stmt) {
            $pdo->exec($stmt);
        }

        // Create a Database with a dummy Config (connection never actually opens)
        $tmpCfg = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'lstr_sqlite_cfg_' . uniqid('', true) . '.php';
        file_put_contents($tmpCfg, '<?php return [];');
        $config = new Config($tmpCfg);
        unlink($tmpCfg);

        $db = new Database($config);

        // Inject the SQLite PDO via reflection (bypasses lazy connect())
        $prop = new ReflectionProperty(Database::class, 'pdo');
        $prop->setAccessible(true);
        $prop->setValue($db, $pdo);

        return $db;
    }
}
