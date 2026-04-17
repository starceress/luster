<?php

declare(strict_types=1);

if (!function_exists('lusterAdminCssFiles')) {
    /**
     * @return list<string>
     */
    function lusterAdminCssFiles(?string $section = null, bool $authOnly = false): array
    {
        $files = ['base.css', 'layout.css', 'components.css', 'forms.css'];

        if ($authOnly) {
            return ['base.css', 'components.css', 'forms.css', 'auth.css'];
        }

        if (in_array($section, ['addons', 'import'], true)) {
            $files[] = 'import.css';
        }

        if (in_array($section, ['emails', 'members', 'kim'], true)) {
            $files[] = 'compose.css';
        }

        if ($section === 'listings') {
            $files[] = 'compose.css';
            $files[] = 'embed.css';
            $files[] = 'codes.css';
        }

        if ($section === 'embed-codes') {
            $files[] = 'embed.css';
        }
        return array_values(array_unique($files));
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
