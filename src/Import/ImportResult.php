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
     * What to do about the codes in a row's missing_images, after "To get their images:".
     * Shown on the result page and in the report; only the CodeSort importer lists them.
     */
    public const MISSING_IMAGES_ADVICE = 'delete these codes on each listing\'s Codes page (you can tick several '
        . 'and delete them together), then run the import again with a working image path, web address or ZIP. '
        . 'They\'ll come back as new codes, with their images. Deleting a code also removes anything you changed '
        . 'on it in Luster since the import — such as a name you gave it, its categories or its place in the '
        . 'order. The new copy has only what CodeSort has.';

    /**
     * A row may also have missing_images (string[]): codes that were imported or matched
     * but have no image file, one label each, e.g. "Moonlight: moon-a.png (code #41)".
     *
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
            $missingImages = $row['missing_images'] ?? [];
            foreach ($missingImages as $label) {
                $lines[] = '  NO IMAGE: ' . $label;
            }
            if ($missingImages !== []) {
                $lines[] = '  To get their images: ' . self::MISSING_IMAGES_ADVICE;
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
