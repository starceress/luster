<?php

declare(strict_types=1);

/**
 * Luster Database Migration Runner
 *
 * Run via the browser AFTER logging into the admin panel.
 * URL: http://your-site/luster/public/migrate/
 *
 * Applies all pending migrations in sequence. Safe to re-run — each migration
 * is tracked in lstr_migrations and skipped if already applied.
 */

// Session guard — must be logged in as admin
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_secure', '1');
ini_set('session.use_strict_mode', '1');
ini_set('session.cookie_samesite', 'Strict');
session_name('luster_session');
session_start();

// ─── Shared page shell ────────────────────────────────────────────────────
function mig_page(string $pageTitle, string $phTitle, string $phSub, string $content, bool $dimGem = false, string $selectedSkin = ''): string
{
    require_once __DIR__ . '/../../templates/admin/_css_assets.php';
    $h = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $titleSafe   = $h($pageTitle);
    $phTitleSafe = $h($phTitle);
    $phSubSafe   = $h($phSub);
    $gemStyle    = $dimGem ? ' style="opacity:.45"' : '';
    $skinAttr    = $selectedSkin !== '' ? ' data-skin="' . $h($selectedSkin) . '"' : '';
    $cssLinks    = lusterAdminCssLinks('../assets/css', __DIR__ . '/../assets/css', $selectedSkin);
    $migCssV     = is_file(__DIR__ . '/migrate.css') ? (string) (filemtime(__DIR__ . '/migrate.css') ?: 0) : '0';

    return <<<HTML
<!DOCTYPE html>
<html lang="en" data-theme="dark"{$skinAttr}>
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>{$titleSafe} &#10022; Luster</title>
  {$cssLinks}
  <link rel="stylesheet" href="migrate.css?v={$migCssV}">


  <script>(function(){var t=localStorage.getItem('lstr_theme');if(t==='dark'||t==='light')document.documentElement.setAttribute('data-theme',t);})();</script>
</head>
<body>

<div class="mig-topbar">
  <div class="mig-tb-gem"></div>
  <a class="mig-tb-brand" href="../admin">Luster</a>
  <div class="mig-tb-sep"></div>
  <span class="mig-tb-sub">Migrations</span>
  <div class="mig-tb-right">
    <button type="button" class="mig-tb-theme theme-toggle" aria-label="Toggle theme">
      <span class="theme-toggle-icon"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="12" r="5"/><path d="M12 1v3M12 20v3M4.22 4.22l2.12 2.12M17.66 17.66l2.12 2.12M1 12h3M20 12h3M4.22 19.78l2.12-2.12M17.66 6.34l2.12-2.12"/></svg></span>
      <div class="toggle-track"><div class="toggle-thumb"></div></div>
      <span class="theme-toggle-icon"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/></svg></span>
    </button>
  </div>
</div>

<div class="mig-wrap">
  <div class="mig-ph">
    <div class="mig-ph-gem"{$gemStyle}></div>
    <div>
      <div class="mig-ph-title">{$phTitleSafe}</div>
      <div class="mig-ph-sub">{$phSubSafe}</div>
    </div>
  </div>

  <div class="mig-card">
    {$content}
  </div>
</div>

<script src="../assets/js/admin.js"></script>
</body>
</html>
HTML;
}

// ─── Access denied ────────────────────────────────────────────────────────
if (empty($_SESSION['lstr_admin_id'])) {
    http_response_code(403);
    echo mig_page('Access Denied', 'Access Denied', 'Authentication required.', <<<HTML
<div class="mig-card-head">
  <div class="mig-card-head-icon">&#128274;</div>
  <div>
    <div class="mig-card-head-title">Access Denied</div>
    <div class="mig-card-head-sub">No active admin session detected.</div>
  </div>
</div>
<div class="mig-card-body">
  <div class="mig-callout mig-callout--err">
    <div class="mig-callout-icon">&#128683;</div>
    <div class="mig-callout-text"><strong>No active admin session.</strong> You must be logged into the admin panel to run migrations. Please log in first, then return to this page.</div>
  </div>
</div>
<div class="mig-card-footer">
  <a href="../admin" class="mig-back-link">&#8592; Go to admin login</a>
</div>
HTML, true);
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
$tokenSafe    = htmlspecialchars($migrateToken, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

// ─── Skin (lightweight pre-read, non-fatal) ───────────────────────────────
// Reads selected_skin from lstr_site_settings before any handler fires so
// the GET confirmation page and POST result page both pick up the active skin.
$migSelectedSkin = '';
(static function () use (&$migSelectedSkin): void {
    $cfgPath = dirname(dirname(__DIR__)) . '/config/app.php';
    if (!is_file($cfgPath)) {
        return;
    }
    $cfg = require $cfgPath;
    try {
        $p = new PDO(
            sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
                $cfg['db_host'] ?? 'localhost',
                (int) ($cfg['db_port'] ?? 3306),
                $cfg['db_name']   ?? ''),
            (string) ($cfg['db_user'] ?? ''),
            (string) ($cfg['db_pass'] ?? ''),
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 2]
        );
        $s = $p->query("SELECT value FROM lstr_site_settings WHERE `key` = 'selected_skin' LIMIT 1");
        if ($s !== false) {
            $row = $s->fetch(PDO::FETCH_ASSOC);
            $migSelectedSkin = ($row !== false && isset($row['value'])) ? (string) $row['value'] : '';
        }
    } catch (\Throwable) {}
})();
// ─────────────────────────────────────────────────────────────────────────────

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    // Show confirmation page — nothing runs on GET.
    echo mig_page('Run Migrations', 'Run Migrations', 'Apply pending database schema updates', <<<HTML
<div class="pc-head">
  <div class="pc-head-icon pc-head-icon-bg">&#128260;</div>
  <div class="pc-head-meta">
    <div class="pc-head-title">Run Migrations</div>
    <div class="pc-head-sub">Safe to re-run &mdash; no duplicate operations.</div>
  </div>
</div>
<div class="mig-card-body">
<div class="callout callout-inline"><svg xmlns="http://www.w3.org/2000/svg" width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="#ffd166" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3"/><path d="M12 9v4"/><path d="M12 17h.01"/></svg>
<div><strong>Always back up your database before continuing.</strong> While each migration is designed to be safe, a backup ensures you can recover if anything goes wrong.</div>
</div>
</div>
<div class="mig-card-footer">
  <a href="../admin" class="btn btn-quaternary">&larr; Cancel</a>
  <form method="POST" action="" style="display:contents">
    <input type="hidden" name="_csrf" value="{$tokenSafe}">
    <button type="submit" class="btn btn-primary">Run migrations &rarr;</button>
  </form>
</div>
HTML, false, $migSelectedSkin);
    exit;
}

