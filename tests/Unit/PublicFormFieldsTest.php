<?php

declare(strict_types=1);

namespace Luster\Tests\Unit;

use Luster\Core\RateLimit;
use Luster\Tests\Unit\Support\RunsMemberSaveHarness;
use PHPUnit\Framework\TestCase;

/**
 * Extra fields on the public Join and Update forms: which control each one gets, what
 * answer it accepts, and that a form sent back with an error still has what the fan
 * typed in it.
 *
 * Runs the real controllers in Support/member-save-harness.php, in a child process with
 * mail going to a file. "The form" below is the real public/embed/{join,update}.php,
 * drawn from the session the controller left, so this checks what a fan would see.
 */
final class PublicFormFieldsTest extends TestCase
{
    use RunsMemberSaveHarness;

    private const SECRET      = 'Moonlight-Secret-2026';
    private const SHORT       = 'Tiny-Secret';
    private const JOIN_ERROR  = 'lstr_flash_error:join:1';
    private const JOIN_OLD    = 'lstr_flash_old:join:1';
    private const UPD_ERROR   = 'lstr_flash_error:update:1';
    private const UPD_OLD     = 'lstr_flash_old:update:1';
    private const ANSWERS     = ['field_fave' => 'My fave', 'field_likes' => ['Red', 'Green'], 'field_pet' => 'Dog', 'field_mood' => 'Sad'];

    private static function def(string $name, string $type = 'text', string $options = '', int $required = 0): array
    {
        return ['name' => $name, 'label' => ucfirst($name), 'type' => $type, 'required' => $required, 'options' => $options];
    }

    private static function table(string $layout): string
    {
        return $layout === 'owned' ? 'fl_moon' : 'fl_moon_members';
    }

    private static function column(string $layout, string $field): string
    {
        return $layout === 'owned' ? $field : 'additional_' . $field;
    }

    /** One field of each control, and a member who has answered them differently. */
    private static function fourFields(string $layout): array
    {
        return [
            'layout'        => $layout,
            'definitions'   => [self::def('fave'), self::def('likes', 'checkbox', 'Red, Blue, Green'), self::def('pet', 'radio', 'Cat, Dog'), self::def('mood', 'dropdown', 'Happy, Sad')],
            'extra_columns' => array_map(static fn(string $f): string => self::column($layout, $f), ['fave', 'likes', 'pet', 'mood']),
            'member'        => [
                self::column($layout, 'fave')  => 'Saved fave',
                self::column($layout, 'likes') => 'Blue',
                self::column($layout, 'pet')   => 'Cat',
                self::column($layout, 'mood')  => 'Happy',
            ],
        ];
    }

    /** @return array<string, array{post?: array, seed_sql?: list<string>, lock_refused?: list<string>, before?: array, nowhere?: bool}> */
    private static function joinPaths(string $layout): array
    {
        $table = self::table($layout);

        return [
            'rate limit'     => ['seed_sql' => ["INSERT INTO lstr_rate_limits (identifier, action, attempts, blocked_until) VALUES ('127.0.0.1', '" . RateLimit::ACTION_PUBLIC_JOIN . "', 99, '2099-01-01 00:00:00')"]],
            'captcha'        => ['post' => ['captcha_answer' => '0']],
            'validation'     => ['post' => ['url' => 'not a website']],
            'blacklist'      => ['seed_sql' => ["INSERT INTO lstr_blacklist (pattern) VALUES ('new@example.com')"]],
            'duplicate'      => ['post' => ['email' => 'ann@example.com']],
            'nowhere safe'   => ['nowhere' => true, 'post' => ['field_nowhere' => 'Lost']],
            'mismatch'       => ['post' => ['password_confirm' => 'A-Different-Secret-1']],
            'policy'         => ['post' => ['password' => self::SHORT, 'password_confirm' => self::SHORT]],
            'busy'           => ['lock_refused' => ['new@example.com']],
            'dup under lock' => ['before' => ['match' => '/GET_LOCK/', 'sql' => "INSERT INTO {$table} (email, name) VALUES ('new@example.com', 'Rival')"]],
        ];
    }

    /** @return array<string, array> */
    private static function updatePaths(string $layout): array
    {
        $table = self::table($layout);

        return [
            'rate limit'      => ['seed_sql' => ["INSERT INTO lstr_rate_limits (identifier, action, attempts, blocked_until) VALUES ('127.0.0.1', '" . RateLimit::ACTION_PUBLIC_UPDATE . "', 99, '2099-01-01 00:00:00')"]],
            'validation'      => ['post' => ['new_email' => 'not an email']],
            'policy'          => ['post' => ['new_password' => self::SHORT, 'new_password_confirm' => self::SHORT]],
            'mismatch'        => ['post' => ['new_password_confirm' => 'A-Different-Secret-1']],
            'blacklist'       => ['seed_sql' => ["INSERT INTO lstr_blacklist (pattern) VALUES ('new@example.com')"], 'post' => ['new_email' => 'new@example.com']],
            'duplicate'       => ['seed_sql' => ["INSERT INTO {$table} (email, name) VALUES ('new@example.com', 'Rival')"], 'post' => ['new_email' => 'new@example.com']],
            'nowhere safe'    => ['nowhere' => true, 'post' => ['field_nowhere' => 'Lost']],
            'busy'            => ['lock_refused' => ['ann@example.com']],
            'rename failure'  => ['fail_on' => '/^\s*UPDATE ' . $table . '\b/', 'post' => ['new_email' => 'new@example.com']],
        ];
    }

