<?php

declare(strict_types=1);

/** Slugs that ship inside tokens.css — their CSS is already bundled. */
if (!defined('LUSTER_BUNDLED_SKINS')) {
    define('LUSTER_BUNDLED_SKINS', ['aurora', 'sol']);
}

if (!function_exists('lusterAdminCssFiles')) {
    /**
     * Returns the ordered list of admin CSS filenames to load.
     *
     * tokens.css            — fonts, :root variables, [data-theme] and bundled
     *                          [data-skin] blocks. Always first.
     * skins/{slug}.css      — community skin (if $selectedSkin is a community file,
     *                          not one of the bundled skins). Loaded after tokens.css
     *                          so community token overrides can win over bundled ones
     *                          sharing the same specificity, and before admin.css so
     *                          component rules see the correct token values.
     * admin.css             — all layout, component, form, and feature rules.
     *
     * @param  string        $selectedSkin  Active skin slug, or '' for default Nebula.
     * @return list<string>
     */
    function lusterAdminCssFiles(string $selectedSkin = ''): array
    {
        $files = ['tokens.css'];

        // Only inject the extra link for community (drop-in) skins.
        // Bundled skins (aurora, sol) live inside tokens.css already.
        if (
            $selectedSkin !== ''
            && !in_array($selectedSkin, LUSTER_BUNDLED_SKINS, true)
            && preg_match('/^[a-z0-9][a-z0-9-]*$/', $selectedSkin)
        ) {
            $files[] = 'skins/' . $selectedSkin . '.css';
        }

        $files[] = 'admin.css';
        return $files;
    }
}

if (!function_exists('lusterAdminCssLinks')) {
    /**
     * Builds <link> tags for the admin CSS files with cache-busting ?v= timestamps.
     *
     * @param string $hrefBase     URL path to the css/ directory, no trailing slash.
     *                             e.g. '/luster/public/assets/css'  (admin layout)
     *                             e.g. '../assets/css'               (migrate page)
     * @param string $diskBase     Absolute filesystem path to the same directory.
     *                             Used only for filemtime(); never exposed to the browser.
     * @param string $selectedSkin Active skin slug ('' = default Nebula).
     *                             Passed through to lusterAdminCssFiles().
     */
    function lusterAdminCssLinks(string $hrefBase, string $diskBase, string $selectedSkin = ''): string
    {
        $hrefBase = rtrim($hrefBase, '/');
        $diskBase = rtrim($diskBase, DIRECTORY_SEPARATOR);
        $tags     = [];

        foreach (lusterAdminCssFiles($selectedSkin) as $file) {
            $diskPath = $diskBase . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $file);
            $version  = is_file($diskPath) ? (string) (filemtime($diskPath) ?: 0) : '0';
            $href     = htmlspecialchars(
                $hrefBase . '/' . $file . '?v=' . $version,
                ENT_QUOTES | ENT_SUBSTITUTE,
                'UTF-8'
            );
            $tags[] = '<link rel="stylesheet" href="' . $href . '">';
        }

        return implode(PHP_EOL . '  ', $tags);
    }
}
