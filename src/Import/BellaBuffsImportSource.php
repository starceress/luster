<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Import adapter for BellaBuffs flat-file fanlisting installations. BellaBuffs
 stores all data in comma-separated text files (members.txt, newbies.txt,
 affiliates.txt, buttons.txt) rather than a database. This adapter parses those
 files, decodes BellaBuffs' email obfuscation tokens (ATTIE, DOTTY, etc.), and
 imports members, affiliates, and codes into Luster. Since there is no SQL
 source, the controller passes Luster's own PDO as both source and target.
*/

declare(strict_types=1);

namespace Luster\Import;

use PDO;
use RuntimeException;

/**
 * Import adapter for BellaBuffs flat-file fanlisting installations.
 *
 * BellaBuffs stores all data in comma-separated text files (members.txt,
 * newbies.txt, affiliates.txt, buttons.txt) rather than a SQL database. This
 * adapter parses those files, decodes BellaBuffs' email obfuscation tokens
 * (ATTIE → @, DOTTY → ., DASHY → -, etc.), and imports members, affiliates,
 * and fan graphic codes into Luster's tables. Because there is no SQL source,
 * the controller passes Luster's own PDO as both the $source and $target arguments
 * in preview() and run(); openConnection() always throws RuntimeException.
 */
final class BellaBuffsImportSource implements ImportSourceInterface
{
    // Longer/more-specific tokens must come before shorter ones that are substrings of them.
    // UNDERSCORE before SCORE (SCORE is a suffix of UNDERSCORE).
    // DASHY before DASH (DASH is a prefix of DASHY).
    private const EMAIL_SEARCH  = ['ATTIE', 'DOTTY', 'DASHY', 'UNDERSCORE', 'SCORE', '[at]', '[dot]', 'DASH'];
    private const EMAIL_REPLACE = ['@',     '.',     '-',     '_',          '_',     '@',    '.',     '-'    ];

    // -----------------------------------------------------------------------
    // ImportSourceInterface — identification and connection
    // -----------------------------------------------------------------------

    public function getName(): string
    {
        return 'BellaBuffs';
    }

    /**
     * Path mode: validates the folder contains a readable members.txt.
     * Upload mode ('bb_mode' => 'upload'): returns null immediately (files
     * were already saved to session by the controller).
     */
    public function testConnection(array $credentials): ?string
    {
        if (($credentials['bb_mode'] ?? '') === 'upload') {
            return null;
        }

        $path = rtrim(trim((string) ($credentials['bb_path'] ?? '')), '/\\');
        if ($path === '') {
            return 'BellaBuffs folder path is required.';
        }
        if (!is_dir($path)) {
            return 'Folder not found or not accessible: ' . $path;
        }
        $membersFile = $path . '/members.txt';
        if (!file_exists($membersFile)) {
            return 'members.txt not found in: ' . $path;
        }
        if (!is_readable($membersFile)) {
            return 'members.txt is not readable (check file permissions).';
        }
        return null;
    }

    /**
     * BellaBuffs has no SQL source database.
     * The controller bypasses this method for this source.
     */
    public function openConnection(array $credentials): PDO
    {
        throw new RuntimeException(
            'BellaBuffs imports are file-based and do not open a source database.'
        );
    }

    /**
     * Always returns 'bellabuffs_flatfile'. The PDO argument is ignored.
     */
    public function detectSchema(PDO $source): string
    {
        return 'bellabuffs_flatfile';
    }

    // -----------------------------------------------------------------------
    // Preview
    // -----------------------------------------------------------------------

