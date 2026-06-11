<?php

declare(strict_types=1);

namespace Luster\Support;

use Luster\Core\TemplateEngine;
use Luster\Core\View;

/**
 * Renders a rule set's plain-text body into HTML for the public rules gate.
 *
 * Lines starting with "1. ", "2. ", etc. become <li> items in an ordered or
 * unordered list (depending on $listStyle); all other non-blank lines become
 * <p> blocks (consecutive lines joined with <br>). {token} placeholders are
 * substituted via TemplateEngine after each line is escaped, matching the
 * escape-then-substitute pattern used by ListingRowRenderer.
 */
final class RulesBodyRenderer
{
    /**
     * @param array<string,string> $tokens raw (unescaped) values for {subject},
     *        {listing_title}, {listing_url}, {join_url}, {update_url}
     */
    public static function render(string $body, array $tokens, string $listStyle = 'numbered'): string
    {
        $listTag   = $listStyle === 'circle' ? 'ul' : 'ol';
        $listClass = $listStyle === 'circle' ? ' class="fl-rules-circle"' : '';

        $html      = '';
        $listItems = [];
        $paraLines = [];

        $flushList = function () use (&$listItems, &$html, $listTag, $listClass): void {
            if ($listItems === []) {
                return;
            }
            $html .= "<{$listTag}{$listClass}>" . implode('', $listItems) . "</{$listTag}>";
            $listItems = [];
        };

        $flushPara = function () use (&$paraLines, &$html): void {
            if ($paraLines === []) {
                return;
            }
            $html .= '<p>' . implode('<br>', $paraLines) . '</p>';
            $paraLines = [];
        };

        foreach (preg_split('/\r\n|\r|\n/', $body) as $line) {
            $trimmed = trim($line);

            if ($trimmed === '') {
                $flushList();
                $flushPara();
                continue;
            }

            if (preg_match('/^\d+\.\s+(.*)$/', $trimmed, $m)) {
                $flushPara();
                $listItems[] = '<li>' . TemplateEngine::tokens(View::e($m[1]), $tokens) . '</li>';
                continue;
            }

            $flushList();
            $paraLines[] = TemplateEngine::tokens(View::e($trimmed), $tokens);
        }

        $flushList();
        $flushPara();

        return $html;
    }
}
