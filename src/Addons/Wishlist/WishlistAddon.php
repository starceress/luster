<?php

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
    public function getKey(): string
    {
        return 'Wishlist';
    }

    public function getName(): string
    {
        return 'Wishlist';
    }

    public function getDescription(): string
    {
        return 'A collective wishlist of fanlistings you would like to open. Supports four entry types (Wanted, Top Wanted, Granted, Upcoming) with category grouping and a public embed for your site pages.';
    }

    public function install(PDO $pdo): void
    {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS `lstr_wishlist` (
                `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `subject`     VARCHAR(255) NOT NULL DEFAULT '',
                `url`         VARCHAR(255) NOT NULL DEFAULT '',
                `image`       VARCHAR(255) NULL,
                `category`    VARCHAR(100) NOT NULL DEFAULT '',
                `type`        ENUM('wanted','top','granted','upcoming') NOT NULL DEFAULT 'wanted',
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

    public function uninstall(PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS `lstr_wishlist`');
    }

    public function registerRoutes(Router $router): void
    {
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
