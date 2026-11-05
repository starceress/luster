<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 The one routine for approving a fan's queued update. Approve, Approve All, bulk
 Approve and the overview's Approve All all come through here, so they can't
 disagree about which changes are safe, what order they go in, or what happens
 when something fails. Each path keeps its own email and message.
*/

declare(strict_types=1);

namespace Luster\Support;

use DateTimeImmutable;
use DateTimeZone;
use Luster\Core\Application;
use Luster\Core\Config;
use Luster\Core\Database;
use Luster\Core\Mailer;
use Luster\Core\Validator;
use Luster\Models\Listing;
use Luster\Models\Member;
use Luster\Repositories\EmailTemplateRepo;
use Luster\Repositories\FieldRepo;
use Luster\Repositories\ListingRepo;
use Luster\Repositories\ListingSettingsRepo;
use Luster\Repositories\MemberLinkCheckRepo;
use Luster\Repositories\MemberRepo;
use Luster\Repositories\PendingUpdateRepo;
use RuntimeException;

/**
 * Applies one queued request.
 *
 * Everything is decided inside one transaction, on rows it has just locked, because a
 * request checked before the transaction can already be used up by another approval:
 *
 *   read the row, plain                 only to learn its addresses
 *   take the address locks
 *   BEGIN
 *     lock and re-read this request     and every other request at that address
 *     lock and read the member          the whole row, kept as the "before" copy
 *     run the checks, in order          the first that applies ends it
 *   an address change passes them all, so ROLLBACK, clear the links and stale rows at
 *   both addresses in their own statements, and go back to BEGIN to decide again
 *     move other requests, delete this request
 *     ---- nothing above this line can touch the member ----
 *     UPDATE the member, read them back, COMMIT
 *   link checks                         best-effort, logged
 *
 * The links can't go inside the transaction, because rollback would bring them back
 * after a member write that may have stuck. They can't go before the checks, or an
 * approval that's refused would still cost the fan their link. See MemberAddressHandover.
 *
 * The caller sends mail after apply() returns, with the locks gone. A check that writes
 * commits what it wrote, and one that doesn't rolls back. There's one catch, around
 * the whole transaction, and nothing carries on after it: see MemberEmailRekey for why,
 * and for how a failed write is told apart from one that may have landed.
 *
 * Every outcome but the last leaves the rest of a batch free to carry on:
 *
 *   applied       Written and read back. Send the email.
 *   gone          Nothing to apply: already handled, or the listing or member is gone.
 *   busy, moved   Couldn't be locked, or changed while locking. Still queued, try again.
 *   needs_review  Stopped for the owner. Still queued, with a hold.
 *   invalid       A value can't be applied. Still queued, for the owner to reject.
 *   unreadable    The member table couldn't be read. Still queued.
 *   nothing       Nothing left to change. Removed, with no email.
 *   conflict      The new address belongs to someone else. Removed.
 *   too_long      A value won't fit its column. Still queued.
 *   failed        A database error, and the member didn't change. Still queued, try again.
 *   unconfirmed   The write may have landed. Held if a hold could be stored. No email.
 *   unresolved    The transaction couldn't be closed. Do no more database work in this
 *                 request: anything read now would include its uncommitted changes.
 */
final class QueuedUpdateApplier
{
    public const HOLD_EXPLANATION = 'Check the member and make any changes you still want by hand, then reject this request to clear it. Rejecting only removes the request.';

    private const STAGE_CHECKS  = 'checks';
    private const STAGE_COLUMNS = 'columns';
    private const STAGE_WRITING = 'writing';

    private readonly PendingUpdateRepo $pendingRepo;
    private readonly ListingRepo $listingRepo;
    private readonly MemberRepo $memberRepo;
    private readonly FieldRepo $fieldRepo;
    private readonly MemberEmailRekey $rekey;
    private readonly ListingSettingsRepo $settingsRepo;

    /** @var array<int, true> Listings whose Last Updated this has already moved. */
    private array $touched = [];

