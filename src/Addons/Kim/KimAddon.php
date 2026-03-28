<?php

declare(strict_types=1);

namespace Luster\Addons\Kim;

use Luster\Addons\Kim\Controllers\KimAdminController;
use Luster\Addons\Kim\Controllers\KimListController;
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
    public function getKey(): string
    {
        return 'Kim';
    }

    public function getName(): string
    {
        return 'Keep In Mind (KIM)';
    }

    public function getDescription(): string
    {
        return 'KIM helps manage a registry of fanlisting owners who are interested in adopting your fanlisting(s) if it were to become available for adoption.';
    }

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

    public function uninstall(PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS `lstr_kim_password_resets`');
        $pdo->exec('DROP TABLE IF EXISTS `lstr_kim_members`');
    }

    public function registerRoutes(Router $router): void
    {
        // ── Public routes ─────────────────────────────────────────────────
        // Order matters: specific paths before dynamic ones.
        $router->get('/kim/success',        KimSignupController::class, 'success');
        $router->get('/kim/list/{id}',      KimListController::class,   'subject');
        $router->get('/kim/list',           KimListController::class,   'index');
        $router->get('/kim/update/success', KimUpdateController::class, 'success');
        $router->get('/kim/update',         KimUpdateController::class, 'showForm');
        $router->post('/kim/update',        KimUpdateController::class, 'submit');
        $router->get('/kim/reset/sent',     KimResetController::class,  'sent');
        $router->get('/kim/reset/confirm',  KimResetController::class,  'showConfirm');
        $router->post('/kim/reset/confirm', KimResetController::class,  'submitConfirm');
        $router->get('/kim/reset/done',     KimResetController::class,  'done');
        $router->get('/kim/reset',          KimResetController::class,  'showForm');
        $router->post('/kim/reset',         KimResetController::class,  'submit');
        $router->get('/kim',                KimSignupController::class, 'showForm');
        $router->post('/kim',               KimSignupController::class, 'submit');

        // ── Admin routes ──────────────────────────────────────────────────
        $router->get('/admin/kim',                      KimAdminController::class, 'index');
        $router->get('/admin/kim/settings',             KimAdminController::class, 'showSettings');
        $router->post('/admin/kim/settings',            KimAdminController::class, 'saveSettings');
        $router->get('/admin/kim/{fid}/mass-email',     KimAdminController::class, 'massEmailForm');
        $router->post('/admin/kim/{fid}/mass-email',    KimAdminController::class, 'massEmail');
        $router->get('/admin/kim/{fid}/show',           KimAdminController::class, 'show');
        $router->get('/admin/kim/{id}/edit',            KimAdminController::class, 'edit');
        $router->post('/admin/kim/{id}/edit',           KimAdminController::class, 'edit');
        $router->post('/admin/kim/{id}/approve',        KimAdminController::class, 'approve');
        $router->post('/admin/kim/{id}/delete',         KimAdminController::class, 'delete');
        $router->get('/admin/kim/{id}/email-member',    KimAdminController::class, 'emailMemberForm');
        $router->post('/admin/kim/{id}/email-member',   KimAdminController::class, 'emailMember');
    }
}
