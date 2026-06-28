<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 POST-only controller for transactional email template management. Handles saving
 all six template types (join, approve, update, remove, reset, notify_join) for a
 given scope (global or per-listing), resetting a single type back to its parent
 level, and toggling the enabled flag via AJAX. The GET index redirects to the
 unified Emails page served by EmailsController.
*/

declare(strict_types=1);

namespace Luster\Controllers\Admin;

use Luster\Core\Request;
use Luster\Core\Response;
use Luster\Repositories\ActivityRepo;
use Luster\Repositories\EmailTemplateRepo;
use Luster\Repositories\ListingRepo;

/**
 * POST-only controller for transactional email template management.
 *
 * Handles saving all template types (join, approve, update, remove, reset,
 * notify_join, affiliate_*, adoption_*, KIM_*) for a given scope (global or
 * per-listing), resetting a single type back to its parent level (per-listing
 * resets to global; global resets to the built-in default), and toggling the
 * enabled flag via an AJAX endpoint. The GET index redirects to the unified
 * Emails page served by EmailsController.
 */
final class EmailTemplatesController extends AdminController
{

    // -----------------------------------------------------------------------
    // GET /admin/email-templates — redirect to unified page
    // -----------------------------------------------------------------------

    /**
     * Redirects legacy /admin/email-templates URLs to the Templates tab of the
     * unified Emails page, preserving any listing= scope parameter.
     */
    public function indexRedirect(Request $request, array $params): Response
    {
        $baseUrl = $this->app->getBaseUrl();
        $listing = $request->query('listing', '');
        $qs      = ($listing !== '' && $listing !== 'global')
            ? '?tab=templates&listing=' . urlencode($listing)
            : '?tab=templates';
        return Response::redirect($baseUrl . '/admin/emails' . $qs);
    }

    /**
     * Resolves the fanlisting_id from the POST scope parameter.
     * Returns COLLECTIVE_ID for 'collective', 0 for 'global', or the listing ID.
     */
    private function scopeToListingId(string $scope): int
    {
        if ($scope === 'collective') {
            return EmailTemplateRepo::COLLECTIVE_ID;
        }
        return $scope === 'global' ? 0 : (int) $scope;
    }

    // -----------------------------------------------------------------------
    // POST /admin/email-templates/save
    // -----------------------------------------------------------------------

    /**
     * Persists all template types for the given scope in one request.
     *
     * Reads email_subject_{type}, email_body_{type}, and email_enabled_{type}
     * POST fields for every type in EmailTemplateRepo::TYPES, skipping
     * collective-only types when the scope is per-listing. Validates that the
     * listing exists when a per-listing scope is given.
     */
    public function save(Request $request, array $params): Response
    {
        $db      = $this->app->getDatabase();
        $config  = $this->app->getConfig();
        $session = $this->app->getSession();
        $baseUrl = $this->app->getBaseUrl();

        $scope        = trim((string) $request->post('scope', 'global'));
        $isCollective = $scope === 'collective';
        $listingId    = $isCollective
            ? EmailTemplateRepo::COLLECTIVE_ID
            : ($scope === 'global' ? 0 : (int) $scope);

        // Validate per-listing scope
        if ($listingId > 0) {
            $listingRepo = new ListingRepo($db, $config);
            if ($listingRepo->getById($listingId) === null) {
                $session->set('lstr_flash_error', 'Listing not found.');
                return Response::redirect($baseUrl . '/admin/emails?tab=templates');
            }
        }

        $emailRepo    = new EmailTemplateRepo($db);
        $allowedTypes = $isCollective
            ? EmailTemplateRepo::COLLECTIVE_TYPES
            : EmailTemplateRepo::LISTING_TYPES;

        foreach (EmailTemplateRepo::TYPES as $type) {
            if (!in_array($type, $allowedTypes, true)) {
                continue;
            }
            // Per-listing scope: also skip collective-only types
            if ($listingId > 0 && in_array($type, EmailTemplateRepo::COLLECTIVE_ONLY_TYPES, true)) {
                continue;
            }
            $subject = trim((string) $request->post('email_subject_' . $type, ''));
            $body    = trim((string) $request->post('email_body_'    . $type, ''));
            $enabled = $request->post('email_enabled_' . $type) === '1';
            $emailRepo->save($listingId, $type, $subject, $body, $enabled);
        }

        $logLabel = match (true) {
            $isCollective   => 'Saved collective email templates',
            $listingId === 0 => 'Saved global email templates',
            default          => "Saved email templates for listing #{$listingId}",
        };
        (new ActivityRepo($db))->log(
            (int) $session->get('lstr_admin_id'),
            'email_templates.save',
            $logLabel,
            $request->ip(),
        );

        $session->set('lstr_flash_success', 'Email templates saved.');

        $qs = match (true) {
            $isCollective    => '?tab=templates&listing=collective',
            $listingId === 0 => '?tab=templates',
            default          => '?tab=templates&listing=' . $listingId,
        };
        return Response::redirect($baseUrl . '/admin/emails' . $qs);
    }

    // -----------------------------------------------------------------------
    // POST /admin/email-templates/toggle
    // Lightweight AJAX endpoint — saves only the enabled flag for one type.
    // Returns JSON {"ok":true} on success, {"ok":false,"error":"..."} on failure.
    // -----------------------------------------------------------------------

