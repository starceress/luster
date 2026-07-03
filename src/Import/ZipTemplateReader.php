<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Reads template files from a Fanatically installation/site-backup ZIP archive.
 All security constraints are enforced here before any content reaches the
 translator or the database: path traversal, symlinks, encryption, size caps,
 entry count caps, binary/NUL content, and invalid UTF-8 are all rejected.

 Never executes any archive content. Never writes to disk.
*/

declare(strict_types=1);

namespace Luster\Import;

use ZipArchive;

/**
 * Reads Fanatically template entries from a ZIP archive.
 *
 * Accepted entry paths (relative to detected root):
 *   templates/display/<basename>.php  → category 'display'
 *   templates/email/<basename>.txt    → category 'email'
 *   admin/default-templates/defaults.json  → not returned as an entry; populates defaultHashes
 *
 * Per-listing entries:
 *   <folder>/templates/<known-display-basename>.php → category 'per-listing'
 *   where <known-display-basename> is one of the known display template basenames.
 *
 * Returns value objects; never writes anything.
 */
final class ZipTemplateReader
{
    /** Maximum number of ZIP entries examined before the archive is rejected. */
    public const MAX_ENTRIES = 250;

    /** Maximum decompressed bytes for a single entry. */
    public const MAX_ENTRY_BYTES = 524_288; // 512 KB

    /** Maximum total decompressed bytes across all accepted entries. */
    public const MAX_TOTAL_BYTES = 10_485_760; // 10 MB

    /** Known display template basenames that are candidates for per-listing overrides. */
    private const KNOWN_DISPLAY_BASENAMES = [
        'stats.php', 'members.php', 'join.php', 'update.php', 'lostpass.php',
        'affiliates.php', 'codes.php', 'collective-stats.php', 'owned-listings.php',
        'owned-listing-item.php', 'joined.php', 'affiliate-item.php',
        'wishlist.php', 'wishlist-item.php', 'kim.php', 'kim-item.php',
        'adopt.php', 'rules.php', 'delete.php', 'head.php',
    ];

    /** @var array<string, string> basename.ext → sha256 from defaults.json (may be empty) */
    private array $defaultHashes = [];

    /** @var list<array{sourcePath: string, category: string, content: string, sha256: string}> */
    private array $entries = [];

    /** @var list<array{path: string, reason: string}> */
    private array $rejected = [];

    /** @var string|null */
    private ?string $parseError = null;

