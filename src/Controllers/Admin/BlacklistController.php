<?php

declare(strict_types=1);

namespace Luster\Controllers\Admin;

use Luster\Core\Application;
use Luster\Core\Request;
use Luster\Core\Response;
use Luster\Repositories\ActivityRepo;
use Luster\Repositories\BlacklistRepo;

/**
 * Admin blacklist management.
 *
 * GET  /admin/blacklist         → index()  — list all entries + add form
 * POST /admin/blacklist/add     → add()    — insert a new pattern
 * POST /admin/blacklist/{id}/delete → delete() — remove an entry
 */
final class BlacklistController
{
    public function __construct(private readonly Application $app) {}

    // -----------------------------------------------------------------------
    // GET /admin/blacklist
    // -----------------------------------------------------------------------

    public function index(Request $request, array $params): Response
    {
        $repo    = new BlacklistRepo($this->app->getDatabase());
        $session = $this->app->getSession();

        $success = (string) $session->get('lstr_flash_success', '');
        $error   = (string) $session->get('lstr_flash_error',   '');
        $session->remove('lstr_flash_success');
        $session->remove('lstr_flash_error');

        return $this->layout([
            'entries'     => $repo->getAll(),
            'success'     => $success,
            'error'       => $error,
            'csrf_token'  => $this->app->getCSRF()->token(),
            'base_url'    => $this->app->getBaseUrl(),
        ]);
    }

    // -----------------------------------------------------------------------
    // POST /admin/blacklist/add
    // -----------------------------------------------------------------------

    public function add(Request $request, array $params): Response
    {
        $baseUrl = $this->app->getBaseUrl();
        $session = $this->app->getSession();
        $pattern = strtolower(trim((string) $request->post('pattern', '')));
        $note    = trim((string) $request->post('note', ''));

        if ($pattern === '') {
            $session->set('lstr_flash_error', 'Pattern is required.');
            return Response::redirect($baseUrl . '/admin/blacklist');
        }

        // Basic format check: must be an email address or start with '@'
        if (!str_starts_with($pattern, '@') && !filter_var($pattern, FILTER_VALIDATE_EMAIL)) {
            $session->set('lstr_flash_error', 'Pattern must be a full email address (e.g. spam@example.com) or a domain starting with @ (e.g. @spam.com).');
            return Response::redirect($baseUrl . '/admin/blacklist');
        }

        $repo = new BlacklistRepo($this->app->getDatabase());

        try {
            $repo->insert($pattern, $note);
        } catch (\PDOException $e) {
            // Duplicate entry
            $session->set('lstr_flash_error', 'That pattern is already on the blacklist.');
            return Response::redirect($baseUrl . '/admin/blacklist');
        }

        $adminId = (int) $this->app->getSession()->get('lstr_admin_id');
        (new ActivityRepo($this->app->getDatabase()))->log(
            $adminId,
            'blacklist.add',
            "Added blacklist pattern: {$pattern}",
        );

        $session->set('lstr_flash_success', "Pattern <strong>" . htmlspecialchars($pattern, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . "</strong> added to blacklist.");
        return Response::redirect($baseUrl . '/admin/blacklist');
    }

    // -----------------------------------------------------------------------
    // POST /admin/blacklist/{id}/delete
    // -----------------------------------------------------------------------

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
            return Response::redirect($baseUrl . '/admin/blacklist');
        }

        $repo->delete($id);

        $adminId = (int) $session->get('lstr_admin_id');
        (new ActivityRepo($this->app->getDatabase()))->log(
            $adminId,
            'blacklist.delete',
            "Removed blacklist pattern: {$entry['pattern']}",
        );

        $session->set('lstr_flash_success', "Pattern removed from blacklist.");
        return Response::redirect($baseUrl . '/admin/blacklist');
    }

    // -----------------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------------

    private function layout(array $data): Response
    {
        $data['page_title']      = 'Blacklist';
        $data['current_section'] = 'blacklist';
        $data['app_name']        = (string) $this->app->getConfig()->get('app_name', 'Luster');
        $data['admin_username']  = (string) $this->app->getSession()->get('lstr_admin_username', '');

        ob_start();
        extract($data);
        require \LUSTER_BASE . '/templates/admin/blacklist/index.php';
        $content = ob_get_clean();

        ob_start();
        extract(['content' => $content] + $data);
        require \LUSTER_BASE . '/templates/admin/layout.php';
        return Response::html((string) ob_get_clean());
    }
}
