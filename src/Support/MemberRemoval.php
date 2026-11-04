<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 The one way to remove a member. Removing one takes their queued requests and
 reset links with them, because whoever gets that address next must not inherit
 them. Every removal path goes through here, and a test keeps it that way.
*/

declare(strict_types=1);

namespace Luster\Support;

use Luster\Core\Application;
use Luster\Core\Config;
use Luster\Core\Database;
use Luster\Repositories\FanPasswordResetRepo;
use Luster\Repositories\MemberLinkCheckRepo;
use Luster\Repositories\MemberRepo;
use Luster\Repositories\PendingUpdateRepo;

/**
 * The requests go first and there's no transaction, on purpose: a request can't
 * outlive its member on either engine. If deleting the requests fails, nothing
 * else happens. If deleting the member fails, they stay without those requests,
 * which the owner was removing anyway, and a retry finishes the job.
 */
final class MemberRemoval
{
    public function __construct(
        private readonly Database $db,
        private readonly Config $config,
        private readonly MemberAddressLock $lock,
    ) {}

    public static function for(Application $app): self
    {
        return new self($app->getDatabase(), $app->getConfig(), $app->getMemberAddressLock());
    }

    /**
     * @throws MemberAddressBusy if the address is busy. Nothing has changed.
     */
    public function remove(int $listingId, string $dbprefix, string $email): void
    {
        $this->lock->run([[$listingId, $email]], function () use ($listingId, $dbprefix, $email): void {
            (new PendingUpdateRepo($this->db))->deleteByListingAndEmail($listingId, $email);
            (new FanPasswordResetRepo($this->db))->deleteByEmail($listingId, $email);
            (new MemberRepo($this->db, $this->config))->deleteByEmail($dbprefix, $email);

            try {
                (new MemberLinkCheckRepo($this->db, $this->config))->removeMember($listingId, $email);
            } catch (\Throwable $e) {
                error_log('[Luster] Removing a link check failed: ' . $e->getMessage());
            }
        });
    }

    /**
     * Rejects a member. On the layout with no rejected state that removes them, and
     * on the other they keep their address, so nothing else moves.
     *
     * @return bool True when the member was removed outright.
     * @throws MemberAddressBusy if the address is busy. Nothing has changed.
     */
    public function reject(int $listingId, string $dbprefix, string $email): bool
    {
        $memberRepo = new MemberRepo($this->db, $this->config);
        if ($memberRepo->supportsRejectedState()) {
            $memberRepo->reject($dbprefix, $email);
            return false;
        }

        $this->remove($listingId, $dbprefix, $email);
        return true;
    }
}
