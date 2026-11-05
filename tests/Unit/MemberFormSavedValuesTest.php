<?php

declare(strict_types=1);

namespace Luster\Tests\Unit;

use DOMDocument;
use DOMXPath;
use Luster\Tests\Unit\Support\RunsMemberSaveHarness;
use PHPUnit\Framework\TestCase;

/**
 * Drawing a form and saving it without touching anything shouldn't change what's saved.
 * Edit Member's country is a dropdown, and a country that isn't on the list, like one from
 * an old import, used to show as "Select Country" and be blanked by the next save.
 */
final class MemberFormSavedValuesTest extends TestCase
{
    use RunsMemberSaveHarness;

    private const OFF_LIST = 'Narnia';

    /** @return list<array{value: string, selected: bool}> The country dropdown's options. */
    private static function countryOptions(string $html): array
    {
        $doc = new DOMDocument();
        libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="UTF-8">' . $html);
        libxml_clear_errors();

        $options = [];
        foreach ((new DOMXPath($doc))->query('//select[@name="country"]/option') as $option) {
            $options[] = ['value' => $option->getAttribute('value'), 'selected' => $option->hasAttribute('selected')];
        }

        return $options;
    }

    private static function selected(string $html): array
    {
        return array_column(array_filter(self::countryOptions($html), static fn(array $o): bool => $o['selected']), 'value');
    }

    private static function offered(string $html, string $value): int
    {
        return count(array_filter(self::countryOptions($html), static fn(array $o): bool => $o['value'] === $value));
    }

    // ------------------------------------------------------------------
    // Edit Member's country
    // ------------------------------------------------------------------

    public function testACountryOffTheListIsOfferedAndSurvivesASave(): void
    {
        foreach (['owned', 'enth'] as $layout) {
            $member  = ['member' => ['country' => self::OFF_LIST]];
            $results = $this->runScenarios([
                'drawn' => $member + ['layout' => $layout, 'action' => 'edit_get'],
                'saved' => $member + ['layout' => $layout, 'action' => 'roundtrip_edit'],
            ]);

            self::assertSame([self::OFF_LIST], self::selected($results['drawn']['body']), $layout);
            self::assertSame(1, self::offered($results['drawn']['body'], self::OFF_LIST), $layout);
            self::assertNull($results['saved']['error'], $layout);
            self::assertSame(self::OFF_LIST, $results['saved']['row']['country'], "{$layout}: saving the form as it's drawn keeps it");
        }
    }

    public function testAnOwnerCanStillChangeOrBlankACountryOffTheList(): void
    {
        foreach (['owned', 'enth'] as $layout) {
            $common  = ['layout' => $layout, 'action' => 'edit', 'member' => ['country' => self::OFF_LIST]];
            $results = $this->runScenarios([
                'listed' => $common + ['post' => ['country' => 'Canada']],
                'blank'  => $common + ['post' => ['country' => '']],
            ]);

            self::assertSame('Canada', $results['listed']['row']['country'], $layout);
            self::assertSame('', $results['blank']['row']['country'], $layout);
        }
    }

    public function testARefusedSaveKeepsTheChoiceAndStillOffersTheSavedCountry(): void
    {
        $common  = ['layout' => 'owned', 'action' => 'edit', 'member' => ['country' => self::OFF_LIST]];
        $results = $this->runScenarios([
            'kept'    => $common + ['post' => ['name' => '', 'country' => self::OFF_LIST]],
            'changed' => $common + ['post' => ['name' => '', 'country' => 'Canada']],
        ]);

        self::assertSame(self::OFF_LIST, $results['kept']['row']['country'], 'Nothing was saved');
        self::assertSame([self::OFF_LIST], self::selected($results['kept']['body']));
        self::assertSame(['Canada'], self::selected($results['changed']['body']), "It shows what the owner picked");
        self::assertSame(1, self::offered($results['changed']['body'], self::OFF_LIST), 'The saved one is still there to go back to');
    }

    public function testAListedCountryIsntOfferedTwice(): void
    {
        $r = $this->runOne(['layout' => 'owned', 'action' => 'edit_get', 'member' => ['country' => 'Canada']]);

        self::assertSame(['Canada'], self::selected($r['body']));
        self::assertSame(1, self::offered($r['body'], 'Canada'));
    }

    public function testNoSavedCountryOffersNothingExtra(): void
    {
        $results = $this->runScenarios([
            'blank'  => ['layout' => 'owned', 'action' => 'edit_get', 'member' => ['country' => '']],
            'listed' => ['layout' => 'owned', 'action' => 'edit_get', 'member' => ['country' => 'Canada']],
        ]);

        self::assertSame([], self::selected($results['blank']['body']));
        self::assertSame(count(self::countryOptions($results['listed']['body'])), count(self::countryOptions($results['blank']['body'])));
        self::assertSame(1, self::offered($results['blank']['body'], ''), 'Only the Select Country choice');
    }

    public function testStraySpacesSelectTheListedCountryAndSavingTidiesThem(): void
    {
        foreach (['owned', 'enth'] as $layout) {
            $member  = ['member' => ['country' => ' Canada ']];
            $results = $this->runScenarios([
                'drawn' => $member + ['layout' => $layout, 'action' => 'edit_get'],
                'saved' => $member + ['layout' => $layout, 'action' => 'roundtrip_edit'],
            ]);

            self::assertSame(['Canada'], self::selected($results['drawn']['body']), $layout);
            self::assertSame(0, self::offered($results['drawn']['body'], ' Canada '), $layout);
            self::assertSame('Canada', $results['saved']['row']['country'], "{$layout}: tidied, not blanked");
        }
    }

    public function testTidyingALongImportedValueIsntAChangeToIt(): void
    {
        $longCountry = str_repeat('c', 120);
        $longName    = str_repeat('n', 120);
        foreach (['owned', 'enth'] as $layout) {
            $r = $this->runOne(['layout' => $layout, 'action' => 'roundtrip_edit', 'member' => ['country' => " {$longCountry} ", 'name' => " {$longName} "]]);

            self::assertNull($r['error'], $layout);
            self::assertSame($longCountry, $r['row']['country'], "{$layout}: not refused for being over 100");
            self::assertSame($longName, $r['row']['name'], $layout);
        }
    }

    public function testASavedCountryIsEscaped(): void
    {
        $country = '<b>"Narnia"</b>';
        $r = $this->runOne(['layout' => 'owned', 'action' => 'edit_get', 'member' => ['country' => $country]]);

        self::assertStringNotContainsString('<b>"Narnia"', $r['body']);
        self::assertSame([$country], self::selected($r['body']));
    }

    // ------------------------------------------------------------------
    // Public Update's website
    // ------------------------------------------------------------------

    public function testSavingTheRealUpdateFormAsItsDrawnKeepsAHiddenWebsiteHidden(): void
    {
        foreach (['owned', 'enth'] as $layout) {
            $r = $this->runOne(['layout' => $layout, 'action' => 'roundtrip_update', 'member' => ['showurl' => 0]]);

            self::assertNull($r['error'], $layout);
            self::assertSame('0', (string) $r['row']['showurl'], $layout);
            self::assertSame('https://ann.test/', $r['row']['url'], $layout);
        }
    }
}
