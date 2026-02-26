<?php

declare(strict_types=1);

/**
 * Luster — application configuration template.
 *
 * Copy this file to config/app.php and fill in your values.
 * NEVER commit config/app.php — it contains credentials.
 *
 * Alternatively, run the browser-based installer at /install.php
 * and it will generate config/app.php for you.
 */

return [

    // ------------------------------------------------------------------
    // Database (PDO / MySQL or MariaDB)
    // ------------------------------------------------------------------
    'db_host'    => 'localhost',
    'db_port'    => 3306,
    'db_name'    => 'your_database',
    'db_user'    => 'your_username',
    'db_pass'    => 'your_password',
    'db_charset' => 'utf8mb4',

    // ------------------------------------------------------------------
    // Application
    // ------------------------------------------------------------------
    'app_name'  => 'Luster',
    'app_url'   => 'http://localhost',   // No trailing slash
    'app_debug' => false,                // Set true only in development

    // ------------------------------------------------------------------
    // Email (PHPMailer / SMTP)
    // Leave smtp_host empty to disable outgoing email.
    // ------------------------------------------------------------------
    'smtp_host'      => '',
    'smtp_port'      => 587,
    'smtp_user'      => '',
    'smtp_pass'      => '',
    'smtp_from'      => '',
    'smtp_from_name' => '',
    'smtp_secure'    => 'tls',   // 'tls', 'ssl', or ''

    // ------------------------------------------------------------------
    // Miscellaneous
    // ------------------------------------------------------------------
    'timezone' => 'UTC',
    'per_page' => 25,

];
