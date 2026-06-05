<?php

declare(strict_types=1);

namespace Luster\Addons\Adoption;

use Luster\Addons\Adoption\Controllers\AdoptionAdminController;
use Luster\Addons\Adoption\Controllers\AdoptPublicController;
use Luster\Core\AddonInterface;
use Luster\Core\Router;
use PDO;

/**
 * Adoption System add-on.
 *
 * Allows individual listings to accept public adoption applications.
 * Adds a per-listing toggle in the listing edit page, a public application
 * form, and an admin section for reviewing and managing applications.
 */
final class AdoptionAddon implements AddonInterface
{
    public function getKey(): string
    {
        return 'Adoption';
    }

    public function getName(): string
    {
        return 'Adoption System';
    }

    public function getDescription(): string
    {
        return 'Let visitors apply to adopt individual fanlistings. Adds a per-listing toggle, a public application form, and an admin panel for reviewing applications.';
    }

    public function install(PDO $pdo): void
    {
        // Add for_adoption column to lstr_fanlisting_settings (idempotent)
        $colCheck = $pdo->query("SHOW COLUMNS FROM `lstr_fanlisting_settings` LIKE 'for_adoption'")->fetchAll();
        if (empty($colCheck)) {
            $pdo->exec(
                "ALTER TABLE `lstr_fanlisting_settings`
                 ADD COLUMN `for_adoption` TINYINT(1) NOT NULL DEFAULT 0 AFTER `approved_by`"
            );
        }

        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS `lstr_adoption_applications` (
                `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `fanlisting_id` INT NOT NULL,
                `name`          VARCHAR(255) NOT NULL DEFAULT '',
                `email`         VARCHAR(255) NOT NULL DEFAULT '',
                `url`           VARCHAR(255) NOT NULL DEFAULT '',
                `message`       TEXT NULL,
                `admin_notes`   TEXT NULL,
                `applied_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `status`        ENUM('pending','reviewed','approved','rejected','granted') NOT NULL DEFAULT 'pending',
                PRIMARY KEY (`id`),
                INDEX `idx_adoption_listing` (`fanlisting_id`),
                INDEX `idx_adoption_status`  (`status`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        // Idempotent migrations for existing installs
        $colType = $pdo->query(
            "SELECT COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME   = 'lstr_adoption_applications'
                AND COLUMN_NAME  = 'status'"
        )->fetchColumn();
        $colType = (string) $colType;

        // Migrate legacy 'new' rows to 'pending'; 'reviewed' rows are kept as-is
        if (strpos($colType, "'new'") !== false) {
            $pdo->exec(
                "UPDATE `lstr_adoption_applications` SET status = 'pending' WHERE status = 'new'"
            );
        }

        // Rebuild ENUM to canonical set if it doesn't match
        if ($colType !== "enum('pending','reviewed','approved','rejected','granted')") {
            $pdo->exec(
                "ALTER TABLE `lstr_adoption_applications`
                 MODIFY COLUMN `status`
                   ENUM('pending','reviewed','approved','rejected','granted') NOT NULL DEFAULT 'pending'"
            );
        }

        // Idempotent: add admin_notes column
        $hasNotes = $pdo->query(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME   = 'lstr_adoption_applications'
                AND COLUMN_NAME  = 'admin_notes'"
        )->fetchColumn();
        if (!$hasNotes) {
            $pdo->exec(
                "ALTER TABLE `lstr_adoption_applications`
                 ADD COLUMN `admin_notes` TEXT NULL AFTER `message`"
            );
        }
    }

    public function uninstall(PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS `lstr_adoption_applications`');

        $colCheck = $pdo->query("SHOW COLUMNS FROM `lstr_fanlisting_settings` LIKE 'for_adoption'")->fetchAll();
        if (!empty($colCheck)) {
            $pdo->exec("ALTER TABLE `lstr_fanlisting_settings` DROP COLUMN `for_adoption`");
        }
    }

    public function registerRoutes(Router $router): void
    {
        // Public routes — /sent before the base route
        $router->get('/adopt/{id}/sent',  AdoptPublicController::class, 'sent');
        $router->get('/adopt/{id}',       AdoptPublicController::class, 'showForm');
        $router->post('/adopt/{id}',      AdoptPublicController::class, 'submit');

        // Admin routes — literal/specific paths before parameterised routes
        $router->post('/admin/adoptions/bulk',
            AdoptionAdminController::class, 'bulk');
        $router->get('/admin/adoptions/settings',
            AdoptionAdminController::class, 'settingsForm');
        $router->post('/admin/adoptions/settings',
            AdoptionAdminController::class, 'saveSettings');
        $router->get('/admin/adoptions/{id}/edit',
            AdoptionAdminController::class, 'editForm');
        $router->post('/admin/adoptions/{id}/edit',
            AdoptionAdminController::class, 'editSave');
        $router->post('/admin/adoptions/{id}/approve',
            AdoptionAdminController::class, 'approve');
        $router->post('/admin/adoptions/{id}/reject',
            AdoptionAdminController::class, 'reject');
        $router->post('/admin/adoptions/{id}/status',
            AdoptionAdminController::class, 'setStatus');
        $router->post('/admin/adoptions/{id}/delete',
            AdoptionAdminController::class, 'delete');
        $router->get('/admin/adoptions',
            AdoptionAdminController::class, 'index');
    }
}