    private static function scenario(array $base, array $path, array $post): array
    {
        $scenario = $base + ['render' => str_contains((string) ($post['_luster_embed_form'] ?? ''), 'join') ? 'join' : 'update'];
        if (!empty($path['nowhere'])) {
            $scenario['definitions'][] = self::def('nowhere');
        }
        foreach (['seed_sql', 'lock_refused', 'before', 'fail_on'] as $key) {
            if (isset($path[$key])) {
                $scenario[$key] = $path[$key];
            }
        }
        $scenario['post'] = ($path['post'] ?? []) + $post;

        return $scenario;
    }

    private static function joinPost(): array
    {
        return self::ANSWERS + ['_luster_embed_form' => 'join:1', 'name' => 'New Fan', 'email' => 'new@example.com', 'url' => 'https://fan.test/',
            'country' => 'Canada', 'password' => self::SECRET, 'password_confirm' => self::SECRET];
    }

    private static function updatePost(): array
    {
        return self::ANSWERS + ['_luster_embed_form' => 'update:1', 'new_name' => 'Renamed', 'new_email' => '', 'url' => 'https://fan.test/',
            'country' => 'Peru', 'new_password' => self::SECRET, 'new_password_confirm' => self::SECRET];
    }

    private static function assertNoPasswordsIn(array $r, string $why): void
    {
        foreach ([self::SECRET, self::SHORT, 'A-Different-Secret-1'] as $secret) {
            self::assertStringNotContainsString($secret, (string) json_encode($r['flashes']), $why . ': the flash');
            self::assertStringNotContainsString($secret, (string) $r['rendered'], $why . ': the form');
        }
    }

    private static function assertAnswersCameBack(array $r, string $why): void
    {
        self::assertIsArray($r['form'], $why . ': the form was drawn');
        self::assertSame('My fave', $r['form']['field_fave'] ?? null, $why . ': text');
        self::assertSame(['Red', 'Green'], $r['form']['field_likes'] ?? null, $why . ': checkbox group');
        self::assertSame('Dog', $r['form']['field_pet'] ?? null, $why . ': radio');
        self::assertSame('Sad', $r['form']['field_mood'] ?? null, $why . ': dropdown');
    }

    // ------------------------------------------------------------------
    // 2.4: every way back to the form keeps what was typed
    // ------------------------------------------------------------------

    public function testEveryWayBackToTheJoinFormKeepsTheAnswersAndNeverThePasswords(): void
    {
        foreach (['owned', 'enth'] as $layout) {
            $base      = ['action' => 'join'] + self::fourFields($layout);
            $scenarios = [];
            foreach (self::joinPaths($layout) as $name => $path) {
                $scenarios[$name] = self::scenario($base, $path, self::joinPost());
            }
            foreach ($this->runScenarios($scenarios) as $name => $r) {
                $why = "{$layout}, {$name}";
                self::assertNull($r['error'], $why);
                self::assertNotSame('', (string) ($r['flashes'][self::JOIN_ERROR] ?? ''), $why . ': it says why');
                self::assertAnswersCameBack($r, $why);
                self::assertSame('New Fan', $r['form']['name'] ?? null, $why . ': name');
                self::assertSame('Canada', $r['form']['country'] ?? null, $why . ': country');
                self::assertSame('https://fan.test/', $name === 'validation' ? 'https://fan.test/' : ($r['form']['url'] ?? null), $why . ': website');
                self::assertNoPasswordsIn($r, $why);
                if ($name !== 'dup under lock') {
                    self::assertFalse($r['new_row'], $why . ': nobody joined');
                }
            }
        }
    }

    public function testEveryWayBackToTheUpdateFormKeepsTheAnswersAndNeverThePasswords(): void
    {
        foreach (['owned', 'enth'] as $layout) {
            $base      = ['action' => 'update'] + self::fourFields($layout);
            $scenarios = [];
            foreach (self::updatePaths($layout) as $name => $path) {
                $scenarios[$name] = self::scenario($base, $path, self::updatePost());
            }
            foreach ($this->runScenarios($scenarios) as $name => $r) {
                $why = "{$layout}, {$name}";
                self::assertNull($r['error'], $why);
                self::assertNotSame('', (string) ($r['flashes'][self::UPD_ERROR] ?? ''), $why . ': it says why');
                self::assertAnswersCameBack($r, $why);
                self::assertSame('Renamed', $r['form']['new_name'] ?? null, $why . ': new name');
                self::assertNoPasswordsIn($r, $why);
                self::assertSame($r['before'], $r['row'], $why . ': nothing was saved');
            }
        }
    }

