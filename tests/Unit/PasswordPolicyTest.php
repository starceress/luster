<?php

declare(strict_types=1);

namespace Luster\Tests\Unit;

use Luster\Core\PasswordPolicy;
use Luster\Core\Validator;
use PHPUnit\Framework\TestCase;

final class PasswordPolicyTest extends TestCase
{
    public function testRequiredPasswordPolicyRejectsShortPasswords(): void
    {
        $validator = new Validator(['password' => 'short']);
        PasswordPolicy::apply($validator->field('password'));

        self::assertTrue($validator->fails());
        self::assertStringContainsString('at least', implode(' ', $validator->allErrors()));
    }

    public function testOptionalPasswordPolicyAllowsBlankButRejectsShortNonBlankPasswords(): void
    {
        $blank = new Validator(['password' => '']);
        PasswordPolicy::applyOptional($blank->field('password'), '');
        self::assertFalse($blank->fails());

        $short = new Validator(['password' => 'tiny']);
        PasswordPolicy::applyOptional($short->field('password'), 'tiny');
        self::assertTrue($short->fails());
    }
}
