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

session_start();

define('LUSTER_BASE', dirname(__DIR__));
define('CONFIG_PATH', LUSTER_BASE . '/config/app.php');

// -------------------------------------------------------------------------
// Already installed?
// -------------------------------------------------------------------------

if (is_file(CONFIG_PATH)) {
    renderAlreadyInstalled();
    exit;
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
                $appUrl              = rtrim(trim((string) ($_POST['app_url'] ?? '')), '/');
                $appName             = trim((string) ($_POST['app_name'] ?? 'Luster'));
                $timezone            = trim((string) ($_POST['timezone'] ?? 'UTC'));
                $adminUsername       = trim((string) ($_POST['admin_username'] ?? ''));
                $adminEmail          = trim((string) ($_POST['admin_email'] ?? ''));
                $adminPassword       = (string) ($_POST['admin_password'] ?? '');
                $adminPasswordConfirm = (string) ($_POST['admin_password_confirm'] ?? '');

                if ($appUrl === '') {
                    $errors[] = 'Site URL is required.';
                }
                if ($appName === '') {
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

                        installTables($pdo);
                        installAdminUser($pdo, $adminUsername, $adminEmail, $adminPassword);
                        writeConfig($db, $appName, $appUrl, $timezone, $adminEmail);

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
    $sql = <<<'SQL'
        CREATE TABLE IF NOT EXISTS `lstr_admin_users` (
            `id`         INT UNSIGNED    NOT NULL AUTO_INCREMENT,
            `username`   VARCHAR(50)     NOT NULL,
            `email`      VARCHAR(255)    NOT NULL,
            `password`   VARCHAR(255)    NOT NULL,
            `created_at` DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uk_username` (`username`),
            UNIQUE KEY `uk_email`    (`email`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

        CREATE TABLE IF NOT EXISTS `lstr_password_resets` (
            `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `admin_user_id` INT UNSIGNED NOT NULL,
            `token_hash`    VARCHAR(255) NOT NULL,
            `expires_at`    DATETIME     NOT NULL,
            `used`          TINYINT(1)   NOT NULL DEFAULT 0,
            `created_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_token_hash`              (`token_hash`),
            KEY `fk_pr_admin_user`            (`admin_user_id`),
            CONSTRAINT `fk_pr_admin_user`
                FOREIGN KEY (`admin_user_id`) REFERENCES `lstr_admin_users` (`id`)
                ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

        CREATE TABLE IF NOT EXISTS `lstr_rate_limits` (
            `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `identifier`    VARCHAR(255) NOT NULL,
            `action`        VARCHAR(100) NOT NULL,
            `attempts`      INT UNSIGNED NOT NULL DEFAULT 1,
            `last_attempt`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            `blocked_until` DATETIME     NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uk_identifier_action` (`identifier`, `action`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

        CREATE TABLE IF NOT EXISTS `lstr_activity_log` (
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

        CREATE TABLE IF NOT EXISTS `lstr_affiliates` (
            `id`             INT UNSIGNED  NOT NULL AUTO_INCREMENT,
            `fanlisting_id`  INT UNSIGNED  NOT NULL,
            `name`           VARCHAR(255)  NOT NULL,
            `url`            VARCHAR(500)  NOT NULL,
            `email`          VARCHAR(255)  NULL,
            `image`          VARCHAR(255)  NULL,
            `sort_order`     INT UNSIGNED  NOT NULL DEFAULT 0,
            `created_at`     DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at`     DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_fanlisting_id` (`fanlisting_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    SQL;

    // Execute each statement separately (PDO does not support multi-statement exec reliably)
    foreach (array_filter(array_map('trim', explode(';', $sql))) as $statement) {
        if ($statement !== '') {
            $pdo->exec($statement);
        }
    }
}

function installAdminUser(PDO $pdo, string $username, string $email, string $password): void
{
    $hash = password_hash($password, PASSWORD_ARGON2ID);

    $stmt = $pdo->prepare(
        'INSERT INTO `lstr_admin_users` (`username`, `email`, `password`) VALUES (:username, :email, :password)'
    );
    $stmt->execute(['username' => $username, 'email' => $email, 'password' => $hash]);
}

function writeConfig(array $db, string $appName, string $appUrl, string $timezone, string $adminEmail): void
{
    $config = [
        'db_host'        => $db['host'],
        'db_port'        => $db['port'],
        'db_name'        => $db['name'],
        'db_user'        => $db['user'],
        'db_pass'        => $db['pass'],
        'db_charset'     => 'utf8mb4',
        'app_name'       => $appName,
        'app_url'        => $appUrl,
        'app_debug'      => false,
        'smtp_host'      => '',
        'smtp_port'      => 587,
        'smtp_user'      => '',
        'smtp_pass'      => '',
        'smtp_from'      => $adminEmail,
        'smtp_from_name' => $appName,
        'smtp_secure'    => 'tls',
        'timezone'       => $timezone,
        'per_page'       => 25,
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
    echo '<div class="card">';
    echo '<h2>Luster is already installed</h2>';
    echo '<p>A <code>config/app.php</code> file already exists. '
        . 'If you need to re-install, delete that file first.</p>';
    echo '<p><a href="/" class="btn">Go to the site</a> &nbsp; <a href="/admin" class="btn btn-secondary">Go to admin</a></p>';
    echo '</div>';
    renderFooter();
}

function renderPage(string $view, array $errors, string $csrf, int $step, array $stored = []): void
{
    $titles = [
        'req' => 'Step 1: Requirements',
        '2'   => 'Step 2: Database',
        '3'   => 'Step 3: Site &amp; Admin',
        'done' => 'Installation Complete',
    ];
    $title = $titles[$view] ?? 'Luster Installer';

    renderHeader($title, $step);

    // Error list
    if (!empty($errors)) {
        echo '<div class="alert alert-error"><strong>Please fix the following:</strong><ul>';
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
    $stepClasses = static function (int $current, int $n): string {
        if ($n < $current) {
            return 'step done';
        }
        if ($n === $current) {
            return 'step active';
        }
        return 'step';
    };
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= e($title) ?> — Luster Installer</title>
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;background:#f0f2f5;color:#1a1a2e;padding:2rem 1rem;min-height:100vh}
.container{max-width:620px;margin:0 auto}
.brand{font-size:1.75rem;font-weight:700;color:#4f46e5;margin-bottom:.25rem}
.brand-sub{color:#6b7280;font-size:.9rem;margin-bottom:1.75rem}
.steps{display:flex;gap:.4rem;margin-bottom:1.75rem}
.step{flex:1;height:5px;border-radius:3px;background:#e5e7eb;transition:background .3s}
.step.active{background:#4f46e5}
.step.done{background:#22c55e}
.card{background:#fff;border-radius:10px;padding:2rem;box-shadow:0 1px 4px rgba(0,0,0,.08);margin-bottom:1.5rem}
h2{font-size:1.2rem;margin-bottom:1.25rem;color:#1a1a2e}
.form-group{margin-bottom:1.2rem}
label{display:block;font-size:.85rem;font-weight:600;color:#374151;margin-bottom:.35rem}
input[type=text],input[type=password],input[type=email],input[type=number],input[type=url],select{
    width:100%;padding:.55rem .75rem;border:1.5px solid #d1d5db;border-radius:6px;
    font-size:.95rem;background:#fff;transition:border-color .15s,box-shadow .15s}
input:focus,select:focus{outline:none;border-color:#4f46e5;box-shadow:0 0 0 3px rgba(79,70,229,.1)}
.hint{font-size:.78rem;color:#9ca3af;margin-top:.3rem}
.row{display:grid;grid-template-columns:1fr 1fr;gap:1rem}
.btn{display:inline-block;padding:.6rem 1.4rem;background:#4f46e5;color:#fff;border:none;border-radius:6px;
     font-size:.95rem;font-weight:600;cursor:pointer;text-decoration:none;transition:background .15s}
.btn:hover{background:#4338ca}
.btn-secondary{background:#6b7280}.btn-secondary:hover{background:#4b5563}
.btn-success{background:#16a34a}.btn-success:hover{background:#15803d}
.alert{padding:.85rem 1rem;border-radius:6px;margin-bottom:1.25rem;font-size:.875rem}
.alert ul{margin:.4rem 0 0 1.2rem}
.alert-error{background:#fef2f2;border:1px solid #fca5a5;color:#b91c1c}
.alert-success{background:#f0fdf4;border:1px solid #86efac;color:#166534}
.alert-info{background:#eff6ff;border:1px solid #93c5fd;color:#1d4ed8}
.req-list{list-style:none}
.req-list li{display:flex;align-items:center;gap:.6rem;padding:.4rem 0;border-bottom:1px solid #f3f4f6;font-size:.875rem}
.req-list li:last-child{border-bottom:none}
.badge{display:inline-block;padding:.15rem .5rem;border-radius:4px;font-size:.75rem;font-weight:700;min-width:46px;text-align:center}
.badge-ok{background:#dcfce7;color:#166534}
.badge-fail{background:#fee2e2;color:#991b1b}
.badge-warn{background:#fef9c3;color:#92400e}
.success-icon{font-size:3rem;margin-bottom:1rem}
.creds{background:#f8fafc;border:1px solid #e2e8f0;border-radius:6px;padding:1rem;margin:1rem 0;font-size:.875rem}
.creds strong{color:#4f46e5}
.notice{font-size:.825rem;color:#6b7280;margin-top:1.5rem;padding-top:1rem;border-top:1px solid #f3f4f6}
</style>
</head>
<body>
<div class="container">
<div class="brand">Luster</div>
<div class="brand-sub">Installation Wizard</div>
<?php if ($step >= 1 && $step <= 3): ?>
<div class="steps">
  <div class="<?= $stepClasses($step, 1) ?>"></div>
  <div class="<?= $stepClasses($step, 2) ?>"></div>
  <div class="<?= $stepClasses($step, 3) ?>"></div>
</div>
<?php endif; ?>
    <?php
}

function renderFooter(): void
{
    echo '</div></body></html>';
}

function renderStep1(string $csrf): void
{
    $checks = checkRequirements();
    $canContinue = requirementsPass($checks);
    ?>
<div class="card">
  <h2>Step 1 — System Requirements</h2>
  <ul class="req-list">
    <?php foreach ($checks as $c): ?>
    <li>
      <span class="badge badge-<?= e($c['status']) ?>"><?= strtoupper(e($c['status'])) ?></span>
      <?= e($c['label']) ?>
    </li>
    <?php endforeach; ?>
  </ul>
</div>

<?php if (!$canContinue): ?>
<div class="alert alert-error">
  One or more requirements have <strong>FAIL</strong> status. Please resolve them before continuing.
</div>
<?php else: ?>
<div class="alert alert-success">All requirements met. You may continue.</div>
<form method="post" action="install.php">
  <input type="hidden" name="lstr_install_token" value="<?= e($csrf) ?>">
  <input type="hidden" name="install_step" value="1">
  <button type="submit" class="btn">Continue to Database Setup &rarr;</button>
</form>
<?php endif; ?>
    <?php
}

function renderStep2(string $csrf, array $stored): void
{
    $db = $stored['db'] ?? [];
    ?>
<div class="card">
  <h2>Step 2 — Database Configuration</h2>
  <form method="post" action="install.php">
    <input type="hidden" name="lstr_install_token" value="<?= e($csrf) ?>">
    <input type="hidden" name="install_step" value="2">

    <div class="row">
      <div class="form-group">
        <label for="db_host">Database Host</label>
        <input type="text" id="db_host" name="db_host"
               value="<?= e((string) ($db['host'] ?? 'localhost')) ?>" required>
      </div>
      <div class="form-group">
        <label for="db_port">Port</label>
        <input type="number" id="db_port" name="db_port" min="1" max="65535"
               value="<?= e((string) ($db['port'] ?? '3306')) ?>" required>
      </div>
    </div>

    <div class="form-group">
      <label for="db_name">Database Name</label>
      <input type="text" id="db_name" name="db_name"
             value="<?= e((string) ($db['name'] ?? '')) ?>" required>
      <p class="hint">The database must already exist and belong to your Enthusiast installation (or a fresh empty database).</p>
    </div>

    <div class="form-group">
      <label for="db_user">Database Username</label>
      <input type="text" id="db_user" name="db_user"
             value="<?= e((string) ($db['user'] ?? '')) ?>" required autocomplete="username">
    </div>

    <div class="form-group">
      <label for="db_pass">Database Password</label>
      <input type="password" id="db_pass" name="db_pass" autocomplete="current-password">
      <p class="hint">Leave blank if your database user has no password.</p>
    </div>

    <button type="submit" class="btn">Test &amp; Continue &rarr;</button>
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
<div class="card">
  <h2>Step 3 — Site Settings &amp; Admin Account</h2>

  <div class="alert alert-success" style="margin-bottom:1.5rem">
    ✓ Database connection successful.
    Connected to <strong><?= e((string) ($db['name'] ?? '')) ?></strong>
    on <strong><?= e((string) ($db['host'] ?? '')) ?></strong>.
  </div>

  <form method="post" action="install.php">
    <input type="hidden" name="lstr_install_token" value="<?= e($csrf) ?>">
    <input type="hidden" name="install_step" value="3">

    <h2 style="font-size:1rem;margin-bottom:1rem;color:#6b7280;font-weight:600;text-transform:uppercase;letter-spacing:.05em">Site Settings</h2>

    <div class="form-group">
      <label for="app_name">Site Name</label>
      <input type="text" id="app_name" name="app_name" value="Luster" required>
    </div>

    <div class="form-group">
      <label for="app_url">Site URL</label>
      <input type="url" id="app_url" name="app_url"
             value="<?= e($guessedUrl) ?>" required placeholder="https://yoursite.example.com">
      <p class="hint">No trailing slash. This is the URL where Luster is installed.</p>
    </div>

    <div class="form-group">
      <label for="timezone">Timezone</label>
      <select id="timezone" name="timezone">
        <?php
        $current = date_default_timezone_get();
        foreach (timezone_identifiers_list() as $tz):
        ?>
        <option value="<?= e($tz) ?>"<?= $tz === $current ? ' selected' : '' ?>><?= e($tz) ?></option>
        <?php endforeach; ?>
      </select>
    </div>

    <h2 style="font-size:1rem;margin:1.5rem 0 1rem;color:#6b7280;font-weight:600;text-transform:uppercase;letter-spacing:.05em">Administrator Account</h2>

    <div class="form-group">
      <label for="admin_username">Username</label>
      <input type="text" id="admin_username" name="admin_username" required
             autocomplete="username" pattern="[a-zA-Z0-9_\-]{3,50}">
      <p class="hint">3–50 characters: letters, numbers, underscores, hyphens.</p>
    </div>

    <div class="form-group">
      <label for="admin_email">Email Address</label>
      <input type="email" id="admin_email" name="admin_email" required autocomplete="email">
    </div>

    <div class="row">
      <div class="form-group">
        <label for="admin_password">Password</label>
        <input type="password" id="admin_password" name="admin_password" required
               autocomplete="new-password" minlength="12">
        <p class="hint">Minimum 12 characters.</p>
      </div>
      <div class="form-group">
        <label for="admin_password_confirm">Confirm Password</label>
        <input type="password" id="admin_password_confirm" name="admin_password_confirm" required
               autocomplete="new-password" minlength="12">
      </div>
    </div>

    <button type="submit" class="btn btn-success">Install Luster &rarr;</button>
  </form>
</div>
    <?php
}

function renderDone(): void
{
    ?>
<div class="card" style="text-align:center">
  <div class="success-icon">&#10003;</div>
  <h2>Installation Complete!</h2>
  <p style="color:#6b7280;margin:.5rem 0 1.5rem">
    Luster has been installed successfully. Your database tables have been created
    and your admin account is ready.
  </p>
  <a href="/admin" class="btn btn-success">Go to Admin Panel &rarr;</a>
</div>

<div class="alert alert-info">
  <strong>Important:</strong> For security, please delete or restrict access to
  <code>public/install.php</code> now that installation is complete.
</div>

<div class="notice">
  Next steps: configure your SMTP settings in <code>config/app.php</code>,
  then visit the admin panel to add your first fanlisting.
</div>
    <?php
}
