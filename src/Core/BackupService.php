<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Builds a backup ZIP of Luster application files and a SQL dump of all lstr_*,
 listing-registry, and per-listing member tables. Supports two profiles:
   'rollback' — excludes public/uploads/ (correct for update-rollback snapshots)
   'complete'  — includes public/uploads/ (suitable for a full site backup)
 Extracted from UpdateApplier so both the updater and the on-demand backup action
 can call it independently.
*/

declare(strict_types=1);

namespace Luster\Core;

use PDO;
use RuntimeException;
use ZipArchive;

/**
 * Creates backup ZIPs of Luster files and database tables.
 */
final class BackupService
{
    private const BACKUP_PATHS = [
        'src',
        'templates',
        'bootstrap.php',
        'composer.json',
        'composer.lock',
        'config/app.php',
        'config/routes.php',
        'public',
    ];

    public function __construct(
        private readonly Database $db,
        private readonly string $basePath,
        private readonly string $enthTablePrefix = '',
    ) {
        if ($enthTablePrefix !== '' && !preg_match('/^[a-zA-Z0-9_]+$/', $enthTablePrefix)) {
            throw new \InvalidArgumentException(
                "Invalid enth_table_prefix '{$enthTablePrefix}': only [a-zA-Z0-9_] allowed."
            );
        }
    }

    /**
     * Creates a backup ZIP in storage/cache and returns its path and filename.
     *
     * @param  string $profile  'rollback' excludes public/uploads/; 'complete' includes it
     * @return array{path:string,filename:string}
     */
    public function createZip(string $profile = 'rollback'): array
    {
        if (!class_exists(ZipArchive::class)) {
            throw new RuntimeException('The PHP zip extension is required to create backups.');
        }

        $cacheDir = $this->cacheDir();
        $filename = 'luster_backup_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.zip';
        $path     = $cacheDir . DIRECTORY_SEPARATOR . $filename;

        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Could not create backup ZIP.');
        }

        $includeUploads = $profile === 'complete';

        $zip->addFromString(
            'BACKUP-NOTES.txt',
            "Luster backup created " . date('c') . "\n\n"
            . "Profile: {$profile}\n"
            . "This backup contains Luster application files, lstr_* database tables,\n"
            . "the listing registry (owned/enth_fanlistings), and all per-listing member tables.\n"
        );

        foreach (self::BACKUP_PATHS as $rel) {
            $full = $this->path($rel);
            if (is_file($full)) {
                $zip->addFile($full, 'files/' . $this->zipPath($rel));
            } elseif (is_dir($full)) {
                $this->addDirectoryToZip($zip, $full, 'files/' . $this->zipPath($rel), $includeUploads);
            }
        }

        $zip->addFromString('database/luster_database.sql', $this->dumpDatabase());
        $zip->close();

