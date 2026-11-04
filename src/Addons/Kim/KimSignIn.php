<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 What a KIM Update sign-in can reach: only the entries whose password it matched.
 KIM signups aren't checked against the mailbox, and approving one doesn't check
 it either, so sharing an address proves nothing. One entry's password must not
 unlock another's.
*/

declare(strict_types=1);

namespace Luster\Addons\Kim;

use Luster\Core\Session;

/**
 * The session keeps the id and stamp of each entry the password matched. An entry
 * stays reachable only while it still exists, its hash matches that stamp, and it's
 * still at the address that signed in. Its id staying the same doesn't prove any of
 * that: an entry can be moved to someone else without its password changing.
 *
 * To manage entries with different passwords, a fan signs in with each, or uses
 * Forgot Password, which emails their mailbox one link per approved entry.
 */
final class KimSignIn
{
    public const EMAIL_KEY     = 'lstr_kim_update_authed';
    private const ENTRIES_KEY  = 'lstr_kim_update_entries';
    public const SIGN_IN_AGAIN = 'Please sign in again.';

    private function __construct() {}

    public static function stamp(string $passwordHash): string
    {
        return hash('sha256', $passwordHash);
    }

    /** @param KimMember[] $entries The entries the password matched. */
    public static function remember(Session $session, string $email, array $entries): void
    {
        $stamps = [];
        foreach ($entries as $entry) {
            $stamps[$entry->id] = self::stamp($entry->password);
        }
        $session->set(self::EMAIL_KEY, $email);
        $session->set(self::ENTRIES_KEY, $stamps);
    }

    public static function forget(Session $session): void
    {
        $session->remove(self::EMAIL_KEY);
        $session->remove(self::ENTRIES_KEY);
    }

    /** The address that signed in, or ''. It proves nothing until checked. */
    public static function email(Session $session): string
    {
        return (string) $session->get(self::EMAIL_KEY, '');
    }

    /**
     * The entries this sign-in can still reach, from a fresh read. Any that can't be
     * reached any more are dropped from the session, and with none left the sign-in ends.
     *
     * @return KimMember[]
     */
    public static function currentEntries(KimMemberRepo $repo, Session $session): array
    {
        $stamps = self::stamps($session);

        $current = [];
        $keep    = [];
        foreach ($repo->findByIds(array_keys($stamps)) as $entry) {
            if (self::canReach($entry, $session)) {
                $current[]         = $entry;
                $keep[$entry->id]  = $stamps[$entry->id];
            }
        }

        if ($keep === []) {
            self::forget($session);
        } elseif (count($keep) !== count($stamps)) {
            $session->set(self::ENTRIES_KEY, $keep);
        }

        return $current;
    }

    /** True when this sign-in can still reach $entry. Use it on a locked read before a write. */
    public static function canReach(KimMember $entry, Session $session): bool
    {
        $stamp = self::stamps($session)[$entry->id] ?? '';

        return $stamp !== ''
            && hash_equals($stamp, self::stamp($entry->password))
            && mb_strtolower($entry->email, 'UTF-8') === mb_strtolower(self::email($session), 'UTF-8');
    }

    /**
     * Keeps the session in step with a save it just committed.
     *
     * @param int[]   $removedIds    Entries the save deleted.
     * @param int[]   $changedIds    Entries it gave a new password.
     * @param ?string $passwordHash  The hash it wrote, not one read back afterwards.
     * @return bool True when the sign-in still reaches something.
     */
    public static function afterSave(Session $session, array $removedIds, array $changedIds, ?string $passwordHash): bool
    {
        $stamps = self::stamps($session);
        foreach ($removedIds as $id) {
            unset($stamps[$id]);
        }
        if ($passwordHash !== null) {
            foreach ($changedIds as $id) {
                $stamps[$id] = self::stamp($passwordHash);
            }
        }
        $session->set(self::ENTRIES_KEY, $stamps);

        return $stamps !== [];
    }

    /** @return array<int, string> Entry id => stamp. A sign-in from before these existed has none. */
    private static function stamps(Session $session): array
    {
        $stamps = $session->get(self::ENTRIES_KEY, []);

        return is_array($stamps) ? $stamps : [];
    }
}
