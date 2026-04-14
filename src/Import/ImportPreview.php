<?php

declare(strict_types=1);

namespace Luster\Import;

/**
 * Value object returned by ImportSourceInterface::preview().
 *
 * Carries read-only counts and informational warnings from the dry-run pass.
 * No writes have been performed when this object is produced.
 *
 * Each item in $rows has shape:
 *   [
 *     'type'    => string,   // e.g. 'Categories', 'Affiliates', 'Members (listing 1)'
 *     'found'   => int,      // rows found in source
 *     'import'  => int,      // rows that would be inserted
 *     'skip'    => int,      // rows that would be skipped (already exist)
 *     'warnings'=> string[], // informational notes (not errors)
 *   ]
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
     * Returns the total number of rows that would be imported across all types.
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
