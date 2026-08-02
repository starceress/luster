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

final class NewsfeedTemplateRenderer
{
    /**
     * @param \Luster\Addons\Newsfeed\NewsfeedEntry[] $entries
     */
    public static function render(array $entries, string $template): string
    {
        $hasEntries = count($entries) > 0;
        $template = TemplateEngine::conditionals($template, [
            'newsfeed'    => $hasEntries,
            'no_newsfeed' => !$hasEntries,
        ]);

        return TemplateEngine::loop(
            $template,
            'newsfeed_entry',
            $entries,
            static function (string $inner, object $entry): string {
                return TemplateEngine::render(
                    $inner,
                    [
                        'newsfeed_title'      => $entry->title,
                        'newsfeed_date'       => date('F j, Y', strtotime($entry->postedAt)),
                        'newsfeed_author'     => $entry->author ?? '',
                        'newsfeed_categories' => implode(', ', $entry->categories()),
                    ],
                    [
                        'newsfeed_body_html' => NewsfeedBodySanitizer::toDisplayHtml($entry->body),
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
