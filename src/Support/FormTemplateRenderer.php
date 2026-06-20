<?php

declare(strict_types=1);

namespace Luster\Support;

use Luster\Core\View;

/**
 * Expands {token} placeholders in an admin-defined form template and wraps
 * the resulting HTML in a <form> element with CSRF protection and the
 * standard honeypot field.
 *
 * Used by public form controllers/embeds (join, codes-submit, affiliate-apply,
 * adopt, kim-signup, ...) when a per-listing or global form template is set.
 * When no template is configured, controllers fall back to the existing
 * hardcoded PHP templates and never call this class.
 *
 * @see docs/FORM-TEMPLATES-PLAN.md
 */
final class FormTemplateRenderer
{
    /**
     * Single-pass token substitution — every {token} in $tokens is replaced
     * with its corresponding pre-rendered HTML/string value. Unknown tokens
     * in the template are left as-is (visible, for easier debugging).
     *
     * Uses strtr() (not str_replace()) so replacement values are never
     * re-scanned for further token matches — a replacement HTML value that
     * happens to contain a literal "{token}" string is left untouched.
     *
     * @param array<string, string> $tokens token (e.g. '{field_name}') => replacement HTML
     */
    public static function expandTokens(string $template, array $tokens): string
    {
        return strtr($template, $tokens);
    }

    /**
     * Wraps already-expanded form body HTML in a <form> tag with the CSRF
     * field and the standard "website_confirm" honeypot field.
     *
     * Set $preview to true for admin preview iframes — adds
     * onsubmit="return false;" so the Submit/Clear buttons don't navigate.
     */
    public static function wrapForm(
        string $innerHtml,
        string $action,
        string $csrfToken,
        bool   $multipart,
        bool   $preview = false,
        string $formMarker = '',
    ): string {
        $enctype  = $multipart ? ' enctype="multipart/form-data"' : '';
        $onsubmit = $preview ? ' onsubmit="return false;"' : '';
        $marker   = $formMarker !== ''
            ? '<input type="hidden" name="_luster_embed_form" value="' . View::e($formMarker) . '">'
            : '';

        return '<form class="fl-form" method="POST" action="' . View::e($action) . '"' . $enctype . $onsubmit . '>'
             . '<input type="hidden" name="_csrf" value="' . View::e($csrfToken) . '">'
             . $marker
             . '<div style="display:none" aria-hidden="true"><input type="text" name="website_confirm" tabindex="-1" autocomplete="off"></div>'
             . $innerHtml
             . '</form>';
    }
}