// POST — validate CSRF token before running anything.
$submittedToken = (string) ($_POST['_csrf'] ?? '');
if (!hash_equals($migrateToken, $submittedToken)) {
    http_response_code(403);
    echo mig_page('Invalid Token', 'Invalid Token', 'Security token missing or expired.', <<<HTML
<div class="mig-card-head">
  <div class="mig-card-head-icon">&#128683;</div>
  <div>
    <div class="mig-card-head-title">Invalid Token</div>
    <div class="mig-card-head-sub">The CSRF token was missing or has expired.</div>
  </div>
</div>
<div class="mig-card-body">
  <div class="mig-callout mig-callout--err">
    <div class="mig-callout-icon">&#9888;</div>
    <div class="mig-callout-text"><strong>Token validation failed.</strong> Please go back to the migration page and try again. Tokens are single-use and expire with your session.</div>
  </div>
</div>
<div class="mig-card-footer">
  <a href="./" class="mig-back-link">&#8592; Back to migration page</a>
</div>
HTML, false, $migSelectedSkin);
    exit;
}

// Consume the token — one-time use.
unset($_SESSION['lstr_migrate_token']);

$configPath = dirname(dirname(__DIR__)) . '/config/app.php';

if (!is_file($configPath)) {
    http_response_code(500);
    echo mig_page('Configuration Missing', 'Configuration Missing', 'Luster is not yet installed.', <<<HTML
<div class="mig-card-head">
  <div class="mig-card-head-icon">&#128462;</div>
  <div>
    <div class="mig-card-head-title">Configuration Missing</div>
    <div class="mig-card-head-sub"><code>config/app.php</code> was not found.</div>
  </div>
</div>
<div class="mig-card-body">
  <div class="mig-callout mig-callout--err">
    <div class="mig-callout-icon">&#9888;</div>
    <div class="mig-callout-text"><strong>Luster is not configured.</strong> Run the installer first, then return to this page.</div>
  </div>
</div>
<div class="mig-card-footer">
  <a href="../install/" class="mig-back-link">&#8594; Go to installer</a>
</div>
HTML, false, $migSelectedSkin);
    exit;
}

$config = require $configPath;

