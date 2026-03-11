<?php

declare(strict_types=1);

/**
 * Luster Phase 5 Database Migration
 *
 * Run this script once via the browser AFTER logging into the admin panel.
 * URL: http://your-site/luster/public/migrate.php
 *
 * What it does:
 *   1. Adds `active` TINYINT column to lstr_affiliates (toggle active/inactive)
 *   2. Creates lstr_fanlisting_settings table (auto-approve per fanlisting)
 *   3. Makes lstr_affiliates.fanlisting_id nullable (NULL = collective affiliate)
 *   4. Creates lstr_joined table (joined fanlistings tracker)
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
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS `lstr_migrations` (
            `migration_number` INT UNSIGNED NOT NULL,
            `run_at`           DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`migration_number`)
         ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    $done[] = 'Created (or verified) <code>lstr_migrations</code> tracking table';

    // Existing installs that ran migrations before tracking was added:
    // if the table is empty AND lstr_categories already exists, mark 1–9 as run.
    $tracked = (int) $pdo->query('SELECT COUNT(*) FROM `lstr_migrations`')->fetchColumn();

    if ($tracked === 0) {
        $categoriesExist = (int) $pdo->query(
            "SELECT COUNT(*) FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'lstr_categories'"
        )->fetchColumn() > 0;

        if ($categoriesExist) {
            $pdo->exec(
                'INSERT INTO `lstr_migrations` (migration_number) VALUES
                 (1),(2),(3),(4),(5),(6),(7),(8),(9)'
            );
            $done[] = 'Marked migrations 1–9 as already run (existing install detected)';
        }
    }
} catch (PDOException $e) {
    $errors[] = 'lstr_migrations bootstrap — ' . htmlspecialchars($e->getMessage(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// ─────────────────────────────────────────────────────────────────────────────
// Migration 1: active column on lstr_affiliates
// ─────────────────────────────────────────────────────────────────────────────
try {
    $pdo->exec(
        'ALTER TABLE `lstr_affiliates`
         ADD COLUMN IF NOT EXISTS `active` TINYINT(1) NOT NULL DEFAULT 1
         AFTER `sort_order`'
    );
    $done[] = 'Added <code>active</code> column to <code>lstr_affiliates</code>';
} catch (PDOException $e) {
    $errors[] = 'lstr_affiliates.active — ' . htmlspecialchars($e->getMessage(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// ─────────────────────────────────────────────────────────────────────────────
// Migration 2: lstr_fanlisting_settings (auto-approve, etc.)
// ─────────────────────────────────────────────────────────────────────────────
try {
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS `lstr_fanlisting_settings` (
            `fanlisting_id` INT UNSIGNED NOT NULL,
            `auto_approve`  TINYINT(1)   NOT NULL DEFAULT 0,
            `updated_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`fanlisting_id`)
         ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    $done[] = 'Created <code>lstr_fanlisting_settings</code> table';
} catch (PDOException $e) {
    $errors[] = 'lstr_fanlisting_settings — ' . htmlspecialchars($e->getMessage(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// ─────────────────────────────────────────────────────────────────────────────
// Migration 3: make lstr_affiliates.fanlisting_id nullable
//              NULL  = collective / site-wide affiliate
//              INT   = belongs to a specific fanlisting
// ─────────────────────────────────────────────────────────────────────────────
try {
    $pdo->exec(
        'ALTER TABLE `lstr_affiliates`
         MODIFY COLUMN `fanlisting_id` INT UNSIGNED NULL DEFAULT NULL'
    );
    $done[] = 'Made <code>lstr_affiliates.fanlisting_id</code> nullable (NULL = collective affiliate)';
} catch (PDOException $e) {
    $errors[] = 'lstr_affiliates.fanlisting_id nullable — ' . htmlspecialchars($e->getMessage(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// ─────────────────────────────────────────────────────────────────────────────
// Migration 4: lstr_joined (joined fanlistings tracker)
// ─────────────────────────────────────────────────────────────────────────────
try {
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
    $done[] = 'Created <code>lstr_joined</code> table';
} catch (PDOException $e) {
    $errors[] = 'lstr_joined — ' . htmlspecialchars($e->getMessage(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// ─────────────────────────────────────────────────────────────────────────────
// Migration 5: stats and members template columns on lstr_fanlisting_settings
// ─────────────────────────────────────────────────────────────────────────────
try {
    $pdo->exec(
        'ALTER TABLE `lstr_fanlisting_settings`
         ADD COLUMN IF NOT EXISTS `stats_template`   TEXT NULL DEFAULT NULL,
         ADD COLUMN IF NOT EXISTS `members_template` TEXT NULL DEFAULT NULL'
    );
    $done[] = 'Added <code>stats_template</code> and <code>members_template</code> columns to <code>lstr_fanlisting_settings</code>';
} catch (PDOException $e) {
    $errors[] = 'lstr_fanlisting_settings templates — ' . htmlspecialchars($e->getMessage(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// ─────────────────────────────────────────────────────────────────────────────
// Migration 6: affiliates template column on lstr_fanlisting_settings
// ─────────────────────────────────────────────────────────────────────────────
try {
    $pdo->exec(
        'ALTER TABLE `lstr_fanlisting_settings`
         ADD COLUMN IF NOT EXISTS `affiliates_template` TEXT NULL DEFAULT NULL'
    );
    $done[] = 'Added <code>affiliates_template</code> column to <code>lstr_fanlisting_settings</code>';
} catch (PDOException $e) {
    $errors[] = 'lstr_fanlisting_settings.affiliates_template — ' . htmlspecialchars($e->getMessage(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// ─────────────────────────────────────────────────────────────────────────────
// Migration 7: lstr_categories table + category_id on lstr_joined
// ─────────────────────────────────────────────────────────────────────────────
try {
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
    $done[] = 'Created <code>lstr_categories</code> table';
} catch (PDOException $e) {
    $errors[] = 'lstr_categories — ' . htmlspecialchars($e->getMessage(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

try {
    $pdo->exec(
        'ALTER TABLE `lstr_joined`
         ADD COLUMN IF NOT EXISTS `category_id` INT UNSIGNED NULL'
    );
    $done[] = 'Added <code>category_id</code> column to <code>lstr_joined</code>';
} catch (PDOException $e) {
    $errors[] = 'lstr_joined.category_id — ' . htmlspecialchars($e->getMessage(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

try {
    $tflCategories = [
        'Academia',
        'Actors',
        'Actresses',
        'Adult',
        'Advertising/TV Channels',
        'Albums',
        'Animals',
        'Animation',
        'Anime/Manga',
        'Arts and Design',
        'Authors/Writers',
        'Calendar Events',
        'Characters: Book/Movie',
        'Characters: TV',
        'Comics',
        'Companies',
        'Computer Miscellany and Internet',
        'Directors/Producers',
        'Episodes',
        'Fan Works',
        'Fashion/Beauty',
        'Food/Drinks',
        'Games',
        'History/Royalty',
        'Hobbies and Recreation',
        'Literature',
        'Miscellaneous',
        'Models',
        'Movies',
        'Music Miscellany',
        'Musicians: Bands/Groups',
        'Musicians: Female',
        'Musicians: Male',
        'Mythology/Religion',
        'Nature',
        'Objects',
        'People Miscellany',
        'Personalities',
        'Places',
        'Politics and Organisations',
        'Relationships: Book/Movie',
        'Relationships: Real Life',
        'Relationships: TV',
        'Songs: Bands/Groups 0-M',
        'Songs: Bands/Groups N-Z',
        'Songs: Female Solo',
        'Songs: Male Solo',
        'Songs: Various',
        'Sports',
        'Sports Entertainment',
        'Stage/Theatre',
        'Toys/Collectibles',
        'Transportation',
        'TV Shows',
        'TV/Movie/Book Miscellany',
        'Webmasters',
        'Websites',
    ];

    $pdo->exec("DELETE FROM `lstr_categories` WHERE source = 'tfl'");

    $stmt = $pdo->prepare(
        "INSERT IGNORE INTO `lstr_categories` (name, source, parent_id)
         VALUES (:name, 'tfl', NULL)"
    );

    foreach ($tflCategories as $catName) {
        $stmt->execute(['name' => $catName]);
    }

    $done[] = 'Seeded ' . count($tflCategories) . ' TFL categories into <code>lstr_categories</code>';
} catch (PDOException $e) {
    $errors[] = 'lstr_categories TFL seed — ' . htmlspecialchars($e->getMessage(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// ─────────────────────────────────────────────────────────────────────────────
// Migration 8: category_id on owned / enth_fanlistings
//              Adds Luster's own category assignment (distinct from Enthusiast's
//              native catid column). References lstr_categories.
// ─────────────────────────────────────────────────────────────────────────────
try {
    // Unprefixed Enthusiast 3 schema
    $pdo->exec(
        'ALTER TABLE `owned`
         ADD COLUMN IF NOT EXISTS `category_id` INT UNSIGNED NULL'
    );
    $done[] = 'Added <code>category_id</code> column to <code>owned</code>';
} catch (PDOException $e) {
    $errors[] = 'owned.category_id — ' . htmlspecialchars($e->getMessage(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

try {
    // Standard prefixed Enthusiast schema
    $pdo->exec(
        'ALTER TABLE `enth_fanlistings`
         ADD COLUMN IF NOT EXISTS `category_id` INT UNSIGNED NULL'
    );
    $done[] = 'Added <code>category_id</code> column to <code>enth_fanlistings</code>';
} catch (PDOException $e) {
    $errors[] = 'enth_fanlistings.category_id — ' . htmlspecialchars($e->getMessage(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// ─────────────────────────────────────────────────────────────────────────────
// Migration 9: TAFL category seed into lstr_categories
//              Flatten TAFL categories to Anime/Manga: Name format.
//              Clears any previous TAFL rows first so re-runs are idempotent.
// ─────────────────────────────────────────────────────────────────────────────
try {
    $pdo->exec("DELETE FROM `lstr_categories` WHERE source = 'tafl'");

    $taflCategories = [
        'Anime/Manga: Adult',
        'Anime/Manga: Characters 0-M',
        'Anime/Manga: Characters N-Z',
        'Anime/Manga: Episodes',
        'Anime/Manga: Fanstuff',
        'Anime/Manga: General',
        'Anime/Manga: Items/Locations',
        'Anime/Manga: Manga-ka & Directors',
        'Anime/Manga: Movies/OVAs',
        'Anime/Manga: Music & Songs',
        'Anime/Manga: Relationships',
        'Anime/Manga: Rivalries',
        'Anime/Manga: Seiyuu',
        'Anime/Manga: Series',
    ];

    $stmt = $pdo->prepare(
        "INSERT IGNORE INTO `lstr_categories` (name, parent_id, source)
         VALUES (:name, NULL, 'tafl')"
    );

    foreach ($taflCategories as $catName) {
        $stmt->execute(['name' => $catName]);
    }

    $done[] = 'Seeded ' . count($taflCategories) . ' TAFL categories into <code>lstr_categories</code>';
} catch (PDOException $e) {
    $errors[] = 'lstr_categories TAFL seed — ' . htmlspecialchars($e->getMessage(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// ─────────────────────────────────────────────────────────────────────────────
// Migration 10: Remove duplicate and bare Anime/Manga categories
// ─────────────────────────────────────────────────────────────────────────────
try {
    // 1. Remove the bare "Anime/Manga" category (now superseded by prefixed rows)
    $pdo->exec("DELETE FROM `lstr_categories` WHERE name = 'Anime/Manga'");

    // 2. Remove any TAFL rows that don't follow the "Anime/Manga: Name" pattern
    $pdo->exec("DELETE FROM `lstr_categories` WHERE source = 'tafl' AND name NOT LIKE 'Anime/Manga:%'");

    // 3. Deduplicate remaining TAFL rows — keep the lowest id when names collide
    $pdo->exec(
        "DELETE c1 FROM `lstr_categories` c1
         INNER JOIN `lstr_categories` c2
         WHERE c1.id > c2.id
           AND c1.name = c2.name
           AND c1.source = 'tafl'"
    );

    $done[] = 'Cleaned up duplicate and bare Anime/Manga categories from <code>lstr_categories</code>';
} catch (PDOException $e) {
    $errors[] = 'lstr_categories cleanup — ' . htmlspecialchars($e->getMessage(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// ─────────────────────────────────────────────────────────────────────────────
// Migration 11: image column on lstr_fanlisting_settings
// ─────────────────────────────────────────────────────────────────────────────
try {
    $pdo->exec(
        'ALTER TABLE `lstr_fanlisting_settings`
         ADD COLUMN IF NOT EXISTS `image` VARCHAR(255) NULL DEFAULT NULL
         AFTER `affiliates_template`'
    );
    $done[] = 'Added <code>image</code> column to <code>lstr_fanlisting_settings</code>';
} catch (PDOException $e) {
    $errors[] = 'lstr_fanlisting_settings.image — ' . htmlspecialchars($e->getMessage(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// ─────────────────────────────────────────────────────────────────────────────
// Migration 12: listing_type column on lstr_fanlisting_settings
// ─────────────────────────────────────────────────────────────────────────────
try {
    $pdo->exec(
        'ALTER TABLE `lstr_fanlisting_settings`
         ADD COLUMN IF NOT EXISTS `listing_type` VARCHAR(50) NULL DEFAULT NULL
         AFTER `image`'
    );
    $done[] = 'Added <code>listing_type</code> column to <code>lstr_fanlisting_settings</code>';
} catch (PDOException $e) {
    $errors[] = 'lstr_fanlisting_settings.listing_type — ' . htmlspecialchars($e->getMessage(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// ─────────────────────────────────────────────────────────────────────────────
// Migration 13: members_per_page column on lstr_fanlisting_settings
// ─────────────────────────────────────────────────────────────────────────────
try {
    $pdo->exec(
        'ALTER TABLE `lstr_fanlisting_settings`
         ADD COLUMN IF NOT EXISTS `members_per_page` TINYINT UNSIGNED NULL DEFAULT NULL
         AFTER `listing_type`'
    );
    $done[] = 'Added <code>members_per_page</code> column to <code>lstr_fanlisting_settings</code>';
} catch (PDOException $e) {
    $errors[] = 'lstr_fanlisting_settings.members_per_page — ' . htmlspecialchars($e->getMessage(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// ─────────────────────────────────────────────────────────────────────────────
// Migration 15: status and categories_json columns on lstr_joined
// ─────────────────────────────────────────────────────────────────────────────
try {
    $pdo->exec(
        "ALTER TABLE `lstr_joined`
         ADD COLUMN IF NOT EXISTS `status`          VARCHAR(20) NOT NULL DEFAULT 'joined' AFTER `active`,
         ADD COLUMN IF NOT EXISTS `categories_json` TEXT        NULL                      AFTER `category`"
    );
    $done[] = 'Added <code>status</code> and <code>categories_json</code> columns to <code>lstr_joined</code>';
} catch (PDOException $e) {
    $errors[] = 'lstr_joined status/categories_json — ' . htmlspecialchars($e->getMessage(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

try {
    $pdo->exec("UPDATE `lstr_joined` SET `status` = IF(`active` = 1, 'joined', 'pending')");
    $done[] = 'Backfilled <code>lstr_joined.status</code> from <code>active</code> column';
} catch (PDOException $e) {
    $errors[] = 'lstr_joined status backfill — ' . htmlspecialchars($e->getMessage(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// ─────────────────────────────────────────────────────────────────────────────
// Migration 14: lstr_error_log table
// ─────────────────────────────────────────────────────────────────────────────
try {
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
    $done[] = 'Created <code>lstr_error_log</code> table';
} catch (PDOException $e) {
    $errors[] = 'lstr_error_log — ' . htmlspecialchars($e->getMessage(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// ─────────────────────────────────────────────────────────────────────────────
// Migration 16: widen lstr_categories.source from ENUM to VARCHAR(50)
// ─────────────────────────────────────────────────────────────────────────────
try {
    $pdo->exec("ALTER TABLE lstr_categories MODIFY COLUMN source VARCHAR(50) NOT NULL DEFAULT 'custom'");
    $done[] = 'Changed <code>lstr_categories.source</code> from ENUM to VARCHAR(50)';
} catch (PDOException $e) {
    $errors[] = 'lstr_categories.source VARCHAR — ' . htmlspecialchars($e->getMessage(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// ─────────────────────────────────────────────────────────────────────────────
// Migration 17: per-fanlisting embed settings columns on lstr_fanlisting_settings
// ─────────────────────────────────────────────────────────────────────────────
try {
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
    $done[] = 'Added embed settings columns to <code>lstr_fanlisting_settings</code>';
} catch (PDOException $e) {
    $errors[] = 'lstr_fanlisting_settings embed columns — ' . htmlspecialchars($e->getMessage(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// ─────────────────────────────────────────────────────────────────────────────
// Migration 18: fan password reset tokens table
// ─────────────────────────────────────────────────────────────────────────────
try {
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
    $done[] = 'Created <code>lstr_fan_password_resets</code> table';
} catch (PDOException $e) {
    $errors[] = 'lstr_fan_password_resets — ' . htmlspecialchars($e->getMessage(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
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
<h1>Luster — Phase 5 Migration</h1>

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
