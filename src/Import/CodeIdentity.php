<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Decides which stored code, if any, a code being imported is. lstr_codes.name only holds
 150 characters and an admin can rename or clear it, so the source filename is what
 identifies a code from one import to the next. Fanatically's listing codes and BellaBuffs
 share this, for their previews too, so a preview can't promise what the import won't do.
*/

declare(strict_types=1);

namespace Luster\Import;

/**
 * Nothing in here touches the database. The importers fetch the rows for one listing and
 * size, and pass them in. The database compares names and filenames ignoring case and
 * accents, so it can't be asked which row is "code.png": every comparison here is byte
 * for byte. Handing over more rows than could match is fine, because the rest are ignored.
 *
 * A row is array{id: int, name: ?string, src: ?string}, where src is import_source_file.
 */
final class CodeIdentity
{
    /** lstr_codes.name, in characters. */
    public const NAME_LIMIT = 150;

    /** lstr_codes.import_source_file (Migration 104), in characters. */
    public const SOURCE_LIMIT = 255;

    public const INSERT   = 'insert';
    public const EXISTING = 'existing';
    public const SKIP     = 'skip';

    private function __construct() {}

    /** What goes in lstr_codes.name for this file. */
    public static function cappedName(string $file): string
    {
        return mb_substr($file, 0, self::NAME_LIMIT);
    }

    /**
     * Why a file can't be tracked between imports at all, or null if it can. Check this
     * before adding a size for the code, so a code that won't be imported leaves nothing.
     */
    public static function cantTrack(string $file, bool $hasSourceColumn): ?string
    {
        if (mb_strlen($file) > self::SOURCE_LIMIT) {
            return "Its filename is longer than 255 characters, so Luster can't keep track of it between imports.";
        }
        if (!$hasSourceColumn && mb_strlen($file) > self::NAME_LIMIT) {
            return "Its filename is longer than 150 characters, so Luster can't keep track of it yet: run the migration page first (Luster needs Migration 104), then import again.";
        }

        return null;
    }

    /**
     * The files that share their first 150 characters with a different file, so an
     * untagged row with that name could be either of them. Pass one listing and size's files.
     *
     * @param list<string> $files
     * @return array<string, true> File => true.
     */
    public static function colliding(array $files): array
    {
        $byCappedName = [];
        foreach ($files as $file) {
            $byCappedName[self::cappedName($file)][$file] = true;
        }

        $colliding = [];
        foreach ($byCappedName as $sameStart) {
            if (count($sameStart) > 1) {
                $colliding += $sameStart;
            }
        }

        return $colliding;
    }

    /**
     * Which stored row this file is. The first rule that fits decides. Call cantTrack() first.
     *
     * With Migration 104 missing it's a plain exact-name match, and nothing below applies.
     * Otherwise:
     *
     *  1. One row tagged with exactly this file: that row, whatever its name says now.
     *  2. More than one such row: they can't be told apart.
     *  3. An untagged row with this name, and the file collides: it could be either file.
     *  4. More than one untagged row with this name: they can't be told apart.
     *  5. One untagged row with this name: that row, tagged if the import writes to it.
     *  6. Nothing: a new row.
     *
     * @param list<array{id: int, name: ?string, src: ?string}> $rows
     * @return array{action: string, id: ?int, tag: bool, reason: ?string} tag says the
     *         row isn't tagged yet, so an overwrite should tag it.
     */
    public static function decide(string $file, bool $hasSourceColumn, bool $collides, array $rows): array
    {
        $capped   = self::cappedName($file);
        $tagged   = [];
        $untagged = [];
        foreach ($rows as $row) {
            if ($row['src'] !== null) {
                if ($row['src'] === $file) {
                    $tagged[] = $row['id'];
                }
            } elseif ($row['name'] === $capped) {
                $untagged[] = $row['id'];
            }
        }

        if (!$hasSourceColumn) {
            return $untagged !== [] ? self::existing($untagged[0], false) : self::insert();
        }

        if (count($tagged) === 1) {
            return self::existing($tagged[0], false);
        }
        if ($tagged !== []) {
            return self::skip('Luster already has ' . count($tagged) . " copies of this code that can't be told apart, so they were left alone.");
        }
        if ($untagged !== [] && $collides) {
            return self::skip('Its filename shares its first 150 characters with another code in this import, and the code already in Luster could be either one, so it was left alone.');
        }
        if (count($untagged) > 1) {
            return self::skip('Luster already has ' . count($untagged) . " codes with this name that can't be told apart, so they were left alone.");
        }
        if ($untagged !== []) {
            return self::existing($untagged[0], true);
        }

        return self::insert();
    }

    /**
     * The rows once this file has been added, so a later code in the same import finds it.
     *
     * @param list<array{id: int, name: ?string, src: ?string}> $rows
     * @return list<array{id: int, name: ?string, src: ?string}>
     */
    public static function afterInsert(array $rows, int $id, string $file, bool $hasSourceColumn): array
    {
        $rows[] = ['id' => $id, 'name' => self::cappedName($file), 'src' => $hasSourceColumn ? $file : null];

        return $rows;
    }

    /**
     * The rows once a row has been tagged with this file.
     *
     * @param list<array{id: int, name: ?string, src: ?string}> $rows
     * @return list<array{id: int, name: ?string, src: ?string}>
     */
    public static function afterTagging(array $rows, int $id, string $file): array
    {
        foreach ($rows as $i => $row) {
            if ($row['id'] === $id) {
                $rows[$i]['src'] = $file;
            }
        }

        return $rows;
    }

    /** @return array{action: string, id: ?int, tag: bool, reason: ?string} */
    private static function existing(int $id, bool $tag): array
    {
        return ['action' => self::EXISTING, 'id' => $id, 'tag' => $tag, 'reason' => null];
    }

    /** @return array{action: string, id: ?int, tag: bool, reason: ?string} */
    private static function insert(): array
    {
        return ['action' => self::INSERT, 'id' => null, 'tag' => false, 'reason' => null];
    }

    /** @return array{action: string, id: ?int, tag: bool, reason: ?string} */
    private static function skip(string $reason): array
    {
        return ['action' => self::SKIP, 'id' => null, 'tag' => false, 'reason' => $reason];
    }
}
