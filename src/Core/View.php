<?php

declare(strict_types=1);

namespace Luster\Core;

use RuntimeException;

/**
 * Template renderer.
 *
 * Fan-facing templates (.html) use {token} substitution — no PHP allowed.
 * Admin templates (.php) use PHP includes with an extracted $data array.
 *
 * All values substituted into HTML templates are automatically escaped with
 * htmlspecialchars(). Admin PHP templates must call View::e() explicitly for
 * any variable output (the admin templates are in source control, not
 * user-supplied, but the rule still applies).
 *
 * Loop syntax for HTML templates:
 *   {member_loop}
 *     <div>{fan_name}</div>
 *   {/member_loop}
 *
 * The loop block is repeated once per item in the $data['member_loop'] array.
 * Each item's keys are available as tokens within the block.
 */
final class View
{
    public function __construct(private readonly string $templateDir) {}

    // -----------------------------------------------------------------------
    // Public API
    // -----------------------------------------------------------------------

    /**
     * Renders a template and returns the HTML string.
     *
     * @param string $template Relative path from the template directory,
     *                         e.g. 'default/join.html' or 'admin/dashboard.php'
     * @param array  $data     Token values (HTML templates) or extracted
     *                         variables (PHP templates).
     */
    public function render(string $template, array $data = []): string
    {
        $path = rtrim($this->templateDir, '/\\') . DIRECTORY_SEPARATOR . $template;

        if (!is_file($path)) {
            throw new RuntimeException("Template not found: {$path}");
        }

        if (str_ends_with($template, '.php')) {
            return $this->renderPhp($path, $data);
        }

        $content = file_get_contents($path);

        if ($content === false) {
            throw new RuntimeException("Could not read template: {$path}");
        }

        return $this->substituteTokens($content, $data);
    }

    /**
     * Escapes a string for safe HTML output.
     * Use in admin PHP templates: <?= View::e($value) ?>
     */
    public static function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    // -----------------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------------

    private function renderPhp(string $path, array $data): string
    {
        // Extract into local scope (EXTR_SKIP prevents overwriting $path/$data)
        extract($data, EXTR_SKIP);

        ob_start();

        try {
            require $path;
        } catch (\Throwable $e) {
            ob_end_clean();
            throw $e;
        }

        return (string) ob_get_clean();
    }

    private function substituteTokens(string $content, array $data): string
    {
        // Process loop blocks first: {loop_key}...{/loop_key}
        $content = preg_replace_callback(
            '/\{([a-z_][a-z0-9_]*)\}(.*?)\{\/\1\}/si',
            function (array $m) use ($data): string {
                $key   = $m[1];
                $block = $m[2];

                if (!isset($data[$key]) || !is_array($data[$key])) {
                    return '';
                }

                $output = '';

                foreach ($data[$key] as $item) {
                    if (!is_array($item)) {
                        continue;
                    }

                    $output .= $this->substituteTokens($block, $item);
                }

                return $output;
            },
            $content
        ) ?? $content;

        // Substitute tokens — ViewRaw values are inserted verbatim (pre-escaped
        // server-side HTML); all other scalar values are htmlspecialchars()-escaped.
        foreach ($data as $key => $value) {
            if ($value instanceof ViewRaw) {
                $content = str_replace('{' . $key . '}', $value->html, $content);
                continue;
            }

            if (!is_scalar($value) && $value !== null) {
                continue;
            }

            $escaped = htmlspecialchars(
                (string) ($value ?? ''),
                ENT_QUOTES | ENT_SUBSTITUTE,
                'UTF-8'
            );

            $content = str_replace('{' . $key . '}', $escaped, $content);
        }

        return $content;
    }
}
