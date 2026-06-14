<?php

declare(strict_types=1);

namespace Luster\Tests\Unit;

use Luster\Addons\Rules\RulesRepo;
use Luster\Tests\Unit\Support\SqliteDatabase;
use PHPUnit\Framework\TestCase;

final class RulesRepoTest extends TestCase
{
    // SQLite doesn't support ENUM; TEXT is used instead.
    private const DDL = <<<SQL
CREATE TABLE lstr_rules (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    fanlisting_id INTEGER NOT NULL,
    body          TEXT    NOT NULL,
    enabled       INTEGER NOT NULL DEFAULT 1,
    list_style    TEXT    NOT NULL DEFAULT 'numbered',
    gate_type     TEXT    NOT NULL DEFAULT 'checkbox',
    gate_label    TEXT    NOT NULL DEFAULT '',
    version       INTEGER NOT NULL DEFAULT 1,
    updated_at    TEXT    NOT NULL DEFAULT '2025-01-01 00:00:00'
);

CREATE TABLE owned (
    listingid INTEGER PRIMARY KEY AUTOINCREMENT,
    title     TEXT NOT NULL DEFAULT '',
    subject   TEXT NOT NULL DEFAULT ''
)
SQL;

    private RulesRepo $repo;

    protected function setUp(): void
    {
        $db         = SqliteDatabase::make(self::DDL);
        $this->repo = new RulesRepo($db);
    }

    // -------------------------------------------------------------------------
    // upsert (insert) + hydrate round trip
    // -------------------------------------------------------------------------

    public function testUpsertInsertsNewRuleSetWithGateSettings(): void
    {
        $this->repo->upsert(1, "<ol>\n<li>Be nice</li>\n</ol>", true, 'button', 'Continue to Join');

        $rules = $this->repo->getForListing(1);

        self::assertNotNull($rules);
        self::assertSame(1, $rules->fanlistingId);
        self::assertSame("<ol>\n<li>Be nice</li>\n</ol>", $rules->body);
        self::assertTrue($rules->enabled);
        self::assertSame('button', $rules->gateType);
        self::assertSame('Continue to Join', $rules->gateLabel);
        self::assertSame(1, $rules->version);
    }

    public function testUpsertDefaultsGateTypeWhenNotProvided(): void
    {
        $this->repo->upsert(1, 'Be nice.', true);

        $rules = $this->repo->getForListing(1);

        self::assertNotNull($rules);
        self::assertSame('checkbox', $rules->gateType);
        self::assertSame('', $rules->gateLabel);
    }

    // -------------------------------------------------------------------------
    // version-bump behaviour
    // -------------------------------------------------------------------------

    public function testUpsertBumpsVersionWhenBodyChanges(): void
    {
        $this->repo->upsert(1, 'Original body.', true);
        $original = $this->repo->getForListing(1);
        self::assertSame(1, $original?->version);

        $this->repo->upsert(1, 'Updated body.', true);
        $updated = $this->repo->getForListing(1);

        self::assertSame(2, $updated?->version);
    }

    public function testUpsertDoesNotBumpVersionWhenOnlyGateSettingsChange(): void
    {
        $this->repo->upsert(1, 'Same body.', true, 'checkbox', '');
        $original = $this->repo->getForListing(1);
        self::assertSame(1, $original?->version);

        // Change gate type and gate label — body untouched.
        $this->repo->upsert(1, 'Same body.', true, 'button', 'Custom Label');
        $updated = $this->repo->getForListing(1);

        self::assertSame(1, $updated?->version);
        self::assertSame('button', $updated?->gateType);
        self::assertSame('Custom Label', $updated?->gateLabel);
    }

    public function testUpsertDoesNotBumpVersionWhenBodyOnlyDiffersByWhitespace(): void
    {
        $this->repo->upsert(1, 'Be nice.', true);
        $original = $this->repo->getForListing(1);
        self::assertSame(1, $original?->version);

        $this->repo->upsert(1, "  Be nice.  \n", true);
        $updated = $this->repo->getForListing(1);

        self::assertSame(1, $updated?->version);
    }

    // -------------------------------------------------------------------------
    // RuleSet::effectiveGateLabel()
    // -------------------------------------------------------------------------

    public function testEffectiveGateLabelReturnsCustomLabelWhenSet(): void
    {
        $this->repo->upsert(1, 'Be nice.', true, 'button', 'Click to Join');

        $rules = $this->repo->getForListing(1);

        self::assertSame('Click to Join', $rules?->effectiveGateLabel());
    }

    public function testEffectiveGateLabelDefaultsForCheckboxGate(): void
    {
        $this->repo->upsert(1, 'Be nice.', true, 'checkbox', '');

        $rules = $this->repo->getForListing(1);

        self::assertSame('Proceed', $rules?->effectiveGateLabel());
    }

    public function testEffectiveGateLabelDefaultsForButtonGate(): void
    {
        $this->repo->upsert(1, 'Be nice.', true, 'button', '');

        $rules = $this->repo->getForListing(1);

        self::assertSame('Proceed', $rules?->effectiveGateLabel());
    }

    // -------------------------------------------------------------------------
    // getForListing — no rules
    // -------------------------------------------------------------------------

    public function testGetForListingReturnsNullWhenNoRulesExist(): void
    {
        self::assertNull($this->repo->getForListing(99));
    }

    // -------------------------------------------------------------------------
    // setEnabled / delete
    // -------------------------------------------------------------------------

    public function testSetEnabledTogglesState(): void
    {
        $this->repo->upsert(1, 'Be nice.', true);

        $this->repo->setEnabled(1, false);
        self::assertFalse($this->repo->getForListing(1)?->enabled);

        $this->repo->setEnabled(1, true);
        self::assertTrue($this->repo->getForListing(1)?->enabled);
    }

    public function testDeleteRemovesRuleSet(): void
    {
        $this->repo->upsert(1, 'Be nice.', true);
        self::assertNotNull($this->repo->getForListing(1));

        $this->repo->delete(1);
        self::assertNull($this->repo->getForListing(1));
    }
}
