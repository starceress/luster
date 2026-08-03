<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Ensures a subdirectory of public/uploads/ exists with a hardening
 .htaccess guard that blocks PHP (and other script) execution. Single
 shared source of truth for content that used to be duplicated across
 ListingController, JoinedController, WishlistAdminController (three
 near-identical, narrower copies) and ImageMigrator (a fourth, even
 weaker copy, used during import) — all four now call this class instead.
*/

declare(strict_types=1);

namespace Luster\Core;

/**
 * Ensures a public/uploads/ subdirectory exists with a script-execution-blocking
 * .htaccess guard, using content identical to the top-level public/uploads/.htaccess.
 */
final class UploadDirectoryGuard
{
    /** Mirrors public/uploads/.htaccess exactly — keep both in sync. */
    private const HTACCESS_CONTENT = <<<'HTACCESS'
Options -Indexes -ExecCGI

# -----------------------------------------------------------------------
# CRITICAL: Block PHP (and all script) execution in uploads directory.
# Uploaded files must never be executed as code.
# -----------------------------------------------------------------------

# Disable PHP engine for this directory
<IfModule mod_php.c>
    php_flag engine off
</IfModule>
<IfModule mod_php7.c>
    php_flag engine off
</IfModule>
<IfModule mod_php8.c>
    php_flag engine off
</IfModule>

# Deny all script handlers
<FilesMatch "\.ph(p[2-8]?|tml|ps|ar)$">
    <IfModule mod_authz_core.c>
        Require all denied
    </IfModule>
    <IfModule !mod_authz_core.c>
        Order allow,deny
        Deny from all
    </IfModule>
</FilesMatch>

# Deny .phtml, .pl, .py, .jsp, .asp, .sh, .cgi
<FilesMatch "\.(phtml|pl|py|jsp|asp|aspx|sh|cgi|shtml)$">
    <IfModule mod_authz_core.c>
        Require all denied
    </IfModule>
    <IfModule !mod_authz_core.c>
        Order allow,deny
        Deny from all
    </IfModule>
</FilesMatch>

# Remove any CGI or server-side-includes handlers
RemoveHandler .php .php3 .php4 .php5 .php7 .php8 .phtml .pht
RemoveType  .php .php3 .php4 .php5 .php7 .php8 .phtml .pht

HTACCESS;

    /**
     * Creates $absoluteDir if it doesn't exist and writes the guard .htaccess
     * if one isn't already there, then returns $absoluteDir unchanged. Never
     * overwrites an existing .htaccess — if some other, weaker guard already
     * exists there, replace it manually; this only ever fills a gap, never
     * clobbers a file (including one an admin may have hand-customized).
     */
    public static function ensure(string $absoluteDir): string
    {
        if (!is_dir($absoluteDir)) {
            if (!mkdir($absoluteDir, 0755, true)) {
                throw new \RuntimeException("Failed to create upload directory: {$absoluteDir}");
            }
        }

        $htaccess = $absoluteDir . '/.htaccess';
        if (!is_file($htaccess)) {
            if (file_put_contents($htaccess, self::HTACCESS_CONTENT) === false) {
                throw new \RuntimeException("Failed to write .htaccess in {$absoluteDir}");
            }
        }

        return $absoluteDir;
    }
}
