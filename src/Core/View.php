<?php

declare(strict_types=1);

namespace Luster\Core;

use RuntimeException;

/**
 * Template renderer.
 *
 * Fan-facing public templates (.php) use PHP fragments wrapped by layout.php.
 * Admin templates (.php) use PHP includes with an extracted $data array.
 * Legacy HTML templates (.html) use {token} substitution — kept for backwards
 * compatibility but superseded by the PHP modular system.
 *
 * All values substituted into HTML templates are automatically escaped with
 * htmlspecialchars(). PHP templates must call View::e() explicitly for any
 * variable output.
 *
 * Public rendering pipeline:
 *   Controller → renderPublic('join.php', $data)
 *     → output-buffers the content fragment (extract + require)
 *     → output-buffers templates/default/layout.php with $content injected
 *     → returns complete HTML string → Response::html()
 *
 * Embed mode: use renderFragment() instead of renderPublic() to return only
 * the content fragment without the layout wrapper.
 */
final class View
{
    public function __construct(private readonly string $templateDir) {}

    /** Variables automatically merged into every renderPhp() call. */
    private array $shared = [];

    /**
     * Sets variables that are injected into every PHP template render.
     * Useful for values that every admin template needs (e.g. admin_date_format).
     * Explicit $data values passed to render() take precedence over shared ones.
     */
    public function share(array $vars): void
    {
        $this->shared = array_merge($this->shared, $vars);
    }

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
    // Public rendering (fan-facing PHP modular templates)
    // -----------------------------------------------------------------------

    /**
     * Renders a public content fragment wrapped in templates/default/layout.php.
     *
     * If layout.php does not exist, returns the fragment alone. This means
     * embed mode works before the site owner creates layout.php.
     *
     * @param string $template  Filename only, e.g. 'join.php' or 'members.php'
     * @param array  $data      Variables available in the content template and layout.
     */
    public function renderPublic(string $template, array $data = []): string
    {
        $content    = $this->renderFragment($template, $data);
        $layoutPath = rtrim($this->templateDir, '/\\')
                    . DIRECTORY_SEPARATOR . 'default'
                    . DIRECTORY_SEPARATOR . 'layout.php';

        if (!is_file($layoutPath)) {
            return $content;
        }

        return $this->renderPhp($layoutPath, array_merge($data, ['content' => $content]));
    }

    /**
     * Renders a public content fragment only — no layout.php wrapping.
     *
     * Used by the embed entry points (public/embed/*.php) where the site
     * owner's own page provides the outer HTML.
     *
     * @param string $template  Filename only, e.g. 'join.php'
     * @param array  $data      Variables available in the content template.
     */
    public function renderFragment(string $template, array $data = []): string
    {
        $path = rtrim($this->templateDir, '/\\')
              . DIRECTORY_SEPARATOR . 'default'
              . DIRECTORY_SEPARATOR . $template;

        if (!is_file($path)) {
            throw new RuntimeException("Public template not found: {$path}");
        }

        return $this->renderPhp($path, $data);
    }

    // -----------------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------------

    private function renderPhp(string $path, array $data): string
    {
        // Merge shared vars (set once via share()) with per-render data.
        // $data values take precedence over shared values.
        extract(array_merge($this->shared, $data), EXTR_SKIP);

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
