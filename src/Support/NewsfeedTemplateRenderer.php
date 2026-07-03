<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Renders a newsfeed through TemplateEngine using a saved template string.
 Shared by admin template previews and the newsfeed embed.
*/

declare(strict_types=1);

namespace Luster\Support;

use Luster\Core\TemplateEngine;
use Luster\Core\View;

final class NewsfeedTemplateRenderer
{
    /**
     * @param \Luster\Addons\Newsfeed\NewsfeedEntry[] $entries
     */
    public static function render(array $entries, string $template): string
    {
        $hasEntries = count($entries) > 0;
        $template = TemplateEngine::conditionals($template, [
            'updates'    => $hasEntries,
            'no_updates' => !$hasEntries,
        ]);

        return TemplateEngine::loop(
            $template,
            'update',
            $entries,
            static function (string $inner, object $entry): string {
                return TemplateEngine::render(
                    $inner,
                    [
                        'update_title'      => $entry->title,
                        'update_date'       => date('F j, Y', strtotime($entry->postedAt)),
                        'update_author'     => $entry->author ?? '',
                        'update_categories' => implode(', ', $entry->categories()),
                    ],
                    [
                        'update_body_html' => nl2br(View::e($entry->body)),
                    ],
                    [
                        'author'     => ($entry->author ?? '') !== '',
                        'categories' => count($entry->categories()) > 0,
                    ],
                );
            },
        );
    }
}