    public function testNothingTickedStaysNothingTickedOnTheUpdateFormAfterAnError(): void
    {
        $post = self::updatePost();
        unset($post['field_likes'], $post['field_pet']);
        $r = $this->runOne(self::scenario(['action' => 'update'] + self::fourFields('owned'), ['post' => ['new_email' => 'not an email']], $post));

        self::assertArrayNotHasKey('field_likes', $r['form'], 'the fan had just unticked it; the saved answer must not come back');
        self::assertArrayNotHasKey('field_pet', $r['form']);
    }

    public function testARejectedNestedAnswerComesBackBlankOnJoinWithNoWarning(): void
    {
        $results = $this->runScenarios([
            'captcha'    => self::scenario(['action' => 'join'] + self::fourFields('owned'), ['post' => ['captcha_answer' => '0', 'field_fave' => [['a']], 'field_likes' => [['Red']]]], self::joinPost()),
            'validation' => self::scenario(['action' => 'join'] + self::fourFields('owned'), ['post' => ['field_fave' => [['a']], 'field_likes' => [['Red']]]], self::joinPost()),
        ]);

        foreach ($results as $name => $r) {
            self::assertNull($r['error'], $name);
            self::assertSame('', $r['form']['field_fave'] ?? null, $name);
            self::assertArrayNotHasKey('field_likes', $r['form'], $name);
            self::assertSame('Dog', $r['form']['field_pet'] ?? null, $name . ': the rest is kept');
            self::assertStringNotContainsString('Array', (string) $r['rendered'], $name);
            self::assertStringNotContainsString('Array to string', $r['log'], $name);
            self::assertArrayNotHasKey('field_fave', $r['flashes'][self::JOIN_OLD] ?? [], $name);
        }
    }

    public function testARejectedNestedAnswerOnUpdateShowsTheSavedAnswerAgain(): void
    {
        $r = $this->runOne(self::scenario(['action' => 'update'] + self::fourFields('owned'), ['post' => ['field_fave' => [['a']]]], self::updatePost()));

        self::assertNull($r['error']);
        self::assertSame("Fave: that answer isn't valid.", $r['flashes'][self::UPD_ERROR] ?? null);
        self::assertSame('Saved fave', $r['form']['field_fave'] ?? null);
        self::assertStringNotContainsString('Array to string', $r['log']);
    }

    public function testACoreValuePostedAsAListComesBackBlankRatherThanArray(): void
    {
        $r = $this->runOne(self::scenario(['action' => 'join'] + self::fourFields('owned'), ['post' => ['name' => ['x'], 'url' => ['y']]], self::joinPost()));

        self::assertSame('', $r['form']['name'] ?? null);
        self::assertSame('', $r['form']['url'] ?? null);
        self::assertStringNotContainsString('Array', (string) $r['rendered']);
    }

    // ------------------------------------------------------------------
    // 2.2: checkbox answers, and everything else that isn't one flat string
    // ------------------------------------------------------------------

    public function testTickedPicksAreSavedJoinedOnceEachOnBothLayouts(): void
    {
        foreach (['owned', 'enth'] as $layout) {
            $column  = self::column($layout, 'likes');
            $common  = ['layout' => $layout, 'definitions' => [self::def('likes', 'checkbox', 'Red, Blue, Green')], 'extra_columns' => [$column]];
            $results = $this->runScenarios([
                'join'   => $common + ['action' => 'join', 'member' => null, 'post' => ['field_likes' => ['Red', ' Green ', '', 'Red']]],
                'update' => $common + ['action' => 'update', 'post' => ['field_likes' => ['Blue', 'Green']]],
            ]);

            self::assertSame('Red, Green', $results['join']['new_row'][$column], $layout);
            self::assertSame('Blue, Green', $results['update']['row'][$column], $layout);
        }
    }

    public function testARequiredGroupWithNothingTickedFails(): void
    {
        $common  = ['layout' => 'owned', 'definitions' => [self::def('likes', 'checkbox', 'Red, Blue', 1)], 'extra_columns' => ['likes']];
        $results = $this->runScenarios([
            'join'   => $common + ['action' => 'join', 'member' => null, 'post' => []],
            'update' => $common + ['action' => 'update', 'member' => ['likes' => 'Red'], 'post' => []],
            'blanks' => $common + ['action' => 'update', 'member' => ['likes' => 'Red'], 'post' => ['field_likes' => ['', '  ']]],
        ]);

        self::assertSame('Likes is required.', $results['join']['flashes']['lstr_flash_error:m1'] ?? null);
        self::assertFalse($results['join']['new_row']);
        self::assertSame('Likes is required.', $results['update']['flashes']['lstr_flash_error:m1'] ?? null);
        self::assertSame($results['update']['before'], $results['update']['row']);
        self::assertSame('Likes is required.', $results['blanks']['flashes']['lstr_flash_error:m1'] ?? null);
    }