require_once __DIR__ . '/../../src/Support/CategorySeedCatalog.php';

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
    $errMsg = htmlspecialchars($e->getMessage(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    http_response_code(500);
    echo mig_page('Database Error', 'Database Error', 'Could not connect to the database.', <<<HTML
<div class="mig-card-head">
  <div class="mig-card-head-icon">&#128268;</div>
  <div>
    <div class="mig-card-head-title">Database Connection Failed</div>
    <div class="mig-card-head-sub">Check your <code>config/app.php</code> credentials.</div>
  </div>
</div>
<div class="mig-card-body">
  <div class="mig-callout mig-callout--err">
    <div class="mig-callout-icon">&#9888;</div>
    <div class="mig-callout-text"><strong>Connection error.</strong> {$errMsg}</div>
  </div>
</div>
<div class="mig-card-footer">
  <a href="./" class="mig-back-link">&#8592; Try again</a>
</div>
HTML, false, $migSelectedSkin);
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
        $tflCategories = \Luster\Support\CategorySeedCatalog::tfl();
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
        $taflCategories = \Luster\Support\CategorySeedCatalog::tafl();
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

// Migration 28: lstr_site_settings — global key/value store (collective templates etc.)
// ─────────────────────────────────────────────────────────────────────────────
try {
    if (!(int) $pdo->query('SELECT COUNT(*) FROM `lstr_migrations` WHERE migration_number = 28')->fetchColumn()) {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS `lstr_site_settings` (
                `setting_key`   VARCHAR(100) NOT NULL,
                `setting_value` TEXT         NULL,
                `updated_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
                                             ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`setting_key`)
             ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        $pdo->exec('INSERT INTO `lstr_migrations` (migration_number) VALUES (28)');
        $done[] = 'Created <code>lstr_site_settings</code> table';
    }
} catch (PDOException $e) {
    $errors[] = 'lstr_site_settings — ' . htmlspecialchars($e->getMessage(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// Migration 29: lstr_saved_emails — admin-saved reusable email draft templates
// ─────────────────────────────────────────────────────────────────────────────
try {
    if (!(int) $pdo->query('SELECT COUNT(*) FROM `lstr_migrations` WHERE migration_number = 29')->fetchColumn()) {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS `lstr_saved_emails` (
                `id`         INT UNSIGNED  NOT NULL AUTO_INCREMENT,
                `name`       VARCHAR(150)  NOT NULL,
                `subject`    VARCHAR(255)  NOT NULL DEFAULT '',
                `body`       TEXT          NOT NULL,
                `created_at` TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`)
             ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        $pdo->exec('INSERT INTO `lstr_migrations` (migration_number) VALUES (29)');
        $done[] = 'Created <code>lstr_saved_emails</code> table';
    }
} catch (PDOException $e) {
    $errors[] = 'lstr_saved_emails — ' . htmlspecialchars($e->getMessage(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// Migration 30: lstr_code_sizes + size_id column on lstr_codes
// ─────────────────────────────────────────────────────────────────────────────
try {
    if (!(int) $pdo->query('SELECT COUNT(*) FROM `lstr_migrations` WHERE migration_number = 30')->fetchColumn()) {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS `lstr_code_sizes` (
                `id`            INT UNSIGNED      NOT NULL AUTO_INCREMENT,
                `fanlisting_id` INT UNSIGNED      NOT NULL,
                `label`         VARCHAR(100)      NOT NULL,
                `width`         SMALLINT UNSIGNED NULL,
                `height`        SMALLINT UNSIGNED NULL,
                `sort_order`    SMALLINT UNSIGNED NOT NULL DEFAULT 0,
                PRIMARY KEY (`id`),
                INDEX `idx_fanlisting` (`fanlisting_id`)
             ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        // Add size_id only if the column does not already exist
        $colCheck = $pdo->query("SHOW COLUMNS FROM `lstr_codes` LIKE 'size_id'")->fetchAll();
        if (empty($colCheck)) {
            $pdo->exec("ALTER TABLE `lstr_codes` ADD COLUMN `size_id` INT UNSIGNED NULL AFTER `height`");
            $pdo->exec(
                "ALTER TABLE `lstr_codes`
                 ADD CONSTRAINT `fk_lstr_codes_size`
                 FOREIGN KEY (`size_id`) REFERENCES `lstr_code_sizes` (`id`) ON DELETE SET NULL"
            );
        }
        $pdo->exec('INSERT INTO `lstr_migrations` (migration_number) VALUES (30)');
        $done[] = 'Created <code>lstr_code_sizes</code> table; added <code>size_id</code> to <code>lstr_codes</code>';
    }
} catch (PDOException $e) {
    $errors[] = 'Code sizes — ' . htmlspecialchars($e->getMessage(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// Migration 31: lstr_code_categories, lstr_code_category_map, lstr_code_donors,
//               donor_id column on lstr_codes
// ─────────────────────────────────────────────────────────────────────────────
try {
    if (!(int) $pdo->query('SELECT COUNT(*) FROM `lstr_migrations` WHERE migration_number = 31')->fetchColumn()) {

        // Categories (supports one level of parent/child)
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS `lstr_code_categories` (
                `id`            INT UNSIGNED      NOT NULL AUTO_INCREMENT,
                `fanlisting_id` INT UNSIGNED      NOT NULL,
                `name`          VARCHAR(100)      NOT NULL,
                `parent_id`     INT UNSIGNED      NULL,
                `sort_order`    SMALLINT UNSIGNED NOT NULL DEFAULT 0,
                PRIMARY KEY (`id`),
                INDEX `idx_fanlisting` (`fanlisting_id`),
                CONSTRAINT `fk_lstr_cat_parent`
                    FOREIGN KEY (`parent_id`) REFERENCES `lstr_code_categories` (`id`) ON DELETE SET NULL
             ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        // Many-to-many junction: codes ↔ categories
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS `lstr_code_category_map` (
                `code_id`     INT UNSIGNED NOT NULL,
                `category_id` INT UNSIGNED NOT NULL,
                PRIMARY KEY (`code_id`, `category_id`),
                CONSTRAINT `fk_lstr_ccmap_code`
                    FOREIGN KEY (`code_id`) REFERENCES `lstr_codes` (`id`) ON DELETE CASCADE,
                CONSTRAINT `fk_lstr_ccmap_cat`
                    FOREIGN KEY (`category_id`) REFERENCES `lstr_code_categories` (`id`) ON DELETE CASCADE
             ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        // Donors
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS `lstr_code_donors` (
                `id`            INT UNSIGNED  NOT NULL AUTO_INCREMENT,
                `fanlisting_id` INT UNSIGNED  NOT NULL,
                `name`          VARCHAR(150)  NOT NULL,
                `email`         VARCHAR(255)  NULL,
                `url`           VARCHAR(500)  NULL,
                PRIMARY KEY (`id`),
                INDEX `idx_fanlisting` (`fanlisting_id`)
             ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        // donor_id on lstr_codes (only if not already present)
        $colCheck = $pdo->query("SHOW COLUMNS FROM `lstr_codes` LIKE 'donor_id'")->fetchAll();
        if (empty($colCheck)) {
            $pdo->exec("ALTER TABLE `lstr_codes` ADD COLUMN `donor_id` INT UNSIGNED NULL AFTER `size_id`");
            $pdo->exec(
                "ALTER TABLE `lstr_codes`
                 ADD CONSTRAINT `fk_lstr_codes_donor`
                 FOREIGN KEY (`donor_id`) REFERENCES `lstr_code_donors` (`id`) ON DELETE SET NULL"
            );
        }

        $pdo->exec('INSERT INTO `lstr_migrations` (migration_number) VALUES (31)');
        $done[] = 'Created <code>lstr_code_categories</code>, <code>lstr_code_category_map</code>, <code>lstr_code_donors</code>; added <code>donor_id</code> to <code>lstr_codes</code>';
    }
} catch (PDOException $e) {
    $errors[] = 'Code categories/donors — ' . htmlspecialchars($e->getMessage(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// ─────────────────────────────────────────────────────────────────────────────
// Migration 33: name column on lstr_codes (per-code label)
// ─────────────────────────────────────────────────────────────────────────────
try {
    if (!(int) $pdo->query('SELECT COUNT(*) FROM `lstr_migrations` WHERE migration_number = 33')->fetchColumn()) {

        $colCheck = $pdo->query("SHOW COLUMNS FROM `lstr_codes` LIKE 'name'")->fetchAll();
        if (empty($colCheck)) {
            $pdo->exec(
                "ALTER TABLE `lstr_codes`
                 ADD COLUMN `name` VARCHAR(150) NULL DEFAULT NULL
                 AFTER `filename`"
            );
        }

        $pdo->exec('INSERT INTO `lstr_migrations` (migration_number) VALUES (33)');
        $done[] = 'Added <code>name</code> column to <code>lstr_codes</code> (per-code label)';
    }
} catch (PDOException $e) {
    $errors[] = 'Code name — ' . htmlspecialchars($e->getMessage(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// ─────────────────────────────────────────────────────────────────────────────
// Migration 32: status column on lstr_codes (approval workflow)
// ─────────────────────────────────────────────────────────────────────────────
try {
    if (!(int) $pdo->query('SELECT COUNT(*) FROM `lstr_migrations` WHERE migration_number = 32')->fetchColumn()) {

        // Add status only if not already present
        $colCheck = $pdo->query("SHOW COLUMNS FROM `lstr_codes` LIKE 'status'")->fetchAll();
        if (empty($colCheck)) {
            $pdo->exec(
                "ALTER TABLE `lstr_codes`
                 ADD COLUMN `status` ENUM('approved','pending','rejected') NOT NULL DEFAULT 'approved'
                 AFTER `donor_id`"
            );
        }

        // Backfill any existing rows (should all be 'approved' via DEFAULT, but be explicit)
        $pdo->exec("UPDATE `lstr_codes` SET `status` = 'approved' WHERE `status` NOT IN ('approved','pending','rejected')");

        // Index for fast per-listing status queries
        $idxCheck = $pdo->query("SHOW INDEX FROM `lstr_codes` WHERE Key_name = 'idx_lstr_codes_status'")->fetchAll();
        if (empty($idxCheck)) {
            $pdo->exec("ALTER TABLE `lstr_codes` ADD INDEX `idx_lstr_codes_status` (`fanlisting_id`, `status`)");
        }

        $pdo->exec('INSERT INTO `lstr_migrations` (migration_number) VALUES (32)');
        $done[] = 'Added <code>status</code> column to <code>lstr_codes</code> (approval workflow)';
    }
} catch (PDOException $e) {
    $errors[] = 'Code status — ' . htmlspecialchars($e->getMessage(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// ─────────────────────────────────────────────────────────────────────────────
// Migration 34: submitter_name and submitter_email on lstr_codes (fan submissions)
// ─────────────────────────────────────────────────────────────────────────────
try {
    if (!(int) $pdo->query('SELECT COUNT(*) FROM `lstr_migrations` WHERE migration_number = 34')->fetchColumn()) {

        $colCheck = $pdo->query("SHOW COLUMNS FROM `lstr_codes` LIKE 'submitter_name'")->fetchAll();
        if (empty($colCheck)) {
            $pdo->exec(
                "ALTER TABLE `lstr_codes`
                 ADD COLUMN `submitter_name`  VARCHAR(150) NULL DEFAULT NULL AFTER `status`,
                 ADD COLUMN `submitter_email` VARCHAR(255) NULL DEFAULT NULL AFTER `submitter_name`"
            );
        }

        $pdo->exec('INSERT INTO `lstr_migrations` (migration_number) VALUES (34)');
        $done[] = 'Added <code>submitter_name</code> and <code>submitter_email</code> to <code>lstr_codes</code> (fan code submissions)';
    }
} catch (PDOException $e) {
    $errors[] = 'Code submitter — ' . htmlspecialchars($e->getMessage(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// ─────────────────────────────────────────────────────────────────────────────
// Migration 35: form_url_codes_submit on lstr_fanlisting_settings
// ─────────────────────────────────────────────────────────────────────────────
try {
    if (!(int) $pdo->query('SELECT COUNT(*) FROM `lstr_migrations` WHERE migration_number = 35')->fetchColumn()) {

        $colCheck = $pdo->query("SHOW COLUMNS FROM `lstr_fanlisting_settings` LIKE 'form_url_codes_submit'")->fetchAll();
        if (empty($colCheck)) {
            $pdo->exec(
                "ALTER TABLE `lstr_fanlisting_settings`
                 ADD COLUMN `form_url_codes_submit` VARCHAR(200) NOT NULL DEFAULT '' AFTER `form_url_reset`"
            );
        }

        $pdo->exec('INSERT INTO `lstr_migrations` (migration_number) VALUES (35)');
        $done[] = 'Added <code>form_url_codes_submit</code> to <code>lstr_fanlisting_settings</code> (code submission page URL)';
    }
} catch (PDOException $e) {
    $errors[] = 'Code submit page URL — ' . htmlspecialchars($e->getMessage(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// ─────────────────────────────────────────────────────────────────────────────
// Migration 36: submitter_url on lstr_codes
// ─────────────────────────────────────────────────────────────────────────────
try {
    if (!(int) $pdo->query('SELECT COUNT(*) FROM `lstr_migrations` WHERE migration_number = 36')->fetchColumn()) {

        $colCheck = $pdo->query("SHOW COLUMNS FROM `lstr_codes` LIKE 'submitter_url'")->fetchAll();
        if (empty($colCheck)) {
            $pdo->exec(
                "ALTER TABLE `lstr_codes`
                 ADD COLUMN `submitter_url` VARCHAR(255) NULL DEFAULT NULL AFTER `submitter_email`"
            );
        }

        $pdo->exec('INSERT INTO `lstr_migrations` (migration_number) VALUES (36)');
        $done[] = 'Added <code>submitter_url</code> to <code>lstr_codes</code> (donor website URL)';
    }
} catch (PDOException $e) {
    $errors[] = 'Donor URL — ' . htmlspecialchars($e->getMessage(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// ─────────────────────────────────────────────────────────────────────────────
// Migration 37: contact_enabled + contact_recipient on lstr_fanlisting_settings
// ─────────────────────────────────────────────────────────────────────────────
try {
    if (!(int) $pdo->query('SELECT COUNT(*) FROM `lstr_migrations` WHERE migration_number = 37')->fetchColumn()) {

        $colCheck = $pdo->query("SHOW COLUMNS FROM `lstr_fanlisting_settings` LIKE 'contact_enabled'")->fetchAll();
        if (empty($colCheck)) {
            $pdo->exec(
                "ALTER TABLE `lstr_fanlisting_settings`
                 ADD COLUMN `contact_enabled`   TINYINT(1)   NOT NULL DEFAULT 1   AFTER `queue_updates`,
                 ADD COLUMN `contact_recipient` VARCHAR(255) NULL     DEFAULT NULL AFTER `contact_enabled`"
            );
        }

        $pdo->exec('INSERT INTO `lstr_migrations` (migration_number) VALUES (37)');
        $done[] = 'Added <code>contact_enabled</code> and <code>contact_recipient</code> to <code>lstr_fanlisting_settings</code> (per-listing contact form)';
    }
} catch (PDOException $e) {
    $errors[] = 'Contact form columns — ' . htmlspecialchars($e->getMessage(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// ─────────────────────────────────────────────────────────────────────────────
// Migration 38: rename lstr_joined status 'joined' → 'approved'
// ─────────────────────────────────────────────────────────────────────────────
try {
    if (!(int) $pdo->query('SELECT COUNT(*) FROM `lstr_migrations` WHERE migration_number = 38')->fetchColumn()) {
        $pdo->exec("UPDATE `lstr_joined` SET `status` = 'approved' WHERE `status` = 'joined'");
        $pdo->exec("ALTER TABLE `lstr_joined` MODIFY COLUMN `status` VARCHAR(20) NOT NULL DEFAULT 'approved'");
        $pdo->exec('INSERT INTO `lstr_migrations` (migration_number) VALUES (38)');
        $done[] = 'Renamed <code>lstr_joined.status</code> value <code>joined</code> → <code>approved</code>';
    }
} catch (PDOException $e) {
    $errors[] = 'Migration 38 — ' . htmlspecialchars($e->getMessage(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// ─────────────────────────────────────────────────────────────────────────────
// Migration 39: approved_by (TFL/AFL) on lstr_fanlisting_settings
// ─────────────────────────────────────────────────────────────────────────────
try {
    if (!(int) $pdo->query('SELECT COUNT(*) FROM `lstr_migrations` WHERE migration_number = 39')->fetchColumn()) {

        $colCheck = $pdo->query("SHOW COLUMNS FROM `lstr_fanlisting_settings` LIKE 'approved_by'")->fetchAll();
        if (empty($colCheck)) {
            $pdo->exec(
                "ALTER TABLE `lstr_fanlisting_settings`
                 ADD COLUMN `approved_by` ENUM('','tfl','tafl') NOT NULL DEFAULT ''"
            );
        }

        $pdo->exec('INSERT INTO `lstr_migrations` (migration_number) VALUES (39)');
        $done[] = 'Added <code>approved_by</code> column to <code>lstr_fanlisting_settings</code> (TFL/AFL approval tracking)';
    }
} catch (PDOException $e) {
    $errors[] = 'Migration 39 — ' . htmlspecialchars($e->getMessage(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// ─────────────────────────────────────────────────────────────────────────────
// Migration 40: link-check columns on lstr_affiliates and lstr_joined
// ─────────────────────────────────────────────────────────────────────────────
try {
    if (!(int) $pdo->query('SELECT COUNT(*) FROM `lstr_migrations` WHERE migration_number = 40')->fetchColumn()) {

        $affCols = $pdo->query("SHOW COLUMNS FROM `lstr_affiliates` LIKE 'link_status'")->fetchAll();
        if (empty($affCols)) {
            $pdo->exec(
                "ALTER TABLE `lstr_affiliates`
                 ADD COLUMN `link_status`           ENUM('unchecked','ok','dead','redirect') NOT NULL DEFAULT 'unchecked',
                 ADD COLUMN `link_checked_at`       DATETIME NULL,
                 ADD COLUMN `link_redirect_url`     VARCHAR(500) NULL,
                 ADD COLUMN `reciprocal_status`     ENUM('unchecked','found','missing') NOT NULL DEFAULT 'unchecked',
                 ADD COLUMN `reciprocal_checked_at` DATETIME NULL"
            );
        }

        $joinedCols = $pdo->query("SHOW COLUMNS FROM `lstr_joined` LIKE 'link_status'")->fetchAll();
        if (empty($joinedCols)) {
            $pdo->exec(
                "ALTER TABLE `lstr_joined`
                 ADD COLUMN `link_status`       ENUM('unchecked','ok','dead','redirect') NOT NULL DEFAULT 'unchecked',
                 ADD COLUMN `link_checked_at`   DATETIME NULL,
                 ADD COLUMN `link_redirect_url` VARCHAR(500) NULL"
            );
        }

        $pdo->exec('INSERT INTO `lstr_migrations` (migration_number) VALUES (40)');
        $done[] = 'Added link-check columns to <code>lstr_affiliates</code> and <code>lstr_joined</code>';
    }
} catch (PDOException $e) {
    $errors[] = 'Migration 40 — ' . htmlspecialchars($e->getMessage(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// Migration 41: affiliate application columns + per-listing apply settings
// ─────────────────────────────────────────────────────────────────────────────
try {
    if (!(int) $pdo->query('SELECT COUNT(*) FROM `lstr_migrations` WHERE migration_number = 41')->fetchColumn()) {

        $pendingCol = $pdo->query("SHOW COLUMNS FROM `lstr_affiliates` LIKE 'pending'")->fetchAll();
        if (empty($pendingCol)) {
            $pdo->exec(
                "ALTER TABLE `lstr_affiliates`
                 ADD COLUMN `pending`             TINYINT(1) NOT NULL DEFAULT 0,
                 ADD COLUMN `application_message` TEXT NULL"
            );
        }

        $applyCol = $pdo->query("SHOW COLUMNS FROM `lstr_fanlisting_settings` LIKE 'affiliate_apply_enabled'")->fetchAll();
        if (empty($applyCol)) {
            $pdo->exec(
                "ALTER TABLE `lstr_fanlisting_settings`
                 ADD COLUMN `affiliate_apply_enabled`      TINYINT(1)   NOT NULL DEFAULT 0,
                 ADD COLUMN `form_url_affiliate_apply`     VARCHAR(500) NOT NULL DEFAULT '',
                 ADD COLUMN `redirect_url_affiliate_apply` VARCHAR(500) NOT NULL DEFAULT ''"
            );
        }

        $pdo->exec('INSERT INTO `lstr_migrations` (migration_number) VALUES (41)');
        $done[] = 'Added affiliate application columns to <code>lstr_affiliates</code> and <code>lstr_fanlisting_settings</code>';
    }
} catch (PDOException $e) {
    $errors[] = 'Migration 41 — ' . htmlspecialchars($e->getMessage(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// ─────────────────────────────────────────────────────────────────────────────
// Migration 42: backfill any missing TFL/TAFL seed rows in lstr_categories.
//
// Fresh installs via install.php now seed these rows directly, but any install
// that went through migrations 7 and 9 before the CategorySeedCatalog was
// introduced has the correct data already.  This migration is intentionally
// INSERT-only — it never deletes or renames existing rows.
// ─────────────────────────────────────────────────────────────────────────────
try {
    if (!(int) $pdo->query('SELECT COUNT(*) FROM `lstr_migrations` WHERE migration_number = 42')->fetchColumn()) {
        $tableExists = (bool) $pdo->query("SHOW TABLES LIKE 'lstr_categories'")->fetchColumn();
        if ($tableExists) {
            $checkStmt  = $pdo->prepare(
                "SELECT COUNT(*) FROM lstr_categories WHERE source = :source AND name = :name"
            );
            $insertStmt = $pdo->prepare(
                "INSERT INTO lstr_categories (name, source, parent_id) VALUES (:name, :source, NULL)"
            );
            $seeded = 0;
            $seeds  = [
                'tfl'  => \Luster\Support\CategorySeedCatalog::tfl(),
                'tafl' => \Luster\Support\CategorySeedCatalog::tafl(),
            ];
            foreach ($seeds as $src => $names) {
                foreach ($names as $catName) {
                    $checkStmt->execute(['source' => $src, 'name' => $catName]);
                    if (!(int) $checkStmt->fetchColumn()) {
                        $insertStmt->execute(['name' => $catName, 'source' => $src]);
                        $seeded++;
                    }
                }
            }
        }
        $pdo->exec('INSERT INTO `lstr_migrations` (migration_number) VALUES (42)');
        $msg = $tableExists
            ? 'Backfilled ' . $seeded . ' missing TFL/TAFL category row(s) into <code>lstr_categories</code>'
            : 'Skipped TFL/TAFL seed — <code>lstr_categories</code> table not found';
        $done[] = $msg;
    }
} catch (PDOException $e) {
    $errors[] = 'Migration 42 — ' . htmlspecialchars($e->getMessage(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// ─────────────────────────────────────────────────────────────────────────────
// Migration 43: UNIQUE(source, name) index on lstr_categories.
//
// Prevents duplicate seed rows from multiple migrate.php runs or concurrent
// admin seeding.  Remaps FK references to the MIN(id) survivor before deleting
// duplicates, then adds the constraint via ALTER TABLE ... ADD UNIQUE KEY.
// ─────────────────────────────────────────────────────────────────────────────
try {
    if (!(int) $pdo->query('SELECT COUNT(*) FROM `lstr_migrations` WHERE migration_number = 43')->fetchColumn()) {
        $tableExists = (bool) $pdo->query("SHOW TABLES LIKE 'lstr_categories'")->fetchColumn();
        if ($tableExists) {
            // Derived table: one row per (source, name) group that has duplicates,
            // holding the MIN(id) that will survive.  Every remap below joins
            // against this subquery so that with N duplicates all non-MIN ids are
            // unconditionally updated to the single surviving id — no ambiguity.
            $survivorSub = "SELECT source, name, MIN(id) AS min_id
                            FROM lstr_categories
                            GROUP BY source, name
                            HAVING COUNT(*) > 1";

            $pdo->exec("
                UPDATE lstr_joined j
                INNER JOIN lstr_categories dup ON j.category_id = dup.id
                INNER JOIN ({$survivorSub}) survivors
                    ON survivors.source  = dup.source
                   AND survivors.name    = dup.name
                   AND survivors.min_id != dup.id
                SET j.category_id = survivors.min_id
            ");

            if ((bool) $pdo->query("SHOW TABLES LIKE 'owned'")->fetchColumn()) {
                $pdo->exec("
                    UPDATE owned o
                    INNER JOIN lstr_categories dup ON o.category_id = dup.id
                    INNER JOIN ({$survivorSub}) survivors
                        ON survivors.source  = dup.source
                       AND survivors.name    = dup.name
                       AND survivors.min_id != dup.id
                    SET o.category_id = survivors.min_id
                ");
            }

            if ((bool) $pdo->query("SHOW TABLES LIKE 'enth_fanlistings'")->fetchColumn()) {
                $pdo->exec("
                    UPDATE enth_fanlistings e
                    INNER JOIN lstr_categories dup ON e.category_id = dup.id
                    INNER JOIN ({$survivorSub}) survivors
                        ON survivors.source  = dup.source
                       AND survivors.name    = dup.name
                       AND survivors.min_id != dup.id
                    SET e.category_id = survivors.min_id
                ");
            }

            // Delete all non-surviving duplicates, keeping the lowest id per (source, name).
            $pdo->exec("
                DELETE c1 FROM lstr_categories c1
                INNER JOIN lstr_categories c2
                WHERE c1.id > c2.id
                  AND c1.source = c2.source
                  AND c1.name   = c2.name
            ");

            $pdo->exec(
                "ALTER TABLE lstr_categories ADD UNIQUE KEY uq_categories_source_name (source, name)"
            );
        }
        $pdo->exec('INSERT INTO `lstr_migrations` (migration_number) VALUES (43)');
        $done[] = $tableExists
            ? 'Added UNIQUE(source, name) index to <code>lstr_categories</code>'
            : 'Skipped — <code>lstr_categories</code> table not found';
    }
} catch (PDOException $e) {
    $errors[] = 'Migration 43 — ' . htmlspecialchars($e->getMessage(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// Migration 44: owner_name column on lstr_admin_users
// ─────────────────────────────────────────────────────────────────────────────
try {
    if (!(int) $pdo->query('SELECT COUNT(*) FROM `lstr_migrations` WHERE migration_number = 44')->fetchColumn()) {
        $col = $pdo->query("SHOW COLUMNS FROM `lstr_admin_users` LIKE 'owner_name'")->fetchAll();
        if (empty($col)) {
            $pdo->exec("ALTER TABLE `lstr_admin_users` ADD COLUMN `owner_name` VARCHAR(100) NULL AFTER `email`");
        }
        $pdo->exec('INSERT INTO `lstr_migrations` (migration_number) VALUES (44)');
        $done[] = 'Added <code>owner_name</code> column to <code>lstr_admin_users</code>';
    }
} catch (PDOException $e) {
    $errors[] = 'Migration 44 — ' . htmlspecialchars($e->getMessage(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// Migration 45: rename display_name → owner_name on lstr_admin_users
// ─────────────────────────────────────────────────────────────────────────────
try {
    if (!(int) $pdo->query('SELECT COUNT(*) FROM `lstr_migrations` WHERE migration_number = 45')->fetchColumn()) {
        $old = $pdo->query("SHOW COLUMNS FROM `lstr_admin_users` LIKE 'display_name'")->fetchAll();
        if (!empty($old)) {
            $pdo->exec("ALTER TABLE `lstr_admin_users` CHANGE `display_name` `owner_name` VARCHAR(100) NULL");
        }
        $pdo->exec('INSERT INTO `lstr_migrations` (migration_number) VALUES (45)');
        $done[] = 'Renamed <code>display_name</code> → <code>owner_name</code> on <code>lstr_admin_users</code>';
    }
} catch (PDOException $e) {
    $errors[] = 'Migration 45 — ' . htmlspecialchars($e->getMessage(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// Migration 46: rename owner_name → display_name on lstr_admin_users
// ─────────────────────────────────────────────────────────────────────────────
try {
    if (!(int) $pdo->query('SELECT COUNT(*) FROM `lstr_migrations` WHERE migration_number = 46')->fetchColumn()) {
        $col = $pdo->query("SHOW COLUMNS FROM `lstr_admin_users` LIKE 'owner_name'")->fetchAll();
        if (!empty($col)) {
            $pdo->exec("ALTER TABLE `lstr_admin_users` CHANGE `owner_name` `display_name` VARCHAR(100) NULL");
        }
        $pdo->exec('INSERT INTO `lstr_migrations` (migration_number) VALUES (46)');
        $done[] = 'Renamed <code>owner_name</code> → <code>display_name</code> on <code>lstr_admin_users</code>';
    }
} catch (PDOException $e) {
    $errors[] = 'Migration 46 — ' . htmlspecialchars($e->getMessage(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// Migration 47: drop parent_id column from lstr_categories
// ─────────────────────────────────────────────────────────────────────────────
try {
    if (!(int) $pdo->query('SELECT COUNT(*) FROM `lstr_migrations` WHERE migration_number = 47')->fetchColumn()) {
        $col = $pdo->query("SHOW COLUMNS FROM `lstr_categories` LIKE 'parent_id'")->fetchAll();
        if (!empty($col)) {
            $pdo->exec("ALTER TABLE `lstr_categories` DROP COLUMN `parent_id`");
        }
        $pdo->exec('INSERT INTO `lstr_migrations` (migration_number) VALUES (47)');
        $done[] = 'Dropped <code>parent_id</code> column from <code>lstr_categories</code>';
    }
} catch (PDOException $e) {
    $errors[] = 'Migration 47 — ' . htmlspecialchars($e->getMessage(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// ─────────────────────────────────────────────────────────────────────────────
// Build results HTML
// ─────────────────────────────────────────────────────────────────────────────

$doneCount   = count($done);
$errorCount  = count($errors);
$totalCount  = $doneCount + $errorCount;

ob_start();
if ($totalCount === 0): ?>
<div class="mig-card-body">
  <div class="mig-empty">
    <div class="mig-empty-ring">&#10003;</div>
    <div class="mig-empty-title">Schema is up to date</div>
    <div class="mig-empty-sub">Nothing to migrate &mdash; all migrations have already been applied.</div>
  </div>
</div>
<div class="mig-card-footer mig-card-footer--center">
  <a href="../admin" class="mig-back-link">&#8592; Return to admin panel</a>
</div>
<?php else: ?>
<div class="mig-card-head">
  <div class="mig-card-head-icon">&#128202;</div>
  <div>
    <div class="mig-card-head-title">Run Log</div>
    <div class="mig-card-head-sub"><?= $doneCount ?> applied &middot; <?= $errorCount ?> error<?= $errorCount !== 1 ? 's' : '' ?></div>
  </div>
</div>
<div class="mig-card-body">
  <?php if (!empty($errors)): ?>
    <div class="mig-section-label mig-section-label--err">&#9888;&nbsp; Errors</div>
    <div class="mig-list">
      <?php foreach ($errors as $i => $msg): ?>
        <div class="mig-item mig-item--err" style="animation-delay:<?= $i * .05 ?>s">
          <div class="mig-icon">&#10005;</div>
          <div class="mig-text"><?= $msg ?></div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <?php if (!empty($done)): ?>
    <div class="mig-section-label mig-section-label--ok">&#10003;&nbsp; Applied</div>
    <div class="mig-list">
      <?php foreach ($done as $i => $msg): ?>
        <div class="mig-item mig-item--ok" style="animation-delay:<?= ($i + count($errors)) * .05 ?>s">
          <div class="mig-icon">&#10003;</div>
          <div class="mig-text"><?= $msg ?></div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <div class="mig-summary">
    <?php if ($doneCount > 0): ?><span class="mig-badge mig-badge--ok">&#10003; <?= $doneCount ?> applied</span><?php endif; ?>
    <?php if ($errorCount > 0): ?><span class="mig-badge mig-badge--err">&#9888; <?= $errorCount ?> failed</span><?php endif; ?>
  </div>
</div>
<div class="mig-card-footer">
  <a href="../admin" class="mig-back-link">&#8592; Return to admin panel</a>
</div>
<?php endif;

$resultsHtml = ob_get_clean();
echo mig_page('Migration Results', 'Migration Results', 'All migrations have been processed.', $resultsHtml, false, $migSelectedSkin);
