<?php

declare(strict_types=1);

namespace Luster\Tests\Unit;

use Luster\Tests\Unit\Support\RunsMemberSaveHarness;
use PHPUnit\Framework\TestCase;

/**
 * Every form that saves a member's extra-field answers, and every page that shows
 * them, on both table layouts: an answer only ever goes to its own field's column
 * (FieldColumnMap), so a field named like a built-in can't overwrite a member's
 * name, password or status. Reads use the same map, so what a form shows is what
 * it saves back.
 *
 * Every case runs the real controllers in Support/member-save-harness.php, a child
 * PHP process started with -d sendmail_path pointing at Support/capture-mail.php
 * and -d error_log at a temp file, so mail() writes to a file and nothing is ever
 * really sent. Each test sends all its scenarios to one child; every scenario gets
 * a fresh database.
 */
final class MemberFieldColumnsTest extends TestCase
{
    use RunsMemberSaveHarness;

    private const MAIL_SEPARATOR  = '--LSTR-MAIL-END--';
    private const PUBLIC_REFUSAL  = "We couldn't save this because of a problem with the form. Nothing was changed. Please let the listing's owner know.";
    private const ADMIN_REFUSAL   = "can't be saved: this field's column is missing or its name clashes with a built-in one. Fix it on the Fields page. Nothing was changed.";
    private const ADMIN_DB_ERROR  = "Couldn't check this listing's member table because of a database error. Nothing was changed.";
    private const ADD_DB_ERROR    = "Couldn't save this member because of a database error. Nothing was added.";
    private const CORE_COLUMNS    = ['email', 'name', 'country', 'url', 'password', 'added', 'showurl', 'showemail'];

    /** A definition with $name, the way saveFields() stores one. */
    private static function def(string $name, string $type = 'text', string $options = ''): array
    {
        return ['name' => $name, 'label' => ucfirst($name), 'type' => $type, 'required' => 0, 'options' => $options];
    }

    /**
     * Each fixture: definitions, columns and answers per layout, and the column the
     * field's answer should be in. Every value is unique, so seeing it in the right
     * place proves it came from the right column.
     *
     * @return array<string, array{layout: string, definitions: list<array>, extra_columns: list<string>, member: array<string, string>, field: string, column: string, answer: string}>
     */
    private static function fixtures(): array
    {
        $fixtures = [
            'owned: name -> additional_name' => ['owned', 'name', ['additional_name' => 'ANSWER-N'], 'additional_name', 'ANSWER-N'],
            'enth: name -> additional_name'  => ['enth', 'name', ['additional_name' => 'ANSWER-N'], 'additional_name', 'ANSWER-N'],
            'owned: Fave -> fave'            => ['owned', 'Fave', ['fave' => 'ANSWER-F'], 'fave', 'ANSWER-F'],
            'owned: Fave -> additional_fave' => ['owned', 'Fave', ['additional_fave' => 'ANSWER-F'], 'additional_fave', 'ANSWER-F'],
            'enth: Fave -> additional_fave'  => ['enth', 'Fave', ['additional_fave' => 'ANSWER-F'], 'additional_fave', 'ANSWER-F'],
            'owned: bare beside additional'  => ['owned', 'fave', ['fave' => 'ANSWER-BARE', 'additional_fave' => 'OTHER-PREFIXED'], 'fave', 'ANSWER-BARE'],
            'enth: bare beside additional'   => ['enth', 'fave', ['fave' => 'OTHER-BARE', 'additional_fave' => 'ANSWER-PREFIXED'], 'additional_fave', 'ANSWER-PREFIXED'],
            'owned: additional_x is bare'    => ['owned', 'additional_x', ['additional_x' => 'ANSWER-X'], 'additional_x', 'ANSWER-X'],
            'enth: additional_x doubled'     => ['enth', 'additional_x', ['additional_additional_x' => 'ANSWER-X'], 'additional_additional_x', 'ANSWER-X'],
            'owned: a numeric name'          => ['owned', '123', ['123' => 'ANSWER-123'], '123', 'ANSWER-123'],
            'enth: a numeric name'           => ['enth', '123', ['additional_123' => 'ANSWER-123'], 'additional_123', 'ANSWER-123'],
            'owned: the answer "0"'          => ['owned', 'fave', ['fave' => '0'], 'fave', '0'],
            'enth: the answer "0"'           => ['enth', 'fave', ['additional_fave' => '0'], 'additional_fave', '0'],
        ];

        $out = [];
        foreach ($fixtures as $label => [$layout, $field, $columns, $column, $answer]) {
            $out[$label] = [
                'layout'        => $layout,
                'definitions'   => [self::def($field)],
                'extra_columns' => array_map('strval', array_keys($columns)),
                'member'        => $columns,
                'field'         => $field,
                'column'        => $column,
                'answer'        => $answer,
            ];
        }
        return $out;
    }

