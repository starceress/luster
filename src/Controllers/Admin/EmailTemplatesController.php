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
