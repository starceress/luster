<?php

declare(strict_types=1);

namespace Luster\Tests\Unit\Support;

use Luster\Import\EnthusiastImportSource;
use Luster\Import\ImportOptions;
use Luster\Import\ImportPreview;
use Luster\Import\ImportResult;
use PDO;

/**
 * A real-shape Enthusiast source and a Luster target for the import tests, on SQLite.
 *
 * The source follows Enthusiast 3.1.5's own CREATE TABLEs: `owned` has the location
 * columns its Add Listing requires, and a member table's url and extra columns can be
 * NULL. The target's member tables follow what the importer creates.
 */
trait EnthusiastImportFixture
{
    protected function sourceDb(): TracingPdo
    {
        $pdo = TracingPdo::memory();
        $pdo->exec("CREATE TABLE category (catid INTEGER PRIMARY KEY, catname TEXT, parent INTEGER DEFAULT 0)");
        $pdo->exec(self::ownedSourceDdl());
        return $pdo;
    }

    private static function ownedSourceDdl(): string
    {
        return "CREATE TABLE owned (
            listingid INTEGER PRIMARY KEY, dbserver TEXT NOT NULL DEFAULT '', dbuser TEXT NOT NULL DEFAULT '',
            dbpassword TEXT NOT NULL DEFAULT '', dbdatabase TEXT NOT NULL DEFAULT '', dbtable TEXT NOT NULL DEFAULT '',
            subject TEXT NOT NULL DEFAULT '', email TEXT NOT NULL DEFAULT '', catid TEXT NOT NULL DEFAULT '',
            url TEXT NULL, title TEXT NOT NULL DEFAULT '', additional TEXT NOT NULL DEFAULT '',
            country INTEGER NOT NULL DEFAULT 1, perpage INTEGER DEFAULT 77, listtemplate TEXT NULL,
            status INTEGER NOT NULL DEFAULT 2, opened TEXT NULL, emailsignup TEXT NOT NULL DEFAULT '',
            emailapproved TEXT NOT NULL DEFAULT '', emailupdate TEXT NOT NULL DEFAULT '', emaillostpass TEXT NOT NULL DEFAULT ''
        )";
    }

    /**
     * @param array<string, mixed> $columns Other `owned` columns, such as additional or dbserver.
     * @param list<string>|null    $memberColumns Extra member columns, or null for no member table at all.
     */
    protected function addSourceListing(PDO $pdo, int $id, string $table, ?string $url, array $columns = [], ?array $memberColumns = []): void
    {
        $row  = array_merge(['listingid' => $id, 'dbtable' => $table, 'url' => $url, 'title' => "Listing {$table}", 'subject' => "Subject {$table}"], $columns);
        $list = implode(', ', array_map(static fn(string $c): string => "`{$c}`", array_keys($row)));
        $vals = implode(', ', array_map(static fn(string $c): string => ':' . $c, array_keys($row)));
        $pdo->prepare("INSERT INTO owned ({$list}) VALUES ({$vals})")->execute($row);
        if ($memberColumns !== null) {
            $this->createSourceMemberTable($pdo, $table, $memberColumns);
        }
    }

    /** @param list<string> $extra */
    protected function createSourceMemberTable(PDO $pdo, string $table, array $extra = []): void
    {
        $cols = '';
        foreach ($extra as $column) {
            $cols .= ", `{$column}` TEXT NULL";
        }
        $pdo->exec("CREATE TABLE `{$table}` (
            email TEXT NOT NULL PRIMARY KEY, name TEXT NOT NULL DEFAULT '', country TEXT NOT NULL DEFAULT '',
            url TEXT NULL, pending INTEGER NOT NULL DEFAULT 0, password TEXT NOT NULL DEFAULT '',
            showemail INTEGER NOT NULL DEFAULT 1, showurl INTEGER NOT NULL DEFAULT 1, added TEXT NULL{$cols}
        )");
    }

    protected function targetDb(): TracingPdo
    {
        $pdo = TracingPdo::memory();
        $this->addTargetTables($pdo);
        $pdo->exec("CREATE TABLE owned (
            listingid INTEGER PRIMARY KEY AUTOINCREMENT, dbtable TEXT NOT NULL DEFAULT '', title TEXT NOT NULL DEFAULT '',
            subject TEXT NOT NULL DEFAULT '', email TEXT NOT NULL DEFAULT '', url TEXT NOT NULL DEFAULT '',
            catid TEXT NOT NULL DEFAULT '', category_id INTEGER NULL, opened TEXT NULL, status INTEGER NOT NULL DEFAULT 2,
            imagefile TEXT NULL, additional TEXT NOT NULL DEFAULT '', dbserver TEXT NOT NULL DEFAULT '',
            dbuser TEXT NOT NULL DEFAULT '', dbpassword TEXT NOT NULL DEFAULT '', dbdatabase TEXT NOT NULL DEFAULT ''
        )");
        return $pdo;
    }

    /** One database playing both parts, as a Luster sharing Enthusiast's database does. */
    protected function sharedDb(): TracingPdo
    {
        $pdo = TracingPdo::memory();
        $this->addTargetTables($pdo);
        $pdo->exec("CREATE TABLE category (catid INTEGER PRIMARY KEY, catname TEXT, parent INTEGER DEFAULT 0)");
        $pdo->exec(self::ownedSourceDdl());
        return $pdo;
    }

    private function addTargetTables(PDO $pdo): void
    {
        $pdo->exec("CREATE TABLE lstr_categories (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, slug TEXT NOT NULL DEFAULT '', parent_id INTEGER NULL, source TEXT NOT NULL DEFAULT 'custom')");
        $pdo->exec("CREATE TABLE lstr_fanlisting_settings (
            fanlisting_id INTEGER PRIMARY KEY, members_per_page INTEGER NOT NULL DEFAULT 25, queue_updates INTEGER NOT NULL DEFAULT 0,
            members_template TEXT NULL, stats_template TEXT NULL, image TEXT NULL, listing_type TEXT NULL,
            form_url_join TEXT NOT NULL DEFAULT '', form_url_update TEXT NOT NULL DEFAULT '', form_url_reset TEXT NOT NULL DEFAULT '',
            description TEXT NULL, last_updated TEXT NULL)");
        $pdo->exec("CREATE TABLE lstr_listing_categories (fanlisting_id INTEGER NOT NULL, category_id INTEGER NOT NULL, PRIMARY KEY (fanlisting_id, category_id))");
        $pdo->exec("CREATE TABLE lstr_email_templates (id INTEGER PRIMARY KEY AUTOINCREMENT, fanlisting_id INTEGER NULL, type TEXT NOT NULL, enabled INTEGER NOT NULL DEFAULT 1, subject TEXT NOT NULL DEFAULT '', body TEXT NOT NULL DEFAULT '')");
        $pdo->exec("CREATE TABLE lstr_affiliates (id INTEGER PRIMARY KEY AUTOINCREMENT, fanlisting_id INTEGER NULL, name TEXT NOT NULL DEFAULT '', url TEXT NOT NULL DEFAULT '', email TEXT NOT NULL DEFAULT '', image TEXT NULL, sort_order INTEGER NOT NULL DEFAULT 0, active INTEGER NOT NULL DEFAULT 1)");
    }

    /**
     * @param array<string, mixed> $columns Other `owned` columns.
     * @param list<string>|null    $memberColumns Extra member columns, or null for no member table at all.
     */
    protected function addTargetListing(PDO $pdo, int $id, string $table, string $url, array $columns = [], ?array $memberColumns = []): void
    {
        $row  = array_merge(['listingid' => $id, 'dbtable' => $table, 'url' => $url, 'title' => "Target {$id}", 'subject' => 'Target'], $columns);
        $list = implode(', ', array_map(static fn(string $c): string => "`{$c}`", array_keys($row)));
        $vals = implode(', ', array_map(static fn(string $c): string => ':' . $c, array_keys($row)));
        $pdo->prepare("INSERT INTO owned ({$list}) VALUES ({$vals})")->execute($row);
        if ($memberColumns !== null) {
            $this->createTargetMemberTable($pdo, $table, $memberColumns);
        }
    }

    /** @param list<string> $extra */
    protected function createTargetMemberTable(PDO $pdo, string $table, array $extra = []): void
    {
        $cols = '';
        foreach ($extra as $column) {
            $cols .= ", `{$column}` TEXT NULL";
        }
        $pdo->exec("CREATE TABLE `{$table}` (
            email TEXT NOT NULL PRIMARY KEY, name TEXT NOT NULL DEFAULT '', country TEXT NOT NULL DEFAULT '',
            url TEXT NOT NULL DEFAULT '', pending INTEGER NOT NULL DEFAULT 0, password TEXT NOT NULL DEFAULT '',
            showemail INTEGER NOT NULL DEFAULT 1, showurl INTEGER NOT NULL DEFAULT 1, added TEXT NULL{$cols}
        )");
    }

    /** @param array<string, mixed> $row */
    protected function addFan(PDO $pdo, string $table, array $row): void
    {
        $list = implode(', ', array_map(static fn(string $c): string => "`{$c}`", array_keys($row)));
        $vals = implode(', ', array_map(static fn(string $c): string => ':' . $c, array_keys($row)));
        $pdo->prepare("INSERT INTO `{$table}` ({$list}) VALUES ({$vals})")->execute($row);
    }

    /** @param array<string, mixed> $post */
    protected function importOptions(array $post = []): ImportOptions
    {
        return ImportOptions::fromPost(array_merge(['source' => 'enthusiast', 'conflict_strategy' => 'skip'], $post));
    }

    /** @param array<string, mixed> $post */
    protected function runImport(PDO $source, PDO $target, array $post = []): ImportResult
    {
        return (new EnthusiastImportSource())->run($source, $target, $this->importOptions($post));
    }

    /** @param array<string, mixed> $post */
    protected function previewImport(PDO $source, PDO $target, array $post = []): ImportPreview
    {
        return (new EnthusiastImportSource())->preview($source, $target, $this->importOptions($post));
    }

    /** @return array<string, mixed>|null */
    protected function row(ImportResult|ImportPreview $result, string $type): ?array
    {
        foreach ($result->rows as $row) {
            if ($row['type'] === $type) {
                return $row;
            }
        }
        return null;
    }

    /** Every note and error in a result or preview, as one string. */
    protected function allText(ImportResult|ImportPreview $result): string
    {
        $text = [];
        foreach ($result->rows as $row) {
            foreach (array_merge($row['warnings'] ?? [], $row['errors'] ?? []) as $line) {
                $text[] = (string) $line;
            }
        }
        return implode("\n", $text);
    }

    protected function tableExists(PDO $pdo, string $table): bool
    {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = :t COLLATE NOCASE");
        $stmt->execute(['t' => $table]);
        return (int) $stmt->fetchColumn() > 0;
    }

    protected function rowCount(PDO $pdo, string $table): int
    {
        return (int) $pdo->query("SELECT COUNT(*) FROM `{$table}`")->fetchColumn();
    }

    /** @return array<string, mixed>|false */
    protected function ownedRow(PDO $pdo, int $id): array|false
    {
        $stmt = $pdo->prepare('SELECT * FROM owned WHERE listingid = :i');
        $stmt->execute(['i' => $id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    /** Makes a SQLite database answer SELECT DATABASE() the way a real server would. */
    protected function connectedAs(PDO $pdo, string $database): void
    {
        $pdo->sqliteCreateFunction('DATABASE', static fn(): string => $database, 0);
    }
}
