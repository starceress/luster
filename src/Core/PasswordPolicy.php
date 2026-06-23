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

final class PasswordPolicy
{
    public const MIN_LENGTH = 12;
    public const MAX_LENGTH = 255;

    public static function apply(ValidatorField $field): ValidatorField
    {
        return $field
            ->required()
            ->minLength(self::MIN_LENGTH)
            ->maxLength(self::MAX_LENGTH);
    }

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
