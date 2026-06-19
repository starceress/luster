<?php

declare(strict_types=1);

namespace Luster\Tests\Unit;

use Luster\Core\FieldWrapper;
use PHPUnit\Framework\TestCase;

final class FieldWrapperTest extends TestCase
{
    private FieldWrapper $wrapper;

    protected function setUp(): void
    {
        $this->wrapper = new FieldWrapper();
    }

    public function testDefaultTemplateIncludesIdAndForWhenFieldIdIsPresent(): void
    {
        $html = $this->wrapper->render('', '<input type="text" id="join-name">', 'Name', 'join-name', true);

        self::assertStringContainsString('id="wrap-join-name"', $html);
        self::assertStringContainsString('for="join-name"', $html);
        self::assertStringContainsString('<input type="text" id="join-name">', $html);
        self::assertStringContainsString('Name<span class="fl-required">*</span>', $html);
    }

    public function testDefaultTemplateOmitsIdAndForWhenFieldIdIsEmpty(): void
    {
        $html = $this->wrapper->render('', '<label><input type="checkbox"></label>', 'Pick one', '', false);

        self::assertStringNotContainsString('id="wrap-', $html);
        self::assertStringNotContainsString('for=', $html);
        self::assertStringContainsString('<label><input type="checkbox"></label>', $html);
    }

    public function testRequiredIndicatorIsAsteriskOnlyWhenRequired(): void
    {
        $required = $this->wrapper->render('', '<input>', 'Name', 'join-name', true);
        $optional = $this->wrapper->render('', '<input>', 'Name', 'join-name', false);

        self::assertStringContainsString('fl-required', $required);
        self::assertStringContainsString('Name</label>', $optional);
        self::assertStringNotContainsString('fl-required', $optional);
    }

    public function testHintIsShownOnlyWhenNonEmpty(): void
    {
        $withHint    = $this->wrapper->render('', '<input>', 'Avatar', 'join-avatar', false, 'JPEG, PNG, or GIF only');
        $withoutHint = $this->wrapper->render('', '<input>', 'Avatar', 'join-avatar', false);

        self::assertStringContainsString('<small class="fl-field-hint">JPEG, PNG, or GIF only</small>', $withHint);
        self::assertStringNotContainsString('<small>', $withoutHint);
    }

    public function testErrorIsShownOnlyWhenNonEmpty(): void
    {
        $withError    = $this->wrapper->render('', '<input>', 'Captcha', 'join-captcha', true, '', 'Incorrect answer.');
        $withoutError = $this->wrapper->render('', '<input>', 'Captcha', 'join-captcha', true);

        self::assertStringContainsString('<p class="fl-field-error">Incorrect answer.</p>', $withError);
        self::assertStringNotContainsString('fl-field-error', $withoutError);
    }

    public function testInputIsInsertedAsTrustedRawHtmlNotEscaped(): void
    {
        $rawInput = '<select id="join-country"><option value="US">United States</option></select>';
        $html     = $this->wrapper->render('', $rawInput, 'Country', 'join-country', false);

        self::assertStringContainsString($rawInput, $html);
    }

    public function testLabelIsEscaped(): void
    {
        $html = $this->wrapper->render('', '<input>', '<b>Name</b>', 'join-name', false);

        self::assertStringContainsString('&lt;b&gt;Name&lt;/b&gt;', $html);
        self::assertStringNotContainsString('<b>Name</b>', $html);
    }

    public function testCustomTemplateIsUsedWhenProvided(): void
    {
        $html = $this->wrapper->render(
            '<div class="custom">{label}: {input}</div>',
            '<input type="text">',
            'Name',
            'join-name',
            false,
        );

        self::assertSame('<div class="custom">Name: <input type="text"></div>', $html);
    }

    public function testLostPasswordUrlConditionalReflectsPresence(): void
    {
        $tpl = '{if_lost_password_url}<a href="{lost_password_url}">Forgot?</a>{/if_lost_password_url}{if_no_field_id}NOID{/if_no_field_id}';

        $withUrl    = $this->wrapper->render($tpl, '<input>', 'Password', 'join-password', true, '', '', 'https://example.com/reset');
        $withoutUrl = $this->wrapper->render($tpl, '<input>', 'Password', 'join-password', true);

        self::assertStringContainsString('<a href="https://example.com/reset">Forgot?</a>', $withUrl);
        self::assertStringNotContainsString('<a href=', $withoutUrl);
        self::assertStringNotContainsString('NOID', $withUrl);
    }
}