    public function testPicksTooLongOnceJoinedAreRefused(): void
    {
        $choices = [];
        for ($i = 0; $i < 40; $i++) {
            $choices[] = sprintf('%02d', $i) . str_repeat('x', 58);
        }
        $common = ['layout' => 'owned', 'definitions' => [self::def('likes', 'checkbox', implode(', ', $choices))], 'extra_columns' => ['likes']];

        $results = $this->runScenarios([
            'join'   => $common + ['action' => 'join', 'member' => null, 'post' => ['field_likes' => $choices]],
            'update' => $common + ['action' => 'update', 'post' => ['field_likes' => $choices]],
        ]);

        self::assertSame('Likes can be at most 255 characters.', $results['join']['flashes']['lstr_flash_error:m1'] ?? null, 'the old check only saw the word "Array"');
        self::assertFalse($results['join']['new_row']);
        self::assertSame('Likes can be at most 255 characters.', $results['update']['flashes']['lstr_flash_error:m1'] ?? null);
        self::assertSame($results['update']['before'], $results['update']['row']);
    }

    public function testACoreValuePostedAsAListIsRefusedAndNothingIsSaved(): void
    {
        $common = ['layout' => 'owned'];
        $scenarios = ['join name' => $common + ['action' => 'join', 'member' => null, 'post' => ['name' => ['x']]]];
        foreach (['new_name', 'new_email', 'url', 'country'] as $key) {
            $scenarios['update ' . $key] = $common + ['action' => 'update', 'post' => [$key => ['x']]];
        }
        $results = $this->runScenarios($scenarios);

        self::assertNull($results['join name']['error']);
        self::assertSame("That value isn't valid.", $results['join name']['flashes']['lstr_flash_error:m1'] ?? null);
        self::assertFalse($results['join name']['new_row']);
        self::assertSame(0, $results['join name']['row_count'], 'used to save a member called Array');
        foreach (['new_name', 'new_email', 'url', 'country'] as $key) {
            $r = $results['update ' . $key];
            self::assertNull($r['error'], $key);
            self::assertSame("That value isn't valid.", $r['flashes']['lstr_flash_error:m1'] ?? null, $key);
            self::assertSame($r['before'], $r['row'], $key);
        }
    }

    public function testZeroIsARealAnswerInEveryControlEvenWhenRequired(): void
    {
        $defs = [
            'text'     => self::def('a', 'text', '', 1),
            'textarea' => self::def('a', 'textarea', '', 1),
            'dropdown' => self::def('a', 'dropdown', '0, 1', 1),
            'radio'    => self::def('a', 'radio', '0, 1', 1),
        ];
        $scenarios = [];
        foreach ($defs as $name => $def) {
            $scenarios[$name] = ['layout' => 'owned', 'action' => 'join', 'member' => null, 'definitions' => [$def], 'extra_columns' => ['a'], 'post' => ['field_a' => '0']];
        }
        $scenarios['checkbox'] = ['layout' => 'owned', 'action' => 'join', 'member' => null, 'definitions' => [self::def('a', 'checkbox', '0, 1', 1)], 'extra_columns' => ['a'], 'post' => ['field_a' => ['0']]];
        $scenarios['update']   = ['layout' => 'owned', 'action' => 'update', 'definitions' => [self::def('a', 'radio', '0, 1', 1)], 'extra_columns' => ['a'], 'post' => ['field_a' => '0']];

        foreach ($this->runScenarios($scenarios) as $name => $r) {
            self::assertNull($r['error'], $name);
            self::assertArrayNotHasKey('lstr_flash_error:m1', $r['flashes'], $name);
            self::assertSame('0', ($name === 'update' ? $r['row'] : $r['new_row'])['a'], $name);
        }
    }

    public function testNoSavedRowEverSaysArray(): void
    {
        $common  = ['layout' => 'owned', 'definitions' => [self::def('fave'), self::def('likes', 'checkbox', 'Red, Blue')], 'extra_columns' => ['fave', 'likes']];
        $results = $this->runScenarios([
            'join ok'      => $common + ['action' => 'join', 'member' => null, 'post' => ['field_likes' => ['Red']]],
            'join text'    => $common + ['action' => 'join', 'member' => null, 'post' => ['field_fave' => ['x']]],
            'update ok'    => $common + ['action' => 'update', 'post' => ['field_likes' => ['Blue']]],
            'update text'  => $common + ['action' => 'update', 'post' => ['field_fave' => ['x']]],
        ]);

        foreach ($results as $name => $r) {
            self::assertStringNotContainsString('Array', (string) json_encode([$r['row'], $r['new_row']]), $name);
            self::assertStringNotContainsString('Array to string', $r['log'], $name);
        }
    }

    // ------------------------------------------------------------------
    // D1: only the listed choices are accepted
    // ------------------------------------------------------------------

