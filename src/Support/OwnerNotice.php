<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Decides where an email telling the owner about a public form goes, sends it,
 and puts anything that goes wrong in the admin panel's Error Log.
*/

declare(strict_types=1);

namespace Luster\Support;

use Luster\Core\Application;
use Luster\Core\Config;
use Luster\Core\Database;
use Luster\Core\Mailer;
use Luster\Models\Listing;
use Luster\Repositories\EmailTemplateRepo;
use Luster\Repositories\ErrorLogRepo;

/**
 * The owner's notices from public forms: a new member, an update, a code donation,
 * and so on. These are best-effort. By the time one goes out the fan's own part is
 * saved, so nothing here throws.
 *
 * Where they go: the Email saved on the listing, then the Contact Email under
 * Settings > Owner Information (the owner_email config key). Never the address the
 * owner logs in with, which is a separate setting on their admin account.
 *
 * The address is worked out each time and never saved. Listing saves write
 * $listing->email back to the table, so it has to stay what the owner typed.
 */
final class OwnerNotice
{
    private const SENT       = 'sent';
    private const OFF        = 'off';
    private const NO_ADDRESS = 'no address';
    private const FAILED     = 'failed';

    public function __construct(
        private readonly Database $db,
        private readonly Config $config,
    ) {}

    public static function for(Application $app): self
    {
        return new self($app->getDatabase(), $app->getConfig());
    }

    /** The listing's own Email, otherwise the Contact Email. Null means the collective. */
    public function addressFor(?Listing $listing): string
    {
        $address = $listing !== null ? trim($listing->email) : '';

        return $address !== '' ? $address : trim((string) $this->config->get('owner_email', ''));
    }

    /**
     * Sends one notice. A notice someone switched off stays off, even with no address
     * to send it to, because that isn't a problem to report.
     *
     * @param ?int                  $scope       The listing whose templates apply, or null for the collective's.
     * @param array<string, string> $tokens
     * @param string                $what        Names the notice in the Error Log. Never a fan's details.
     * @param list<string>          $attachments
     */
    public function send(?int $scope, string $type, array $tokens, string $to, string $toName, string $what, array $attachments = []): void
    {
        $this->attempt($scope, $type, $tokens, $to, $toName, $what, $attachments);
    }

    /**
     * Sends a collective-scope notice about several listings: one email per address,
     * each covering only the listings that use that address.
     *
     * A fan can use a different name on each listing, and one owner shouldn't learn what
     * they go by on someone else's. So who the fan is comes with each entry as its
     * "identity" tokens, and an address only sees the ones from its own entries. $tokens
     * is only for what every address may see.
     *
     * It stops at the first failed delivery. A mail server that's down takes 15 seconds
     * or more to give up, and a fan shouldn't sit through that once per address.
     *
     * @param array<string, string> $tokens
     * @param list<array{listing: ?Listing, heading: string, lines: list<string>, identity: array<string, string>}> $entries
     */
    public function sendForEachAddress(string $type, array $tokens, array $entries, string $toName, string $what): void
    {
        $byAddress = [];
        foreach ($entries as $entry) {
            if ($entry['lines'] !== []) {
                $byAddress[$this->addressFor($entry['listing'])][] = $entry;
            }
        }

        $left = count($byAddress);
        foreach ($byAddress as $address => $group) {
            $left--;
            // {changes} goes last: tokens are filled in order, so nothing a fan typed gets filled in.
            $tokensHere = array_merge($tokens, self::identityTokens($group), ['{changes}' => MemberChangeList::forSeveralListings($group)]);
            $outcome    = $this->attempt(null, $type, $tokensHere, (string) $address, $toName, $what, []);
            if ($outcome === self::FAILED) {
                if ($left > 0) {
                    $this->record("Didn't try the {$what} for {$left} more address(es), since the one before it failed.");
                }
                return;
            }
        }
    }

    /**
     * The identity tokens for one address's entries. An owner with several of the listings
     * sees each different value once, in the order the entries came.
     *
     * @param list<array{identity: array<string, string>}> $entries
     * @return array<string, string>
     */
    private static function identityTokens(array $entries): array
    {
        $values = [];
        foreach ($entries as $entry) {
            foreach ($entry['identity'] as $token => $value) {
                $values[$token] ??= [];
                if ($value !== '' && !in_array($value, $values[$token], true)) {
                    $values[$token][] = $value;
                }
            }
        }

        return array_map(static fn(array $list): string => implode(', ', $list), $values);
    }

    /** Records that a notice failed, for when the sending happens outside this class. */
    public function reportFailure(string $what, \Throwable $e): void
    {
        $this->record("Couldn't send the {$what}: " . $e->getMessage());
    }

    /** Records that there's no address for a notice, for when the sending happens outside this class. */
    public function reportNoAddress(string $what): void
    {
        $this->record("Nowhere to send the {$what}. Add a Contact Email under Settings > Owner Information.");
    }

    /**
     * @param array<string, string> $tokens
     * @param list<string>          $attachments
     * @return self::SENT|self::OFF|self::NO_ADDRESS|self::FAILED
     */
    private function attempt(?int $scope, string $type, array $tokens, string $to, string $toName, string $what, array $attachments): string
    {
        try {
            [$subject, $body] = (new EmailTemplateRepo($this->db))->resolveForListing($scope, $type, $tokens);
            if ($subject === '') {
                return self::OFF;
            }
            if ($to === '') {
                $this->reportNoAddress($what);
                return self::NO_ADDRESS;
            }

            (new Mailer($this->config))->send($to, $toName, $subject, $body, $attachments);

            return self::SENT;
        } catch (\Throwable $e) {
            $this->reportFailure($what, $e);
            return self::FAILED;
        }
    }

    /** The panel's Error Log and PHP's. If the database is what's wrong, PHP's is all there is. */
    private function record(string $message): void
    {
        error_log('[Luster] ' . $message);

        try {
            (new ErrorLogRepo($this->db))->log($message, 'error', 'OwnerNotice');
        } catch (\Throwable) {
            // Already in PHP's log.
        }
    }
}
