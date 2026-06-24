<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Immutable value object returned by ImportSourceInterface::preview().
 It carries per-data-type row counts (found, would-import, would-skip)
 and informational warnings from the read-only dry-run pass. No
 writes have been performed when this object exists. The result page
 in the import wizard renders these counts so the user can review
 before committing the import.
*/

declare(strict_types=1);

namespace Luster\Import;

/**
 * Read-only dry-run result returned by ImportSourceInterface::preview().
 *
 * Carries per-data-type row counts (found, would-import, would-skip) and any
 * informational warnings gathered without writing anything to either database.
 * The import wizard's preview page renders these values so the admin can review
 * what will happen before committing the actual import run.
 */
final class ImportPreview
{
    /** @param list<array{type:string,found:int,import:int,skip:int,warnings:string[]}> $rows */
    public function __construct(
        public readonly array  $rows,
        public readonly string $schemaVariant,
        public readonly bool   $sameDatabase,
    ) {}

    /**
     * Returns the total number of rows that would be imported across all data types.
     */
    public function totalToImport(): int
    {
        return (int) array_sum(array_column($this->rows, 'import'));
    }

    /**
     * Returns all unique warning strings across all row types.
     *
     * @return string[]
     */
    public function allWarnings(): array
    {
        $warnings = [];
        foreach ($this->rows as $row) {
            foreach ($row['warnings'] as $w) {
                $warnings[] = $w;
            }
        }
        return array_unique($warnings);
    }
}
