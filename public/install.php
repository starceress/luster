<?php

declare(strict_types=1);

/**
 * Luster — browser-based installation wizard.
 *
 * This file is intentionally standalone: it does NOT use the Luster
 * framework, Composer autoloader, or config/app.php — none of which
 * exist before installation.
 *
 * Steps:
 *   1. System requirements check
 *   2. Database configuration (tests connection live)
 *   3. Site settings + first admin account → runs install → success
 *
 * Security:
 *   - CSRF via a per-session nonce in $_SESSION['lstr_install_token']
 *   - All user input validated before use
 *   - Passwords hashed with Argon2id
 *   - config/app.php written with var_export() — no code injection possible
 *   - File is not deleted after install (access blocked via "already installed"
 *     check); instruct users to delete install.php after completing setup.
 *
 * NOTE: exit() is used in this standalone file because it operates
 * outside the Application lifecycle. This is the only file where
 * exit() is acceptable.
 */

// -------------------------------------------------------------------------
// Bootstrap
// -------------------------------------------------------------------------

ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_secure', '1');
ini_set('session.cookie_samesite', 'Strict');
ini_set('session.use_strict_mode', '1');
session_start();

define('LUSTER_BASE', dirname(__DIR__));
define('CONFIG_PATH',   LUSTER_BASE . '/config/app.php');
define('SENTINEL_PATH', LUSTER_BASE . '/storage/.installed');

// -------------------------------------------------------------------------
// Already installed?
// Checked against a sentinel file in storage/ so that deleting config/app.php
// alone cannot re-open the installer and allow a takeover.
// -------------------------------------------------------------------------

if (is_file(SENTINEL_PATH) || is_file(CONFIG_PATH)) {
    renderAlreadyInstalled();
    exit;
}

// -------------------------------------------------------------------------
// DB-level re-installation guard.
// If the sentinel or config files were deleted but the database is already
// populated, refuse to re-run the installer regardless. This is an
// independent second lock so that file-system access alone cannot reopen
// the wizard against an existing installation.
// -------------------------------------------------------------------------

if (!empty($_SESSION['lstr_install_data']['db'])) {
    $_guard = $_SESSION['lstr_install_data']['db'];
    try {
        $_guardPdo = new PDO(
            "mysql:host={$_guard['host']};port={$_guard['port']};dbname={$_guard['name']};charset=utf8mb4",
            $_guard['user'],
            $_guard['pass'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 3]
        );
        $_tableCheck = $_guardPdo->query(
            "SELECT COUNT(*) FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'lstr_admin_users'"
        );
        if ($_tableCheck !== false && (int) $_tableCheck->fetchColumn() > 0) {
            $_countCheck = $_guardPdo->query('SELECT COUNT(*) FROM lstr_admin_users');
            if ($_countCheck !== false && (int) $_countCheck->fetchColumn() > 0) {
                renderAlreadyInstalled();
                exit;
            }
        }
        unset($_guardPdo, $_tableCheck, $_countCheck);
    } catch (\PDOException $e) {
        // Cannot connect yet — proceed; step 2 will re-validate credentials.
    }
    unset($_guard);
}

// -------------------------------------------------------------------------
// CSRF token
// -------------------------------------------------------------------------

