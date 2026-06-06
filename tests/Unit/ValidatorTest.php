<?php

declare(strict_types=1);

namespace Luster\Tests\Unit;

use Luster\Core\Validator;
use PHPUnit\Framework\TestCase;

/**
 * Tests every validation rule in Validator + ValidatorField.
 */
final class ValidatorTest extends TestCase
{
    // -----------------------------------------------------------------------
    // passes() / fails()
    // -----------------------------------------------------------------------

    public function testPassesWhenNoRulesViolated(): void
    {
        $v = new Validator(['name' => 'Alice']);
        $v->field('name')->required()->maxLength(100);

        $this->assertTrue($v->passes());
        $this->assertFalse($v->fails());
    }

    public function testFailsWhenRuleViolated(): void
    {
        $v = new Validator(['name' => '']);
        $v->field('name')->required();

        $this->assertFalse($v->passes());
        $this->assertTrue($v->fails());
    }

    // -----------------------------------------------------------------------
    // required()
    // -----------------------------------------------------------------------

    public function testRequiredPassesOnNonEmptyValue(): void
    {
        $v = new Validator(['field' => 'hello']);
        $v->field('field')->required();

        $this->assertTrue($v->passes());
    }

    public function testRequiredFailsOnEmptyString(): void
    {
        $v = new Validator(['field' => '']);
        $v->field('field')->required();

        $this->assertTrue($v->fails());
        $this->assertNotEmpty($v->firstError('field'));
    }

    public function testRequiredFailsOnWhitespaceOnly(): void
    {
        $v = new Validator(['field' => '   ']);
        $v->field('field')->required();

        $this->assertTrue($v->fails());
    }

    public function testRequiredFailsWhenKeyAbsent(): void
    {
        $v = new Validator([]);
        $v->field('missing')->required();

        $this->assertTrue($v->fails());
    }

    public function testRequiredStopsChainOnFailure(): void
    {
        // If required() fails, the email rule should NOT run and produce an
        // additional error — there should be exactly one error for the field.
        $v = new Validator(['field' => '']);
        $v->field('field')->required()->email();

        $errors = $v->errors();
        $this->assertCount(1, $errors['field']);
    }

    // -----------------------------------------------------------------------
    // email()
    // -----------------------------------------------------------------------

    public function testEmailPassesOnValidAddress(): void
    {
        $v = new Validator(['email' => 'user@example.com']);
        $v->field('email')->email();

        $this->assertTrue($v->passes());
    }

    public function testEmailFailsOnInvalidAddress(): void
    {
        $v = new Validator(['email' => 'not-an-email']);
        $v->field('email')->email();

        $this->assertTrue($v->fails());
    }

    public function testEmailPassesOnEmptyWhenNotRequired(): void
    {
        // email() only validates if the value is non-empty
        $v = new Validator(['email' => '']);
        $v->field('email')->email();

        $this->assertTrue($v->passes());
    }

    // -----------------------------------------------------------------------
    // url()
    // -----------------------------------------------------------------------

    public function testUrlPassesOnValidUrl(): void
    {
        $v = new Validator(['url' => 'https://example.com']);
        $v->field('url')->url();

        $this->assertTrue($v->passes());
    }

    public function testUrlPassesOnHttpUrl(): void
    {
        $v = new Validator(['url' => 'http://example.com/path?query=1']);
        $v->field('url')->url();

        $this->assertTrue($v->passes());
    }

    public function testUrlFailsOnBareString(): void
    {
        $v = new Validator(['url' => 'just-a-string']);
        $v->field('url')->url();

        $this->assertTrue($v->fails());
    }

    public function testUrlPassesOnEmptyWhenNotRequired(): void
    {
        $v = new Validator(['url' => '']);
        $v->field('url')->url();

        $this->assertTrue($v->passes());
    }

    // -----------------------------------------------------------------------
    // httpUrl()
    // -----------------------------------------------------------------------

    public function testHttpUrlPassesOnHttps(): void
    {
        $v = new Validator(['url' => 'https://example.com']);
        $v->field('url')->httpUrl();

        $this->assertTrue($v->passes());
    }

    public function testHttpUrlPassesOnHttp(): void
    {
        $v = new Validator(['url' => 'http://example.com/path']);
        $v->field('url')->httpUrl();

        $this->assertTrue($v->passes());
    }

    public function testHttpUrlPassesOnEmpty(): void
    {
        $v = new Validator(['url' => '']);
        $v->field('url')->httpUrl();

        $this->assertTrue($v->passes());
    }

    public function testHttpUrlRejectsFtpScheme(): void
    {
        $v = new Validator(['url' => 'ftp://example.com/file.zip']);
        $v->field('url')->httpUrl();

        $this->assertTrue($v->fails());
    }

    public function testHttpUrlRejectsDataUri(): void
    {
        $v = new Validator(['url' => 'data:text/html,<script>alert(1)</script>']);
        $v->field('url')->httpUrl();

        $this->assertTrue($v->fails());
    }

    public function testHttpUrlRejectsBareString(): void
    {
        $v = new Validator(['url' => 'not-a-url']);
        $v->field('url')->httpUrl();

        $this->assertTrue($v->fails());
    }

