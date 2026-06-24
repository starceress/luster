<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Immutable value object returned by ImportSourceInterface::run()
 after an import completes. Holds per-data-type counts of rows
 imported, skipped, and errored, plus a separate image-row tally.
 Also provides a toText() method that produces a plain-text
 report the user can download from the result page.
*/

declare(strict_types=1);

namespace Luster\Import;

/**
 * Full result report returned by ImportSourceInterface::run() after an import completes.
 *
 * Holds per-data-type row counts (imported, skipped, errors) and a separate
 * image-migration tally. toText() produces a plain-text report the admin can
 * download from the wizard's result page.
 */
final class ImportResult
{
    /**
     * @param list<array{type:string,imported:int,skipped:int,warnings:string[],errors:string[],skipped_items:array<array{label:string,reason:string}>}> $rows
     * @param list<array{type:string,copied:int,skipped:int,errors:string[]}>                     $imageRows
     * @param float  $duration  Elapsed seconds
     */
    public function __construct(
        public readonly array  $rows,
        public readonly array  $imageRows,
        public readonly float  $duration,
        public readonly string $schemaVariant,
    ) {}

    /**
     * Returns the total number of records successfully inserted across all data types.
     */
    public function totalImported(): int
    {
        return (int) array_sum(array_column($this->rows, 'imported'));
    }

    /**
     * Returns the total number of records skipped (conflict strategy or gate) across all data types.
     */
    public function totalSkipped(): int
    {
        return (int) array_sum(array_column($this->rows, 'skipped'));
    }

    /**
     * Returns the total number of image files successfully copied or fetched.
     */
    public function totalImagesCopied(): int
    {
        return (int) array_sum(array_column($this->imageRows, 'copied'));
    }

    /**
     * Returns the total number of image migrations that failed or were skipped.
     */
    public function totalImagesFailed(): int
    {
        return (int) array_sum(array_column($this->imageRows, 'skipped'));
    }

    /**
     * Returns true if any data row or image row recorded at least one error string.
     */
    public function hasErrors(): bool
    {
        foreach ($this->rows as $row) {
            if (!empty($row['errors'])) {
                return true;
            }
        }
        foreach ($this->imageRows as $row) {
            if (!empty($row['errors'])) {
                return true;
            }
        }
        return false;
    }

    /**
     * Returns a plain-text report suitable for download.
     */
    public function toText(): string
    {
        $lines   = [];
        $lines[] = 'Luster Import Report';
        $lines[] = 'Schema: ' . $this->schemaVariant;
        $lines[] = sprintf('Duration: %.2f seconds', $this->duration);
        $lines[] = str_repeat('-', 60);

        foreach ($this->rows as $row) {
            $lines[] = sprintf(
                '%-30s  imported: %d  skipped: %d',
                $row['type'],
                $row['imported'],
                $row['skipped'],
            );
            foreach ($row['warnings'] as $w) {
                $lines[] = '  NOTE: ' . $w;
            }
            foreach ($row['errors'] as $e) {
                $lines[] = '  ERROR: ' . $e;
            }
            foreach ($row['skipped_items'] ?? [] as $si) {
                $lines[] = '  SKIPPED: ' . $si['label'] . ' — ' . $si['reason'];
            }
        }

        if (!empty($this->imageRows)) {
            $lines[] = str_repeat('-', 60);
            foreach ($this->imageRows as $row) {
                $lines[] = sprintf(
                    '%-30s  copied: %d  skipped: %d',
                    $row['type'],
                    $row['copied'],
                    $row['skipped'],
                );
                foreach ($row['errors'] as $e) {
                    $lines[] = '  ERROR: ' . $e;
                }
            }
        }

        $lines[] = str_repeat('-', 60);
        $lines[] = sprintf('Total imported: %d  Total skipped: %d  Images copied: %d',
            $this->totalImported(), $this->totalSkipped(), $this->totalImagesCopied());

        return implode("\n", $lines) . "\n";
    }
}
