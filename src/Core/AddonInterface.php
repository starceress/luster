<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Interface that every Luster add-on must implement. Defines the lifecycle
 contract for install, uninstall, and route registration. Add-ons are
 auto-discovered from src/Addons/ at boot time and must follow the naming
 convention {Key}Addon.php inside a matching directory.
*/

declare(strict_types=1);

namespace Luster\Core;

use PDO;

/**
 * Contract every Luster add-on must fulfill.
 *
 * Place your add-on class at:
 *   src/Addons/{Key}/{Key}Addon.php
 *   namespace Luster\Addons\{Key};
 *
 * The class must be named exactly {Key}Addon (e.g. KimAddon, WishlistAddon).
 * Luster discovers add-ons by scanning src/Addons/ at boot time.
 *
 * Lifecycle
 * ─────────
 * install()   — called once when the admin clicks "Install"
 *               Create tables, insert seed rows, etc.
 *               Wrapped in a transaction by the controller.
 *
 * uninstall() — called once when the admin clicks "Uninstall"
 *               Drop tables and remove all addon data.
 *               Wrapped in a transaction by the controller.
 *
 * registerRoutes() — called at every request boot (both the public and admin
 *               front controllers) when the addon is installed. $context is
 *               'public' or 'admin' — register public (fan-facing) routes only
 *               when $context === 'public', and /admin/* routes only when
 *               $context === 'admin'. The public front controller has no
 *               AuthMiddleware, so admin routes registered under 'public'
 *               would be reachable without authentication.
 */
interface AddonInterface
{
    /**
     * Unique slug — used as the primary key in lstr_addons.
     * Must match the directory name under src/Addons/.
     * Use lowercase letters, digits, and hyphens only (e.g. 'kim', 'wishlist').
     */
    public function getKey(): string;

    /** Human-readable name shown in the admin Add-ons panel. */
    public function getName(): string;

    /** One-sentence description shown in the admin Add-ons panel. */
    public function getDescription(): string;

    /**
     * Run all install-time DDL/DML.
     * Called inside a PDO transaction — throw on failure to trigger rollback.
     */
    public function install(PDO $pdo): void;

    /**
     * Reverse all install-time DDL/DML.
     * Called inside a PDO transaction — throw on failure to trigger rollback.
     */
    public function uninstall(PDO $pdo): void;

    /**
     * Register routes exposed by this add-on.
     * Called at every request after the addon is confirmed installed, once per
     * front controller. $context is 'public' or 'admin' — gate route
     * registration on it so /admin/* routes are never reachable through the
     * unauthenticated public front controller.
     */
    public function registerRoutes(Router $router, string $context): void;
}
