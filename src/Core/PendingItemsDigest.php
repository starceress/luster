<?php

declare(strict_types=1);

namespace Luster\Core;

use Luster\Addons\Adoption\AdoptionRepo;
use Luster\Addons\Kim\KimMemberRepo;
use Luster\Repositories\AffiliateRepo;
use Luster\Repositories\CodesRepo;
use Luster\Repositories\ListingRepo;
use Luster\Repositories\MemberRepo;
use Luster\Repositories\PendingUpdateRepo;

/**
 * Gathers and formats the weekly pending-items digest email.
 *
 * Aggregates pending counts from all collective data sources:
 * members (per-listing), affiliate applications, code submissions,
 * member update requests, KIM sign-ups (optional), and adoption
 * applications (optional). Construct with addon-installed flags so
 * callers don't need to inject the addon repos themselves.
 */
final class PendingItemsDigest
{
    public function __construct(
        private readonly Database $db,
        private readonly Config   $config,
        private readonly bool     $kimInstalled,
        private readonly bool     $adoptionInstalled,
    ) {}

    /**
     * Gathers pending-item counts across the whole collective.
     *
     * The 'members.by_listing' sub-array only includes listings with at least
     * one pending member; listings with zero pending are omitted.
     *
     * The 'kim' and 'adoption' keys are null when the respective add-on is
     * not installed, and an array with a 'total' key when it is.
     *
     * @return array{
     *     members: array{total: int, by_listing: list<array{subject: string, count: int}>},
     *     affiliates: array{total: int},
     *     codes: array{total: int},
     *     updates: array{total: int},
     *     kim: ?array{total: int},
     *     adoption: ?array{total: int},
     *     grand_total: int,
     * }
     */
    public function gather(): array
    {
        $listingRepo = new ListingRepo($this->db, $this->config);
        $memberRepo  = new MemberRepo($this->db, $this->config);

        $membersTotal = 0;
        $byListing    = [];

        foreach ($listingRepo->getAll() as $listing) {
            if ($listing->dbprefix === '') {
                continue;
            }
            $count = $memberRepo->countPending($listing->dbprefix);
            if ($count > 0) {
                $byListing[]   = ['subject' => $listing->subject, 'count' => $count];
                $membersTotal += $count;
            }
        }

        $affiliatesTotal = (new AffiliateRepo($this->db))->getPendingCount();
        $codesTotal      = (new CodesRepo($this->db))->countPendingAll();
        $updatesTotal    = (new PendingUpdateRepo($this->db))->countAll();

        $kim = null;
        if ($this->kimInstalled) {
            $kimTotal = (int) array_sum((new KimMemberRepo($this->db))->getPendingCounts());
            $kim      = ['total' => $kimTotal];
        }

        $adoption = null;
        if ($this->adoptionInstalled) {
            $adoption = ['total' => (new AdoptionRepo($this->db))->countNew()];
        }

        $grandTotal = $membersTotal + $affiliatesTotal + $codesTotal + $updatesTotal
            + ($kim['total'] ?? 0) + ($adoption['total'] ?? 0);

        return [
            'members'     => ['total' => $membersTotal, 'by_listing' => $byListing],
            'affiliates'  => ['total' => $affiliatesTotal],
            'codes'       => ['total' => $codesTotal],
            'updates'     => ['total' => $updatesTotal],
            'kim'         => $kim,
            'adoption'    => $adoption,
            'grand_total' => $grandTotal,
        ];
    }

    /**
     * Renders the digest email body from gathered data.
     *
     * Loads templates/email/admin-pending-digest.txt when available, substituting
     * {items}, {collective_name}, {admin_url}, and {total}. Falls back to an
     * inline body when the template file cannot be read.
     */
    public function renderEmailBody(array $data, string $siteName, string $adminUrl): string
    {
        $lines = [];

        if ($data['members']['total'] > 0) {
            $lines[] = '- Members awaiting approval: ' . $data['members']['total'];
            foreach ($data['members']['by_listing'] as $entry) {
                $lines[] = '    - ' . $entry['subject'] . ': ' . $entry['count'];
            }
        }
        if ($data['affiliates']['total'] > 0) {
            $lines[] = '- Affiliate applications awaiting review: ' . $data['affiliates']['total'];
        }
        if ($data['codes']['total'] > 0) {
            $lines[] = '- Code submissions awaiting review: ' . $data['codes']['total'];
        }
        if ($data['updates']['total'] > 0) {
            $lines[] = '- Member update requests awaiting review: ' . $data['updates']['total'];
        }
        if ($data['kim'] !== null && $data['kim']['total'] > 0) {
            $lines[] = '- Keep In Mind sign-ups awaiting approval: ' . $data['kim']['total'];
        }
        if ($data['adoption'] !== null && $data['adoption']['total'] > 0) {
            $lines[] = '- Adoption applications awaiting review: ' . $data['adoption']['total'];
        }

        $items    = implode("\n", $lines);
        $tplPath  = defined('LUSTER_BASE') ? LUSTER_BASE . '/templates/email/admin-pending-digest.txt' : '';
        if ($tplPath !== '' && is_file($tplPath)) {
            return str_replace(
                ['{items}', '{collective_name}', '{admin_url}', '{total}'],
                [$items, $siteName, $adminUrl, (string) $data['grand_total']],
                (string) file_get_contents($tplPath),
            );
        }

        return "Pending items on {$siteName}:\n\n"
             . $items
             . "\n\nTotal pending items: {$data['grand_total']}"
             . "\n\nLog in to review: {$adminUrl}";
    }
}