    /**
     * Reads the BellaBuffs flat files and returns counts.
     *
     * BellaBuffs has no foreign source database — $source and $target are both
     * Luster's own PDO. The controller passes the target for both arguments.
     */
    public function preview(PDO $source, PDO $target, ImportOptions $options): ImportPreview
    {
        $targetPdo = $target;
        $rows      = [];

        if ($options->bbTargetListingId <= 0) {
            return new ImportPreview(
                [['type' => 'Members', 'found' => 0, 'import' => 0, 'skip' => 0,
                  'warnings' => ['No target listing selected.']]],
                'bellabuffs_flatfile',
                false
            );
        }

        try {
            [$fanlistingId, $dbtable] = $this->resolveTargetListing($targetPdo, $options->bbTargetListingId);
        } catch (RuntimeException $e) {
            return new ImportPreview(
                [['type' => 'Members', 'found' => 0, 'import' => 0, 'skip' => 0,
                  'warnings' => [$e->getMessage()]]],
                'bellabuffs_flatfile',
                false
            );
        }

        // --- Approved members
        if ($options->bbImportApproved && $options->bbMembersPath !== '') {
            $approved = $this->parseMemberFile($options->bbMembersPath);
            $dupes    = $this->countMemberDupes($targetPdo, $dbtable, $approved);
            $rows[]   = [
                'type'     => 'Members (approved)',
                'found'    => count($approved),
                'import'   => max(0, count($approved) - $dupes),
                'skip'     => $dupes,
                'warnings' => [],
            ];
        }

        // --- Pending members
        if ($options->bbImportPending && $options->bbNewbiesPath !== '') {
            $pending = $this->parseMemberFile($options->bbNewbiesPath);
            $dupes   = $this->countMemberDupes($targetPdo, $dbtable, $pending);
            $rows[]  = [
                'type'     => 'Members (pending)',
                'found'    => count($pending),
                'import'   => max(0, count($pending) - $dupes),
                'skip'     => $dupes,
                'warnings' => [],
            ];
        }

        // --- Affiliates
        if ($options->bbImportAffiliates) {
            $affPath = $options->bbAffiliatesPath;
            if ($affPath !== '' && is_readable($affPath)) {
                $affiliates = $this->parseAffiliateFile($affPath);
                $dupes      = $this->countAffiliateDupes($targetPdo, $fanlistingId, $affiliates);
                $affWarns   = [];
                if ($options->bbButtonsDir === '') {
                    $affWarns[] = 'buttons/ directory not found — affiliate images will not be copied.';
                }
                $rows[] = [
                    'type'     => 'Affiliates',
                    'found'    => count($affiliates),
                    'import'   => max(0, count($affiliates) - $dupes),
                    'skip'     => $dupes,
                    'warnings' => $affWarns,
                ];
            } else {
                $rows[] = [
                    'type'     => 'Affiliates',
                    'found'    => 0,
                    'import'   => 0,
                    'skip'     => 0,
                    'warnings' => ['affiliates.txt not found or not readable.'],
                ];
            }
        }

        // --- Codes / Buttons
        if ($options->bbImportCodes) {
            $btnPath = $options->bbButtonsPath;
            if ($btnPath !== '' && is_readable($btnPath)) {
                $buttons   = $this->parseButtonFile($btnPath);
                $dupes     = $this->countCodeDupes($targetPdo, $fanlistingId, $buttons);
                $codeWarns = [];
                if ($options->bbButtonsDir === '') {
                    $codeWarns[] = 'buttons/ directory not found — code images will not be copied.';
                }
                $rows[] = [
                    'type'     => 'Codes / Buttons',
                    'found'    => count($buttons),
                    'import'   => max(0, count($buttons) - $dupes),
                    'skip'     => $dupes,
                    'warnings' => $codeWarns,
                ];
            } else {
                $rows[] = [
                    'type'     => 'Codes / Buttons',
                    'found'    => 0,
                    'import'   => 0,
                    'skip'     => 0,
                    'warnings' => ['buttons.txt not found or not readable.'],
                ];
            }
        }

        if (empty($rows)) {
            $rows[] = [
                'type'     => 'Members',
                'found'    => 0,
                'import'   => 0,
                'skip'     => 0,
                'warnings' => ['No data types selected for import.'],
            ];
        }

        return new ImportPreview($rows, 'bellabuffs_flatfile', false);
    }