    /**
     * Parses a ZIP file and populates entries, rejected, and defaultHashes.
     *
     * @return bool  True when the archive was opened without a fatal error.
     *               Entry-level rejections are non-fatal (see getRejected()).
     */
    public function read(string $zipPath): bool
    {
        $this->entries      = [];
        $this->rejected     = [];
        $this->defaultHashes = [];
        $this->parseError   = null;

        $zip = new ZipArchive();
        $result = $zip->open($zipPath);
        if ($result !== true) {
            $this->parseError = 'Could not open ZIP (error code ' . $result . ').';
            return false;
        }

        $count = $zip->count();
        if ($count > self::MAX_ENTRIES) {
            $zip->close();
            $this->parseError = 'Archive has ' . $count . ' entries, which exceeds the limit of ' . self::MAX_ENTRIES . '.';
            return false;
        }

        // Preflight: collect all entry names; detect and validate root wrapper.
        $allNames = [];
        for ($i = 0; $i < $count; $i++) {
            $name = $zip->getNameIndex($i);
            if ($name === false) {
                continue;
            }
            $allNames[] = [$i, $name];
        }

        $root = $this->detectRoot($allNames);
        if ($root === false) {
            $zip->close();
            $this->parseError = 'Archive contains multiple plausible Fanatically root directories; cannot resolve unambiguously.';
            return false;
        }

        // Validate the root wrapper directory name before it is stripped from any path.
        // An attacker could craft ../ as the root, stripping it would hide the traversal.
        if ($root !== '') {
            if (
                str_starts_with($root, '.')
                || str_contains($root, '/')
                || str_contains($root, '\\')
                || str_contains($root, "\0")
            ) {
                $zip->close();
                $this->parseError = 'Archive root directory name is unsafe.';
                return false;
            }
        }

        // Preflight declared sizes to catch zip-bomb declarations before reading content.
        $totalDeclared = 0;
        for ($i = 0; $i < $count; $i++) {
            $stat = $zip->statIndex($i);
            if ($stat !== false) {
                $totalDeclared += $stat['size'];
                if ($totalDeclared > self::MAX_TOTAL_BYTES * 2) {
                    $zip->close();
                    $this->parseError = 'Archive declared size exceeds the 10 MB total limit before decompression.';
                    return false;
                }
            }
        }

        $totalActualBytes  = 0;
        $seenNormalized    = [];
        $defaultsJsonRaw   = null;

        for ($i = 0; $i < $count; $i++) {
            $stat = $zip->statIndex($i);
            if ($stat === false) {
                continue;
            }

            $rawName = $stat['name'];

            // Strip the root prefix when one was detected.
            $normalized = ($root !== '' && str_starts_with($rawName, $root . '/'))
                ? substr($rawName, strlen($root) + 1)
                : $rawName;

            // Skip directory entries.
            if ($normalized === '' || str_ends_with($normalized, '/')) {
                continue;
            }

            // --- Security: path traversal and dangerous component checks ---
            if (!$this->isPathSafe($normalized, $rawName)) {
                // Rejection recorded inside isPathSafe.
                continue;
            }

            // --- Security: symlink detection via external attributes ---
            $osAttr = 0;
            $extAttr = 0;
            $zip->getExternalAttributesIndex($i, $osAttr, $extAttr);
            if ($osAttr === ZipArchive::OPSYS_UNIX) {
                $fileType = ($extAttr >> 16) & 0xF000;
                if ($fileType === 0xA000) { // S_IFLNK = 0xa000
                    $this->rejected[] = ['path' => $rawName, 'reason' => 'Symlink entries are not accepted.'];
                    continue;
                }
            }

            // --- Security: encryption detection ---
            // ZipArchive::EM_AES_256 (and others) indicate an encrypted entry.
            // Use statIndex's 'encryption_method' field when available; otherwise fall back to ZipArchive constants.
            $isEncrypted = false;
            if (isset($stat['encryption_method']) && $stat['encryption_method'] !== 0) {
                $isEncrypted = true;
            } elseif (defined('ZipArchive::EM_AES_256') && method_exists($zip, 'isEncryptionMethod')) {
                /** @phpstan-ignore-next-line */
                $isEncrypted = $zip->isEncryptionMethod($i, ZipArchive::EM_AES_256);
            }
            if ($isEncrypted) {
                $this->rejected[] = ['path' => $rawName, 'reason' => 'Encrypted entries are not accepted.'];
                continue;
            }

            // Classify the normalized path.
            $category = $this->classify($normalized);
            $isDefaultsJson = ($normalized === 'admin/default-templates/defaults.json');

            if ($category === null && !$isDefaultsJson) {
                // Not a path we care about — skip silently.
                continue;
            }

            // Duplicate normalized-path check (case-insensitive to catch case-collision attacks).
            $lowerNorm = strtolower($normalized);
            if (isset($seenNormalized[$lowerNorm])) {
                $this->rejected[] = ['path' => $rawName, 'reason' => 'Duplicate or case-colliding path.'];
                continue;
            }
            $seenNormalized[$lowerNorm] = true;

            // Preflight per-entry declared size before streaming.
            $declaredSize = $stat['size'];
            if ($declaredSize > self::MAX_ENTRY_BYTES) {
                $this->rejected[] = ['path' => $rawName, 'reason' => 'Entry exceeds the 512 KB size limit (declared size).'];
                continue;
            }

            // Read via stream in fixed chunks, capping on actual bytes.
            $content = $this->readEntryStreamed($zip, $rawName, $totalActualBytes);
            if ($content === null) {
                // Rejection was recorded inside readEntryStreamed.
                continue;
            }

            $totalActualBytes += strlen($content);
            if ($totalActualBytes > self::MAX_TOTAL_BYTES) {
                // Record a rejection and stop reading further entries.
                $this->rejected[] = ['path' => $rawName, 'reason' => 'Total archive size exceeded 10 MB limit.'];
                break;
            }

            // Validate text content: reject binary, NUL bytes, and invalid UTF-8.
            if (!$this->isValidTextContent($content)) {
                $this->rejected[] = ['path' => $rawName, 'reason' => 'Entry contains binary, NUL bytes, or invalid UTF-8.'];
                continue;
            }

            if ($isDefaultsJson) {
                $defaultsJsonRaw = $content;
                continue;
            }

            $this->entries[] = [
                'sourcePath' => $normalized,
                'category'   => $category,
                'content'    => $content,
                'sha256'     => hash('sha256', $content),
            ];
        }

        $zip->close();

        if ($defaultsJsonRaw !== null) {
            $this->defaultHashes = $this->parseDefaultsJson($defaultsJsonRaw);
        }

        return true;
    }

