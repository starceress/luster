<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Addon entry point for the Newsfeed. Implements AddonInterface to create the
 lstr_newsfeed table and register admin routes. Public output is embed-only
 (public/embed/newsfeed.php and public/embed/collective-newsfeed.php).
*/

declare(strict_types=1);

namespace Luster\Addons\Newsfeed;

use Luster\Addons\Newsfeed\Controllers\NewsfeedAdminController;
use Luster\Core\AddonInterface;
use Luster\Core\Router;
use PDO;

/**
 * Newsfeed add-on.
 *
 * Provides a news/updates system for owned fanlistings and the collective.
 * Admins post entries; fans read them via embeds on the owner's host pages.
 */
final class NewsfeedAddon implements AddonInterface
{
    /** Returns the unique string key used in lstr_addons and class discovery. */
    public function getKey(): string
    {
        return 'Newsfeed';
    }

    /** Returns the human-readable add-on name shown in the admin nav and Manage Add-ons page. */
    public function getName(): string
    {
        return 'News Feed';
    }

    /** Returns the description shown on the add-on card in the admin panel. */
    public function getDescription(): string
    {
        return 'Allows you to post categorized news and updates for your collective and individual listings. Adds a collective-side newsfeed, a per-listing newsfeed, and an admin panel for managing entries.';
    }

    /** Creates lstr_newsfeed. */
    public function install(PDO $pdo): void
    {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS `lstr_newsfeed` (
                `id`            INT UNSIGNED  NOT NULL AUTO_INCREMENT,
                `fanlisting_id` INT UNSIGNED  NULL     DEFAULT NULL,
                `title`         VARCHAR(255)  NOT NULL,
                `body`          TEXT          NOT NULL,
                `category`      VARCHAR(500)  NOT NULL DEFAULT '',
                `author`        VARCHAR(100)  NOT NULL DEFAULT '',
                `posted_at`     DATETIME      NOT NULL,
                `created_at`    DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                KEY `idx_fanlisting` (`fanlisting_id`),
                KEY `idx_posted_at`  (`posted_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        );
    }

    /** Drops lstr_newsfeed. */
    public function uninstall(PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS `lstr_newsfeed`');
    }

    /** Registers admin (/admin/newsfeed/*) routes. Public output is embed-only. */
    public function registerRoutes(Router $router, string $context): void
    {
        if ($context !== 'admin') {
            return;
        }

        // --- Admin routes
        // Specific literal paths must be registered before parameterised ones.

        // Overview
        $router->get('/admin/newsfeed', NewsfeedAdminController::class, 'index');

        // Settings — before {fid} to avoid collision
        $router->get('/admin/newsfeed/settings',  NewsfeedAdminController::class, 'settings');
        $router->post('/admin/newsfeed/settings', NewsfeedAdminController::class, 'settings');

        // Collective — specific paths first
        $router->get('/admin/newsfeed/collective/add',  NewsfeedAdminController::class, 'collectiveAddForm');
        $router->post('/admin/newsfeed/collective/add', NewsfeedAdminController::class, 'collectiveAdd');
        $router->get('/admin/newsfeed/collective',      NewsfeedAdminController::class, 'collectiveList');

        // Entry-level edit/delete — before {fid} routes
        $router->get('/admin/newsfeed/entry/{id}/edit',    NewsfeedAdminController::class, 'editForm');
        $router->post('/admin/newsfeed/entry/{id}/edit',   NewsfeedAdminController::class, 'edit');
        $router->post('/admin/newsfeed/entry/{id}/delete', NewsfeedAdminController::class, 'delete');

        $router->post('/admin/newsfeed/bulk', NewsfeedAdminController::class, 'bulkDelete');

        // Per-fanlisting — add before list so 'add' is not matched as {fid}
        $router->get('/admin/newsfeed/{fid}/add',  NewsfeedAdminController::class, 'addForm');
        $router->post('/admin/newsfeed/{fid}/add', NewsfeedAdminController::class, 'add');
        $router->get('/admin/newsfeed/{fid}',      NewsfeedAdminController::class, 'list');
    }
}
