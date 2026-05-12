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
                `applied_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `status`        ENUM('new','reviewed','approved','rejected') NOT NULL DEFAULT 'new',
                PRIMARY KEY (`id`),
                INDEX `idx_adoption_listing` (`fanlisting_id`),
                INDEX `idx_adoption_status`  (`status`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
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

        // Admin routes — specific action paths before parameterised {listingId}
        $router->post('/admin/adoptions/{id}/status',
            AdoptionAdminController::class, 'setStatus');
        $router->post('/admin/adoptions/{id}/delete',
            AdoptionAdminController::class, 'delete');
        $router->get('/admin/adoptions/{listingId}',
            AdoptionAdminController::class, 'forListing');
        $router->get('/admin/adoptions',
            AdoptionAdminController::class, 'index');
    }
}