    // -----------------------------------------------------------------------
    // Run
    // -----------------------------------------------------------------------

    /**
     * Executes the import.
     *
     * IMPORTANT: $source is ignored. $target is the Luster PDO. Both are
     * the same connection, as set by the controller for this source.
     */
    public function run(PDO $source, PDO $target, ImportOptions $options): ImportResult
    {
        $startTime = microtime(true);
        $rows      = [];
        $imageRows = [];

        [$fanlistingId, $dbtable] = $this->resolveTargetListing($target, $options->bbTargetListingId);

        $migrator = $options->bbButtonsDir !== ''
            ? new ImageMigrator(dirname(__DIR__, 2) . '/public')
            : null;

        $today = date('Y-m-d');

        // --- Approved members
        if ($options->bbImportApproved && $options->bbMembersPath !== '') {
            $approved = $this->parseMemberFile($options->bbMembersPath);
            $rows[]   = $this->insertMembers($target, $dbtable, $approved, 0, $today);
        }

        // --- Pending members
        if ($options->bbImportPending && $options->bbNewbiesPath !== '') {
            $pending = $this->parseMemberFile($options->bbNewbiesPath);
            $rows[]  = $this->insertMembers($target, $dbtable, $pending, 1, $today);
        }

        // --- Affiliates
        if ($options->bbImportAffiliates && $options->bbAffiliatesPath !== '') {
            [$affRow, $affImageRow] = $this->insertAffiliates($target, $fanlistingId, $options, $migrator);
            $rows[] = $affRow;
            if ($affImageRow !== []) {
                $imageRows[] = $affImageRow;
            }
        }

        // --- Codes / Buttons
        if ($options->bbImportCodes && $options->bbButtonsPath !== '') {
            [$codeRow, $codeImageRow] = $this->insertCodes($target, $fanlistingId, $options, $migrator);
            $rows[] = $codeRow;
            if ($codeImageRow !== []) {
                $imageRows[] = $codeImageRow;
            }
        }

        if (empty($rows)) {
            $rows[] = [
                'type'          => 'Members',
                'imported'      => 0,
                'skipped'       => 0,
                'warnings'      => ['No data types were selected.'],
                'errors'        => [],
                'skipped_items' => [],
            ];
        }

        return new ImportResult(
            $rows,
            $imageRows,
            microtime(true) - $startTime,
            'bellabuffs_flatfile'
        );
    }

    // -----------------------------------------------------------------------
    // Member insertion
    // -----------------------------------------------------------------------

    /** @return array{type:string,imported:int,skipped:int,warnings:string[],errors:string[],skipped_items:array<array{label:string,reason:string}>} */
    private function insertMembers(
        PDO $pdo, string $dbtable, array $members, int $pending, string $today
    ): array {
        $imported     = 0;
        $skipped      = 0;
        $errors       = [];
        $skippedItems = [];
        $label        = $pending === 0 ? 'Members (approved)' : 'Members (pending)';

        foreach ($members as $m) {
            try {
                if ($m['email'] === '') {
                    $skipped++;
                    $skippedItems[] = ['label' => $m['name'] ?: '(blank)', 'reason' => 'Empty email after decoding'];
                    continue;
                }

                $check = $pdo->prepare("SELECT COUNT(*) FROM `{$dbtable}` WHERE email = :email");
                $check->execute(['email' => $m['email']]);
                if ((int) $check->fetchColumn() > 0) {
                    $skipped++;
                    $skippedItems[] = ['label' => $m['email'], 'reason' => 'Email already exists'];
                    continue;
                }

                $stmt = $pdo->prepare(
                    "INSERT INTO `{$dbtable}`
                     (email, name, url, country, pending, password, showemail, showurl, added)
                     VALUES (:email, :name, :url, :country, :pending, '', :showemail, :showurl, :added)"
                );
                $stmt->execute([
                    'email'     => $m['email'],
                    'name'      => $m['name'],
                    'url'       => $m['url'],
                    'country'   => $m['country'],
                    'pending'   => $pending,
                    'showemail' => $m['dispemail'] === 'yes' ? 1 : 0,
                    'showurl'   => $m['url'] !== '' ? 1 : 0,
                    'added'     => $today,
                ]);
                $imported++;
            } catch (\Throwable $e) {
                $errors[] = ($m['email'] ?: $m['name'] ?: '?') . ': ' . $e->getMessage();
            }
        }

        return [
            'type'          => $label,
            'imported'      => $imported,
            'skipped'       => $skipped,
            'warnings'      => [],
            'errors'        => $errors,
            'skipped_items' => $skippedItems,
        ];
    }