    public function __construct(
        private readonly Database $db,
        private readonly Config $config,
        private readonly MemberAddressLock $lock,
    ) {
        $this->pendingRepo = new PendingUpdateRepo($db);
        $this->listingRepo = new ListingRepo($db, $config);
        $this->memberRepo  = new MemberRepo($db, $config);
        $this->fieldRepo   = new FieldRepo($db, $config);
        $this->rekey       = new MemberEmailRekey($db, $config);
        $this->settingsRepo = new ListingSettingsRepo($db);
    }

    public static function for(Application $app): self
    {
        return new self($app->getDatabase(), $app->getConfig(), $app->getMemberAddressLock());
    }

    /**
     * @return array{
     *     outcome: string, gone: ?string, reason: ?string, hold: ?string, held: int,
     *     pending: ?object, listing: ?Listing, member: ?Member, final: ?Member, moves_date: bool
     * } gone is handled, listing or member. reason says what's wrong with a value.
     *   hold is the reason a row is stopped, and held is how many rows a write marked.
     *   moves_date says what was applied counts towards the listing's Last Updated.
     */
    public function apply(int $id): array
    {
        if ($this->rekey->transactionIsOpen()) {
            return self::result('unresolved');
        }

        try {
            $firstLook = $this->pendingRepo->getById($id);
        } catch (\Throwable $e) {
            error_log('[Luster] Reading queued update #' . $id . ' failed: ' . $e->getMessage());
            return self::result('failed');
        }

        if ($firstLook === null) {
            return self::result('gone', ['gone' => 'handled']);
        }

        try {
            $result = $this->lock->run(self::addressesOf($firstLook), fn(): array => $this->applyLocked($id));
        } catch (MemberAddressBusy) {
            return self::result('busy', ['pending' => $firstLook]);
        }

        if ($this->rekey->transactionIsOpen()) {
            return ['outcome' => 'unresolved', 'hold' => null, 'held' => 0, 'final' => null] + $result;
        }

        // Only a change that really landed counts as the listing being updated, and a
        // batch only has to say so once per listing.
        if ($result['moves_date'] && !isset($this->touched[$result['pending']->fanlisting_id])) {
            $this->touched[$result['pending']->fanlisting_id] = true;
            $this->settingsRepo->touchLastUpdated($result['pending']->fanlisting_id);
        }

        return $result;
    }

    /**
     * Applies each request in turn, oldest first if that's the order given, and counts
     * what happened. One bad row never stops the rest, but a transaction that can't be
     * closed does, because nothing after it could be trusted.
     *
     * @param list<int> $ids
     * @return array<string, int> Outcome => how many. A gone row counts under handled,
     *                            or under discarded when its listing or member is gone.
     */
    public function applyAll(array $ids, bool $sendEmail): array
    {
        $tally = [];
        foreach ($ids as $id) {
            $result = $this->apply($id);

            $key = $result['outcome'];
            if ($key === 'gone') {
                $key = $result['gone'] === 'handled' ? 'handled' : 'discarded';
            }
            $tally[$key] = ($tally[$key] ?? 0) + 1;

            if ($key === 'unresolved') {
                break;
            }

            if ($sendEmail && $result['outcome'] === 'applied') {
                $this->sendConfirmation($result);
            }
        }

        return $tally;
    }

