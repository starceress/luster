<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Contract that every import adapter must implement. Each adapter
 connects to a foreign script's database, previews what would be
 imported (dry run), and then executes the actual import into
 Luster's tables. Adapters are stateless -- all context flows
 through method arguments, and the source and target PDO
 connections may point to the same database.
*/

declare(strict_types=1);

namespace Luster\Import;

use PDO;

/**
 * Contract that every import adapter must implement.
 *
 * Each adapter connects to a foreign fanlisting script's database (or reads
 * flat files in the BellaBuffs case), runs a read-only preview pass, and then
 * executes the actual data migration into Luster's tables. Adapters are
 * stateless — all context flows through method arguments — and the $source and
 * $target PDO connections may point to the same database (e.g. when Enthusiast
 * and Luster share a MySQL instance).
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
     * News Feed, Rules, Adoption) are installed, so the preview accurately
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
