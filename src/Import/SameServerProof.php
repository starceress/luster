<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Works out whether two database connections reach the same server. Comparing
 database names can't tell: two servers can each have a database of one name.
 A named lock is server-wide, so a lock taken on one connection and seen on the
 other shows they share a server. It needs no privileges and writes nothing.
*/

declare(strict_types=1);

namespace Luster\Import;

use PDO;

/**
 * The proof that two connections share a server, for an import deciding whether a
 * source and target are one database.
 *
 *  - The lock is taken on $source, the connection opened just for the import. On MySQL
 *    before 5.7.5 a second GET_LOCK() silently drops the first, and $target is the app's
 *    own connection, which can be holding a member address lock.
 *  - IS_USED_LOCK() answers with the owner's connection id, not a yes or no, so it's
 *    compared with the id the source connection reports. NULL means this server has
 *    never heard of the lock.
 *  - Anything but a clear yes is false, and nothing here throws. Not proven only means
 *    the import treats them as two databases.
 *
 * It needs connections that each reach one stable server. A routing proxy can answer
 * lock functions from one server and reads from another, so seeing the lock wouldn't
 * prove where later reads come from. That's not a supported setup for this check.
 */
final class SameServerProof
{
    private function __construct() {}

    public static function holds(PDO $source, PDO $target): bool
    {
        $name = 'lstr_import_' . bin2hex(random_bytes(16));

        try {
            $ownerId = self::ask($source, 'SELECT CONNECTION_ID()', []);
            if (!is_numeric($ownerId) || !self::isOne(self::ask($source, 'SELECT GET_LOCK(?, 0)', [$name]))) {
                return false;
            }
        } catch (\Throwable) {
            return false;
        }

        try {
            $seen = self::ask($target, 'SELECT IS_USED_LOCK(?)', [$name]);

            return is_numeric($seen) && (string) $seen === (string) $ownerId;
        } catch (\Throwable) {
            return false;
        } finally {
            try {
                self::ask($source, 'SELECT RELEASE_LOCK(?)', [$name]);
            } catch (\Throwable) {
                // The lock goes when the connection closes, so this changes nothing.
            }
        }
    }

    /** @param list<string> $params */
    private static function ask(PDO $pdo, string $sql, array $params): mixed
    {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $answer = $stmt->fetchColumn();
        $stmt->closeCursor();

        return $answer;
    }

    private static function isOne(mixed $answer): bool
    {
        return is_numeric($answer) && (string) $answer === '1';
    }
}