    public function testAnAnswerThatIsNotOnTheListIsRefusedOnJoinAndUpdate(): void
    {
        $cases = [
            'dropdown' => [self::def('a', 'dropdown', 'Cat, Dog'), 'Fish', 'A: pick one of the listed options.'],
            'select'   => [self::def('a', 'select', 'Cat, Dog'), 'Fish', 'A: pick one of the listed options.'],
            'radio'    => [self::def('a', 'radio', 'Cat, Dog'), 'Fish', 'A: pick one of the listed options.'],
            'checkbox' => [self::def('a', 'checkbox', 'Cat, Dog'), ['Cat', 'Fish'], 'A: pick from the listed options.'],
            'yes box'  => [self::def('a', 'checkbox'), 'nope', "A: that answer isn't valid."],
        ];
        foreach ($cases as $name => [$def, $posted, $message]) {
            $common  = ['layout' => 'owned', 'definitions' => [$def], 'extra_columns' => ['a']];
            $results = $this->runScenarios([
                'join'   => $common + ['action' => 'join', 'member' => null, 'post' => ['field_a' => $posted]],
                'update' => $common + ['action' => 'update', 'post' => ['field_a' => $posted]],
            ]);
            self::assertSame($message, $results['join']['flashes']['lstr_flash_error:m1'] ?? null, $name);
            self::assertFalse($results['join']['new_row'], $name);
            self::assertSame($message, $results['update']['flashes']['lstr_flash_error:m1'] ?? null, $name);
            self::assertSame($results['update']['before'], $results['update']['row'], $name);
        }
    }

    public function testChoicesChangedWhileTheFormWasOpenAsksAgainAndKeepsTheRest(): void
    {
        $r = $this->runOne(self::scenario(['action' => 'join'] + self::fourFields('owned'), ['post' => ['field_mood' => 'Angry']], self::joinPost()));

        self::assertSame('Mood: pick one of the listed options.', $r['flashes'][self::JOIN_ERROR] ?? null);
        self::assertSame('', $r['form']['field_mood'] ?? null, "it isn't offered any more, so it can't be selected");
        self::assertStringContainsString('value="Happy"', (string) $r['rendered']);
        self::assertStringContainsString('value="Sad"', (string) $r['rendered']);
        self::assertStringNotContainsString('Angry', (string) $r['rendered']);
        self::assertSame('My fave', $r['form']['field_fave'] ?? null);
        self::assertSame('New Fan', $r['form']['name'] ?? null);
    }

    public function testAnAnswerIsTidiedBeforeItIsCheckedAndThatIsWhatGetsSaved(): void
    {
        $r = $this->runOne(['layout' => 'owned', 'action' => 'join', 'member' => null, 'definitions' => [self::def('a', 'radio', 'Cat, Dog')], 'extra_columns' => ['a'], 'post' => ['field_a' => '  Dog  ']]);

        self::assertArrayNotHasKey('lstr_flash_error:m1', $r['flashes']);
        self::assertSame('Dog', $r['new_row']['a']);
    }

    // ------------------------------------------------------------------
    // A saved answer that's no longer a choice (found while building)
    // ------------------------------------------------------------------

    public function testSavingTheUpdateFormUnchangedKeepsAnAnswerThatIsNoLongerAChoice(): void
    {
        $cases = [
            'dropdown' => ['dropdown', 'Cat, Dog', 'Retired'],
            'select'   => ['select', 'Cat, Dog', 'Retired'],
            'radio'    => ['radio', 'Cat, Dog', 'Retired'],
            'group'    => ['checkbox', 'Red, Blue', 'Blue, Retired'],
            'wiped'    => ['checkbox', '', 'Red, Blue'],
            'required' => ['dropdown', 'Cat, Dog', 'Retired', 1],
        ];
        foreach (['owned', 'enth'] as $layout) {
            $column    = self::column($layout, 'a');
            $scenarios = [];
            foreach ($cases as $name => $case) {
                $scenarios[$name] = ['layout' => $layout, 'action' => 'roundtrip_update', 'definitions' => [self::def('a', $case[0], $case[1], $case[3] ?? 0)],
                    'extra_columns' => [$column], 'member' => [$column => $case[2]]];
            }
            foreach ($this->runScenarios($scenarios) as $name => $r) {
                self::assertNull($r['error'], "{$layout}, {$name}");
                self::assertArrayNotHasKey('lstr_flash_error:update:1', $r['flashes'], "{$layout}, {$name}");
                self::assertSame($r['before'], $r['row'], "{$layout}, {$name}: byte for byte");
            }
        }
    }