    /** The scenario keys of a fixture, for the harness. */
    private static function scenario(array $fixture, string $action, array $over = []): array
    {
        return ['action' => $action] + $over + array_intersect_key($fixture, array_flip(['layout', 'definitions', 'extra_columns', 'member']));
    }

    /** The row without what a save is meant to change. */
    private static function comparable(array $row): array
    {
        unset($row['updated']);
        return $row;
    }

    private static function assertCoreUnchanged(array $r, string $why): void
    {
        self::assertIsArray($r['row'], $why . ': the member is still there');
        foreach (self::CORE_COLUMNS as $column) {
            self::assertSame($r['before'][$column] ?? null, $r['row'][$column] ?? null, "{$why}: {$column} unchanged");
        }
        foreach (['pending', 'approved'] as $column) {
            if (array_key_exists($column, $r['before'])) {
                self::assertSame($r['before'][$column], $r['row'][$column], "{$why}: {$column} unchanged");
            }
        }
    }

    /** No error thrown, nothing written to the member, nothing queued, the given refusal shown. */
    private static function assertPublicRefusal(array $r, string $why): void
    {
        self::assertNull($r['error'], $why);
        self::assertSame(self::PUBLIC_REFUSAL, $r['flashes']['lstr_flash_error:m1'] ?? null, $why);
        self::assertSame($r['before'], $r['row'], $why . ': the row is byte-identical');
        self::assertSame([], $r['queue'], $why . ': nothing queued');
    }

    // ------------------------------------------------------------------
    // The unchanged round trip: what a form shows is what it saves back
    // ------------------------------------------------------------------

    public function testPostingTheEditMemberFormUnchangedLeavesTheRowAlone(): void
    {
        $scenarios = [];
        foreach (self::fixtures() as $label => $fixture) {
            $scenarios[$label] = self::scenario($fixture, 'roundtrip_edit');
        }

        foreach ($this->runScenarios($scenarios) as $label => $r) {
            $fixture = self::fixtures()[$label];
            self::assertNull($r['error'], $label);
            self::assertSame($fixture['answer'], $r['posted']['field_' . $fixture['field']] ?? null, $label . ': the form shows the answer');
            self::assertSame(self::comparable($r['before']), self::comparable($r['row']), $label . ': the row is byte-identical');
        }
    }

    public function testPostingTheUpdateFormUnchangedLeavesTheRowAlone(): void
    {
        $scenarios = [];
        foreach (self::fixtures() as $label => $fixture) {
            $scenarios[$label] = self::scenario($fixture, 'roundtrip_update');
        }

        foreach ($this->runScenarios($scenarios) as $label => $r) {
            $fixture = self::fixtures()[$label];
            self::assertNull($r['error'], $label);
            self::assertSame($fixture['answer'], $r['posted']['field_' . $fixture['field']] ?? null, $label . ': the form shows the answer');
            self::assertSame($r['before'], $r['row'], $label . ': the row is byte-identical');
            self::assertSame([], $r['queue'], $label);
        }

        // Queued, an unchanged form asks for nothing, so nothing waits for approval.
        $queued = [];
        foreach (self::fixtures() as $label => $fixture) {
            $queued[$label] = self::scenario($fixture, 'roundtrip_update', ['queue_updates' => true]);
        }
        foreach ($this->runScenarios($queued) as $label => $r) {
            self::assertSame([], $r['queue'], $label . ': nothing queued');
            self::assertSame($r['before'], $r['row'], $label);
        }
    }

