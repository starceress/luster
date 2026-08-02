<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Sanitizes a News Feed entry body into a small allowlist of inline HTML
 formatting tags for display. Escapes the entire raw body first, then
 selectively converts back only exact, understood tag/attribute patterns —
 never the reverse. News Feed entries are admin-authored only; this exists to
 give admins familiar formatting tools and prevent accidental self-harm (a
 malformed tag breaking the page), not to defend against a malicious author.
*/

declare(strict_types=1);

namespace Luster\Support;

final class NewsfeedBodySanitizer
{
    /**
     * Per-tag attribute spec: which attribute names are recognized, and
     * which are required for the tag to convert at all. `img` and `br` are
     * handled by their own dedicated passes (void elements, different shape
     * entirely) and are not listed here.
     */
    private const TAG_SPECS = [
        'b'          => ['required' => [], 'optional' => ['class', 'title']],
        'strong'     => ['required' => [], 'optional' => ['class', 'title']],
        'i'          => ['required' => [], 'optional' => ['class', 'title']],
        'em'         => ['required' => [], 'optional' => ['class', 'title']],
        'u'          => ['required' => [], 'optional' => ['class', 'title']],
        's'          => ['required' => [], 'optional' => ['class', 'title']],
        'p'          => ['required' => [], 'optional' => ['class', 'title']],
        'blockquote' => ['required' => [], 'optional' => ['class', 'title']],
        'a'          => ['required' => ['href'], 'optional' => ['class', 'title']],
    ];

    /** Namespaced-only class tokens — anything else is silently dropped. */
    private const CLASS_TOKEN_PATTERN = '/^(fl-newsfeed-|nf-)[a-zA-Z0-9-]+$/';

