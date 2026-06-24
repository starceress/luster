<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Loads a mysqldump .sql file into temporary tables so import
 adapters can run against the dump data without a live source
 database. Only CREATE TABLE and INSERT statements are executed;
 all other SQL (DROP, ALTER, SET, GRANT, etc.) is silently
 discarded for security. Table names are prefixed to avoid
 collisions, and cleanup() drops them all after the import.
*/

declare(strict_types=1);

namespace Luster\Import;

/**
 * Loads a mysqldump .sql file into temporary tables so import adapters can run
 * queries against the dump data without a live source database connection.
 *
 * Only CREATE TABLE (column-definition form) and INSERT … VALUES (literal-only)
 * statements are executed; every other SQL statement (DROP, ALTER, SET, GRANT,
 * etc.) is silently discarded for security. Table names are prefixed to avoid
 * collisions with Luster's own tables. cleanup() drops all temp tables created
 * by a previous load() call.
 */
final class SqlDumpLoader
{
    /** Maximum allowed file size in bytes (50 MB). */
    public const MAX_FILE_BYTES = 52_428_800;

    /** Allowed MIME types for the uploaded file. */
    private const ALLOWED_MIME = ['text/plain', 'text/x-sql', 'application/sql', 'application/x-sql'];

    // -----------------------------------------------------------------------
    // Public API
    // -----------------------------------------------------------------------

    /**
     * Validates and loads a .sql dump file into $pdo under $tempPrefix.
     *
     * @return string[] Original (un-prefixed) table names that were successfully created.
     * @throws \RuntimeException on validation failure or fatal parse/exec error.
     */
    public function load(string $filePath, \PDO $pdo, string $tempPrefix): array
    {
        $this->validateFile($filePath);

        $content = file_get_contents($filePath);
        if ($content === false) {
            throw new \RuntimeException('Could not read the uploaded SQL file.');
        }

        $statements = $this->splitStatements($content);
        $createdTables = [];

        foreach ($statements as $stmt) {
            $stmt = trim($stmt);
            if ($stmt === '') {
                continue;
            }

            if (preg_match('/^CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?[`"]?([a-zA-Z0-9_]+)[`"]?/i', $stmt, $m)) {
                $origName = $m[1];

                // Only allow CREATE TABLE name (...) — the column definition must
                // start with an opening paren. Reject AS SELECT, LIKE, SELECT, and
                // any form that doesn't have a column-definition block.
                if (!preg_match('/^CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?[`"]?[a-zA-Z0-9_]+[`"]?\s*\(/i', $stmt)) {
                    throw new \RuntimeException("Rejected unsafe CREATE TABLE for `{$origName}`: only CREATE TABLE name (...) is allowed.");
                }

                $prefixedSql = $this->rewriteTableName($stmt, $origName, $tempPrefix . $origName);
                try {
                    $pdo->exec($prefixedSql);
                    $createdTables[] = $origName;
                } catch (\PDOException $e) {
                    throw new \RuntimeException("Failed to create table `{$origName}`: " . $e->getMessage());
                }
                continue;
            }

            if (preg_match('/^INSERT\s+(?:IGNORE\s+)?INTO\s+[`"]?([a-zA-Z0-9_]+)[`"]?/i', $stmt, $m)) {
                $origName = $m[1];

                // Only allow INSERT ... VALUES (...) with literal-only tuples.
                // Reject INSERT ... SELECT and any function calls inside VALUES.
                if (!preg_match('/\bVALUES\s*\(/i', $stmt)) {
                    continue;
                }

                // Strip quoted strings from the VALUES block before safety checks,
                // so legitimate text like 'Star Trek (2009)' or 'KIM (Keep In Mind)'
                // does not trigger false positives. Only unquoted SQL structure is checked.
                $valuesBlock = (string) preg_replace('/^.*?\bVALUES\s*/i', '', $stmt);
                $stripped    = self::stripQuotedStrings($valuesBlock);

                // Reject subqueries and expressions: SELECT, variables (@), subquery parens
                if (preg_match('/\b(SELECT|CONCAT|IF|CASE|LOAD_FILE|BENCHMARK|SLEEP)\b/i', $stripped)) {
                    continue;
                }
                // Reject @variables (user-defined or system)
                if (preg_match('/@\w/', $stripped)) {
                    continue;
                }
                // Reject any function call pattern: word( in unquoted context
                if (preg_match('/[a-zA-Z_]\w*\s*\(/', $stripped)) {
                    continue;
                }

                $prefixedSql = $this->rewriteTableName($stmt, $origName, $tempPrefix . $origName);
                try {
                    $pdo->exec($prefixedSql);
                } catch (\PDOException) {
                }
            }
            // All other statement types are silently discarded.
        }

        return array_unique($createdTables);
    }

