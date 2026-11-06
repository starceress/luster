<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Works out, before anything is written, where each Enthusiast source listing goes:
 the same row in a proven shared registry, the one listing with its address, a new
 listing, or not imported at all, with the reason. Preview and every phase of a run
 read this one answer, so they can't disagree, and refusing in one phase can't be
 undone by the next. It also works out whether each listing's members are really where
 the import is reading from.
*/

declare(strict_types=1);

namespace Luster\Import;

use Luster\Support\ProtectedTables;
use PDO;
use RuntimeException;

/**
 * One import's answer for every source listing.
 *
 * Ids are only compared inside a proven shared registry. Between unrelated databases a
 * listing id means nothing, so a blank address outside one can't be matched at all.
 *
 * A listing's saved server and database are only compared, never connected to, and the
 * saved user and password are never read out of the row into anything here.
 */
final class EnthusiastListingPlan
{
    /** @param array<int, EnthusiastPlannedListing> $listings Source id => its answer, in source order. */
    private function __construct(
        private array $listings,
        public readonly bool $sameRegistry,
        private readonly string $sourceIdentity,
        /** Why the source's listings couldn't be read, or ''. A phase says so, since there's nothing to plan. */
        public readonly string $sourceError = '',
    ) {}

    /**
     * @throws RuntimeException If Luster's own listing table or a member table's columns can't be read.
     */
    public static function build(PDO $source, PDO $target, string $schema, ImportOptions $options): self
    {
        $prefix = $options->tablePrefix;
        $enth   = $schema === 'enthusiast_enth';

        $registry = self::readTargetRegistry($target);

        $connected  = self::databaseName($source);
        $sameServer = $source === $target
            || ($connected !== '' && $connected === self::databaseName($target) && SameServerProof::holds($source, $target));
        $sameDatabase = $prefix === '' && $sameServer;
        $sameRegistry = $sameDatabase && !$enth;
        $isFile       = $prefix !== '';

        $sourceError = '';
        $rows        = self::readSourceRegistry($source, $prefix . ($enth ? 'enth_fanlistings' : 'owned'), $enth ? 'id' : 'listingid', $sourceError);
        $tables      = self::targetTables($target);

        // What this run has claimed so far, so a second source listing can't share it.
        $claimedUrls   = [];
        $claimedTables = [];
        $listings      = [];

        foreach ($rows as $row) {
            $row      = array_change_key_case($row, CASE_LOWER);
            $id       = (int) ($row[$enth ? 'id' : 'listingid'] ?? 0);
            $url      = trim((string) ($row['url'] ?? ''));
            $title    = trim(self::decode((string) ($row[$enth ? 'name' : 'title'] ?? '')));
            $rawTable = trim((string) ($row[$enth ? 'dbprefix' : 'dbtable'] ?? ''));
            $table    = $enth ? ($rawTable === '' ? '' : $rawTable . 'members') : $rawTable;
            $fields   = EnthusiastSourceFields::read((string) ($row[$enth ? 'additional_info' : 'additional'] ?? ''), $schema);
            $server   = trim((string) ($row['dbserver'] ?? ''));
            $database = trim((string) ($row['dbdatabase'] ?? ''));

            $entry = new EnthusiastPlannedListing(
                sourceId: $id,
                title: $title,
                url: $url,
                sourceTable: $table,
                fields: $fields,
                result: EnthusiastPlannedListing::NOT_IMPORTED,
                targetId: null,
                targetTable: null,
                savedServer: $server,
                savedDatabase: $database,
            );

            self::decideDestination(
                $entry, $options, $sameRegistry, $sameDatabase, $registry, $tables, $target,
                $claimedUrls, $claimedTables,
            );

            if ($entry->isImported()) {
                self::decideLocation($entry, $source, $prefix, $options, $connected, $isFile, $tables);
            }

            if ($id > 0) {
                $listings[$id] = $entry;
            }
        }

        $identity = implode("\0", ['enthusiast', strtolower(trim($options->sourceHost)), $connected, $prefix]);

        return new self($listings, $sameRegistry, $identity, $sourceError);
    }

