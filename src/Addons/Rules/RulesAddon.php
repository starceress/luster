<?php

declare(strict_types=1);

namespace Luster\Addons\Rules;

use Luster\Addons\Rules\Controllers\RulesAdminController;
use Luster\Addons\Rules\Controllers\RulesPublicController;
use Luster\Core\AddonInterface;
use Luster\Core\Router;
use PDO;

/**
 * Rules add-on.
 *
 * Lets listing owners define rules that fans must agree to.
 * Agreement is tracked per session and per rule-set version:
 * editing the rule body invalidates existing agreements and fans
 * must re-agree on their next visit.
 */
final class RulesAddon implements AddonInterface
{
    public function getKey(): string
    {
        return 'Rules';
    }

    public function getName(): string
    {
        return 'Join Rules';
    }

    public function getDescription(): string
    {
        return 'Add per-listing rules that fans must read and agree to. When you update the rules text, existing agreements are invalidated so fans must re-agree on their next visit.';
    }

    public function install(PDO $pdo): void
    {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS `lstr_rules` (
                `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `fanlisting_id` INT NOT NULL,
                `body`          TEXT NOT NULL,
                `enabled`       TINYINT(1) NOT NULL DEFAULT 1,
                `list_style`    ENUM('numbered','circle') NOT NULL DEFAULT 'numbered',
                `gate_type`     ENUM('checkbox','button') NOT NULL DEFAULT 'checkbox',
                `gate_label`    VARCHAR(255) NOT NULL DEFAULT '',
                `version`       INT UNSIGNED NOT NULL DEFAULT 1,
                `updated_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uk_rules_listing` (`fanlisting_id`),
                KEY `idx_rules_enabled` (`enabled`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        // Idempotent migrations for existing installs
        $hasListStyle = $pdo->query(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME   = 'lstr_rules'
                AND COLUMN_NAME  = 'list_style'"
        )->fetchColumn();
        if (!$hasListStyle) {
            $pdo->exec(
                "ALTER TABLE `lstr_rules`
                 ADD COLUMN `list_style` ENUM('numbered','circle') NOT NULL DEFAULT 'numbered' AFTER `enabled`"
            );
        }

        $hasGateType = $pdo->query(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME   = 'lstr_rules'
                AND COLUMN_NAME  = 'gate_type'"
        )->fetchColumn();
        if (!$hasGateType) {
            $pdo->exec(
                "ALTER TABLE `lstr_rules`
                 ADD COLUMN `gate_type` ENUM('checkbox','button') NOT NULL DEFAULT 'checkbox' AFTER `list_style`"
            );
        }

        $hasGateLabel = $pdo->query(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME   = 'lstr_rules'
                AND COLUMN_NAME  = 'gate_label'"
        )->fetchColumn();
        if (!$hasGateLabel) {
            $pdo->exec(
                "ALTER TABLE `lstr_rules`
                 ADD COLUMN `gate_label` VARCHAR(255) NOT NULL DEFAULT '' AFTER `gate_type`"
            );
        }

        $hasTitle = $pdo->query(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME   = 'lstr_rules'
                AND COLUMN_NAME  = 'title'"
        )->fetchColumn();
        if ($hasTitle) {
            $pdo->exec('ALTER TABLE `lstr_rules` DROP COLUMN `title`');
        }
    }

    public function uninstall(PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS `lstr_rules`');
    }

    public function registerRoutes(Router $router): void
    {
        // Public
        $router->get('/rules/{id}',       RulesPublicController::class, 'gate');
        $router->post('/rules/{id}/agree', RulesPublicController::class, 'agree');

        // Admin — literal paths before parameterised
        $router->get('/admin/rules',              RulesAdminController::class, 'index');
        $router->get('/admin/rules/{id}/edit',    RulesAdminController::class, 'editForm');
        $router->post('/admin/rules/{id}/edit',   RulesAdminController::class, 'editSave');
        $router->post('/admin/rules/{id}/toggle', RulesAdminController::class, 'toggle');
        $router->post('/admin/rules/{id}/delete', RulesAdminController::class, 'delete');
    }
}