    // -----------------------------------------------------------------------
    // Affiliate insertion
    // -----------------------------------------------------------------------

    /** @return array{array,array} [data row, image row] */
    private function insertAffiliates(
        PDO $pdo, int $fanlistingId, ImportOptions $options, ?ImageMigrator $migrator
    ): array {
        $affiliates   = $this->parseAffiliateFile($options->bbAffiliatesPath);
        $imported     = 0;
        $skipped      = 0;
        $errors       = [];
        $skippedItems = [];
        $imgCopied    = 0;
        $imgFailed    = 0;
        $imgErrors    = [];

        $sortBase = (int) $pdo->query(
            'SELECT COALESCE(MAX(sort_order), 0) FROM lstr_affiliates WHERE fanlisting_id = ' . $fanlistingId
        )->fetchColumn();

        foreach ($affiliates as $aff) {
            try {
                $url = $aff['url'];
                if ($url === '') {
                    $skipped++;
                    $skippedItems[] = ['label' => $aff['sitename'] ?: '(blank)', 'reason' => 'Empty URL'];
                    continue;
                }

                $check = $pdo->prepare(
                    'SELECT id FROM lstr_affiliates WHERE fanlisting_id = :fid AND url = :url'
                );
                $check->execute(['fid' => $fanlistingId, 'url' => $url]);
                if ($check->fetchColumn() !== false) {
                    $skipped++;
                    $skippedItems[] = ['label' => $url, 'reason' => 'URL already exists for this listing'];
                    continue;
                }

                $image = null;
                if ($migrator !== null && $aff['button'] !== '' && $options->bbButtonsDir !== '') {
                    $srcPath = rtrim($options->bbButtonsDir, '/\\') . '/' . $aff['button'];
                    $newName = $migrator->copyFromPath($srcPath, 'affiliates', 'bb_af_');
                    if ($newName !== null) {
                        $image = $newName;
                        $imgCopied++;
                    } else {
                        $imgFailed++;
                        $imgErrors[] = 'Image not copied: ' . $aff['button'];
                    }
                }

                $name = $aff['sitename'] !== '' ? $aff['sitename']
                    : ($aff['affname'] !== '' ? $aff['affname'] : $url);

                $stmt = $pdo->prepare(
                    'INSERT INTO lstr_affiliates (fanlisting_id, name, url, email, image, sort_order)
                     VALUES (:fid, :name, :url, :email, :image, :sort_order)'
                );
                $stmt->execute([
                    'fid'        => $fanlistingId,
                    'name'       => $name,
                    'url'        => $url,
                    'email'      => $aff['email'],
                    'image'      => $image,
                    'sort_order' => ++$sortBase,
                ]);
                $imported++;
            } catch (\Throwable $e) {
                $errors[] = ($aff['url'] ?: '?') . ': ' . $e->getMessage();
            }
        }

        $dataRow = [
            'type'          => 'Affiliates',
            'imported'      => $imported,
            'skipped'       => $skipped,
            'warnings'      => [],
            'errors'        => $errors,
            'skipped_items' => $skippedItems,
        ];

        $imgRow = $migrator !== null ? [
            'type'   => 'Images (affiliates)',
            'copied' => $imgCopied,
            'skipped' => $imgFailed,
            'errors' => $imgErrors,
        ] : [];

        return [$dataRow, $imgRow];
    }