    // -----------------------------------------------------------------------
    // maxLength()
    // -----------------------------------------------------------------------

    public function testMaxLengthPassesWhenWithinLimit(): void
    {
        $v = new Validator(['field' => 'hello']);
        $v->field('field')->maxLength(10);

        $this->assertTrue($v->passes());
    }

    public function testMaxLengthPassesAtExactLimit(): void
    {
        $v = new Validator(['field' => '12345']);
        $v->field('field')->maxLength(5);

        $this->assertTrue($v->passes());
    }

    public function testMaxLengthFailsWhenOverLimit(): void
    {
        $v = new Validator(['field' => 'hello world']);
        $v->field('field')->maxLength(5);

        $this->assertTrue($v->fails());
    }

    public function testMaxLengthCountsMultibyteCorrectly(): void
    {
        // "你好" = 2 characters, should pass maxLength(2), fail maxLength(1)
        $v2 = new Validator(['field' => '你好']);
        $v2->field('field')->maxLength(2);
        $this->assertTrue($v2->passes());

        $v1 = new Validator(['field' => '你好']);
        $v1->field('field')->maxLength(1);
        $this->assertTrue($v1->fails());
    }

    // -----------------------------------------------------------------------
    // minLength()
    // -----------------------------------------------------------------------

    public function testMinLengthPassesWhenAtOrAboveLimit(): void
    {
        $v = new Validator(['field' => 'hello']);
        $v->field('field')->minLength(3);

        $this->assertTrue($v->passes());
    }

    public function testMinLengthFailsWhenBelowLimit(): void
    {
        $v = new Validator(['field' => 'hi']);
        $v->field('field')->minLength(5);

        $this->assertTrue($v->fails());
    }

    // -----------------------------------------------------------------------
    // alphaNumericSpaces()
    // -----------------------------------------------------------------------

    public function testAlphaNumericSpacesPassesOnLettersAndNumbers(): void
    {
        $v = new Validator(['field' => 'Hello World 123']);
        $v->field('field')->alphaNumericSpaces();

        $this->assertTrue($v->passes());
    }

    public function testAlphaNumericSpacesPassesOnUnicode(): void
    {
        $v = new Validator(['field' => 'Ünïcödé 42']);
        $v->field('field')->alphaNumericSpaces();

        $this->assertTrue($v->passes());
    }

    public function testAlphaNumericSpacesFailsOnSpecialChars(): void
    {
        $v = new Validator(['field' => 'hello!']);
        $v->field('field')->alphaNumericSpaces();

        $this->assertTrue($v->fails());
    }

    public function testAlphaNumericSpacesPassesOnEmptyValue(): void
    {
        $v = new Validator(['field' => '']);
        $v->field('field')->alphaNumericSpaces();

        $this->assertTrue($v->passes());
    }

    // -----------------------------------------------------------------------
    // in()
    // -----------------------------------------------------------------------

    public function testInPassesWhenValueIsAllowed(): void
    {
        $v = new Validator(['status' => 'active']);
        $v->field('status')->in(['active', 'inactive', 'pending']);

        $this->assertTrue($v->passes());
    }

    public function testInFailsWhenValueNotAllowed(): void
    {
        $v = new Validator(['status' => 'unknown']);
        $v->field('status')->in(['active', 'inactive']);

        $this->assertTrue($v->fails());
    }

    public function testInPassesOnEmptyWhenNotRequired(): void
    {
        $v = new Validator(['status' => '']);
        $v->field('status')->in(['active', 'inactive']);

        $this->assertTrue($v->passes());
    }

    public function testInUsesStrictComparison(): void
    {
        // '1' (string) should not match 1 (int-like string comparison against a string list)
        $v = new Validator(['flag' => '1']);
        $v->field('flag')->in(['true', 'false']);

        $this->assertTrue($v->fails());
    }

    // -----------------------------------------------------------------------
    // allErrors() + firstError()
    // -----------------------------------------------------------------------

    public function testAllErrorsReturnsFlatList(): void
    {
        $v = new Validator(['a' => '', 'b' => '']);
        $v->field('a')->required();
        $v->field('b')->required();

        $all = $v->allErrors();
        $this->assertCount(2, $all);
    }

    public function testFirstErrorReturnsNullWhenNone(): void
    {
        $v = new Validator(['name' => 'Alice']);
        $v->field('name')->required();

        $this->assertNull($v->firstError('name'));
    }

    public function testFirstErrorReturnsFirstMessageForField(): void
    {
        $v = new Validator(['name' => '']);
        $v->field('name')->required();

        $this->assertIsString($v->firstError('name'));
        $this->assertNotEmpty($v->firstError('name'));
    }

    // -----------------------------------------------------------------------
    // Custom messages
    // -----------------------------------------------------------------------

    public function testCustomRequiredMessage(): void
    {
        $v = new Validator(['name' => '']);
        $v->field('name')->required('Name is needed.');

        $this->assertSame('Name is needed.', $v->firstError('name'));
    }

    public function testCustomMaxLengthMessage(): void
    {
        $v = new Validator(['name' => 'toolong']);
        $v->field('name')->maxLength(3, 'Too long!');

        $this->assertSame('Too long!', $v->firstError('name'));
    }
}
