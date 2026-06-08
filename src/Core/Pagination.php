<?php

declare(strict_types=1);

namespace Luster\Core;

/**
 * Generates pagination HTML from an admin-editable {token} template.
 *
 * Replaces MembersController::buildPagination() and the inline pagination
 * loop in embed/updates.php with one shared, tested implementation. Static
 * (like TemplateEngine) — a stateless string-transformation utility called
 * directly throughout the codebase.
 *
 * Computes a windowed page list (current page ±2, always including the first
 * and last page, with `…` gap markers in between) and feeds it through
 * TemplateEngine::loop() as {each_page}, handing the caller's template full
 * control over per-item markup via {page_label}, {page_url}, {if_current},
 * {if_linked}, {if_gap} — see docs/PUBLIC-TEMPLATES-PLAN.md "Pagination".
 */
final class Pagination
{
    private const DEFAULT_TEMPLATE = <<<'HTML'
        {if_pages}
        <div class="fl-pagination-nav fl-pagination" role="group" aria-label="Pagination">
          {if_prev}<a class="fl-page-prev" href="{prev_url}">&laquo; Prev</a>{/if_prev}
          {each_page}{if_current}<span class="fl-page-current">{page_label}</span>{/if_current}{if_linked}<a class="fl-page-link" href="{page_url}">{page_label}</a>{/if_linked}{if_gap}<span class="fl-page-gap">{page_label}</span>{/if_gap}{/each_page}
          {if_next}<a class="fl-page-next" href="{next_url}">Next &raquo;</a>{/if_next}
        </div>
        {/if_pages}
        HTML;

    private function __construct()
    {
    }

    /**
     * @param array<string, scalar|null> $extraParams extra query-string params merged into every page URL
     */
    public static function render(
        int $current,
        int $total,
        string $baseUrl,
        string $template = '',
        array $extraParams = [],
    ): string {
        if ($total <= 1) {
            return '';
        }

        $tpl = $template !== '' ? $template : self::DEFAULT_TEMPLATE;

        // Split off any query string already present on $baseUrl (e.g. callers
        // that build the base URL from the current request's path + sort/filter
        // params) so we merge rather than append a second "?", which would
        // otherwise produce an invalid URL like "/members?sort=name?page=2".
        [$basePath, $baseQuery] = array_pad(explode('?', $baseUrl, 2), 2, '');
        $baseParams = [];
        parse_str($baseQuery, $baseParams);

        $makeUrl = static function (int $page) use ($basePath, $baseParams, $extraParams): string {
            $params = array_merge($baseParams, $extraParams, ['page' => $page]);

            return $basePath . '?' . http_build_query($params);
        };

        $items = self::buildWindow($current, $total);

        return TemplateEngine::render(
            $tpl,
            escaped: [
                'current_page' => $current,
                'total_pages'  => $total,
                'prev_url'     => $current > 1 ? $makeUrl($current - 1) : '',
                'next_url'     => $current < $total ? $makeUrl($current + 1) : '',
            ],
            conditions: [
                'pages' => $total > 1,
                'prev'  => $current > 1,
                'next'  => $current < $total,
            ],
            loops: [
                'page' => [
                    'rows'  => $items,
                    'rowFn' => static fn(string $inner, array $item): string => TemplateEngine::render(
                        $inner,
                        escaped: [
                            'page_label' => $item['label'],
                            'page_url'   => $item['page'] !== null ? $makeUrl($item['page']) : '',
                        ],
                        conditions: [
                            'current' => $item['type'] === 'current',
                            'linked'  => $item['type'] === 'linked',
                            'gap'     => $item['type'] === 'gap',
                        ],
                    ),
                ],
            ],
        );
    }

    /**
     * Builds the windowed page-item list: current ±2, first/last always
     * included, `…` gap markers filling the space in between.
     *
     * @return array<int, array{type: string, label: string, page: ?int}>
     */
    private static function buildWindow(int $current, int $total): array
    {
        $pages = [1];

        for ($i = $current - 2; $i <= $current + 2; $i++) {
            if ($i > 1 && $i < $total) {
                $pages[] = $i;
            }
        }

        if ($total > 1) {
            $pages[] = $total;
        }

        $pages = array_values(array_unique($pages));
        sort($pages);

        $items    = [];
        $previous = null;

        foreach ($pages as $page) {
            if ($previous !== null && $page - $previous > 1) {
                $items[] = ['type' => 'gap', 'label' => '…', 'page' => null];
            }

            $items[] = [
                'type'  => $page === $current ? 'current' : 'linked',
                'label' => (string) $page,
                'page'  => $page === $current ? null : $page,
            ];

            $previous = $page;
        }

        return $items;
    }
}