    /**
     * Toggles the enabled flag for a single template type via AJAX.
     *
     * Accepts scope (global or a listing ID), type, and enabled (1 or 0).
     * Validates both the type and the scope before writing. Returns JSON
     * so the client can update the toggle UI without a full page reload.
     */
    public function toggleEnabled(Request $request, array $params): Response
    {
        $db      = $this->app->getDatabase();
        $config  = $this->app->getConfig();
        $session = $this->app->getSession();

        $scope        = trim((string) $request->post('scope', 'global'));
        $type         = trim((string) $request->post('type', ''));
        $enabled      = $request->post('enabled') === '1';
        $isCollective = $scope === 'collective';
        $listingId    = $isCollective
            ? EmailTemplateRepo::COLLECTIVE_ID
            : ($scope === 'global' ? 0 : (int) $scope);

        if (!in_array($type, EmailTemplateRepo::TYPES, true)) {
            return Response::json(['ok' => false, 'error' => 'Invalid type.'], 422);
        }

        // Validate type is allowed for the given scope
        if ($isCollective && !in_array($type, EmailTemplateRepo::COLLECTIVE_TYPES, true)) {
            return Response::json(['ok' => false, 'error' => 'This template type is not available in collective scope.'], 422);
        }

        if ($listingId > 0 && in_array($type, EmailTemplateRepo::COLLECTIVE_ONLY_TYPES, true)) {
            return Response::json(['ok' => false, 'error' => 'This template type is collective-level only.'], 422);
        }

        if ($listingId > 0) {
            $listingRepo = new ListingRepo($db, $config);
            if ($listingRepo->getById($listingId) === null) {
                return Response::json(['ok' => false, 'error' => 'Listing not found.'], 422);
            }
        }

        (new EmailTemplateRepo($db))->setEnabled($listingId, $type, $enabled);

        $scopeLabel = match (true) {
            $isCollective    => ' (collective)',
            $listingId === 0 => ' (global)',
            default          => " for listing #{$listingId}",
        };
        (new ActivityRepo($db))->log(
            (int) $session->get('lstr_admin_id'),
            'email_templates.toggle',
            ($enabled ? 'Enabled' : 'Disabled') . " {$type} email template" . $scopeLabel,
            $request->ip(),
        );

        return Response::json(['ok' => true]);
    }

    // -----------------------------------------------------------------------
    // POST /admin/email-templates/reset
    // -----------------------------------------------------------------------

    /**
     * Resets a single template type to its parent level by deleting the override row.
     *
     * For a per-listing scope: deletes the per-listing row so the template inherits
     * from the global setting. For the global scope: deletes the global row so the
     * template falls back to the built-in default. Collective-only types cannot be
     * reset on a per-listing scope.
     */
    public function reset(Request $request, array $params): Response
    {
        $db      = $this->app->getDatabase();
        $config  = $this->app->getConfig();
        $session = $this->app->getSession();
        $baseUrl = $this->app->getBaseUrl();

        $scope        = trim((string) $request->post('scope', 'global'));
        $type         = trim((string) $request->post('type', ''));
        $isCollective = $scope === 'collective';
        $listingId    = $isCollective
            ? EmailTemplateRepo::COLLECTIVE_ID
            : ($scope === 'global' ? 0 : (int) $scope);

        if (!in_array($type, EmailTemplateRepo::TYPES, true)) {
            $session->set('lstr_flash_error', 'Invalid template type.');
            return Response::redirect($baseUrl . '/admin/emails?tab=templates');
        }

        if ($isCollective && !in_array($type, EmailTemplateRepo::COLLECTIVE_TYPES, true)) {
            $session->set('lstr_flash_error', 'This template type is not available in collective scope.');
            return Response::redirect($baseUrl . '/admin/emails?tab=templates');
        }

        if ($listingId > 0 && in_array($type, EmailTemplateRepo::COLLECTIVE_ONLY_TYPES, true)) {
            $session->set('lstr_flash_error', 'This template type is collective-level only.');
            return Response::redirect($baseUrl . '/admin/emails?tab=templates');
        }

        if ($listingId > 0) {
            $listingRepo = new ListingRepo($db, $config);
            if ($listingRepo->getById($listingId) === null) {
                $session->set('lstr_flash_error', 'Listing not found.');
                return Response::redirect($baseUrl . '/admin/emails?tab=templates');
            }
        }

        (new EmailTemplateRepo($db))->delete($listingId, $type);

        $logLabel = match (true) {
            $isCollective    => "Reset collective {$type} email template to built-in default",
            $listingId === 0 => "Reset global {$type} email template to built-in default",
            default          => "Reset {$type} email template for listing #{$listingId} to global",
        };
        (new ActivityRepo($db))->log(
            (int) $session->get('lstr_admin_id'),
            'email_templates.reset',
            $logLabel,
            $request->ip(),
        );

        $label = $listingId > 0 ? 'global template' : 'built-in default';
        $session->set('lstr_flash_success', "Template reset to {$label}.");

        $qs = match (true) {
            $isCollective    => '?tab=templates&listing=collective',
            $listingId === 0 => '?tab=templates',
            default          => '?tab=templates&listing=' . $listingId,
        };
        return Response::redirect($baseUrl . '/admin/emails' . $qs);
    }

    // -----------------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------------

}