    public function testEditingAnAnswerSavesItToThatFieldsOwnColumn(): void
    {
        $scenarios = [];
        foreach (self::fixtures() as $label => $fixture) {
            $scenarios[$label] = self::scenario($fixture, 'edit', ['post' => ['field_' . $fixture['field'] => 'CHANGED']]);
        }

        foreach ($this->runScenarios($scenarios) as $label => $r) {
            $fixture = self::fixtures()[$label];
            self::assertNull($r['error'], $label);
            self::assertSame('CHANGED', (string) $r['row'][$fixture['column']], $label);
            self::assertCoreUnchanged($r, $label);
            foreach ($fixture['member'] as $column => $value) {
                if ((string) $column !== $fixture['column']) {
                    self::assertSame($value, $r['row'][$column], $label . ': the other column keeps its value');
                }
            }
        }
    }

    public function testEditMemberSavesExtraAnswersOnTheEnthLayout(): void
    {
        $r = $this->runOne(['layout' => 'enth', 'action' => 'edit', 'definitions' => [self::def('fave')], 'extra_columns' => ['additional_fave'],
            'member' => ['additional_fave' => 'old answer'], 'post' => ['field_fave' => 'new answer']]);

        self::assertNull($r['error']);
        self::assertSame('new answer', $r['row']['additional_fave'], 'it used to report success and keep the old answer');
    }

    // ------------------------------------------------------------------
    // Showing answers
    // ------------------------------------------------------------------

    public function testEveryPageShowsTheAnswerUnderItsOwnField(): void
    {
        $scenarios = [];
        foreach (self::fixtures() as $label => $fixture) {
            $scenarios[$label] = self::scenario($fixture, 'display');
        }

        foreach ($this->runScenarios($scenarios) as $label => $r) {
            $fixture = self::fixtures()[$label];
            self::assertNull($r['error'], $label);
            $d = $r['display'];
            self::assertStringContainsString('[' . $fixture['field'] . '=' . $fixture['answer'] . ']', $d['token_row'], $label . ': {fan_x}');
            self::assertStringContainsString('<span class="fl-member-field-value">' . $fixture['answer'] . '</span>', $d['block'], $label . ': {fan_additional_fields}');
            self::assertStringContainsString('<span class="fl-member-table-cell">' . $fixture['answer'] . '</span>', $d['cells'], $label . ': {fan_additional_field_cells}');
            self::assertSame('ANYHAS', $d['conditions'], $label . ': the template conditionals see the answer');
            self::assertStringContainsString('<span class="fl-member-table-cell">' . $fixture['answer'] . '</span>', $d['default'], $label . ': the default template');
            self::assertStringContainsString('>' . $fixture['answer'] . '</td>', $d['admin'], $label . ': the Members list');
            self::assertStringContainsString($fixture['answer'], $d['csv'], $label . ': the CSV');

            foreach ($fixture['member'] as $value) {
                if ($value !== $fixture['answer']) {
                    foreach (['token_row', 'block', 'cells', 'default', 'admin', 'csv'] as $page) {
                        self::assertStringNotContainsString($value, $d[$page], "{$label}: {$page} doesn't show the other column");
                    }
                }
            }
        }
    }

    public function testAFieldWithNoColumnShowsBlankNotACoreValue(): void
    {
        $r = $this->runOne(['layout' => 'owned', 'action' => 'display', 'definitions' => [self::def('name')]]);

        self::assertNull($r['error']);
        self::assertStringContainsString('[name=]', $r['display']['token_row'], "the field's own answer is blank, never the member's real name");
        self::assertSame('', $r['display']['block']);
    }

    // ------------------------------------------------------------------
    // A definition named like a built-in column
    // ------------------------------------------------------------------

    /** @return array<string, array{0: string, 1: array}> Field, what's posted for it. */
    private static function coreFieldCases(): array
    {
        $cases = [];
        foreach (['pending', 'name', 'email', 'password', 'added', 'showemail'] as $core) {
            $cases["{$core} posted"] = [$core, ['field_' . $core => 'overwritten']];
            $cases["{$core} blank"]  = [$core, ['field_' . $core => '']];
            $cases["{$core} absent"] = [$core, []];
        }
        return $cases;
    }

