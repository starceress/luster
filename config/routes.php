<?php

declare(strict_types=1);

use Luster\Controllers\Admin\AuthController;
use Luster\Controllers\Admin\DashboardController;
use Luster\Controllers\Public\JoinController;
use Luster\Controllers\Public\MembersController;
use Luster\Controllers\Public\RemoveController;
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
    // Admin routes  (Phase 2 — auth; Phase 5 — full panel)
    // ------------------------------------------------------------------
    'admin' => function (Router $router): void {
        // Authentication (Phase 2)
        $router->get('/admin',         DashboardController::class, 'index');
        $router->get('/admin/login',   AuthController::class,      'loginForm');
        $router->post('/admin/login',  AuthController::class,      'login');
        $router->post('/admin/logout', AuthController::class,      'logout');

        // Phase 5 — uncomment as controllers are built
        // $router->get('/admin/fanlistings',            Controllers\Admin\FanlistingController::class, 'index');
        // $router->get('/admin/fanlistings/{id}/edit',  Controllers\Admin\FanlistingController::class, 'edit');
        // $router->post('/admin/fanlistings/{id}/edit', Controllers\Admin\FanlistingController::class, 'update');
        // $router->get('/admin/members',                Controllers\Admin\MemberController::class,     'index');
        // $router->get('/admin/affiliates',             Controllers\Admin\AffiliateController::class,  'index');
        // $router->get('/admin/settings',               Controllers\Admin\SettingsController::class,   'index');
        // $router->post('/admin/settings',              Controllers\Admin\SettingsController::class,   'update');
    },

];
