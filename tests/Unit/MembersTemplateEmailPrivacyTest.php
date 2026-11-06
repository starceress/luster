<?php

declare(strict_types=1);

namespace Luster\Tests\Unit;

use Luster\Import\EnthusiastImportSource;
use Luster\Models\AdditionalField;
use Luster\Models\Member;
use Luster\Repositories\ListingSettingsRepo;
use Luster\Support\MembersTemplateRenderer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * A fan's email address is collected for the owner's correspondence and never
 * goes on a public page, whatever a stored or imported template asks for, and
 * whatever the member's showemail says.
 */
final class MembersTemplateEmailPrivacyTest extends TestCase
{
    private const ADDRESS = 'private.fan@example.test';

    /** @param array<string, mixed> $extra */
    private function member(array $extra = []): Member
    {
        return Member::fromRow(array_merge([
            'email'    => self::ADDRESS,
            'name'     => 'Usagi',
            'country'  => 'Japan',
            'url'      => 'https://usagi.example',
            'pending'  => 0,
            'password' => '',
            'showurl'  => 1,
            'added'    => '2024-01-02',
        ], $extra));
    }

    /** @param AdditionalField[] $fields */
    private function render(string $template, Member $member, array $fields = []): string
    {
        return MembersTemplateRenderer::render($template, [$member], $fields, '/join', 1);
    }

    /** @return array<string, array{string, array<string, mixed>}> */
    public static function everyTemplateAndEveryFlag(): array
    {
        $templates = [
            'per-row template'     => '<p>{fan_name} {fan_email}</p>',
            'each_member template' => '<ul>{each_member}<li>{fan_name} {fan_email}</li>{/each_member}</ul>',
            'twice in one row'     => '{fan_email} and {fan_email}',
            'hand-written mailto'  => '<a href="mailto:{fan_email}">Email me</a>',
            'in an attribute'      => '<span data-email="{fan_email}" title="{fan_email}">Usagi</span>',
            'inside a conditional' => '{if_fan_url}{fan_email}{/if_fan_url}',
            'Enthusiast stock'     => '<p><b>{fan_name}</b><br />' . "\n" . '{fan_email} - {fan_url}</p>',
            'Listing Admin link'   => '<li>{fan_name}<br /><a href="mailto:{fan_email}">{fan_email}</a> &middot; {fan_url}</li>',
        ];
        $flags = [
            'showing' => ['showemail' => 1],
            'hidden'  => ['showemail' => 0],
            'missing' => [],
            'null'    => ['showemail' => null],
        ];

        $cases = [];
        foreach ($templates as $templateName => $template) {
            foreach ($flags as $flagName => $flag) {
                $cases["{$templateName}, {$flagName}"] = [$template, $flag];
            }
        }
        return $cases;
    }

