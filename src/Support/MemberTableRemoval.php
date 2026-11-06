<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Works out what deleting a listing does to its member table, before anything is
 deleted. A table another listing also uses stays, because dropping it would take that
 listing's fans too. A table that's really one of Luster's or Enthusiast's own, or one
 whose other users can't be checked, stops the delete altogether.
*/

declare(strict_types=1);

namespace Luster\Support;

use Luster\Repositories\ListingRepo;
use RuntimeException;

/**
 * The decision about one listing's member table, made while nothing has been touched.
 *
 * The physical table is worked out the same way MemberRepo does, so on the enth_ layout
 * it's {dbprefix}members, not the prefix.
 */
final class MemberTableRemoval
{
    public const NOTHING = 'nothing';
    public const DROP    = 'drop';
    public const KEEP    = 'keep';

    /** @param list<int> $otherListings */
    private function __construct(
        public readonly string $action,
        public readonly string $table = '',
        public readonly array $otherListings = [],
    ) {}

    /**
     * @throws RuntimeException With the message to show, when the delete must not go ahead.
     */
    public static function decide(ListingRepo $listings, int $listingId, string $dbprefix): self
    {
        if ($dbprefix === '' || preg_match('/^[a-zA-Z0-9_]+$/', $dbprefix) !== 1) {
            return new self(self::NOTHING);
        }

        $table = $listings->physicalMemberTable($dbprefix);

        if (ProtectedTables::contains($table)) {
            throw new RuntimeException(
                "This listing's member table is `{$table}`, which Luster or Enthusiast needs for something else. Nothing was deleted. Fix the listing's table name first."
            );
        }

        try {
            $others = $listings->otherListingsUsingMemberTable($listingId, $table);
        } catch (\Throwable $e) {
            throw new RuntimeException(
                "Luster couldn't check whether another listing also uses the member table `{$table}`, so nothing was deleted. " . $e->getMessage(),
                0,
                $e,
            );
        }

        return $others === []
            ? new self(self::DROP, $table)
            : new self(self::KEEP, $table, $others);
    }

    /** What to add to the success message, or '' when there's nothing to say. */
    public function note(): string
    {
        if ($this->action !== self::KEEP) {
            return '';
        }
        $ids = implode(' and ', array_map(static fn(int $id): string => '#' . $id, $this->otherListings));

        return " Its member table `{$this->table}` was kept, because listing {$ids} uses it too.";
    }
}
