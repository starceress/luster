<?php

declare(strict_types=1);

namespace Luster\Controllers\Admin;

use Luster\Core\Application;
use Luster\Core\Request;
use Luster\Core\Response;
use Luster\Repositories\ListingRepo;

/**
 * Read-only reference page showing copyable PHP embed snippets.
 *
 * Route: GET /admin/embed-codes
 */
final class EmbedCodesController
{
    public function __construct(private readonly Application $app) {}

    public function index(Request $request, array $params): Response
    {
        $db        = $this->app->getDatabase();
        $config    = $this->app->getConfig();
        $view      = $this->app->getView();
        $session   = $this->app->getSession();
        $baseUrl   = $this->app->getBaseUrl();
        $csrfToken = $this->app->getCSRF()->token();

        $listingRepo = new ListingRepo($db, $config);
        $listings    = $listingRepo->getAll();

        // Pick a real listing ID for snippet examples; fall back to 1
        $exampleId = 1;
        foreach ($listings as $fl) {
            if (!$fl->upcoming && !$fl->trouble) {
                $exampleId = $fl->id;
                break;
            }
        }

        // Absolute server path to the embed directory
        $lusterBase = defined('LUSTER_BASE') ? \LUSTER_BASE : dirname(__DIR__, 3);
        $embedPath  = rtrim(str_replace('\\', '/', (string) realpath($lusterBase . '/public/embed')), '/');

        $inner = $view->render('admin/embed-codes/index.php', [
            'embed_path'  => $embedPath,
            'example_id'  => $exampleId,
            'listings'    => $listings,
            'base_url'    => $baseUrl,
            'csrf_token'  => $csrfToken,
        ]);

        return Response::html($view->render('admin/layout.php', [
            'page_title'      => 'Embed Codes',
            'site_name'       => (string) $config->get('site_name', 'Luster'),
            'admin_username'  => (string) $session->get('lstr_admin_username', ''),
            'csrf_token'      => $csrfToken,
            'current_section' => 'embed-codes',
            'base_url'        => $baseUrl,
            'content'         => $inner,
        ]));
    }
}
