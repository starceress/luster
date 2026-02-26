<?php

declare(strict_types=1);

use Luster\Core\Router;

/**
 * Route definitions for Luster.
 *
 * Returns an array of two callables:
 *   'public' — registered by public/index.php
 *   'admin'  — registered by public/admin.php
 *
 * Controller classes are added in Phase 4 (public) and Phase 5 (admin).
 * Placeholder closures are used here so the Router can be exercised
 * without real controllers.
 */

return [

    // ------------------------------------------------------------------
    // Public routes  (Phase 4)
    // ------------------------------------------------------------------
    'public' => function (Router $router): void {
        // $router->get('/',                    Controllers\Public\JoinController::class,    'index');
        // $router->post('/join/{slug}',         Controllers\Public\JoinController::class,    'submit');
        // $router->get('/members/{slug}',       Controllers\Public\MembersController::class, 'index');
        // $router->get('/update/{slug}',        Controllers\Public\UpdateController::class,  'index');
        // $router->post('/update/{slug}',       Controllers\Public\UpdateController::class,  'submit');
        // $router->get('/remove/{slug}',        Controllers\Public\RemoveController::class,  'index');
        // $router->post('/remove/{slug}',       Controllers\Public\RemoveController::class,  'submit');
    },

    // ------------------------------------------------------------------
    // Admin routes  (Phase 5)
    // ------------------------------------------------------------------
    'admin' => function (Router $router): void {
        // $router->get('/admin',                        Controllers\Admin\DashboardController::class,  'index');
        // $router->get('/admin/login',                  Controllers\Admin\AuthController::class,       'loginForm');
        // $router->post('/admin/login',                 Controllers\Admin\AuthController::class,       'login');
        // $router->post('/admin/logout',                Controllers\Admin\AuthController::class,       'logout');
        // $router->get('/admin/fanlistings',            Controllers\Admin\FanlistingController::class, 'index');
        // $router->get('/admin/fanlistings/{id}/edit',  Controllers\Admin\FanlistingController::class, 'edit');
        // $router->post('/admin/fanlistings/{id}/edit', Controllers\Admin\FanlistingController::class, 'update');
        // $router->get('/admin/members',                Controllers\Admin\MemberController::class,     'index');
        // $router->get('/admin/affiliates',             Controllers\Admin\AffiliateController::class,  'index');
        // $router->get('/admin/settings',               Controllers\Admin\SettingsController::class,   'index');
        // $router->post('/admin/settings',              Controllers\Admin\SettingsController::class,   'update');
    },

];
