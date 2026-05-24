<?php

declare(strict_types=1);

if (!function_exists('lusterAdminCssFiles')) {
    /**
     * Returns the ordered list of admin CSS filenames to load.
     *
     * tokens.css            — fonts, :root variables, [data-theme] and bundled
     *                          [data-skin] blocks. Always first.
     * skins/{slug}.css      — community skin only (if $selectedSkin names a
     *                          community drop-in, not a bundled skin). Loaded after
     *                          tokens.css so community overrides win at equal
     *                          specificity, and before admin.css so component rules
     *                          see the correct token values.
     * admin.css             — all layout, component, form, and feature rules.
     *
     * Bundled-skin detection uses SkinCatalog when the autoloader is available
     * (admin, auth, and migrate pages). On the install page, which is truly
     * standalone and does not load vendor/autoload.php, class_exists() returns
     * false and the inline slug list acts as a safety-net fallback.
     *
     * @param  string        $selectedSkin  Active skin slug, or '' for Velvet Geode default.
     * @return list<string>
     */
    function lusterAdminCssFiles(string $selectedSkin = ''): array
    {
        $files = ['tokens.css'];

        if ($selectedSkin !== '' && preg_match('/^[a-z0-9][a-z0-9-]*$/', $selectedSkin)) {
            // Allow autoloading so admin pages always use the live SkinCatalog.
            // On pages without an autoloader (install), class_exists() returns false
            // and the inline fallback list handles the check safely.
            $catalogClass = 'Luster\Core\SkinCatalog';
            $isBundled = class_exists($catalogClass)
                ? $catalogClass::isBundled($selectedSkin)
                : in_array($selectedSkin, ['prism-power', 'victory-road', 'the-force', 'floating-lights', 'moving-castle'], true);

            if (!$isBundled) {
                $files[] = 'skins/' . $selectedSkin . '.css';
            }
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
     * @param string $selectedSkin Active skin slug ('' = default Velvet Geode).
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