    public function testASavedAnswerWithSpacesAroundItIsTidiedAndNeverErased(): void
    {
        $cases = [
            'dropdown, on the list'   => ['dropdown', 'Cat, Dog', ' Cat ', 'Cat'],
            'dropdown, retired'       => ['dropdown', 'Cat, Dog', ' Retired ', 'Retired'],
            'dropdown, required'      => ['dropdown', 'Cat, Dog', ' Retired ', 'Retired', 1],
            'select, on the list'     => ['select', 'Cat, Dog', ' Dog ', 'Dog'],
            'select, retired'         => ['select', 'Cat, Dog', ' Retired ', 'Retired'],
            'radio, on the list'      => ['radio', 'Cat, Dog', ' Cat ', 'Cat'],
            'radio, retired'          => ['radio', 'Cat, Dog', ' Retired ', 'Retired'],
            'radio, required'         => ['radio', 'Cat, Dog', ' Retired ', 'Retired', 1],
            'radio, a tab and a line' => ['radio', 'Cat, Dog', "\tRetired\n", 'Retired'],
            'group, on the list'      => ['checkbox', 'Red, Blue', ' Red , Blue ', 'Red, Blue'],
            'group, retired'          => ['checkbox', 'Red, Blue', ' Blue , Retired ', 'Blue, Retired'],
            'group, required'         => ['checkbox', 'Red, Blue', ' Retired ', 'Retired', 1],
            'yes box, ticked'         => ['checkbox', '', ' 1 ', '1'],
            'yes box, old answer'     => ['checkbox', '', ' Red, Blue ', 'Red, Blue'],
            'no choices'              => ['dropdown', '', ' Typed ', 'Typed'],
            'text'                    => ['text', '', ' Typed ', 'Typed'],
            'text area'               => ['textarea', '', ' Typed ', 'Typed'],
            'zero, retired'           => ['radio', 'Cat, Dog', ' 0 ', '0'],
        ];
        foreach (['owned', 'enth'] as $layout) {
            $column    = self::column($layout, 'a');
            $scenarios = [];
            foreach ($cases as $name => $case) {
                $scenarios[$name] = ['layout' => $layout, 'action' => 'roundtrip_update', 'definitions' => [self::def('a', $case[0], $case[1], $case[4] ?? 0)],
                    'extra_columns' => [$column], 'member' => [$column => $case[2]]];
            }
            foreach ($this->runScenarios($scenarios) as $name => $r) {
                $why = "{$layout}, {$name}";
                self::assertNull($r['error'], $why);
                self::assertArrayNotHasKey('lstr_flash_error:update:1', $r['flashes'], $why);
                self::assertSame($cases[$name][3], $r['row'][$column], $why . ': tidied is fine, blank is not');
            }
        }
    }

    public function testASavedAnswerThatDiffersOnlyInCapitalsIsKeptAsItIs(): void
    {
        $r = $this->runOne(['layout' => 'owned', 'action' => 'roundtrip_update', 'definitions' => [self::def('a', 'dropdown', 'Cat, Dog')], 'extra_columns' => ['a'], 'member' => ['a' => 'cat']]);

        self::assertArrayNotHasKey('lstr_flash_error:update:1', $r['flashes']);
        self::assertSame('cat', $r['row']['a']);
    }

    public function testASavedAnswerThatIsOnlySpacesCountsAsBlank(): void
    {
        $common  = ['action' => 'roundtrip_update', 'layout' => 'owned', 'extra_columns' => ['a'], 'member' => ['a' => '   ']];
        $results = $this->runScenarios([
            'optional' => $common + ['definitions' => [self::def('a', 'dropdown', 'Cat, Dog')]],
            'required' => $common + ['definitions' => [self::def('a', 'dropdown', 'Cat, Dog', 1)]],
        ]);

        self::assertSame('', $results['optional']['row']['a']);
        self::assertSame('A is required.', $results['required']['flashes']['lstr_flash_error:m1'] ?? $results['required']['flashes']['lstr_flash_error:update:1'] ?? null, 'it says so rather than quietly saving');
        self::assertSame('   ', $results['required']['row']['a']);
    }

    public function testASavedChoiceThatWasRetiredIsOnlyOfferedToTheMemberWhoHasIt(): void
    {
        $common  = ['layout' => 'owned', 'definitions' => [self::def('a', 'dropdown', 'Cat, Dog')], 'extra_columns' => ['a']];
        $results = $this->runScenarios([
            'theirs'   => $common + ['action' => 'none', 'member' => ['a' => 'Retired'], 'render' => 'update'],
            'someone'  => $common + ['action' => 'none', 'member' => ['a' => 'Cat'], 'render' => 'update'],
            'joining'  => $common + ['action' => 'none', 'member' => null, 'render' => 'join'],
            'stuck'    => $common + ['action' => 'update', 'member' => ['a' => 'Retired'], 'post' => ['field_a' => 'Other']],
            'moves on' => $common + ['action' => 'update', 'member' => ['a' => 'Retired'], 'post' => ['field_a' => 'Dog']],
            'keeps'    => $common + ['action' => 'update', 'member' => ['a' => 'Retired'], 'post' => ['field_a' => 'Retired', 'new_name' => 'Renamed']],
        ]);

        self::assertSame('Retired', $results['theirs']['form']['field_a'] ?? null, 'selected, so they can see it');
        self::assertStringNotContainsString('Retired', (string) $results['someone']['rendered']);
        self::assertStringNotContainsString('Retired', (string) $results['joining']['rendered']);
        self::assertSame('A: pick one of the listed options.', $results['stuck']['flashes']['lstr_flash_error:m1'] ?? null);
        self::assertSame('Dog', $results['moves on']['row']['a']);
        self::assertSame('Retired', $results['keeps']['row']['a']);
        self::assertSame('Renamed', $results['keeps']['row']['name'], 'and the rest of the form saves');
    }