    public function testACoreNamedFieldWithNoColumnOfItsOwnNeverTouchesTheMember(): void
    {
        $scenarios = [];
        foreach (['update', 'edit'] as $action) {
            foreach (self::coreFieldCases() as $label => [$core, $post]) {
                $scenarios["{$action}, {$label}"] = ['layout' => 'owned', 'action' => $action, 'definitions' => [self::def($core)], 'post' => $post];
            }
        }

        foreach ($this->runScenarios($scenarios) as $why => $r) {
            self::assertNull($r['error'], $why);
            self::assertCoreUnchanged($r, $why);
            self::assertTrue(password_verify('existing-password-1', (string) $r['row']['password']), $why . ': still the same password hash');

            $refused = str_ends_with($why, 'posted');
            if (str_starts_with($why, 'update')) {
                self::assertSame($refused ? self::PUBLIC_REFUSAL : null, $r['flashes']['lstr_flash_error:m1'] ?? null, $why);
            } else {
                self::assertSame($refused, str_contains($r['body'], htmlspecialchars(self::ADMIN_REFUSAL, ENT_QUOTES)), $why);
            }
        }
    }

    public function testACoreNamedFieldOnJoinAndAddMemberNeverTouchesTheNewMember(): void
    {
        // A save that gets through costs a real password hash, so the blank and absent
        // cases keep to the two names that matter most. Every name is posted.
        $scenarios = [];
        foreach (self::coreFieldCases() as $label => [$core, $post]) {
            if (!str_ends_with($label, 'posted') && !in_array($core, ['pending', 'password'], true)) {
                continue;
            }
            $addPost = [];
            foreach ($post as $key => $value) {
                $addPost['field_1_' . substr($key, 6)] = $value;
            }
            $scenarios["join, {$label}"] = ['layout' => 'owned', 'action' => 'join', 'definitions' => [self::def($core)], 'member' => null, 'post' => $post];
            $scenarios["add, {$label}"]  = ['layout' => 'owned', 'action' => 'add', 'definitions' => [self::def($core)], 'member' => null, 'post' => $addPost];
        }

        foreach ($this->runScenarios($scenarios) as $why => $r) {
            self::assertNull($r['error'], $why);
            if (str_ends_with($why, 'posted')) {
                self::assertFalse($r['new_row'], $why . ': nothing saved');
                self::assertSame(0, $r['row_count'], $why);
                self::assertSame('', $r['mail'], $why . ': no email');
                continue;
            }
            self::assertIsArray($r['new_row'], $why . ': a blank answer has nothing to lose');
            self::assertSame('New Fan', $r['new_row']['name'], $why);
            self::assertSame('new@example.com', $r['new_row']['email'], $why);
            self::assertStringStartsWith('$argon2id$', (string) $r['new_row']['password'], $why);
            self::assertSame(str_starts_with($why, 'join') ? 1 : 0, (int) $r['new_row']['pending'], $why . ': the status comes from the form');
        }
    }

    public function testACoreNamedFieldWithAnAdditionalColumnSavesThereAndLeavesTheCoreAlone(): void
    {
        $scenarios = [];
        foreach (['name', 'email', 'password', 'pending', 'added', 'showemail'] as $core) {
            $common = ['layout' => 'owned', 'definitions' => [self::def($core)], 'extra_columns' => ['additional_' . $core]];
            $scenarios["update, {$core}"] = $common + ['action' => 'update', 'post' => ['field_' . $core => 'SAVED']];
            $scenarios["edit, {$core}"]   = $common + ['action' => 'edit', 'post' => ['field_' . $core => 'SAVED']];
            if (in_array($core, ['name', 'password', 'pending'], true)) {
                $scenarios["join, {$core}"] = $common + ['action' => 'join', 'member' => null, 'post' => ['field_' . $core => 'SAVED']];
                $scenarios["add, {$core}"]  = $common + ['action' => 'add', 'member' => null, 'post' => ['field_1_' . $core => 'SAVED']];
            }
        }

        foreach ($this->runScenarios($scenarios) as $why => $r) {
            [$form, $core] = explode(', ', $why);
            self::assertNull($r['error'], $why);
            if (in_array($form, ['update', 'edit'], true)) {
                self::assertSame('SAVED', $r['row']['additional_' . $core], $why);
                self::assertCoreUnchanged($r, $why);
            } else {
                self::assertSame('SAVED', $r['new_row']['additional_' . $core], $why);
                self::assertSame('New Fan', $r['new_row']['name'], $why);
                self::assertSame('new@example.com', $r['new_row']['email'], $why);
                self::assertStringStartsWith('$argon2id$', (string) $r['new_row']['password'], $why);
            }
        }
    }

