<?php

declare(strict_types=1);

use Luster\Controllers\Admin\AddonsController;
use Luster\Controllers\Admin\LinkCheckerController;
use Luster\Controllers\Admin\FeedbackController;
use Luster\Controllers\Admin\ImportController;
use Luster\Controllers\Admin\ImportMembersController;
use Luster\Controllers\Admin\ActivityLogController;
use Luster\Controllers\Admin\EmailsController;
use Luster\Controllers\Admin\BulkEmailController;
use Luster\Controllers\Admin\AdminUsersController;
use Luster\Controllers\Admin\AdminUpdateController;
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
use Luster\Controllers\Public\AffiliateApplyController;
use Luster\Controllers\Public\AffiliatesController;
use Luster\Controllers\Public\JoinController;
use Luster\Controllers\Public\MembersController;
use Luster\Controllers\Public\ResetController;
use Luster\Controllers\Public\CodesSubmitController;
use Luster\Controllers\Public\StatsController;
use Luster\Controllers\Public\ContactController;
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

        // Members list
        $router->get('/members/{id}',         MembersController::class, 'index');

        // Stats
        $router->get('/stats/{listingId}', StatsController::class,   'index');

        // Update membership
        $router->get('/update/{id}',           UpdateController::class, 'showForm');
        $router->post('/update/{id}/sign-in',  UpdateController::class, 'signIn');
        $router->get('/update/{id}/sign-out',  UpdateController::class, 'signOut');
        $router->post('/update/{id}',          UpdateController::class, 'submit');

        // Affiliates — collective route before the per-fanlisting route
        $router->get('/affiliates',      AffiliatesController::class, 'collective');
        $router->get('/affiliates/{id}', AffiliatesController::class, 'index');

        // Code submission (fan-facing)
        $router->get('/codes/submit/{id}',        CodesSubmitController::class, 'showForm');
        $router->post('/codes/submit/{id}',       CodesSubmitController::class, 'submit');

        // Contact forms — collective routes before per-listing
        $router->get('/contact',             ContactController::class, 'collectiveForm');
        $router->post('/contact',            ContactController::class, 'collectiveSubmit');
        $router->get('/contact/{id}',        ContactController::class, 'listingForm');
        $router->post('/contact/{id}',       ContactController::class, 'listingSubmit');

        // Affiliate applications — collective literal paths before parameterised {id} routes
        $router->get('/affiliate-apply/collective',         AffiliateApplyController::class, 'collectiveShow');
        $router->post('/affiliate-apply/collective',        AffiliateApplyController::class, 'collectiveSubmit');
        $router->get('/affiliate-apply/{id}',         AffiliateApplyController::class, 'show');
        $router->post('/affiliate-apply/{id}',        AffiliateApplyController::class, 'submit');

        // Password reset — confirm path before base route
        $router->get('/reset/{id}/confirm',   ResetController::class,   'showConfirm');
        $router->post('/reset/{id}/confirm',  ResetController::class,   'submitConfirm');
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
        $router->post('/admin/maintenance/bulk-touch-updated',
            DashboardController::class, 'bulkTouchUpdated');
        $router->post('/admin/listings/{id}/touch-updated',
            DashboardController::class, 'touchListing');
        $router->post('/admin/dismiss-update',
            DashboardController::class, 'dismissUpdate');
        $router->get('/admin/update',
            AdminUpdateController::class, 'index');
        $router->post('/admin/update/prepare',
            AdminUpdateController::class, 'prepare');
        $router->post('/admin/update/backup',
            AdminUpdateController::class, 'backup');
        $router->post('/admin/update/apply',
            AdminUpdateController::class, 'apply');
        $router->post('/admin/update/discard',
            AdminUpdateController::class, 'discard');

        // ── Listings ────────────────────────────────────────────────
        $router->get('/admin/listings',
            ListingController::class, 'index');
        $router->get('/admin/listings/add',      ListingController::class, 'addForm');
        $router->post('/admin/listings/add',     ListingController::class, 'add');
        $router->get('/admin/listings/global-templates',  ListingController::class, 'globalTemplates');
        $router->post('/admin/listings/global-templates', ListingController::class, 'saveGlobalTemplates');
        $router->post('/admin/listings/global-templates/preview', ListingController::class, 'globalTemplatesPreview');
        $router->get('/admin/listings/settings', ListingController::class, 'indexSettings');
        $router->post('/admin/listings/settings', ListingController::class, 'saveSettings');
        $router->post('/admin/listings/settings/send-overdue-now', ListingController::class, 'sendOverdueNow');
        $router->post('/admin/listings/bulk', ListingController::class, 'bulk');
        $router->get('/admin/collective',
            SettingsController::class, 'collectiveIndex');
        $router->post('/admin/collective/quick-actions/reorder',
            SettingsController::class, 'reorderQuickActions');
        $router->get('/admin/collective/templates',
            SettingsController::class, 'collectiveTemplates');
        $router->post('/admin/collective/templates/preview',
            SettingsController::class, 'previewCollectiveTemplate');
        $router->get('/admin/collective/snippets',
            SettingsController::class, 'collectiveSnippets');
        $router->get('/admin/collective/settings',
            SettingsController::class, 'collectiveSettings');
        $router->post('/admin/collective/settings',
            SettingsController::class, 'saveCollectiveSettings');
        $router->get('/admin/listings/{id}',
            ListingController::class, 'show');
        $router->get('/admin/listings/{id}/edit',
            ListingController::class, 'edit');
        $router->post('/admin/listings/{id}/edit',
            ListingController::class, 'edit');
        $router->post('/admin/listings/{id}/templates',
            ListingController::class, 'saveTemplates');
        $router->post('/admin/listings/{id}/templates/preview',
            ListingController::class, 'templatesPreview');
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

        $router->post('/admin/members/approve-all-overview',
            MemberController::class, 'approveAllOverview');

        $router->post('/admin/members/pending-bulk',
            MemberController::class, 'pendingBulk');

        // export-pending is registered before {id} so 'export-pending' isn't
        // captured as a listing id.
        $router->get('/admin/members/export-pending',
            MemberController::class, 'exportPending');

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
        $router->get('/admin/members/{id}/emailmember',
            MemberController::class, 'emailMemberForm');
        $router->post('/admin/members/{id}/emailmember',
            MemberController::class, 'emailMemberSend');
        $router->get('/admin/members/{id}/email-selected',
            MemberController::class, 'emailSelectedForm');
        $router->post('/admin/members/{id}/email-selected/send',
            MemberController::class, 'emailSelectedSend');
        $router->post('/admin/members/{id}/email-selected/save-template',
            MemberController::class, 'emailSelectedSaveTemplate');

        // ── Codes for a specific listing ─────────────────────────
        //    Specific literal paths are registered before parameterised ones
        //    to prevent e.g. 'upload' or 'sizes' matching as {id}.
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
        $router->post('/admin/listings/{fid}/codes/{id}/update',
            CodesController::class, 'updateCode');

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

        // ── Per-listing affiliate add
        $router->get('/admin/listings/{fid}/affiliates/add',
            AffiliateController::class, 'add');
        $router->post('/admin/listings/{fid}/affiliates/add',
            AffiliateController::class, 'add');

        // ── Affiliates ──────────────────────────────────────────────
        $router->get('/admin/affiliates',
            AffiliateController::class, 'index');
        // Literal sub-paths registered before parameterised {id} routes
        $router->get('/admin/affiliates/pending',
            AffiliateController::class, 'pending');
        $router->get('/admin/affiliates/add',
            AffiliateController::class, 'add');
        $router->post('/admin/affiliates/add',
            AffiliateController::class, 'add');
        $router->post('/admin/affiliates/bulk',
            AffiliateController::class, 'bulk');
        $router->post('/admin/affiliates/reorder',
            AffiliateController::class, 'reorder');
        $router->get('/admin/affiliates/email',
            AffiliateController::class, 'emailCompose');
        $router->post('/admin/affiliates/email',
            AffiliateController::class, 'emailSend');
        $router->get('/admin/affiliates/{id}/edit',
            AffiliateController::class, 'editAffiliate');
        $router->post('/admin/affiliates/{id}/edit',
            AffiliateController::class, 'editAffiliate');
        $router->post('/admin/affiliates/{id}/toggle',
            AffiliateController::class, 'toggle');
        $router->post('/admin/affiliates/{id}/delete',
            AffiliateController::class, 'deleteAffiliate');
        $router->post('/admin/affiliates/{id}/approve',
            AffiliateController::class, 'approve');
        $router->post('/admin/affiliates/{id}/reject',
            AffiliateController::class, 'reject');
        $router->post('/admin/affiliates/{id}/re-approve',
            AffiliateController::class, 'reApprove');

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
        // Old URL → redirect to unified Emails page
        $router->get('/admin/email-templates', EmailTemplatesController::class, 'indexRedirect');
        $router->post('/admin/email-templates/save',
            EmailTemplatesController::class, 'save');
        $router->post('/admin/email-templates/reset',
            EmailTemplatesController::class, 'reset');
        $router->post('/admin/email-templates/toggle',
            EmailTemplatesController::class, 'toggleEnabled');

        // ── Settings ────────────────────────────────────────────────
        $router->get('/admin/settings',
            SettingsController::class, 'index');
        $router->post('/admin/settings',
            SettingsController::class, 'updateGeneral');
        $router->post('/admin/settings/paths',
            SettingsController::class, 'updatePaths');
        $router->post('/admin/settings/smtp',
            SettingsController::class, 'updateSmtp');
        $router->post('/admin/settings/templates',
            SettingsController::class, 'updateTemplates');
        $router->post('/admin/settings/profile',
            SettingsController::class, 'updateProfile');
        $router->post('/admin/settings/test-email',
            SettingsController::class, 'testEmail');
        $router->post('/admin/settings/check-update',
            SettingsController::class, 'checkUpdate');
        $router->post('/admin/settings/import-visibility',
            SettingsController::class, 'saveImportVisibility');
        $router->post('/admin/settings/notifications',
            SettingsController::class, 'saveNotifications');
        $router->post('/admin/settings/default-theme',
            SettingsController::class, 'saveDefaultTheme');
        $router->post('/admin/settings/skin',
            SettingsController::class, 'saveSkin');

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

        // ── Global Codes ─────────────────────────────────────────────
        $router->get('/admin/codes',
            \Luster\Controllers\Admin\GlobalCodesController::class, 'index');
        $router->get('/admin/codes/collective/export',
            \Luster\Controllers\Admin\GlobalCodesController::class, 'exportCollective');
        $router->post('/admin/codes/collective-upload',
            \Luster\Controllers\Admin\GlobalCodesController::class, 'uploadCollective');
        $router->post('/admin/codes/approve-all',
            \Luster\Controllers\Admin\GlobalCodesController::class, 'approveAll');
        $router->post('/admin/codes/bulk',
            \Luster\Controllers\Admin\GlobalCodesController::class, 'bulk');
        $router->post('/admin/codes/collective/bulk',
            \Luster\Controllers\Admin\GlobalCodesController::class, 'bulkCollective');
        $router->post('/admin/codes/collective/reorder',
            \Luster\Controllers\Admin\GlobalCodesController::class, 'reorderCollective');
        $router->post('/admin/codes/collective/{id}/update',
            \Luster\Controllers\Admin\GlobalCodesController::class, 'updateCollective');
        $router->post('/admin/codes/collective/{id}/delete',
            \Luster\Controllers\Admin\GlobalCodesController::class, 'deleteCollective');
        $router->post('/admin/codes/{id}/approve',
            \Luster\Controllers\Admin\GlobalCodesController::class, 'approve');
        $router->post('/admin/codes/{id}/reject',
            \Luster\Controllers\Admin\GlobalCodesController::class, 'reject');

        // ── Categories ───────────────────────────────────────────────
        $router->get('/admin/categories',
            CategoryController::class, 'index');
        $router->get('/admin/categories/add',
            CategoryController::class, 'addForm');
        $router->post('/admin/categories/add',
            CategoryController::class, 'add');
        $router->post('/admin/categories/seed',
            CategoryController::class, 'seed');
        $router->get('/admin/categories/{id}/edit',
            CategoryController::class, 'editForm');
        $router->post('/admin/categories/{id}/edit',
            CategoryController::class, 'edit');
        $router->post('/admin/categories/{id}/delete',
            CategoryController::class, 'delete');
        $router->post('/admin/categories/bulk',
            CategoryController::class, 'bulk');
        $router->get('/admin/categories/settings',
            CategoryController::class, 'settingsForm');
        $router->post('/admin/categories/settings',
            CategoryController::class, 'saveSettings');

        // ── Add-ons ───────────────────────────────────────────────────
        $router->get('/admin/addons',
            AddonsController::class, 'index');
        $router->post('/admin/addons/install',
            AddonsController::class, 'install');
        $router->post('/admin/addons/uninstall',
            AddonsController::class, 'uninstall');

        // ── Activity log ─────────────────────────────────────────────
        $router->get('/admin/activitylog',
            ActivityLogController::class, 'index');
        $router->get('/admin/activitylog/export',
            ActivityLogController::class, 'export');
        $router->post('/admin/activitylog/flush',
            ActivityLogController::class, 'flush');

        // ── Error log ────────────────────────────────────────────────
        $router->get('/admin/errorlog',
            ErrorLogController::class, 'index');
        $router->get('/admin/errorlog/export',
            ErrorLogController::class, 'export');
        $router->post('/admin/errorlog/flush',
            ErrorLogController::class, 'flush');

        // ── System info ──────────────────────────────────────────────
        $router->get('/admin/sysinfo',
            SysInfoController::class, 'index');

        // ── Link Checker ─────────────────────────────────────────────────
        $router->get('/admin/link-checker',
            LinkCheckerController::class, 'index');
        $router->get('/admin/link-checker/settings',
            LinkCheckerController::class, 'settings');
        $router->get('/admin/link-checker/all-ids',
            LinkCheckerController::class, 'allIds');
        $router->post('/admin/link-checker/settings',
            LinkCheckerController::class, 'settings');
        $router->post('/admin/link-checker/check-affiliate',
            LinkCheckerController::class, 'checkAffiliate');
        $router->post('/admin/link-checker/check-joined',
            LinkCheckerController::class, 'checkJoined');
        $router->post('/admin/link-checker/reset-affiliates',
            LinkCheckerController::class, 'resetAffiliates');
        $router->post('/admin/link-checker/reset-joined',
            LinkCheckerController::class, 'resetJoined');
        $router->post('/admin/link-checker/reset-all',
            LinkCheckerController::class, 'resetAll');
        $router->post('/admin/link-checker/apply-affiliate-redirect',
            LinkCheckerController::class, 'applyRedirectAffiliate');
        $router->post('/admin/link-checker/apply-joined-redirect',
            LinkCheckerController::class, 'applyRedirectJoined');
        $router->post('/admin/link-checker/save-cron-settings',
            LinkCheckerController::class, 'saveCronSettings');
        $router->post('/admin/link-checker/scan-members',
            LinkCheckerController::class, 'scanMembers');
        $router->post('/admin/link-checker/check-member',
            LinkCheckerController::class, 'checkMember');
        $router->post('/admin/link-checker/apply-member-redirect',
            LinkCheckerController::class, 'applyRedirectMember');
        $router->post('/admin/link-checker/reset-members',
            LinkCheckerController::class, 'resetMembers');
        $router->post('/admin/link-checker/scan-member-ajax',
            LinkCheckerController::class, 'scanMemberAjax');

        // ── Feedback ──────────────────────────────────────────────────
        $router->get('/admin/feedback',  FeedbackController::class, 'showForm');
        $router->post('/admin/feedback', FeedbackController::class, 'submit');

        // ── Pending update approvals (list + detail live in Members overview) ──
        $router->post('/admin/pending-updates/approve-all',
            PendingUpdateController::class, 'approveAll');
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


        // ── Import wizard ─────────────────────────────────────────────
        // Literal sub-paths registered before parameterised {source} routes
        $router->get('/admin/import',
            ImportController::class, 'index');
        $router->get('/admin/import/settings',
            ImportController::class, 'settings');
        $router->post('/admin/import/settings',
            ImportController::class, 'settings');
        // Step 2: Connect (credentials)
        $router->get('/admin/import/{source}/connect',
            ImportController::class, 'connect');
        $router->post('/admin/import/{source}/connect',
            ImportController::class, 'connect');
        // configure → connect redirect (backward compatibility)
        $router->get('/admin/import/{source}/configure',
            ImportController::class, 'configure');
        $router->post('/admin/import/{source}/configure',
            ImportController::class, 'configure');
        // AJAX test connection
        $router->post('/admin/import/{source}/test-connection',
            ImportController::class, 'testConnection');
        // AJAX auto-detect legacy config credentials (opt-in, disabled by default)
        $router->post('/admin/import/{source}/detect-config',
            ImportController::class, 'detectConfig');
        // Step 3: Options (data types + image mode + ZIP upload)
        $router->get('/admin/import/{source}/options',
            ImportController::class, 'scope');
        $router->post('/admin/import/{source}/options',
            ImportController::class, 'scope');
        $router->post('/admin/import/{source}/zip-upload',
            ImportController::class, 'uploadZip');
        // Step 4: Preview (GET = browser followed redirect from scope POST; POST = back-compat)
        $router->get('/admin/import/{source}/preview',
            ImportController::class, 'preview');
        $router->post('/admin/import/{source}/preview',
            ImportController::class, 'preview');
        // Step 5: Run + result
        $router->post('/admin/import/{source}/run',
            ImportController::class, 'run');
        $router->get('/admin/import/{source}/result',
            ImportController::class, 'result');
        $router->get('/admin/import/{source}/report',
            ImportController::class, 'downloadReport');
        // SQL dump upload (called from connect step)
        $router->post('/admin/import/{source}/upload-dump',
            ImportController::class, 'uploadDump');


        // ── Embed codes + compat test ─────────────────────────────────
        $router->get('/admin/embed-codes',
            EmbedCodesController::class, 'index');
        $router->get('/admin/embed-codes/preview',
            EmbedCodesController::class, 'preview');
        $router->get('/admin/compat-test',
            EmbedCodesController::class, 'compatTest');
        $router->post('/admin/compat-test',
            EmbedCodesController::class, 'compatTest');

        // ── Compat bridge downloads ───────────────────────────────────
        $router->get('/admin/compat-bridge/{set}',
            SettingsController::class, 'compatBridgeDownload');

        // ── Import members (targeted SQL member import) ───────────────
        $router->get('/admin/import-members',
            ImportMembersController::class, 'landing');
        $router->post('/admin/import-members/upload',
            ImportMembersController::class, 'upload');
        $router->get('/admin/import-members/preview',
            ImportMembersController::class, 'preview');
        $router->post('/admin/import-members/run',
            ImportMembersController::class, 'run');
        $router->get('/admin/import-members/result',
            ImportMembersController::class, 'result');

// ── Unified Emails page ───────────────────────────────────────
        $router->get('/admin/emails',
            EmailsController::class, 'index');
        $router->post('/admin/emails/preview',
            BulkEmailController::class, 'preview');
        $router->post('/admin/emails/send',
            BulkEmailController::class, 'send');

        // ── Saved email templates (old URL → redirect) ────────────────
        $router->get('/admin/new-email-template',
            BulkEmailController::class, 'savedEmailsRedirect');
        $router->post('/admin/new-email-template/save',
            BulkEmailController::class, 'saveTemplate');
        $router->post('/admin/new-email-template/{id}/delete',
            BulkEmailController::class, 'deleteTemplate');
        $router->post('/admin/new-email-template/{id}/update',
            BulkEmailController::class, 'updateTemplate');
    },

];
