<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Fills in text columns a listing table insists on but Luster doesn't use. An `owned` table
 that began on Enthusiast has email and template columns that are NOT NULL with no default.
 A strict server refuses an insert that leaves those out (error 1364), and strict is the
 default on modern MariaDB and MySQL. A server that isn't strict stores an empty string, so
 this gives every server what those installs have always had.
*/

declare(strict_types=1);

namespace Luster\Support;

use PDO;
use RuntimeException;

/**
 * Only text columns get filled. For anything else there's no one obviously right value, so
 * it's left out and the server decides, exactly as it does today: a server that isn't
 * strict picks its own, and a strict one refuses with its own error. The same goes for a
 * primary or single-column unique key, because an empty one would only work once. No known
 * table needs either. The table itself never changes.
 */
final class RequiredColumns
{
    private const TEXT_TYPES = ['char', 'varchar', 'tinytext', 'text', 'mediumtext', 'longtext'];

    private function __construct() {}

    /**
     * SQL to add to an INSERT, so it also gives an empty string to every required text
     * column it doesn't list itself. Both parts are empty when there aren't any, and
     * otherwise start with a comma, ready to go after the insert's own lists.
     *
     * @param string       $table  A table name, checked before it goes in any SQL.
     * @param list<string> $listed The columns the insert already gives a value.
     * @return array{columns: string, values: string}
     * @throws RuntimeException If the table's columns can't be read. That's never taken to
     *                          mean there's nothing to fill.
     */
    public static function forInsert(PDO $pdo, string $table, array $listed): array
    {
        if (preg_match('/^[A-Za-z0-9_]+$/', $table) !== 1) {
            throw new RuntimeException("Invalid table name: '{$table}'.");
        }

        $alreadyListed = array_flip(array_map('strtolower', $listed));

        $columns = '';
        $values  = '';
        foreach (self::requiredTextColumns($pdo, $table) as $column) {
            if (!isset($alreadyListed[strtolower($column)]) && preg_match('/^[A-Za-z0-9_]+$/', $column) === 1) {
                $columns .= ", `{$column}`";
                $values  .= ", ''";
            }
        }

        return ['columns' => $columns, 'values' => $values];
    }

    /** @return list<string> */
    private static function requiredTextColumns(PDO $pdo, string $table): array
    {
        return $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite'
            ? self::onSqlite($pdo, $table)
            : self::onMysql($pdo, $table);
    }

    /** @return list<string> */
    private static function onMysql(PDO $pdo, string $table): array
    {
        $stmt = $pdo->prepare(
            'SELECT COLUMN_NAME AS name, DATA_TYPE AS type, IS_NULLABLE AS nullable, COLUMN_DEFAULT AS dflt, EXTRA AS extra, COLUMN_KEY AS key_type
               FROM INFORMATION_SCHEMA.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :tbl
              ORDER BY ORDINAL_POSITION'
        );
        if ($stmt === false || !$stmt->execute(['tbl' => $table])) {
            throw new RuntimeException("Couldn't read the columns of table '{$table}'.");
        }
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if ($rows === []) {
            throw new RuntimeException("Couldn't find any columns for table '{$table}'.");
        }

        $required = [];
        foreach ($rows as $row) {
            if ($row['nullable'] === 'NO'
                && $row['dflt'] === null
                && !str_contains(strtolower((string) $row['extra']), 'auto_increment')
                && !str_contains(strtolower((string) $row['extra']), 'generated')
                && !in_array($row['key_type'], ['PRI', 'UNI'], true)
                && in_array(strtolower((string) $row['type']), self::TEXT_TYPES, true)
            ) {
                $required[] = (string) $row['name'];
            }
        }

        return $required;
    }

    /**
     * SQLite only runs the tests. table_info doesn't report UNIQUE, so this only leaves out
     * a primary key. The unique-key rule is checked on real MariaDB.
     *
     * @return list<string>
     */
    private static function onSqlite(PDO $pdo, string $table): array
    {
        $stmt = $pdo->query("PRAGMA table_info(`{$table}`)");
        if ($stmt === false) {
            throw new RuntimeException("Couldn't read the columns of table '{$table}'.");
        }
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if ($rows === []) {
            throw new RuntimeException("Couldn't find any columns for table '{$table}'.");
        }

        $required = [];
        foreach ($rows as $row) {
            if ((int) $row['notnull'] === 1
                && $row['dflt_value'] === null
                && (int) $row['pk'] === 0
                && preg_match('/char|text|clob/i', (string) $row['type']) === 1
            ) {
                $required[] = (string) $row['name'];
            }
        }

        return $required;
    }
}