    public function testARefusedUpdateWritesNoPasswordEither(): void
    {
        $r = $this->runOne(['layout' => 'owned', 'action' => 'update', 'definitions' => [self::def('name')],
            'post' => ['field_name' => 'overwritten', 'new_password' => 'a-brand-new-password-1', 'new_password_confirm' => 'a-brand-new-password-1', 'new_name' => 'New Name']]);

        self::assertPublicRefusal($r, 'the password change happens first today, so it would be half-saved');
        self::assertTrue(password_verify('existing-password-1', (string) $r['row']['password']));
        self::assertSame('Ann', $r['row']['name']);
    }

    public function testAnUpdatePasswordChangeStillWorksAndIsNeverQueued(): void
    {
        $r = $this->runOne(['layout' => 'owned', 'action' => 'update', 'queue_updates' => true, 'definitions' => [self::def('fave')], 'extra_columns' => ['fave'],
            'post' => ['field_fave' => 'keep', 'new_password' => 'a-brand-new-password-1', 'new_password_confirm' => 'a-brand-new-password-1']]);

        self::assertNull($r['error']);
        self::assertTrue(password_verify('a-brand-new-password-1', (string) $r['row']['password']));
        self::assertCount(1, $r['queue']);
        self::assertArrayNotHasKey('password', $r['queue'][0]['changes']);
        self::assertSame('keep', $r['queue'][0]['changes']['fave']);
    }

    public function testAFieldWhoseFallbackBelongsToAnotherFieldIsRefusedAndTheOtherKeepsItsValue(): void
    {
        $common = ['layout' => 'owned', 'definitions' => [self::def('fave'), self::def('additional_fave')],
            'extra_columns' => ['additional_fave'], 'member' => ['additional_fave' => 'THEIRS']];

        $refused = $this->runOne($common + ['action' => 'update', 'post' => ['field_fave' => 'mine', 'field_additional_fave' => 'THEIRS']]);
        self::assertPublicRefusal($refused, 'bare fave is missing, and additional_fave belongs to the field of that name');

        $blank = $this->runOne($common + ['action' => 'update', 'post' => ['field_fave' => '', 'field_additional_fave' => 'CHANGED']]);
        self::assertSame('CHANGED', $blank['row']['additional_fave'], 'a blank answer for fave is ignored and the other field saves');
    }

    public function testTheCoreColumnsAreNotInThePlaceOfAnyAnswerOnTheEnthLayout(): void
    {
        $scenarios = [];
        foreach (['name', 'email', 'password', 'approved', 'id', 'added'] as $core) {
            $scenarios[$core] = ['layout' => 'enth', 'action' => 'update', 'definitions' => [self::def($core)], 'post' => ['field_' . $core => 'overwritten']];
        }

        foreach ($this->runScenarios($scenarios) as $core => $r) {
            self::assertPublicRefusal($r, $core . ' has no additional_ column');
        }
    }

    // ------------------------------------------------------------------
    // Malformed answers
    // ------------------------------------------------------------------

    public function testAnArrayPostedToAnyFieldButACheckboxIsRefusedOnJoinAndUpdate(): void
    {
        foreach (['text', 'textarea', 'radio', 'dropdown'] as $type) {
            $options = in_array($type, ['radio', 'dropdown'], true) ? 'Cat, Dog' : '';
            $common  = ['layout' => 'owned', 'definitions' => [self::def('pick', $type, $options)], 'extra_columns' => ['pick']];
            $results = $this->runScenarios([
                'update' => $common + ['action' => 'update', 'post' => ['field_pick' => ['Cat', 'Dog']]],
                'join'   => $common + ['action' => 'join', 'member' => null, 'post' => ['field_pick' => ['Cat', 'Dog']]],
            ]);

            self::assertNull($results['update']['error'], $type);
            self::assertSame("Pick: that answer isn't valid.", $results['update']['flashes']['lstr_flash_error:m1'] ?? null, $type);
            self::assertSame($results['update']['before'], $results['update']['row'], $type . ': it used to be joined and saved');

            self::assertNull($results['join']['error'], $type);
            self::assertSame("Pick: that answer isn't valid.", $results['join']['flashes']['lstr_flash_error:m1'] ?? null, $type);
            self::assertFalse($results['join']['new_row'], $type);
        }
    }