    /**
     * Drops every temp table created by load(). Non-fatal per table — a failure
     * to drop one table does not prevent the others from being dropped.
     *
     * @param string[] $originalTableNames As returned by load().
     */
    public function cleanup(array $originalTableNames, string $tempPrefix, \PDO $pdo): void
    {
        foreach ($originalTableNames as $name) {
            $fullName = $tempPrefix . $name;
            try {
                $pdo->exec('DROP TABLE IF EXISTS `' . $fullName . '`');
            } catch (\PDOException) {
                // Non-fatal — log elsewhere if needed.
            }
        }
    }

    // -----------------------------------------------------------------------
    // Schema / source inference (static — usable without instantiation)
    // -----------------------------------------------------------------------

    /**
     * Infers the import source slug from a list of original table names.
     * Returns one of: 'enthusiast', 'listing-admin', 'fanupdate', 'codesort', 'unknown'.
     *
     * @param string[] $tables
     */
    public static function inferSource(array $tables): string
    {
        $set = array_flip($tables);

        if (isset($set['owned']) || isset($set['enth_fanlistings'])) {
            return 'enthusiast';
        }
        if (isset($set['main']) && (isset($set['members']) || isset($set['categories']))) {
            return 'listing-admin';
        }
        // Custom LA prefix: look for a table where {table}_members also exists (e.g. trex + trex_members)
        foreach ($tables as $tbl) {
            if (isset($set[$tbl . '_members']) && isset($set[$tbl . '_affiliates'])) {
                return 'listing-admin';
            }
        }
        if (isset($set['blog']) || isset($set['blog_category']) || isset($set['blog_catjoin'])) {
            return 'fanupdate';
        }
        if (isset($set['codes']) || isset($set['codes_codes'])) {
            return 'codesort';
        }

        return 'unknown';
    }

    /**
     * Infers the detailed schema variant string that matches what each adapter's
     * detectSchema() returns, so adapters can skip live detection.
     *
     * Returns one of: 'enthusiast_unprefixed', 'enthusiast_enth',
     *                 'listing_admin_3x', 'fanupdate', 'codesort_bare',
     *                 'codesort_prefixed', 'unknown'.
     *
     * @param string[] $tables
     */
    public static function inferSchemaVariant(array $tables): string
    {
        $set = array_flip($tables);

        if (isset($set['owned'])) {
            return 'enthusiast_unprefixed';
        }
        if (isset($set['enth_fanlistings'])) {
            return 'enthusiast_enth';
        }
        if (isset($set['main'])) {
            return 'listing_admin_3x';
        }
        if (isset($set['blog']) || isset($set['blog_category'])) {
            return 'fanupdate';
        }
        if (isset($set['codes'])) {
            return 'codesort_bare';
        }
        if (isset($set['codes_codes'])) {
            return 'codesort_prefixed';
        }

        return 'unknown';
    }

    // -----------------------------------------------------------------------
    // Validation
    // -----------------------------------------------------------------------

    /**
     * Validates the uploaded SQL file: checks it is readable, within size limits,
     * non-empty, and has an allowed plain-text MIME type.
     *
     * @throws \RuntimeException on any validation failure.
     */
    private function validateFile(string $filePath): void
    {
        if (!is_file($filePath) || !is_readable($filePath)) {
            throw new \RuntimeException('Uploaded file is not readable.');
        }

        $size = filesize($filePath);
        if ($size === false || $size > self::MAX_FILE_BYTES) {
            $mb = round(self::MAX_FILE_BYTES / 1_048_576);
            throw new \RuntimeException("SQL file exceeds the {$mb} MB size limit.");
        }
        if ($size === 0) {
            throw new \RuntimeException('Uploaded SQL file is empty.');
        }

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime  = $finfo->file($filePath);
        if (!in_array($mime, self::ALLOWED_MIME, true)) {
            throw new \RuntimeException("Invalid file type ({$mime}). Upload a plain-text .sql file.");
        }
    }