    /**
     * Whether a listing's saved location matches what the import is reading from.
     *
     * Enthusiast opens a separate connection from those saved values, so "localhost" means
     * the machine Enthusiast ran on. That's only tied to this import when the wizard also
     * connected to this machine's database: reaching a registry on a remote server doesn't
     * make a listing's "localhost" that same place. A file has no connection to compare.
     */
    public static function locationIsHere(string $savedServer, string $savedDatabase, string $typedHost, string $connectedDatabase, bool $isFile): bool
    {
        $savedServer   = strtolower(trim($savedServer));
        $savedDatabase = trim($savedDatabase);
        $typedHost     = strtolower(trim($typedHost));

        if ($savedServer === '' && $savedDatabase === '') {
            return true;
        }
        if ($isFile || $connectedDatabase === '' || $savedDatabase !== $connectedDatabase) {
            return false;
        }
        if ($typedHost === '') {
            return false;
        }
        if ($savedServer === $typedHost) {
            return true;
        }

        return self::isThisMachine($savedServer) && self::isThisMachine($typedHost);
    }

    /** @return array<int, EnthusiastPlannedListing> */
    public function listings(): array
    {
        return $this->listings;
    }

    public function forSource(int $sourceId): ?EnthusiastPlannedListing
    {
        return $this->listings[$sourceId] ?? null;
    }

    /** @return list<EnthusiastPlannedListing> */
    public function notImported(): array
    {
        return array_values(array_filter($this->listings, static fn(EnthusiastPlannedListing $l): bool => !$l->isImported()));
    }

    /** True when this is one shared registry, and every listing's members are known to be in it. */
    public function readsEverythingInPlace(): bool
    {
        if (!$this->sameRegistry) {
            return false;
        }
        foreach ($this->listings as $entry) {
            if ($entry->isImported() && !$entry->membersAreHere()) {
                return false;
            }
        }
        return true;
    }

    /** Moves a listing whose save failed out of the plan, so later phases skip it. */
    public function markFailed(int $sourceId, string $reason): void
    {
        if (isset($this->listings[$sourceId])) {
            $this->listings[$sourceId]->result      = EnthusiastPlannedListing::NOT_IMPORTED;
            $this->listings[$sourceId]->targetId    = null;
            $this->listings[$sourceId]->targetTable = null;
            $this->listings[$sourceId]->reason      = $reason;
        }
    }

    /**
     * What the Preview asks the owner to confirm, or null when there's nothing to ask.
     *
     * @return array{fingerprint: string, listings: list<array{id: int, title: string, table: string, server: string, database: string}>}|null
     */
    public function confirmation(): ?array
    {
        $listings = [];
        foreach ($this->listings as $entry) {
            if ($entry->offered) {
                $listings[] = [
                    'id'       => $entry->sourceId,
                    'title'    => $entry->label(),
                    'table'    => $entry->sourceTable,
                    'server'   => $entry->savedServer,
                    'database' => $entry->savedDatabase,
                ];
            }
        }

        return $listings === [] ? null : ['fingerprint' => $this->fingerprint(), 'listings' => $listings];
    }

    /**
     * Applies a yes from the Run form. It only covers what the Preview showed, so anything
     * that doesn't match stops here, before the run has written anything.
     *
     * @throws MemberConfirmationChanged
     */
    public function applyConsent(string $consent): void
    {
        if ($consent === '') {
            return;
        }
        if ($this->confirmation() === null || !hash_equals($this->fingerprint(), $consent)) {
            throw new MemberConfirmationChanged();
        }
        foreach ($this->listings as $entry) {
            if ($entry->offered) {
                $entry->consented = true;
            }
        }
    }

    /**
     * A hash of the selected source and, for each listing offered, its id, tables, saved
     * location and destination. A new listing, or any of those changing, changes it. Holds
     * no user or password.
     */
    private function fingerprint(): string
    {
        $listed = [];
        foreach ($this->listings as $entry) {
            if ($entry->offered) {
                $listed[] = [
                    $entry->sourceId, $entry->sourceTable, $entry->savedServer, $entry->savedDatabase,
                    $entry->result, $entry->targetId, $entry->targetTable,
                ];
            }
        }
        usort($listed, static fn(array $a, array $b): int => $a[0] <=> $b[0]);

        return hash('sha256', (string) json_encode([$this->sourceIdentity, $listed]));
    }

