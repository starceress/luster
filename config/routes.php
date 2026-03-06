<?php

declare(strict_types=1);

use Luster\Controllers\Admin\ActivityLogController;
use Luster\Controllers\Admin\AffiliateController;
use Luster\Controllers\Admin\ErrorLogController;
use Luster\Controllers\Admin\SysInfoController;
use Luster\Controllers\Admin\AuthController;
use Luster\Controllers\Admin\CategoryController;
use Luster\Controllers\Admin\DashboardController;
use Luster\Controllers\Admin\FanlistingController;
use Luster\Controllers\Admin\FieldController;
use Luster\Controllers\Admin\JoinedController;
use Luster\Controllers\Admin\MemberController;
use Luster\Controllers\Admin\MembersIndexController;
use Luster\Controllers\Admin\SettingsController;
use Luster\Controllers\Public\JoinController;
use Luster\Controllers\Public\MembersController;
use Luster\Controllers\Public\RemoveController;
use Luster\Controllers\Public\StatsController;
use Luster\Controllers\Public\UpdateController;
use Luster\Core\Router;

/**
 * Route definitions for Luster.
 *
 * Returns an array of two callables:
 *   'public' — registered by public/index.php
 *   'admin'  — registered by public/admin.php
 */

return [

    // ------------------------------------------------------------------
    // Public routes  (Phase 4)
    // ------------------------------------------------------------------
    'public' => function (Router $router): void {
        // Join
        $router->get('/join/{id}',            JoinController::class,    'showForm');
        $router->post('/join/{id}',           JoinController::class,    'submit');
        $router->get('/join/{id}/success',    JoinController::class,    'success');

        // Members list
        $router->get('/members/{id}',         MembersController::class, 'index');

        // Stats
        $router->get('/stats/{fanlistingId}', StatsController::class,   'index');

        // Update membership
        $router->get('/update/{id}',          UpdateController::class,  'showForm');
        $router->post('/update/{id}',         UpdateController::class,  'submit');
        $router->get('/update/{id}/success',  UpdateController::class,  'success');

        // Remove membership
        $router->get('/remove/{id}',          RemoveController::class,  'showForm');
        $router->post('/remove/{id}',         RemoveController::class,  'submit');
        $router->get('/remove/{id}/success',  RemoveController::class,  'success');
    },

    // ------------------------------------------------------------------
    // Admin routes  (Phase 2 auth + Phase 5 full panel)
    // ------------------------------------------------------------------
    'admin' => function (Router $router): void {

        // ── Authentication ──────────────────────────────────────────
        $router->get('/admin',         DashboardController::class, 'index');
        $router->get('/admin/login',   AuthController::class,      'loginForm');
        $router->post('/admin/login',  AuthController::class,      'login');
        $router->post('/admin/logout', AuthController::class,      'logout');

        // ── Fanlistings ─────────────────────────────────────────────
        $router->get('/admin/fanlistings',
            FanlistingController::class, 'index');
        $router->get('/admin/fanlistings/{id}',
            FanlistingController::class, 'show');
        $router->get('/admin/fanlistings/{id}/edit',
            FanlistingController::class, 'edit');
        $router->post('/admin/fanlistings/{id}/edit',
            FanlistingController::class, 'edit');
        $router->post('/admin/fanlistings/{id}/templates',
            FanlistingController::class, 'saveTemplates');
        $router->post('/admin/fanlistings/{id}/update',
            FanlistingController::class, 'update');
        $router->post('/admin/fanlistings/{id}/delete',
            FanlistingController::class, 'delete');
        $router->get('/admin/fanlistings/{id}/edit-prefix',
            FanlistingController::class, 'editPrefix');
        $router->post('/admin/fanlistings/{id}/edit-prefix',
            FanlistingController::class, 'editPrefix');

        // ── Members top-level (fanlisting picker) ───────────────────
        $router->get('/admin/members',
            MembersIndexController::class, 'index');

        // ── Members for a specific fanlisting ───────────────────────
        $router->get('/admin/members/{id}',
            MemberController::class, 'index');
        $router->get('/admin/members/{id}/edit',
            MemberController::class, 'edit');
        $router->post('/admin/members/{id}/edit',
            MemberController::class, 'edit');
        $router->post('/admin/members/{id}/approve',
            MemberController::class, 'approve');
        $router->post('/admin/members/{id}/reject',
            MemberController::class, 'reject');
        $router->post('/admin/members/{id}/delete',
            MemberController::class, 'deleteMember');
        $router->post('/admin/members/{id}/bulk',
            MemberController::class, 'bulk');
        $router->get('/admin/members/{id}/export',
            MemberController::class, 'export');

        // ── Additional fields for a specific fanlisting ──────────────
        $router->get('/admin/fanlistings/{fid}/fields',
            FieldController::class, 'index');
        $router->post('/admin/fanlistings/{fid}/fields/add',
            FieldController::class, 'add');
        $router->get('/admin/fanlistings/{fid}/fields/{name}/edit',
            FieldController::class, 'edit');
        $router->post('/admin/fanlistings/{fid}/fields/{name}/edit',
            FieldController::class, 'edit');
        $router->post('/admin/fanlistings/{fid}/fields/{name}/delete',
            FieldController::class, 'delete');

        // ── Per-fanlisting affiliate add (must be before /affiliates routes
        //    to avoid route ordering issues, and before /fanlistings/{id}) ──
        $router->get('/admin/fanlistings/{fid}/affiliates/add',
            AffiliateController::class, 'add');
        $router->post('/admin/fanlistings/{fid}/affiliates/add',
            AffiliateController::class, 'add');

        // ── Affiliates ──────────────────────────────────────────────
        $router->get('/admin/affiliates',
            AffiliateController::class, 'index');
        $router->get('/admin/affiliates/add',
            AffiliateController::class, 'add');
        $router->post('/admin/affiliates/add',
            AffiliateController::class, 'add');
        $router->get('/admin/affiliates/{id}/edit',
            AffiliateController::class, 'editAffiliate');
        $router->post('/admin/affiliates/{id}/edit',
            AffiliateController::class, 'editAffiliate');
        $router->post('/admin/affiliates/{id}/toggle',
            AffiliateController::class, 'toggle');
        $router->post('/admin/affiliates/{id}/delete',
            AffiliateController::class, 'deleteAffiliate');

        // ── Settings ────────────────────────────────────────────────
        $router->get('/admin/settings',
            SettingsController::class, 'index');
        $router->post('/admin/settings',
            SettingsController::class, 'update');

        // ── Joined fanlistings ───────────────────────────────────────
        $router->get('/admin/joined',
            JoinedController::class, 'index');
        $router->get('/admin/joined/add',
            JoinedController::class, 'add');
        $router->post('/admin/joined/add',
            JoinedController::class, 'add');
        $router->get('/admin/joined/{id}/edit',
            JoinedController::class, 'edit');
        $router->post('/admin/joined/{id}/edit',
            JoinedController::class, 'edit');
        $router->post('/admin/joined/{id}/delete',
            JoinedController::class, 'delete');
        $router->post('/admin/joined/{id}/toggle',
            JoinedController::class, 'toggle');

        // ── Categories ───────────────────────────────────────────────
        $router->get('/admin/categories',
            CategoryController::class, 'index');
        $router->post('/admin/categories/add',
            CategoryController::class, 'add');
        $router->post('/admin/categories/{id}/delete',
            CategoryController::class, 'delete');

        // ── Activity log ─────────────────────────────────────────────
        $router->get('/admin/activity',
            ActivityLogController::class, 'index');

        // ── Error log ────────────────────────────────────────────────
        $router->get('/admin/errorlog',
            ErrorLogController::class, 'index');
        $router->post('/admin/errorlog/flush',
            ErrorLogController::class, 'flush');

        // ── System info ──────────────────────────────────────────────
        $router->get('/admin/sysinfo',
            SysInfoController::class, 'index');
    },

];