    public function testCheckboxGroupsAndASingleYesBoxStillSave(): void
    {
        $group   = ['layout' => 'owned', 'definitions' => [self::def('likes', 'checkbox', 'Red, Blue, Green')], 'extra_columns' => ['likes']];
        $yesBox  = ['layout' => 'enth', 'definitions' => [self::def('agree', 'checkbox')], 'extra_columns' => ['additional_agree']];
        $results = $this->runScenarios([
            'group update' => $group + ['action' => 'update', 'post' => ['field_likes' => ['Red', ' Blue ', '']]],
            'group join'   => $group + ['action' => 'join', 'member' => null, 'post' => ['field_likes' => ['Red', 'Green']]],
            'yes update'   => $yesBox + ['action' => 'update', 'post' => ['field_agree' => '1']],
            'none ticked'  => $group + ['action' => 'update', 'member' => ['likes' => 'Red'], 'post' => []],
        ]);

        self::assertSame('Red, Blue', $results['group update']['row']['likes']);
        self::assertSame('Red, Green', $results['group join']['new_row']['likes']);
        self::assertSame('1', $results['yes update']['row']['additional_agree']);
        self::assertSame('', $results['none ticked']['row']['likes'], 'unticking every box still clears the answer');
    }

    public function testNestedArraysAreAFieldErrorEvenWithNowhereToSave(): void
    {
        $nested = ['field_x' => [['a']]];
        foreach (['checkbox', 'text'] as $type) {
            $r = $this->runOne(['layout' => 'owned', 'action' => 'update', 'definitions' => [self::def('x', $type)], 'post' => $nested]);
            self::assertNull($r['error'], $type . ': no TypeError');
            self::assertSame("X: that answer isn't valid.", $r['flashes']['lstr_flash_error:m1'] ?? null, $type . ": not an ignored blank");
            self::assertSame($r['before'], $r['row'], $type);
        }
    }

    public function testEditMemberRefusesAnArrayAndShowsTheSavedAnswerAgain(): void
    {
        $r = $this->runOne(['layout' => 'owned', 'action' => 'edit', 'definitions' => [self::def('fave')], 'extra_columns' => ['fave'],
            'member' => ['fave' => 'SAVED-ANSWER'], 'post' => ['field_fave' => ['a', 'b'], 'name' => 'Changed Name']]);

        self::assertNull($r['error']);
        self::assertSame(200, $r['status']);
        self::assertStringContainsString(htmlspecialchars("Fave: that answer isn't valid.", ENT_QUOTES), $r['body']);
        self::assertStringContainsString('value="SAVED-ANSWER"', $r['body']);
        self::assertStringNotContainsString('value="Array"', $r['body']);
        self::assertSame($r['before'], $r['row'], 'nothing written, the name included');
    }

    // ------------------------------------------------------------------
    // When the columns can't be read
    // ------------------------------------------------------------------

    public function testAFailedColumnReadRefusesEverySaveAndWritesNothing(): void
    {
        $failRead = '/PRAGMA table_info/';
        foreach (['owned' => 'fave', 'enth' => 'additional_fave'] as $layout => $column) {
            $common  = ['layout' => $layout, 'definitions' => [self::def('fave')], 'extra_columns' => [$column], 'fail_on' => $failRead];
            $results = $this->runScenarios([
                'update'       => $common + ['action' => 'update', 'post' => ['field_fave' => 'x', 'new_password' => 'a-brand-new-password-1', 'new_password_confirm' => 'a-brand-new-password-1']],
                'update blank' => $common + ['action' => 'update', 'post' => ['field_fave' => '']],
                'join'         => $common + ['action' => 'join', 'member' => null, 'post' => ['field_fave' => 'x']],
                'join blank'   => $common + ['action' => 'join', 'member' => null, 'post' => ['field_fave' => '']],
                'add'          => $common + ['action' => 'add', 'member' => null, 'post' => ['field_1_fave' => 'x']],
                'edit'         => $common + ['action' => 'edit', 'post' => ['field_fave' => 'x', 'name' => 'Changed Name']],
            ]);

            foreach (['update', 'update blank'] as $case) {
                self::assertPublicRefusal($results[$case], "{$layout}, {$case}");
                self::assertStringContainsString("listing #1", $results[$case]['log'], "{$layout}, {$case}: the reason goes to the log");
            }
            foreach (['join', 'join blank'] as $case) {
                self::assertNull($results[$case]['error'], "{$layout}, {$case}");
                self::assertSame(self::PUBLIC_REFUSAL, $results[$case]['flashes']['lstr_flash_error:m1'] ?? null, "{$layout}, {$case}");
                self::assertFalse($results[$case]['new_row'], "{$layout}, {$case}");
                self::assertSame('', $results[$case]['mail'], "{$layout}, {$case}: no email");
            }
            self::assertNull($results['add']['error'], $layout);
            self::assertStringContainsString(htmlspecialchars(self::ADD_DB_ERROR, ENT_QUOTES), $results['add']['body'], $layout);
            self::assertFalse($results['add']['new_row'], $layout);

            self::assertNull($results['edit']['error'], $layout);
            self::assertStringContainsString(htmlspecialchars(self::ADMIN_DB_ERROR, ENT_QUOTES), $results['edit']['body'], $layout);
            self::assertSame($results['edit']['before'], $results['edit']['row'], $layout);
        }
    }

