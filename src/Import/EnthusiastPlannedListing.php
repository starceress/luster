<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 The answer for one Enthusiast source listing during an import: where it goes, which
 member table that means, and whether we know where its members really are. Every
 phase reads this rather than working it out again.
*/

declare(strict_types=1);

namespace Luster\Import;

/**
 * One source listing's place in an EnthusiastListingPlan.
 *
 * targetId and result can change during a run: a new listing gets its id when its row is
 * saved, and a save that fails turns it into not imported, so later phases skip it.
 */
final class EnthusiastPlannedListing
{
    public const SAME_ROW     = 'same_row';
    public const EXISTING     = 'existing';
    public const NEW          = 'new';
    public const NOT_IMPORTED = 'not_imported';

    public function __construct(
        public readonly int $sourceId,
        public readonly string $title,
        public readonly string $url,
        /** The table name the source gives, with no dump prefix. */
        public readonly string $sourceTable,
        /** What the source says, less anything planning had to leave out of a new listing. */
        public EnthusiastSourceFields $fields,
        public string $result,
        public ?int $targetId,
        public ?string $targetTable,
        public string $reason = '',
        public readonly string $savedServer = '',
        public readonly string $savedDatabase = '',
        /** A note about the destination that isn't a refusal. */
        public string $note = '',
        /** True when source and target are proven to be one physical table. */
        public bool $sameTable = false,
        /** True when the listing's saved location matches what was picked. */
        public bool $locationConfirmed = true,
        /** True when the Preview offers this listing's location for confirmation. */
        public bool $offered = false,
        /** True once the owner has confirmed the offer for this run. */
        public bool $consented = false,
        public bool $sourceTableExists = false,
        public bool $targetTableExists = false,
    ) {}

    public function isImported(): bool
    {
        return $this->result !== self::NOT_IMPORTED;
    }

    /** True once this listing has a row to attach things to. */
    public function hasTarget(): bool
    {
        return $this->isImported() && $this->targetId !== null;
    }

    /** True when its members can be treated as being where it says. */
    public function membersAreHere(): bool
    {
        return $this->locationConfirmed || $this->consented;
    }

    /** True when members should really be copied from the source table. */
    public function canCopyMembers(): bool
    {
        return $this->isImported()
            && !$this->sameTable
            && $this->targetTable !== null
            && $this->membersAreHere()
            && $this->sourceTableExists;
    }

    public function isReadInPlace(): bool
    {
        return $this->isImported() && $this->sameTable;
    }

    /** A plain name for a message. */
    public function label(): string
    {
        return $this->title !== '' ? $this->title : ($this->sourceTable !== '' ? $this->sourceTable : '#' . $this->sourceId);
    }
}