    /**
     * Escapes the raw body, converts the allowed tag/attribute set back to
     * real HTML, and returns it. Does not touch newlines — see toDisplayHtml().
     */
    public static function sanitize(string $raw): string
    {
        $escaped = htmlspecialchars($raw, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        foreach (self::TAG_SPECS as $tag => $spec) {
            $escaped = self::convertPairedTag($escaped, $tag, $spec);
        }

        $escaped = self::convertImg($escaped);
        $escaped = self::convertBr($escaped);

        return $escaped;
    }

    /**
     * sanitize() plus newline-to-<br> conversion, for direct use at render
     * sites. The explicit `false` keeps every line break — typed <br> or
     * newline-derived — a consistently bare <br>, never XHTML-style <br />.
     */
    public static function toDisplayHtml(string $raw): string
    {
        return nl2br(self::sanitize($raw), false);
    }

    /**
     * Finds the offset of a tag's real closing `&gt;` starting at $pos — the
     * first one NOT inside a &quot;...&quot; span — or null if none exists
     * before the string ends. Linear scan, no backtracking, so no
     * regex-engine blowup is possible regardless of how malformed or
     * quote-heavy the input is.
     */
    private static function findTagEnd(string $escaped, int $pos): ?int
    {
        $len = strlen($escaped);
        $inQuote = false;
        while ($pos < $len) {
            if (!$inQuote && substr_compare($escaped, '&gt;', $pos, 4) === 0) {
                return $pos;
            }
            if (substr_compare($escaped, '&quot;', $pos, 6) === 0) {
                $inQuote = !$inQuote;
                $pos += 6;
                continue;
            }
            $pos++;
        }
        return null;
    }

    /**
     * Converts every well-formed occurrence of one paired tag (opening +
     * inner content + closing) atomically — opening and closing are only
     * ever emitted together, from the same successful match. Nested
     * instances of the SAME tag are not depth-matched: an opening pairs with
     * the first later closing literal for that tag, so same-tag nesting
     * (e.g. `<b>outer <b>inner</b> tail</b>`) can render partially inert —
     * a deliberate scope limit, not a bug; a real HTML parser would need
     * stack-aware pairing to nest the same tag correctly, which this
     * regex-plus-scanner approach doesn't attempt. Different tags nest
     * correctly, since each tag gets its own independent pass over the
     * whole string.
     */
    private static function convertPairedTag(string $escaped, string $tag, array $spec): string
    {
        $namePattern    = '/&lt;' . preg_quote($tag, '/') . '(?=&gt;|\s|\/)/i';
        $closingPattern = '/&lt;\/' . preg_quote($tag, '/') . '(?=&gt;)&gt;/i';
        $result = '';
        $cursor = 0;

        while (preg_match($namePattern, $escaped, $m, PREG_OFFSET_CAPTURE, $cursor)) {
            $matchStart = $m[0][1];
            $afterName  = $matchStart + strlen($m[0][0]);
            $result    .= substr($escaped, $cursor, $matchStart - $cursor);

            $openEnd = self::findTagEnd($escaped, $afterName);
            if ($openEnd === null) {
                // No real end found anywhere in the rest of the string for
                // this opening attempt - leave just the tag-name portion
                // inert and keep scanning. There is no separate closing pass
                // that could later, independently rediscover an unrelated
                // </tag> sitting inside this abandoned span.
                $result .= substr($escaped, $matchStart, $afterName - $matchStart);
                $cursor  = $afterName;
                continue;
            }

            $blob = substr($escaped, $afterName, $openEnd - $afterName);
            $rest = substr($escaped, $openEnd + 4); // +4 = strlen('&gt;')

            if (!preg_match($closingPattern, $rest, $cm, PREG_OFFSET_CAPTURE)) {
                // Well-formed opening, but no matching closing tag anywhere
                // after it - leave the whole opening inert. Never invent a
                // closing tag.
                $result .= substr($escaped, $matchStart, $openEnd + 4 - $matchStart);
                $cursor  = $openEnd + 4;
                continue;
            }

            $innerContent = substr($rest, 0, $cm[0][1]);
            $afterClosing = $openEnd + 4 + $cm[0][1] + strlen($cm[0][0]);

            $rebuilt = self::rebuildOpeningTag($tag, $spec, $blob);
            $result .= $rebuilt !== null
                ? $rebuilt . $innerContent . '</' . $tag . '>'
                : substr($escaped, $matchStart, $afterClosing - $matchStart); // whole pair stays inert together
            $cursor = $afterClosing;
        }

        $result .= substr($escaped, $cursor);
        return $result;
    }

    /**
     * Extracts recognized attributes from an already-delimited attribute
     * blob and rebuilds the opening tag, or returns null when a required
     * attribute is missing/invalid (caller keeps the original text inert).
     */
    private static function rebuildOpeningTag(string $tag, array $spec, string $blob): ?string
    {
        preg_match_all(
            '/([a-zA-Z][a-zA-Z0-9:_-]*)\s*=\s*&quot;((?:(?!&quot;).){0,2000})&quot;/',
            $blob,
            $pairs,
            PREG_SET_ORDER,
        );

        $attrs = [];
        foreach ($pairs as $p) {
            $attrs[strtolower($p[1])] = $p[2]; // normalize name case; last occurrence wins
        }

        foreach ($spec['required'] as $reqName) {
            if (!isset($attrs[$reqName]) || !self::isValidHttpUrl(html_entity_decode($attrs[$reqName], ENT_QUOTES | ENT_HTML5))) {
                return null;
            }
        }

        $out = '<' . $tag;
        foreach ($spec['required'] as $reqName) {
            $out .= ' ' . $reqName . '="' . $attrs[$reqName] . '"';
        }
        if ($tag === 'a') {
            $out .= ' target="_blank" rel="noopener noreferrer"';
        }
        if (isset($attrs['class'])) {
            $filtered = self::filterClassTokens($attrs['class']);
            if ($filtered !== '') {
                $out .= ' class="' . $filtered . '"';
            }
        }
        if (isset($attrs['title'])) {
            $out .= ' title="' . $attrs['title'] . '"';
        }
        return $out . '>';
    }

    /**
     * <img> — self-contained, self-closing aware. Void element, no closing
     * tag, so no orphaned-tag risk. Uses the identical findTagEnd() scan as
     * every paired tag; a trailing self-close slash needs no special
     * handling, the scanner just walks past it. fl-newsfeed-img is always
     * force-injected into class (see public/assets/css/default/style.css),
     * alongside any admin-supplied class.
     */
    private static function convertImg(string $escaped): string
    {
        $namePattern = '/&lt;img(?=&gt;|\s|\/)/i';
        $result = '';
        $cursor = 0;

        while (preg_match($namePattern, $escaped, $m, PREG_OFFSET_CAPTURE, $cursor)) {
            $matchStart = $m[0][1];
            $afterName  = $matchStart + strlen($m[0][0]);
            $result    .= substr($escaped, $cursor, $matchStart - $cursor);

            $end = self::findTagEnd($escaped, $afterName);
            if ($end === null) {
                $result .= substr($escaped, $matchStart, $afterName - $matchStart);
                $cursor  = $afterName;
                continue;
            }

            $blob    = substr($escaped, $afterName, $end - $afterName);
            $rebuilt = self::rebuildImgTag($blob);
            $result .= $rebuilt ?? substr($escaped, $matchStart, $end + 4 - $matchStart);
            $cursor  = $end + 4;
        }

        $result .= substr($escaped, $cursor);
        return $result;
    }

    private static function rebuildImgTag(string $blob): ?string
    {
        preg_match_all(
            '/([a-zA-Z][a-zA-Z0-9:_-]*)\s*=\s*&quot;((?:(?!&quot;).){0,2000})&quot;/',
            $blob,
            $pairs,
            PREG_SET_ORDER,
        );

        $attrs = [];
        foreach ($pairs as $p) {
            $attrs[strtolower($p[1])] = $p[2];
        }

        if (!isset($attrs['src']) || !self::isValidHttpUrl(html_entity_decode($attrs['src'], ENT_QUOTES | ENT_HTML5))) {
            return null;
        }

        $classTokens = 'fl-newsfeed-img';
        if (isset($attrs['class'])) {
            $filtered = self::filterClassTokens($attrs['class']);
            if ($filtered !== '') {
                $classTokens .= ' ' . $filtered;
            }
        }

        $out = '<img src="' . $attrs['src'] . '" class="' . $classTokens . '"';
        if (isset($attrs['alt'])) {
            $out .= ' alt="' . $attrs['alt'] . '"';
        }
        if (isset($attrs['title'])) {
            $out .= ' title="' . $attrs['title'] . '"';
        }
        return $out . '>';
    }

    /**
     * <br> — structurally permissive, semantically always bare. Same
     * name+boundary regex and findTagEnd() scan as every other tag, but no
     * extraction/rebuild step: whatever's between the tag name and its real
     * end is discarded, and the replacement is always the literal <br>.
     */
    private static function convertBr(string $escaped): string
    {
        $namePattern = '/&lt;br(?=&gt;|\s|\/)/i';
        $result = '';
        $cursor = 0;

        while (preg_match($namePattern, $escaped, $m, PREG_OFFSET_CAPTURE, $cursor)) {
            $matchStart = $m[0][1];
            $afterName  = $matchStart + strlen($m[0][0]);
            $result    .= substr($escaped, $cursor, $matchStart - $cursor);

            $end = self::findTagEnd($escaped, $afterName);
            if ($end === null) {
                $result .= substr($escaped, $matchStart, $afterName - $matchStart);
                $cursor  = $afterName;
                continue;
            }

            $result .= '<br>';
            $cursor  = $end + 4;
        }

        $result .= substr($escaped, $cursor);
        return $result;
    }

    /**
     * Splits an escaped class value on whitespace, keeps only namespaced
     * tokens, rejoins survivors with a single space. Returns '' when every
     * token is dropped (caller omits the attribute entirely rather than
     * emitting class="").
     */
    private static function filterClassTokens(string $value): string
    {
        $tokens = preg_split('/\s+/', trim($value), -1, PREG_SPLIT_NO_EMPTY);
        if ($tokens === false) {
            return '';
        }

        $kept = array_filter(
            $tokens,
            static fn(string $t): bool => preg_match(self::CLASS_TOKEN_PATTERN, $t) === 1,
        );

        return implode(' ', $kept);
    }

    /**
     * Reuses the same http/https-only validation rule already established
     * in ValidatorField::httpUrl() (src/Core/ValidatorField.php).
     */
    private static function isValidHttpUrl(string $url): bool
    {
        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            return false;
        }

        // parse_url() preserves the scheme's original case (unlike a browser,
        // which treats URI schemes case-insensitively per RFC 3986) - without
        // strtolower() here, "HTTP://..." fails this check even though it's a
        // perfectly valid URL, diverging from the JS preview mirror
        // (newsfeedIsValidHttpUrl() in admin.js), which already lowercases.
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        return in_array($scheme, ['http', 'https'], true);
    }
}
