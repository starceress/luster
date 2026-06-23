<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Reads a legacy fanlisting script's config.php as plain text and
 extracts database credentials via regex. The file is never
 require()'d or eval()'d -- only string matching is used. Covers
 variable naming conventions from Enthusiast, Listing Admin,
 FanUpdate, CodeSort, and Fanatically so the import wizard can
 pre-fill the connection form automatically.
*/

declare(strict_types=1);

namespace Luster\Import;
final class LegacyConfigReader
{
    /** Refuse files larger than this; config.php files are always tiny. */
    private const MAX_FILE_BYTES = 1_048_576; // 1 MB

    /**
     * Parse $filePath and return recognised credential fields.
     * Each value is the first non-empty string matched in the file, or null.
     *
     * @return array{host: string|null, name: string|null, user: string|null, pass: string|null, prefix: string|null}
     * @throws \RuntimeException when the file cannot be read or is too large.
     */
    public static function parse(string $filePath): array
    {
        if (!is_file($filePath) || !is_readable($filePath)) {
            throw new \RuntimeException('File not found or not readable.');
        }

        $size = filesize($filePath);
        if ($size === false || $size > self::MAX_FILE_BYTES) {
            throw new \RuntimeException('File is too large (max 1 MB).');
        }

        $content = file_get_contents($filePath, false, null, 0, self::MAX_FILE_BYTES);
        if ($content === false) {
            throw new \RuntimeException('Could not read the file.');
        }

        return [
            'host'   => self::first($content,
                ['db_server', 'db_host'],
                ['DB_SERVER', 'DB_HOST']),
            'name'   => self::first($content,
                ['db_database', 'db_name', 'database'],
                ['DB_DATABASE', 'DB_NAME']),
            'user'   => self::first($content,
                ['db_user', 'db_username'],
                ['DB_USER', 'DB_USERNAME']),
            'pass'   => self::first($content,
                ['db_password', 'db_pass'],
                ['DB_PASSWORD', 'DB_PASS']),
            'prefix' => self::first($content,
                ['table_prefix', 'db_prefix', 'tbl_prefix'],
                ['TABLE_PREFIX', 'DB_PREFIX']),
        ];
    }

    /**
     * Tries each name in order; returns the first non-empty value found.
     *
     * @param list<string> $vars   PHP variable names (without the leading $)
     * @param list<string> $consts PHP constant names for define() calls
     */
    private static function first(string $content, array $vars, array $consts): ?string
    {
        foreach ($vars as $name) {
            if (($v = self::matchVar($content, $name)) !== null) {
                return $v;
            }
        }
        foreach ($consts as $name) {
            if (($v = self::matchConst($content, $name)) !== null) {
                return $v;
            }
        }
        return null;
    }

    /**
     * Matches  $varname = 'value';  or  $varname = "value";
     * anchored to the start of a line so e.g. $db_name_extra won't match $db_name.
     */
    private static function matchVar(string $content, string $var): ?string
    {
        // (?![a-zA-Z0-9_]) prevents $db_user from matching $db_username.
        $pat = '/^\s*\$' . preg_quote($var, '/') . '(?![a-zA-Z0-9_])\s*=\s*(?:\'([^\']*)\'|"([^"]*)")/m';
        if (!preg_match($pat, $content, $m)) {
            return null;
        }
        // m[1] = single-quoted value, m[2] = double-quoted value.
        // Unmatched groups are empty string in PHP; treat both as potentially empty.
        $val = ($m[1] !== '') ? $m[1] : ($m[2] ?? '');
        return $val !== '' ? $val : null;
    }

    /**
     * Matches  define('CONSTNAME', 'value');
     * Case-insensitive on the define() call itself.
     */
    private static function matchConst(string $content, string $const): ?string
    {
        $pat = '/define\s*\(\s*[\'"]' . preg_quote($const, '/') . '[\'"]\s*,\s*(?:\'([^\']*)\'|"([^"]*)")\s*\)/i';
        if (!preg_match($pat, $content, $m)) {
            return null;
        }
        $val = ($m[1] !== '') ? $m[1] : ($m[2] ?? '');
        return $val !== '' ? $val : null;
    }
}