    // -----------------------------------------------------------------------
    // Code / Button insertion
    // -----------------------------------------------------------------------

    /** @return array{array,array} [data row, image row] */
    private function insertCodes(
        PDO $pdo, int $fanlistingId, ImportOptions $options, ?ImageMigrator $migrator
    ): array {
        $buttons      = $this->parseButtonFile($options->bbButtonsPath);
        $imported     = 0;
        $skipped      = 0;
        $errors       = [];
        $skippedItems = [];
        $imgCopied    = 0;
        $imgFailed    = 0;
        $imgErrors    = [];
        $sizeCache    = [];
        $donorCache   = [];

        $sortBase = (int) $pdo->query(
            'SELECT COALESCE(MAX(sort_order), 0) FROM lstr_codes WHERE fanlisting_id = ' . $fanlistingId
        )->fetchColumn();

        foreach ($buttons as $btn) {
            try {
                $origName = $btn['filename'];
                $w        = $btn['width'];
                $h        = $btn['height'];

                if ($origName === '') {
                    $skipped++;
                    $skippedItems[] = ['label' => '(blank)', 'reason' => 'Empty filename'];
                    continue;
                }

                // Deduplication: original filename + dimensions stored in lstr_codes.name
                $check = $pdo->prepare(
                    'SELECT id FROM lstr_codes
                     WHERE fanlisting_id = :fid AND name = :name AND width = :w AND height = :h'
                );
                $check->execute(['fid' => $fanlistingId, 'name' => $origName, 'w' => $w, 'h' => $h]);
                if ($check->fetchColumn() !== false) {
                    $skipped++;
                    $skippedItems[] = ['label' => $origName, 'reason' => 'Code already exists (same filename and dimensions)'];
                    continue;
                }

                // Image copy — required; skip code if image cannot be copied
                $newFilename = null;
                if ($migrator !== null && $options->bbButtonsDir !== '') {
                    $srcPath     = rtrim($options->bbButtonsDir, '/\\') . '/' . $origName;
                    $newFilename = $migrator->copyFromPath($srcPath, 'codes', 'bb_code_');
                    if ($newFilename !== null) {
                        $imgCopied++;
                    } else {
                        $imgFailed++;
                        $imgErrors[] = 'Image not copied: ' . $origName;
                    }
                }

                if ($newFilename === null) {
                    $skipped++;
                    $skippedItems[] = ['label' => $origName, 'reason' => 'Image file not found or failed MIME check'];
                    continue;
                }

                // Size upsert (cached per WxH)
                $sizeKey = "{$w}x{$h}";
                if (!isset($sizeCache[$sizeKey])) {
                    $sizeCache[$sizeKey] = $this->upsertSize($pdo, $fanlistingId, $w, $h);
                }
                $sizeId = $sizeCache[$sizeKey];

                // Donor upsert (cached by name||url)
                $donorId = null;
                if ($btn['donated'] && ($btn['donator'] !== '' || $btn['donatorUrl'] !== '')) {
                    $donorKey = $btn['donator'] . '||' . $btn['donatorUrl'];
                    if (!isset($donorCache[$donorKey])) {
                        $donorCache[$donorKey] = $this->upsertDonor(
                            $pdo, $fanlistingId, $btn['donator'], $btn['donatorUrl']
                        );
                    }
                    $donorId = $donorCache[$donorKey];
                }

                $stmt = $pdo->prepare(
                    'INSERT INTO lstr_codes
                     (fanlisting_id, name, filename, width, height, size_id, donor_id,
                      sort_order, status, submitter_name, submitter_url)
                     VALUES
                     (:fid, :name, :filename, :w, :h, :sid, :did,
                      :sort, :status, :sname, :surl)'
                );
                $stmt->execute([
                    'fid'      => $fanlistingId,
                    'name'     => $origName,
                    'filename' => $newFilename,
                    'w'        => $w,
                    'h'        => $h,
                    'sid'      => $sizeId,
                    'did'      => $donorId,
                    'sort'     => ++$sortBase,
                    'status'   => 'approved',
                    'sname'    => $btn['donated'] ? $btn['donator'] : null,
                    'surl'     => $btn['donated'] ? $btn['donatorUrl'] : null,
                ]);
                $imported++;
            } catch (\Throwable $e) {
                $errors[] = ($btn['filename'] ?: '?') . ': ' . $e->getMessage();
            }
        }

        $dataRow = [
            'type'          => 'Codes / Buttons',
            'imported'      => $imported,
            'skipped'       => $skipped,
            'warnings'      => [],
            'errors'        => $errors,
            'skipped_items' => $skippedItems,
        ];

        $imgRow = $migrator !== null ? [
            'type'   => 'Images (codes)',
            'copied' => $imgCopied,
            'skipped' => $imgFailed,
            'errors' => $imgErrors,
        ] : [];

        return [$dataRow, $imgRow];
    }