if (empty($_SESSION['lstr_install_token'])) {
    $_SESSION['lstr_install_token'] = bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION['lstr_install_token'];

// -------------------------------------------------------------------------
// State
// -------------------------------------------------------------------------

$step   = (int) ($_SESSION['lstr_install_step'] ?? 1);
$errors = [];
$stored = $_SESSION['lstr_install_data'] ?? [];

// -------------------------------------------------------------------------
// Handle POST submissions
// -------------------------------------------------------------------------

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // CSRF check
    if (!hash_equals($csrfToken, (string) ($_POST['lstr_install_token'] ?? ''))) {
        $errors[] = 'Security token mismatch. Please refresh the page and try again.';
    } else {

        $postedStep = (int) ($_POST['install_step'] ?? 1);

        switch ($postedStep) {

            // ----------------------------------------------------------------
            // Step 1 → Step 2: requirements passed (button click only)
            // ----------------------------------------------------------------
            case 1:
                $_SESSION['lstr_install_step'] = 2;
                header('Location: install.php');
                exit;

            // ----------------------------------------------------------------
            // Step 2: validate DB credentials, test connection
            // ----------------------------------------------------------------
            case 2:
                $dbHost = trim((string) ($_POST['db_host'] ?? 'localhost'));
                $dbPort = (int) ($_POST['db_port'] ?? 3306);
                $dbName = trim((string) ($_POST['db_name'] ?? ''));
                $dbUser = trim((string) ($_POST['db_user'] ?? ''));
                $dbPass = (string) ($_POST['db_pass'] ?? '');

                if ($dbHost === '') {
                    $errors[] = 'Database host is required.';
                }
                if ($dbPort < 1 || $dbPort > 65535) {
                    $errors[] = 'Database port must be between 1 and 65535.';
                }
                if ($dbName === '') {
                    $errors[] = 'Database name is required.';
                }
                if ($dbUser === '') {
                    $errors[] = 'Database username is required.';
                }

                if (empty($errors)) {
                    try {
                        $testPdo = new PDO(
                            "mysql:host={$dbHost};port={$dbPort};dbname={$dbName};charset=utf8mb4",
                            $dbUser,
                            $dbPass,
                            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5]
                        );
                        unset($testPdo);

                        $stored['db'] = [
                            'host' => $dbHost,
                            'port' => $dbPort,
                            'name' => $dbName,
                            'user' => $dbUser,
                            'pass' => $dbPass,
                        ];
                        $_SESSION['lstr_install_data'] = $stored;
                        $_SESSION['lstr_install_step'] = 3;
                        header('Location: install.php');
                        exit;
                    } catch (\PDOException $e) {
                        $errors[] = 'Database connection failed: ' . $e->getMessage();
                    }
                }

                $step = 2;
                break;

            // ----------------------------------------------------------------
            // Step 3: site config + admin account → run install
            // ----------------------------------------------------------------
            case 3:
                $siteUrl               = rtrim(trim((string) ($_POST['site_url'] ?? '')), '/');
                $siteName              = trim((string) ($_POST['site_name'] ?? 'Luster'));
                $timezone             = trim((string) ($_POST['timezone'] ?? 'UTC'));
                $ownerName            = trim((string) ($_POST['owner_name']  ?? ''));
                $ownerEmail           = trim((string) ($_POST['owner_email'] ?? ''));
                $adminUsername        = trim((string) ($_POST['admin_username'] ?? ''));
                $adminEmail           = trim((string) ($_POST['admin_email'] ?? ''));
                $adminPassword        = (string) ($_POST['admin_password'] ?? '');
                $adminPasswordConfirm = (string) ($_POST['admin_password_confirm'] ?? '');

                if ($siteUrl === '') {
                    $errors[] = 'Site URL is required.';
                }
                if ($siteName === '') {
                    $errors[] = 'Site name is required.';
                }
                if (!in_array($timezone, timezone_identifiers_list(), true)) {
                    $errors[] = 'Invalid timezone selected.';
                }
                if ($adminUsername === '') {
                    $errors[] = 'Admin username is required.';
                } elseif (!preg_match('/^[a-zA-Z0-9_\-]{3,50}$/', $adminUsername)) {
                    $errors[] = 'Admin username must be 3–50 characters: letters, numbers, _ and - only.';
                }
                if (!filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) {
                    $errors[] = 'A valid admin email address is required.';
                }
                if (strlen($adminPassword) < 12) {
                    $errors[] = 'Password must be at least 12 characters.';
                }
                if ($adminPassword !== $adminPasswordConfirm) {
                    $errors[] = 'Passwords do not match.';
                }

                if (empty($errors)) {
                    $db = $stored['db'] ?? null;

                    if ($db === null) {
                        $errors[] = 'Session expired. Please start over.';
                        $_SESSION['lstr_install_step'] = 1;
                        unset($_SESSION['lstr_install_data']);
                        $step = 1;
                        break;
                    }

                    try {
                        $pdo = new PDO(
                            "mysql:host={$db['host']};port={$db['port']};dbname={$db['name']};charset=utf8mb4",
                            $db['user'],
                            $db['pass'],
                            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
                        );

                        // Hard DB guard: reject if lstr_admin_users already has rows.
                        // Catches the case where both sentinel and config were deleted
                        // but the database was untouched.
                        $existingTable = $pdo->query(
                            "SELECT COUNT(*) FROM information_schema.TABLES
                             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'lstr_admin_users'"
                        );
                        if ($existingTable !== false && (int) $existingTable->fetchColumn() > 0) {
                            $existingAdmin = $pdo->query('SELECT COUNT(*) FROM lstr_admin_users');
                            if ($existingAdmin !== false && (int) $existingAdmin->fetchColumn() > 0) {
                                throw new \RuntimeException(
                                    'Installation blocked: this database already contains admin accounts. ' .
                                    'Drop the lstr_* tables first, or restore the missing installation files.'
                                );
                            }
                        }

                        installTables($pdo);
                        installAdminUser($pdo, $adminUsername, $adminEmail, $adminPassword);
                        writeConfig($db, $siteName, $siteUrl, $timezone, $adminEmail, $ownerName, $ownerEmail);

                        // Write installation sentinel — prevents the installer from
                        // re-opening even if config/app.php is later deleted.
                        file_put_contents(SENTINEL_PATH, date('Y-m-d H:i:s') . "\n");

                        // Clear install session
                        unset(
                            $_SESSION['lstr_install_step'],
                            $_SESSION['lstr_install_data'],
                            $_SESSION['lstr_install_token']
                        );

                        $_SESSION['lstr_install_complete'] = true;
                        header('Location: install.php?done=1');
                        exit;
                    } catch (\Throwable $e) {
                        $errors[] = 'Installation failed: ' . $e->getMessage();
                    }
                }

                $step = 3;
                break;
        }
    }
}

// -------------------------------------------------------------------------
// Done page (after redirect)
// -------------------------------------------------------------------------

if (isset($_GET['done']) && !empty($_SESSION['lstr_install_complete'])) {
    unset($_SESSION['lstr_install_complete']);
    renderPage('done', [], $csrfToken, $step);
    exit;
}

// -------------------------------------------------------------------------
// Render current step
// -------------------------------------------------------------------------

renderPage($step === 1 ? 'req' : (string) $step, $errors, $csrfToken, $step, $stored);

// =========================================================================
// Functions
// =========================================================================

// -------------------------------------------------------------------------
// Installation logic
// -------------------------------------------------------------------------