    /**
     * @param array{byId: array<int, array{url: string, table: string}>, byUrl: array<string, list<int>>, tableOwners: array<string, list<int>>} $registry
     * @param array<string, string> $tables Lowercase name => real name.
     * @param array<string, true> $claimedUrls
     * @param array<string, true> $claimedTables
     */
    private static function decideDestination(
        EnthusiastPlannedListing $entry,
        ImportOptions $options,
        bool $sameRegistry,
        bool $sameDatabase,
        array $registry,
        array $tables,
        PDO $target,
        array &$claimedUrls,
        array &$claimedTables,
    ): void {
        $refuse = static function (string $reason) use ($entry): void {
            $entry->result = EnthusiastPlannedListing::NOT_IMPORTED;
            $entry->reason = $reason;
        };
        $name = '"' . $entry->label() . '"';

        if ($entry->sourceId <= 0) {
            $refuse("{$name} wasn't imported: it has no listing id.");
            return;
        }

        // A name from the source says which table gets read, so it's checked first, for
        // every kind of destination, and as written there: a file's temporary prefix
        // goes on afterward and would hide what it really names. A blank one reads nothing.
        if ($entry->sourceTable !== '') {
            if (preg_match('/^[a-zA-Z0-9_]+$/', $entry->sourceTable) !== 1) {
                $refuse("{$name} wasn't imported: its member table name \"{$entry->sourceTable}\" isn't valid.");
                return;
            }
            if (ProtectedTables::contains($entry->sourceTable)) {
                $refuse("{$name} wasn't imported: its member table is `{$entry->sourceTable}`, and that table isn't a member table.");
                return;
            }
        }

        // One proven registry: the same row, whatever its address says.
        if ($sameRegistry && isset($registry['byId'][$entry->sourceId])) {
            $own                = $registry['byId'][$entry->sourceId];
            $entry->result      = EnthusiastPlannedListing::SAME_ROW;
            $entry->targetId    = $entry->sourceId;
            $entry->targetTable = $own['table'] !== '' ? $own['table'] : null;
            $entry->sameTable   = true;
            return;
        }

        if ($entry->url === '') {
            $refuse("{$name} wasn't imported: it has no web address, so Luster can't tell which listing it is on a later import. Give it a web address in Enthusiast and run the import again.");
            return;
        }

        $matches = $registry['byUrl'][$entry->url] ?? [];

        if (count($matches) > 1) {
            $ids = implode(' and ', array_map(static fn(int $id): string => '#' . $id, $matches));
            $refuse("{$name} wasn't imported: listings {$ids} here share the address {$entry->url}, and Luster won't guess which one you mean. Give each its own address and run the import again.");
            return;
        }

        if (isset($claimedUrls[$entry->url])) {
            $refuse("{$name} wasn't imported: another listing in this import has the same address, {$entry->url}.");
            return;
        }

        if (count($matches) === 1) {
            $targetId = $matches[0];
            $own      = $registry['byId'][$targetId];
            if ($own['table'] !== '' && (preg_match('/^[a-zA-Z0-9_]+$/', $own['table']) !== 1 || ProtectedTables::contains($own['table']))) {
                $refuse("{$name} wasn't imported: listing #{$targetId}'s member table is `{$own['table']}`, and that can't be a member table.");
                return;
            }
            $owners = $registry['tableOwners'][strtolower($own['table'])] ?? [];
            if (count($owners) > 1) {
                $ids = implode(' and ', array_map(static fn(int $id): string => '#' . $id, $owners));
                $refuse("{$name} wasn't imported: listings {$ids} here share the member table `{$own['table']}`, so anything imported for one would change the other too. Give each its own table and run the import again.");
                return;
            }
            if ($own['table'] !== '' && !self::tableIsUsable($target, $own['table'], $tables)) {
                $refuse("{$name} wasn't imported: listing #{$targetId}'s table `{$own['table']}` doesn't have the email and pending columns a member table needs.");
                return;
            }
            $isSameTable = $sameDatabase && $own['table'] !== '' && strcasecmp($own['table'], $entry->sourceTable) === 0;
            // In one database, a table another listing owns holds that listing's fans.
            $sourceOwners = array_diff($registry['tableOwners'][strtolower($entry->sourceTable)] ?? [], [$targetId]);
            if ($sameDatabase && !$isSameTable && $sourceOwners !== []) {
                $owner = '#' . reset($sourceOwners);
                $refuse("{$name} wasn't imported: its member table `{$entry->sourceTable}` belongs to listing {$owner} here, so those fans aren't this listing's.");
                return;
            }
            $entry->result      = EnthusiastPlannedListing::EXISTING;
            $entry->targetId    = $targetId;
            $entry->targetTable = $own['table'] !== '' ? $own['table'] : null;
            $entry->sameTable   = $isSameTable;
            $claimedUrls[$entry->url] = true;
            return;
        }

        // No match, so this can only be a new listing.
        if (!$options->importOwnedListings) {
            $refuse("{$name} wasn't imported: no listing here has the address {$entry->url}. Tick Owned listings to create it.");
            return;
        }
        if ($entry->sourceTable === '') {
            $refuse("{$name} wasn't imported: it has no member table name.");
            return;
        }
        $lower = strtolower($entry->sourceTable);
        if (isset($registry['tableOwners'][$lower])) {
            $owner = '#' . $registry['tableOwners'][$lower][0];
            $refuse("{$name} wasn't imported: the table `{$entry->sourceTable}` already belongs to listing {$owner}, and its members would be merged in. If that's the same listing with a new address, give it the same address on both sides and run the import again.");
            return;
        }
        if (isset($claimedTables[$lower])) {
            $refuse("{$name} wasn't imported: another listing in this import uses the same table, `{$entry->sourceTable}`.");
            return;
        }
        if (isset($tables[$lower])) {
            if (!self::tableIsUsable($target, $entry->sourceTable, $tables)) {
                $refuse("{$name} wasn't imported: a table called `{$entry->sourceTable}` already exists here, and it isn't a member table.");
                return;
            }
            $entry->note = "{$name}: a member table called `{$entry->sourceTable}` is already here with no listing using it, probably from an earlier import. Its members stay, and this listing will use it.";
        }

        // Before anything is stored or created, so two fields can't end up fighting over
        // one column, with one's answers showing under the other.
        $entry->fields = EnthusiastFieldMerge::planNewListing(
            $entry->fields,
            isset($tables[$lower]) ? self::columnsOf($target, $tables[$lower]) : [],
        );

        $entry->result      = EnthusiastPlannedListing::NEW;
        $entry->targetTable = $entry->sourceTable;
        $entry->sameTable   = $sameDatabase && isset($tables[$lower]);
        $claimedUrls[$entry->url] = true;
        $claimedTables[$lower]    = true;
    }

