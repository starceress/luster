<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Install wizard. Walks a new user through database connection, table
 creation, and admin account setup. Completely standalone — it doesn't
 use bootstrap.php or Composer, so it works on a fresh upload before
 anything else is configured.
*/

declare(strict_types=1);

ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

// Set cookie_secure only on HTTPS. On plain HTTP (local XAMPP, staging)
// a Secure cookie is rejected by the browser, silently breaking session state.
$_lstr_https = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on')
            || (isset($_SERVER['HTTP_X_FORWARDED_PROTO'])
                && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');

ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_secure',   $_lstr_https ? '1' : '0');
ini_set('session.cookie_samesite', 'Strict');
ini_set('session.use_strict_mode', '1');
session_start();

define('LUSTER_BASE', dirname(__DIR__));
define('CONFIG_PATH',   LUSTER_BASE . '/config/app.php');
define('SENTINEL_PATH', LUSTER_BASE . '/storage/.installed');

// Legacy demo URLs were removed. Do not let old debug query strings fall
// through to the normal installer or already-installed page.
if (array_key_exists('demo', $_GET)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Not Found';
    exit;
}

// -------------------------------------------------------------------------
// Done page — must precede installed-state guards so the completion screen
// renders on the first visit after install (when sentinel and config exist).
// -------------------------------------------------------------------------

if (isset($_GET['done']) && !empty($_SESSION['lstr_install_complete'])) {
    // Guard: if config/sentinel were deleted since this flag was set, it's stale.
    if (!is_file(CONFIG_PATH) || !is_file(SENTINEL_PATH)) {
        unset($_SESSION['lstr_install_complete']);
        header('Location: ./');
        exit;
    }
    unset($_SESSION['lstr_install_complete']);
    // Read the freshly-written config for the correct Luster URL.
    $_doneCfg = @include CONFIG_PATH;
    $_doneUrl = rtrim((string) ($_doneCfg['app_url'] ?? ''), '/');
    renderPage('done', [], '', 5, ['site' => ['app_url' => $_doneUrl]]);
    exit;
}

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
// Back navigation (GET ?back=N)
// Must follow state setup so $stored is populated before editing it.
// -------------------------------------------------------------------------

if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['back'])) {
    $targetStep = (int) $_GET['back'];
    if ($targetStep === 1) {
        // Back to Requirements: no data to clear.
        $_SESSION['lstr_install_step'] = 1;
        header('Location: ./');
        exit;
    } elseif ($targetStep === 2) {
        // Back to DB step: force re-validation and clear any stale password hash.
        unset($stored['db'], $stored['site']['admin_password_hash']);
        unset($stored['db_check'], $_SESSION['lstr_install_db_check_pending']);
        $_SESSION['lstr_install_data'] = $stored;
        $_SESSION['lstr_install_step'] = 2;
        header('Location: ./');
        exit;
    } elseif ($targetStep === 3) {
        // Back to Site & Admin: keep validated DB, require password re-entry.
        unset($stored['site']['admin_password_hash']);
        unset($_SESSION['lstr_install_db_check_pending']);
        $_SESSION['lstr_install_data'] = $stored;
        $_SESSION['lstr_install_step'] = 3;
        header('Location: ./');
        exit;
    }
}

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
                header('Location: ./');
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

                // Capture form values (no password) for repopulation on failure.
                // db_form ≠ db: this key holds whatever was typed, validated or not.
                $stored['db_form'] = [
                    'host' => $dbHost,
                    'port' => $dbPort,
                    'name' => $dbName,
                    'user' => $dbUser,
                ];
                $_SESSION['lstr_install_data'] = $stored;

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
                if ($dbPass === '') {
                    $errors[] = 'Database password is required.';
                }

                if (empty($errors)) {
                    try {
                        $testPdo = new PDO(
                            "mysql:host={$dbHost};port={$dbPort};dbname={$dbName};charset=utf8mb4",
                            $dbUser,
                            $dbPass,
                            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5]
                        );

                        // Check for existing lstr_* tables.
                        $tableCountStmt = $testPdo->query(
                            "SELECT COUNT(*) FROM information_schema.TABLES
                             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE 'lstr\\_%'"
                        );
                        $tableCount = $tableCountStmt !== false ? (int) $tableCountStmt->fetchColumn() : 0;

                        $adminCount = 0;
                        if ($tableCount > 0) {
                            $adminTableStmt = $testPdo->query(
                                "SELECT COUNT(*) FROM information_schema.TABLES
                                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'lstr_admin_users'"
                            );
                            if ($adminTableStmt !== false && (int) $adminTableStmt->fetchColumn() > 0) {
                                $adminCountStmt = $testPdo->query('SELECT COUNT(*) FROM lstr_admin_users');
                                $adminCount = $adminCountStmt !== false ? (int) $adminCountStmt->fetchColumn() : 0;
                            }
                        }

                        $schemaStatus = $tableCount === 0 ? 'fresh' : ($adminCount > 0 ? 'exists' : 'partial');

                        unset($testPdo);

                        $stored['db'] = [
                            'host' => $dbHost,
                            'port' => $dbPort,
                            'name' => $dbName,
                            'user' => $dbUser,
                            'pass' => $dbPass,
                        ];
                        $stored['db_check'] = [
                            'is_error'      => false,
                            'message'       => 'Database connection successful! Connected to <strong>' . e($dbName) . '</strong> on <strong>' . e($dbHost) . '</strong>.',
                            'detail'        => '',
                            'schema_status' => $schemaStatus,
                            'table_count'   => $tableCount,
                        ];
                        $_SESSION['lstr_install_data'] = $stored;
                        $_SESSION['lstr_install_db_check_pending'] = true;
                        $_SESSION['lstr_install_step'] = 3;
                        header('Location: ./');
                        exit;
                    } catch (\PDOException $e) {
                        $rawMessage = $e->getMessage();
                        $userMessage = 'Could not connect to the database.';
                        if (str_contains($rawMessage, 'Access denied')) {
                            $userMessage = 'Access denied — check your username and password.';
                        } elseif (str_contains($rawMessage, 'Unknown database')) {
                            $userMessage = 'Database not found — make sure the database exists before installing.';
                        } elseif (str_contains($rawMessage, 'Connection refused') || str_contains($rawMessage, 'No such file')) {
                            $userMessage = 'Could not reach the database server — check the host and port.';
                        } elseif (str_contains($rawMessage, "Can't connect")) {
                            $userMessage = 'Could not connect — check that the database server is running and the host is correct.';
                        }
                        $stored['db_check'] = [
                            'is_error' => true,
                            'message'  => $userMessage,
                            'detail'   => htmlspecialchars($rawMessage, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                        ];
                        $_SESSION['lstr_install_data'] = $stored;
                        $_SESSION['lstr_install_db_check_pending'] = true;
                        header('Location: ./');
                        exit;
                    }
                }

                $step = 2;
                break;

            // ----------------------------------------------------------------
            // Database check interstitial: acknowledge success, then continue
            // into the existing Step 3 setup form. This is not a new wizard step.
            // ----------------------------------------------------------------
            case 23:
                if (empty($stored['db'])) {
                    $_SESSION['lstr_install_step'] = 2;
                    header('Location: ./');
                    exit;
                }

                unset($_SESSION['lstr_install_db_check_pending']);
                $_SESSION['lstr_install_step'] = 3;
                header('Location: ./');
                exit;

            // ----------------------------------------------------------------
            // Step 3: validate site config + admin account; compute password
            // hash; advance to Review. No database or filesystem writes here.
            // ----------------------------------------------------------------
            case 3:
                $appUrl                = rtrim(trim((string) ($_POST['app_url'] ?? '')), '/');
                $siteUrl               = rtrim(trim((string) ($_POST['site_url'] ?? '')), '/');
                $siteName              = trim((string) ($_POST['site_name'] ?? ''));
                $ownerName             = trim((string) ($_POST['owner_name']  ?? ''));
                $ownerEmail            = trim((string) ($_POST['owner_email'] ?? ''));
                $adminUsername         = trim((string) ($_POST['admin_username'] ?? ''));
                $adminEmail            = trim((string) ($_POST['admin_email'] ?? ''));
                $adminPassword         = (string) ($_POST['admin_password'] ?? '');
                $adminPasswordConfirm  = (string) ($_POST['admin_password_confirm'] ?? '');

                // Store non-password fields immediately for repopulation on failure.
                $stored['site'] = [
                    'site_name'      => $siteName,
                    'app_url'        => $appUrl,
                    'site_url'       => $siteUrl,
                    'owner_name'     => $ownerName,
                    'owner_email'    => $ownerEmail,
                    'admin_username' => $adminUsername,
                    'admin_email'    => $adminEmail,
                ];
                $_SESSION['lstr_install_data'] = $stored;

                if ($appUrl === '') {
                    $errors[] = 'Luster Install URL is required.';
                }
                if ($siteUrl === '') {
                    $errors[] = 'Site URL is required.';
                }
                if ($siteName === '') {
                    $errors[] = 'Site name is required.';
                }
                if ($ownerName === '') {
                    $errors[] = 'Name is required.';
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
                    if (empty($stored['db'])) {
                        $errors[] = 'Session expired. Please start over.';
                        $_SESSION['lstr_install_step'] = 1;
                        unset($_SESSION['lstr_install_data']);
                        $step = 1;
                        break;
                    }

                    // Compute hash here; raw password never leaves this handler.
                    $stored['site']['admin_password_hash'] = password_hash($adminPassword, PASSWORD_ARGON2ID);
                    $_SESSION['lstr_install_data'] = $stored;
                    $_SESSION['lstr_install_step'] = 4;
                    header('Location: ./');
                    exit;
                }

                $step = 3;
                break;

            // ----------------------------------------------------------------
            // Step 4: confirm review → run all install writes
            // ----------------------------------------------------------------
            case 4:
                $db   = $stored['db']   ?? null;
                $site = $stored['site'] ?? null;
                $hash = $site['admin_password_hash'] ?? null;

                if ($db === null) {
                    // DB session missing — re-validate credentials.
                    $_SESSION['lstr_install_step'] = 2;
                    header('Location: ./');
                    exit;
                }

                if ($site === null || $hash === null) {
                    // Safe site data may still exist; preserve it and require
                    // password re-entry rather than wiping the whole session.
                    unset($stored['site']['admin_password_hash']);
                    $_SESSION['lstr_install_data'] = $stored;
                    $_SESSION['lstr_install_step'] = 3;
                    header('Location: ./');
                    exit;
                }

                try {
                    $pdo = new PDO(
                        "mysql:host={$db['host']};port={$db['port']};dbname={$db['name']};charset=utf8mb4",
                        $db['user'],
                        $db['pass'],
                        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
                    );

                    // Hard DB guard: reject if lstr_admin_users already has rows.
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
                    installAddons($pdo);
                    installAdminUser(
                        $pdo,
                        (string) ($site['admin_username'] ?? ''),
                        (string) ($site['admin_email']    ?? ''),
                        $hash,
                        (string) ($site['owner_name']     ?? '')
                    );
                    writeConfig(
                        $db,
                        (string) ($site['site_name']   ?? ''),
                        (string) ($site['app_url']     ?? ''),
                        (string) ($site['site_url']    ?? $site['app_url'] ?? ''),
                        date_default_timezone_get(),
                        (string) ($site['admin_email'] ?? ''),
                        (string) ($site['owner_name']  ?? ''),
                        (string) ($site['owner_email'] ?? '')
                    );
                    patchHtaccess((string) ($site['app_url'] ?? ''));

                    if (file_put_contents(SENTINEL_PATH, date('Y-m-d H:i:s') . "\n") === false) {
                        throw new \RuntimeException(
                            'Could not write storage/.installed sentinel. '
                            . 'Ensure the web server has write permission on the storage/ directory.'
                        );
                    }

                    unset(
                        $_SESSION['lstr_install_step'],
                        $_SESSION['lstr_install_data'],
                        $_SESSION['lstr_install_token']
                    );

                    $_SESSION['lstr_install_complete'] = true;
                    header('Location: ./?done=1');
                    exit;
                } catch (\Throwable $e) {
                    $errors[] = 'Installation failed: ' . $e->getMessage();
                }

                $step = 4;
                break;
        }
    }
}

