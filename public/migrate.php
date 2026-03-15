<?php

declare(strict_types=1);

/**
 * Luster Database Migration Runner
 *
 * Run via the browser AFTER logging into the admin panel.
 * URL: http://your-site/luster/public/migrate.php
 *
 * Applies all pending migrations in sequence. Safe to re-run — each migration
 * is tracked in lstr_migrations and skipped if already applied.
 */

// Session guard — must be logged in as admin
ini_set('session.cookie_httponly', '1');
ini_set('session.use_strict_mode', '1');
ini_set('session.cookie_samesite', 'Strict');
session_name('luster_session');
session_start();

if (empty($_SESSION['lstr_admin_id'])) {
    http_response_code(403);
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><title>Access Denied</title></head><body>'
        . '<h1>Access Denied</h1><p>Please <a href="admin.php">log in to the admin panel</a> first.</p>'
        . '</body></html>';
    exit;
}

// ─────────────────────────────────────────────────────────────────────────────
// CSRF guard — GET shows confirmation form; POST requires a valid token.
// ─────────────────────────────────────────────────────────────────────────────

// Generate a per-session token if one doesn't exist yet.
if (empty($_SESSION['lstr_migrate_token'])) {
    $_SESSION['lstr_migrate_token'] = bin2hex(random_bytes(32));
}

$migrateToken = (string) $_SESSION['lstr_migrate_token'];

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    // Show confirmation page — nothing runs on GET.
    $style = 'body{font-family:system-ui,sans-serif;max-width:540px;margin:2.5rem auto;padding:1rem;color:#111}'
           . 'h1{font-size:1.4rem;margin-bottom:.75rem}'
           . 'p{margin:.5rem 0 1rem}'
           . '.warn{background:#fffbeb;border:1px solid #fcd34d;border-radius:5px;padding:.7rem 1rem;margin-bottom:1.25rem;color:#92400e}'
           . 'button{background:#4f46e5;color:#fff;border:none;padding:.55rem 1.25rem;border-radius:5px;cursor:pointer;font-size:1rem}'
           . 'button:hover{background:#4338ca}'
           . 'a{color:#4f46e5}';
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><title>Run Migrations</title>'
        . '<style>' . $style . '</style></head><body>'
        . '<h1>Luster — Run Migrations</h1>'
        . '<div class="warn">This will apply all pending database migrations. '
        . 'Back up your database before continuing.</div>'
        . '<form method="POST" action="">'
        . '<input type="hidden" name="_csrf" value="' . htmlspecialchars($migrateToken, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '">'
        . '<button type="submit">Run Migrations</button>'
        . '</form>'
        . '<p style="margin-top:1.25rem"><a href="admin.php">Cancel — return to admin panel</a></p>'
        . '</body></html>';
    exit;
}

// POST — validate CSRF token before running anything.
$submittedToken = (string) ($_POST['_csrf'] ?? '');
if (!hash_equals($migrateToken, $submittedToken)) {
    http_response_code(403);
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><title>Invalid Token</title></head><body>'
        . '<h1>Invalid or Expired Token</h1>'
        . '<p><a href="migrate.php">Go back and try again.</a></p>'
        . '</body></html>';
    exit;
}

// Consume the token — one-time use.
unset($_SESSION['lstr_migrate_token']);

$configPath = dirname(__DIR__) . '/config/app.php';

if (!is_file($configPath)) {
    http_response_code(500);
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><title>Error</title></head><body>'
        . '<h1>Configuration Missing</h1><p>config/app.php not found. Run the installer first.</p>'
        . '</body></html>';
    exit;
}

$config = require $configPath;

$dsn = sprintf(
    'mysql:host=%s;port=%d;dbname=%s;charset=%s',
    $config['db_host']    ?? 'localhost',
    (int) ($config['db_port'] ?? 3306),
    $config['db_name'],
    $config['db_charset'] ?? 'utf8mb4',
);

