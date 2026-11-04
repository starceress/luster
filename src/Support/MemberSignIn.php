<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Remembers which member a public Update sign-in verified, not just the address
 they used. Someone else can get that address while the sign-in is still alive,
 and legacy member tables have no id to tell them apart.
*/

declare(strict_types=1);

namespace Luster\Support;

use Luster\Core\Session;
use Luster\Models\Member;

/**
 * A sign-in's stamp is a hash of the member's stored password hash. Those are
 * salted, so another member's differs even with the same password, and an owner
 * changing a fan's password or a reset also ends that fan's open sign-ins.
 *
 * Issue a stamp while holding the address lock, from the hash read back after
 * MemberRepo::verifyAndUpgrade(), so it can only be the member who was verified.
 */
final class MemberSignIn
{
    private const EMAIL_KEY        = 'lstr_update_authed_';
    private const STAMP_KEY        = 'lstr_update_authed_stamp_';
    public const FORM_TOKEN_UPDATE = 'lstr_update_formtoken_update_';
    public const FORM_TOKEN_DELETE = 'lstr_update_formtoken_delete_';

    public const SIGN_IN_AGAIN = 'Please sign in again.';

    private function __construct() {}

    public static function stamp(string $passwordHash): string
    {
        return hash('sha256', $passwordHash);
    }

    public static function remember(Session $session, int $listingId, string $email, string $passwordHash): void
    {
        $session->set(self::EMAIL_KEY . $listingId, $email);
        $session->set(self::STAMP_KEY . $listingId, self::stamp($passwordHash));
    }

    /** The address this session signed in with, or ''. It proves nothing until checked. */
    public static function email(Session $session, int $listingId): string
    {
        return (string) $session->get(self::EMAIL_KEY . $listingId, '');
    }

    /** The stamp this session's sign-in was given, or ''. */
    public static function sessionStamp(Session $session, int $listingId): string
    {
        return (string) $session->get(self::STAMP_KEY . $listingId, '');
    }

    /** True when $member is the one this stamp was issued for. A missing stamp never matches. */
    public static function matches(string $stamp, Member $member): bool
    {
        return $stamp !== '' && hash_equals($stamp, self::stamp($member->password));
    }

    /** Ends the sign-in, fallback tokens included. */
    public static function forget(Session $session, int $listingId): void
    {
        $session->remove(self::EMAIL_KEY . $listingId);
        $session->remove(self::STAMP_KEY . $listingId);
        $session->remove(self::FORM_TOKEN_UPDATE . $listingId);
        $session->remove(self::FORM_TOKEN_DELETE . $listingId);
    }
}