    /**
     * @param bool $wayIsClear True on the second pass, once an address change has passed
     *                         the checks and its links are gone.
     * @return array{outcome: string, gone: ?string, reason: ?string, hold: ?string, held: int, pending: ?object, listing: ?Listing, member: ?Member, final: ?Member, moves_date: bool}
     */
    private function applyLocked(int $id, bool $wayIsClear = false): array
    {
        $stage   = self::STAGE_CHECKS;
        $pending = null;
        $listing = null;
        $before  = null;
        $changes = [];

        try {
            $this->db->beginTransaction();

            $pending = $this->pendingRepo->lockById($id);
            if ($pending === null) {
                return $this->stopWithoutWriting(self::result('gone', ['gone' => 'handled']));
            }
            if (!$this->lock->holds(self::addressesOf($pending))) {
                return $this->stopWithoutWriting(self::result('moved', ['pending' => $pending]));
            }

            $atAddress = $this->pendingRepo->lockAtAddress($pending->fanlisting_id, $pending->member_email);

            if (PendingUpdateRepo::isHeld($pending)) {
                return $this->stopWithoutWriting(self::result('needs_review', ['pending' => $pending, 'hold' => $pending->review_state]));
            }

            $listing = $this->listingRepo->getById($pending->fanlisting_id);
            if ($listing === null) {
                return $this->removeRequest($id, self::result('gone', ['gone' => 'listing', 'pending' => $pending]));
            }

            $before = $this->memberRepo->lockRawRow($listing->dbprefix, $pending->member_email);
            if ($before === null) {
                if ($this->anAskedForAddressIsTaken($listing->dbprefix, $atAddress, true)) {
                    return $this->storeHold($pending, $listing, PendingUpdateRepo::ADDRESS_MOVED);
                }
                return $this->removeRequest($id, self::result('gone', ['gone' => 'member', 'pending' => $pending, 'listing' => $listing]));
            }

            $member = Member::fromRow($before);
            $base   = ['pending' => $pending, 'listing' => $listing, 'member' => $member];

            $hold = self::holdReason($pending, $member);
            if ($hold !== null) {
                return $this->storeHold($pending, $listing, $hold, $member);
            }

            if ($pending->malformed || !self::valuesAreFlat($pending->changes)) {
                return $this->stopWithoutWriting(self::result('invalid', $base + ['reason' => "the request couldn't be read"]));
            }

            $stage        = self::STAGE_COLUMNS;
            $widths       = $this->memberRepo->columnWidths($listing->dbprefix);
            $fields       = $this->fieldRepo->getFieldsForListing($pending->fanlisting_id);
            $fieldColumns = $this->fieldRepo->fieldColumns($listing->dbprefix, $fields);
            $stage        = self::STAGE_CHECKS;

            $changes = self::allowedChanges($pending->changes, array_keys($widths), $fieldColumns);
            $changes = self::withoutUnchanged($changes, $before);
            if ($changes === []) {
                return $this->removeRequest($id, self::result('nothing', $base));
            }

            $invalid = self::invalidReason($changes);
            if ($invalid !== null) {
                return $this->stopWithoutWriting(self::result('invalid', $base + ['reason' => $invalid]));
            }

            if ($this->addressBelongsToSomeoneElse($listing->dbprefix, $changes, $before)) {
                return $this->removeRequest($id, self::result('conflict', $base));
            }

            $tooLong = MemberRepo::tooLong($widths, $changes);
            if ($tooLong !== []) {
                return $this->stopWithoutWriting(self::result('too_long', $base + [
                    'reason' => 'the requested ' . self::labelFor($tooLong[0]) . " is too long for this listing's member table",
                ]));
            }

            if (isset($changes['email'])) {
                if (!$wayIsClear) {
                    $this->db->rollBack();
                    $this->rekey->clearTheWay($pending->fanlisting_id, $pending->member_email, (string) $changes['email']);
                    return $this->applyLocked($id, true);
                }
                $this->pendingRepo->renameMemberEmail($pending->fanlisting_id, $pending->member_email, (string) $changes['email']);
            }
            if (!$this->pendingRepo->delete($id)) {
                throw new RuntimeException('The queued request vanished while it was locked.');
            }

            // Nothing above this line can touch the member.
            $stage    = self::STAGE_WRITING;
            $finalRow = $this->rekey->writeAndCommit($listing->dbprefix, $pending->member_email, $changes);
        } catch (\Throwable $e) {
            error_log('[Luster] Applying queued update #' . $id . ' failed: ' . $e->getMessage());

            return match ($stage) {
                self::STAGE_WRITING => $this->afterFailedWrite($id, $pending, $listing, $before, $changes),
                self::STAGE_COLUMNS => $this->stopAfterFailure(self::result('unreadable', ['pending' => $pending, 'listing' => $listing])),
                default             => $this->stopAfterFailure(self::result('failed', ['pending' => $pending, 'listing' => $listing])),
            };
        }

        $final = Member::fromRow($finalRow);
        $this->syncLinkCheck($pending, $changes, $final);

        return self::result('applied', [
            'pending'    => $pending,
            'listing'    => $listing,
            'member'     => Member::fromRow($before),
            'final'      => $final,
            'moves_date' => ListingSettingsRepo::changesCountAsUpdate($changes),
        ]);
    }

