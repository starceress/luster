<?php

declare(strict_types=1);

namespace Luster\Import;

use PDO;

/**
 * Import adapter for FanUpdate databases.
 *
 * FanUpdate stores update entries in a `blog` table whose name is configurable
 * via blog-config.php. The table prefix is provided as $options->fanUpdatePrefix
 * (default 'blog').
 *
 * Schema detected:
 *   'fanupdate_blog' — standard table name matches and entry_id column present
 *
 * What this adapter imports into Luster's lstr_* tables:
 *   - lstr_updates ← {prefix} entries (requires Updates Feed add-on to be installed)
 *
 * Fanlisting association: FanUpdate links entries to fanlistings via
 * {prefix}_category (fl_id, fl_subject) and {prefix}_catjoin (entry_id, cat_id).
 * fl_subject values are matched case-insensitively against Luster's listing titles.
 * Unmatched entries are imported with fanlisting_id = NULL (collective-level).
 *
 * Non-public entries (is_public = 0) are skipped.
 * Comments, smilies, and display options have no Luster equivalent and are dropped.
 */
final class FanUpdateImportSource implements ImportSourceInterface
{
    private const CHUNK = 500;

    // -----------------------------------------------------------------------
    // ImportSourceInterface
    // -----------------------------------------------------------------------

    public function getName(): string
    {
        return 'FanUpdate';
    }

    public function testConnection(array $credentials): ?string
    {
        try {
            $this->openConnection($credentials);
            return null;
        } catch (\Throwable $e) {
            return $e->getMessage();
        }
    }

