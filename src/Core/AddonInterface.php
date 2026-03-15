<?php

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
 * registerRoutes() — called at every request boot (admin context) when the
 *               addon is installed. Register any admin or public routes here.
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

    /** Semantic version string, e.g. '1.0.0'. */
    public function getVersion(): string;

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
     * Called at every request after the addon is confirmed installed.
     */
    public function registerRoutes(Router $router): void;
}
