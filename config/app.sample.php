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
    //
    // app_url  — URL to this Luster install, no trailing slash.
    //            Used for routing, redirects, and all admin links.
    //            e.g. https://example.com/luster/public
    //
    // site_url — Public collective homepage URL, no trailing slash.
    //            Shown in outgoing emails and public pages. Can differ
    //            from app_url when Luster lives in a subdirectory.
    //            e.g. https://example.com
    // ------------------------------------------------------------------
    'site_name'  => 'Luster',
    'app_url'    => 'http://localhost/luster/public',  // Luster install URL, no trailing slash
    'site_url'   => 'http://localhost',                // Collective homepage, no trailing slash
    'app_debug'  => false,                             // Set true only in development

    // ------------------------------------------------------------------
    // Site owner identity
    // Used in outgoing email templates as {owner_name} / {owner_email}.
    // owner_email may differ from your admin login email.
    // ------------------------------------------------------------------
    'owner_name'  => '',
    'owner_email' => '',

    // ------------------------------------------------------------------
    // Enthusiast database compatibility
    //
    // enth_table_prefix controls the naming convention of your Enthusiast
    // core tables and member tables.
    //
    //   ''       — Unprefixed Enthusiast 3 install (e.g. nebuluna.com style):
    //              listing registry = 'owned', member tables bare names
    //              (huntrix, bes, ...), approved column is 'pending = 0'.
    //
    //   'enth_'  — Standard prefixed Enthusiast install:
    //              listing registry = 'enth_fanlistings', member tables
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
    'per_page' => 10,

    // ------------------------------------------------------------------
    // last_updated_fallback — string shown in public stats when no
    //   last_updated date has been stored (e.g. "Never", "Unknown").
    // ------------------------------------------------------------------
    'last_updated_fallback' => 'Never',

    // ------------------------------------------------------------------
    // Embed & Paths
    //
    // install_path — absolute server path to the Luster root directory,
    //   with trailing slash. Used to generate embed include lines shown
    //   in the admin panel (e.g. /home/user/nebuluna.com/luster/).
    //   Leave blank if you are not using the embed system.
    //
    // session_cookie_domain — advanced/manual setting. Most installs
    //   should leave this blank. Set to ".yourdomain.com" only if the
    //   same Luster admin session must be shared across subdomains.
    //   This is intentionally not shown on the admin settings page.
    //
    // embed_base_url — the public URL where Luster's form submissions
    //   go (e.g. https://nebuluna.com/luster/public). Leave blank to
    //   use site_url above.
    // ------------------------------------------------------------------
    'install_path'          => '',
    'session_cookie_domain' => '',
    'embed_base_url'        => '',

];