    /**
     * After a failure at or after the member write. An email change holds every request
     * at the old address, because a name-only one alongside it couldn't spot the rename
     * by itself and would be thrown away as "member no longer exists". A request that
     * COMMIT really did use up is never recreated, so the hold can mark nothing at all.
     *
     * @param array<string, mixed> $changes
     */
    private function afterFailedWrite(int $id, object $pending, Listing $listing, array $before, array $changes): array
    {
        $base = ['pending' => $pending, 'listing' => $listing, 'member' => Member::fromRow($before)];

        $what = $this->rekey->rollBackAndCompare($listing->dbprefix, $pending->member_email, $before);
        if ($what === MemberEmailRekey::FAILED) {
            return self::result('failed', $base);
        }

        $held = 0;
        if (!$this->rekey->transactionIsOpen()) {
            $held = isset($changes['email'])
                ? $this->pendingRepo->holdAddress($pending->fanlisting_id, $pending->member_email, PendingUpdateRepo::WRITE_UNCONFIRMED)
                : $this->pendingRepo->holdRow($id, PendingUpdateRepo::WRITE_UNCONFIRMED);
        }

        return self::result('unconfirmed', $base + ['held' => $held, 'hold' => $held > 0 ? PendingUpdateRepo::WRITE_UNCONFIRMED : null]);
    }

    private function stopAfterFailure(array $result): array
    {
        $this->rekey->rollBack();

        return $result;
    }

    private function stopWithoutWriting(array $result): array
    {
        $this->db->rollBack();

        return $result;
    }

    private function removeRequest(int $id, array $result): array
    {
        $this->pendingRepo->delete($id);
        $this->db->commit();

        return $result;
    }

    /** The hold goes in its own statement, once the transaction has let go of its locks. */
    private function storeHold(object $pending, Listing $listing, string $state, ?Member $member = null): array
    {
        $this->db->rollBack();

        return self::result('needs_review', [
            'pending' => $pending,
            'listing' => $listing,
            'member'  => $member,
            'hold'    => $state,
            'held'    => $this->pendingRepo->holdRow($pending->id, $state),
        ]);
    }

    /**
     * Nobody is at this address, and a request here asks for an address someone now
     * holds. It concludes nothing about who's who, it just means an earlier save may
     * not have finished, so the request has to stay stopped whoever joins later.
     *
     * @param list<object> $atAddress Every request at the address.
     * @param bool         $locking   False for a page view, which takes no locks.
     */
    private function anAskedForAddressIsTaken(string $dbprefix, array $atAddress, bool $locking): bool
    {
        foreach ($atAddress as $row) {
            $asked = $row->changes['email'] ?? '';
            if (!is_string($asked) || trim($asked) === '' || MemberEmailRekey::sameAddress($asked, $row->member_email)) {
                continue;
            }

            $holder = $locking
                ? $this->memberRepo->lockRawRow($dbprefix, $asked)
                : $this->memberRepo->getRawRow($dbprefix, $asked);
            if ($holder !== null) {
                return true;
            }
        }

        return false;
    }