    public function testAFormWithNoFieldsDoesntReadTheColumnsToPlaceAnswers(): void
    {
        $r = $this->runOne(['layout' => 'owned', 'action' => 'update', 'post' => ['new_name' => 'Changed']]);

        self::assertNull($r['error']);
        self::assertSame('Changed', $r['row']['name']);
        $reads = array_filter($r['statements'], static fn(string $sql): bool => str_starts_with($sql, 'PRAGMA table_info'));
        self::assertCount(1, $reads, "only MemberRepo::update()'s own read");
    }

    // ------------------------------------------------------------------
    // An import's real table shape
    // ------------------------------------------------------------------

    public function testTheImportersShapeWorksOnEveryForm(): void
    {
        // An enth_ source imported onto the unprefixed layout: bare definitions, additional_ columns.
        $common = ['layout' => 'owned', 'definitions' => [self::def('fave'), self::def('name')], 'extra_columns' => ['additional_fave', 'additional_name']];
        $saved  = ['additional_fave' => 'old fave', 'additional_name' => 'old name'];

        $results = $this->runScenarios([
            'update' => $common + ['action' => 'update', 'member' => $saved, 'post' => ['field_fave' => 'new fave', 'field_name' => 'new name']],
            'edit'   => $common + ['action' => 'edit', 'member' => $saved, 'post' => ['field_fave' => 'new fave', 'field_name' => 'new name']],
            'join'   => $common + ['action' => 'join', 'member' => null, 'post' => ['field_fave' => 'new fave', 'field_name' => 'new name']],
            'add'    => $common + ['action' => 'add', 'member' => null, 'post' => ['field_1_fave' => 'new fave', 'field_1_name' => 'new name']],
        ]);

        foreach (['update' => 'row', 'edit' => 'row', 'join' => 'new_row', 'add' => 'new_row'] as $form => $key) {
            self::assertNull($results[$form]['error'], $form);
            self::assertSame('new fave', $results[$form][$key]['additional_fave'], $form);
            self::assertSame('new name', $results[$form][$key]['additional_name'], $form);
            self::assertSame($key === 'row' ? 'Ann' : 'New Fan', $results[$form][$key]['name'], $form . ': the real name is untouched');
        }
    }

    public function testTheRealNameAndAFieldNamedNameChangeTogetherThroughTheQueue(): void
    {
        $r = $this->runOne(['layout' => 'owned', 'action' => 'update_and_approve', 'queue_updates' => true,
            'definitions' => [self::def('name')], 'extra_columns' => ['additional_name'], 'member' => ['additional_name' => 'old answer'],
            'post' => ['new_name' => 'New Real Name', 'field_name' => 'new answer']]);

        self::assertNull($r['error']);
        $queued = $r['display']['queued_before_approval'][0];
        self::assertSame('New Real Name', $queued['name'], 'the queue keeps the two apart');
        self::assertSame('new answer', $queued['additional_name']);

        self::assertSame('New Real Name', $r['row']['name'], "today's approver applies both");
        self::assertSame('new answer', $r['row']['additional_name']);
        self::assertSame([], $r['queue']);
    }