    /** @param array<string, mixed> $flag */
    #[DataProvider('everyTemplateAndEveryFlag')]
    public function testTheAddressIsNowhereInTheHtml(string $template, array $flag): void
    {
        $html = $this->render($template, $this->member($flag));

        self::assertStringNotContainsString(self::ADDRESS, $html);
        self::assertStringNotContainsString(self::ADDRESS, html_entity_decode($html, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
    }

    public function testTheTokenItselfDoesNotShowUp(): void
    {
        self::assertSame('<p>Usagi </p>', $this->render('<p>{fan_name} {fan_email}</p>', $this->member()));
    }

    public function testEnthusiastsStockTemplateLeavesNoStraySeparator(): void
    {
        $html = $this->render('<p><b>{fan_name}</b><br />' . "\n" . '{fan_email} - {fan_url}</p>', $this->member());

        self::assertSame('<p><b>Usagi</b><br />' . "\n" . 'https://usagi.example</p>', $html);
    }

    public function testListingAdminsLinkLeavesNoEmptyLinkOrSeparator(): void
    {
        $html = $this->render(
            '<li>{fan_name}<br /><a href="mailto:{fan_email}">{fan_email}</a> &middot; {fan_url}</li>',
            $this->member(),
        );

        self::assertSame('<li>Usagi<br />https://usagi.example</li>', $html);
    }

    public function testListingAdminsLinkOnItsOwnLeavesNoEmptyLink(): void
    {
        $html = $this->render('<li><a href="mailto:{fan_email}">{fan_email}</a></li>', $this->member());

        self::assertSame('<li></li>', $html);
    }

    public function testEveryOtherTokenStillWorks(): void
    {
        $html = $this->render('{fan_name}|{fan_url}|{fan_country}|{fan_email}|', $this->member());

        self::assertSame('Usagi|https://usagi.example|Japan||', $html);
    }

    public function testTheAddressIsNotInTheAdditionalFieldsBlock(): void
    {
        $fields = [new AdditionalField('fave', 'Favourite', 'text', false)];
        $member = $this->member(['fave' => 'Moon']);

        $html = $this->render('{fan_additional_fields}{fan_additional_field_cells}', $member, $fields);

        self::assertStringContainsString('Moon', $html);
        self::assertStringNotContainsString(self::ADDRESS, $html);
    }

    /**
     * A field named "email" (only an import can make one) holds what the fan typed
     * into it, so it keeps the token, and the separator still separates something.
     */
    public function testAFieldNamedEmailKeepsItsOwnTokenAndNeverTheRealAddress(): void
    {
        $fields = [new AdditionalField('email', 'Other address', 'text', false)];
        $member = $this->member(['additional_email' => 'typed@example.test']);

        $html = $this->render('{fan_email} - {fan_url}', $member, $fields);

        self::assertSame('typed@example.test - https://usagi.example', $html);
    }

    /** Nowhere safe to read that field from, so it's blank. It never falls back to the real address. */
    public function testAFieldNamedEmailWithNoColumnIsBlank(): void
    {
        $fields = [new AdditionalField('email', 'Other address', 'text', false)];

        $html = $this->render('[{fan_email}] - {fan_url}', $this->member(), $fields);

        self::assertSame('[] - https://usagi.example', $html);
    }

    public function testAFieldNamedEmailThatsUnansweredIsBlank(): void
    {
        $fields = [new AdditionalField('email', 'Other address', 'text', false)];

        $html = $this->render('[{fan_email}]', $this->member(['additional_email' => '']), $fields);

        self::assertSame('[]', $html);
    }

    public function testAFieldNamedEmailInCapitalsDoesNotClaimTheToken(): void
    {
        $fields = [new AdditionalField('Email', 'Other address', 'text', false)];
        $member = $this->member(['additional_Email' => 'typed@example.test']);

        $html = $this->render('{fan_email} - {fan_Email}', $member, $fields);

        self::assertSame('typed@example.test', $html);
    }

    /** The whole road from Enthusiast's own stock list template to the public page. */
    public function testAnImportedStockTemplateStaysPrivate(): void
    {
        $translate = new ReflectionMethod(EnthusiastImportSource::class, 'translateDisplayTokens');
        $template  = (string) $translate->invoke(
            null,
            '<p><b>$$fan_name$$</b><br />' . "\n" . '$$fan_email_generic$$ - $$fan_url_generic$$</p>',
        );

        $html = $this->render($template, $this->member());

        self::assertSame('<p><b>Usagi</b><br />' . "\n" . 'https://usagi.example</p>', $html);
    }

    /** Stored templates can still hold the old syntax, and they're translated as they're read. */
    public function testATemplateTranslatedOnReadStaysPrivate(): void
    {
        $translate = new ReflectionMethod(ListingSettingsRepo::class, 'translateLegacyTokens');
        $template  = (string) $translate->invoke(null, '$$fan_name$$ $$fan_email$$ $$fan_email_generic$$');

        self::assertStringNotContainsString(self::ADDRESS, $this->render($template, $this->member()));
    }

    /** @return array<string, array{string, string}> */
    public static function publicTokenLists(): array
    {
        return [
            'listing templates'  => ['templates/admin/listings/edit.php', "'members_template', '{fan_email}'"],
            'global templates'   => ['templates/admin/listings/global-templates.php', "'listing_default_members_template', '{fan_email}'"],
            'embed reference'    => ['templates/admin/embed-codes/index.php', '<code>{fan_email}</code>'],
        ];
    }

    #[DataProvider('publicTokenLists')]
    public function testTheAdminPanelDoesNotOfferTheTokenForPublicTemplates(string $file, string $offer): void
    {
        $contents = (string) file_get_contents(dirname(__DIR__, 2) . '/' . $file);

        self::assertStringNotContainsString($offer, $contents);
    }
}
