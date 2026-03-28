<?php

declare(strict_types=1);

namespace Luster\Controllers\Admin;

use Luster\Core\Request;
use Luster\Core\Response;
use Luster\Repositories\ActivityRepo;
use Luster\Repositories\EmailTemplateRepo;
use Luster\Repositories\ListingRepo;

/**
 * Email template management (POST handlers only — GET now served by EmailsController).
 *
 * GET  /admin/email-templates          — 302 redirect to /admin/emails?tab=templates
 * POST /admin/email-templates/save     — save all 6 types for scope
 * POST /admin/email-templates/reset    — reset one type to next level
 * POST /admin/email-templates/toggle   — AJAX toggle enabled flag
 */
final class EmailTemplatesController extends AdminController
{

    // -----------------------------------------------------------------------
    // GET /admin/email-templates — redirect to unified page
    // -----------------------------------------------------------------------

    public function indexRedirect(Request $request, array $params): Response
    {
        $baseUrl = $this->app->getBaseUrl();
        $listing = $request->query('listing', '');
        $qs      = ($listing !== '' && $listing !== 'global')
            ? '?tab=templates&listing=' . urlencode($listing)
            : '?tab=templates';
        return Response::redirect($baseUrl . '/admin/emails' . $qs);
    }

    // -----------------------------------------------------------------------
    // Legacy index — kept for internal use only (no longer routed via GET)
    // -----------------------------------------------------------------------

    public function index(Request $request, array $params): Response
    {
        $db      = $this->app->getDatabase();
        $config  = $this->app->getConfig();
        $session = $this->app->getSession();

        $success = (string) $session->get('lstr_flash_success', '');
        $error   = (string) $session->get('lstr_flash_error',   '');
        $session->remove('lstr_flash_success');
        $session->remove('lstr_flash_error');

        $listingRepo = new ListingRepo($db, $config);
        $allListings = $listingRepo->getAll();

        $emailRepo      = new EmailTemplateRepo($db);
        $globalTemplates = $emailRepo->getAllGlobal();

        $scope    = $request->query('listing', 'global');
        $listing  = null;
        $perListingTemplates = [];

        if ($scope !== 'global') {
            $listingId = (int) $scope;
            $listing   = $listingRepo->getById($listingId);
            if ($listing === null) {
                // Invalid listing ID — fall back to global scope
                $scope   = 'global';
            } else {
                $perListingTemplates = $emailRepo->getAll($listingId);
            }
        }

        // Build pre-filled values and customisation state for each type.
        // Pre-filled value = saved text for this scope, or resolved text from
        // the next level if nothing is saved here yet (so inputs are always
        // editable from a working starting point).
        $cards = [];
        foreach (EmailTemplateRepo::TYPES as $type) {
            $globalTpl  = $globalTemplates[$type] ?? null;
            $listingTpl = $perListingTemplates[$type] ?? null;

            if ($scope === 'global') {
                // Global scope: show saved global, or built-in default
                $savedSubject = $globalTpl?->subject ?? '';
                $savedBody    = $globalTpl?->body    ?? '';
                $fillSubject  = $savedSubject !== '' ? $savedSubject : EmailTemplateRepo::defaultSubject($type);
                $fillBody     = $savedBody    !== '' ? $savedBody    : EmailTemplateRepo::defaultBody($type);
                $isEnabled    = $globalTpl === null || $globalTpl->enabled;
                $isCustomised = false; // not applicable at global scope
            } else {
                // Per-listing scope: show saved per-listing, or resolved global
                $savedSubject = $listingTpl?->subject ?? '';
                $savedBody    = $listingTpl?->body    ?? '';
                $isCustomised = $listingTpl !== null && trim($listingTpl->subject) !== '';
                $isEnabled    = $listingTpl === null ? ($globalTpl === null || $globalTpl->enabled) : $listingTpl->enabled;

                if ($isCustomised) {
                    $fillSubject = $savedSubject;
                    $fillBody    = $savedBody;
                } else {
                    // Pre-fill with the resolved global (or built-in default)
                    $fillSubject = ($globalTpl !== null && trim($globalTpl->subject) !== '')
                        ? $globalTpl->subject
                        : EmailTemplateRepo::defaultSubject($type);
                    $fillBody = ($globalTpl !== null && trim($globalTpl->body) !== '')
                        ? $globalTpl->body
                        : EmailTemplateRepo::defaultBody($type);
                }
            }

            $cards[$type] = [
                'fillSubject'  => $fillSubject,
                'fillBody'     => $fillBody,
                'isEnabled'    => $isEnabled,
                'isCustomised' => $isCustomised,
            ];
        }

        return $this->adminLayout('admin/email-templates/index.php', 'Emails', 'email-templates', [
            'all_listings'        => $allListings,
            'scope'               => $scope,
            'listing'             => $listing,
            'cards'               => $cards,
            'owner_name'          => (string) $config->get('owner_name', ''),
            'owner_email'         => (string) $config->get('owner_email', ''),
            'site_name'           => (string) $config->get('site_name', 'Luster'),
            'success'             => $success,
            'error'               => $error,
        ], 'Emails › Email Templates');
    }

