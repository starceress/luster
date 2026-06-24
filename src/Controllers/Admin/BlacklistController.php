<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Manages the email/domain blacklist from the Settings panel. Blacklist patterns
 are either full email addresses or @domain patterns that block matching
 addresses from joining any listing. Provides add and delete actions, with the
 index redirecting to the blacklist panel on the Settings page.
*/

declare(strict_types=1);

namespace Luster\Controllers\Admin;

use Luster\Core\Application;
use Luster\Core\Request;
use Luster\Core\Response;
use Luster\Repositories\ActivityRepo;
use Luster\Repositories\BlacklistRepo;

/**
 * Manages the email/domain blacklist accessible from the Settings panel.
 *
 * Blacklist patterns are either full email addresses or @domain suffixes that
 * block matching addresses from joining any listing. The index simply redirects
 * to the blacklist panel on the Settings page; the add and delete actions handle
 * mutations and redirect back to the same panel after logging each change.
 */
final class BlacklistController
{
    public function __construct(private readonly Application $app) {}

    // -----------------------------------------------------------------------
    // GET /admin/blacklist
    // -----------------------------------------------------------------------

    /**
     * Redirects to the blacklist panel on the Settings page.
     *
     * There is no dedicated blacklist index — the list is rendered as a card
     * inside the Settings page at #panel-blacklist.
     */
    public function index(Request $request, array $params): Response
    {
        return Response::redirect($this->app->getBaseUrl() . '/admin/settings#panel-blacklist');
    }

    // -----------------------------------------------------------------------
    // POST /admin/blacklist/add
    // -----------------------------------------------------------------------

    /**
     * Adds a new entry to the blacklist.
     *
     * The pattern must be either a full email address or an @domain string.
     * Duplicate entries are caught by the database unique constraint and reported
     * as a flash error rather than a 500.
     */
    public function add(Request $request, array $params): Response
    {
        $baseUrl = $this->app->getBaseUrl();
        $session = $this->app->getSession();
        $pattern = strtolower(trim((string) $request->post('pattern', '')));
        $note    = trim((string) $request->post('note', ''));

        if ($pattern === '') {
            $session->set('lstr_flash_error', 'Pattern is required.');
            return Response::redirect($baseUrl . '/admin/settings#panel-blacklist');
        }

        // Basic format check: must be an email address or start with '@'
        if (!str_starts_with($pattern, '@') && !filter_var($pattern, FILTER_VALIDATE_EMAIL)) {
            $session->set('lstr_flash_error', 'Pattern must be a full email address (e.g. spam@example.com) or a domain starting with @ (e.g. @spam.com).');
            return Response::redirect($baseUrl . '/admin/settings#panel-blacklist');
        }

        $repo = new BlacklistRepo($this->app->getDatabase());

        try {
            $repo->insert($pattern, $note);
        } catch (\PDOException $e) {
            // Duplicate entry
            $session->set('lstr_flash_error', 'That pattern is already on the blacklist.');
            return Response::redirect($baseUrl . '/admin/settings#panel-blacklist');
        }

        $adminId = (int) $this->app->getSession()->get('lstr_admin_id');
        (new ActivityRepo($this->app->getDatabase()))->log(
            $adminId,
            'blacklist.add',
            "Added blacklist pattern: {$pattern}",
            $request->ip(),
        );

        $session->set('lstr_flash_success', "Pattern \"{$pattern}\" added to blacklist.");
        return Response::redirect($baseUrl . '/admin/settings#panel-blacklist');
    }

    // -----------------------------------------------------------------------
    // POST /admin/blacklist/{id}/delete
    // -----------------------------------------------------------------------

    /**
     * Removes a blacklist entry by its ID.
     *
     * Fetches the pattern before deleting so it can be included in the activity
     * log entry. Returns a 404-style flash error if the ID is not found.
     */
    public function delete(Request $request, array $params): Response
    {
        $baseUrl = $this->app->getBaseUrl();
        $session = $this->app->getSession();
        $id      = (int) ($params['id'] ?? 0);

        $repo  = new BlacklistRepo($this->app->getDatabase());
        $entry = null;

        // Fetch pattern for the activity log before deleting
        foreach ($repo->getAll() as $e) {
            if ($e['id'] === $id) {
                $entry = $e;
                break;
            }
        }

        if ($entry === null) {
            $session->set('lstr_flash_error', 'Blacklist entry not found.');
            return Response::redirect($baseUrl . '/admin/settings#panel-blacklist');
        }

        $repo->delete($id);

        $adminId = (int) $session->get('lstr_admin_id');
        (new ActivityRepo($this->app->getDatabase()))->log(
            $adminId,
            'blacklist.delete',
            "Removed blacklist pattern: {$entry['pattern']}",
            $request->ip(),
        );

        $session->set('lstr_flash_success', "Pattern removed from blacklist.");
        return Response::redirect($baseUrl . '/admin/settings#panel-blacklist');
    }


}
