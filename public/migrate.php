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
ini_set('session.cookie_secure', '1');
ini_set('session.use_strict_mode', '1');
ini_set('session.cookie_samesite', 'Strict');
session_name('luster_session');
session_start();

// ─── Shared page shell ────────────────────────────────────────────────────
/**
 * Returns a fully-styled Nebula-themed HTML page for the migration runner.
 *
 * @param string $title    <title> / topbar subtitle
 * @param string $content  Main card HTML
 */
function mig_page(string $title, string $content): string
{
    $h = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $titleSafe = $h($title);

    return <<<HTML
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>{$titleSafe} — Luster</title>
  <link rel="stylesheet" href="./assets/css/admin.css">
  <style>
    /* ── Migration runner — page-level overrides ─────────────── */
    body {
      display: block;
      min-height: 100vh;
      padding: 0;
      margin: 0;
    }

    .mig-topbar {
      position: sticky; top: 0; z-index: 50;
      display: flex; align-items: center; gap: 14px;
      padding: 0 28px;
      height: 58px;
      background: var(--bg-sidebar);
      border-bottom: 1px solid var(--border);
      box-shadow: 0 2px 16px rgba(0,0,0,0.35);
    }
    .mig-topbar-brand {
      font-family: 'Playfair Display', Georgia, serif;
      font-size: 1.25rem;
      font-weight: 700;
      color: var(--accent);
      letter-spacing: -0.01em;
      text-decoration: none;
    }
    .mig-topbar-sep {
      width: 1px; height: 20px;
      background: var(--border);
      flex-shrink: 0;
    }
    .mig-topbar-title {
      font-size: 0.85rem;
      color: var(--text-muted);
      font-weight: 400;
    }
    .mig-topbar-theme {
      margin-left: auto;
      background: none; border: 1px solid var(--border);
      color: var(--text-muted);
      border-radius: 8px;
      padding: 5px 10px;
      cursor: pointer;
      font-size: 0.8rem;
      font-family: 'DM Sans', sans-serif;
      transition: border-color 0.2s, color 0.2s;
    }
    .mig-topbar-theme:hover {
      border-color: var(--accent);
      color: var(--accent);
    }

    .mig-wrap {
      max-width: 680px;
      margin: 48px auto;
      padding: 0 20px 48px;
    }

    .mig-card {
      background: var(--bg-card);
      border: 1px solid var(--border);
      border-radius: 16px;
      padding: 32px 36px;
      box-shadow: var(--shadow);
      position: relative;
      overflow: hidden;
    }
    .mig-card::before {
      content: '';
      position: absolute; top: 0; left: 0;
      width: 4px; height: 100%;
      background: linear-gradient(180deg, var(--accent), var(--accent2));
      border-radius: 4px 0 0 4px;
    }

    .mig-heading {
      font-family: 'Playfair Display', Georgia, serif;
      font-size: 1.5rem;
      font-weight: 700;
      color: var(--text);
      margin-bottom: 6px;
    }
    .mig-sub {
      font-size: 0.875rem;
      color: var(--text-muted);
      margin-bottom: 24px;
      line-height: 1.5;
    }

    /* Warning callout */
    .mig-warn {
      display: flex; align-items: flex-start; gap: 12px;
      background: var(--warn-bg);
      border: 1px solid color-mix(in srgb, var(--warn) 40%, transparent);
      border-radius: 10px;
      padding: 14px 16px;
      margin-bottom: 24px;
    }
    .mig-warn-icon {
      font-size: 1rem; flex-shrink: 0;
      line-height: 1.5;
      color: var(--warn);
    }
    .mig-warn-text {
      font-size: 0.875rem;
      color: var(--text);
      line-height: 1.5;
    }
    .mig-warn-text strong { color: var(--warn); }

    /* Error callout */
    .mig-error-callout {
      display: flex; align-items: flex-start; gap: 12px;
      background: var(--danger-bg);
      border: 1px solid color-mix(in srgb, var(--danger) 40%, transparent);
      border-radius: 10px;
      padding: 14px 16px;
      margin-bottom: 24px;
    }
    .mig-error-callout-icon { font-size: 1rem; flex-shrink: 0; line-height: 1.5; color: var(--danger); }
    .mig-error-callout-text { font-size: 0.875rem; color: var(--text); line-height: 1.5; }

    /* Result items */
    .mig-results { display: flex; flex-direction: column; gap: 8px; margin: 20px 0; }

    .mig-item {
      display: flex; align-items: flex-start; gap: 12px;
      padding: 12px 14px;
      border-radius: 10px;
      border: 1px solid transparent;
      font-size: 0.875rem;
      line-height: 1.4;
    }
    .mig-item-icon {
      font-size: 0.85rem; flex-shrink: 0;
      width: 22px; height: 22px;
      display: flex; align-items: center; justify-content: center;
      border-radius: 50%;
      font-weight: 700;
    }
    .mig-item-text { color: var(--text); padding-top: 2px; }

    .mig-item--ok {
      background: var(--success-bg);
      border-color: color-mix(in srgb, var(--success) 30%, transparent);
    }
    .mig-item--ok .mig-item-icon {
      background: color-mix(in srgb, var(--success) 20%, transparent);
      color: var(--success);
    }

    .mig-item--err {
      background: var(--danger-bg);
      border-color: color-mix(in srgb, var(--danger) 30%, transparent);
    }
    .mig-item--err .mig-item-icon {
      background: color-mix(in srgb, var(--danger) 20%, transparent);
      color: var(--danger);
    }

    /* Section labels */
    .mig-section-label {
      display: flex; align-items: center; gap: 10px;
      font-size: 0.75rem; font-weight: 600; text-transform: uppercase;
      letter-spacing: 0.08em;
      margin-bottom: 10px; margin-top: 20px;
    }
    .mig-section-label::after {
      content: ''; flex: 1; height: 1px; background: var(--border);
    }
    .mig-section-label--ok  { color: var(--success); }
    .mig-section-label--err { color: var(--danger); }

    /* Empty state */
    .mig-empty {
      text-align: center;
      padding: 28px 0;
      color: var(--text-dim);
      font-size: 0.9rem;
    }
    .mig-empty-icon { font-size: 2rem; margin-bottom: 8px; opacity: 0.5; }

    /* Summary pill */
    .mig-summary {
      display: flex; gap: 12px; flex-wrap: wrap;
      margin-top: 24px; padding-top: 20px;
      border-top: 1px solid var(--border);
    }
    .mig-badge {
      display: inline-flex; align-items: center; gap: 6px;
      padding: 5px 12px;
      border-radius: 100px;
      font-size: 0.8rem; font-weight: 500;
    }
    .mig-badge--ok  { background: var(--success-bg); color: var(--success); }
    .mig-badge--err { background: var(--danger-bg);  color: var(--danger); }

    /* Back link */
    .mig-back {
      margin-top: 24px;
      font-size: 0.875rem;
    }
    .mig-back a {
      color: var(--accent);
      text-decoration: none;
      display: inline-flex; align-items: center; gap: 6px;
      transition: opacity 0.15s;
    }
    .mig-back a:hover { opacity: 0.75; }

    /* code */
    code {
      font-family: 'DM Mono', 'Courier New', monospace;
      font-size: 0.82em;
      background: var(--bg-subtle);
      color: var(--accent);
      padding: 1px 6px;
      border-radius: 4px;
      border: 1px solid var(--border-soft);
    }
  </style>
  <script>
    (function(){
      var t = localStorage.getItem('lstr_theme');
      if (t === 'dark' || t === 'light') document.documentElement.setAttribute('data-theme', t);
      else document.documentElement.setAttribute('data-theme', 'dark');
    })();
  </script>
</head>
<body>

<div class="mig-topbar">
  <a class="mig-topbar-brand" href="./admin">Luster</a>
  <div class="mig-topbar-sep"></div>
  <span class="mig-topbar-title">Database Migrations</span>
  <button class="mig-topbar-theme" onclick="(function(){var h=document.documentElement,t=h.getAttribute('data-theme')==='dark'?'light':'dark';h.setAttribute('data-theme',t);localStorage.setItem('lstr_theme',t);})()">
    Toggle theme
  </button>
</div>

<div class="mig-wrap">
  <div class="mig-card">
    {$content}
  </div>
</div>

</body>
</html>
HTML;
}