    public function testWhatComesBackIsEscaped(): void
    {
        $nasty   = '"><script>alert(1)</script>';
        $results = $this->runScenarios([
            'typed'  => self::scenario(['action' => 'join'] + self::fourFields('owned'), ['post' => ['captcha_answer' => '0', 'name' => $nasty, 'field_fave' => $nasty, 'field_likes' => [$nasty], 'field_mood' => $nasty]], self::joinPost()),
            'saved'  => ['layout' => 'owned', 'action' => 'none', 'render' => 'update', 'definitions' => [self::def('a', 'dropdown', 'Cat'), self::def('b', 'checkbox', 'Cat'), self::def('c', 'checkbox')],
                'extra_columns' => ['a', 'b', 'c'], 'member' => ['a' => $nasty, 'b' => $nasty, 'c' => $nasty]],
        ]);

        foreach ($results as $name => $r) {
            self::assertNull($r['error'], $name);
            self::assertStringNotContainsString('<script>alert', (string) $r['rendered'], $name);
        }
        self::assertSame($nasty, $results['typed']['form']['field_fave'] ?? null, 'kept as typed, and safe');
        self::assertSame($nasty, $results['saved']['form']['field_a'] ?? null);
    }

    public function testUntickingARetiredChoiceClearsIt(): void
    {
        $common  = ['layout' => 'owned', 'definitions' => [self::def('a', 'checkbox', 'Red, Blue')], 'extra_columns' => ['a'], 'member' => ['a' => 'Blue, Retired']];
        $results = $this->runScenarios([
            'one'   => $common + ['action' => 'update', 'post' => ['field_a' => ['Blue']]],
            'all'   => $common + ['action' => 'update', 'post' => []],
            'drawn' => $common + ['action' => 'none', 'render' => 'update'],
        ]);

        self::assertSame('Blue', $results['one']['row']['a']);
        self::assertSame('', $results['all']['row']['a']);
        self::assertSame(['Blue', 'Retired'], $results['drawn']['form']['field_a'] ?? null);
    }

    // ------------------------------------------------------------------
    // 2.1: which control a field gets
    // ------------------------------------------------------------------

    public function testAFieldWithChoicesGetsItsControlAndOneWithoutGetsAnAnswerableTextBox(): void
    {
        $scenarios = [];
        foreach (['select', 'dropdown', 'radio'] as $type) {
            foreach (['Cat, Dog' => 'choices', '' => 'none'] as $options => $label) {
                $scenarios["{$type} {$label}"] = ['layout' => 'owned', 'action' => 'none', 'member' => null, 'render' => 'join',
                    'definitions' => [self::def('a', $type, (string) $options, 1)], 'extra_columns' => ['a']];
            }
        }
        $results = $this->runScenarios($scenarios);

        foreach (['select', 'dropdown'] as $type) {
            self::assertMatchesRegularExpression('/<select[^>]*name="field_a"/', (string) $results["{$type} choices"]['rendered'], $type);
            self::assertStringContainsString('value="Dog"', (string) $results["{$type} choices"]['rendered'], $type);
        }
        self::assertMatchesRegularExpression('/type="radio"[^>]*name="field_a"/', (string) $results['radio choices']['rendered']);
        foreach (['select', 'dropdown', 'radio'] as $type) {
            $html = (string) $results["{$type} none"]['rendered'];
            self::assertMatchesRegularExpression('/<input type="text"[^>]*name="field_a"/', $html, $type);
            self::assertDoesNotMatchRegularExpression('/<select[^>]*name="field_a"/', $html, $type);
        }
    }

    public function testARequiredFieldWithNoChoicesCanBeAnsweredSoPeopleCanJoin(): void
    {
        foreach (['dropdown', 'radio', 'select'] as $type) {
            $common  = ['layout' => 'owned', 'action' => 'join', 'member' => null, 'definitions' => [self::def('a', $type, '', 1)], 'extra_columns' => ['a']];
            $results = $this->runScenarios([
                'typed' => $common + ['post' => ['field_a' => 'Anything']],
                'blank' => $common + ['post' => []],
            ]);
            self::assertSame('Anything', $results['typed']['new_row']['a'], $type . ': nobody could join before');
            self::assertSame('A is required.', $results['blank']['flashes']['lstr_flash_error:m1'] ?? null, $type);
        }
    }

    public function testAddMemberAndThePublicFormsAgreeOnAFieldWithNoChoices(): void
    {
        $common = ['layout' => 'owned', 'definitions' => [self::def('a', 'dropdown')], 'extra_columns' => ['a']];
        $r      = $this->runOne($common + ['action' => 'add', 'post' => ['field_1_a' => 'Anything']]);

        self::assertSame('Anything', $r['new_row']['a'] ?? null, 'it used to say to pick from a list that had nothing on it');
    }

