<?php

declare(strict_types=1);

namespace Luster\Support;

use Luster\Core\View;

/**
 * Renders a rule set body for the public gate.
 *
 * Two rendering paths are chosen automatically:
 *
 *   HTML template  — body contains a tag-like `<` (e.g. `<p>`, `</div>`, `<!--`) or
 *                    `{gate_button}` (new-style admin-authored HTML). Text tokens are
 *                    HTML-escaped; raw gate tokens inserted verbatim.
 *
 *   Legacy plain text — body has no tag-like `<` and no {gate_button} (created before
 *                    the HTML template system). Lines beginning with "N. " become <li>
 *                    items in an <ol>; other non-blank lines become <p> blocks. Text and
 *                    token values are HTML-escaped — including bare `<`/`>` characters,
 *                    e.g. "Score < 10" renders as "Score &lt; 10".
 *
 *                    Note: a stray `<` immediately followed by a letter (e.g. "Use <em>
 *                    sparingly") is indistinguishable from a real tag and is treated as
 *                    HTML. This is a rare edge case for admin-authored content.
 *
 * {if_gate_checkbox}...{/if_gate_checkbox} is resolved before token substitution in
 * both paths: the enclosed content is kept only when $raw['gate_checkbox'] is non-empty
 * (checkbox gate type), otherwise the block (including its tags) is removed.
 */
final class RulesBodyRenderer
{
    /**
     * Returns the built-in default Join Rules template body, used to pre-fill
     * the Global Rules Template and per-listing Rules editors for new records.
     */
    public static function defaultBody(): string
    {
        return <<<'RULES'
<h1>Join Rules</h1>

<p>Only submit the join form once. If you need to change your membership details later, use the <a href="{update_url}">update form</a> instead. Memberships may be edited, declined, or removed if they are inappropriate or unsafe.</p>

<ol class="fl-rules-list">
  <li>You must be a genuine fan of {subject}.</li>
  <li>Please use a real name or nickname. Offensive, impersonating, or spam-like names may be declined.</li>
  <li>A valid email address is required so your membership can be approved or updated.</li>
  <li>If you include a website URL, please make sure it is your own site or one you help maintain.</li>
  <li>Do not direct-link images. Please save fanlisting codes and upload them to your own server.</li>
</ol>

<p class="fl-rules-gate-checkbox">{if_gate_checkbox}{gate_checkbox}{/if_gate_checkbox}By joining, you agree to follow the rules.</p>

<div class="fl-form-actions">
  {gate_button}
</div>
RULES;
    }

    /**
     * Returns true when the body uses HTML syntax or the new gate-token system.
     * Returns false for legacy plain-text bodies.
     */
    public static function isHtmlTemplate(string $body): bool
    {
        return preg_match('/<\/?[a-zA-Z!]/', $body) === 1 || str_contains($body, '{gate_button}');
    }

    /**
     * Returns true when the body embeds its own gate controls via {gate_button}.
     * When false, the caller must append gate controls after the rendered HTML.
     */
    public static function hasGateToken(string $body): bool
    {
        return str_contains($body, '{gate_button}');
    }

    /**
     * Renders the body, choosing HTML-template or legacy-plain-text path automatically.
     *
     * {if_gate_checkbox}...{/if_gate_checkbox} blocks are resolved first, based on
     * whether $raw['gate_checkbox'] is non-empty (checkbox gate type).
     *
     * @param array<string,string> $escaped  Raw values auto-escaped before substitution
     * @param array<string,string> $raw      Pre-rendered HTML values inserted verbatim
     *                                       (only applied in the HTML-template path)
     */
    public static function render(string $body, array $escaped = [], array $raw = []): string
    {
        $isHtml = self::isHtmlTemplate($body);
        $body   = self::applyGateCheckboxConditional($body, ($raw['gate_checkbox'] ?? '') !== '');

        if ($isHtml) {
            return self::renderHtml($body, $escaped, $raw);
        }

        return self::renderLegacyPlainText($body, $escaped);
    }