    /**
     * Only another member counts. Fan@example.com for fan@example.com is the member's
     * own address in other capitals. A rival who slips in after this hits the unique key,
     * which is an ordinary failed write.
     *
     * @param array<string, mixed> $changes
     * @param array<string, mixed> $before
     */
    private function addressBelongsToSomeoneElse(string $dbprefix, array $changes, array $before): bool
    {
        if (!isset($changes['email'])) {
            return false;
        }

        $holder = $this->memberRepo->lockRawRow($dbprefix, (string) $changes['email']);

        return $holder !== null && !MemberEmailRekey::sameAddress((string) $holder['email'], (string) $before['email']);
    }

    /**
     * Keeps the link-check row in step, after COMMIT. A failure here is logged and the
     * approval stands, because the next Link Checker sync rebuilds that row.
     *
     * @param array<string, mixed> $changes
     */
    private function syncLinkCheck(object $pending, array $changes, Member $final): void
    {
        if (isset($changes['email'])) {
            $this->rekey->moveLinkCheck($pending->fanlisting_id, $pending->member_email, $final->email);
        }
        if ($final->url === '') {
            return;
        }

        try {
            (new MemberLinkCheckRepo($this->db, $this->config))->upsertMember($pending->fanlisting_id, $final->email, $final->name, $final->url);
        } catch (\Throwable $e) {
            error_log('[Luster] Updating a link check after approving an update failed: ' . $e->getMessage());
        }
    }

    // -----------------------------------------------------------------------
    // The rules, with nothing to read or write
    // -----------------------------------------------------------------------

    /**
     * Why this request is stopped, or null if it isn't. The queue screen uses this too,
     * so a row that would be held shows as held before anyone clicks.
     *
     * Dates are a warning, not proof of who's who. A member's join date comes from
     * PHP's clock and a request's from the database's, and nothing keeps those in
     * step, so both rules allow one calendar day of slack.
     *
     * @param bool $addressMoved Only for a row with no member: an asked-for address is taken.
     */
    public static function holdReason(object $row, ?Member $member, bool $addressMoved = false): ?string
    {
        if (PendingUpdateRepo::isHeld($row)) {
            return $row->review_state;
        }

        if ($member === null) {
            return $addressMoved ? PendingUpdateRepo::ADDRESS_MOVED : null;
        }

        $requested = self::calendarDay((string) $row->submitted_at);
        $joined    = self::calendarDay($member->added);

        // Queued when removals still left requests behind. Someone who joined around
        // then, or whose date can't be read, could be a replacement. That can't be told
        // apart from a real member who joined just before asking, so the owner decides.
        if (($row->review_state ?? null) === PendingUpdateRepo::QUEUED_BEFORE_CHECKS) {
            if ($requested === null || $joined === null || $joined >= $requested - 1) {
                return PendingUpdateRepo::MAY_BE_OTHER_MEMBER;
            }
            return null;
        }

        if ($requested !== null && $joined !== null && $joined > $requested + 1) {
            return PendingUpdateRepo::JOINED_AFTER_REQUEST;
        }

        return null;
    }

    /** A stored date as a day number, or null when it's blank, zero or can't be read. */
    private static function calendarDay(string $value): ?int
    {
        $day  = substr(trim($value), 0, 10);
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $day, new DateTimeZone('UTC'));

        // createFromFormat() turns 0000-00-00 into a real date without complaining, so a
        // value only counts if it reads back exactly the same.
        if ($date === false || $date->format('Y-m-d') !== $day) {
            return null;
        }

