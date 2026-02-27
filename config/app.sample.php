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
    // Enthusiast database compatibility
    //
    // enth_table_prefix controls the naming convention of your Enthusiast
    // core tables and member tables.
    //
    //   ''       — Unprefixed Enthusiast 3 install (e.g. nebuluna.com style):
    //              fanlisting registry = 'owned', member tables bare names
    //              (huntrix, bes, ...), approved column is 'pending = 0'.
    //
    //   'enth_'  — Standard prefixed Enthusiast install:
    //              fanlisting registry = 'enth_fanlistings', member tables
    //              use {dbprefix}members pattern, approved column is
    //              'approved = 1'.
    //
    // Must match [a-zA-Z0-9_] if non-empty.
    // ------------------------------------------------------------------
    'enth_table_prefix' => '',

    // ------------------------------------------------------------------
    // Miscellaneous
    // ------------------------------------------------------------------
    'timezone' => 'UTC',
    'per_page' => 25,

];