    // -----------------------------------------------------------------------
    // Database helpers
    // -----------------------------------------------------------------------

    /**
     * @return array{int, string} [fanlistingId, dbtable]
     * @throws RuntimeException
     */
    private function resolveTargetListing(PDO $pdo, int $listingId): array
    {
        $stmt = $pdo->prepare('SELECT listingid, dbtable FROM owned WHERE listingid = :id');
        $stmt->execute(['id' => $listingId]);
        $row = $stmt->fetch();

        if (!is_array($row) || !isset($row['dbtable'])) {
            throw new RuntimeException("Listing #{$listingId} not found in `owned` table.");
        }

        $dbtable = (string) $row['dbtable'];
        if (!preg_match('/^[A-Za-z0-9_]+$/', $dbtable)) {
            throw new RuntimeException("Invalid member table name '{$dbtable}' for listing #{$listingId}.");
        }

        // Verify the table exists before writing to it
        try {
            $pdo->query("SELECT 1 FROM `{$dbtable}` LIMIT 1");
        } catch (\Throwable) {
            throw new RuntimeException("Member table `{$dbtable}` does not exist or is not accessible.");
        }

        return [(int) $row['listingid'], $dbtable];
    }

    private function countMemberDupes(PDO $pdo, string $dbtable, array $members): int
    {
        $dupes = 0;
        foreach ($members as $m) {
            if ($m['email'] === '') {
                continue;
            }
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM `{$dbtable}` WHERE email = :email");
            $stmt->execute(['email' => $m['email']]);
            if ((int) $stmt->fetchColumn() > 0) {
                $dupes++;
            }
        }
        return $dupes;
    }

    private function countAffiliateDupes(PDO $pdo, int $fanlistingId, array $affiliates): int
    {
        $dupes = 0;
        foreach ($affiliates as $aff) {
            if ($aff['url'] === '') {
                continue;
            }
            $stmt = $pdo->prepare(
                'SELECT COUNT(*) FROM lstr_affiliates WHERE fanlisting_id = :fid AND url = :url'
            );
            $stmt->execute(['fid' => $fanlistingId, 'url' => $aff['url']]);
            if ((int) $stmt->fetchColumn() > 0) {
                $dupes++;
            }
        }
        return $dupes;
    }

    private function countCodeDupes(PDO $pdo, int $fanlistingId, array $buttons): int
    {
        $dupes = 0;
        foreach ($buttons as $btn) {
            if ($btn['filename'] === '') {
                continue;
            }
            $stmt = $pdo->prepare(
                'SELECT COUNT(*) FROM lstr_codes
                 WHERE fanlisting_id = :fid AND name = :name AND width = :w AND height = :h'
            );
            $stmt->execute([
                'fid'  => $fanlistingId,
                'name' => $btn['filename'],
                'w'    => $btn['width'],
                'h'    => $btn['height'],
            ]);
            if ((int) $stmt->fetchColumn() > 0) {
                $dupes++;
            }
        }
        return $dupes;
    }

