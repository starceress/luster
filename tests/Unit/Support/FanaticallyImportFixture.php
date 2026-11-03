<?php

declare(strict_types=1);

namespace Luster\Tests\Unit\Support;

use Luster\Core\Config;
use Luster\Core\Database;
use Luster\Import\FanaticallyImportSource;
use Luster\Import\ImportOptions;
use Luster\Import\ImportResult;
use PDO;

/**
 * Shared in-memory Fanatically source and Luster target databases for the
 * Fanatically import tests, plus helpers to run an import and read the target
 * back through Luster's own repositories.
 *
 * The target comes in both schemas: unprefixed ('owned' + per-listing member
 * tables) and standard ('enth_fanlistings' + {dbprefix}members), the registry
 * table the Fanatically importer writes for that schema.
 */
trait FanaticallyImportFixture
{
    private function makePdo(): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->sqliteCreateFunction('NOW', static fn(): string => date('Y-m-d H:i:s'));
        return $pdo;
    }

    /**
     * A Fanatically 1.2.3-shaped source with no table prefix.
     *
     * @param PDO|null $pdo The connection to build it in (a fresh one when null)
     */
    private function makeSource(?PDO $pdo = null): PDO
    {
        $pdo ??= $this->makePdo();
        $pdo->exec("CREATE TABLE listings (
            id INTEGER PRIMARY KEY AUTOINCREMENT, title TEXT NOT NULL DEFAULT '', subject TEXT NOT NULL DEFAULT '',
            url TEXT NOT NULL DEFAULT '', status TEXT NOT NULL DEFAULT 'current', approved_by TEXT NOT NULL DEFAULT '',
            last_updated TEXT NULL, for_adoption INTEGER NOT NULL DEFAULT 0, opened_date TEXT NULL,
            image_file TEXT NOT NULL DEFAULT '', description TEXT NOT NULL DEFAULT '', custom_fields TEXT NULL)");
        $pdo->exec("CREATE TABLE members (
            id INTEGER PRIMARY KEY AUTOINCREMENT, listing_id INTEGER NOT NULL, name TEXT NOT NULL DEFAULT '',
            email TEXT NOT NULL DEFAULT '', url TEXT NOT NULL DEFAULT '', country TEXT NOT NULL DEFAULT '',
            password_hash TEXT NOT NULL DEFAULT '', custom_field_values TEXT NULL,
            is_approved INTEGER NOT NULL DEFAULT 1, joined_at TEXT NULL, pending_update TEXT NULL)");
        $pdo->exec("CREATE TABLE settings (setting_key TEXT NOT NULL PRIMARY KEY, setting_value TEXT NOT NULL DEFAULT '')");
        $pdo->exec("CREATE TABLE codes (
            id INTEGER PRIMARY KEY AUTOINCREMENT, listing_id INTEGER NOT NULL DEFAULT 0, size_label TEXT NOT NULL DEFAULT '',
            file_name TEXT NOT NULL DEFAULT '', donor_name TEXT NOT NULL DEFAULT '', donor_url TEXT NOT NULL DEFAULT '',
            donor_email TEXT NOT NULL DEFAULT '', is_approved INTEGER NOT NULL DEFAULT 1)");
        $pdo->exec("CREATE TABLE kim (
            id INTEGER PRIMARY KEY AUTOINCREMENT, listing_id INTEGER NOT NULL DEFAULT 0, name TEXT NOT NULL DEFAULT '',
            email TEXT NOT NULL DEFAULT '', url TEXT NOT NULL DEFAULT '', notes TEXT NOT NULL DEFAULT '',
            is_approved INTEGER NOT NULL DEFAULT 1, added_at TEXT NULL)");
        $pdo->exec("CREATE TABLE affiliates (
            id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL DEFAULT '', email TEXT NOT NULL DEFAULT '',
            url TEXT NOT NULL DEFAULT '', image_file TEXT NOT NULL DEFAULT '', listing_ids TEXT NULL,
            is_approved INTEGER NOT NULL DEFAULT 1, added_at TEXT NULL, link_status TEXT NOT NULL DEFAULT 'unchecked',
            link_checked_at TEXT NULL, link_redirect_url TEXT NOT NULL DEFAULT '',
            reciprocal_status TEXT NOT NULL DEFAULT 'unchecked', reciprocal_checked_at TEXT NULL)");
        return $pdo;
    }

    /**
     * @param list<array<string,mixed>>|null $customFields Fanatically custom_fields JSON entries
     */
    private function addSourceListing(PDO $src, int $id, string $title, string $url, ?array $customFields = null, string $status = 'current'): void
    {
        $src->prepare(
            'INSERT INTO listings (id, title, subject, url, status, custom_fields) VALUES (:id, :t, :t, :u, :s, :cf)'
        )->execute([
            'id' => $id, 't' => $title, 'u' => $url, 's' => $status,
            'cf' => $customFields === null ? null : json_encode($customFields, JSON_THROW_ON_ERROR),
        ]);
    }

    /** @param array<string,string>|null $values Custom field values keyed by Fanatically field name */
    private function addSourceMember(PDO $src, int $listingId, string $email, bool $approved = true, ?array $values = null, ?string $pendingUpdate = null): void
    {
        $src->prepare(
            'INSERT INTO members (listing_id, name, email, country, custom_field_values, is_approved, joined_at, pending_update)
             VALUES (:lid, :n, :e, :c, :cfv, :a, :j, :pu)'
        )->execute([
            'lid' => $listingId, 'n' => ucfirst(strtok($email, '@')), 'e' => $email, 'c' => 'Canada',
            'cfv' => $values === null ? null : json_encode($values, JSON_THROW_ON_ERROR),
            'a' => $approved ? 1 : 0, 'j' => '2024-05-01', 'pu' => $pendingUpdate,
        ]);
    }

    /** Luster tables both target schemas share. */
    private function createLusterTables(PDO $pdo): void
    {
        $pdo->exec("CREATE TABLE lstr_fanlisting_settings (
            fanlisting_id INTEGER PRIMARY KEY, last_updated DATE NULL, approved_by TEXT NOT NULL DEFAULT '',
            queue_updates INTEGER NOT NULL DEFAULT 0, join_confirmation_email INTEGER NULL,
            description TEXT NULL, image TEXT NULL, for_adoption INTEGER NOT NULL DEFAULT 0)");
        $pdo->exec('CREATE TABLE lstr_listing_categories (fanlisting_id INTEGER NOT NULL, category_id INTEGER NOT NULL,
            PRIMARY KEY (fanlisting_id, category_id))');
        $pdo->exec("CREATE TABLE lstr_listing_external_refs (
            id INTEGER PRIMARY KEY AUTOINCREMENT, listing_id INTEGER NOT NULL, source TEXT NOT NULL,
            external_id TEXT NOT NULL, external_slug_or_url TEXT NOT NULL DEFAULT '', metadata_json TEXT NULL,
            UNIQUE (source, external_id))");
        // After Migration 108: archived_at can be NULL (date unknown).
        $pdo->exec('CREATE TABLE lstr_listing_lifecycle (
            fanlisting_id INTEGER NOT NULL PRIMARY KEY, lifecycle_status TEXT NOT NULL, archived_at TEXT NULL,
            legacy_upcoming INTEGER NOT NULL DEFAULT 0, legacy_trouble INTEGER NOT NULL DEFAULT 0,
            adopted_to_name TEXT NULL, adopted_to_url TEXT NULL, archive_note TEXT NULL)');
        $pdo->exec('CREATE TABLE lstr_pending_updates (
            id INTEGER PRIMARY KEY AUTOINCREMENT, fanlisting_id INTEGER NOT NULL, member_email TEXT NOT NULL,
            changes TEXT NOT NULL)');
        // Real collective-capable shape: nullable fanlisting_id, submitter_* credit.
        // name and import_source_file ignore case when compared, like production's
        // utf8mb4_unicode_ci (for ASCII letters; MySQL also folds accents).
        $pdo->exec("CREATE TABLE lstr_codes (
            id INTEGER PRIMARY KEY AUTOINCREMENT, fanlisting_id INTEGER NULL, filename TEXT NOT NULL,
            name TEXT NULL COLLATE NOCASE, width INTEGER NOT NULL DEFAULT 0, height INTEGER NOT NULL DEFAULT 0,
            size_id INTEGER NULL, donor_id INTEGER NULL, status TEXT NOT NULL DEFAULT 'approved',
            submitter_name TEXT NULL, submitter_email TEXT NULL, submitter_url TEXT NULL,
            sort_order INTEGER NOT NULL DEFAULT 0, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            import_ref TEXT NULL UNIQUE, import_source_file TEXT NULL COLLATE NOCASE)");
        $pdo->exec('CREATE TABLE lstr_code_sizes (id INTEGER PRIMARY KEY AUTOINCREMENT, fanlisting_id INTEGER NOT NULL,
            label TEXT NULL, width INTEGER NOT NULL DEFAULT 0, height INTEGER NOT NULL DEFAULT 0)');
        $pdo->exec("CREATE TABLE lstr_code_donors (id INTEGER PRIMARY KEY AUTOINCREMENT, fanlisting_id INTEGER NOT NULL,
            name TEXT NOT NULL DEFAULT '', url TEXT NULL, email TEXT NULL)");
        $pdo->exec("CREATE TABLE lstr_affiliates (
            id INTEGER PRIMARY KEY AUTOINCREMENT, fanlisting_id INTEGER NULL, name TEXT NOT NULL DEFAULT '',
            url TEXT NOT NULL DEFAULT '', email TEXT NOT NULL DEFAULT '', image TEXT NULL, pending INTEGER NOT NULL DEFAULT 0,
            added_at TEXT NULL, sort_order INTEGER NOT NULL DEFAULT 0, link_status TEXT NOT NULL DEFAULT 'unchecked',
            link_checked_at TEXT NULL, link_redirect_url TEXT NOT NULL DEFAULT '',
            reciprocal_status TEXT NOT NULL DEFAULT 'unchecked', reciprocal_checked_at TEXT NULL)");
        $pdo->exec("CREATE TABLE lstr_kim_members (
            id INTEGER PRIMARY KEY AUTOINCREMENT, fanlisting_id INTEGER NOT NULL, name TEXT NOT NULL, email TEXT NOT NULL,
            url TEXT NOT NULL DEFAULT '', password TEXT NOT NULL, pending INTEGER NOT NULL DEFAULT 1,
            granted INTEGER NOT NULL DEFAULT 0, added_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE (email, fanlisting_id))");
        $pdo->exec("CREATE TABLE lstr_addons (addon_key TEXT PRIMARY KEY, version TEXT NOT NULL DEFAULT '1.0.0', installed_at TEXT NULL)");
        $pdo->exec("INSERT INTO lstr_addons (addon_key) VALUES ('kim')");
    }

    /** An in-memory PDO that records statements and can be told to fail some (TracingPdo::$failOn). */
    private function makeTracingPdo(): TracingPdo
    {
        $pdo = TracingPdo::memory();
        $pdo->sqliteCreateFunction('NOW', static fn(): string => date('Y-m-d H:i:s'));
        return $pdo;
    }

    /** @param PDO|null $pdo The connection to build it in (a fresh one when null) */
    private function makeOwnedTarget(?PDO $pdo = null): PDO
    {
        $pdo ??= $this->makePdo();
        $pdo->exec("CREATE TABLE owned (
            listingid INTEGER PRIMARY KEY AUTOINCREMENT, dbtable TEXT NOT NULL DEFAULT '', title TEXT NOT NULL DEFAULT '',
            subject TEXT NOT NULL DEFAULT '', email TEXT NOT NULL DEFAULT '', url TEXT NOT NULL DEFAULT '',
            catid TEXT NOT NULL DEFAULT '' CHECK (length(catid) <= 255), category_id INTEGER NULL, opened TEXT NULL, status INTEGER NOT NULL DEFAULT 2,
            imagefile TEXT NOT NULL DEFAULT '', additional TEXT NOT NULL DEFAULT '')");
        $this->createLusterTables($pdo);
        return $pdo;
    }

    private function makeEnthTarget(): PDO
    {
        $pdo = $this->makePdo();
        $pdo->exec("CREATE TABLE enth_fanlistings (
            id INTEGER PRIMARY KEY AUTOINCREMENT, dbprefix TEXT NOT NULL DEFAULT '', name TEXT NOT NULL DEFAULT '',
            subject TEXT NOT NULL DEFAULT '', url TEXT NOT NULL DEFAULT '', email TEXT NOT NULL DEFAULT '',
            catid INTEGER NULL, additional_info TEXT NULL, upcoming INTEGER NOT NULL DEFAULT 0,
            trouble INTEGER NOT NULL DEFAULT 0, opened TEXT NULL)");
        $this->createLusterTables($pdo);
        return $pdo;
    }

    /** @param array<string,string> $overrides POST-style option values */
    private function options(array $overrides = []): ImportOptions
    {
        return ImportOptions::fromPost(array_merge([
            'source'            => 'fanatically',
            'conflict_strategy' => 'skip',
            'fn_table_prefix'   => '',
        ], $overrides));
    }

    private function runImport(PDO $src, PDO $tgt, ImportOptions $options): ImportResult
    {
        return (new FanaticallyImportSource())->run($src, $tgt, $options);
    }

    /** @return array<string,mixed> The result row for one import step */
    private function resultRow(ImportResult $result, string $type): array
    {
        foreach ($result->rows as $row) {
            if ($row['type'] === $type) {
                return $row;
            }
        }
        self::fail("No '{$type}' row in the import result");
    }

    /** @return list<string> Every warning on the preview */
    private function previewWarnings(PDO $src, PDO $tgt, ImportOptions $options): array
    {
        $warnings = [];
        foreach ((new FanaticallyImportSource())->preview($src, $tgt, $options)->rows as $row) {
            $warnings = array_merge($warnings, $row['warnings'] ?? []);
        }
        return $warnings;
    }

    /** @return array<string,mixed> The preview row for one step */
    private function previewRow(PDO $src, PDO $tgt, ImportOptions $options, string $type): array
    {
        foreach ((new FanaticallyImportSource())->preview($src, $tgt, $options)->rows as $row) {
            if ($row['type'] === $type) {
                return $row;
            }
        }
        self::fail("No '{$type}' row in the preview");
    }

    private function config(string $enthTablePrefix = ''): Config
    {
        $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'lstr_fan_fixture_' . uniqid('', true) . '.php';
        file_put_contents($path, '<?php return ' . var_export(['enth_table_prefix' => $enthTablePrefix], true) . ';');
        $config = new Config($path);
        unlink($path);
        return $config;
    }

    /** Wraps a target PDO in a Database so Luster's repositories can read it. */
    private function database(PDO $pdo): Database
    {
        $db   = new Database($this->config());
        $prop = new \ReflectionProperty(Database::class, 'pdo');
        $prop->setAccessible(true);
        $prop->setValue($db, $pdo);
        return $db;
    }

    /** @param list<string> $needles */
    private static function assertSomeWarningContains(array $warnings, string $needle, string $message = ''): void
    {
        foreach ($warnings as $warning) {
            if (str_contains($warning, $needle)) {
                self::assertTrue(true);
                return;
            }
        }
        self::fail(($message !== '' ? $message . "\n" : '') . "No warning contains '{$needle}'. Warnings:\n" . implode("\n", $warnings));
    }
}