        return (int) floor($date->getTimestamp() / 86400);
    }

    /** @param array<int|string, mixed> $changes */
    private static function valuesAreFlat(array $changes): bool
    {
        foreach ($changes as $value) {
            if (!is_string($value) && !is_int($value)) {
                return false;
            }
        }

        return true;
    }

    /**
     * What a queued request may change. In this order, so a queued "name" is always the
     * member's name and never a field's:
     *
     *  1. The supported core keys stay core.
     *  2. Every other core column is dropped, like pending, password and added.
     *  3. What's left may be a field. A key matching one of this listing's field columns,
     *     ignoring case, gets its real spelling. A key that's a definition's own name
     *     goes through the map, so a request queued before the map existed keeps its answer.
     *  4. Anything else is dropped, including a field deleted since it was queued.
     *
     * @param array<int|string, mixed> $changes
     * @param array<int|string>        $columnNames  The member table's real columns.
     * @param array<string, ?string>   $fieldColumns FieldRepo::fieldColumns().
     * @return array<string, string|int> Real column => value.
     */
    public static function allowedChanges(array $changes, array $columnNames, array $fieldColumns): array
    {
        $realColumn = [];
        foreach ($columnNames as $column) {
            $realColumn[strtolower((string) $column)] = (string) $column;
        }

        $fieldByColumn = [];
        $fieldByName   = [];
        foreach ($fieldColumns as $name => $column) {
            if ($column !== null) {
                $fieldByColumn[strtolower($column)]       = $column;
                $fieldByName[strtolower((string) $name)] = $column;
            }
        }

        $core = [];
        $byColumn = [];
        $byName = [];
        foreach ($changes as $key => $value) {
            $lower = strtolower((string) $key);

            if (in_array($lower, PendingUpdateRepo::CORE_KEYS, true)) {
                if (isset($realColumn[$lower])) {
                    $core[$realColumn[$lower]] = $value;
                }
            } elseif (in_array($lower, FieldColumnMap::CORE_COLUMNS, true)) {
                continue;
            } elseif (isset($fieldByColumn[$lower])) {
                $byColumn[$fieldByColumn[$lower]] = $value;
            } elseif (isset($fieldByName[$lower])) {
                $byName[$fieldByName[$lower]] = $value;
            }
        }

        // A key naming the column beats one that only names the field.
        return $core + $byColumn + $byName;
    }

    /**
     * @param array<string, string|int> $changes
     * @param array<string, mixed>      $current The member's row.
     * @return array<string, string|int>
     */
    public static function withoutUnchanged(array $changes, array $current): array
    {
        foreach ($changes as $column => $value) {
            if (array_key_exists($column, $current) && (string) $current[$column] === (string) $value) {
                unset($changes[$column]);
            }
        }

        return $changes;
    }

    /**
     * What's wrong with a value this request really changes, or null. Blank means "keep"
     * on both forms, so a blank name, email or country can't be a real request.
     * A member with a blank country isn't affected by an unrelated update, because
     * withoutUnchanged() has already dropped anything equal to the current row.
     *
     * This checks the exact text that gets saved. Extra spaces round a value are gone
     * before it gets here: see PendingUpdateRepo::hydrate().
     *
     * @param array<string, string|int> $changes
     */
    public static function invalidReason(array $changes): ?string
    {
        foreach ($changes as $column => $value) {
            $text = (string) $value;

            switch ((string) $column) {
                case 'name':
                    if (trim($text) === '') {
                        return 'the requested name is blank';
                    }
                    break;
                case 'country':
                    if (trim($text) === '') {
                        return 'the requested country is blank';
                    }
                    break;
                case 'email':
                    if ($text === '' || filter_var($text, FILTER_VALIDATE_EMAIL) === false) {
                        return "the requested email isn't valid";
                    }
                    break;
                case 'url':
                    // Blank passes: it's the form's "remove my website".
                    $v = new Validator(['url' => $text]);
                    $v->field('url')->httpUrl();
                    if ($v->fails()) {
                        return "the requested website address isn't valid";
                    }
                    break;
                case 'showurl':
                    if ($text !== '0' && $text !== '1') {
                        return "the requested show-website setting isn't valid";
                    }
                    break;
            }
        }

        return null;
    }

    /**
     * The address a queued request is at, and the one it asks for.
     *
     * @return list<array{0: int, 1: string}>
     */
    private static function addressesOf(object $pending): array
    {
        $addresses = [[$pending->fanlisting_id, $pending->member_email]];
        $requested = $pending->changes['email'] ?? '';
        if (is_string($requested) && $requested !== '') {
            $addresses[] = [$pending->fanlisting_id, $requested];
        }

        return $addresses;
    }

    private static function labelFor(string $column): string
    {
        return match ($column) {
            'url'     => 'website address',
            'showurl' => 'show-website setting',
            default   => $column,
        };
    }

    /** @param array<string, mixed> $values */
    private static function result(string $outcome, array $values = []): array
    {
        return $values + [
            'outcome' => $outcome,
            'gone'    => null,
            'reason'  => null,
            'hold'    => null,
            'held'    => 0,
            'pending' => null,
            'listing' => null,
            'member'  => null,
            'final'   => null,
            'moves_date' => false,
        ];
    }

    // -----------------------------------------------------------------------
    // What the admin and the fan get told
    // -----------------------------------------------------------------------

    /**
     * Why a request is stopped, for the queue screen, including one that isn't marked yet.
     * It's only a label and apply() decides for itself, so a failed read shows no hold.
     */
    public function holdForScreen(object $row, ?Member $member, string $dbprefix): ?string
    {
        if ($member !== null || PendingUpdateRepo::isHeld($row)) {
            return self::holdReason($row, $member);
        }

        try {
            $atAddress = $this->pendingRepo->getAtAddress($row->fanlisting_id, $row->member_email);
            $moved     = $this->anAskedForAddressIsTaken($dbprefix, $atAddress, false);
        } catch (\Throwable $e) {
            error_log('[Luster] Checking a queued update for the queue screen failed: ' . $e->getMessage());
            return null;
        }

        return self::holdReason($row, null, $moved);
    }

    public static function holdLabel(string $state): string
    {
        return $state === PendingUpdateRepo::WRITE_UNCONFIRMED
            ? 'Needs review — may be partly applied'
            : 'Needs review — approval stopped';
    }

    public static function holdText(string $state): string
    {
        return match ($state) {
            PendingUpdateRepo::ADDRESS_MOVED        => 'Nobody is at this address now, and someone is at the address this request asks for, so an earlier save may not have finished.',
            PendingUpdateRepo::MAY_BE_OTHER_MEMBER  => "This request was queued before Luster started removing requests along with their member, and this member joined around the same time or later, so Luster can't tell it's the same person.",
            PendingUpdateRepo::JOINED_AFTER_REQUEST => 'This member joined more than a day after this request was sent, so it probably came from someone else.',
            PendingUpdateRepo::WRITE_UNCONFIRMED    => "Luster couldn't confirm this update finished, so the member may already have some of these changes.",
            default                                 => '',
        };
    }

    /**
     * What to tell the admin after approving one request, for anything but applied.
     *
     * @param array{outcome: string, gone: ?string, reason: ?string, hold: ?string, held: int} $result
     */
    public static function message(array $result): string
    {
        return match ($result['outcome']) {
            'gone' => match ($result['gone']) {
                'listing' => 'Associated listing no longer exists.',
                'member'  => 'Member no longer exists — update discarded.',
                default   => 'That update was already handled.',
            },
            'busy'         => MemberAddressBusy::MESSAGE,
            'moved'        => 'That update changed while it was being approved. Try again.',
            'needs_review' => "This update needs your review, so it wasn't applied. " . self::holdText((string) $result['hold']),
            'invalid'      => "Couldn't apply this: {$result['reason']}. It's still in the queue.",
            'too_long'     => "Couldn't apply this: {$result['reason']}. It's still in the queue.",
            'unreadable'   => "Couldn't check this listing's member table because of a database error, so nothing was changed. It's still in the queue.",
            'nothing'      => 'That request had nothing left to change, so it was removed.',
            'conflict'     => 'Cannot apply update — the requested email is already taken by another member. Update discarded.',
            'failed'       => "Couldn't apply this update because of a database error. Nothing was changed, so you can try again.",
            'unconfirmed'  => $result['held'] > 0
                ? "Luster couldn't confirm this update finished, so the member may already have some of these changes. It's being held in the queue for you to check."
                : "Luster couldn't confirm this update finished. Check this member's details, and make any changes you still want by hand.",
            'unresolved'   => "A database problem stopped this approval partway, and Luster can't tell what was saved. Reload the queue and check this member's details before trying again.",
            default        => '',
        };
    }

    /**
     * What a batch needs to add to its own "Approved N" line.
     *
     * @param array<string, int> $tally From applyAll().
     */
    public static function batchNotes(array $tally): string
    {
        $count = static fn(string $key): int => $tally[$key] ?? 0;
        $stillQueued = $count('invalid') + $count('too_long') + $count('unreadable');

        $notes = [];
        if ($count('needs_review') > 0) {
            $notes[] = $count('needs_review') . ' need' . ($count('needs_review') === 1 ? 's' : '') . ' review and stayed in the queue.';
        }
        if ($count('conflict') > 0) {
            $notes[] = 'Skipped ' . $count('conflict') . ' because the requested email was already taken.';
        }
        if ($stillQueued > 0) {
            $notes[] = $stillQueued . " couldn't be applied and stayed in the queue. Approve one by itself to see why.";
        }
        if ($count('nothing') > 0) {
            $notes[] = 'Removed ' . $count('nothing') . ' with nothing left to change.';
        }
        if ($count('discarded') > 0) {
            $notes[] = 'Discarded ' . $count('discarded') . ' because the member or listing no longer exists.';
        }
        if ($count('busy') + $count('moved') > 0) {
            $notes[] = ($count('busy') + $count('moved')) . " couldn't be applied just now, so they're still in the queue. Try again in a moment.";
        }
        if ($count('failed') > 0) {
            $notes[] = $count('failed') . " failed because of a database error. Nothing was changed on those, so you can try again.";
        }
        if ($count('unconfirmed') > 0) {
            $notes[] = 'Luster couldn\'t confirm ' . $count('unconfirmed') . " finished, so check those members' details.";
        }
        if ($count('unresolved') > 0) {
            $notes[] = "Luster stopped here because a database problem left its work unfinished, so the rest weren't tried. Reload the queue before approving more, because one member may already have some of their changes.";
        }

        return implode(' ', $notes);
    }

    /**
     * Tells the fan their update went through, at their final address. Best-effort: the
     * update stands whatever happens here. Call it after apply(), with the locks gone.
     *
     * @param array{listing: ?Listing, pending: ?object, final: ?Member} $result An applied result.
     */
    public function sendConfirmation(array $result): void
    {
        $listing = $result['listing'];
        $final   = $result['final'];
        if ($listing === null || $final === null) {
            return;
        }

        try {
            $listingId = $result['pending']->fanlisting_id;
            $settings  = new ListingSettingsRepo($this->db);
            $embed     = $settings->getResolvedEmbedSettings($listingId);
            $tokens    = EmailTemplateRepo::buildTokens(
                listingName:    $listing->title,
                listingUrl:     $listing->url,
                siteName:       (string) $this->config->get('site_name', 'Luster'),
                listingSubject: $listing->subject,
                ownerName:      (string) $this->config->get('owner_name', ''),
                ownerEmail:     (string) $this->config->get('owner_email', ''),
                fanName:        $final->name,
                fanEmail:       $final->email,
                listingEmail:   $listing->email,
                formUrlJoin:    (string) $embed['form_url_join'],
                formUrlUpdate:  (string) $embed['form_url_update'],
                formUrlReset:   (string) $embed['form_url_reset'],
                listingType:    $settings->getListingType($listingId),
            );
            [$subject, $body] = (new EmailTemplateRepo($this->db))->resolveForListing($listingId, EmailTemplateRepo::TYPE_UPDATE, $tokens);
            if ($subject !== '') {
                (new Mailer($this->config))->send($final->email, $final->name, $subject, $body);
            }
        } catch (\Throwable $e) {
            error_log('[Luster] Approved-update email failed for ' . $final->email . ': ' . $e->getMessage());
        }
    }
}