    public function openConnection(array $credentials): PDO
    {
        $dsn = sprintf(
            'mysql:host=%s;dbname=%s;charset=utf8mb4',
            $credentials['host'],
            $credentials['name'],
        );

        return new PDO($dsn, $credentials['user'], $credentials['pass'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    }

    public function detectSchema(PDO $source): string
    {
        // Try the default `blog` table; check for entry_id to distinguish
        $stmt = $source->query("SHOW TABLES LIKE 'blog'");
        if ($stmt && $stmt->fetchColumn() !== false) {
            try {
                $source->query("SELECT entry_id FROM `blog` LIMIT 0");
                return 'fanupdate_blog';
            } catch (\Throwable) {}
        }
        return 'unknown';
    }

    // -----------------------------------------------------------------------
    // Preview (read-only)
    // -----------------------------------------------------------------------

    public function preview(PDO $source, PDO $target, ImportOptions $options): ImportPreview
    {
        $schema = $options->schemaVariant !== '' ? $options->schemaVariant : $this->detectSchema($source);
        $prefix = $options->tablePrefix . $options->fanUpdatePrefix;
        $rows   = [];

        if ($options->importUpdates) {
            $rows[] = $this->previewUpdates($source, $target, $prefix);
        }

        return new ImportPreview($rows, $schema, false);
    }

    // -----------------------------------------------------------------------
    // Run (writes to $target)
    // -----------------------------------------------------------------------

    public function run(PDO $source, PDO $target, ImportOptions $options): ImportResult
    {
        set_time_limit(0);
        ini_set('memory_limit', '256M');

        $start  = microtime(true);
        $prefix = $options->tablePrefix . $options->fanUpdatePrefix;
        $rows   = [];

        if ($options->importUpdates) {
            $rows[] = $this->runUpdates($source, $target, $prefix, $options);
        }

        return new ImportResult(
            rows:          $rows,
            imageRows:     [],
            duration:      microtime(true) - $start,
            schemaVariant: $options->schemaVariant !== '' ? $options->schemaVariant : $this->detectSchema($source),
        );
    }

    // -----------------------------------------------------------------------
    // Preview helpers
    // -----------------------------------------------------------------------

    /**
     * Checks whether $table exists on $pdo, using sqlite_master for SQLite
     * connections (used in tests) and SHOW TABLES LIKE for MySQL/MariaDB.
     */
    private function tableExists(PDO $pdo, string $table): bool
    {
        try {
            if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
                $stmt = $pdo->prepare("SELECT name FROM sqlite_master WHERE type='table' AND name = ?");
                $stmt->execute([$table]);
                return $stmt->fetchColumn() !== false;
            }
            $stmt = $pdo->query('SHOW TABLES LIKE ' . $pdo->quote($table));
            return $stmt && $stmt->fetchColumn() !== false;
        } catch (\Throwable) {
            return false;
        }
    }

    /** @return array{type:string,found:int,import:int,skip:int,warnings:string[]} */
    private function previewUpdates(PDO $source, PDO $target, string $prefix): array
    {
        $warns = [];

        if (!$this->tableExists($target, 'lstr_updates')) {
            $warns[] = 'Updates Feed add-on is not installed — lstr_updates table not found. Updates will be skipped.';
        }

        if (!$this->tableExists($source, $prefix)) {
            $warns[] = "Table `{$prefix}` not found — check the Blog Table Name setting.";
            return ['type' => 'Updates (blog entries)', 'found' => 0, 'import' => 0, 'skip' => 0, 'warnings' => $warns];
        }

        $total     = (int) $source->query("SELECT COUNT(*) FROM `{$prefix}`")->fetchColumn();
        $public    = (int) $source->query("SELECT COUNT(*) FROM `{$prefix}` WHERE is_public = 1")->fetchColumn();
        $nonPublic = $total - $public;

        if ($nonPublic > 0) {
            $warns[] = "{$nonPublic} non-public entr" . ($nonPublic === 1 ? 'y' : 'ies') . " will be skipped.";
        }

        return [
            'type'     => 'Updates (blog entries)',
            'found'    => $total,
            'import'   => $public,
            'skip'     => $nonPublic,
            'warnings' => $warns,
        ];
    }

    // -----------------------------------------------------------------------
    // Run helpers
    // -----------------------------------------------------------------------

    /** @return array{type:string,imported:int,skipped:int,warnings:string[],errors:int} */
    private function runUpdates(PDO $source, PDO $target, string $prefix, ImportOptions $options): array
    {
        $imported  = 0;
        $skipped   = 0;
        $errors    = 0;
        $warnings  = [];

        // Gate: lstr_updates must exist (requires Updates Feed add-on)
        $chk = $target->query("SHOW TABLES LIKE 'lstr_updates'");
        if (!$chk || $chk->fetchColumn() === false) {
            return [
                'type'     => 'Updates (blog entries)',
                'imported' => 0,
                'skipped'  => 0,
                'warnings' => ['Updates Feed add-on is not installed — lstr_updates table not found.'],
                'errors'   => 1,
            ];
        }

        // Gate: source table exists
        $chk = $source->query("SHOW TABLES LIKE '{$prefix}'");
        if (!$chk || $chk->fetchColumn() === false) {
            return [
                'type'     => 'Updates (blog entries)',
                'imported' => 0,
                'skipped'  => 0,
                'warnings' => ["Table `{$prefix}` not found — check the Blog Table Name setting."],
                'errors'   => 0,
            ];
        }

        // Build Luster listing map: lowercase(title) → listing_id
        $listingMap = $this->buildListingMap($target);

        // Load FanUpdate fanlisting registry: fl_id → fl_subject
        $csFlMap   = [];
        $hasCatTbl = false;
        $chk = $source->query("SHOW TABLES LIKE '{$prefix}_category'");
        if ($chk && $chk->fetchColumn() !== false) {
            $hasCatTbl = true;
            $stmt = $source->query("SELECT fl_id, fl_subject FROM `{$prefix}_category`");
            foreach ($stmt->fetchAll() as $row) {
                $csFlMap[(int) $row['fl_id']] = (string) $row['fl_subject'];
            }
        }

        // Check catjoin table
        $hasCatjoin = false;
        $chk = $source->query("SHOW TABLES LIKE '{$prefix}_catjoin'");
        if ($chk && $chk->fetchColumn() !== false) {
            $hasCatjoin = true;
        }

        // Prepared statements
        $insertStmt = $target->prepare(
            'INSERT INTO lstr_updates (fanlisting_id, title, body, category, author, posted_at, created_at)
             VALUES (:fid, :title, :body, NULL, NULL, :posted_at, NOW())'
        );

        $skipStmt = $target->prepare(
            'SELECT COUNT(*) FROM lstr_updates
             WHERE title = :title AND posted_at = :posted_at
             AND fanlisting_id <=> :fid'
        );

        $nonPublicCount = 0;
        $offset         = 0;

        while (true) {
            $stmt = $source->prepare(
                "SELECT entry_id, added, title, body, is_public
                 FROM `{$prefix}`
                 ORDER BY entry_id ASC
                 LIMIT :limit OFFSET :offset"
            );
            $stmt->bindValue(':limit',  self::CHUNK, PDO::PARAM_INT);
            $stmt->bindValue(':offset', $offset,     PDO::PARAM_INT);
            $stmt->execute();
            $chunk = $stmt->fetchAll();

            if (empty($chunk)) {
                break;
            }

            foreach ($chunk as $row) {
                if ((int) $row['is_public'] !== 1) {
                    $nonPublicCount++;
                    continue;
                }

                $title    = (string) $row['title'];
                $body     = (string) $row['body'];
                $postedAt = (string) $row['added'];

                $flIds = $this->resolveFlIds(
                    $source, $hasCatjoin, $prefix,
                    (int) $row['entry_id'], $csFlMap, $listingMap
                );

                foreach ($flIds as $flId) {
                    try {
                        if ($options->conflictStrategy === 'skip') {
                            $skipStmt->execute(['title' => $title, 'posted_at' => $postedAt, 'fid' => $flId]);
                            if ((int) $skipStmt->fetchColumn() > 0) {
                                $skipped++;
                                continue;
                            }
                        }

                        $insertStmt->execute([
                            'fid'       => $flId,
                            'title'     => $title,
                            'body'      => $body,
                            'posted_at' => $postedAt,
                        ]);
                        $imported++;
                    } catch (\Throwable) {
                        $errors++;
                    }
                }
            }

            $offset += self::CHUNK;
        }

        if ($nonPublicCount > 0) {
            $warnings[] = "{$nonPublicCount} non-public entr" . ($nonPublicCount === 1 ? 'y' : 'ies') . " skipped.";
        }

        return [
            'type'     => 'Updates (blog entries)',
            'imported' => $imported,
            'skipped'  => $skipped,
            'warnings' => $warnings,
            'errors'   => $errors,
        ];
    }

    // -----------------------------------------------------------------------
    // Utilities
    // -----------------------------------------------------------------------

    /**
     * Resolves an entry's target Luster fanlisting IDs by looking up catjoin rows.
     * Returns a list of int|null values — null means collective-level (no match or no catjoin).
     *
     * @param array<int,string> $csFlMap     fl_id → fl_subject from FanUpdate's category table
     * @param array<string,int> $listingMap  lowercase(title) → lstr listing id
     * @return list<int|null>
     */
    private function resolveFlIds(
        PDO    $source,
        bool   $hasCatjoin,
        string $prefix,
        int    $entryId,
        array  $csFlMap,
        array  $listingMap,
    ): array {
        if (!$hasCatjoin) {
            return [null];
        }

        try {
            $stmt = $source->prepare("SELECT cat_id FROM `{$prefix}_catjoin` WHERE entry_id = :id");
            $stmt->execute(['id' => $entryId]);
            $catIds = array_column($stmt->fetchAll(), 'cat_id');
        } catch (\Throwable) {
            return [null];
        }

        if (empty($catIds)) {
            return [null];
        }

        $seen = [];
        $out  = [];
        foreach ($catIds as $catId) {
            $subject = $csFlMap[(int) $catId] ?? null;
            $lstrId  = $subject !== null
                ? ($listingMap[strtolower(trim($subject))] ?? null)
                : null;

            $key = $lstrId === null ? '__null__' : (string) $lstrId;
            if (!isset($seen[$key])) {
                $seen[$key] = true;
                $out[]      = $lstrId;
            }
        }

        return $out ?: [null];
    }

    /**
     * Returns a lowercase-title → listing_id map from Luster's own DB.
     * Tries `owned` first (unprefixed Enthusiast schema), then `enth_fanlistings`.
     *
     * @return array<string, int>
     */
    private function buildListingMap(PDO $target): array
    {
        try {
            $chk = $target->query("SHOW TABLES LIKE 'owned'");
            if ($chk && $chk->fetchColumn() !== false) {
                $stmt = $target->query("SELECT listingid, title FROM `owned`");
                $map  = [];
                foreach ($stmt->fetchAll() as $row) {
                    $map[strtolower(trim((string) $row['title']))] = (int) $row['listingid'];
                }
                return $map;
            }
        } catch (\Throwable) {}

        try {
            $chk = $target->query("SHOW TABLES LIKE 'enth_fanlistings'");
            if ($chk && $chk->fetchColumn() !== false) {
                $stmt = $target->query("SELECT id, name FROM `enth_fanlistings`");
                $map  = [];
                foreach ($stmt->fetchAll() as $row) {
                    $map[strtolower(trim((string) $row['name']))] = (int) $row['id'];
                }
                return $map;
            }
        } catch (\Throwable) {}

        return [];
    }
}
