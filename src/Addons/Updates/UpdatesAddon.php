<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Addon entry point for the News Feed (internally "Updates"). Implements
 AddonInterface to create the lstr_updates table and register public
 and admin routes. Provides a blogging-style news system for both
 individual fanlistings and the collective as a whole.
*/

declare(strict_types=1);

namespace Luster\Addons\Updates;

use Luster\Addons\Updates\Controllers\UpdatesAdminController;
use Luster\Addons\Updates\Controllers\UpdatesPublicController;
use Luster\Core\AddonInterface;
use Luster\Core\Router;
use PDO;

/**
 * Updates add-on.
 *
 * Provides a news/updates system for owned fanlistings and the collective.
 * Admins post updates; fans read them via the public feed or embed.
 */
final class UpdatesAddon implements AddonInterface
{
    public function getKey(): string
    {
        return 'Updates';
    }

    public function getName(): string
    {
        return 'News Feed';
    }

    public function getDescription(): string
    {
        return 'Blogging management system to post news & updates for both individual fanlistings and an entire collective.';
    }

    public function install(PDO $pdo): void
    {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS `lstr_updates` (
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

    public function uninstall(PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS `lstr_updates`');
    }

    public function registerRoutes(Router $router): void
    {
        // ── Public routes (read-only feed) ────────────────────────────────
        $router->get('/updates',      UpdatesPublicController::class, 'collective');
        $router->get('/updates/{id}', UpdatesPublicController::class, 'feed');

        // ── Admin routes ──────────────────────────────────────────────────
        // Specific literal paths must be registered before parameterised ones.

        // Overview
        $router->get('/admin/updates', UpdatesAdminController::class, 'index');

        // Settings — before {fid} to avoid collision
        $router->get('/admin/updates/settings',  UpdatesAdminController::class, 'settings');
        $router->post('/admin/updates/settings', UpdatesAdminController::class, 'settings');

        // Collective — specific paths first
        $router->get('/admin/updates/collective/add',  UpdatesAdminController::class, 'collectiveAddForm');
        $router->post('/admin/updates/collective/add', UpdatesAdminController::class, 'collectiveAdd');
        $router->get('/admin/updates/collective',      UpdatesAdminController::class, 'collectiveList');

        // Entry-level edit/delete — before {fid} routes
        $router->get('/admin/updates/entry/{id}/edit',  UpdatesAdminController::class, 'editForm');
        $router->post('/admin/updates/entry/{id}/edit',  UpdatesAdminController::class, 'edit');
        $router->post('/admin/updates/entry/{id}/delete', UpdatesAdminController::class, 'delete');

        $router->post('/admin/updates/bulk', UpdatesAdminController::class, 'bulkDelete');

        // Per-fanlisting — add before list so 'add' is not matched as {fid}
        $router->get('/admin/updates/{fid}/add',  UpdatesAdminController::class, 'addForm');
        $router->post('/admin/updates/{fid}/add', UpdatesAdminController::class, 'add');
        $router->get('/admin/updates/{fid}',      UpdatesAdminController::class, 'list');
    }
}