function installTables(PDO $pdo): void
{
    $statements = [

        // ------------------------------------------------------------------
        // Core auth + security tables
        // ------------------------------------------------------------------

        "CREATE TABLE IF NOT EXISTS `lstr_admin_users` (
            `id`              INT UNSIGNED    NOT NULL AUTO_INCREMENT,
            `username`        VARCHAR(50)     NOT NULL,
            `email`           VARCHAR(255)    NOT NULL,
            `password`        VARCHAR(255)    NOT NULL,
            `failed_attempts` INT UNSIGNED    NOT NULL DEFAULT 0,
            `last_login`      DATETIME        NULL DEFAULT NULL,
            `created_at`      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at`      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uk_username` (`username`),
            UNIQUE KEY `uk_email`    (`email`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS `lstr_password_resets` (
            `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `admin_user_id` INT UNSIGNED NOT NULL,
            `token_hash`    VARCHAR(255) NOT NULL,
            `expires_at`    DATETIME     NOT NULL,
            `used`          TINYINT(1)   NOT NULL DEFAULT 0,
            `created_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_token_hash`   (`token_hash`),
            KEY `fk_pr_admin_user` (`admin_user_id`),
            CONSTRAINT `fk_pr_admin_user`
                FOREIGN KEY (`admin_user_id`) REFERENCES `lstr_admin_users` (`id`)
                ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS `lstr_rate_limits` (
            `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `identifier`    VARCHAR(255) NOT NULL,
            `action`        VARCHAR(100) NOT NULL,
            `attempts`      INT UNSIGNED NOT NULL DEFAULT 1,
            `last_attempt`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            `blocked_until` DATETIME     NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uk_identifier_action` (`identifier`, `action`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        // Admin remember-me tokens (M26).
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        // Installed add-on registry (M27).
        "CREATE TABLE IF NOT EXISTS `lstr_addons` (
            `addon_key`    VARCHAR(50) NOT NULL,
            `installed_at` DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`addon_key`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        // ------------------------------------------------------------------
        // Audit
        // ------------------------------------------------------------------

        "CREATE TABLE IF NOT EXISTS `lstr_activity_log` (
            `id`            INT UNSIGNED  NOT NULL AUTO_INCREMENT,
            `admin_user_id` INT UNSIGNED  NULL,
            `action`        VARCHAR(100)  NOT NULL,
            `description`   TEXT          NULL,
            `ip_address`    VARCHAR(45)   NULL,
            `created_at`    DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_admin_user_id` (`admin_user_id`),
            KEY `idx_action`        (`action`),
            KEY `idx_created_at`    (`created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS `lstr_error_log` (
            `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `level`      VARCHAR(20)     NOT NULL DEFAULT 'error',
            `message`    TEXT            NOT NULL,
            `source`     VARCHAR(255)    NULL,
            `created_at` DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            INDEX `idx_created` (`created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        // ------------------------------------------------------------------
        // Migration tracking
        // ------------------------------------------------------------------

        "CREATE TABLE IF NOT EXISTS `lstr_migrations` (
            `migration_number` INT UNSIGNED NOT NULL,
            `run_at`           DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`migration_number`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        // ------------------------------------------------------------------
        // Listing data
        // ------------------------------------------------------------------

        // Global key/value store for collective templates and site settings (M28).
        "CREATE TABLE IF NOT EXISTS `lstr_site_settings` (
            `setting_key`   VARCHAR(100) NOT NULL,
            `setting_value` TEXT         NULL,
            `updated_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
                                         ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`setting_key`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        // Admin-saved reusable email draft templates (M29).
        "CREATE TABLE IF NOT EXISTS `lstr_saved_emails` (
            `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `name`       VARCHAR(150) NOT NULL,
            `subject`    VARCHAR(255) NOT NULL DEFAULT '',
            `body`       TEXT         NOT NULL,
            `created_at` TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        // Affiliates: fanlisting_id nullable (NULL = collective/site-wide).
        // Full column set including M40 link-check and M41 application fields.
        "CREATE TABLE IF NOT EXISTS `lstr_affiliates` (
            `id`                    INT UNSIGNED  NOT NULL AUTO_INCREMENT,
            `fanlisting_id`         INT UNSIGNED  NULL DEFAULT NULL,
            `name`                  VARCHAR(255)  NOT NULL,
            `url`                   VARCHAR(500)  NOT NULL,
            `email`                 VARCHAR(255)  NULL,
            `image`                 VARCHAR(255)  NULL,
            `sort_order`            INT UNSIGNED  NOT NULL DEFAULT 0,
            `active`                TINYINT(1)    NOT NULL DEFAULT 1,
            `created_at`            DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at`            DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            `link_status`           ENUM('unchecked','ok','dead','redirect') NOT NULL DEFAULT 'unchecked',
            `link_checked_at`       DATETIME      NULL,
            `link_redirect_url`     VARCHAR(500)  NULL,
            `reciprocal_status`     ENUM('unchecked','found','missing')      NOT NULL DEFAULT 'unchecked',
            `reciprocal_checked_at` DATETIME      NULL,
            `pending`               TINYINT(1)    NOT NULL DEFAULT 0,
            `application_message`   TEXT          NULL,
            PRIMARY KEY (`id`),
            KEY `idx_fanlisting_id` (`fanlisting_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        // Per-listing Luster settings — full column set (all migrations merged through 42).
        "CREATE TABLE IF NOT EXISTS `lstr_fanlisting_settings` (
            `fanlisting_id`                INT UNSIGNED          NOT NULL,
            `auto_approve`                 TINYINT(1)            NOT NULL DEFAULT 0,
            `stats_template`               TEXT                  NULL DEFAULT NULL,
            `members_template`             TEXT                  NULL DEFAULT NULL,
            `affiliates_template`          TEXT                  NULL DEFAULT NULL,
            `image`                        VARCHAR(255)          NULL DEFAULT NULL,
            `listing_type`                 VARCHAR(50)           NULL DEFAULT NULL,
            `members_per_page`             TINYINT UNSIGNED      NULL DEFAULT NULL,
            `country_field_enabled`        TINYINT(1)            NOT NULL DEFAULT 1,
            `redirect_url_join`            VARCHAR(500)          NOT NULL DEFAULT '',
            `redirect_url_update`          VARCHAR(500)          NOT NULL DEFAULT '',
            `redirect_url_remove`          VARCHAR(500)          NOT NULL DEFAULT '',
            `form_url_join`                VARCHAR(500)          NOT NULL DEFAULT '',
            `form_url_update`              VARCHAR(500)          NOT NULL DEFAULT '',
            `form_url_remove`              VARCHAR(500)          NOT NULL DEFAULT '',
            `form_url_reset`               VARCHAR(500)          NOT NULL DEFAULT '',
            `redirect_url_reset`           VARCHAR(500)          NOT NULL DEFAULT '',
            `last_updated`                 DATE                  NULL DEFAULT NULL,
            `queue_updates`                TINYINT(1)            NOT NULL DEFAULT 0,
            `contact_enabled`              TINYINT(1)            NOT NULL DEFAULT 1,
            `contact_recipient`            VARCHAR(255)          NULL DEFAULT NULL,
            `form_url_codes_submit`        VARCHAR(200)          NOT NULL DEFAULT '',
            `approved_by`                  ENUM('','tfl','tafl') NOT NULL DEFAULT '',
            `affiliate_apply_enabled`      TINYINT(1)            NOT NULL DEFAULT 0,
            `form_url_affiliate_apply`     VARCHAR(500)          NOT NULL DEFAULT '',
            `redirect_url_affiliate_apply` VARCHAR(500)          NOT NULL DEFAULT '',
            `db_host`                      VARCHAR(255)          NOT NULL DEFAULT '',
            `db_name`                      VARCHAR(255)          NOT NULL DEFAULT '',
            `db_user`                      VARCHAR(255)          NOT NULL DEFAULT '',
            `db_pass`                      VARCHAR(500)          NOT NULL DEFAULT '',
            `updated_at`                   DATETIME              NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`fanlisting_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        // Codes subsystem — lstr_code_sizes and lstr_code_donors must precede
        // lstr_codes because lstr_codes carries FK constraints to both.

        "CREATE TABLE IF NOT EXISTS `lstr_code_sizes` (
            `id`            INT UNSIGNED      NOT NULL AUTO_INCREMENT,
            `fanlisting_id` INT UNSIGNED      NOT NULL,
            `label`         VARCHAR(100)      NOT NULL,
            `width`         SMALLINT UNSIGNED NULL,
            `height`        SMALLINT UNSIGNED NULL,
            `sort_order`    SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (`id`),
            INDEX `idx_fanlisting` (`fanlisting_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS `lstr_code_donors` (
            `id`            INT UNSIGNED  NOT NULL AUTO_INCREMENT,
            `fanlisting_id` INT UNSIGNED  NOT NULL,
            `name`          VARCHAR(150)  NOT NULL,
            `email`         VARCHAR(255)  NULL,
            `url`           VARCHAR(500)  NULL,
            PRIMARY KEY (`id`),
            INDEX `idx_fanlisting` (`fanlisting_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        // Codes (button images) — full column set through M36.
        "CREATE TABLE IF NOT EXISTS `lstr_codes` (
            `id`              INT UNSIGNED      NOT NULL AUTO_INCREMENT,
            `fanlisting_id`   INT UNSIGNED      NOT NULL,
            `filename`        VARCHAR(255)      NOT NULL,
            `name`            VARCHAR(150)      NULL DEFAULT NULL,
            `width`           SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            `height`          SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            `size_id`         INT UNSIGNED      NULL,
            `donor_id`        INT UNSIGNED      NULL,
            `status`          ENUM('approved','pending','rejected') NOT NULL DEFAULT 'approved',
            `submitter_name`  VARCHAR(150)      NULL DEFAULT NULL,
            `submitter_email` VARCHAR(255)      NULL DEFAULT NULL,
            `submitter_url`   VARCHAR(255)      NULL DEFAULT NULL,
            `sort_order`      SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            `created_at`      DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            INDEX `idx_fanlisting`        (`fanlisting_id`),
            INDEX `idx_fanlisting_sort`   (`fanlisting_id`, `sort_order`, `id`),
            INDEX `idx_lstr_codes_status` (`fanlisting_id`, `status`),
            CONSTRAINT `fk_lstr_codes_size`
                FOREIGN KEY (`size_id`)  REFERENCES `lstr_code_sizes`  (`id`) ON DELETE SET NULL,
            CONSTRAINT `fk_lstr_codes_donor`
                FOREIGN KEY (`donor_id`) REFERENCES `lstr_code_donors` (`id`) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS `lstr_code_category_map` (
            `code_id`     INT UNSIGNED NOT NULL,
            `category_id` INT UNSIGNED NOT NULL,
            PRIMARY KEY (`code_id`, `category_id`),
            CONSTRAINT `fk_lstr_ccmap_code`
                FOREIGN KEY (`code_id`)     REFERENCES `lstr_codes`           (`id`) ON DELETE CASCADE,
            CONSTRAINT `fk_lstr_ccmap_cat`
                FOREIGN KEY (`category_id`) REFERENCES `lstr_code_categories` (`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        // Per-listing transactional email templates.
        "CREATE TABLE IF NOT EXISTS `lstr_email_templates` (
            `id`            INT UNSIGNED  NOT NULL AUTO_INCREMENT,
            `fanlisting_id` INT UNSIGNED  NOT NULL,
            `type`          VARCHAR(20)   NOT NULL,
            `enabled`       TINYINT(1)    NOT NULL DEFAULT 1,
            `subject`       VARCHAR(255)  NOT NULL DEFAULT '',
            `body`          TEXT          NOT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_fanlisting_type` (`fanlisting_id`, `type`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        // Fan-facing password reset tokens.
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        // Member-submitted updates awaiting admin review.
        "CREATE TABLE IF NOT EXISTS `lstr_pending_updates` (
            `id`            INT UNSIGNED  NOT NULL AUTO_INCREMENT,
            `fanlisting_id` INT UNSIGNED  NOT NULL,
            `member_email`  VARCHAR(255)  NOT NULL,
            `changes`       TEXT          NOT NULL,
            `submitted_at`  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            INDEX `idx_fanlisting` (`fanlisting_id`),
            INDEX `idx_email`      (`member_email`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        // ------------------------------------------------------------------
        // Collective data
        // ------------------------------------------------------------------

        // Categories (TFL/TAFL/custom) — source VARCHAR(50) from the start.
        "CREATE TABLE IF NOT EXISTS `lstr_categories` (
            `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `name`       VARCHAR(150) NOT NULL,
            `parent_id`  INT UNSIGNED NULL,
            `source`     VARCHAR(50)  NOT NULL DEFAULT 'custom',
            `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        // Spam / email blacklist.
        "CREATE TABLE IF NOT EXISTS `lstr_blacklist` (
            `id`         INT UNSIGNED  NOT NULL AUTO_INCREMENT,
            `pattern`    VARCHAR(255)  NOT NULL,
            `note`       VARCHAR(500)  NOT NULL DEFAULT '',
            `created_at` DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_pattern` (`pattern`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        // Joined listings tracker — all columns from migrations 4, 7, 15, 38, 40.
        "CREATE TABLE IF NOT EXISTS `lstr_joined` (
            `id`               INT UNSIGNED  NOT NULL AUTO_INCREMENT,
            `name`             VARCHAR(255)  NOT NULL,
            `url`              VARCHAR(500)  NOT NULL,
            `image`            VARCHAR(255)  NULL,
            `category`         VARCHAR(100)  NOT NULL DEFAULT '',
            `categories_json`  TEXT          NULL,
            `date_joined`      DATE          NULL,
            `active`           TINYINT(1)    NOT NULL DEFAULT 1,
            `status`           VARCHAR(20)   NOT NULL DEFAULT 'approved',
            `sort_order`       INT           NOT NULL DEFAULT 0,
            `category_id`      INT UNSIGNED  NULL,
            `created_at`       DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `link_status`      ENUM('unchecked','ok','dead','redirect') NOT NULL DEFAULT 'unchecked',
            `link_checked_at`  DATETIME      NULL,
            `link_redirect_url` VARCHAR(500) NULL,
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

    ];

    foreach ($statements as $statement) {
        $pdo->exec($statement);
    }

    // Seed the official TFL and TAFL category lists so fresh installs have a
    // populated category table without needing to run migrate.php.
    require_once __DIR__ . '/../src/Support/CategorySeedCatalog.php';
    $catCheck  = $pdo->prepare(
        "SELECT COUNT(*) FROM lstr_categories WHERE source = :source AND name = :name"
    );
    $catInsert = $pdo->prepare(
        "INSERT INTO lstr_categories (name, source, parent_id) VALUES (:name, :source, NULL)"
    );
    $seedData = [
        'tfl'  => \Luster\Support\CategorySeedCatalog::tfl(),
        'tafl' => \Luster\Support\CategorySeedCatalog::tafl(),
    ];
    foreach ($seedData as $src => $names) {
        foreach ($names as $catName) {
            $catCheck->execute(['source' => $src, 'name' => $catName]);
            if (!(int) $catCheck->fetchColumn()) {
                $catInsert->execute(['name' => $catName, 'source' => $src]);
            }
        }
    }

    // Mark all migrations as already run so that migrate.php treats this
    // install as fully up-to-date.  The schema created above already
    // incorporates every column and table added by migrations 1–42.
    $pdo->exec(
        'INSERT IGNORE INTO `lstr_migrations` (migration_number) VALUES
         (1),(2),(3),(4),(5),(6),(7),(8),(9),(10),(11),(12),(13),
         (14),(15),(16),(17),(18),(19),(20),(21),(22),(23),(24),
         (25),(26),(27),(28),(29),(30),(31),(32),(33),(34),(35),
         (36),(37),(38),(39),(40),(41),(42)'
    );
}

function installAdminUser(PDO $pdo, string $username, string $email, string $password): void
{
    $hash = password_hash($password, PASSWORD_ARGON2ID);

    $stmt = $pdo->prepare(
        'INSERT INTO `lstr_admin_users` (`username`, `email`, `password`) VALUES (:username, :email, :password)'
    );
    $stmt->execute(['username' => $username, 'email' => $email, 'password' => $hash]);
}

function writeConfig(array $db, string $siteName, string $siteUrl, string $timezone, string $adminEmail, string $ownerName = '', string $ownerEmail = ''): void
{
    $config = [
        // Database
        'db_host'    => $db['host'],
        'db_port'    => $db['port'],
        'db_name'    => $db['name'],
        'db_user'    => $db['user'],
        'db_pass'    => $db['pass'],
        'db_charset' => 'utf8mb4',
        // Application
        'site_name'  => $siteName,
        'site_url'   => $siteUrl,
        'app_debug'  => false,
        // Site owner identity (shown in email templates)
        'owner_name'  => $ownerName,
        'owner_email' => $ownerEmail !== '' ? $ownerEmail : $adminEmail,
        // Email (SMTP)
        'smtp_host'      => '',
        'smtp_port'      => 587,
        'smtp_user'      => '',
        'smtp_pass'      => '',
        'smtp_from'      => $adminEmail,
        'smtp_from_name' => $siteName,
        'smtp_secure'    => 'tls',
        // Enthusiast compatibility
        'enth_table_prefix' => '',
        // Miscellaneous
        'timezone' => $timezone,
        'per_page' => 25,
        // Staleness detection
        'inactive_threshold_days' => 60,
        'last_updated_fallback'   => 'Never',
        // Embed & Paths
        'install_path'          => LUSTER_BASE,
        'session_cookie_domain' => '',
        'embed_base_url'        => '',
    ];

    $exported = var_export($config, true);
    $content  = "<?php\n\ndeclare(strict_types=1);\n\n// Generated by Luster installer. Do NOT commit this file.\nreturn {$exported};\n";

    if (file_put_contents(CONFIG_PATH, $content) === false) {
        throw new \RuntimeException(
            'Could not write config/app.php. '
            . 'Ensure the web server has write permission on the config/ directory.'
        );
    }
}

// -------------------------------------------------------------------------
// Requirements check helpers
// -------------------------------------------------------------------------

function checkRequirements(): array
{
    $checks = [];

    // PHP version
    $phpOk = version_compare(PHP_VERSION, '8.2.0', '>=');
    $checks[] = [
        'label'  => 'PHP version ≥ 8.2 (found ' . PHP_VERSION . ')',
        'status' => $phpOk ? 'ok' : 'fail',
    ];

    // PDO + MySQL driver
    $checks[] = [
        'label'  => 'PDO extension',
        'status' => extension_loaded('pdo') ? 'ok' : 'fail',
    ];
    $checks[] = [
        'label'  => 'PDO MySQL driver',
        'status' => extension_loaded('pdo_mysql') ? 'ok' : 'fail',
    ];

    // Other required extensions
    foreach (['mbstring', 'openssl', 'json', 'filter', 'fileinfo'] as $ext) {
        $checks[] = [
            'label'  => "PHP extension: {$ext}",
            'status' => extension_loaded($ext) ? 'ok' : 'fail',
        ];
    }

    // config/ writable
    $configDir = LUSTER_BASE . '/config';
    $checks[] = [
        'label'  => 'config/ directory is writable',
        'status' => is_writable($configDir) ? 'ok' : 'fail',
    ];

    // storage/ writable
    $storageDir = LUSTER_BASE . '/storage';
    $checks[] = [
        'label'  => 'storage/ directory is writable',
        'status' => is_writable($storageDir) ? 'ok' : 'warn',
    ];

    // Composer autoloader present
    $checks[] = [
        'label'  => 'Composer dependencies installed (vendor/autoload.php)',
        'status' => is_file(LUSTER_BASE . '/vendor/autoload.php') ? 'ok' : 'warn',
    ];

    return $checks;
}

function requirementsPass(array $checks): bool
{
    foreach ($checks as $c) {
        if ($c['status'] === 'fail') {
            return false;
        }
    }

    return true;
}

// -------------------------------------------------------------------------
// HTML rendering
// -------------------------------------------------------------------------

function e(string $v): string
{
    return htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function renderAlreadyInstalled(): void
{
    renderHeader('Already Installed', 0);
    ?>
<div class="install-card" style="text-align:center">
  <div style="font-size:2.5rem;margin-bottom:1rem;color:var(--warn)">&#9888;</div>
  <h2>Already Installed</h2>
  <p style="color:var(--text-muted);margin:.5rem 0 1.75rem">
    Luster is already installed. To reinstall from scratch, delete both
    <code style="background:var(--bg-subtle);padding:.1rem .4rem;border-radius:5px;font-size:.85em">storage/.installed</code>
    and
    <code style="background:var(--bg-subtle);padding:.1rem .4rem;border-radius:5px;font-size:.85em">config/app.php</code>.
  </p>
  <div style="display:flex;gap:12px;justify-content:center;flex-wrap:wrap">
    <a href="./" class="btn btn-ghost">Home</a>
    <a href="./admin.php" class="btn btn-primary">Go to Admin Panel &rarr;</a>
  </div>
</div>
    <?php
    renderFooter();
}

function renderPage(string $view, array $errors, string $csrf, int $step, array $stored = []): void
{
    $titles = [
        'req'  => 'Step 1: Requirements',
        '2'    => 'Step 2: Database',
        '3'    => 'Step 3: Site &amp; Admin',
        'done' => 'Installation Complete',
    ];
    $title = $titles[$view] ?? 'Luster Installer';

    renderHeader($title, $step);

    // Error list
    if (!empty($errors)) {
        echo '<div class="alert alert-error" role="alert"><strong>Please fix the following:</strong><ul style="margin:.5rem 0 0 1.2rem">';
        foreach ($errors as $err) {
            echo '<li>' . e($err) . '</li>';
        }
        echo '</ul></div>';
    }

    match ($view) {
        'req'  => renderStep1($csrf),
        '2'    => renderStep2($csrf, $stored),
        '3'    => renderStep3($csrf, $stored),
        'done' => renderDone(),
        default => null,
    };

    renderFooter();
}

function renderHeader(string $title, int $step): void
{
    require_once __DIR__ . '/../templates/admin/_css_assets.php';
    ?>
<!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= e($title) ?> &mdash; Luster Installer</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@700&family=DM+Sans:wght@400;500;600&family=DM+Mono&display=swap" rel="stylesheet">
<?= lusterAdminCssLinks('./assets/css/admin', __DIR__ . '/assets/css/admin', null, true) . PHP_EOL ?>
<script>
(function(){
  var t=localStorage.getItem('lstr_theme')||'dark';
  document.documentElement.setAttribute('data-theme',t);
})();
</script>
<style>
/* ── Installer-specific layout ── */
.install-page {
  min-height: 100vh;
  display: flex;
  align-items: flex-start;
  justify-content: center;
  padding: 3rem 1rem 5rem;
  background: var(--bg);
  font-family: 'DM Sans', sans-serif;
  flex-direction: column;
}
.install-wrap {
  width: 100%;
  max-width: 580px;
  margin: 0 auto;
}
/* Brand */
.install-brand {
  text-align: center;
  margin-bottom: 2rem;
}
.install-gem {
  width: 40px; height: 40px; margin: 0 auto 0.75rem;
  background: linear-gradient(135deg, #ff7eb3 0%, #78e8d8 35%, #ffd166 65%, #70e098 100%);
  clip-path: polygon(50% 0%, 100% 35%, 80% 100%, 20% 100%, 0% 35%);
  animation: iGemFloat 6s ease-in-out infinite;
}
@keyframes iGemFloat {
  0%,100% { transform: translateY(0) rotate(0deg); }
  33%     { transform: translateY(-5px) rotate(5deg); }
  66%     { transform: translateY(-2px) rotate(-3deg); }
}
.install-brand-name {
  font-family: 'Playfair Display', serif;
  font-size: 2rem; font-weight: 700; line-height: 1;
  background: linear-gradient(135deg, #f4f0ff 0%, #ff7eb3 55%, #78e8d8 100%);
  -webkit-background-clip: text; -webkit-text-fill-color: transparent; background-clip: text;
  letter-spacing: 0.01em;
}
[data-theme="light"] .install-brand-name {
  background: linear-gradient(135deg, #160f2a 0%, #d4006a 55%, #008b80 100%);
  -webkit-background-clip: text; -webkit-text-fill-color: transparent; background-clip: text;
}
.install-brand-sub {
  font-size: 0.7rem; color: var(--text-dim);
  letter-spacing: 0.2em; text-transform: uppercase; margin-top: 6px;
}
/* Step indicator */
.install-steps {
  display: flex;
  align-items: center;
  margin-bottom: 1.75rem;
}
.i-step {
  display: flex;
  align-items: center;
  gap: 8px;
  flex-shrink: 0;
}
.i-step-num {
  width: 28px; height: 28px; border-radius: 50%;
  display: flex; align-items: center; justify-content: center;
  font-size: 0.72rem; font-weight: 700; flex-shrink: 0;
  border: 1px solid var(--border);
  background: var(--bg-subtle); color: var(--text-dim);
  transition: all 0.3s;
}
.i-step-label { font-size: 0.75rem; color: var(--text-dim); font-weight: 500; white-space: nowrap; }
.i-connector { flex: 1; height: 2px; background: var(--border); margin: 0 8px; transition: background 0.3s; }
.i-step.done .i-step-num {
  background: var(--success-bg); border-color: var(--success); color: var(--success);
}
.i-step.done .i-step-label { color: var(--success); }
.i-step.done + .i-connector { background: var(--success); }
.i-step.active .i-step-num {
  background: var(--accent-glow); border-color: var(--accent); color: var(--accent);
  box-shadow: 0 0 0 3px var(--accent-glow);
}
.i-step.active .i-step-label { color: var(--text); font-weight: 600; }
/* Card */
.install-card {
  background: var(--bg-card);
  border: 1px solid var(--border);
  border-radius: 16px;
  padding: 2rem 2.25rem;
  box-shadow: var(--shadow-lg);
  margin-bottom: 1rem;
}
.install-card h2 {
  font-size: 1.05rem; font-weight: 700;
  color: var(--text); margin-bottom: 1.5rem; line-height: 1;
}
/* Section dividers within a card */
.install-section {
  font-size: 0.65rem; font-weight: 700;
  letter-spacing: 0.18em; text-transform: uppercase;
  color: var(--text-dim); margin: 1.75rem 0 1.25rem;
  display: flex; align-items: center; gap: 8px;
}
.install-section::after {
  content: ''; flex: 1; height: 1px; background: var(--border);
}
/* Requirements list */
.req-list { list-style: none; }
.req-list li {
  display: flex; align-items: center; gap: 10px;
  padding: 9px 0; border-bottom: 1px solid var(--border-soft);
  font-size: 0.85rem; color: var(--text-muted);
}
.req-list li:last-child { border-bottom: none; }
.req-badge {
  display: inline-flex; align-items: center; justify-content: center;
  padding: 2px 8px; border-radius: 6px; font-size: 0.68rem; font-weight: 700;
  min-width: 44px; border: 1px solid transparent; flex-shrink: 0;
}
.req-badge.ok   { background: var(--success-bg); color: var(--success); border-color: rgba(52,211,153,.2); }
.req-badge.fail { background: var(--danger-bg);  color: var(--danger);  border-color: rgba(248,113,113,.2); }
.req-badge.warn { background: var(--warn-bg);    color: var(--warn);    border-color: rgba(251,191,36,.2); }
/* Success / done */
.install-success-icon { font-size: 3rem; color: var(--success); margin-bottom: 1rem; }
/* Footer hint */
.install-footer {
  font-size: 0.78rem; color: var(--text-dim);
  text-align: center; margin-top: 0.75rem; line-height: 1.5;
}
/* Theme toggle (fixed) */
.install-theme-btn {
  position: fixed; top: 16px; right: 16px;
  background: var(--bg-card); border: 1px solid var(--border);
  border-radius: 8px; padding: 6px 10px; cursor: pointer;
  color: var(--text-muted); font-size: 0.9rem; line-height: 1;
  transition: border-color 0.2s, color 0.2s;
  font-family: 'DM Sans', sans-serif;
  z-index: 200;
}
.install-theme-btn:hover { border-color: var(--accent); color: var(--accent); }
</style>
</head>
<body class="install-page">

<button class="install-theme-btn" id="installThemeBtn" aria-label="Toggle theme">
  <span id="installThemeIcon">&#9728;</span>
</button>

<div class="install-wrap">

  <div class="install-brand">
    <div class="install-gem"></div>
    <div class="install-brand-name">Luster</div>
    <div class="install-brand-sub">Installation Wizard</div>
  </div>

<?php if ($step >= 1 && $step <= 3):
    $s = static function (int $current, int $n): string {
        if ($n < $current) return 'done';
        if ($n === $current) return 'active';
        return '';
    };
?>
<div class="install-steps">
  <div class="i-step <?= $s($step, 1) ?>">
    <div class="i-step-num"><?= $step > 1 ? '&#10003;' : '1' ?></div>
    <div class="i-step-label">Requirements</div>
  </div>
  <div class="i-connector"></div>
  <div class="i-step <?= $s($step, 2) ?>">
    <div class="i-step-num"><?= $step > 2 ? '&#10003;' : '2' ?></div>
    <div class="i-step-label">Database</div>
  </div>
  <div class="i-connector"></div>
  <div class="i-step <?= $s($step, 3) ?>">
    <div class="i-step-num">3</div>
    <div class="i-step-label">Site &amp; Admin</div>
  </div>
</div>
<?php endif; ?>
    <?php
}

function renderFooter(): void
{
    ?>
</div>

<script>
(function(){
  var btn  = document.getElementById('installThemeBtn');
  var icon = document.getElementById('installThemeIcon');
  function updateIcon(t) { icon.innerHTML = t === 'dark' ? '&#9728;' : '&#9790;'; }
  updateIcon(document.documentElement.getAttribute('data-theme') || 'dark');
  btn.addEventListener('click', function(){
    var cur  = document.documentElement.getAttribute('data-theme') || 'dark';
    var next = cur === 'dark' ? 'light' : 'dark';
    document.documentElement.setAttribute('data-theme', next);
    localStorage.setItem('lstr_theme', next);
    updateIcon(next);
  });
})();
</script>
</body>
</html>
    <?php
}

function renderStep1(string $csrf): void
{
    $checks     = checkRequirements();
    $canContinue = requirementsPass($checks);
    ?>
<div class="install-card">
  <h2>System Requirements</h2>
  <ul class="req-list">
    <?php foreach ($checks as $c): ?>
    <li>
      <span class="req-badge <?= e($c['status']) ?>"><?= strtoupper(e($c['status'])) ?></span>
      <?= e($c['label']) ?>
    </li>
    <?php endforeach; ?>
  </ul>
</div>

<?php if (!$canContinue): ?>
<div class="alert alert-error">
  One or more requirements have <strong>FAIL</strong> status. Resolve them before continuing.
</div>
<?php else: ?>
<div class="alert alert-success">All requirements met. You may continue.</div>
<form method="post" action="install.php">
  <input type="hidden" name="lstr_install_token" value="<?= e($csrf) ?>">
  <input type="hidden" name="install_step" value="1">
  <button type="submit" class="btn btn-primary">Continue to Database Setup &rarr;</button>
</form>
<?php endif; ?>
    <?php
}

function renderStep2(string $csrf, array $stored): void
{
    $db = $stored['db'] ?? [];
    ?>
<div class="install-card">
  <h2>Database Configuration</h2>
  <form method="post" action="install.php">
    <input type="hidden" name="lstr_install_token" value="<?= e($csrf) ?>">
    <input type="hidden" name="install_step" value="2">

    <div class="form-row">
      <div class="form-group">
        <label class="form-label" for="db_host">Database Host</label>
        <input type="text" id="db_host" name="db_host" class="form"
               value="<?= e((string) ($db['host'] ?? 'localhost')) ?>" required>
      </div>
      <div class="form-group">
        <label class="form-label" for="db_port">Port</label>
        <input type="number" id="db_port" name="db_port" class="form"
               min="1" max="65535"
               value="<?= e((string) ($db['port'] ?? '3306')) ?>" required>
      </div>
    </div>

    <div class="form-group">
      <label class="form-label" for="db_name">Database Name</label>
      <input type="text" id="db_name" name="db_name" class="form"
             value="<?= e((string) ($db['name'] ?? '')) ?>" required>
      <p class="form-hint">The database must already exist. It can be your existing Enthusiast database or a fresh empty one.</p>
    </div>

    <div class="form-group">
      <label class="form-label" for="db_user">Database Username</label>
      <input type="text" id="db_user" name="db_user" class="form"
             value="<?= e((string) ($db['user'] ?? '')) ?>" required autocomplete="username">
    </div>

    <div class="form-group">
      <label class="form-label" for="db_pass">Database Password</label>
      <input type="password" id="db_pass" name="db_pass" class="form"
             autocomplete="current-password">
      <p class="form-hint">Leave blank if your database user has no password.</p>
    </div>

    <div class="btn-row">
      <button type="submit" class="btn btn-primary">Test &amp; Continue &rarr;</button>
    </div>
  </form>
</div>
    <?php
}

function renderStep3(string $csrf, array $stored): void
{
    $db = $stored['db'] ?? [];
    // Detect current URL base as a helpful default
    $guessedUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http')
        . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
    // Strip /install.php or /public if present
    $guessedUrl = rtrim(preg_replace('#/install\.php.*$#i', '', $guessedUrl) ?? $guessedUrl, '/');
    ?>
<div class="alert alert-success">
  &#10003; Database connection successful &mdash;
  connected to <strong><?= e((string) ($db['name'] ?? '')) ?></strong>
  on <strong><?= e((string) ($db['host'] ?? '')) ?></strong>.
</div>

<div class="install-card">
  <h2>Site Settings &amp; Admin Account</h2>
  <form method="post" action="install.php">
    <input type="hidden" name="lstr_install_token" value="<?= e($csrf) ?>">
    <input type="hidden" name="install_step" value="3">

    <div class="install-section">Site Settings</div>

    <div class="form-group">
      <label class="form-label" for="site_name">Site Name</label>
      <input type="text" id="site_name" name="site_name" class="form"
             value="Luster" required>
    </div>

    <div class="form-group">
      <label class="form-label" for="site_url">Site URL</label>
      <input type="url" id="site_url" name="site_url" class="form"
             value="<?= e($guessedUrl) ?>" required
             placeholder="https://yoursite.example.com">
      <p class="form-hint">No trailing slash. This is the base URL where Luster is installed (e.g. <code>https://example.com/luster/public</code>).</p>
    </div>

    <div class="form-group">
      <label class="form-label" for="timezone">Timezone</label>
      <div class="select-wrap">
        <select id="timezone" name="timezone" class="form-select">
          <?php
          $current = date_default_timezone_get();
          foreach (timezone_identifiers_list() as $tz):
          ?>
          <option value="<?= e($tz) ?>"<?= $tz === $current ? ' selected' : '' ?>><?= e($tz) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>

    <div class="form-row">
      <div class="form-group">
        <label class="form-label" for="owner_name">Owner Name</label>
        <input type="text" id="owner_name" name="owner_name" class="form"
               maxlength="100" placeholder="Jiji">
        <p class="form-hint">Your display name used in outgoing fan emails (e.g. <em>Jiji</em>). Can be changed later in Settings.</p>
      </div>
      <div class="form-group">
        <label class="form-label" for="owner_email">Owner Email <em style="font-weight:400">(optional)</em></label>
        <input type="email" id="owner_email" name="owner_email" class="form"
               maxlength="255" placeholder="you@example.com">
        <p class="form-hint">Contact email shown in fan emails. Defaults to your admin email if left blank.</p>
      </div>
    </div>

    <div class="install-section">Administrator Account</div>

    <div class="form-group">
      <label class="form-label" for="admin_username">Username</label>
      <input type="text" id="admin_username" name="admin_username" class="form"
             required autocomplete="username"
             pattern="[a-zA-Z0-9_\-]{3,50}">
      <p class="form-hint">3–50 characters: letters, numbers, underscores, hyphens.</p>
    </div>

    <div class="form-group">
      <label class="form-label" for="admin_email">Email Address</label>
      <input type="email" id="admin_email" name="admin_email" class="form"
             required autocomplete="email">
    </div>

    <div class="form-row">
      <div class="form-group">
        <label class="form-label" for="admin_password">Password</label>
        <input type="password" id="admin_password" name="admin_password" class="form"
               required autocomplete="new-password" minlength="12">
        <p class="form-hint">Minimum 12 characters.</p>
      </div>
      <div class="form-group">
        <label class="form-label" for="admin_password_confirm">Confirm Password</label>
        <input type="password" id="admin_password_confirm" name="admin_password_confirm" class="form"
               required autocomplete="new-password" minlength="12">
      </div>
    </div>

    <div class="btn-row">
      <button type="submit" class="btn btn-primary">Install Luster &rarr;</button>
    </div>
  </form>
</div>
    <?php
}

function renderDone(): void
{
    ?>
<div class="install-card" style="text-align:center">
  <div class="install-success-icon">&#10003;</div>
  <h2>Installation Complete!</h2>
  <p style="color:var(--text-muted);margin:.5rem 0 1.75rem;line-height:1.6">
    Luster has been installed successfully. All database tables have been created
    and your admin account is ready.
  </p>
  <a href="./admin.php" class="btn btn-primary btn-full">Go to Admin Panel &rarr;</a>
</div>

<div class="alert alert-warning" style="background:var(--warn-bg);color:var(--warn);border:1px solid rgba(251,191,36,.2);padding:12px 16px;border-radius:10px;font-size:.85rem">
  <strong>Security:</strong> Delete or restrict access to
  <code style="background:rgba(0,0,0,.15);padding:.1rem .35rem;border-radius:4px">public/install.php</code>
  now that installation is complete.
</div>

<div style="margin-top:1.5rem">
  <h3 style="margin-bottom:.875rem;font-size:1rem;font-weight:600;color:var(--text-muted);text-transform:uppercase;letter-spacing:.04em">Next Steps</h3>
  <div style="display:grid;grid-template-columns:1fr 1fr;gap:.875rem">
    <a href="./admin/listings/add" class="install-card" style="text-align:left;text-decoration:none;color:var(--text);padding:1rem 1.125rem;border:1px solid var(--border-soft);border-radius:12px;display:block">
      <div style="font-weight:600;margin-bottom:.3rem">&#43; Add your first listing</div>
      <div style="font-size:.82rem;color:var(--text-muted);line-height:1.45">Create a new fanlisting manually from the admin panel.</div>
    </a>
    <a href="./admin/import" class="install-card" style="text-align:left;text-decoration:none;color:var(--text);padding:1rem 1.125rem;border:1px solid var(--border-soft);border-radius:12px;display:block">
      <div style="font-weight:600;margin-bottom:.3rem">&#8679; Import from Enthusiast or Listing Admin</div>
      <div style="font-size:.82rem;color:var(--text-muted);line-height:1.45">Migrate your existing data using the built-in import wizard. Same-database Enthusiast installs require no credentials.</div>
    </a>
  </div>
</div>

<p class="install-footer">
  Also: configure SMTP in <code>config/app.php</code> to enable outgoing email.
</p>
    <?php
}