    public function testASelectIsReadAsADropdownWithoutChangingWhatIsStored(): void
    {
        $serialized = serialize([self::def('a', 'select', 'Cat, Dog', 1)]);
        $results    = $this->runScenarios([
            'serialized' => ['layout' => 'enth', 'action' => 'none', 'stored_raw' => $serialized, 'read_definitions' => true, 'extra_columns' => ['additional_a']],
            'pipes'      => ['layout' => 'enth', 'action' => 'none', 'stored_raw' => 'a,Label,select,1', 'read_definitions' => true, 'extra_columns' => ['additional_a']],
        ]);

        self::assertSame('dropdown', $results['serialized']['fields']['a']['type']);
        self::assertSame('Cat, Dog', $results['serialized']['fields']['a']['options']);
        self::assertSame('select', $results['serialized']['fields']['a']['kind']);
        self::assertSame('dropdown', $results['pipes']['fields']['a']['type']);
        self::assertSame('text', $results['pipes']['fields']['a']['kind'], "an Enthusiast select has no choices stored");
        self::assertSame('Dropdown (no choices yet)', $results['pipes']['fields']['a']['type_label']);
    }

    public function testATypeThisVersionDoesNotKnowIsReadAsPlainText(): void
    {
        $results = $this->runScenarios([
            'serialized' => ['layout' => 'enth', 'action' => 'none', 'stored_raw' => serialize([self::def('a', 'carrier-pigeon', 'Cat, Dog')]), 'read_definitions' => true],
            'pipes'      => ['layout' => 'enth', 'action' => 'none', 'stored_raw' => 'a,Label,carrier-pigeon,1', 'read_definitions' => true],
        ]);

        self::assertSame('text', $results['serialized']['fields']['a']['type']);
        self::assertSame('text', $results['pipes']['fields']['a']['type']);
    }

    public function testTheFieldsPageSaysWhenAChoiceFieldHasNoChoicesYet(): void
    {
        $r = $this->runOne(['layout' => 'owned', 'action' => 'fields_page', 'definitions' => [
            self::def('a', 'dropdown'), self::def('b', 'dropdown', 'Cat, Dog'), self::def('c', 'radio'), self::def('d', 'checkbox'), self::def('e', 'textarea'),
        ], 'extra_columns' => ['a', 'b', 'c', 'd', 'e']]);

        self::assertStringContainsString('Dropdown (no choices yet)', $r['body']);
        self::assertStringContainsString('Radio Button (no choices yet)', $r['body']);
        self::assertStringContainsString('>Dropdown<', $r['body']);
        self::assertStringContainsString('>Checkbox<', $r['body'], 'a checkbox with no choices is a single yes box, not something missing');
        self::assertStringContainsString('>Text Area<', $r['body']);
    }

    public function testBothFieldListsUseTheSameLabel(): void
    {
        $root = dirname(__DIR__, 2);
        foreach (['templates/admin/listings/edit.php', 'templates/admin/listings/fields/index.php'] as $template) {
            self::assertStringContainsString('$field->typeLabel()', (string) file_get_contents($root . '/' . $template), $template);
        }
    }

    // ------------------------------------------------------------------
    // 2.3: Edit Field doesn't retype a field by accident
    // ------------------------------------------------------------------

    public function testATextAreaKeepsItsTypeThroughEditField(): void
    {
        $textarea = ['layout' => 'owned', 'definitions' => [self::def('a', 'textarea')], 'extra_columns' => ['a'], 'field' => 'a', 'read_definitions' => true];
        $text     = ['layout' => 'owned', 'definitions' => [self::def('a')], 'extra_columns' => ['a'], 'field' => 'a'];
        $results  = $this->runScenarios([
            'page'       => $textarea + ['action' => 'field_edit_get'],
            'text page'  => $text + ['action' => 'field_edit_get'],
            'save'       => $textarea + ['action' => 'field_edit', 'post' => ['field_name' => 'a', 'label' => 'New label', 'type' => 'textarea', 'required' => '0', 'options' => '']],
            'add'        => ['layout' => 'owned', 'action' => 'field_add', 'definitions' => [], 'read_definitions' => true, 'post' => ['name' => 'b', 'label' => 'B', 'type' => 'textarea', 'required' => '0']],
        ]);

        self::assertMatchesRegularExpression('/<option value="textarea"\s+selected/', $results['page']['body']);
        self::assertStringNotContainsString('value="textarea"', $results['text page']['body'], 'only offered when it already is one');
        self::assertSame('textarea', $results['save']['fields']['a']['type']);
        self::assertSame('New label', $results['save']['fields']['a']['label']);
        self::assertSame([], $results['add']['fields'], 'still not on the Add Field form');
    }

    public function testASelectIsShownAsADropdownOnEditFieldAndSavesAsOne(): void
    {
        $common  = ['layout' => 'owned', 'definitions' => [self::def('a', 'select', 'Cat, Dog')], 'extra_columns' => ['a'], 'field' => 'a', 'read_definitions' => true];
        $results = $this->runScenarios([
            'page' => $common + ['action' => 'field_edit_get'],
            'save' => $common + ['action' => 'field_edit', 'post' => ['field_name' => 'a', 'label' => 'A', 'type' => 'dropdown', 'required' => '0', 'options' => 'Cat, Dog']],
        ]);

        self::assertMatchesRegularExpression('/<option value="dropdown"\s+selected/', $results['page']['body'], 'it used to fall back to Text Field');
        self::assertSame('dropdown', $results['save']['fields']['a']['type']);
        self::assertSame('Cat, Dog', $results['save']['fields']['a']['options']);
    }
}