        return ['path' => $path, 'filename' => $filename];
    }

    private function addDirectoryToZip(ZipArchive $zip, string $dir, string $zipPrefix, bool $includeUploads): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST,
        );

        foreach ($iterator as $item) {
            $full   = $item->getPathname();
            $rel    = $this->normalizeRelative(substr($full, strlen($dir) + 1));
            $zipRel = $this->normalizeRelative($zipPrefix . '/' . $rel);

            if ($this->shouldSkipPath($zipRel, $includeUploads)) {
                continue;
            }

            $zipPath = $this->zipPath($zipRel);
            if ($item->isDir()) {
                $zip->addEmptyDir($zipPath);
            } elseif ($item->isFile()) {
                $zip->addFile($full, $zipPath);
            }
        }
    }

    /** Returns true when a path should be excluded from the ZIP. */
    private function shouldSkipPath(string $rel, bool $includeUploads): bool
    {
        $rel = $this->normalizeRelative(preg_replace('#^files/#', '', $rel) ?? $rel);
        if (str_starts_with($rel, 'vendor/') || str_starts_with($rel, 'storage/')) {
            return true;
        }
        return !$includeUploads && str_starts_with($rel, 'public/uploads/');
    }

    /**
     * Produces a SQL dump of all lstr_* tables, the listing registry
     * (owned / enth_fanlistings), and per-listing member tables.
     */
    private function dumpDatabase(): string
    {
        $pdo    = $this->db->pdo();
        $tables = $this->lusterTableNames($pdo);

        $out   = [];
        $out[] = '-- Luster backup';
        $out[] = '-- Created: ' . date('c');
        $out[] = '-- Includes lstr_* tables, listing registry, and per-listing member tables.';
        $out[] = 'SET FOREIGN_KEY_CHECKS=0;';
        $out[] = '';

        foreach ($tables as $table) {
            $quoted = $this->quoteIdentifier($table);
            $row    = $pdo->query('SHOW CREATE TABLE ' . $quoted)->fetch(PDO::FETCH_ASSOC);
            $create = is_array($row) ? (string) ($row['Create Table'] ?? array_values($row)[1] ?? '') : '';
            if ($create !== '') {
                $out[] = 'DROP TABLE IF EXISTS ' . $quoted . ';';
                $out[] = $create . ';';
                $out[] = '';
            }

            $stmt = $pdo->query('SELECT * FROM ' . $quoted);
            while (($record = $stmt->fetch(PDO::FETCH_ASSOC)) !== false) {
                $columns = array_map(fn(string $col): string => $this->quoteIdentifier($col), array_keys($record));
                $values  = array_map(fn(mixed $value): string => $this->sqlLiteral($pdo, $value), array_values($record));
                $out[]   = 'INSERT INTO ' . $quoted . ' (' . implode(', ', $columns) . ') VALUES (' . implode(', ', $values) . ');';
            }
            $out[] = '';
        }

        $out[] = 'SET FOREIGN_KEY_CHECKS=1;';
        $out[] = '';

        return implode("\n", $out);
    }

    /** @return array<int,string> */
    private function lusterTableNames(PDO $pdo): array
    {
        $allTables = [];
        foreach ($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_NUM) as $row) {
            $allTables[] = (string) ($row[0] ?? '');
        }

        $tables = [];

        foreach ($allTables as $table) {
            if (str_starts_with($table, 'lstr_') && preg_match('/^[a-zA-Z0-9_]+$/', $table)) {
                $tables[] = $table;
            }
        }

        // Derive the registry table the same way ListingRepo does:
        // ($prefix === '') ? 'owned' : $prefix . 'listings'
        // so enth_table_prefix='enth_' gives 'enth_listings', not 'enth_fanlistings'.
        $isOwnedSchema = ($this->enthTablePrefix === '');
        $registry      = $isOwnedSchema ? 'owned' : $this->enthTablePrefix . 'listings';
        $memberCol     = $isOwnedSchema ? 'dbtable' : 'dbprefix';

        if (in_array($registry, $allTables, true)) {
            $tables[] = $registry;
            try {
                $stmt = $pdo->query("SELECT `{$memberCol}` FROM `{$registry}`");
                while ($row = $stmt->fetch(PDO::FETCH_NUM)) {
                    $memberPrefix = (string) ($row[0] ?? '');
                    if ($memberPrefix === '' || !preg_match('/^[a-zA-Z0-9_]+$/', $memberPrefix)) {
                        continue;
                    }
                    $memberTable = $isOwnedSchema ? $memberPrefix : $memberPrefix . 'members';
                    if (in_array($memberTable, $allTables, true) && !in_array($memberTable, $tables, true)) {
                        $tables[] = $memberTable;
                    }
                }
            } catch (\Throwable) {}
        }

        sort($tables);
        return $tables;
    }

    private function quoteIdentifier(string $identifier): string
    {
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $identifier)) {
            throw new RuntimeException('Unsafe database identifier in backup.');
        }
        return '`' . $identifier . '`';
    }

    private function sqlLiteral(PDO $pdo, mixed $value): string
    {
        if ($value === null) {
            return 'NULL';
        }
        return (string) $pdo->quote((string) $value);
    }

    private function cacheDir(): string
    {
        $dir = $this->path('storage/cache');
        if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
            throw new RuntimeException('Could not create storage/cache directory for backup.');
        }
        return $dir;
    }

    private function path(string $rel): string
    {
        return rtrim($this->basePath, '/\\') . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, ltrim($rel, '/\\'));
    }

    private function normalizeRelative(string $rel): string
    {
        return trim(str_replace('\\', '/', $rel), '/');
    }

    private function zipPath(string $rel): string
    {
        return $this->normalizeRelative($rel);
    }
}
