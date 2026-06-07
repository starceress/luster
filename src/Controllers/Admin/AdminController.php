<?php

declare(strict_types=1);

namespace Luster\Controllers\Admin;

use Luster\Core\Application;
use Luster\Core\Response;
use Luster\Core\UrlNormalizer;

/**
 * Abstract base for all admin controllers.
 *
 * Provides adminLayout() — the single shared method for rendering an admin
 * page inside the Velvet Geode shell (layout.php).
 *
 * Usage in subclasses:
 *   return $this->adminLayout('admin/foo/index.php', 'Page Title', 'section', $data);
 *   return $this->adminLayout('admin/foo/edit.php',  'Edit Foo',   'section', $data, '', [
 *       $this->crumb('Foo', $this->adminUrl('/admin/foo')),
 *       $this->crumb('Bar'),
 *   ]);
 */
abstract class AdminController
{
    public function __construct(protected readonly Application $app) {}

    /**
     * Renders an admin page inside the Velvet Geode layout shell.
     *
     * @param string $template     Path relative to templates/ (e.g. 'admin/foo/index.php')
     * @param string $pageTitle    Value of $page_title in the layout
     * @param string $section      Value of $current_section (drives sidebar highlight)
     * @param array  $data         Variables injected into the inner content template
     * @param string $pageSubtitle Optional legacy breadcrumb subtitle
     * @param array<int,array{label:string,url?:string|null}> $breadcrumbs Structured topbar breadcrumbs
     */
    protected function adminLayout(
        string $template,
        string $pageTitle,
        string $section,
        array  $data         = [],
        string $pageSubtitle = '',
        array  $breadcrumbs  = [],
    ): Response {
        $view      = $this->app->getView();
        $config    = $this->app->getConfig();
        $session   = $this->app->getSession();
        $baseUrl   = $this->app->getBaseUrl();
        $csrfToken = $this->app->getCSRF()->token();
        $pageBreadcrumbs = $breadcrumbs !== []
            ? $this->normalizeBreadcrumbs($breadcrumbs)
            : $this->breadcrumbsFromSubtitle($pageSubtitle);

        $inner = $view->render($template, array_merge($this->app->getAdminNavData(), $data, [
            'base_url'   => $baseUrl,
            'csrf_token' => $csrfToken,
        ]));

        return Response::html($view->render('admin/layout.php', array_merge([
            'page_title'       => $pageTitle,
            'page_subtitle'    => $pageSubtitle,
            'page_breadcrumbs' => $pageBreadcrumbs,
            'site_name'        => (string) $config->get('site_name', 'Luster'),
            'admin_username'   => (string) $session->get('lstr_admin_username', ''),
            'csrf_token'       => $csrfToken,
            'current_section'  => $section,
            'base_url'         => $baseUrl,
            'content'          => $inner,
        ], $this->app->getAdminNavData())));
    }

    /**
     * Creates a structured breadcrumb item for the admin topbar.
     *
     * @return array{label:string,url?:string|null}
     */
    protected function crumb(string $label, ?string $url = null): array
    {
        $crumb = ['label' => trim($label)];
        if ($url !== null && trim($url) !== '') {
            $crumb['url'] = $url;
        }

        return $crumb;
    }

    protected static function httpsify(string $url): string
    {
        return UrlNormalizer::httpsify($url);
    }

    protected function adminUrl(string $path = ''): string
    {
        $base = rtrim($this->app->getBaseUrl(), '/');
        $path = '/' . ltrim($path, '/');

        return $base . ($path === '/' ? '' : $path);
    }

    /**
     * @param array<int,array{label:string,url?:string|null}> $breadcrumbs
     * @return array<int,array{label:string,url?:string|null}>
     */
    private function normalizeBreadcrumbs(array $breadcrumbs): array
    {
        $out = [];
        foreach ($breadcrumbs as $crumb) {
            $label = trim((string) ($crumb['label'] ?? ''));
            if ($label === '') {
                continue;
            }

            $item = ['label' => $label];
            $url = trim((string) ($crumb['url'] ?? ''));
            if ($url !== '') {
                $item['url'] = $url;
            }
            $out[] = $item;
        }

        return $out;
    }

    /**
     * Keeps legacy subtitle strings visible while pages migrate to breadcrumbs.
     *
     * @return array<int,array{label:string,url?:string|null}>
     */
    private function breadcrumbsFromSubtitle(string $subtitle): array
    {
        $subtitle = trim($subtitle);
        if ($subtitle === '') {
            return [];
        }

        $parts = preg_split('/\s*(?:â€º|›|>)\s*/u', $subtitle) ?: [$subtitle];
        $crumbs = [];
        foreach ($parts as $part) {
            $label = trim($part);
            if ($label !== '') {
                $crumbs[] = ['label' => $label];
            }
        }

        return $crumbs;
    }
}