    /**
     * Returns entries parsed from the archive.
     *
     * @return list<array{sourcePath: string, category: string, content: string, sha256: string}>
     */
    public function getEntries(): array
    {
        return $this->entries;
    }

    /**
     * Returns entries that were rejected during parsing, with reasons.
     *
     * @return list<array{path: string, reason: string}>
     */
    public function getRejected(): array
    {
        return $this->rejected;
    }

    /**
     * Returns the hash map from defaults.json (may be empty if no defaults.json was found).
     *
     * @return array<string, string>  'display/stats.php' => sha256hex
     */
    public function getDefaultHashes(): array
    {
        return $this->defaultHashes;
    }

    /**
     * Returns the fatal parse error when read() returned false, or null on success.
     */
    public function getParseError(): ?string
    {
        return $this->parseError;
    }

    /**
     * Compares each entry's sha256 against the defaults.json hash map.
     * Maps the ZIP storage path to the defaults.json key by stripping the
     * `templates/` prefix (e.g. `templates/display/stats.php` → `display/stats.php`).
     *
     * @return array<string, string>  sourcePath → 'customized'|'matches_default'|'no_defaults_file'
     */
    public function compareToDefaults(): array
    {
        $result = [];
        $noFile = empty($this->defaultHashes);

        foreach ($this->entries as $entry) {
            if ($noFile) {
                $result[$entry['sourcePath']] = 'no_defaults_file';
                continue;
            }

            // Map storage path to defaults.json key.
            // ZIP path: templates/display/stats.php → key: display/stats.php
            // Per-listing: <folder>/templates/stats.php → key: display/stats.php
            $key = $this->toDefaultsKey($entry['sourcePath'], $entry['category']);

            if ($key !== null && isset($this->defaultHashes[$key]) && hash_equals($this->defaultHashes[$key], $entry['sha256'])) {
                $result[$entry['sourcePath']] = 'matches_default';
            } else {
                $result[$entry['sourcePath']] = 'customized';
            }
        }

        return $result;
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Detects and validates the optional single top-level wrapper directory.
     *
     * Returns '' when there is no wrapper (paths are at the root), a non-empty
     * string with the wrapper directory name when exactly one plausible root
     * is detected, or false when multiple plausible roots exist.
     *
     * @param list<array{int, string}> $allNames  [$index, $rawName] pairs
     * @return string|false
     */
    private function detectRoot(array $allNames): string|false
    {
        $candidates = [];
        foreach ($allNames as [, $name]) {
            if (str_contains($name, 'templates/display/') || str_contains($name, 'admin/default-templates/')) {
                $parts = explode('/', $name, 2);
                $first = $parts[0];
                if (!str_ends_with($name, '/') && str_contains($name, '/')) {
                    // Check if this looks like a wrapper: first segment, rest has the expected sub-paths.
                    $rest = $parts[1] ?? '';
                    if (str_starts_with($rest, 'templates/') || str_starts_with($rest, 'admin/')) {
                        $candidates[$first] = true;
                    } else {
                        // No wrapper — paths at root level.
                        $candidates[''] = true;
                    }
                }
            }
        }

        if (count($candidates) === 0) {
            return ''; // No template-matching entries at all — fine, we'll just find nothing.
        }

        $keys = array_keys($candidates);
        if (count($keys) > 1) {
            return false; // Multiple roots detected.
        }

        return $keys[0];
    }

    /**
     * Validates a normalized entry path for safety.
     * Records a rejection and returns false on any violation.
     */
    private function isPathSafe(string $normalized, string $rawName): bool
    {
        // Absolute paths.
        if (str_starts_with($normalized, '/') || preg_match('/^[A-Za-z]:/', $normalized)) {
            $this->rejected[] = ['path' => $rawName, 'reason' => 'Absolute path rejected.'];
            return false;
        }

        // Split into components and check each.
        $components = explode('/', $normalized);
        foreach ($components as $part) {
            if ($part === '' || $part === '.' || $part === '..') {
                $this->rejected[] = ['path' => $rawName, 'reason' => 'Path traversal component detected.'];
                return false;
            }
            if (str_starts_with($part, '.')) {
                $this->rejected[] = ['path' => $rawName, 'reason' => 'Hidden component (starts with dot) rejected.'];
                return false;
            }
        }

        // Backslash traversal.
        if (str_contains($normalized, '\\')) {
            $this->rejected[] = ['path' => $rawName, 'reason' => 'Backslash in path rejected.'];
            return false;
        }

        // NUL bytes in path.
        if (str_contains($normalized, "\0")) {
            $this->rejected[] = ['path' => $rawName, 'reason' => 'NUL byte in path rejected.'];
            return false;
        }

        return true;
    }

    /**
     * Classifies a normalized entry path into a category, or returns null if not accepted.
     *
     * Accepted:
     *   templates/display/<known-basename>.php  → 'display'
     *   templates/email/<basename>.txt          → 'email'
     *   <folder>/templates/<known-basename>.php → 'per-listing'
     *
     * admin/default-templates/defaults.json is handled separately by the caller.
     */
    private function classify(string $normalized): ?string
    {
        // Global display template.
        if (preg_match('#^templates/display/([^/]+\.php)$#', $normalized, $m)) {
            if (in_array($m[1], self::KNOWN_DISPLAY_BASENAMES, true)) {
                return 'display';
            }
            return null;
        }

        // Global email template.
        if (preg_match('#^templates/email/[^/]+\.txt$#', $normalized)) {
            return 'email';
        }

        // Per-listing override: exactly one folder level then /templates/<known-basename>.php.
        // Must not match the global templates/ path itself (guarded by requiring a folder prefix).
        if (preg_match('#^([^/]+)/templates/([^/]+\.php)$#', $normalized, $m)) {
            $folder   = $m[1];
            $basename = $m[2];
            // Reject if the folder segment IS "templates" (would be templates/templates/...)
            if ($folder !== 'templates' && in_array($basename, self::KNOWN_DISPLAY_BASENAMES, true)) {
                return 'per-listing';
            }
        }

        return null;
    }

    /**
     * Reads a ZIP entry via stream in fixed-size chunks, stopping when MAX_ENTRY_BYTES is reached.
     * Returns the content string, or null when the read fails or the cap is exceeded.
     * Records rejection on null return.
     *
     * @param int &$totalActualBytes  Running total updated by the caller after success.
     */
    private function readEntryStreamed(ZipArchive $zip, string $rawName, int $totalActualBytes): ?string
    {
        // getStreamIndex() only exists on PHP 8.2+; getStream() by name works on 8.1
        // (Luster's documented minimum) and $rawName is already the exact entry name
        // for this index (from statIndex() in the caller), so no extra lookup is needed.
        $stream = $zip->getStream($rawName);
        if ($stream === false) {
            $this->rejected[] = ['path' => $rawName, 'reason' => 'Could not open entry stream.'];
            return null;
        }

        $content     = '';
        $bytesRead   = 0;
        $chunkSize   = 65536; // 64 KB

        while (!feof($stream)) {
            $chunk = fread($stream, $chunkSize);
            if ($chunk === false) {
                fclose($stream);
                $this->rejected[] = ['path' => $rawName, 'reason' => 'Read error on entry stream.'];
                return null;
            }
            $bytesRead += strlen($chunk);
            if ($bytesRead > self::MAX_ENTRY_BYTES) {
                fclose($stream);
                $this->rejected[] = ['path' => $rawName, 'reason' => 'Entry exceeds the 512 KB size limit (actual bytes).'];
                return null;
            }
            // Also enforce total cap during streaming.
            if ($totalActualBytes + $bytesRead > self::MAX_TOTAL_BYTES) {
                fclose($stream);
                $this->rejected[] = ['path' => $rawName, 'reason' => 'Total archive size exceeded 10 MB limit during streaming.'];
                return null;
            }
            $content .= $chunk;
        }

        fclose($stream);
        return $content;
    }

    /**
     * Returns true when content is valid UTF-8 text with no NUL bytes.
     * Rejects binary content and invalid UTF-8 sequences.
     */
    private function isValidTextContent(string $content): bool
    {
        if (str_contains($content, "\0")) {
            return false;
        }
        return mb_check_encoding($content, 'UTF-8');
    }

    /**
     * Parses defaults.json: validates it is a bounded key→64-char-SHA-256 map.
     * Returns only valid entries; silently skips malformed ones.
     *
     * @return array<string, string>
     */
    private function parseDefaultsJson(string $raw): array
    {
        if (strlen($raw) > 65536) { // 64 KB guard on the JSON itself
            return [];
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }

        $hashes = [];
        foreach ($decoded as $key => $hash) {
            if (!is_string($key) || !is_string($hash)) {
                continue;
            }
            // Key must be a relative path like display/stats.php or email/join.txt.
            if (!preg_match('#^(display|email)/[^/]+\.(php|txt|css)$#', $key)) {
                continue;
            }
            // Hash must be a 64-character lowercase hex SHA-256.
            if (!preg_match('/^[0-9a-f]{64}$/', $hash)) {
                continue;
            }
            $hashes[$key] = $hash;
        }

        return $hashes;
    }

    /**
     * Maps a ZIP entry's sourcePath + category to the defaults.json key format.
     *
     * ZIP path `templates/display/stats.php` → defaults key `display/stats.php`
     * ZIP path `templates/email/join.txt`    → defaults key `email/join.txt`
     * Per-listing `<folder>/templates/stats.php` → defaults key `display/stats.php`
     */
    private function toDefaultsKey(string $sourcePath, string $category): ?string
    {
        if ($category === 'display') {
            // Strip `templates/` prefix to get `display/<basename>.php`
            if (str_starts_with($sourcePath, 'templates/')) {
                return substr($sourcePath, strlen('templates/'));
            }
        } elseif ($category === 'email') {
            if (str_starts_with($sourcePath, 'templates/')) {
                return substr($sourcePath, strlen('templates/'));
            }
        } elseif ($category === 'per-listing') {
            // <folder>/templates/<basename>.php → display/<basename>.php
            if (preg_match('#/templates/([^/]+\.php)$#', $sourcePath, $m)) {
                return 'display/' . $m[1];
            }
        }

        return null;
    }
}