    /** @param array<string, string> $tables */
    private static function decideLocation(
        EnthusiastPlannedListing $entry,
        PDO $source,
        string $prefix,
        ImportOptions $options,
        string $connected,
        bool $isFile,
        array $tables,
    ): void {
        $entry->locationConfirmed = self::locationIsHere($entry->savedServer, $entry->savedDatabase, $options->sourceHost, $connected, $isFile);
        $entry->sourceTableExists = preg_match('/^[a-zA-Z0-9_]+$/', $entry->sourceTable) === 1
            && self::readable($source, $prefix . $entry->sourceTable);
        $entry->targetTableExists = $entry->targetTable !== null && isset($tables[strtolower($entry->targetTable)]);

        if ($entry->locationConfirmed || !$options->importMembers) {
            return;
        }
        $entry->offered = $entry->sameTable ? $entry->targetTableExists : ($entry->sourceTableExists && $entry->targetTable !== null);
    }

    /**
     * @return array{byId: array<int, array{url: string, table: string}>, byUrl: array<string, list<int>>, tableOwners: array<string, list<int>>}
     */
    private static function readTargetRegistry(PDO $target): array
    {
        try {
            $rows = $target->query('SELECT listingid, url, dbtable FROM owned ORDER BY listingid ASC')->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            throw new RuntimeException("Luster couldn't read its own listing table, so nothing was imported: " . $e->getMessage(), 0, $e);
        }

        $byId = [];
        $byUrl = [];
        $owners = [];
        foreach ($rows as $row) {
            $id    = (int) $row['listingid'];
            $url   = trim((string) ($row['url'] ?? ''));
            $table = trim((string) ($row['dbtable'] ?? ''));

            $byId[$id] = ['url' => $url, 'table' => $table];
            if ($url !== '') {
                $byUrl[$url][] = $id;
            }
            if ($table !== '') {
                $owners[strtolower($table)][] = $id;
            }
        }

        return ['byId' => $byId, 'byUrl' => $byUrl, 'tableOwners' => $owners];
    }

