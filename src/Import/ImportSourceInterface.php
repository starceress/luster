<?php

declare(strict_types=1);

namespace Luster\Import;

use PDO;

/**
 * Contract that every import adapter must implement.
 *
 * Adapters are stateless: all context is passed as arguments.
 * $source is a PDO connected to the foreign database.
 * $target is Luster's own PDO (from Database::getPdo()).
 * They may be the same connection object (Enthusiast on same DB as Luster).
 */
interface ImportSourceInterface
{
    /**
     * Returns the human-readable name of this source.
     */
    public function getName(): string;

    /**
     * Attempts to open a PDO connection to the source database using the
     * supplied credentials array. Returns null on success, or an error
     * message string on failure.
     *
     * @param array{host:string,name:string,user:string,pass:string} $credentials
     */
    public function testConnection(array $credentials): ?string;

    /**
     * Opens and returns a PDO connection to the source database.
     * Throws \RuntimeException on failure.
     *
     * @param array{host:string,name:string,user:string,pass:string} $credentials
     */
    public function openConnection(array $credentials): PDO;

    /**
     * Inspects the source database and returns a short schema variant string,
     * e.g. 'enthusiast_unprefixed', 'enthusiast_enth', 'listing_admin_3x'.
     * Returns 'unknown' if the schema cannot be identified.
     */
    public function detectSchema(PDO $source): string;

    /**
     * Performs a read-only pass over the source database and returns counts
     * and warnings without writing anything to either database.
     *
     * $target may be used to check whether Luster add-ons (Wishlist, KIM,
     * Updates Feed, Rules, Adoption) are installed, so the preview accurately
     * reports what will be imported vs. skipped.
     */
    public function preview(PDO $source, PDO $target, ImportOptions $options): ImportPreview;

    /**
     * Executes the import, writing to $target.
     * Returns a full result report.
     *
     * Implementations must:
     * - Process records in chunks (500 per batch)
     * - Wrap each individual record in try/catch; one bad row must not abort
     * - Never write to $source
     */
    public function run(PDO $source, PDO $target, ImportOptions $options): ImportResult;
}
