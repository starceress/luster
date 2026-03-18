<?php

declare(strict_types=1);

use Luster\Controllers\Admin\AddonsController;
use Luster\Controllers\Admin\ActivityLogController;
use Luster\Controllers\Admin\MassEmailController;
use Luster\Controllers\Admin\AdminUsersController;
use Luster\Controllers\Admin\BlacklistController;
use Luster\Controllers\Admin\EmailTemplatesController;
use Luster\Controllers\Admin\AffiliateController;
use Luster\Controllers\Admin\CodesController;
use Luster\Controllers\Admin\EmbedCodesController;
use Luster\Controllers\Admin\PendingUpdateController;
use Luster\Controllers\Admin\ErrorLogController;
use Luster\Controllers\Admin\SysInfoController;
use Luster\Controllers\Admin\AuthController;
use Luster\Controllers\Admin\CategoryController;
use Luster\Controllers\Admin\DashboardController;
use Luster\Controllers\Admin\ListingController;
use Luster\Controllers\Admin\FieldController;
use Luster\Controllers\Admin\JoinedController;
use Luster\Controllers\Admin\MemberController;
use Luster\Controllers\Admin\SettingsController;
use Luster\Controllers\Public\AffiliatesController;
use Luster\Controllers\Public\JoinController;
use Luster\Controllers\Public\MembersController;
use Luster\Controllers\Public\RemoveController;
use Luster\Controllers\Public\ResetController;
use Luster\Controllers\Public\CodesSubmitController;
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
        $router->get('/stats/{listingId}', StatsController::class,   'index');

        // Update membership
        $router->get('/update/{id}',          UpdateController::class,  'showForm');
        $router->post('/update/{id}',         UpdateController::class,  'submit');
        $router->get('/update/{id}/success',  UpdateController::class,  'success');

        // Remove membership
        $router->get('/remove/{id}',          RemoveController::class,  'showForm');
        $router->post('/remove/{id}',         RemoveController::class,  'submit');
        $router->get('/remove/{id}/success',  RemoveController::class,  'success');

        // Affiliates — collective route before the per-fanlisting route
        $router->get('/affiliates',      AffiliatesController::class, 'collective');
        $router->get('/affiliates/{id}', AffiliatesController::class, 'index');

        // Code submission (fan-facing) — /thanks must be before the base /{id} route
        $router->get('/codes/submit/{id}/thanks', CodesSubmitController::class, 'thanks');
        $router->get('/codes/submit/{id}',        CodesSubmitController::class, 'showForm');
        $router->post('/codes/submit/{id}',       CodesSubmitController::class, 'submit');

        // Password reset — specific sub-paths registered before the base route
        $router->get('/reset/{id}/sent',      ResetController::class,   'sent');
        $router->get('/reset/{id}/confirm',   ResetController::class,   'showConfirm');
        $router->post('/reset/{id}/confirm',  ResetController::class,   'submitConfirm');
        $router->get('/reset/{id}/done',      ResetController::class,   'done');
        $router->get('/reset/{id}',           ResetController::class,   'showForm');
        $router->post('/reset/{id}',          ResetController::class,   'submit');
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
        $router->get('/admin/forgot',          AuthController::class,      'forgotForm');
        $router->post('/admin/forgot',         AuthController::class,      'forgot');
        $router->get('/admin/reset/{token}',   AuthController::class,      'resetForm');
        $router->post('/admin/reset/{token}',  AuthController::class,      'reset');

        // ── Maintenance ──────────────────────────────────────────────
        $router->post('/admin/maintenance/touch-all-updated',
            DashboardController::class, 'touchAllUpdated');

        // ── Listings ────────────────────────────────────────────────
        $router->get('/admin/listings',
            ListingController::class, 'index');
        $router->get('/admin/listings/add',      ListingController::class, 'addForm');
        $router->post('/admin/listings/add',     ListingController::class, 'add');
        $router->get('/admin/listings/settings', ListingController::class, 'indexSettings');
        $router->post('/admin/listings/settings', ListingController::class, 'saveSettings');
        $router->post('/admin/listings/bulk', ListingController::class, 'bulk');
        $router->get('/admin/listings/{id}',
            ListingController::class, 'show');
        $router->get('/admin/listings/{id}/edit',
            ListingController::class, 'edit');
        $router->post('/admin/listings/{id}/edit',
            ListingController::class, 'edit');
        $router->post('/admin/listings/{id}/templates',
            ListingController::class, 'saveTemplates');
        // Old per-listing email-template routes — redirect to unified page
        $router->get('/admin/listings/{id}/email-templates',
            ListingController::class, 'emailTemplatesRedirect');
        $router->post('/admin/listings/{id}/email-templates',
            ListingController::class, 'emailTemplatesRedirect');
        $router->post('/admin/listings/{id}/embed',
            ListingController::class, 'saveEmbed');
        $router->post('/admin/listings/{id}/update',
            ListingController::class, 'update');
        $router->post('/admin/listings/{id}/delete',
            ListingController::class, 'delete');
        $router->get('/admin/listings/{id}/edit-prefix',
            ListingController::class, 'editPrefix');
        $router->post('/admin/listings/{id}/edit-prefix',
            ListingController::class, 'editPrefix');

        // ── Members overview (pending across all listings) ────────
        $router->get('/admin/members',
            MemberController::class, 'overview');
        $router->get('/admin/members/settings',
            MemberController::class, 'indexSettings');
        $router->post('/admin/members/settings',
            MemberController::class, 'saveSettings');

        // ── Members for a specific listing ───────────────────────
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
        $router->post('/admin/members/{id}/merge',
            MemberController::class, 'merge');
        $router->post('/admin/members/{id}/bulk',
            MemberController::class, 'bulk');
        $router->post('/admin/members/{id}/approve-all-pending',
            MemberController::class, 'approveAllPending');
        $router->get('/admin/members/{id}/export',
            MemberController::class, 'export');
        $router->get('/admin/members/export-pending',
            MemberController::class, 'exportPending');
        $router->get('/admin/members/{id}/emailmember',
            MemberController::class, 'emailMemberForm');
        $router->post('/admin/members/{id}/emailmember',
            MemberController::class, 'emailMemberSend');

        // import/template must be before import so 'template' isn't treated as a sub-param
        $router->get('/admin/members/{id}/import/template',
            MemberController::class, 'importTemplate');
        $router->get('/admin/members/{id}/import',
            MemberController::class, 'importForm');
        $router->post('/admin/members/{id}/import',
            MemberController::class, 'import');

        // ── Codes for a specific listing ─────────────────────────
        //    Specific literal paths are registered before parameterised ones
        //    to prevent e.g. 'upload' or 'sizes' matching as {id}.
        $router->get('/admin/listings/{fid}/codes',
            CodesController::class, 'index');
        $router->post('/admin/listings/{fid}/codes/upload',
            CodesController::class, 'upload');
        $router->post('/admin/listings/{fid}/codes/sizes/add',
            CodesController::class, 'addSize');
        $router->post('/admin/listings/{fid}/codes/sizes/{sid}/edit',
            CodesController::class, 'editSize');
        $router->post('/admin/listings/{fid}/codes/sizes/{sid}/delete',
            CodesController::class, 'deleteSize');
        $router->post('/admin/listings/{fid}/codes/categories/add',
            CodesController::class, 'addCategory');
        $router->post('/admin/listings/{fid}/codes/categories/{cid}/edit',
            CodesController::class, 'editCategory');
        $router->post('/admin/listings/{fid}/codes/categories/{cid}/delete',
            CodesController::class, 'deleteCategory');
        $router->post('/admin/listings/{fid}/codes/donors/add',
            CodesController::class, 'addDonor');
        $router->post('/admin/listings/{fid}/codes/donors/{did}/edit',
            CodesController::class, 'editDonor');
        $router->post('/admin/listings/{fid}/codes/donors/{did}/delete',
            CodesController::class, 'deleteDonor');
        $router->post('/admin/listings/{fid}/codes/bulk',
            CodesController::class, 'bulk');
        $router->get('/admin/listings/{fid}/codes/export',
            CodesController::class, 'export');
        $router->post('/admin/listings/{fid}/codes/reorder',
            CodesController::class, 'reorder');
        $router->post('/admin/listings/{fid}/codes/{id}/delete',
            CodesController::class, 'delete');
        $router->post('/admin/listings/{fid}/codes/{id}/size',
            CodesController::class, 'assignSize');
        $router->post('/admin/listings/{fid}/codes/{id}/categories',
            CodesController::class, 'assignCategories');
        $router->post('/admin/listings/{fid}/codes/{id}/donor',
            CodesController::class, 'assignDonor');
        $router->post('/admin/listings/{fid}/codes/{id}/status',
            CodesController::class, 'assignStatus');
        $router->post('/admin/listings/{fid}/codes/{id}/name',
            CodesController::class, 'assignName');

        // ── Additional fields for a specific listing ──────────────
        $router->get('/admin/listings/{fid}/fields',
            FieldController::class, 'index');
        $router->post('/admin/listings/{fid}/fields/add',
            FieldController::class, 'add');
        $router->get('/admin/listings/{fid}/fields/{name}/edit',
            FieldController::class, 'edit');
        $router->post('/admin/listings/{fid}/fields/{name}/edit',
            FieldController::class, 'edit');
        $router->post('/admin/listings/{fid}/fields/{name}/delete',
            FieldController::class, 'delete');

        // ── Per-listing affiliate add (must be before /affiliates routes
        //    to avoid route ordering issues, and before /listings/{id}) ──
        $router->get('/admin/listings/{fid}/affiliates/add',
            AffiliateController::class, 'add');
        $router->post('/admin/listings/{fid}/affiliates/add',
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

        // ── Admin user management (list lives in Settings › Admin Details) ──
        $router->get('/admin/admin-users/add',
            AdminUsersController::class, 'addForm');
        $router->post('/admin/admin-users/add',
            AdminUsersController::class, 'add');
        $router->get('/admin/admin-users/{id}/edit',
            AdminUsersController::class, 'editForm');
        $router->post('/admin/admin-users/{id}/edit',
            AdminUsersController::class, 'edit');
        $router->post('/admin/admin-users/{id}/delete',
            AdminUsersController::class, 'delete');

        // ── Email Templates ───────────────────────────────────────────
        $router->get('/admin/email-templates',
            EmailTemplatesController::class, 'index');
        $router->post('/admin/email-templates/save',
            EmailTemplatesController::class, 'save');
        $router->post('/admin/email-templates/reset',
            EmailTemplatesController::class, 'reset');

        // ── Settings ────────────────────────────────────────────────
        $router->get('/admin/settings',
            SettingsController::class, 'index');
        $router->post('/admin/settings',
            SettingsController::class, 'update');
        $router->post('/admin/settings/templates',
            SettingsController::class, 'updateTemplates');
        $router->post('/admin/settings/profile',
            SettingsController::class, 'updateProfile');
        $router->post('/admin/settings/validate-smtp',
            SettingsController::class, 'validateSmtp');
        $router->post('/admin/settings/test-email',
            SettingsController::class, 'testEmail');

        // ── Joined listings ───────────────────────────────────────
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
        $router->post('/admin/joined/bulk',
            JoinedController::class, 'bulkAction');
        $router->get('/admin/joined/settings',
            JoinedController::class, 'settings');
        $router->post('/admin/joined/settings',
            JoinedController::class, 'settings');

        // ── Categories ───────────────────────────────────────────────
        $router->get('/admin/categories',
            CategoryController::class, 'index');
        $router->get('/admin/categories/add',
            CategoryController::class, 'addForm');
        $router->post('/admin/categories/add',
            CategoryController::class, 'add');
        $router->get('/admin/categories/{id}/edit',
            CategoryController::class, 'editForm');
        $router->post('/admin/categories/{id}/edit',
            CategoryController::class, 'edit');
        $router->post('/admin/categories/{id}/delete',
            CategoryController::class, 'delete');

        // ── Add-ons ───────────────────────────────────────────────────
        $router->get('/admin/addons',
            AddonsController::class, 'index');
        $router->post('/admin/addons/install',
            AddonsController::class, 'install');
        $router->post('/admin/addons/uninstall',
            AddonsController::class, 'uninstall');

        // ── Activity log ─────────────────────────────────────────────
        $router->get('/admin/activity',
            ActivityLogController::class, 'index');
        $router->post('/admin/activity/flush',
            ActivityLogController::class, 'flush');

        // ── Error log ────────────────────────────────────────────────
        $router->get('/admin/errorlog',
            ErrorLogController::class, 'index');
        $router->post('/admin/errorlog/flush',
            ErrorLogController::class, 'flush');

        // ── System info ──────────────────────────────────────────────
        $router->get('/admin/sysinfo',
            SysInfoController::class, 'index');

        // ── Pending update approvals (list + detail live in Members overview) ──
        $router->post('/admin/pending-updates/bulk',
            PendingUpdateController::class, 'bulk');
        $router->post('/admin/pending-updates/{id}/approve',
            PendingUpdateController::class, 'approve');
        $router->post('/admin/pending-updates/{id}/reject',
            PendingUpdateController::class, 'reject');

        // ── Blacklist ─────────────────────────────────────────────────
        $router->get('/admin/blacklist',
            BlacklistController::class, 'index');
        $router->post('/admin/blacklist/add',
            BlacklistController::class, 'add');
        $router->post('/admin/blacklist/{id}/delete',
            BlacklistController::class, 'delete');

        // ── Embed codes reference ─────────────────────────────────────
        $router->get('/admin/embed-codes',
            EmbedCodesController::class, 'index');

        // ── Mass email ────────────────────────────────────────────────
        $router->get('/admin/mass-email',
            MassEmailController::class, 'compose');
        $router->post('/admin/mass-email/preview',
            MassEmailController::class, 'preview');
        $router->post('/admin/mass-email/send',
            MassEmailController::class, 'send');

        // ── Saved email templates ─────────────────────────────────────
        $router->get('/admin/saved-emails',
            MassEmailController::class, 'savedEmails');
        $router->post('/admin/saved-emails/save',
            MassEmailController::class, 'saveTemplate');
        $router->post('/admin/saved-emails/{id}/delete',
            MassEmailController::class, 'deleteTemplate');
    },

];