    // ------------------------------------------------------------------
    // The queue's comparison screens
    // ------------------------------------------------------------------

    public function testTheQueueComparesPhysicalColumnsAndShowsBeforeValues(): void
    {
        $r = $this->runOne(['layout' => 'owned', 'action' => 'queue_page', 'definitions' => [self::def('name')], 'extra_columns' => ['additional_name'],
            'member' => ['additional_name' => 'OLD-ANSWER', 'showurl' => 1],
            'queued' => [['changes' => ['additional_name' => 'NEW-ANSWER', 'email' => 'new@example.com', 'showurl' => 0, 'name' => 'New Name']]]]);

        self::assertNull($r['error']);
        self::assertSame(200, $r['status']);
        self::assertStringContainsString('<span class="diff-old">OLD-ANSWER</span>', $r['body'], 'the extra column has a before value');
        self::assertStringContainsString('<span class="diff-old">ann@example.com</span>', $r['body'], 'email shows a before value now');
        self::assertStringContainsString('<span class="diff-old">Ann</span>', $r['body']);
        self::assertMatchesRegularExpression('/Showurl<\/span>\s*<span class="diff-old">1<\/span>/', $r['body'], 'showurl shows a before value now');
    }

    public function testAValueThatIsntTextDisplaysAsNotValid(): void
    {
        $queued  = [['changes' => ['name' => ['a', 'b']]]];
        $results = $this->runScenarios([
            'listing'  => ['layout' => 'owned', 'action' => 'queue_page', 'queued' => $queued],
            'overview' => ['layout' => 'owned', 'action' => 'queue_overview', 'queued' => $queued],
        ]);

        foreach ($results as $page => $r) {
            self::assertNull($r['error'], $page);
            self::assertStringContainsString('(not valid)', $r['body'], $page);
            self::assertStringNotContainsString('>Array<', $r['body'], $page);
        }
    }

    public function testOneAddressInTwoListingsShowsEachListingsOwnMember(): void
    {
        $r = $this->runOne(['layout' => 'owned', 'action' => 'queue_overview', 'second_listing' => true,
            'queued' => [['changes' => ['country' => 'Chile']]]]);

        self::assertNull($r['error']);
        self::assertSame(2, substr_count($r['body'], 'class="row-cb-pu"'), 'both requests are listed');
        self::assertStringContainsString('<b>Ann</b>', $r['body']);
        self::assertStringContainsString('<b>Sun Ann</b>', $r['body'], 'the map used to be keyed by address, so both rows showed one name');
        self::assertStringContainsString('https://sun-ann.test/', $r['body']);
    }

    // ------------------------------------------------------------------
    // The Fields list's label
    // ------------------------------------------------------------------

    public function testTheFieldsListLabelsOnlyFieldsThatCantSaveAnswers(): void
    {
        $common = ['layout' => 'owned', 'action' => 'fields_page', 'definitions' => [self::def('name'), self::def('fave')], 'extra_columns' => ['fave']];

        $results = $this->runScenarios([
            'one bad'     => $common,
            'failed read' => $common + ['fail_on' => '/PRAGMA table_info/'],
            'all fine'    => ['definitions' => [self::def('fave')]] + $common,
        ]);

        self::assertSame(1, substr_count($results['one bad']['body'], "Can&#039;t save answers") + substr_count($results['one bad']['body'], "Can't save answers"));
        self::assertStringContainsString('Delete this field and add it again under another name.', $results['one bad']['body']);

        self::assertNull($results['failed read']['error'], "a failed read doesn't break the page");
        self::assertSame(200, $results['failed read']['status']);
        self::assertStringNotContainsString("Can't save answers", $results['failed read']['body']);
        self::assertStringNotContainsString("Can't save answers", $results['all fine']['body']);
    }

    public function testTheUpdateEmbedReadsAnswersTheSameWay(): void
    {
        // It boots from the live config, so tests can't run it here: Support/member-save-harness.php
        // builds the same form. This keeps the two reading answers the same way.
        $embed = (string) file_get_contents(dirname(__DIR__, 2) . '/public/embed/update.php');

        self::assertStringContainsString('$_lusterMember->answers($_lusterFields)', $embed);
        self::assertStringNotContainsString('additionalFields', $embed);
    }
}