    // -----------------------------------------------------------------------
    // POST /admin/email-templates/save
    // -----------------------------------------------------------------------

    public function save(Request $request, array $params): Response
    {
        $db      = $this->app->getDatabase();
        $config  = $this->app->getConfig();
        $session = $this->app->getSession();
        $baseUrl = $this->app->getBaseUrl();

        $scope     = trim((string) $request->post('scope', 'global'));
        $listingId = $scope === 'global' ? 0 : (int) $scope;

        // Validate per-listing scope
        if ($listingId !== 0) {
            $listingRepo = new ListingRepo($db, $config);
            if ($listingRepo->getById($listingId) === null) {
                $session->set('lstr_flash_error', 'Listing not found.');
                return Response::redirect($baseUrl . '/admin/emails?tab=templates');
            }
        }

        $emailRepo = new EmailTemplateRepo($db);

        foreach (EmailTemplateRepo::TYPES as $type) {
            $subject = trim((string) $request->post('email_subject_' . $type, ''));
            $body    = trim((string) $request->post('email_body_'    . $type, ''));
            $enabled = $request->post('email_enabled_' . $type) === '1';
            $emailRepo->save($listingId, $type, $subject, $body, $enabled);
        }

        (new ActivityRepo($db))->log(
            (int) $session->get('lstr_admin_id'),
            'email_templates.save',
            $listingId === 0
                ? 'Saved global email templates'
                : "Saved email templates for listing #{$listingId}",
            $request->ip(),
        );

        $session->set('lstr_flash_success', 'Email templates saved.');

        $qs = $scope === 'global' ? '?tab=templates' : '?tab=templates&listing=' . $listingId;
        return Response::redirect($baseUrl . '/admin/emails' . $qs);
    }

    // -----------------------------------------------------------------------
    // POST /admin/email-templates/toggle
    // Lightweight AJAX endpoint — saves only the enabled flag for one type.
    // Returns JSON {"ok":true} on success, {"ok":false,"error":"..."} on failure.
    // -----------------------------------------------------------------------

    public function toggleEnabled(Request $request, array $params): Response
    {
        $db      = $this->app->getDatabase();
        $config  = $this->app->getConfig();
        $session = $this->app->getSession();

        $scope     = trim((string) $request->post('scope', 'global'));
        $type      = trim((string) $request->post('type', ''));
        $enabled   = $request->post('enabled') === '1';
        $listingId = $scope === 'global' ? 0 : (int) $scope;

        if (!in_array($type, EmailTemplateRepo::TYPES, true)) {
            return Response::json(['ok' => false, 'error' => 'Invalid type.'], 422);
        }

        if ($listingId !== 0) {
            $listingRepo = new ListingRepo($db, $config);
            if ($listingRepo->getById($listingId) === null) {
                return Response::json(['ok' => false, 'error' => 'Listing not found.'], 422);
            }
        }

        (new EmailTemplateRepo($db))->setEnabled($listingId, $type, $enabled);

        (new ActivityRepo($db))->log(
            (int) $session->get('lstr_admin_id'),
            'email_templates.toggle',
            ($enabled ? 'Enabled' : 'Disabled') . " {$type} email template"
                . ($listingId === 0 ? ' (global)' : " for listing #{$listingId}"),
            $request->ip(),
        );

        return Response::json(['ok' => true]);
    }

    // -----------------------------------------------------------------------
    // POST /admin/email-templates/reset
    // -----------------------------------------------------------------------

    public function reset(Request $request, array $params): Response
    {
        $db      = $this->app->getDatabase();
        $config  = $this->app->getConfig();
        $session = $this->app->getSession();
        $baseUrl = $this->app->getBaseUrl();

        $scope     = trim((string) $request->post('scope', 'global'));
        $type      = trim((string) $request->post('type', ''));
        $listingId = $scope === 'global' ? 0 : (int) $scope;

        if (!in_array($type, EmailTemplateRepo::TYPES, true)) {
            $session->set('lstr_flash_error', 'Invalid template type.');
            return Response::redirect($baseUrl . '/admin/emails?tab=templates');
        }

        if ($listingId !== 0) {
            $listingRepo = new ListingRepo($db, $config);
            if ($listingRepo->getById($listingId) === null) {
                $session->set('lstr_flash_error', 'Listing not found.');
                return Response::redirect($baseUrl . '/admin/emails?tab=templates');
            }
        }

        (new EmailTemplateRepo($db))->delete($listingId, $type);

        (new ActivityRepo($db))->log(
            (int) $session->get('lstr_admin_id'),
            'email_templates.reset',
            $listingId === 0
                ? "Reset global {$type} email template to built-in default"
                : "Reset {$type} email template for listing #{$listingId} to global",
            $request->ip(),
        );

        $label = $listingId === 0 ? 'built-in default' : 'global template';
        $session->set('lstr_flash_success', "Template reset to {$label}.");

        $qs = $scope === 'global' ? '?tab=templates' : '?tab=templates&listing=' . $listingId;
        return Response::redirect($baseUrl . '/admin/emails' . $qs);
    }

    // -----------------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------------

}