// ─── Access denied ────────────────────────────────────────────────────────
if (empty($_SESSION['lstr_admin_id'])) {
    http_response_code(403);
    echo mig_page('Access Denied', <<<HTML
<h1 class="mig-heading">Access Denied</h1>
<p class="mig-sub">You must be logged into the admin panel to run migrations.</p>
<div class="mig-error-callout">
  <div class="mig-error-callout-icon">&#128274;</div>
  <div class="mig-error-callout-text">No active admin session was detected. Please log in first, then return to this page.</div>
</div>
<div class="mig-back">
  <a href="./admin">&#8592; Go to admin login</a>
</div>
HTML);
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

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    // Show confirmation page — nothing runs on GET.
    echo mig_page('Run Migrations', <<<HTML
<h1 class="mig-heading">Run Migrations</h1>
<p class="mig-sub">Applies all pending database schema updates in sequence. Safe to re-run — already-applied migrations are skipped automatically.</p>
<div class="mig-warn">
  <div class="mig-warn-icon">&#9888;</div>
  <div class="mig-warn-text">
    <strong>Back up your database before continuing.</strong>
    While each migration is designed to be safe, a backup ensures you can recover if anything goes wrong.
  </div>
</div>
<form method="POST" action="">
  <input type="hidden" name="_csrf" value="{$tokenSafe}">
  <button type="submit" class="btn btn-primary">
    &#8635;&nbsp; Run migrations
  </button>
</form>
<div class="mig-back">
  <a href="./admin">&#8592; Cancel &mdash; return to admin panel</a>
</div>
HTML);
    exit;
}