    /**
     * Renders the body twice — once for each gate type (checkbox and button) —
     * so admins can preview {if_gate_checkbox} behaviour in both states without
     * needing extra form fields. Each render is wrapped in the public gate
     * markup and preceded by a labelled section divider.
     *
     * When $gateLabel is non-empty, it is used as the {gate_button} text in
     * BOTH sections (mirroring RuleSet::effectiveGateLabel(), which applies the
     * same admin-configured label regardless of gate type). When blank, each
     * section falls back to the type-appropriate built-in default.
     *
     * @param array<string,string> $escaped Text-token values (auto-escaped)
     */
    public static function renderPreview(string $body, array $escaped = [], string $gateLabel = ''): string
    {
        $checkboxLabel = $gateLabel !== '' ? $gateLabel : 'I have read and agree to the rules above.';
        $buttonLabel   = $gateLabel !== '' ? $gateLabel : 'I agree — proceed to join form';

        $checkboxGate = [
            'gate_checkbox' => '<input type="checkbox" class="fl-rules-agree-check" name="agree" value="1" required>',
            'gate_button'   => '<button type="submit" class="fl-submit fl-rules-gate-btn">' . View::e($checkboxLabel) . '</button>',
        ];
        $buttonGate = [
            'gate_checkbox' => '',
            'gate_button'   => '<button type="submit" class="fl-submit fl-rules-gate-btn" name="agree" value="1">' . View::e($buttonLabel) . '</button>',
        ];

        return self::sectionLabel('Checkbox Gate')
            . self::wrapGate(self::renderWithFallback($body, $escaped, $checkboxGate))
            . self::sectionLabel('Button Gate')
            . self::wrapGate(self::renderWithFallback($body, $escaped, $buttonGate));
    }

    // -------------------------------------------------------------------------
    // Private rendering paths
    // -------------------------------------------------------------------------

    /**
     * Resolves {if_gate_checkbox}...{/if_gate_checkbox} blocks, keeping the
     * enclosed content only when the checkbox gate is active. Always run
     * before flat token substitution.
     */
    private static function applyGateCheckboxConditional(string $body, bool $showCheckbox): string
    {
        return (string) preg_replace_callback(
            '/\{if_gate_checkbox\}(.*?)\{\/if_gate_checkbox\}/s',
            static fn(array $m): string => $showCheckbox ? $m[1] : '',
            $body,
        );
    }

    /** @param array<string,string> $escaped @param array<string,string> $raw */
    private static function renderWithFallback(string $body, array $escaped, array $raw): string
    {
        $html = self::render($body, $escaped, $raw);
        if (!self::hasGateToken($body)) {
            $html .= "\n" . $raw['gate_checkbox'] . "\n" . $raw['gate_button'];
        }
        return $html;
    }

    private static function wrapGate(string $innerHtml): string
    {
        return '<div class="fl-embed fl-embed--rules-gate"><div class="fl-rules-gate">'
            . '<form class="fl-form fl-rules-form">' . $innerHtml . '</form>'
            . '</div></div>';
    }

    /**
     * Renders a small section divider, matching
     * ListingController::previewSectionLabel().
     */
    private static function sectionLabel(string $label): string
    {
        return '<div style="margin:1.5rem 0 .5rem;padding:.35rem .6rem;background:#eef1f8;'
            . 'border-left:3px solid #889bd6;font:600 .75rem sans-serif;color:#445;'
            . 'text-transform:uppercase;letter-spacing:.04em">'
            . htmlspecialchars($label, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
            . '</div>';
    }

    /** @param array<string,string> $escaped @param array<string,string> $raw */
    private static function renderHtml(string $body, array $escaped, array $raw): string
    {
        $map = [];
        foreach ($escaped as $key => $value) {
            $map['{' . $key . '}'] = View::e((string) $value);
        }
        foreach ($raw as $key => $value) {
            $map['{' . $key . '}'] = (string) $value;
        }
        return strtr($body, $map);
    }

    /**
     * Converts legacy plain-text body to HTML paragraphs / ordered list, then
     * substitutes text tokens. Gate tokens ({gate_checkbox}, {gate_button}) are
     * not supported in this path — the caller must append controls separately.
     *
     * @param array<string,string> $escaped  Raw text-token values (auto-escaped)
     */
    private static function renderLegacyPlainText(string $body, array $escaped): string
    {
        $tokenMap = [];
        foreach ($escaped as $key => $value) {
            $tokenMap['{' . $key . '}'] = View::e((string) $value);
        }

        $html      = '';
        $listItems = [];
        $paraLines = [];

        $flushList = function () use (&$listItems, &$html): void {
            if ($listItems === []) {
                return;
            }
            $html     .= '<ol>' . implode('', $listItems) . '</ol>';
            $listItems = [];
        };

        $flushPara = function () use (&$paraLines, &$html): void {
            if ($paraLines === []) {
                return;
            }
            $html     .= '<p>' . implode('<br>', $paraLines) . '</p>';
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
                $listItems[] = '<li>' . strtr(View::e($m[1]), $tokenMap) . '</li>';
                continue;
            }

            $flushList();
            $paraLines[] = strtr(View::e($trimmed), $tokenMap);
        }

        $flushList();
        $flushPara();

        return $html;
    }
}
