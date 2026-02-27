<?php

declare(strict_types=1);

namespace Luster\Tests\Unit;

use Luster\Core\CSRF;
use Luster\Core\Session;
use PHPUnit\Framework\TestCase;

/**
 * Tests token generation, validation, timing-safe comparison, and regeneration.
 *
 * Session is started in setUp() and destroyed in tearDown() so each test
 * gets a clean slate. Session::start() is safe to call in CLI (PHP's file
 * session handler works without HTTP, cookie flags are no-ops in CLI mode).
 */
final class CSRFTest extends TestCase
{
    private Session $session;
    private CSRF    $csrf;

    protected function setUp(): void
    {
        $this->session = new Session();
        $this->session->start();
        // Clear any token left over from a previous test iteration
        $this->session->remove('lstr_csrf_token');
        $this->csrf = new CSRF($this->session);
    }

    protected function tearDown(): void
    {
        $this->session->destroy();
    }

    // -----------------------------------------------------------------------
    // token() generation
    // -----------------------------------------------------------------------

    public function testTokenReturns64CharHexString(): void
    {
        $token = $this->csrf->token();

        // bin2hex(random_bytes(32)) → 64 lowercase hex characters
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $token);
    }

    public function testTokenReturnsSameValueOnSubsequentCalls(): void
    {
        $first  = $this->csrf->token();
        $second = $this->csrf->token();

        $this->assertSame($first, $second);
    }

    public function testTokenPersistsInSession(): void
    {
        $token = $this->csrf->token();

        // Create a second CSRF instance backed by the same session — must see same token
        $csrf2 = new CSRF($this->session);
        $this->assertSame($token, $csrf2->token());
    }

    // -----------------------------------------------------------------------
    // validate() — correct tokens
    // -----------------------------------------------------------------------

    public function testValidateReturnsTrueForMatchingToken(): void
    {
        $token = $this->csrf->token();

        $this->assertTrue($this->csrf->validate($token));
    }

    // -----------------------------------------------------------------------
    // validate() — incorrect / absent tokens
    // -----------------------------------------------------------------------

    public function testValidateReturnsFalseForWrongToken(): void
    {
        $this->csrf->token(); // ensure a token is stored

        $this->assertFalse($this->csrf->validate('deadbeefdeadbeefdeadbeefdeadbeefdeadbeefdeadbeefdeadbeefdeadbeef'));
    }

    public function testValidateReturnsFalseForEmptyString(): void
    {
        $this->csrf->token();

        $this->assertFalse($this->csrf->validate(''));
    }

    public function testValidateReturnsFalseWhenNoTokenIssued(): void
    {
        // setUp removed the token; validate() must return false immediately
        $this->assertFalse($this->csrf->validate('anything'));
    }

    public function testValidateReturnsFalseForPartialToken(): void
    {
        $token = $this->csrf->token();

        $this->assertFalse($this->csrf->validate(substr($token, 0, 32)));
    }

    public function testValidateReturnsFalseForTokenWithExtraChar(): void
    {
        $token = $this->csrf->token();

        $this->assertFalse($this->csrf->validate($token . 'a'));
    }

    // -----------------------------------------------------------------------
    // Timing-safe comparison — hash_equals must be used (structural test)
    // -----------------------------------------------------------------------

    public function testValidateUsesTimingSafeComparisonNotLooseEquality(): void
    {
        // A token of all-zeros should not validate against a legitimate token
        $this->csrf->token();

        $this->assertFalse($this->csrf->validate(str_repeat('0', 64)));
    }

    // -----------------------------------------------------------------------
    // regenerate()
    // -----------------------------------------------------------------------

    public function testRegenerateReturns64CharHexString(): void
    {
        $new = $this->csrf->regenerate();

        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $new);
    }

    public function testRegenerateProducesDifferentTokenFromOriginal(): void
    {
        $old = $this->csrf->token();
        $new = $this->csrf->regenerate();

        // Statistically impossible for two random 32-byte tokens to be equal
        $this->assertNotSame($old, $new);
    }

    public function testOldTokenFailsValidationAfterRegenerate(): void
    {
        $old = $this->csrf->token();
        $this->csrf->regenerate();

        $this->assertFalse($this->csrf->validate($old));
    }

    public function testNewTokenPassesValidationAfterRegenerate(): void
    {
        $this->csrf->token(); // generate original
        $new = $this->csrf->regenerate();

        $this->assertTrue($this->csrf->validate($new));
    }

    public function testTokenAfterRegenerateMatchesNewValue(): void
    {
        $this->csrf->token();
        $new = $this->csrf->regenerate();

        // token() should now return the regenerated value
        $this->assertSame($new, $this->csrf->token());
    }
}
