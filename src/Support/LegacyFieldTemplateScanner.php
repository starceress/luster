<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Scans a hand-written legacy template file (Enthusiast's addform.inc.php
 convention, or similar) for <select>/<option>, radio, and checkbox markup, so
 an admin can quickly re-configure an imported additional field's real type
 after a migration where no database anywhere had type/options information —
 only a hand-written per-listing include file the site owner wrote themselves.

 SECURITY: the input is treated purely as inert text. Every PHP open/close
 tag pair (long form, short-echo form, or the bare short form) is stripped
 via regex BEFORE anything else touches the content — never included,
 required, or eval'd.
 Only the remaining static HTML skeleton is parsed for markup, using
 DOMDocument (a passive parser, not a template engine).
*/

declare(strict_types=1);

namespace Luster\Support;

use DOMDocument;
use DOMNode;

final class LegacyFieldTemplateScanner
{
    /**
     * @return array<int, array{type:string, label:string, options:string[], snippet:string}>
     */
    public function scan(string $rawContents): array
    {
        $html = $this->stripPhpTags($rawContents);

        if (trim($html) === '') {
            return [];
        }

        $dom = new DOMDocument();
        $prevSetting = libxml_use_internal_errors(true);
        // Wrap in a minimal document so loadHTML doesn't choke on a bare fragment
        // (the source is never a complete HTML page — just a snippet of markup).
        $dom->loadHTML('<!DOCTYPE html><html><body>' . $html . '</body></html>', LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($prevSetting);

        $candidates = [];

        foreach ($dom->getElementsByTagName('select') as $select) {
            $options = [];
            foreach ($select->getElementsByTagName('option') as $option) {
                // An option with an explicit but empty value attribute (e.g.
                // <option value="">Choose ---</option>) is a blank/placeholder
                // row regardless of its visible text, and must be excluded —
                // selecting it submits nothing. Only fall back to the visible
                // text when there's no value attribute at all.
                if ($option->hasAttribute('value')) {
                    $optText = trim($option->getAttribute('value'));
                } else {
                    $optText = trim($option->textContent);
                }
                if ($optText === '') {
                    continue;
                }
                $options[] = $optText;
            }
            if ($options === []) {
                continue;
            }
            $candidates[] = [
                'type'    => 'dropdown',
                'label'   => $this->guessLabel($select),
                'options' => array_values(array_unique($options)),
                'snippet' => $this->outerHtml($select),
            ];
        }

        // Every <input type="radio"> in the file is folded into a single
        // candidate group, regardless of its `name` attribute. A hand-written
        // template's name is often built from an interpolated PHP variable
        // (e.g. a short-echo tag inside the name attribute) that becomes
        // generic/empty once PHP is stripped, so grouping by name isn't
        // reliable. If a file genuinely
        // defines more than one radio-based field, this lumps them together —
        // acceptable since this is a review-before-apply tool: the admin edits
        // or skips whatever doesn't look right before anything is saved.
        $radioOptions = [];
        $radioLabelNode = null;
        foreach ($dom->getElementsByTagName('input') as $input) {
            if (strtolower($input->getAttribute('type')) === 'radio') {
                $value = trim($input->getAttribute('value'));
                if ($value !== '') {
                    $radioOptions[] = $value;
                    $radioLabelNode ??= $input;
                }
            }
        }
        if ($radioOptions !== [] && $radioLabelNode !== null) {
            $candidates[] = [
                'type'    => 'radio',
                'label'   => $this->guessLabel($radioLabelNode),
                'options' => array_values(array_unique($radioOptions)),
                'snippet' => $this->outerHtml($radioLabelNode),
            ];
        }

        foreach ($dom->getElementsByTagName('input') as $input) {
            if (strtolower($input->getAttribute('type')) === 'checkbox') {
                $candidates[] = [
                    'type'    => 'checkbox',
                    'label'   => $this->guessLabel($input),
                    'options' => [],
                    'snippet' => $this->outerHtml($input),
                ];
            }
        }

        return $candidates;
    }

    /**
     * Removes every PHP open/close tag pair (long form, short-echo form, or
     * the bare short form), leaving only the static HTML skeleton. This is
     * the step that guarantees no PHP is ever evaluated — everything after
     * this point only ever sees plain text.
     */
    private function stripPhpTags(string $raw): string
    {
        return (string) preg_replace('/<\?.*?\?>/s', '', $raw);
    }

    /**
     * Best-effort label guess: the trimmed text immediately preceding the
     * control within its parent, falling back to the parent's own text.
     * Returns '' rather than inventing a label when nothing usable is found —
     * the admin fills it in during review either way.
     */
    private function guessLabel(DOMNode $node): string
    {
        $sibling = $node->previousSibling;
        while ($sibling !== null) {
            $text = trim($sibling->textContent ?? '');
            if ($text !== '') {
                return mb_substr($text, 0, 100);
            }
            $sibling = $sibling->previousSibling;
        }

        $parent = $node->parentNode;
        if ($parent !== null) {
            $text = trim($parent->textContent ?? '');
            if ($text !== '') {
                return mb_substr($text, 0, 100);
            }
        }

        return '';
    }

    private function outerHtml(DOMNode $node): string
    {
        $doc = $node->ownerDocument;
        return $doc !== null ? (string) $doc->saveHTML($node) : '';
    }
}
