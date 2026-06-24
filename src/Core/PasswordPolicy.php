<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Centralised password strength policy for admin accounts. Defines minimum
 and maximum length constants and provides helper methods that apply those
 rules to a ValidatorField, keeping the policy consistent across account
 creation, password change, and password reset flows.
*/

declare(strict_types=1);

namespace Luster\Core;

/**
 * Centralised admin password strength policy.
 *
 * Keeps the minimum/maximum length constants in one place and provides
 * helper methods that apply them to a ValidatorField. Use apply() when a
 * password is required; use applyOptional() on edit forms where leaving the
 * field blank means "keep the current password".
 */
final class PasswordPolicy
{
    public const MIN_LENGTH = 12;
    public const MAX_LENGTH = 255;

    /**
     * Applies the full password policy (required + min/max length) to $field.
     * Use this on account creation and forced password change forms.
     */
    public static function apply(ValidatorField $field): ValidatorField
    {
        return $field
            ->required()
            ->minLength(self::MIN_LENGTH)
            ->maxLength(self::MAX_LENGTH);
    }

    /**
     * Applies the password policy only when $value is non-empty.
     *
     * Use on admin profile edit forms: if the admin leaves the password field
     * blank the field passes with no constraints, and the controller skips the
     * re-hash. When a new password is provided it must still satisfy the policy.
     */
    public static function applyOptional(ValidatorField $field, string $value): ValidatorField
    {
        if ($value === '') {
            return $field;
        }

        return $field
            ->minLength(self::MIN_LENGTH)
            ->maxLength(self::MAX_LENGTH);
    }
}
