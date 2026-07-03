<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Addon entry point for the Wishlist. Implements AddonInterface to create
 the lstr_wishlist table and register the admin routes. The Wishlist lets
 the site owner maintain a categorised list of fanlistings they would
 like to open, with type grouping (Wanted, Top Wanted, Granted) and a
 public embed for display on any page.
*/

declare(strict_types=1);

namespace Luster\Addons\Wishlist;

use Luster\Addons\Wishlist\Controllers\WishlistAdminController;
use Luster\Core\AddonInterface;
use Luster\Core\Router;
use PDO;

/**
 * Wishlist add-on.
 *
 * A collective wishlist of fanlistings the site owner would like to open.
 * Entries can be categorised by type (Wanted, Top Wanted, Granted, Upcoming)
 * and embedded on any page via the public embed file.
 */
final class WishlistAddon implements AddonInterface
{
    /** Returns the unique string key used in lstr_addons and class discovery. */
    public function getKey(): string
    {
        return 'Wishlist';
    }

    /** Returns the human-readable add-on name shown in the admin nav and Manage Add-ons page. */
    public function getName(): string
    {
        return 'Wishlist';
    }

    /** Returns the description shown on the add-on card in the admin panel. */
    public function getDescription(): string
    {
        return 'A wishlist of listings you would like to own some day. Supports three entry types (Wanted, Top Wanted, Granted). Adds a collective-side wishlist, a per-listing wishlist, and an admin panel for managing entries. Entries can be public or private.';
    }

    /** Creates lstr_wishlist. */
    public function install(PDO $pdo): void
    {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS `lstr_wishlist` (
                `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `subject`     VARCHAR(255) NOT NULL DEFAULT '',
                `url`         VARCHAR(255) NOT NULL DEFAULT '',
                `image`       VARCHAR(255) NULL,
                `category`    VARCHAR(500) NOT NULL DEFAULT '',
                `type`        ENUM('wanted','top','granted') NOT NULL DEFAULT 'wanted',
                `description` TEXT NULL,
                `added_at`    DATE NULL,
                `sort_order`  SMALLINT UNSIGNED NOT NULL DEFAULT 0,
                `active`      TINYINT(1) NOT NULL DEFAULT 1,
                PRIMARY KEY (`id`),
                INDEX `idx_lstr_wishlist_type` (`type`),
                INDEX `idx_lstr_wishlist_sort` (`sort_order`, `id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    }

    /** Drops lstr_wishlist. */
    public function uninstall(PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS `lstr_wishlist`');
    }

    /** Registers admin (/admin/wishlist/*) routes. No public routes — output is embed-only. */
    public function registerRoutes(Router $router, string $context): void
    {
        if ($context !== 'admin') {
            return;
        }

        $router->get('/admin/wishlist',                  WishlistAdminController::class, 'index');
        $router->get('/admin/wishlist/add',              WishlistAdminController::class, 'add');
        $router->post('/admin/wishlist/add',             WishlistAdminController::class, 'add');
        $router->get('/admin/wishlist/settings',         WishlistAdminController::class, 'settings');
        $router->post('/admin/wishlist/settings',        WishlistAdminController::class, 'settings');
        $router->post('/admin/wishlist/bulk',            WishlistAdminController::class, 'bulk');
        $router->get('/admin/wishlist/{id}/edit',        WishlistAdminController::class, 'edit');
        $router->post('/admin/wishlist/{id}/edit',       WishlistAdminController::class, 'edit');
        $router->post('/admin/wishlist/{id}/delete',     WishlistAdminController::class, 'delete');
        $router->post('/admin/wishlist/{id}/toggle',     WishlistAdminController::class, 'toggle');
        $router->post('/admin/wishlist/reorder',         WishlistAdminController::class, 'reorder');
    }
}