    // -----------------------------------------------------------------------
    // SQL parsing
    // -----------------------------------------------------------------------

    /**
     * Splits a mysqldump file into individual SQL statements.
     * Handles multi-line statements and ignores comment lines.
     *
     * @return string[]
     */
    private function splitStatements(string $sql): array
    {
        $statements = [];
        $buffer     = '';
        $inString   = false;
        $stringChar = '';
        $len        = strlen($sql);

        for ($i = 0; $i < $len; $i++) {
            $ch = $sql[$i];

            // Handle string literals so we don't split on ; inside them.
            if ($inString) {
                $buffer .= $ch;
                if ($ch === '\\') {
                    // Escaped character — consume next char too.
                    if ($i + 1 < $len) {
                        $buffer .= $sql[++$i];
                    }
                } elseif ($ch === $stringChar) {
                    $inString = false;
                }
                continue;
            }

            // Detect string start.
            if ($ch === "'" || $ch === '"' || $ch === '`') {
                $inString   = true;
                $stringChar = $ch;
                $buffer    .= $ch;
                continue;
            }

            // Skip single-line comments (-- and #).
            if ($ch === '-' && isset($sql[$i + 1]) && $sql[$i + 1] === '-') {
                while ($i < $len && $sql[$i] !== "\n") {
                    $i++;
                }
                continue;
            }
            if ($ch === '#') {
                while ($i < $len && $sql[$i] !== "\n") {
                    $i++;
                }
                continue;
            }

            // Skip block comments /* ... */.
            if ($ch === '/' && isset($sql[$i + 1]) && $sql[$i + 1] === '*') {
                $i += 2;
                while ($i < $len - 1) {
                    if ($sql[$i] === '*' && $sql[$i + 1] === '/') {
                        $i += 2;
                        break;
                    }
                    $i++;
                }
                continue;
            }

            // Statement terminator.
            if ($ch === ';') {
                $stmt = trim($buffer);
                if ($stmt !== '') {
                    $statements[] = $stmt;
                }
                $buffer = '';
                continue;
            }

            $buffer .= $ch;
        }

        // Trailing statement without semicolon.
        $stmt = trim($buffer);
        if ($stmt !== '') {
            $statements[] = $stmt;
        }

        return $statements;
    }

    /**
     * Replaces all single- and double-quoted string literals with empty quotes so
     * safety-check regexes only see unquoted SQL structure (no false positives from
     * data such as 'Star Trek (2009)' or 'KIM (Keep In Mind)').
     */
    private static function stripQuotedStrings(string $sql): string
    {
        $out       = '';
        $len       = strlen($sql);
        $inString  = false;
        $quote     = '';

        for ($i = 0; $i < $len; $i++) {
            $ch = $sql[$i];

            if ($inString) {
                if ($ch === '\\' && $i + 1 < $len) {
                    $i++;
                    continue;
                }
                if ($ch === $quote) {
                    if ($i + 1 < $len && $sql[$i + 1] === $quote) {
                        $i++;
                        continue;
                    }
                    $inString = false;
                    $out .= $quote;
                }
                continue;
            }

            if ($ch === "'" || $ch === '"') {
                $inString = true;
                $quote    = $ch;
                $out     .= $ch;
                continue;
            }

            $out .= $ch;
        }

        return $out;
    }

    /**
     * Rewrites all occurrences of $original (bare or backtick-quoted) to $replacement
     * in positions where a table name is expected (after TABLE [IF NOT EXISTS], INTO,
     * FROM, JOIN, UPDATE). Column names that happen to share the table name are left alone.
     */
    private function rewriteTableName(string $sql, string $original, string $replacement): string
    {
        // Match backtick-quoted or bare table name after the keywords we care about.
        $orig = preg_quote($original, '/');
        $repl = '`' . $replacement . '`';

        // Replace `original` anywhere it appears as a quoted identifier.
        $sql = preg_replace('/`' . $orig . '`/', $repl, $sql);

        // Replace unquoted occurrence right after TABLE [IF NOT EXISTS], INTO, FROM, JOIN, UPDATE
        // followed by whitespace — avoids replacing column names.
        $sql = preg_replace(
            '/\b(TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?|INTO\s+|FROM\s+|JOIN\s+|UPDATE\s+)' . $orig . '\b/i',
            '$1' . $repl,
            $sql
        );

        return $sql;
    }
}
