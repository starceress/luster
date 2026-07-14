<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Addon entry point for KIM (Keep In Mind). Implements AddonInterface to
 create the lstr_kim_members and lstr_kim_password_resets tables, and
 registers all public and admin routes for the KIM sign-up, update,
 reset, list, and management workflows.
*/

declare(strict_types=1);

namespace Luster\Addons\Kim;

use Luster\Addons\Kim\Controllers\KimAdminController;
use Luster\Addons\Kim\Controllers\KimResetController;
use Luster\Addons\Kim\Controllers\KimSignupController;
use Luster\Addons\Kim\Controllers\KimUpdateController;
use Luster\Core\AddonInterface;
use Luster\Core\Router;
use PDO;

/**
 * KIM List add-on.
 *
 * KIM (Keep In Mind) is a collective-wide adoption interest registry.
 * When a fanlisting owner might someday give up their fanlisting, other
 * owners can sign up to express interest in adopting it.
 */
final class KimAddon implements AddonInterface
{
    /** Returns the unique string key used in lstr_addons and class discovery. */
    public function getKey(): string
    {
        return 'Kim';
    }

    /** Returns the human-readable add-on name shown in the admin nav and Manage Add-ons page. */
    public function getName(): string
    {
        return 'Keep In Mind (KIM)';
    }

    /** Returns the description shown on the add-on card. */
    public function getDescription(): string
    {
        return 'A KIM List helps manage a registry of individuals who are interested in adopting your fanlisting(s). Adds a collective-side sign-up form and an admin panel for managing sign-ups.';
    }

    /** Creates lstr_kim_members and lstr_kim_password_resets (with CASCADE FK). */
    public function install(PDO $pdo): void
    {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS `lstr_kim_members` (
                `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `fanlisting_id` INT UNSIGNED NOT NULL,
                `name`          VARCHAR(255) NOT NULL,
                `email`         VARCHAR(255) NOT NULL,
                `url`           VARCHAR(500) NOT NULL DEFAULT '',
                `password`      VARCHAR(255) NOT NULL,
                `pending`       TINYINT(1)   NOT NULL DEFAULT 1,
                `granted`       TINYINT(1)   NOT NULL DEFAULT 0,
                `added_at`      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uk_email_fanlisting` (`email`, `fanlisting_id`),
                KEY `idx_fanlisting` (`fanlisting_id`),
                KEY `idx_pending` (`pending`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS `lstr_kim_password_resets` (
                `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `kim_member_id` INT UNSIGNED NOT NULL,
                `token_hash`    VARCHAR(64)  NOT NULL,
                `expires_at`    DATETIME     NOT NULL,
                `created_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uk_token_hash` (`token_hash`),
                KEY `idx_kim_member` (`kim_member_id`),
                CONSTRAINT `fk_kpr_kim_member`
                    FOREIGN KEY (`kim_member_id`) REFERENCES `lstr_kim_members` (`id`)
                    ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    }

    /** Drops lstr_kim_password_resets then lstr_kim_members (FK order). */
    public function uninstall(PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS `lstr_kim_password_resets`');
        $pdo->exec('DROP TABLE IF EXISTS `lstr_kim_members`');
    }

    /** Registers all public (/kim/*) and admin (/admin/kim/*) routes, gated by $context. */
    public function registerRoutes(Router $router, string $context): void
    {
        if ($context === 'public') {
            // Public routes (POST only — fan-facing pages live on the owner's site via embeds)
            $router->post('/kim/update/sign-in', KimUpdateController::class, 'submitSignIn');
            $router->post('/kim/update/save',    KimUpdateController::class, 'submitSave');
            $router->post('/kim/reset/confirm', KimResetController::class,  'submitConfirm');
            $router->post('/kim/reset',         KimResetController::class,  'submit');
            $router->post('/kim',               KimSignupController::class, 'submit');
            return;
        }

        if ($context !== 'admin') {
            return;
        }

        // --- Admin routes
        $router->get('/admin/kim',                      KimAdminController::class, 'index');
        $router->get('/admin/kim/listings',             KimAdminController::class, 'listings');
        $router->get('/admin/kim/add',                  KimAdminController::class, 'addForm');
        $router->post('/admin/kim/add',                 KimAdminController::class, 'add');
        $router->get('/admin/kim/settings',             KimAdminController::class, 'showSettings');
        $router->post('/admin/kim/settings',            KimAdminController::class, 'saveSettings');
        $router->post('/admin/kim/bulk',                KimAdminController::class, 'bulkGlobal');
        $router->get('/admin/kim/email-selected',       KimAdminController::class, 'emailSelectedGlobalForm');
        $router->post('/admin/kim/email-selected',      KimAdminController::class, 'emailSelectedGlobalSend');
        $router->get('/admin/kim/{fid}/bulk-email',     KimAdminController::class, 'massEmailForm');
        $router->post('/admin/kim/{fid}/bulk-email',    KimAdminController::class, 'massEmail');
        $router->get('/admin/kim/{fid}/show',           KimAdminController::class, 'show');
        $router->get('/admin/kim/{id}/edit',            KimAdminController::class, 'edit');
        $router->post('/admin/kim/{id}/edit',           KimAdminController::class, 'edit');
        $router->post('/admin/kim/{fid}/bulk',           KimAdminController::class, 'bulk');
        $router->post('/admin/kim/{id}/approve',        KimAdminController::class, 'approve');
        $router->post('/admin/kim/{id}/delete',         KimAdminController::class, 'delete');
        $router->get('/admin/kim/{id}/email-member',    KimAdminController::class, 'emailMemberForm');
        $router->post('/admin/kim/{id}/email-member',   KimAdminController::class, 'emailMember');
    }
}
