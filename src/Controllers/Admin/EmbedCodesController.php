<?php

declare(strict_types=1);

namespace Luster\Controllers\Admin;

use Luster\Core\Application;
use Luster\Core\Request;
use Luster\Core\Response;
use Luster\Repositories\FanlistingRepo;

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
        $db      = $this->app->getDatabase();
        $config  = $this->app->getConfig();
        $view    = $this->app->getView();
        $session = $this->app->getSession();
        $baseUrl = $this->app->getBaseUrl();

        $fanlistingRepo = new FanlistingRepo($db, $config);
        $fanlistings    = $fanlistingRepo->getAll();

        // Pick a real fanlisting ID for snippet examples; fall back to 1
        $exampleId = 1;
        foreach ($fanlistings as $fl) {
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
            'fanlistings' => $fanlistings,
            'base_url'    => $baseUrl,
            'csrf_token'  => $this->app->getCSRF()->token(),
        ]);

        return Response::html($view->render('admin/layout.php', [
            'page_title'      => 'Embed Codes',
            'app_name'        => (string) $config->get('app_name', 'Luster'),
            'admin_username'  => (string) $session->get('lstr_admin_username', ''),
            'csrf_token'      => $this->app->getCSRF()->token(),
            'current_section' => 'embed-codes',
            'base_url'        => $baseUrl,
            'content'         => $inner,
        ]));
    }
}
