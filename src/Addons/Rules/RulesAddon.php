<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Addon entry point for the Join Rules system. Implements AddonInterface
 to create the lstr_rules table and register public and admin routes.
 When installed, listing owners can define per-listing rules that fans
 must read and agree to before joining. Rule body edits bump the version
 number, automatically invalidating prior session-based agreements.
*/

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
    /** Returns the unique string key used in lstr_addons and class discovery. */
    public function getKey(): string
    {
        return 'Rules';
    }

    /** Returns the human-readable add-on name shown in the admin nav and Manage Add-ons page. */
    public function getName(): string
    {
        return 'Join Rules';
    }

    /** Returns the description shown on the add-on card in the admin panel. */
    public function getDescription(): string
    {
        return 'Add per-listing rules that fans must read and agree to before joining. Adds a global rules editor, a per-listing rules editor, and an admin panel for managing and customizing rules.';
    }

    /**
     * Creates lstr_rules with all required columns. Runs INFORMATION_SCHEMA
     * checks for each optional column so re-running install on an existing
     * database is safe. Also removes the legacy title column if it exists.
     */
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

    /** Drops lstr_rules. */
    public function uninstall(PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS `lstr_rules`');
    }

    /** Registers public (/rules/*) and admin (/admin/rules/*) routes, gated by $context. */
    public function registerRoutes(Router $router, string $context): void
    {
        if ($context === 'public') {
            // Public (POST only — rules gate lives on the owner's site via embed)
            $router->post('/rules/{id}/agree', RulesPublicController::class, 'agree');
            return;
        }

        if ($context !== 'admin') {
            return;
        }

        // Admin — literal paths before parameterised
        $router->get('/admin/rules',              RulesAdminController::class, 'index');
        $router->post('/admin/rules/bulk',        RulesAdminController::class, 'bulk');
        $router->get('/admin/rules/settings',     RulesAdminController::class, 'settingsForm');
        $router->post('/admin/rules/settings',    RulesAdminController::class, 'settingsSave');
        $router->get('/admin/rules/global',       RulesAdminController::class, 'globalForm');
        $router->post('/admin/rules/global',      RulesAdminController::class, 'globalSave');
        $router->post('/admin/rules/global/preview', RulesAdminController::class, 'globalPreview');
        $router->get('/admin/rules/{id}/edit',    RulesAdminController::class, 'editForm');
        $router->post('/admin/rules/{id}/edit',   RulesAdminController::class, 'editSave');
        $router->post('/admin/rules/{id}/toggle', RulesAdminController::class, 'toggle');
        $router->post('/admin/rules/{id}/delete', RulesAdminController::class, 'delete');
    }
}
