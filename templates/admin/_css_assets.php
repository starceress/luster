<?php

declare(strict_types=1);

if (!function_exists('lusterAdminCssFiles')) {
    /**
     * @return list<string>
     */
    function lusterAdminCssFiles(?string $section = null, bool $authOnly = false): array
    {
        if ($authOnly) {
            return ['layout.css', 'components.css', 'forms.css'];
        }

        return ['layout.css', 'components.css', 'forms.css', 'features.css'];
    }
}

if (!function_exists('lusterAdminCssLinks')) {
    function lusterAdminCssLinks(string $hrefBase, string $diskBase, ?string $section = null, bool $authOnly = false): string
    {
        $hrefBase = rtrim($hrefBase, '/');
        $diskBase = rtrim($diskBase, DIRECTORY_SEPARATOR);
        $tags = [];

        foreach (lusterAdminCssFiles($section, $authOnly) as $file) {
            $diskPath = $diskBase . DIRECTORY_SEPARATOR . $file;
            $version = is_file($diskPath) ? (string) (filemtime($diskPath) ?: 0) : '0';
            $href = htmlspecialchars($hrefBase . '/' . $file . '?v=' . $version, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $tags[] = '<link rel="stylesheet" href="' . $href . '">';
        }

        return implode(PHP_EOL . '  ', $tags);
    }
}