    private function upsertSize(PDO $pdo, int $fanlistingId, int $w, int $h): int
    {
        $stmt = $pdo->prepare(
            'SELECT id FROM lstr_code_sizes WHERE fanlisting_id = :fid AND width = :w AND height = :h'
        );
        $stmt->execute(['fid' => $fanlistingId, 'w' => $w, 'h' => $h]);
        $id = $stmt->fetchColumn();
        if ($id !== false) {
            return (int) $id;
        }

        $sortMax = (int) $pdo->query(
            "SELECT COALESCE(MAX(sort_order), 0) FROM lstr_code_sizes WHERE fanlisting_id = {$fanlistingId}"
        )->fetchColumn();

        $ins = $pdo->prepare(
            'INSERT INTO lstr_code_sizes (fanlisting_id, label, width, height, sort_order)
             VALUES (:fid, :label, :w, :h, :sort)'
        );
        $ins->execute([
            'fid'   => $fanlistingId,
            'label' => "{$w}x{$h}",
            'w'     => $w,
            'h'     => $h,
            'sort'  => $sortMax + 1,
        ]);
        return (int) $pdo->lastInsertId();
    }

    private function upsertDonor(PDO $pdo, int $fanlistingId, string $name, string $url): int
    {
        $stmt = $pdo->prepare(
            'SELECT id FROM lstr_code_donors WHERE fanlisting_id = :fid AND name = :name'
        );
        $stmt->execute(['fid' => $fanlistingId, 'name' => $name]);
        $id = $stmt->fetchColumn();
        if ($id !== false) {
            return (int) $id;
        }

        $ins = $pdo->prepare(
            'INSERT INTO lstr_code_donors (fanlisting_id, name, url) VALUES (:fid, :name, :url)'
        );
        $ins->execute(['fid' => $fanlistingId, 'name' => $name, 'url' => $url]);
        return (int) $pdo->lastInsertId();
    }

    // -----------------------------------------------------------------------
    // Parsers
    // -----------------------------------------------------------------------

    /**
     * @return list<array{name:string,email:string,dispemail:string,url:string,country:string,fave:string}>
     */
    private function parseMemberFile(string $path): array
    {
        if (!is_readable($path)) {
            return [];
        }
        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false || empty($lines)) {
            return [];
        }

