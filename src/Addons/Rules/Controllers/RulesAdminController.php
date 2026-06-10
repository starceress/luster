<?php

declare(strict_types=1);

namespace Luster\Addons\Rules\Controllers;

use Luster\Addons\Rules\RulesRepo;
use Luster\Controllers\Admin\AdminController;
use Luster\Core\Request;
use Luster\Core\Response;
use Luster\Repositories\ActivityRepo;
use Luster\Repositories\ListingRepo;

/**
 * Admin management of per-listing rules.
 *
 * Routes (registered by RulesAddon::registerRoutes()):
 *   GET  /admin/rules                 index()    — list all listings + rules status
 *   GET  /admin/rules/{id}/edit       editForm() — edit rules for a listing
 *   POST /admin/rules/{id}/edit       editSave() — save rules
 *   POST /admin/rules/{id}/toggle     toggle()   — enable/disable without editing
 *   POST /admin/rules/{id}/delete     delete()   — remove rules for a listing
 */
final class RulesAdminController extends AdminController
{
    // -----------------------------------------------------------------------
    // GET /admin/rules
    // -----------------------------------------------------------------------

    public function index(Request $request, array $params): Response
    {
        $session = $this->app->getSession();
        $success = (string) $session->get('lstr_flash_success', '');
        $error   = (string) $session->get('lstr_flash_error',   '');
        $session->remove('lstr_flash_success');
        $session->remove('lstr_flash_error');

        $rows = (new RulesRepo($this->app->getDatabase()))->getAllWithListings();

        return $this->adminLayout('admin/addons/rules/index.php', 'Join Rules', 'rules', [
            'rows'    => $rows,
            'success' => $success,
            'error'   => $error,
        ]);
    }

    // -----------------------------------------------------------------------
    // GET /admin/rules/{id}/edit
    // POST /admin/rules/{id}/edit
    // -----------------------------------------------------------------------

    public function editForm(Request $request, array $params): Response
    {
        $listingId = (int) ($params['id'] ?? 0);
        $listing   = $this->requireListing($listingId);
        if ($listing instanceof Response) {
            return $listing;
        }

        $session = $this->app->getSession();
        $success = (string) $session->get('lstr_flash_success', '');
        $error   = (string) $session->get('lstr_flash_error',   '');
        $session->remove('lstr_flash_success');
        $session->remove('lstr_flash_error');

        $ruleSet = (new RulesRepo($this->app->getDatabase()))->getForListing($listingId);

        return $this->adminLayout('admin/addons/rules/edit.php', 'Edit Rules', 'rules', [
            'listing'    => $listing,
            'listing_id' => $listingId,
            'rules'      => $ruleSet,
            'success'    => $success,
            'error'      => $error,
        ], '', [
            $this->crumb('Join Rules', $this->adminUrl('/admin/rules')),
            $this->crumb($listing->title),
        ]);
    }

    public function editSave(Request $request, array $params): Response
    {
        $listingId = (int) ($params['id'] ?? 0);
        $listing   = $this->requireListing($listingId);
        if ($listing instanceof Response) {
            return $listing;
        }

        $title   = trim((string) $request->post('title',   'Join Rules'));
        $body    = trim((string) $request->post('body',    ''));
        $enabled = $request->post('enabled', '') !== '';

        if ($title === '') {
            $title = 'Join Rules';
        }

        if ($body === '' && $enabled) {
            $session = $this->app->getSession();
            $session->set('lstr_flash_error', 'Rules cannot be enabled without any rules text. Add text or uncheck "Enabled".');
            return Response::redirect($this->adminUrl('/admin/rules/' . $listingId . '/edit'));
        }

        $repo = new RulesRepo($this->app->getDatabase());
        $existing = $repo->getForListing($listingId);
        $oldVersion = $existing?->version ?? 0;

        $repo->upsert($listingId, $title, $body, $enabled);

        $newVersion = $repo->getForListing($listingId)?->version ?? 0;
        $bumped     = $newVersion > $oldVersion;

        (new ActivityRepo($this->app->getDatabase()))->log(
            (int) $this->app->getSession()->get('lstr_admin_id', 0),
            'rules.saved',
            'Saved rules for listing "' . $listing->title . '"'
                . ($bumped ? ' (version bumped to ' . $newVersion . ')' : ''),
        );

        $session = $this->app->getSession();
        $session->set('lstr_flash_success', 'Rules saved.' . ($bumped ? ' All existing fan agreements have been reset.' : ''));

        return Response::redirect($this->adminUrl('/admin/rules/' . $listingId . '/edit'));
    }

    // -----------------------------------------------------------------------
    // POST /admin/rules/{id}/toggle
    // -----------------------------------------------------------------------

    public function toggle(Request $request, array $params): Response
    {
        $listingId = (int) ($params['id'] ?? 0);
        $listing   = $this->requireListing($listingId);
        if ($listing instanceof Response) {
            return $listing;
        }

        $repo    = new RulesRepo($this->app->getDatabase());
        $ruleSet = $repo->getForListing($listingId);

        if ($ruleSet === null) {
            $this->app->getSession()->set('lstr_flash_error', 'No rules exist for this listing yet. Use the edit page to create them.');
            return Response::redirect($this->adminUrl('/admin/rules'));
        }

        $newState = !$ruleSet->enabled;

        if ($newState && trim($ruleSet->body) === '') {
            $this->app->getSession()->set('lstr_flash_error', 'Rules cannot be enabled without any rules text. Use the edit page to add text first.');
            return Response::redirect($this->adminUrl('/admin/rules'));
        }

        $repo->setEnabled($listingId, $newState);

        (new ActivityRepo($this->app->getDatabase()))->log(
            (int) $this->app->getSession()->get('lstr_admin_id', 0),
            'rules.toggled',
            ($newState ? 'Enabled' : 'Disabled') . ' rules for listing "' . $listing->title . '"',
        );

        $this->app->getSession()->set(
            'lstr_flash_success',
            'Rules ' . ($newState ? 'enabled' : 'disabled') . ' for ' . $listing->title . '.',
        );

        return Response::redirect($this->adminUrl('/admin/rules'));
    }

    // -----------------------------------------------------------------------
    // POST /admin/rules/{id}/delete
    // -----------------------------------------------------------------------

    public function delete(Request $request, array $params): Response
    {
        $listingId = (int) ($params['id'] ?? 0);
        $listing   = $this->requireListing($listingId);
        if ($listing instanceof Response) {
            return $listing;
        }

        (new RulesRepo($this->app->getDatabase()))->delete($listingId);

        (new ActivityRepo($this->app->getDatabase()))->log(
            (int) $this->app->getSession()->get('lstr_admin_id', 0),
            'rules.deleted',
            'Deleted rules for listing "' . $listing->title . '"',
        );

        $this->app->getSession()->set('lstr_flash_success', 'Rules deleted for ' . $listing->title . '.');

        return Response::redirect($this->adminUrl('/admin/rules'));
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    /**
     * Returns the listing model, or a redirect Response if not found.
     *
     * @return \Luster\Models\Listing|Response
     */
    private function requireListing(int $listingId): mixed
    {
        if ($listingId <= 0) {
            return Response::redirect($this->adminUrl('/admin/rules'));
        }

        $listing = (new ListingRepo($this->app->getDatabase(), $this->app->getConfig()))
            ->getById($listingId);

        if ($listing === null) {
            $this->app->getSession()->set('lstr_flash_error', 'Listing not found.');
            return Response::redirect($this->adminUrl('/admin/rules'));
        }

        return $listing;
    }
}
