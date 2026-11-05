<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Clears what's left at an address before someone new gets it. A reset link or a
 Collective Update link works on whoever is at the address when it's used, so an old
 one must not still be there for the next person. Join, Add Member and every change
 of address come through here.
*/

declare(strict_types=1);

namespace Luster\Support;

use Luster\Core\Application;
use Luster\Core\Database;
use Luster\Repositories\CollectiveUpdateTokenRepo;
use Luster\Repositories\FanPasswordResetRepo;
use Luster\Repositories\PendingUpdateRepo;

/**
 * Call this while holding the address lock, before the member is written, and never
 * inside a transaction. A MyISAM member can keep a change when the transaction around it
 * rolls back, and a crash can land between the two, so a link deleted inside that
 * transaction could come back with someone new at the address. Nothing here can be
 * undone by a failure later on, and the worst that costs is asking for a new link.
 *
 * A Collective Update link isn't tied to a listing, so this ends it everywhere that
 * address is a member, not only in this listing.
 */
final class MemberAddressHandover
{
    public function __construct(private readonly Database $db) {}

    public static function for(Application $app): self
    {
        return new self($app->getDatabase());
    }

    /** @throws \RuntimeException if anything can't be cleared. Don't go on to write the member. */
    public function clear(int $listingId, string $email): void
    {
        (new PendingUpdateRepo($this->db))->deleteStale($listingId, $email);
        (new FanPasswordResetRepo($this->db))->deleteByEmail($listingId, $email);
        (new CollectiveUpdateTokenRepo($this->db))->deleteByEmail($email);
    }
}