// -------------------------------------------------------------------------
// Prerequisite guards — prevent rendering a step whose session data is
// incomplete (stale lstr_install_step, tampered session, partial expiry).
// Only redirect on GET; POST handlers own their own error paths.
// -------------------------------------------------------------------------

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if ($step >= 3 && empty($stored['db'])) {
        $_SESSION['lstr_install_step'] = 2;
        header('Location: ./');
        exit;
    }
    if ($step >= 4 && empty($stored['site']['admin_password_hash'])) {
        $_SESSION['lstr_install_step'] = 3;
        header('Location: ./');
        exit;
    }
}

// -------------------------------------------------------------------------
// Render current step
// -------------------------------------------------------------------------

$view = $step === 1 ? 'req' : (string) $step;
if (($step === 2 || $step === 3) && !empty($_SESSION['lstr_install_db_check_pending'])) {
    $view = 'db-check';
}
renderPage($view, $errors, $csrfToken, $step, $stored);

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
            `display_name`    VARCHAR(100)    NULL,
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
        // Full column set through M58 (status column replacing boolean pending flag).
        "CREATE TABLE IF NOT EXISTS `lstr_affiliates` (
            `id`                    INT UNSIGNED  NOT NULL AUTO_INCREMENT,
            `fanlisting_id`         INT UNSIGNED  NULL DEFAULT NULL,
            `name`                  VARCHAR(255)  NOT NULL,
            `url`                   VARCHAR(500)  NOT NULL,
            `email`                 VARCHAR(255)  NULL,
            `image`                 VARCHAR(255)  NULL,
            `sort_order`            INT UNSIGNED  NOT NULL DEFAULT 0,
            `active`                TINYINT(1)    NOT NULL DEFAULT 1,
            `status`                VARCHAR(20)   NOT NULL DEFAULT 'approved',
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

        // Per-listing Luster settings.
        "CREATE TABLE IF NOT EXISTS `lstr_fanlisting_settings` (
            `fanlisting_id`                INT UNSIGNED          NOT NULL,
            `auto_approve`                 TINYINT(1)            NOT NULL DEFAULT 0,
            `description`                  TEXT                  NULL DEFAULT NULL,
            `stats_template`               TEXT                  NULL DEFAULT NULL,
            `members_template`             TEXT                  NULL DEFAULT NULL,
            `affiliates_section_template`  TEXT                  NULL DEFAULT NULL,
            `image`                        VARCHAR(255)          NULL DEFAULT NULL,
            `listing_type`                 VARCHAR(50)           NULL DEFAULT NULL,
            `members_per_page`             TINYINT UNSIGNED      NULL DEFAULT NULL,
            `country_field_enabled`        TINYINT(1)            NOT NULL DEFAULT 1,
            `redirect_url_join`            VARCHAR(500)          NOT NULL DEFAULT '',
            `redirect_url_update`          VARCHAR(500)          NOT NULL DEFAULT '',
            `form_url_join`                VARCHAR(500)          NOT NULL DEFAULT '',
            `form_url_update`              VARCHAR(500)          NOT NULL DEFAULT '',
            `form_url_reset`               VARCHAR(500)          NOT NULL DEFAULT '',
            `form_url_contact`             VARCHAR(500)          NOT NULL DEFAULT '',
            `redirect_url_reset`           VARCHAR(500)          NOT NULL DEFAULT '',
            `last_updated`                 DATE                  NULL DEFAULT NULL,
            `queue_updates`                TINYINT(1)            NOT NULL DEFAULT 0,
            `contact_enabled`              TINYINT(1)            NOT NULL DEFAULT 1,
            `contact_recipient`            VARCHAR(255)          NULL DEFAULT NULL,
            `form_url_codes_submit`        VARCHAR(200)          NOT NULL DEFAULT '',
            `approved_by`                  ENUM('','tfl','tafl') NOT NULL DEFAULT '',
            `join_confirmation_email`      TINYINT(1)            NULL DEFAULT NULL,
            `auto_approve_joins`           TINYINT(1)            NOT NULL DEFAULT 0,
            `affiliate_apply_enabled`      TINYINT(1)            NOT NULL DEFAULT 1,
            `form_url_affiliate_apply`     VARCHAR(500)          NOT NULL DEFAULT '',
            `redirect_url_affiliate_apply` VARCHAR(500)          NOT NULL DEFAULT '',
            `db_host`                      VARCHAR(255)          NOT NULL DEFAULT '',
            `db_name`                      VARCHAR(255)          NOT NULL DEFAULT '',
            `db_user`                      VARCHAR(255)          NOT NULL DEFAULT '',
            `db_pass`                      VARCHAR(500)          NOT NULL DEFAULT '',
            `codes_submit_enabled`         TINYINT(1)            NOT NULL DEFAULT 1,
            `code_approval_email`          TINYINT(1)            NULL DEFAULT NULL,
            `pagination_template`          TEXT                  NULL DEFAULT NULL,
            `field_wrapper_template`       TEXT                  NULL DEFAULT NULL,
            `success_template`             TEXT                  NULL DEFAULT NULL,
            `contact_sent_template`        TEXT                  NULL DEFAULT NULL,
            `contact_form_template`        TEXT                  NULL DEFAULT NULL,
            `affiliate_apply_sent_template` TEXT                 NULL DEFAULT NULL,
            `codes_template`               TEXT                  NULL DEFAULT NULL,
            `updates_template`             TEXT                  NULL DEFAULT NULL,
            `adoption_intro_template`      TEXT                  NULL DEFAULT NULL,
            `rules_intro_template`         TEXT                  NULL DEFAULT NULL,
            `join_form_template`           TEXT                  NULL DEFAULT NULL,
            `codes_submit_form_template`   TEXT                  NULL DEFAULT NULL,
            `affiliate_apply_form_template` TEXT                 NULL DEFAULT NULL,
            `adopt_form_template`          TEXT                  NULL DEFAULT NULL,
            `update_form_template`         TEXT                  NULL DEFAULT NULL,
            `reset_form_template`          TEXT                  NULL DEFAULT NULL,
            `updated_at`                   DATETIME              NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`fanlisting_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        // Codes subsystem — lstr_code_sizes and lstr_code_donors must precede
        // lstr_codes because lstr_codes carries FK constraints to both.

        "CREATE TABLE IF NOT EXISTS `lstr_code_sizes` (
            `id`            INT UNSIGNED      NOT NULL AUTO_INCREMENT,
            `fanlisting_id` INT UNSIGNED      NULL,
            `label`         VARCHAR(100)      NOT NULL,
            `width`         SMALLINT UNSIGNED NULL,
            `height`        SMALLINT UNSIGNED NULL,
            `sort_order`    SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (`id`),
            INDEX `idx_fanlisting` (`fanlisting_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS `lstr_code_donors` (
            `id`            INT UNSIGNED  NOT NULL AUTO_INCREMENT,
            `fanlisting_id` INT UNSIGNED  NULL,
            `name`          VARCHAR(150)  NOT NULL,
            `email`         VARCHAR(255)  NULL,
            `url`           VARCHAR(500)  NULL,
            PRIMARY KEY (`id`),
            INDEX `idx_fanlisting` (`fanlisting_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        // Codes (button images) — full column set through M62.
        "CREATE TABLE IF NOT EXISTS `lstr_codes` (
            `id`              INT UNSIGNED      NOT NULL AUTO_INCREMENT,
            `fanlisting_id`   INT UNSIGNED      NULL,
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
            `fanlisting_id` INT UNSIGNED      NULL,
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
            `fanlisting_id` INT           NOT NULL,
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

        // Categories (TFL/TAFL/custom) — slug added M55, parent_id re-added M56.
        "CREATE TABLE IF NOT EXISTS `lstr_categories` (
            `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `name`       VARCHAR(150) NOT NULL,
            `slug`       VARCHAR(100) NOT NULL DEFAULT '',
            `parent_id`  INT UNSIGNED NULL,
            `source`     VARCHAR(50)  NOT NULL DEFAULT 'custom',
            `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_categories_source_name` (`source`, `name`),
            UNIQUE KEY `uq_cat_slug` (`source`, `slug`)
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

        // External listing ID references (M57) — links Luster listings to IDs in
        // third-party scripts (fanatically, codesort, fansfansfans).
        "CREATE TABLE IF NOT EXISTS `lstr_listing_external_refs` (
            `id`                   INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `listing_id`           INT UNSIGNED NOT NULL,
            `source`               VARCHAR(50)  NOT NULL,
            `external_id`          VARCHAR(100) NOT NULL,
            `external_slug_or_url` VARCHAR(500) NOT NULL DEFAULT '',
            `created_at`           DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_ext_refs` (`source`, `external_id`),
            KEY `idx_listing_id` (`listing_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        // Multi-category junction table for listings (M51).
        "CREATE TABLE IF NOT EXISTS `lstr_listing_categories` (
            `fanlisting_id` INT NOT NULL,
            `category_id`   INT NOT NULL,
            PRIMARY KEY (`fanlisting_id`, `category_id`),
            KEY `idx_lc_category` (`category_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        // Joined listings tracker — all columns from migrations 4, 7, 15, 38, 40.
        // ------------------------------------------------------------------
        // Enthusiast-compatible listing registry (unprefixed schema)
        // ------------------------------------------------------------------

        "CREATE TABLE IF NOT EXISTS `owned` (
            `listingid`    INT UNSIGNED  NOT NULL AUTO_INCREMENT,
            `title`        VARCHAR(255)  NOT NULL DEFAULT '',
            `subject`      VARCHAR(255)  NOT NULL DEFAULT '',
            `url`          VARCHAR(255)  NOT NULL DEFAULT '',
            `email`        VARCHAR(255)  NOT NULL DEFAULT '',
            `status`       TINYINT(1)   NOT NULL DEFAULT 2,
            `imagefile`    VARCHAR(255)  NULL,
            `catid`        VARCHAR(255)  NOT NULL DEFAULT '',
            `category_id`  INT UNSIGNED  NULL,
            `opened`       DATE          NULL,
            `dbtable`      VARCHAR(255)  NOT NULL DEFAULT '',
            `dbserver`     VARCHAR(255)  NOT NULL DEFAULT '',
            `dbuser`       VARCHAR(255)  NOT NULL DEFAULT '',
            `dbpassword`   VARCHAR(255)  NOT NULL DEFAULT '',
            `dbdatabase`   VARCHAR(255)  NOT NULL DEFAULT '',
            `additional`   TEXT          NOT NULL,
            PRIMARY KEY (`listingid`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

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
            `description`      TEXT          NULL,
            `comments`         TEXT          NULL,
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        // Member URL link-check tracking.
        "CREATE TABLE IF NOT EXISTS `lstr_member_link_checks` (
            `id`                INT NOT NULL AUTO_INCREMENT,
            `listing_id`        INT NOT NULL,
            `member_email`      VARCHAR(255) NOT NULL,
            `member_name`       VARCHAR(255) NOT NULL DEFAULT '',
            `member_url`        VARCHAR(500) NOT NULL DEFAULT '',
            `link_status`       ENUM('unchecked','ok','redirect','dead','error') NOT NULL DEFAULT 'unchecked',
            `link_redirect_url` VARCHAR(500) NULL,
            `link_checked_at`   DATETIME NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uniq_mlc_listing_email` (`listing_id`, `member_email`),
            INDEX `idx_mlc_status`  (`link_status`),
            INDEX `idx_mlc_listing` (`listing_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

    ];

    foreach ($statements as $statement) {
        $pdo->exec($statement);
    }

    // Seed the official TFL and TAFL category lists so fresh installs have a
    // populated category table without needing to run migrate.php.
    require_once __DIR__ . '/../src/Support/CategorySeedCatalog.php';

    // Inline slugify — same logic as CategoryRepo::slugify(), no autoloader needed.
    $catSlugify = static function (string $name): string {
        $s = strtolower(trim((string) preg_replace('/[^a-zA-Z0-9]+/', '-', $name), '-'));
        return substr($s !== '' ? $s : 'category', 0, 90);
    };

    $catCheck  = $pdo->prepare(
        "SELECT COUNT(*) FROM lstr_categories WHERE source = :source AND name = :name"
    );
    $catInsert = $pdo->prepare(
        "INSERT INTO lstr_categories (name, slug, source) VALUES (:name, :slug, :source)"
    );
    $seedData  = [
        'tfl'  => \Luster\Support\CategorySeedCatalog::tfl(),
        'tafl' => \Luster\Support\CategorySeedCatalog::tafl(),
    ];
    $seenSlugs = [];
    foreach ($seedData as $src => $names) {
        foreach ($names as $catName) {
            $catCheck->execute(['source' => $src, 'name' => $catName]);
            if (!(int) $catCheck->fetchColumn()) {
                $base = $catSlugify($catName);
                $slug = $base;
                $n    = 2;
                while (isset($seenSlugs[$src][$slug])) {
                    $slug = $base . '-' . $n++;
                }
                $seenSlugs[$src][$slug] = true;
                $catInsert->execute(['name' => $catName, 'slug' => $slug, 'source' => $src]);
            }
        }
    }

    // Mark migrations 1-53, 55-57 as already run — the schema created
    // above incorporates every column and table those migrations add, with
    // one intentional omission:
    //   54 — widens lstr_wishlist.category; installer does not create
    //         lstr_wishlist (Wishlist add-on owns that table). The add-on's
    //         install() now creates category VARCHAR(500) directly, so the
    //         migration is a no-op for new add-on installs.
    //
    // This list is kept in sync with the CREATE TABLE statements above.
    // Migrations that are purely data operations (e.g., M67 resets a stale
    // setting, M69 touches the Rules add-on table) are included here so they
    // are not re-applied against fresh installs. Future migrations that add
    // schema must also update the CREATE TABLE DDL above before being added.
    $pdo->exec(
        'INSERT IGNORE INTO `lstr_migrations` (migration_number) VALUES
         (1),(2),(3),(4),(5),(6),(7),(8),(9),(10),(11),(12),(13),
         (14),(15),(16),(17),(18),(19),(20),(21),(22),(23),(24),
         (25),(26),(27),(28),(29),(30),(31),(32),(33),(34),(35),
         (36),(37),(38),(39),(40),(41),(42),(43),(44),(45),(46),
         (47),(48),(49),(50),(51),(52),(53),(55),(56),(57),
         (58),(59),(60),(61),(62),(63),(64),(65),(66),(67),(68),
         (69),(70),(71),(72),(73),(74),(75),(76),(77),(78)'
    );
}

function installAddons(PDO $pdo): void
{
    // Load Composer autoloader to access addon classes (no-op if already loaded).
    require_once LUSTER_BASE . '/vendor/autoload.php';

    $addonsDir = LUSTER_BASE . '/src/Addons';
    if (!is_dir($addonsDir)) {
        return;
    }

    $stmt = $pdo->prepare('INSERT IGNORE INTO `lstr_addons` (`addon_key`) VALUES (:key)');

    foreach (new \DirectoryIterator($addonsDir) as $entry) {
        if (!$entry->isDir() || $entry->isDot()) {
            continue;
        }
        $dir   = $entry->getFilename();
        $class = "Luster\\Addons\\{$dir}\\{$dir}Addon";
        if (!class_exists($class)) {
            continue;
        }
        $addon = new $class();
        if (!($addon instanceof \Luster\Core\AddonInterface)) {
            continue;
        }
        // DDL in install() causes implicit MySQL commits — no transaction wrapper,
        // matching how AddonsController::install() calls it.
        $addon->install($pdo);
        $stmt->execute(['key' => $addon->getKey()]);
    }
}

function installAdminUser(PDO $pdo, string $username, string $email, string $passwordHash, string $displayName = ''): void
{
    $stmt = $pdo->prepare(
        'INSERT INTO `lstr_admin_users` (`username`, `email`, `display_name`, `password`) VALUES (:username, :email, :display_name, :password)'
    );
    $stmt->execute([
        'username'     => $username,
        'email'        => $email,
        'display_name' => $displayName !== '' ? $displayName : null,
        'password'     => $passwordHash,
    ]);
}

function patchHtaccess(string $appUrl): void
{
    $htaccess = LUSTER_BASE . '/public/.htaccess';
    if (!is_file($htaccess) || !is_writable($htaccess)) {
        return;
    }

    $path = rtrim(parse_url($appUrl, PHP_URL_PATH) ?? '', '/') . '/';
    if ($path === '/') {
        return; // document-root install — default RewriteBase / is already correct
    }

    $contents = file_get_contents($htaccess);
    if ($contents === false) {
        return;
    }

    $patched = preg_replace('/^(\s*)#?\s*(RewriteBase\s+)\S+/m', '${1}${2}' . $path, $contents);
    if ($patched !== null && $patched !== $contents) {
        file_put_contents($htaccess, $patched);
    }
}

function writeConfig(array $db, string $siteName, string $appUrl, string $siteUrl, string $timezone, string $adminEmail, string $ownerName = '', string $ownerEmail = ''): void
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
        'app_url'    => $appUrl,         // Luster install URL — used for routing and admin links
        'site_url'   => $siteUrl,        // Collective homepage shown in emails and admin navigation
        'app_debug'  => false,
        // Site owner identity (shown in email templates)
        'owner_name'  => $ownerName,
        'owner_email' => $ownerEmail !== '' ? $ownerEmail : $adminEmail,
        // Enthusiast compatibility
        'enth_table_prefix' => '',
        // Miscellaneous
        'timezone' => $timezone,
        'per_page' => 10,
        'last_updated_fallback' => 'Never',
        // Embed & Paths
        'install_path'          => LUSTER_BASE . DIRECTORY_SEPARATOR,
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
    $configDirWritable = is_writable(LUSTER_BASE . '/config');
    $storageDirWritable = is_writable(LUSTER_BASE . '/storage');
    $rootWritable = $configDirWritable && $storageDirWritable;
    $phpOk = version_compare(PHP_VERSION, '8.1.0', '>=');
    $status = static fn(bool $ok): string => $ok ? 'ok' : 'fail';
    $detail = static fn(bool $ok, string $okDetail, string $failDetail): string => $ok ? $okDetail : $failDetail;

    return [
        [
            'label'  => 'PHP 8.1 or newer',
            'detail' => $detail($phpOk, 'Found PHP ' . PHP_VERSION, 'Requires PHP 8.1 or newer.'),
            'status' => $status($phpOk),
        ],
        [
            'label'  => 'Database Support',
            'detail' => $detail(extension_loaded('pdo'), 'PDO is enabled.', 'Enable the PHP PDO extension.'),
            'status' => $status(extension_loaded('pdo')),
        ],
        [
            'label'  => 'PDO MySQL Driver',
            'detail' => $detail(extension_loaded('pdo_mysql'), 'PDO MySQL is enabled.', 'Enable the PDO MySQL driver.'),
            'status' => $status(extension_loaded('pdo_mysql')),
        ],
        [
            'label'  => 'cURL Extension',
            'detail' => $detail(extension_loaded('curl'), 'cURL is enabled.', 'Enable the PHP cURL extension (ext-curl).'),
            'status' => $status(extension_loaded('curl')),
        ],
        [
            'label'  => 'Root Directory Access',
            'detail' => $detail($rootWritable, 'Root directory is writable.', 'Make your root directory writable before continuing.'),
            'status' => $status($rootWritable),
        ],
    ];
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
    $_cfg    = @include LUSTER_BASE . '/config/app.php';
    $_appUrl = rtrim((string) ($_cfg['app_url'] ?? ''), '/');
    $_migrateUrl = $_appUrl !== '' ? $_appUrl . '/migrate/' : '../public/migrate/';
    $_adminUrl   = $_appUrl !== '' ? $_appUrl . '/admin' : '../public/admin';

    renderHeader('Already Installed', 0);
    ?>
<div class="onboard-callout">
  <div class="onboard-callout-inner">
    <div class="onboard-callout-heading" style="justify-content: center; margin-bottom: 2rem;"><span class="onboard-callout-sparke">&#10022;</span> Already Installed!</div>

    <div class="alert alert-warning" style="margin-bottom: 2rem;">Luster is already installed on this server. The admin panel is available <a href="<?= e($_adminUrl) ?>" class="link">here</a>.</div>

    <div class="section-label">Updating to a new version?</div>
    <p class="onboard-callout-body">Use the <a href="<?= e($_migrateUrl) ?>" class="link">migration wizard</a> to apply missing database changes. It only runs migrations that have not been applied yet and does not affect your existing data.</p>

    <div class="section-label">Reinstalling from scratch?</div>
    <p class="onboard-callout-body">A full reinstall requires all three of the following steps. <strong>Back up your data first!</strong> After completing all three steps, reload this page to start the installer again.</p>
    <ul class="star-list">
      <li>Drop all <code>lstr_*</code> tables from the database.</li>
      <li>Delete <code>config/app.php</code>.</li>
      <li>Delete <code>storage/.installed</code>.</li>
    </ul>

    <div class="overview-section-divider"></div>
    <div style="display: flex; align-items: center; gap: 1rem;">
      <a href="<?= e($_migrateUrl) ?>" class="btn btn-secondary">Migrations &rarr;</a>
      <span style="flex: 1; text-align: center; color: var(--text-dim); font-size: var(--fs-sm);">or</span>
      <a href="<?= e($_adminUrl) ?>" class="btn btn-primary">Admin Panel &rarr;</a>
    </div>
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
        'db-check' => 'Database Check',
        '3'    => 'Step 3: Site &amp; Admin',
        '4'    => 'Step 4: Review &amp; Install',
        'done' => 'Installation Complete',
    ];
    $title = $titles[$view] ?? '&#10022; Luster Installation Wizard';

    renderHeader($title, $step);

    // Error list (step 2 renders its own errors inside the form)
    if (!empty($errors) && $view !== '2') {
        echo '<div class="alert alert-error" role="alert"><strong>Please fix the following:</strong><ul class="install-error-list">';
        foreach ($errors as $err) {
            echo '<li>' . e($err) . '</li>';
        }
        echo '</ul></div>';
    }

    match ($view) {
        'req'  => renderStep1($csrf),
        '2'    => renderStep2($csrf, $stored, $errors),
        'db-check' => renderDatabaseCheck($csrf, $stored),
        '3'    => renderStep3($csrf, $stored),
        '4'    => renderStep4($csrf, $stored),
        'done' => renderDone(rtrim((string) ($stored['site']['app_url'] ?? ''), '/')),
        default => null,
    };

    renderFooter();
}

function renderHeader(string $title, int $step): void
{
    $tokensCssV  = (string) (filemtime(__DIR__ . '/../public/assets/css/tokens.css') ?: 0);
    $adminCssV   = (string) (filemtime(__DIR__ . '/../public/assets/css/admin.css')  ?: 0);
    $skinsCssV   = (string) (filemtime(__DIR__ . '/../public/assets/css/skins.css')  ?: 0);
    $installCssV = (string) (filemtime(__DIR__ . '/install.css') ?: 0);
    ?>
<!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= e($title) ?> &#10022; Luster Installation Wizard</title>
<link rel="icon" type="image/svg+xml" href="../public/assets/images/logo16.svg">
<link rel="stylesheet" href="../public/assets/css/tokens.css?v=<?= $tokensCssV ?>">
<link rel="stylesheet" href="../public/assets/css/admin.css?v=<?= $adminCssV ?>">
<link rel="stylesheet" href="../public/assets/css/skins.css?v=<?= $skinsCssV ?>">
<link rel="stylesheet" href="install.css?v=<?= $installCssV ?>">
<script>(function(){var t=localStorage.getItem('lstr_theme');if(t==='dark'||t==='light')document.documentElement.setAttribute('data-theme',t);})();</script>
</head>
<body class="install-page">

<div class="login-theme-corner">
  <button type="button" class="theme-toggle" aria-label="Toggle theme">
    <span class="theme-toggle-icon"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="12" r="5"/><path d="M12 1v3M12 20v3M4.22 4.22l2.12 2.12M17.66 17.66l2.12 2.12M1 12h3M20 12h3M4.22 19.78l2.12-2.12M17.66 6.34l2.12-2.12"/></svg></span>
    <div class="toggle-track"><div class="toggle-thumb"></div></div>
    <span class="theme-toggle-icon"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/></svg></span>
  </button>
</div>

<div class="install-wrap<?= ($step === 3 || $step === 4) ? ' install-wrap--wide' : '' ?>">

  <div class="install-brand">
    <picture>
      <source media="(max-width: 640px)" srcset="../public/assets/images/logo50.svg">
      <img src="../public/assets/images/logo.svg" alt="" class="install-logo">
    </picture>
    <h1 class="install-wordmark"><span class="install-brand-plain">Welcome to </span><span class="install-brand-gradient">Luster</span></h1>
    <p class="install-luster-sub">A few questions and we'll have your collective up and running! This should take a few minutes.</p>
  </div>

<?php if ($step >= 1 && $step <= 5):
    $steps = [
        1 => ['Requirements', 'System checks'],
        2 => ['Database', 'Connection check'],
        3 => ['Settings', 'Site & admin details'],
        4 => ['Review', 'Confirm & install'],
        5 => ['Complete', 'All set!'],
    ];
    $stepClamped = max(1, min(5, $step));
?>
<nav class="wizard-steps" aria-label="Installation progress">
  <div class="wizard-steps-line"></div>
  <div class="wizard-steps-progress wizard-steps-progress--step<?= $stepClamped ?>"></div>
  <?php foreach ($steps as $n => [$label, $sub]):
      $nodeClass = 'wizard-step-node';
      if ($n < $step || ($n === $step && $step === 5)) {
          $nodeClass .= ' wizard-step-node--done';
      } elseif ($n === $step) {
          $nodeClass .= ' wizard-step-node--active';
      }
  ?>
  <div class="<?= $nodeClass ?>" <?= $n === $step ? 'aria-current="step"' : '' ?>>
    <div class="wizard-step-bubble"><?= ($n < $step || ($n === $step && $step === 5)) ? '&#10003;' : (string) $n ?></div>
    <div class="wizard-step-label" data-step-sub="<?= e($sub) ?>"><?= e($label) ?></div>
  </div>
  <?php endforeach; ?>
</nav>
<?php endif; ?>
    <?php
}

function renderFooter(): void
{
    ?>
</div>

<p class="login-footer"><a href="https://luster.nu/" target="_blank" title="Luster ✦ Fanlisting Management Script" class="link"><b>Luster</b></a> &copy; <a href="https://dreamesque.net/" target="_blank" title="Star ✦ Dreamesque.Net" class="link">Star</a>, <?= date('Y') === '2026' ? '2026' : '2026&#x2013;' . date('Y') ?>.</p>

<?php $installJsV = filemtime(__DIR__ . '/../public/assets/js/install.js') ?: ''; ?>
<script src="../public/assets/js/install.js?v=<?= $installJsV ?>"></script>
</body>
</html>
    <?php
}

function renderStep1(string $csrf): void
{
    $checks     = checkRequirements();
    $canContinue = requirementsPass($checks);
    ?>
<div class="main-card">
  <div class="panel-header">
    <div class="panel-header-icon panel-header-icon-filled" aria-hidden="true"><svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M11.017 2.814a1 1 0 0 1 1.966 0l1.051 5.558a2 2 0 0 0 1.594 1.594l5.558 1.051a1 1 0 0 1 0 1.966l-5.558 1.051a2 2 0 0 0-1.594 1.594l-1.051 5.558a1 1 0 0 1-1.966 0l-1.051-5.558a2 2 0 0 0-1.594-1.594l-5.558-1.051a1 1 0 0 1 0-1.966l5.558-1.051a2 2 0 0 0 1.594-1.594z"/><path d="M20 2v4"/><path d="M22 4h-4"/><circle cx="4" cy="20" r="2"/></svg></div>
    <div class="panel-header-meta">
      <h2 class="panel-header-title">System Requirements</h2>
      <div class="panel-header-sub">Luster needs these checks to pass before installation can begin.</div>
    </div>
  </div>
  <div class="card-body">
    <p class="form-hint" style="font-size:.875rem;margin-bottom:1.25rem">
      Before we begin, Luster will make sure your server has the essentials needed to run the installer.
    </p>
    <ul class="install-req-list">
      <?php foreach ($checks as $c): ?>
      <li class="install-req-row install-req-row--<?= e($c['status']) ?>">
        <span class="install-req-name"><?= e($c['label']) ?></span>
        <span class="results-icon <?= $c['status'] === 'ok' ? 'results-icon-success' : 'results-icon-err' ?>" aria-label="<?= $c['status'] === 'ok' ? 'Ready' : 'Needs attention' ?>"><?= $c['status'] === 'ok' ? '&#10003;' : '&#10005;' ?></span>
        <span class="install-req-detail">
          <?= e((string) ($c['detail'] ?? '')) ?>
          <?php if ($c['status'] === 'fail' && !empty($c['hint'])): ?>
          <code class="req-hint"><?= e($c['hint']) ?></code>
          <?php endif; ?>
        </span>
      </li>
      <?php endforeach; ?>
    </ul>
    <?php if ($canContinue): ?>
    <div class="alert alert-success" style="margin-top: 2rem; margin-bottom: 0;"><span class="results-icon results-icon-success" aria-label="Ready">&#10003;</span> &nbsp; All requirements met! Click <strong>Continue</strong> below to proceed.</div>
    <?php else: ?>
    <div class="alert alert-error" style="margin-top: 2rem; margin-bottom: 0;"><span class="results-icon results-icon-err" aria-label="Needs attention">&#10005;</span> &nbsp; Please fix the highlighted items above, then click <strong>Refresh</strong> below to try the installer again.</div>
    <?php endif; ?>
  </div>
  <form method="post" action="./" class="composer-footer <?= $canContinue ? 'composer-footer--center' : 'composer-footer--row' ?>">
    <input type="hidden" name="lstr_install_token" value="<?= e($csrf) ?>">
    <input type="hidden" name="install_step" value="1">
    <?php if (!$canContinue): ?>
    <button type="button" class="btn btn-secondary" onclick="location.reload()">Refresh</button>
    <?php endif; ?>
    <button type="submit" class="btn btn-primary" <?= !$canContinue ? 'disabled aria-disabled="true"' : '' ?>>Continue &rarr;</button>
  </form>
</div>
    <?php
}

function renderStep2(string $csrf, array $stored, array $errors = []): void
{
    // db_form holds the last-typed values (set on every Step 2 POST, no password).
    // Fall back to db (validated connection) so back-navigation from Step 3
    // still repopulates with the confirmed values.
    $db = $stored['db_form'] ?? $stored['db'] ?? [];
    ?>
<form method="post" action="./" class="main-card">
  <input type="hidden" name="lstr_install_token" value="<?= e($csrf) ?>">
  <input type="hidden" name="install_step" value="2">
  <input type="hidden" name="db_port" value="<?= e((string) ($db['port'] ?? '3306')) ?>">
  <div class="panel-header">
    <div class="panel-header-icon panel-header-icon-filled" aria-hidden="true"><svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-database-icon lucide-database"><ellipse cx="12" cy="5" rx="9" ry="3"/><path d="M3 5V19A9 3 0 0 0 21 19V5"/><path d="M3 12A9 3 0 0 0 21 12"/></svg></div>
    <div class="panel-header-meta">
      <h2 class="panel-header-title">Database Configuration</h2>
      <div class="panel-header-sub">Luster will test the connection before continuing.</div>
    </div>
  </div>
  <div class="card-body">
    <?php if (!empty($errors)): ?>
    <div class="alert alert-error" role="alert"><strong>Please fix the following:</strong><ul class="star-list" style="color: var(--danger)"><?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul></div>
    <?php endif; ?>
    <div class="field-grid">
      <div class="field-group">
        <label class="field-label" for="db_host">Database Host <span class="req">*</span></label>
        <input type="text" id="db_host" name="db_host" class="form"
               value="<?= e((string) ($db['host'] ?? 'localhost')) ?>" required>
      </div>
      <div class="field-group">
        <label class="field-label" for="db_name">Database Name <span class="req">*</span></label>
        <input type="text" id="db_name" name="db_name" class="form"
               value="<?= e((string) ($db['name'] ?? '')) ?>" required>
        <p class="form-hint">The database must already exist.</p>
      </div>
    </div>

    <div class="field-grid">
      <div class="field-group">
        <label class="field-label" for="db_user">Database Username <span class="req">*</span></label>
        <input type="text" id="db_user" name="db_user" class="form"
               value="<?= e((string) ($db['user'] ?? '')) ?>" required autocomplete="username">
        <p class="form-hint">User must have full privileges on the provided database.</p>
      </div>
      <div class="field-group">
        <label class="field-label" for="db_pass">Database Password <span class="req">*</span></label>
        <div class="input-icon-wrap">
          <input type="password" id="db_pass" name="db_pass" class="form"
                 required autocomplete="current-password">
          <button type="button" class="input-icon-btn" aria-label="Show password" tabindex="-1">
            <svg class="eye-on" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
            <svg class="eye-off" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" style="display:none"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
          </button>
        </div>
        <p class="form-hint">Use the password for the database username provided.</p>
      </div>
    </div>

    <details class="form-details">
      <summary>Where do I find this information?</summary>
      <div class="form-details-body">
        <ul class="star-list">
          <li><strong>Host</strong> — Usually <code>localhost</code> for shared hosting and local installs. Check your hosting control panel if you are unsure.</li>
          <li><strong>Database Name</strong> — The name of an existing MySQL database. Create one in phpMyAdmin or your hosting panel if needed.</li>
          <li><strong>Database Username / Password</strong> — Credentials for a MySQL user with full privileges on that database. Often found in your hosting panel under <em>MySQL Users</em> or <em>Databases</em>.</li>
        </ul>
      </div>
    </details>

  </div>
  <div class="composer-footer composer-footer--row">
    <a href="./?back=1" class="btn btn-quaternary">&larr; Back</a>
    <button type="submit" class="btn btn-primary">Test Connection &rarr;</button>
  </div>
</form>
    <?php
}

function renderDatabaseCheck(string $csrf, array $stored): void
{
    $check        = $stored['db_check'] ?? [];
    $isError      = (bool)   ($check['is_error']      ?? false);
    $message      = (string) ($check['message']       ?? ($isError ? 'Could not connect to the database.' : 'Database connection successful.'));
    $detail       = (string) ($check['detail']        ?? '');
    $schemaStatus = (string) ($check['schema_status'] ?? 'fresh');
    ?>
<div class="main-card">
  <div class="panel-header">
    <div class="panel-header-icon panel-header-icon-filled" aria-hidden="true"><svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><ellipse cx="12" cy="5" rx="9" ry="3"/><path d="M3 5V19A9 3 0 0 0 21 19V5"/><path d="M3 12A9 3 0 0 0 21 12"/></svg></div>
    <div class="panel-header-meta">
      <h2 class="panel-header-title">Database Configuration</h2>
      <div class="panel-header-sub">Results of your database connection.</div>
    </div>
  </div>
  <div class="card-body">
    <div class="alert <?= $isError ? 'alert-error' : 'alert-success' ?>" style="margin-bottom: <?= ($isError && $detail !== '') ? '1rem' : '0' ?>;">
      <?= $isError
          ? '<span class="results-icon results-icon-err" aria-label="Needs attention">&#10005;</span> &nbsp;  '
          : '<span class="results-icon results-icon-success" aria-label="Ready">&#10003;</span> &nbsp;  ' ?><?= $message ?>
    </div>
    <?php if ($isError && $detail !== ''): ?>
    <details class="form-details">
      <summary>Error Report</summary>
      <div class="form-details-body">
        <p><code><?= $detail ?></code></p>
      </div>
    </details>
    <?php elseif (!$isError):
          $tableCount = (int) ($check['table_count'] ?? 0);
      ?>
      <?php if ($schemaStatus === 'fresh'): ?>
      <div class="alert alert-success" style="margin-top: 1rem; margin-bottom: 0;">
        No existing Luster tables found. A fresh schema will be created.
      </div>
      <?php elseif ($schemaStatus === 'partial'): ?>
      <div class="alert alert-warning" style="margin-top: 1rem; margin-bottom: 0;">
        <strong><?= $tableCount ?> existing Luster table<?= $tableCount !== 1 ? 's' : '' ?> found.</strong>
        The installer will add any missing tables and will not modify existing data.
      </div>
      <?php elseif ($schemaStatus === 'exists'): ?>
      <div class="alert alert-warning" style="margin-top: 1rem; margin-bottom: 0;">
        <strong>An existing Luster installation was detected in this database.</strong>
        The installer will be blocked at the final step.
        <ul class="star-list" style="color: var(--warn)">
          <li> To <u>update</u> your database, <a href="../public/migrate/" class="link">click here to run migrations instead.</a></li>
          <li>To <u>reinstall</u> from scratch, remove all <code>lstr_*</code> tables first, delete <code>config/app.php</code> and <code>storage/.installed</code>, then refresh this page to start the installer again.</li>
        </ul>
      </div>
      <?php endif; ?>
    <?php endif; ?>
  </div>
  <div class="composer-footer <?= (!$isError && $schemaStatus !== 'exists') ? 'composer-footer--row' : 'composer-footer--center' ?>">
    <a href="./?back=2" class="btn btn-quaternary">&larr; Back</a>
    <?php if (!$isError && $schemaStatus !== 'exists'): ?>
    <form method="post" action="./" class="action-form-inline">
      <input type="hidden" name="lstr_install_token" value="<?= e($csrf) ?>">
      <input type="hidden" name="install_step" value="23">
      <button type="submit" class="btn btn-primary">Continue &rarr;</button>
    </form>
    <?php endif; ?>
  </div>
</div>
    <?php
}

function renderStep3(string $csrf, array $stored): void
{
    $db   = $stored['db']   ?? [];
    $site = $stored['site'] ?? [];

    // Build the guessed URL from REQUEST_URI so subdirectory installs resolve
    // correctly (e.g. http://localhost/luster/public, not just http://localhost).
    // Use the same two-condition HTTPS check as the session cookie fix so
    // reverse-proxy installs (HTTP_X_FORWARDED_PROTO=https) get https:// URLs.
    $_protocol  = ((isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on')
                || (isset($_SERVER['HTTP_X_FORWARDED_PROTO'])
                    && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https'))
                ? 'https' : 'http';
    $_host      = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $_uriPath   = strtok($_SERVER['REQUEST_URI'] ?? '/install/', '?');
    $_uriPath   = preg_replace('#/install(/index\.php)?/?$#i', '', $_uriPath) ?? $_uriPath;
    $guessedUrl = rtrim($_protocol . '://' . $_host . $_uriPath, '/') . '/public';
    unset($_protocol, $_host, $_uriPath);

    // If a previous Step 3 submission is in session, prefer that over the guess.
    $currentAppUrl   = (string) ($site['app_url']   ?? $guessedUrl);
    // Site URL is the collective homepage — default to just the domain root
    // (protocol + host), not the Luster install subdirectory.
    $_siteGuess      = (string) preg_replace('#^(https?://[^/]+).*$#', '$1', $guessedUrl);
    $currentSiteUrl  = (string) ($site['site_url']  ?? $_siteGuess);
    unset($_siteGuess);
    $currentSiteName = (string) ($site['site_name'] ?? '');
    ?>

<form method="post" action="./" class="main-card">
  <input type="hidden" name="lstr_install_token" value="<?= e($csrf) ?>">
  <input type="hidden" name="install_step" value="3">
  <div class="panel-header">
    <div class="panel-header-icon panel-header-icon-filled" aria-hidden="true"><svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M9.671 4.136a2.34 2.34 0 0 1 4.659 0 2.34 2.34 0 0 0 3.319 1.915 2.34 2.34 0 0 1 2.33 4.033 2.34 2.34 0 0 0 0 3.831 2.34 2.34 0 0 1-2.33 4.033 2.34 2.34 0 0 0-3.319 1.915 2.34 2.34 0 0 1-4.659 0 2.34 2.34 0 0 0-3.32-1.915 2.34 2.34 0 0 1-2.33-4.033 2.34 2.34 0 0 0 0-3.831A2.34 2.34 0 0 1 6.35 6.051a2.34 2.34 0 0 0 3.319-1.915"/><circle cx="12" cy="12" r="3"/></svg></div>
    <div class="panel-header-meta">
      <h2 class="panel-header-title">Site Settings & Admin Account</h2>
      <div class="panel-header-sub">Set your public site details and create the first administrator.</div>
    </div>
  </div>
  <div class="card-body">
    <div class="section-label">Site Settings</div>

      <div class="field-group">
      <label class="field-label" for="app_url">Luster Install URL <span class="req">*</span></label>
      <input type="url" id="app_url" name="app_url" class="form"
             value="<?= e($currentAppUrl) ?>" required
             placeholder="https://example.com/luster/public">
      <p class="form-hint"><b>Must end with <code>/public</code> — no trailing slash.</b> This is the URL to Luster's <code>public/</code> folder, which is where the admin panel and all public pages live. Example: if you uploaded Luster to <code>yoursite.com/luster/</code>, enter <code>https://yoursite.com/luster/public</code>.</p>
    </div>

    <div class="field-grid">
      <div class="field-group">
        <label class="field-label" for="site_name">Site Name <span class="req">*</span></label>
        <input type="text" id="site_name" name="site_name" class="form"
               value="<?= e($currentSiteName) ?>" required>
      </div>
      <div class="field-group">
        <label class="field-label" for="site_url">Site URL <span class="req">*</span></label>
        <input type="url" id="site_url" name="site_url" class="form"
               value="<?= e($currentSiteUrl) ?>" required
               placeholder="https://example.com">
        <p class="form-hint">Your public website (collective, fanlisting, etc.) URL.</p>
      </div>
    </div>

    <div class="field-grid">
      <div class="field-group">
        <label class="field-label" for="owner_name">Name <span class="req">*</span></label>
        <input type="text" id="owner_name" name="owner_name" class="form"
               maxlength="100" required value="<?= e((string) ($site['owner_name'] ?? '')) ?>">
        <p class="form-hint">Used in outgoing outgoing emails (e.g. <em>Star</em>). Can be changed later in Settings.</p>
      </div>
      <div class="field-group">
        <label class="field-label" for="owner_email">Contact Email <em class="fw-normal">(optional)</em></label>
        <input type="email" id="owner_email" name="owner_email" class="form"
               maxlength="255" value="<?= e((string) ($site['owner_email'] ?? '')) ?>">
        <p class="form-hint">Used in for outgoing emails. <strong>Defaults to your admin email if left blank.</strong> Can be changed later in Settings.</p>
      </div>
    </div>

          </div>

  <div class="card-body">
    <div class="section-label">Administrator Account</div>
    <p class="form-hint" style="font-size:.875rem;margin-bottom:1.25rem">
      This account is for logging into the Luster admin panel. You can add more admin users after installation.
    </p>

    <div class="field-grid">
    <div class="field-group">
      <label class="field-label" for="admin_username">Username <span class="req">*</span></label>
      <input type="text" id="admin_username" name="admin_username" class="form"
             required autocomplete="username"
             pattern="[a-zA-Z0-9_\-]{3,50}"
             value="<?= e((string) ($site['admin_username'] ?? '')) ?>">
    </div>

    <div class="field-group">
      <label class="field-label" for="admin_email">Email Address <span class="req">*</span></label>
      <input type="email" id="admin_email" name="admin_email" class="form"
             required autocomplete="email"
             value="<?= e((string) ($site['admin_email'] ?? '')) ?>">
      <p class="form-hint">Used for password resets and as the default email for outgoing emails until configured in Settings.</p>
    </div>
    </div>

    <div class="field-grid">
      <div class="field-group">
        <label class="field-label" for="admin_password">Password <span class="req">*</span></label>
        <div class="input-icon-wrap">
          <input type="password" id="admin_password" name="admin_password" class="form"
                 required autocomplete="new-password" minlength="12">
          <button type="button" class="input-icon-btn" aria-label="Show password" tabindex="-1">
            <svg class="eye-on" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
            <svg class="eye-off" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" style="display:none"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
          </button>
        </div>
        <p class="form-hint">Minimum 12 characters.</p>
      </div>
      <div class="field-group">
        <label class="field-label" for="admin_password_confirm">Confirm Password <span class="req">*</span></label>
        <div class="input-icon-wrap">
          <input type="password" id="admin_password_confirm" name="admin_password_confirm" class="form"
                 required autocomplete="new-password" minlength="12">
          <button type="button" class="input-icon-btn" aria-label="Show password" tabindex="-1">
            <svg class="eye-on" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
            <svg class="eye-off" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" style="display:none"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
          </button>
        </div>
      </div>
    </div>

  </div>
  <div class="composer-footer composer-footer--row">
    <a href="./?back=2" class="btn btn-quaternary">&larr; Back </a>
    <button type="submit" class="btn btn-primary">Continue &rarr;</button>
  </div>
</form>
    <?php
}

function renderStep4(string $csrf, array $stored): void
{
    $db   = $stored['db']   ?? [];
    $site = $stored['site'] ?? [];
    ?>
<div class="main-card">
  <div class="panel-header">
    <div class="panel-header-icon panel-header-icon-filled" aria-hidden="true"><svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-clipboard-check-icon lucide-clipboard-check"><rect width="8" height="4" x="8" y="2" rx="1" ry="1"/><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/><path d="m9 14 2 2 4-4"/></svg></div>
    <div class="panel-header-meta">
      <h2 class="panel-header-title">Review &amp; Install</h2>
      <div class="panel-header-sub">Confirm everything before installing.</div>
    </div>
  </div>

  <div class="card-body">
    <p class="form-hint" style="font-size:.875rem;margin-bottom:1.25rem">Please confirm the details below. If you need to change anything, click the <strong>Back</strong> button. Otherwise, click <strong>Install Luster</strong> to proceed with the installation.</p>

    <div class="section-label">Site</div>
    <div class="sysinfo-row"><span class="field-label">Luster Install URL</span><?= e((string) ($site['app_url'] ?? '')) ?></div>
    <div class="sysinfo-row"><span class="field-label">Site Name</span><?= e((string) ($site['site_name'] ?? '')) ?></div>
    <div class="sysinfo-row"><span class="field-label">Site URL</span><?= e((string) ($site['site_url'] ?? $site['app_url'] ?? '')) ?></div>
    <?php if ((string) ($site['owner_name'] ?? '') !== ''): ?>
    <div class="sysinfo-row"><span class="field-label">Name</span><?= e((string) $site['owner_name']) ?></div>
    <?php endif; ?>
    <?php if ((string) ($site['owner_email'] ?? '') !== ''): ?>
    <div class="sysinfo-row"><span class="field-label">Contact Email</span><?= e((string) $site['owner_email']) ?></div>
    <?php endif; ?>
  </div>
  
  <div class="card-body">
    <div class="section-label">Admin Account</div>
    <div class="sysinfo-row"><span class="field-label">Username</span><?= e((string) ($site['admin_username'] ?? '')) ?></div>
    <div class="sysinfo-row"><span class="field-label">Email</span><?= e((string) ($site['admin_email'] ?? '')) ?></div>
  </div>

  <div class="composer-footer composer-footer--row">
    <a href="./?back=3" class="btn btn-quaternary">&larr; Back</a>
    <form method="post" action="./" class="action-form-inline">
      <input type="hidden" name="lstr_install_token" value="<?= e($csrf) ?>">
      <input type="hidden" name="install_step" value="4">
      <button type="submit" class="btn btn-primary">Install Luster &rarr;</button>
    </form>
  </div>
</div>
    <?php
}

function renderDone(string $appUrl): void
{
    ?>
    <div class="onboard-callout">
      <div class="onboard-callout-inner">
        <div class="onboard-callout-heading" style="justify-content: center; margin-bottom: 1.5rem;">
          <span class="onboard-callout-sparke">&#10022;</span>
          Installation Complete! <svg xmlns="http://www.w3.org/2000/svg" width="30" height="30" viewBox="0 0 24 24" fill="none" stroke="url(#partyGrad)" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><defs><linearGradient id="partyGrad" x1="0" y1="0" x2="1" y2="1"><stop offset="0%" stop-color="#ff7eb3"/><stop offset="50%" stop-color="#78e8d8"/><stop offset="100%" stop-color="#ffd166"/></linearGradient></defs><path d="M5.8 11.3 2 22l10.7-3.79"/><path d="M4 3h.01"/><path d="M22 8h.01"/><path d="M15 2h.01"/><path d="M22 20h.01"/><path d="m22 2-2.24.75a2.9 2.9 0 0 0-1.96 3.12c.1.86-.57 1.63-1.45 1.63h-.38c-.86 0-1.6.6-1.76 1.44L14 10"/><path d="m22 13-.82-.33c-.86-.34-1.82.2-1.98 1.11c-.11.7-.72 1.22-1.43 1.22H17"/><path d="m11 2 .33.82c.34.86-.2 1.82-1.11 1.98C9.52 4.9 9 5.52 9 6.23V7"/><path d="M11 13c1.93 1.93 2.83 4.17 2 5-.83.83-3.07-.07-5-2-1.93-1.93-2.83-4.17-2-5 .83-.83 3.07.07 5 2Z"/></svg>
        </div>

        <p class="onboard-callout-body">Luster has been installed successfully and is ready for your first listing! <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:middle"><circle cx="12" cy="12" r="10"/><path d="M8 14s1.5 2 4 2 4-2 4-2"/><line x1="9" x2="9.01" y1="9" y2="9"/><line x1="15" x2="15.01" y1="9" y2="9"/></svg></p>
        <div class="alert alert-warning" style="margin-bottom: 2rem;"><strong>ATTENTION:</strong> For your security, delete the <code>install/</code> folder now that installation is complete.</div>
        <div class="section-label">Next Steps</div>
        <ul class="star-list" style="margin-bottom: 1.25rem;">
          <li>Migrating from another script? Use <a href="<?= e($appUrl) ?>/admin/import" class="link"><strong>Import Data</strong></a> in the admin panel sidebar.</li>
          <li>Confirm your email <a href="<?= e($appUrl) ?>/admin/settings#panel-general" class="link"><strong>Settings &rarr; General</strong></a> so email notifications and password resets work.</li>
          <li>Open the <a href="<?= e($appUrl) ?>/admin" class="link">admin panel</a> and add your first listing under <a href="<?= e($appUrl) ?>/admin/listings" class="link"><strong>Listings</strong></a>!</li>
        </ul>
        <div class="overview-section-divider"></div>
        <div class="onboard-callout__actions" style="text-align: center;">
          <a href="<?= e($appUrl) ?>/admin" class="btn btn-primary">Proceed to Admin Panel &rarr;</a>
        </div>
      </div>
    </div>
    <?php
}