// POST — validate CSRF token before running anything.
$submittedToken = (string) ($_POST['_csrf'] ?? '');
if (!hash_equals($migrateToken, $submittedToken)) {
    http_response_code(403);
    echo mig_page('Invalid Token', <<<HTML
<h1 class="mig-heading">Invalid Token</h1>
<p class="mig-sub">The security token was missing or expired.</p>
<div class="mig-error-callout">
  <div class="mig-error-callout-icon">&#128683;</div>
  <div class="mig-error-callout-text">Please go back to the migration page and try again. Tokens are single-use and expire with your session.</div>
</div>
<div class="mig-back">
  <a href="./migrate.php">&#8592; Back to migration page</a>
</div>
HTML);
    exit;
}

// Consume the token — one-time use.
unset($_SESSION['lstr_migrate_token']);

$configPath = dirname(__DIR__) . '/config/app.php';

if (!is_file($configPath)) {
    http_response_code(500);
    echo mig_page('Configuration Missing', <<<HTML
<h1 class="mig-heading">Configuration Missing</h1>
<p class="mig-sub">Luster is not yet installed.</p>
<div class="mig-error-callout">
  <div class="mig-error-callout-icon">&#128462;</div>
  <div class="mig-error-callout-text"><code>config/app.php</code> was not found. Run the installer first, then return to this page.</div>
</div>
<div class="mig-back">
  <a href="./install.php">&#8594; Go to installer</a>
</div>
HTML);
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
    $errMsg = htmlspecialchars($e->getMessage(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    http_response_code(500);
    echo mig_page('Database Error', <<<HTML
<h1 class="mig-heading">Database Connection Failed</h1>
<p class="mig-sub">Could not connect to the database. Check your <code>config/app.php</code> credentials.</p>
<div class="mig-error-callout">
  <div class="mig-error-callout-icon">&#128268;</div>
  <div class="mig-error-callout-text">{$errMsg}</div>
</div>
<div class="mig-back">
  <a href="./migrate.php">&#8592; Try again</a>
</div>
HTML);
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
// Build results HTML
// ─────────────────────────────────────────────────────────────────────────────

$doneCount   = count($done);
$errorCount  = count($errors);
$totalCount  = $doneCount + $errorCount;

ob_start();
?>
<h1 class="mig-heading">Migration Results</h1>
<p class="mig-sub">All migrations have been processed.</p>

<?php if ($totalCount === 0): ?>
  <div class="mig-empty">
    <div class="mig-empty-icon">&#10003;</div>
    <div>Nothing to migrate &mdash; your schema is already up to date.</div>
  </div>

<?php else: ?>

  <?php if (!empty($errors)): ?>
    <div class="mig-section-label mig-section-label--err">
      &#9888;&nbsp; Errors
    </div>
    <div class="mig-results">
      <?php foreach ($errors as $msg): ?>
        <div class="mig-item mig-item--err">
          <div class="mig-item-icon">&#10005;</div>
          <div class="mig-item-text"><?= $msg ?></div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <?php if (!empty($done)): ?>
    <div class="mig-section-label mig-section-label--ok">
      &#10003;&nbsp; Applied
    </div>
    <div class="mig-results">
      <?php foreach ($done as $msg): ?>
        <div class="mig-item mig-item--ok">
          <div class="mig-item-icon">&#10003;</div>
          <div class="mig-item-text"><?= $msg ?></div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <div class="mig-summary">
    <?php if ($doneCount > 0): ?>
      <span class="mig-badge mig-badge--ok">&#10003;&nbsp; <?= $doneCount ?> applied</span>
    <?php endif; ?>
    <?php if ($errorCount > 0): ?>
      <span class="mig-badge mig-badge--err">&#9888;&nbsp; <?= $errorCount ?> failed</span>
    <?php endif; ?>
  </div>

<?php endif; ?>

<div class="mig-back">
  <a href="./admin">&#8592; Return to admin panel</a>
</div>
<?php

$resultsHtml = ob_get_clean();
echo mig_page('Migration Results', $resultsHtml);