try {
    $pdo = new PDO($dsn, (string) ($config['db_user'] ?? ''), (string) ($config['db_pass'] ?? ''), [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><title>DB Error</title></head><body>'
        . '<h1>Database Connection Failed</h1>'
        . '<p>' . htmlspecialchars($e->getMessage(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>'
        . '</body></html>';
    exit;
}

$done   = [];
$errors = [];

// ─────────────────────────────────────────────────────────────────────────────
// Bootstrap: create lstr_migrations tracking table
// ─────────────────────────────────────────────────────────────────────────────
try {
    // Check existence before CREATE so we only report it when actually new.
    $trackingExists = (int) $pdo->query(
        "SELECT COUNT(*) FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'lstr_migrations'"
    )->fetchColumn() > 0;

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS `lstr_migrations` (
            `migration_number` INT UNSIGNED NOT NULL,
            `run_at`           DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`migration_number`)
         ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    if (!$trackingExists) {
        $done[] = 'Created <code>lstr_migrations</code> tracking table';
    }

    // Existing installs that ran migrations before tracking was added:
    // Detect how far along the schema is by checking sentinel tables, then
    // silently mark those migrations as done without adding them to $done[].
    $tracked = (int) $pdo->query('SELECT COUNT(*) FROM `lstr_migrations`')->fetchColumn();

    if ($tracked === 0) {
        /** @param non-empty-string $table */
        $tableExists = static function (string $table) use ($pdo): bool {
            return (int) $pdo->query(
                "SELECT COUNT(*) FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = " . $pdo->quote($table)
            )->fetchColumn() > 0;
        };

        $toMark = [];

        if ($tableExists('lstr_categories')) {
            // Migrations 1–9 were all applied
            array_push($toMark, 1, 2, 3, 4, 5, 6, 7, 8, 9);
        }
        if ($tableExists('lstr_error_log')) {
            // Migrations 10–12 were applied
            array_push($toMark, 10, 11, 12);
        }
        if ($tableExists('lstr_fan_password_resets')) {
            // Migrations 13–14 were applied
            array_push($toMark, 13, 14);
        }
        if ($tableExists('lstr_pending_updates')) {
            // Migrations 15–21 were applied
            array_push($toMark, 15, 16, 17, 18, 19, 20, 21);
        }
        if ($tableExists('lstr_codes')) {
            // Migration 22 was applied
            $toMark[] = 22;
        }
        if ($tableExists('lstr_email_templates')) {
            // Migration 23 was applied
            $toMark[] = 23;
        }
        if ($tableExists('lstr_addons')) {
            // Migration 27 was applied
            $toMark[] = 27;
        }

        if (!empty($toMark)) {
            $vals = implode(',', array_map(static fn(int $n): string => "($n)", $toMark));
            $pdo->exec("INSERT IGNORE INTO `lstr_migrations` (migration_number) VALUES $vals");
        }
    }
} catch (PDOException $e) {
    $errors[] = 'lstr_migrations bootstrap — ' . htmlspecialchars($e->getMessage(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// ─────────────────────────────────────────────────────────────────────────────
// Migration 1: active column on lstr_affiliates
// ─────────────────────────────────────────────────────────────────────────────
try {
    if (!(int) $pdo->query('SELECT COUNT(*) FROM `lstr_migrations` WHERE migration_number = 1')->fetchColumn()) {
        $pdo->exec(
            'ALTER TABLE `lstr_affiliates`
             ADD COLUMN IF NOT EXISTS `active` TINYINT(1) NOT NULL DEFAULT 1
             AFTER `sort_order`'
        );
        $pdo->exec('INSERT INTO `lstr_migrations` (migration_number) VALUES (1)');
        $done[] = 'Added <code>active</code> column to <code>lstr_affiliates</code>';
    }
} catch (PDOException $e) {
    $errors[] = 'lstr_affiliates.active — ' . htmlspecialchars($e->getMessage(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// ─────────────────────────────────────────────────────────────────────────────
// Migration 2: lstr_fanlisting_settings (auto-approve, etc.)
// ─────────────────────────────────────────────────────────────────────────────
try {
    if (!(int) $pdo->query('SELECT COUNT(*) FROM `lstr_migrations` WHERE migration_number = 2')->fetchColumn()) {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS `lstr_fanlisting_settings` (
                `fanlisting_id` INT UNSIGNED NOT NULL,
                `auto_approve`  TINYINT(1)   NOT NULL DEFAULT 0,
                `updated_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`fanlisting_id`)
             ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        $pdo->exec('INSERT INTO `lstr_migrations` (migration_number) VALUES (2)');
        $done[] = 'Created <code>lstr_fanlisting_settings</code> table';
    }
} catch (PDOException $e) {
    $errors[] = 'lstr_fanlisting_settings — ' . htmlspecialchars($e->getMessage(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// ─────────────────────────────────────────────────────────────────────────────
// Migration 3: make lstr_affiliates.fanlisting_id nullable
//              NULL  = collective / site-wide affiliate
//              INT   = belongs to a specific fanlisting
// ─────────────────────────────────────────────────────────────────────────────
try {
    if (!(int) $pdo->query('SELECT COUNT(*) FROM `lstr_migrations` WHERE migration_number = 3')->fetchColumn()) {
        $pdo->exec(
            'ALTER TABLE `lstr_affiliates`
             MODIFY COLUMN `fanlisting_id` INT UNSIGNED NULL DEFAULT NULL'
        );
        $pdo->exec('INSERT INTO `lstr_migrations` (migration_number) VALUES (3)');
        $done[] = 'Made <code>lstr_affiliates.fanlisting_id</code> nullable (NULL = collective affiliate)';
    }
} catch (PDOException $e) {
    $errors[] = 'lstr_affiliates.fanlisting_id nullable — ' . htmlspecialchars($e->getMessage(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// ─────────────────────────────────────────────────────────────────────────────
// Migration 4: lstr_joined (joined listings tracker)
// ─────────────────────────────────────────────────────────────────────────────
try {
    if (!(int) $pdo->query('SELECT COUNT(*) FROM `lstr_migrations` WHERE migration_number = 4')->fetchColumn()) {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS `lstr_joined` (
                `id`          INT UNSIGNED  NOT NULL AUTO_INCREMENT,
                `name`        VARCHAR(255)  NOT NULL,
                `url`         VARCHAR(500)  NOT NULL,
                `image`       VARCHAR(255)  NULL,
                `category`    VARCHAR(100)  NOT NULL DEFAULT '',
                `date_joined` DATE          NULL,
                `active`      TINYINT(1)    NOT NULL DEFAULT 1,
                `sort_order`  INT           NOT NULL DEFAULT 0,
                `created_at`  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`)
             ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        $pdo->exec('INSERT INTO `lstr_migrations` (migration_number) VALUES (4)');
        $done[] = 'Created <code>lstr_joined</code> table';
    }
} catch (PDOException $e) {
    $errors[] = 'lstr_joined — ' . htmlspecialchars($e->getMessage(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// ─────────────────────────────────────────────────────────────────────────────
// Migration 5: stats and members template columns on lstr_fanlisting_settings
// ─────────────────────────────────────────────────────────────────────────────
try {
    if (!(int) $pdo->query('SELECT COUNT(*) FROM `lstr_migrations` WHERE migration_number = 5')->fetchColumn()) {
        $pdo->exec(
            'ALTER TABLE `lstr_fanlisting_settings`
             ADD COLUMN IF NOT EXISTS `stats_template`   TEXT NULL DEFAULT NULL,
             ADD COLUMN IF NOT EXISTS `members_template` TEXT NULL DEFAULT NULL'
        );
        $pdo->exec('INSERT INTO `lstr_migrations` (migration_number) VALUES (5)');
        $done[] = 'Added <code>stats_template</code> and <code>members_template</code> columns to <code>lstr_fanlisting_settings</code>';
    }
} catch (PDOException $e) {
    $errors[] = 'lstr_fanlisting_settings templates — ' . htmlspecialchars($e->getMessage(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// ─────────────────────────────────────────────────────────────────────────────
// Migration 6: affiliates template column on lstr_fanlisting_settings
// ─────────────────────────────────────────────────────────────────────────────
try {
    if (!(int) $pdo->query('SELECT COUNT(*) FROM `lstr_migrations` WHERE migration_number = 6')->fetchColumn()) {
        $pdo->exec(
            'ALTER TABLE `lstr_fanlisting_settings`
             ADD COLUMN IF NOT EXISTS `affiliates_template` TEXT NULL DEFAULT NULL'
        );
        $pdo->exec('INSERT INTO `lstr_migrations` (migration_number) VALUES (6)');
        $done[] = 'Added <code>affiliates_template</code> column to <code>lstr_fanlisting_settings</code>';
    }
} catch (PDOException $e) {
    $errors[] = 'lstr_fanlisting_settings.affiliates_template — ' . htmlspecialchars($e->getMessage(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// ─────────────────────────────────────────────────────────────────────────────
// Migration 7: lstr_categories table + category_id on lstr_joined + TFL seed
// ─────────────────────────────────────────────────────────────────────────────
try {
    if (!(int) $pdo->query('SELECT COUNT(*) FROM `lstr_migrations` WHERE migration_number = 7')->fetchColumn()) {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS `lstr_categories` (
                `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `name`       VARCHAR(150) NOT NULL,
                `parent_id`  INT UNSIGNED NULL,
                `source`     ENUM('tfl','tafl','custom') NOT NULL DEFAULT 'custom',
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`)
             ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        $pdo->exec(
            'ALTER TABLE `lstr_joined`
             ADD COLUMN IF NOT EXISTS `category_id` INT UNSIGNED NULL'
        );
        $tflCategories = [
            'Academia','Actors','Actresses','Adult','Advertising/TV Channels','Albums',
            'Animals','Animation','Anime/Manga','Arts and Design','Authors/Writers',
            'Calendar Events','Characters: Book/Movie','Characters: TV','Comics',
            'Companies','Computer Miscellany and Internet','Directors/Producers',
            'Episodes','Fan Works','Fashion/Beauty','Food/Drinks','Games',
            'History/Royalty','Hobbies and Recreation','Literature','Miscellaneous',
            'Models','Movies','Music Miscellany','Musicians: Bands/Groups',
            'Musicians: Female','Musicians: Male','Mythology/Religion','Nature',
            'Objects','People Miscellany','Personalities','Places',
            'Politics and Organisations','Relationships: Book/Movie',
            'Relationships: Real Life','Relationships: TV',
            'Songs: Bands/Groups 0-M','Songs: Bands/Groups N-Z',
            'Songs: Female Solo','Songs: Male Solo','Songs: Various','Sports',
            'Sports Entertainment','Stage/Theatre','Toys/Collectibles',
            'Transportation','TV Shows','TV/Movie/Book Miscellany','Webmasters','Websites',
        ];
        $pdo->exec("DELETE FROM `lstr_categories` WHERE source = 'tfl'");
        $stmt = $pdo->prepare("INSERT IGNORE INTO `lstr_categories` (name, source, parent_id) VALUES (:name, 'tfl', NULL)");
        foreach ($tflCategories as $catName) {
            $stmt->execute(['name' => $catName]);
        }
        $pdo->exec('INSERT INTO `lstr_migrations` (migration_number) VALUES (7)');
        $done[] = 'Created <code>lstr_categories</code> table, added <code>category_id</code> to <code>lstr_joined</code>, seeded ' . count($tflCategories) . ' TFL categories';
    }
} catch (PDOException $e) {
    $errors[] = 'migration 7 — ' . htmlspecialchars($e->getMessage(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// ─────────────────────────────────────────────────────────────────────────────
// Migration 8: category_id on owned / enth_fanlistings
//              Adds Luster's own category assignment (distinct from Enthusiast's
//              native catid column). References lstr_categories.
// ─────────────────────────────────────────────────────────────────────────────
try {
    if (!(int) $pdo->query('SELECT COUNT(*) FROM `lstr_migrations` WHERE migration_number = 8')->fetchColumn()) {
        // Unprefixed Enthusiast 3 schema
        $pdo->exec('ALTER TABLE `owned` ADD COLUMN IF NOT EXISTS `category_id` INT UNSIGNED NULL');
        if (!empty($config['enth_table_prefix'])) {
            // Standard prefixed Enthusiast schema only
            $pdo->exec('ALTER TABLE `enth_fanlistings` ADD COLUMN IF NOT EXISTS `category_id` INT UNSIGNED NULL');
        }
        $pdo->exec('INSERT INTO `lstr_migrations` (migration_number) VALUES (8)');
        $done[] = 'Added <code>category_id</code> column to listing registry table';
    }
} catch (PDOException $e) {
    $errors[] = 'migration 8 — ' . htmlspecialchars($e->getMessage(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// ─────────────────────────────────────────────────────────────────────────────
// Migration 9: TAFL category seed into lstr_categories
//              Flatten TAFL categories to Anime/Manga: Name format.
// ─────────────────────────────────────────────────────────────────────────────
try {
    if (!(int) $pdo->query('SELECT COUNT(*) FROM `lstr_migrations` WHERE migration_number = 9')->fetchColumn()) {
        $taflCategories = [
            'Anime/Manga: Adult','Anime/Manga: Characters 0-M','Anime/Manga: Characters N-Z',
            'Anime/Manga: Episodes','Anime/Manga: Fanstuff','Anime/Manga: General',
            'Anime/Manga: Items/Locations','Anime/Manga: Manga-ka & Directors',
            'Anime/Manga: Movies/OVAs','Anime/Manga: Music & Songs',
            'Anime/Manga: Relationships','Anime/Manga: Rivalries',
            'Anime/Manga: Seiyuu','Anime/Manga: Series',
        ];
        $pdo->exec("DELETE FROM `lstr_categories` WHERE source = 'tafl'");
        $stmt = $pdo->prepare("INSERT IGNORE INTO `lstr_categories` (name, parent_id, source) VALUES (:name, NULL, 'tafl')");
        foreach ($taflCategories as $catName) {
            $stmt->execute(['name' => $catName]);
        }
        $pdo->exec('INSERT INTO `lstr_migrations` (migration_number) VALUES (9)');
        $done[] = 'Seeded ' . count($taflCategories) . ' TAFL categories into <code>lstr_categories</code>';
    }
} catch (PDOException $e) {
    $errors[] = 'migration 9 — ' . htmlspecialchars($e->getMessage(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// ─────────────────────────────────────────────────────────────────────────────
// Migration 10: Remove duplicate and bare Anime/Manga categories
// ─────────────────────────────────────────────────────────────────────────────
try {
    if (!(int) $pdo->query('SELECT COUNT(*) FROM `lstr_migrations` WHERE migration_number = 10')->fetchColumn()) {
        $pdo->exec("DELETE FROM `lstr_categories` WHERE name = 'Anime/Manga'");
        $pdo->exec("DELETE FROM `lstr_categories` WHERE source = 'tafl' AND name NOT LIKE 'Anime/Manga:%'");
        $pdo->exec(
            "DELETE c1 FROM `lstr_categories` c1
             INNER JOIN `lstr_categories` c2
             WHERE c1.id > c2.id AND c1.name = c2.name AND c1.source = 'tafl'"
        );
        $pdo->exec('INSERT INTO `lstr_migrations` (migration_number) VALUES (10)');
        $done[] = 'Cleaned up duplicate and bare Anime/Manga categories from <code>lstr_categories</code>';
    }
} catch (PDOException $e) {
    $errors[] = 'migration 10 — ' . htmlspecialchars($e->getMessage(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// ─────────────────────────────────────────────────────────────────────────────
// Migration 11: image column on lstr_fanlisting_settings
// ─────────────────────────────────────────────────────────────────────────────
try {
    if (!(int) $pdo->query('SELECT COUNT(*) FROM `lstr_migrations` WHERE migration_number = 11')->fetchColumn()) {
        $pdo->exec(
            'ALTER TABLE `lstr_fanlisting_settings`
             ADD COLUMN IF NOT EXISTS `image` VARCHAR(255) NULL DEFAULT NULL
             AFTER `affiliates_template`'
        );
        $pdo->exec('INSERT INTO `lstr_migrations` (migration_number) VALUES (11)');
        $done[] = 'Added <code>image</code> column to <code>lstr_fanlisting_settings</code>';
    }
} catch (PDOException $e) {
    $errors[] = 'lstr_fanlisting_settings.image — ' . htmlspecialchars($e->getMessage(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// ─────────────────────────────────────────────────────────────────────────────
// Migration 12: listing_type column on lstr_fanlisting_settings
// ─────────────────────────────────────────────────────────────────────────────
try {
    if (!(int) $pdo->query('SELECT COUNT(*) FROM `lstr_migrations` WHERE migration_number = 12')->fetchColumn()) {
        $pdo->exec(
            'ALTER TABLE `lstr_fanlisting_settings`
             ADD COLUMN IF NOT EXISTS `listing_type` VARCHAR(50) NULL DEFAULT NULL
             AFTER `image`'
        );
        $pdo->exec('INSERT INTO `lstr_migrations` (migration_number) VALUES (12)');
        $done[] = 'Added <code>listing_type</code> column to <code>lstr_fanlisting_settings</code>';
    }
} catch (PDOException $e) {
    $errors[] = 'lstr_fanlisting_settings.listing_type — ' . htmlspecialchars($e->getMessage(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// ─────────────────────────────────────────────────────────────────────────────
// Migration 13: members_per_page column on lstr_fanlisting_settings
// ─────────────────────────────────────────────────────────────────────────────
try {
    if (!(int) $pdo->query('SELECT COUNT(*) FROM `lstr_migrations` WHERE migration_number = 13')->fetchColumn()) {
        $pdo->exec(
            'ALTER TABLE `lstr_fanlisting_settings`
             ADD COLUMN IF NOT EXISTS `members_per_page` TINYINT UNSIGNED NULL DEFAULT NULL
             AFTER `listing_type`'
        );
        $pdo->exec('INSERT INTO `lstr_migrations` (migration_number) VALUES (13)');
        $done[] = 'Added <code>members_per_page</code> column to <code>lstr_fanlisting_settings</code>';
    }
} catch (PDOException $e) {
    $errors[] = 'lstr_fanlisting_settings.members_per_page — ' . htmlspecialchars($e->getMessage(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// ─────────────────────────────────────────────────────────────────────────────
// Migration 14: lstr_error_log table
// ─────────────────────────────────────────────────────────────────────────────
try {
    if (!(int) $pdo->query('SELECT COUNT(*) FROM `lstr_migrations` WHERE migration_number = 14')->fetchColumn()) {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS `lstr_error_log` (
                `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `level`      VARCHAR(20)     NOT NULL DEFAULT 'error',
                `message`    TEXT            NOT NULL,
                `source`     VARCHAR(255)    NULL,
                `created_at` DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                INDEX `idx_created` (`created_at`)
             ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        $pdo->exec('INSERT INTO `lstr_migrations` (migration_number) VALUES (14)');
        $done[] = 'Created <code>lstr_error_log</code> table';
    }
} catch (PDOException $e) {
    $errors[] = 'lstr_error_log — ' . htmlspecialchars($e->getMessage(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// ─────────────────────────────────────────────────────────────────────────────
// Migration 15: status and categories_json columns on lstr_joined
// ─────────────────────────────────────────────────────────────────────────────
try {
    if (!(int) $pdo->query('SELECT COUNT(*) FROM `lstr_migrations` WHERE migration_number = 15')->fetchColumn()) {
        $pdo->exec(
            "ALTER TABLE `lstr_joined`
             ADD COLUMN IF NOT EXISTS `status`          VARCHAR(20) NOT NULL DEFAULT 'joined' AFTER `active`,
             ADD COLUMN IF NOT EXISTS `categories_json` TEXT        NULL                      AFTER `category`"
        );
        $pdo->exec("UPDATE `lstr_joined` SET `status` = IF(`active` = 1, 'joined', 'pending')");
        $pdo->exec('INSERT INTO `lstr_migrations` (migration_number) VALUES (15)');
        $done[] = 'Added <code>status</code> and <code>categories_json</code> columns to <code>lstr_joined</code>';
    }
} catch (PDOException $e) {
    $errors[] = 'migration 15 — ' . htmlspecialchars($e->getMessage(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// ─────────────────────────────────────────────────────────────────────────────
// Migration 16: widen lstr_categories.source from ENUM to VARCHAR(50)
// ─────────────────────────────────────────────────────────────────────────────
try {
    if (!(int) $pdo->query('SELECT COUNT(*) FROM `lstr_migrations` WHERE migration_number = 16')->fetchColumn()) {
        $pdo->exec("ALTER TABLE lstr_categories MODIFY COLUMN source VARCHAR(50) NOT NULL DEFAULT 'custom'");
        $pdo->exec('INSERT INTO `lstr_migrations` (migration_number) VALUES (16)');
        $done[] = 'Changed <code>lstr_categories.source</code> from ENUM to VARCHAR(50)';
    }
} catch (PDOException $e) {
    $errors[] = 'lstr_categories.source VARCHAR — ' . htmlspecialchars($e->getMessage(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// ─────────────────────────────────────────────────────────────────────────────
// Migration 17: per-fanlisting embed settings columns on lstr_fanlisting_settings
// ─────────────────────────────────────────────────────────────────────────────
try {
    if (!(int) $pdo->query('SELECT COUNT(*) FROM `lstr_migrations` WHERE migration_number = 17')->fetchColumn()) {
        $pdo->exec(
            "ALTER TABLE `lstr_fanlisting_settings`
             ADD COLUMN IF NOT EXISTS `country_field_enabled` TINYINT(1)   NOT NULL DEFAULT 1   AFTER `members_per_page`,
             ADD COLUMN IF NOT EXISTS `redirect_url_join`     VARCHAR(500) NOT NULL DEFAULT ''   AFTER `country_field_enabled`,
             ADD COLUMN IF NOT EXISTS `redirect_url_update`   VARCHAR(500) NOT NULL DEFAULT ''   AFTER `redirect_url_join`,
             ADD COLUMN IF NOT EXISTS `redirect_url_remove`   VARCHAR(500) NOT NULL DEFAULT ''   AFTER `redirect_url_update`,
             ADD COLUMN IF NOT EXISTS `db_host`               VARCHAR(255) NOT NULL DEFAULT ''   AFTER `redirect_url_remove`,
             ADD COLUMN IF NOT EXISTS `db_name`               VARCHAR(255) NOT NULL DEFAULT ''   AFTER `db_host`,
             ADD COLUMN IF NOT EXISTS `db_user`               VARCHAR(255) NOT NULL DEFAULT ''   AFTER `db_name`,
             ADD COLUMN IF NOT EXISTS `db_pass`               VARCHAR(500) NOT NULL DEFAULT ''   AFTER `db_user`"
        );
        $pdo->exec('INSERT INTO `lstr_migrations` (migration_number) VALUES (17)');
        $done[] = 'Added embed settings columns to <code>lstr_fanlisting_settings</code>';
    }
} catch (PDOException $e) {
    $errors[] = 'lstr_fanlisting_settings embed columns — ' . htmlspecialchars($e->getMessage(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// ─────────────────────────────────────────────────────────────────────────────
// Migration 18: fan password reset tokens table
// ─────────────────────────────────────────────────────────────────────────────
try {
    if (!(int) $pdo->query('SELECT COUNT(*) FROM `lstr_migrations` WHERE migration_number = 18')->fetchColumn()) {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS `lstr_fan_password_resets` (
               `id`            INT UNSIGNED  NOT NULL AUTO_INCREMENT,
               `fanlisting_id` INT UNSIGNED  NOT NULL,
               `email`         VARCHAR(255)  NOT NULL,
               `token_hash`    VARCHAR(64)   NOT NULL,
               `expires_at`    DATETIME      NOT NULL,
               `created_at`    DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
               PRIMARY KEY (`id`),
               INDEX `idx_token`    (`token_hash`),
               INDEX `idx_email_fl` (`fanlisting_id`, `email`)
             ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        $pdo->exec('INSERT INTO `lstr_migrations` (migration_number) VALUES (18)');
        $done[] = 'Created <code>lstr_fan_password_resets</code> table';
    }
} catch (PDOException $e) {
    $errors[] = 'lstr_fan_password_resets — ' . htmlspecialchars($e->getMessage(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// ─────────────────────────────────────────────────────────────────────────────
// Migration 19: per-fanlisting form page URLs + reset redirect URL
// ─────────────────────────────────────────────────────────────────────────────
try {
    if (!(int) $pdo->query('SELECT COUNT(*) FROM `lstr_migrations` WHERE migration_number = 19')->fetchColumn()) {
        $pdo->exec(
            "ALTER TABLE `lstr_fanlisting_settings`
             ADD COLUMN IF NOT EXISTS `form_url_join`      VARCHAR(500) NOT NULL DEFAULT '' AFTER `redirect_url_remove`,
             ADD COLUMN IF NOT EXISTS `form_url_update`    VARCHAR(500) NOT NULL DEFAULT '' AFTER `form_url_join`,
             ADD COLUMN IF NOT EXISTS `form_url_remove`    VARCHAR(500) NOT NULL DEFAULT '' AFTER `form_url_update`,
             ADD COLUMN IF NOT EXISTS `form_url_reset`     VARCHAR(500) NOT NULL DEFAULT '' AFTER `form_url_remove`,
             ADD COLUMN IF NOT EXISTS `redirect_url_reset` VARCHAR(500) NOT NULL DEFAULT '' AFTER `form_url_reset`"
        );
        $pdo->exec('INSERT INTO `lstr_migrations` (migration_number) VALUES (19)');
        $done[] = 'Added <code>form_url_*</code> and <code>redirect_url_reset</code> columns to <code>lstr_fanlisting_settings</code>';
    }
} catch (PDOException $e) {
    $errors[] = 'lstr_fanlisting_settings form URLs — ' . htmlspecialchars($e->getMessage(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// ─────────────────────────────────────────────────────────────────────────────
// Migration 20: last_updated DATE on lstr_fanlisting_settings
// ─────────────────────────────────────────────────────────────────────────────
try {
    if (!(int) $pdo->query('SELECT COUNT(*) FROM `lstr_migrations` WHERE migration_number = 20')->fetchColumn()) {
        $pdo->exec(
            'ALTER TABLE `lstr_fanlisting_settings`
             ADD COLUMN IF NOT EXISTS `last_updated` DATE NULL DEFAULT NULL
             AFTER `redirect_url_reset`'
        );
        $pdo->exec('INSERT INTO `lstr_migrations` (migration_number) VALUES (20)');
        $done[] = 'Added <code>last_updated</code> column to <code>lstr_fanlisting_settings</code>';
    }
} catch (PDOException $e) {
    $errors[] = 'lstr_fanlisting_settings.last_updated — ' . htmlspecialchars($e->getMessage(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// ─────────────────────────────────────────────────────────────────────────────
// Migration 21: pending update approval
// ─────────────────────────────────────────────────────────────────────────────
try {
    if (!(int) $pdo->query('SELECT COUNT(*) FROM `lstr_migrations` WHERE migration_number = 21')->fetchColumn()) {
        $pdo->exec(
            'ALTER TABLE `lstr_fanlisting_settings`
             ADD COLUMN IF NOT EXISTS `queue_updates` TINYINT(1) NOT NULL DEFAULT 0
             AFTER `last_updated`'
        );
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS `lstr_pending_updates` (
               `id`            INT UNSIGNED  NOT NULL AUTO_INCREMENT,
               `fanlisting_id` INT UNSIGNED  NOT NULL,
               `member_email`  VARCHAR(255)  NOT NULL,
               `changes`       TEXT          NOT NULL,
               `submitted_at`  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
               PRIMARY KEY (`id`),
               INDEX `idx_fanlisting` (`fanlisting_id`),
               INDEX `idx_email`      (`member_email`)
             ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        $pdo->exec('INSERT INTO `lstr_migrations` (migration_number) VALUES (21)');
        $done[] = 'Added <code>queue_updates</code> column and created <code>lstr_pending_updates</code> table';
    }
} catch (PDOException $e) {
    $errors[] = 'migration 21 — ' . htmlspecialchars($e->getMessage(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// ─────────────────────────────────────────────────────────────────────────────
// Migration 22: listing codes
// ─────────────────────────────────────────────────────────────────────────────
try {
    if (!(int) $pdo->query('SELECT COUNT(*) FROM `lstr_migrations` WHERE migration_number = 22')->fetchColumn()) {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS `lstr_codes` (
               `id`            INT UNSIGNED      NOT NULL AUTO_INCREMENT,
               `fanlisting_id` INT UNSIGNED      NOT NULL,
               `filename`      VARCHAR(255)      NOT NULL,
               `width`         SMALLINT UNSIGNED NOT NULL DEFAULT 0,
               `height`        SMALLINT UNSIGNED NOT NULL DEFAULT 0,
               `sort_order`    SMALLINT UNSIGNED NOT NULL DEFAULT 0,
               `created_at`    DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP,
               PRIMARY KEY (`id`),
               INDEX `idx_fanlisting`      (`fanlisting_id`),
               INDEX `idx_fanlisting_sort` (`fanlisting_id`, `sort_order`, `id`)
             ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        $pdo->exec('INSERT INTO `lstr_migrations` (migration_number) VALUES (22)');
        $done[] = 'Created <code>lstr_codes</code> table';
    }
} catch (PDOException $e) {
    $errors[] = 'lstr_codes — ' . htmlspecialchars($e->getMessage(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// ─────────────────────────────────────────────────────────────────────────────
// Migration 23: per-fanlisting email templates
// ─────────────────────────────────────────────────────────────────────────────
try {
    if (!(int) $pdo->query('SELECT COUNT(*) FROM `lstr_migrations` WHERE migration_number = 23')->fetchColumn()) {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS `lstr_email_templates` (
               `id`            INT UNSIGNED  NOT NULL AUTO_INCREMENT,
               `fanlisting_id` INT UNSIGNED  NOT NULL,
               `type`          VARCHAR(20)   NOT NULL,
               `enabled`       TINYINT(1)    NOT NULL DEFAULT 1,
               `subject`       VARCHAR(255)  NOT NULL DEFAULT '',
               `body`          TEXT          NOT NULL,
               PRIMARY KEY (`id`),
               UNIQUE KEY `uq_fanlisting_type` (`fanlisting_id`, `type`)
             ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        $pdo->exec('INSERT INTO `lstr_migrations` (migration_number) VALUES (23)');
        $done[] = 'Created <code>lstr_email_templates</code> table';
    }
} catch (PDOException $e) {
    $errors[] = 'lstr_email_templates — ' . htmlspecialchars($e->getMessage(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// ─────────────────────────────────────────────────────────────────────────────
// Migration 24: lstr_blacklist (email / domain spam block list)
// ─────────────────────────────────────────────────────────────────────────────
try {
    $already = (int) $pdo->query(
        'SELECT COUNT(*) FROM `lstr_migrations` WHERE migration_number = 24'
    )->fetchColumn();

    if (!$already) {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS `lstr_blacklist` (
                `id`         INT UNSIGNED  NOT NULL AUTO_INCREMENT,
                `pattern`    VARCHAR(255)  NOT NULL,
                `note`       VARCHAR(500)  NOT NULL DEFAULT '',
                `created_at` DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_pattern` (`pattern`)
             ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        $pdo->exec('INSERT INTO `lstr_migrations` (migration_number) VALUES (24)');
        $done[] = 'Created <code>lstr_blacklist</code> table';
    }
} catch (PDOException $e) {
    $errors[] = 'lstr_blacklist — ' . htmlspecialchars($e->getMessage(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// Migration 25: last_login and failed_attempts columns on lstr_admin_users
try {
    $ran = $pdo->query('SELECT COUNT(*) FROM `lstr_migrations` WHERE migration_number = 25')->fetchColumn();
    if (!$ran) {
        $pdo->exec(
            "ALTER TABLE `lstr_admin_users`
             ADD COLUMN IF NOT EXISTS `last_login`      DATETIME     NULL     DEFAULT NULL,
             ADD COLUMN IF NOT EXISTS `failed_attempts` INT UNSIGNED NOT NULL DEFAULT 0"
        );
        $pdo->exec('INSERT INTO `lstr_migrations` (migration_number) VALUES (25)');
        $done[] = 'Added <code>last_login</code> and <code>failed_attempts</code> to <code>lstr_admin_users</code>';
    }
} catch (PDOException $e) {
    $errors[] = 'lstr_admin_users columns — ' . htmlspecialchars($e->getMessage(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// Migration 26: admin remember-me tokens
try {
    if (!(int) $pdo->query('SELECT COUNT(*) FROM `lstr_migrations` WHERE migration_number = 26')->fetchColumn()) {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS `lstr_admin_remember_tokens` (
                `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `admin_user_id` INT UNSIGNED NOT NULL,
                `token_hash`    VARCHAR(64)  NOT NULL,
                `expires_at`    DATETIME     NOT NULL,
                `created_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uk_token_hash` (`token_hash`),
                KEY `idx_user` (`admin_user_id`),
                CONSTRAINT `fk_rt_admin_user`
                    FOREIGN KEY (`admin_user_id`) REFERENCES `lstr_admin_users` (`id`)
                    ON DELETE CASCADE
             ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        $pdo->exec('INSERT INTO `lstr_migrations` (migration_number) VALUES (26)');
        $done[] = 'Created <code>lstr_admin_remember_tokens</code> table';
    }
} catch (PDOException $e) {
    $errors[] = 'lstr_admin_remember_tokens — ' . htmlspecialchars($e->getMessage(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// ─────────────────────────────────────────────────────────────────────────────
// Migration 27: lstr_addons — installed add-on registry
// ─────────────────────────────────────────────────────────────────────────────
try {
    if (!(int) $pdo->query('SELECT COUNT(*) FROM `lstr_migrations` WHERE migration_number = 27')->fetchColumn()) {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS `lstr_addons` (
                `addon_key`    VARCHAR(50)  NOT NULL,
                `installed_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`addon_key`)
             ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        $pdo->exec('INSERT INTO `lstr_migrations` (migration_number) VALUES (27)');
        $done[] = 'Created <code>lstr_addons</code> table';
    }
} catch (PDOException $e) {
    $errors[] = 'lstr_addons — ' . htmlspecialchars($e->getMessage(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

?><!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Luster Migration</title>
  <style>
    body  { font-family: system-ui, sans-serif; max-width: 640px; margin: 2.5rem auto; padding: 1rem; color: #111; }
    h1    { font-size: 1.5rem; margin-bottom: 1.25rem; }
    h2    { font-size: 1.1rem; margin: 1rem 0 0.5rem; }
    .ok   { background: #f0fdf4; border: 1px solid #86efac; border-radius: 5px;
            padding: .65rem 1rem; margin: .35rem 0; color: #15803d; }
    .err  { background: #fef2f2; border: 1px solid #fca5a5; border-radius: 5px;
            padding: .65rem 1rem; margin: .35rem 0; color: #b91c1c; }
    code  { background: #f3f4f6; padding: .1rem .3rem; border-radius: 3px; font-size: .9em; }
    a     { color: #4f46e5; }
    .back { margin-top: 1.75rem; }
  </style>
</head>
<body>
<h1>Luster — Migration Runner</h1>

<?php if (!empty($errors)): ?>
  <h2 style="color:#b91c1c">Errors</h2>
  <?php foreach ($errors as $msg): ?>
    <div class="err"><?= $msg ?></div>
  <?php endforeach; ?>
<?php endif; ?>

<?php if (!empty($done)): ?>
  <h2 style="color:#15803d">Completed</h2>
  <?php foreach ($done as $msg): ?>
    <div class="ok"><?= $msg ?></div>
  <?php endforeach; ?>
<?php endif; ?>

<?php if (empty($errors) && empty($done)): ?>
  <p>Nothing to migrate.</p>
<?php endif; ?>

<p class="back"><a href="/luster/public/admin">Return to admin panel</a></p>
</body>
</html>