        $rows = [];
        foreach ($lines as $line) {
            $parsed = $this->parseMemberLine($line);
            if ($parsed !== null) {
                $rows[] = $parsed;
            }
        }
        return $rows;
    }

    /**
     * @return array{name:string,email:string,dispemail:string,url:string,country:string,fave:string}|null
     */
    private function parseMemberLine(string $line): ?array
    {
        $line = trim($line);
        if ($line === '') {
            return null;
        }

        // BellaBuffs splits on comma but stores pipe-escaped commas inside fields.
        // preg_split('/,(?! )/' mirrors BellaBuffs's own join.php parsing.
        $parts = preg_split('/,(?! )/', $line);
        if (!is_array($parts) || count($parts) < 2) {
            return null;
        }

        if ($this->looksCanonical($parts)) {
            return [
                'name'      => self::decodeText($parts[0] ?? ''),
                'email'     => self::decodeEmail($parts[1] ?? ''),
                'dispemail' => strtolower(trim($parts[2] ?? '')) === 'yes' ? 'yes' : 'no',
                'url'       => self::normalizeUrl($parts[3] ?? ''),
                'country'   => self::decodeText($parts[4] ?? ''),
                'fave'      => self::decodeText($parts[5] ?? ''),
            ];
        }

        return $this->parseMemberLineFallback($parts);
    }

    /**
     * Canonical check: column 2 is dispemail (yes/no/blank) and at least 5 fields.
     */
    private function looksCanonical(array $parts): bool
    {
        if (count($parts) < 5) {
            return false;
        }
        return in_array(strtolower(trim($parts[2] ?? '')), ['yes', 'no', ''], true);
    }

    /**
     * Fallback parser for non-canonical BellaBuffs files.
     * Detects the URL column by scanning for the first URL-like value at column ≥ 2
     * (skipping cells that look like a dispemail yes/no value).
     * Country is taken from the column immediately after the URL column.
     */
    private function parseMemberLineFallback(array $parts): ?array
    {
        if (count($parts) < 2) {
            return null;
        }

        $urlCol = count($parts) >= 3 ? 2 : count($parts) - 1;
        foreach ($parts as $i => $val) {
            if ($i < 2) {
                continue;
            }
            $val = trim($val);
            if ($val === 'yes' || $val === 'no') {
                continue;
            }
            if (preg_match('#^https?://#i', $val) || preg_match('#^www\.#i', $val)) {
                $urlCol = $i;
                break;
            }
        }

        return [
            'name'      => self::decodeText($parts[0] ?? ''),
            'email'     => self::decodeEmail($parts[1] ?? ''),
            'dispemail' => 'no',
            'url'       => self::normalizeUrl($parts[$urlCol] ?? ''),
            'country'   => self::decodeText($parts[$urlCol + 1] ?? ''),
            'fave'      => '',
        ];
    }

    /**
     * @return list<array{button:string,affname:string,email:string,url:string,sitename:string}>
     */
    private function parseAffiliateFile(string $path): array
    {
        if (!is_readable($path)) {
            return [];
        }
        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return [];
        }

        $rows = [];
        foreach ($lines as $line) {
            $line  = trim($line);
            $parts = explode(',', $line);
            if (count($parts) < 4) {
                continue;
            }
            $rows[] = [
                'button'   => trim($parts[0]),
                'affname'  => self::decodeText($parts[1] ?? ''),
                'email'    => self::decodeEmail($parts[2] ?? ''),
                'url'      => trim($parts[3] ?? ''),
                'sitename' => self::decodeText($parts[4] ?? ''),
            ];
        }
        return $rows;
    }

    /**
     * @return list<array{filename:string,width:int,height:int,donated:bool,donator:string,donatorUrl:string}>
     */
    private function parseButtonFile(string $path): array
    {
        if (!is_readable($path)) {
            return [];
        }
        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return [];
        }

        $rows = [];
        foreach ($lines as $line) {
            $line  = trim($line);
            $parts = explode(',', $line);
            if (count($parts) < 3) {
                continue;
            }
            $rows[] = [
                'filename'   => trim($parts[0]),
                'width'      => max(0, (int) trim($parts[1] ?? '0')),
                'height'     => max(0, (int) trim($parts[2] ?? '0')),
                'donated'    => strtolower(trim($parts[3] ?? '')) === 'yes',
                'donator'    => self::decodeText($parts[4] ?? ''),
                'donatorUrl' => trim($parts[5] ?? ''),
            ];
        }
        return $rows;
    }

    // -----------------------------------------------------------------------
    // Static decoding helpers (public so tests can call them directly)
    // -----------------------------------------------------------------------

    public static function decodeEmail(string $e): string
    {
        return str_ireplace(self::EMAIL_SEARCH, self::EMAIL_REPLACE, trim($e));
    }

    public static function decodeText(string $t): string
    {
        $t = trim($t);
        $t = stripslashes($t);
        $t = html_entity_decode($t, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $t = str_replace('|', ',', $t);
        return $t;
    }

    public static function normalizeUrl(string $u): string
    {
        $u = trim($u);
        if (in_array($u, ['', 'http://', 'https://'], true)) {
            return '';
        }
        return $u;
    }
}