    /** @return list<array<string, mixed>> Empty when it can't be read, with $error saying why. */
    private static function readSourceRegistry(PDO $source, string $table, string $idColumn, string &$error): array
    {
        try {
            return $source->query("SELECT * FROM `{$table}` ORDER BY `{$idColumn}` ASC")->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            $error = $e->getMessage();
            return [];
        }
    }

    /**
     * Lowercase name => real name, for every table on the target.
     *
     * @return array<string, string>
     * @throws RuntimeException
     */
    private static function targetTables(PDO $target): array
    {
        try {
            $sql = $target->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite'
                ? "SELECT name FROM sqlite_master WHERE type = 'table'"
                : 'SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE()';
            $names = $target->query($sql)->fetchAll(PDO::FETCH_COLUMN);
        } catch (\Throwable $e) {
            throw new RuntimeException("Luster couldn't read its own table list, so nothing was imported: " . $e->getMessage(), 0, $e);
        }

        $out = [];
        foreach ($names as $name) {
            $out[strtolower((string) $name)] = (string) $name;
        }
        return $out;
    }

    /**
     * A table that isn't there is fine, since it can be created. One that's there needs
     * the two columns every member read and write depends on.
     *
     * @param array<string, string> $tables
     * @throws RuntimeException
     */
    private static function tableIsUsable(PDO $target, string $table, array $tables): bool
    {
        $lower = strtolower($table);
        if (!isset($tables[$lower])) {
            return true;
        }
        if (preg_match('/^[a-zA-Z0-9_]+$/', $tables[$lower]) !== 1) {
            return false;
        }

        $columns = array_map('strtolower', self::columnsOf($target, $tables[$lower]));

        return in_array('email', $columns, true) && in_array('pending', $columns, true);
    }

    /**
     * A table's real column names.
     *
     * @return list<string>
     * @throws RuntimeException If they can't be read, which never counts as having none.
     */
    public static function columnsOf(PDO $pdo, string $table): array
    {
        if (preg_match('/^[a-zA-Z0-9_]+$/', $table) !== 1) {
            throw new RuntimeException("`{$table}` isn't a valid table name.");
        }

        try {
            if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
                return array_map('strval', array_column($pdo->query("PRAGMA table_info(`{$table}`)")->fetchAll(PDO::FETCH_ASSOC), 'name'));
            }

            $stmt = $pdo->prepare(
                'SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t ORDER BY ORDINAL_POSITION'
            );
            $stmt->execute(['t' => $table]);

            return array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN));
        } catch (\Throwable $e) {
            throw new RuntimeException("Couldn't read the columns of `{$table}`: " . $e->getMessage(), 0, $e);
        }
    }

    /** True when the table can be opened. Reads no rows. */
    private static function readable(PDO $source, string $table): bool
    {
        try {
            $source->query("SELECT 1 FROM `{$table}` LIMIT 0")->fetchAll();
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /** The connected database's name, or '' when it can't be read. */
    private static function databaseName(PDO $pdo): string
    {
        try {
            return (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
        } catch (\Throwable) {
            return '';
        }
    }

    private static function isThisMachine(string $host): bool
    {
        return in_array($host, ['', 'localhost', '127.0.0.1', '::1', '[::1]'], true);
    }

    private static function decode(string $text): string
    {
        return html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}
