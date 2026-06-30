<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Resolves a writable directory for PHP session storage. Restrictive shared
 hosting sometimes ships a session.save_path that is not writable by the
 web user; this resolver tries the configured/default path first, then
 falls back to the system temp directory, then to a Luster-controlled
 storage/sessions/ directory as a last resort. Used by Session::start().
*/

declare(strict_types=1);

namespace Luster\Core;

/**
 * Resolves a writable session save path, falling back through a chain of
 * candidates when the PHP-configured default is not writable.
 */
final class SessionPathResolver
{
    public function __construct(private readonly string $lusterBase) {}

    /**
     * Returns the resolved writable path and whether a fallback was needed.
     *
     * @return array{path: string, usedFallback: bool, probeOk: bool}
     */
    public function resolve(): array
    {
        $configured = $this->normalizeConfiguredPath((string) ini_get('session.save_path'));

        if ($configured !== '' && $this->isWritable($configured)) {
            return ['path' => $configured, 'usedFallback' => false, 'probeOk' => true];
        }

        $temp = sys_get_temp_dir();
        if ($temp !== '' && $this->isWritable($temp)) {
            return ['path' => $temp, 'usedFallback' => true, 'probeOk' => true];
        }

        $fallback = $this->prepareStorageFallback();
        return ['path' => $fallback, 'usedFallback' => true, 'probeOk' => $this->isWritable($fallback)];
    }

    /**
     * PHP's session.save_path may be a "N;/path" multi-level format (rare,
     * legacy). Take the actual path segment, which is always last.
     */
    private function normalizeConfiguredPath(string $configured): string
    {
        $configured = trim($configured);
        if ($configured === '' || !str_contains($configured, ';')) {
            return $configured;
        }

        $parts = explode(';', $configured);
        return trim((string) end($parts));
    }

    /**
     * Confirms $path is actually writable by creating and deleting a random
     * probe file, since is_writable() can be wrong on some filesystems
     * (NFS mounts, certain Windows ACL configurations, open_basedir quirks).
     */
    private function isWritable(string $path): bool
    {
        if ($path === '' || !is_dir($path)) {
            return false;
        }

        $probe = rtrim($path, '/\\') . '/.lstr_probe_' . bin2hex(random_bytes(6));
        if (@file_put_contents($probe, '1') === false) {
            return false;
        }

        @unlink($probe);
        return true;
    }

    /**
     * Prepares storage/sessions/ as a last-resort Luster-controlled fallback
     * and returns its path. Creates the directory if it does not exist yet.
     */
    private function prepareStorageFallback(): string
    {
        $dir = rtrim($this->lusterBase, '/\\') . '/storage/sessions';

        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        return $dir;
    }
}
