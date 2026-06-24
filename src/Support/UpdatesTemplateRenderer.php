<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Renders an updates/news feed through TemplateEngine using a saved template
 string. Shared by admin template previews and the updates embed.
*/

declare(strict_types=1);

namespace Luster\Support;

use Luster\Core\TemplateEngine;
use Luster\Core\View;

final class UpdatesTemplateRenderer
{
    /**
     * @param \Luster\Addons\Updates\Update[] $updates
     */
    public static function render(array $updates, string $template): string
    {
        $hasUpdates = count($updates) > 0;
        $template = TemplateEngine::conditionals($template, [
            'updates'    => $hasUpdates,
            'no_updates' => !$hasUpdates,
        ]);

        return TemplateEngine::loop(
            $template,
            'update',
            $updates,
            static function (string $inner, object $update): string {
                return TemplateEngine::render(
                    $inner,
                    [
                        'update_title'      => $update->title,
                        'update_date'       => date('F j, Y', strtotime($update->postedAt)),
                        'update_author'     => $update->author ?? '',
                        'update_categories' => implode(', ', $update->categories()),
                    ],
                    [
                        'update_body_html' => nl2br(View::e($update->body)),
                    ],
                    [
                        'author'     => ($update->author ?? '') !== '',
                        'categories' => count($update->categories()) > 0,
                    ],
                );
            },
        );
    }
}
