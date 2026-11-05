<?php

declare(strict_types=1);

namespace Luster\Tests\Unit;

use Luster\Tests\Unit\Support\RunsMemberSaveHarness;
use PHPUnit\Framework\TestCase;

/**
 * Nothing's written that the column can't hold. Real member tables are often narrower
 * than the forms allow, and a non-strict server cuts a long value short without saying.
 * SQLite never cuts anything, so "nothing was written" is what these check. The same
 * forms run on real MariaDB, strict and not, in the release checks.
 */
final class FormColumnWidthsTest extends TestCase
{
    use RunsMemberSaveHarness;

    private const ERROR = 'lstr_flash_error:m1';
    private const PUBLIC_REFUSAL = "We couldn't save this because of a problem with the form. Nothing was changed. Please let the listing's owner know.";
    private const EDIT_DB_ERROR  = "Couldn't check this listing's member table because of a database error. Nothing was changed.";
    private const ADD_DB_ERROR   = "Couldn't save this member because of a database error. Nothing was added.";

    private static function address(int $length): string
    {
        return str_repeat('a', $length - strlen('@example.com')) . '@example.com';
    }

    private static function column(string $layout, string $field): string
    {
        return $layout === 'owned' ? $field : 'additional_' . $field;
    }

    /** One scenario per form, each posting $value to the form's own email field. */
    private static function emailOnEveryForm(string $layout, string $value, array $over = []): array
    {
        $common = $over + ['layout' => $layout];

        return [
            'join'   => $common + ['action' => 'join', 'member' => null, 'post' => ['email' => $value]],
            'add'    => $common + ['action' => 'add', 'member' => null, 'post' => ['email' => $value]],
            'edit'   => $common + ['action' => 'edit', 'post' => ['email' => $value]],
            'update' => $common + ['action' => 'update', 'post' => ['new_email' => $value]],
            'queued' => $common + ['action' => 'update', 'queue_updates' => true, 'post' => ['new_email' => $value]],
        ];
    }

    private static function assertNothingWritten(array $r, string $why): void
    {
        self::assertNull($r['error'], $why);
        self::assertSame($r['before'], $r['row'], $why . ': the member');
        self::assertSame([], $r['queue'], $why . ': the queue');
        self::assertSame(is_array($r['before']) ? 1 : 0, $r['row_count'], $why . ': the table');
    }

    private static function messageOf(array $r): string
    {
        return (string) ($r['flashes'][self::ERROR] ?? '') . htmlspecialchars_decode($r['body'], ENT_QUOTES);
    }

    // ------------------------------------------------------------------
    // What the table can hold
    // ------------------------------------------------------------------

    public function testAnEmailTooLongForTheColumnIsRefusedOnEveryForm(): void
    {
        $narrow = ['column_types' => ['email' => 'VARCHAR(64)']];
        foreach (['owned', 'enth'] as $layout) {
            foreach ($this->runScenarios(self::emailOnEveryForm($layout, self::address(65), $narrow)) as $form => $r) {
                $why = "{$layout}, {$form}";
                self::assertNothingWritten($r, $why);
                self::assertStringContainsStringIgnoringCase('email address is too long', self::messageOf($r), $why);
            }
        }
    }

    public function testAnEmailThatJustFitsIsSavedOnEveryForm(): void
    {
        $address = self::address(64);
        $narrow  = ['column_types' => ['email' => 'VARCHAR(64)']];
        foreach (['owned', 'enth'] as $layout) {
            $results = $this->runScenarios(self::emailOnEveryForm($layout, $address, $narrow));

            foreach (['join', 'add'] as $form) {
                self::assertNull($results[$form]['error'], "{$layout}, {$form}");
                self::assertSame(1, $results[$form]['row_count'], "{$layout}, {$form}");
            }
            foreach (['edit', 'update'] as $form) {
                self::assertSame(0, $this->countRows($results[$form], 'ann@example.com'), "{$layout}, {$form}: renamed");
            }
            self::assertSame($address, $results['queued']['queue'][0]['changes']['email'] ?? null, $layout);
        }
    }

    private function countRows(array $r, string $email): int
    {
        return is_array($r['row']) && $r['row']['email'] === $email ? 1 : 0;
    }

    public function testATextColumnHasNoLimitBeyondTheFormsOwn(): void
    {
        foreach (['owned', 'enth'] as $layout) {
            $long    = str_repeat('a', 60) . '@' . str_repeat('b', 60) . '.' . str_repeat('c', 60) . '.example.com';
            $results = $this->runScenarios(self::emailOnEveryForm($layout, $long));
            self::assertSame(1, $results['join']['row_count'], $layout);
            self::assertArrayNotHasKey(self::ERROR, $results['join']['flashes'], $layout);
            self::assertSame($long, $results['queued']['queue'][0]['changes']['email'] ?? null, $layout);
        }
    }

    public function testAnAnswerTooLongForItsColumnIsRefusedAndNamesTheField(): void
    {
        foreach (['owned', 'enth'] as $layout) {
            $column = self::column($layout, 'fave');
            $common = ['layout' => $layout, 'definitions' => [['name' => 'fave', 'label' => 'Favourite']], 'extra_columns' => [$column], 'column_types' => [$column => 'VARCHAR(5)']];
            $results = $this->runScenarios([
                'join'   => $common + ['action' => 'join', 'member' => null, 'post' => ['field_fave' => 'Sixsix']],
                'add'    => $common + ['action' => 'add', 'member' => null, 'post' => ['field_1_fave' => 'Sixsix']],
                'edit'   => $common + ['action' => 'edit', 'post' => ['field_fave' => 'Sixsix']],
                'update' => $common + ['action' => 'update', 'post' => ['field_fave' => 'Sixsix']],
            ]);
            foreach ($results as $form => $r) {
                self::assertNothingWritten($r, "{$layout}, {$form}");
                self::assertStringContainsString('Favourite is too long', self::messageOf($r), "{$layout}, {$form}");
            }

            $fits = $this->runOne($common + ['action' => 'update', 'post' => ['field_fave' => 'Fives']]);
            self::assertSame('Fives', $fits['row'][$column], $layout);
        }
    }

    public function testAKeptValueStillHasToFitButAUntouchedOneIsNeverAProblem(): void
    {
        $common = ['layout' => 'owned', 'column_types' => ['name' => 'VARCHAR(128)'], 'member' => ['name' => str_repeat('n', 128)]];

        $r = $this->runOne($common + ['action' => 'update', 'post' => ['country' => 'Peru']]);

        self::assertSame('Peru', $r['row']['country']);
        self::assertSame(str_repeat('n', 128), $r['row']['name']);
    }

    // ------------------------------------------------------------------
    // The new password's own column
    // ------------------------------------------------------------------

    public function testAPasswordColumnTooNarrowForTheHashRefusesRatherThanCuttingIt(): void
    {
        $pass   = 'A-Long-Enough-Password-1';
        $common = ['layout' => 'owned', 'column_types' => ['password' => 'VARCHAR(32)']];
        $results = $this->runScenarios([
            'join'          => $common + ['action' => 'join', 'member' => null, 'post' => ['password' => $pass, 'password_confirm' => $pass]],
            'join, made up' => $common + ['action' => 'join', 'member' => null, 'post' => []],
            'add'           => $common + ['action' => 'add', 'member' => null, 'post' => []],
            'update'        => $common + ['action' => 'update', 'post' => ['new_password' => $pass, 'new_password_confirm' => $pass]],
            'update, name'  => $common + ['action' => 'update', 'post' => ['new_name' => 'New Name', 'new_password' => $pass, 'new_password_confirm' => $pass]],
            'update, queue' => $common + ['action' => 'update', 'queue_updates' => true, 'post' => ['new_name' => 'New Name', 'new_password' => $pass, 'new_password_confirm' => $pass]],
        ]);

        foreach ($results as $form => $r) {
            self::assertNothingWritten($r, $form);
            self::assertStringContainsString($form === 'add' ? 'password' : self::PUBLIC_REFUSAL, self::messageOf($r), $form);
        }
        self::assertStringContainsString('password', strtolower($results['update']['log']), 'the owner can find out why');

        $noPassword = $this->runOne($common + ['action' => 'update', 'post' => ['new_name' => 'New Name']]);
        self::assertSame('New Name', $noPassword['row']['name'], "a save that doesn't change it isn't affected");
    }

    // ------------------------------------------------------------------
    // If the table can't be read
    // ------------------------------------------------------------------

    public function testAFailedReadOfTheTableRefusesOnEveryForm(): void
    {
        foreach (['owned', 'enth'] as $layout) {
            $scenarios = self::emailOnEveryForm($layout, self::address(40), ['fail_on' => '/PRAGMA table_info/']);
            foreach ($this->runScenarios($scenarios) as $form => $r) {
                $why = "{$layout}, {$form}";
                self::assertNothingWritten($r, $why);
                self::assertStringContainsString(match ($form) {
                    'add'   => self::ADD_DB_ERROR,
                    'edit'  => self::EDIT_DB_ERROR,
                    default => self::PUBLIC_REFUSAL,
                }, self::messageOf($r), $why);
            }
        }
    }

    public function testAReadThatFailsJustOnceStillRefusesTheWholeSave(): void
    {
        // Otherwise a later read failing the same way could hide a missing check.
        $once = ['fail_on' => '/PRAGMA table_info/', 'fail_times' => 1];
        foreach (['owned', 'enth'] as $layout) {
            foreach ($this->runScenarios(self::emailOnEveryForm($layout, self::address(40), $once)) as $form => $r) {
                self::assertNothingWritten($r, "{$layout}, {$form}");
            }
        }
    }

    public function testAFailedReadLeavesAPasswordChangeUnwritten(): void
    {
        $pass = 'A-Long-Enough-Password-1';
        $r    = $this->runOne(['layout' => 'owned', 'action' => 'update', 'fail_on' => '/PRAGMA table_info/', 'post' => ['new_password' => $pass, 'new_password_confirm' => $pass]]);

        self::assertNothingWritten($r, 'password only');
        self::assertSame(self::PUBLIC_REFUSAL, $r['flashes'][self::ERROR] ?? null);
    }

    // ------------------------------------------------------------------
    // Edit Member's own limits (the listed bug)
    // ------------------------------------------------------------------

    public function testEditMemberLimitsANewNameAndCountryTo100LikeEveryOtherForm(): void
    {
        foreach (['owned', 'enth'] as $layout) {
            $common  = ['layout' => $layout, 'action' => 'edit'];
            $results = $this->runScenarios([
                'name 101'          => $common + ['post' => ['name' => str_repeat('n', 101)]],
                'name 100'          => $common + ['post' => ['name' => str_repeat('n', 100)]],
                'accents 100'       => $common + ['post' => ['name' => str_repeat('é', 100)]],
                'country 101'       => $common + ['post' => ['country' => str_repeat('c', 101)]],
                'country 100'       => $common + ['post' => ['country' => str_repeat('c', 100)]],
                'unchanged 128'     => $common + ['member' => ['name' => str_repeat('n', 128)], 'post' => ['name' => str_repeat('n', 128), 'url' => 'https://new.test/']],
                'unchanged country' => $common + ['member' => ['country' => str_repeat('c', 128)], 'post' => ['country' => str_repeat('c', 128), 'name' => 'New Name']],
            ]);

            self::assertNothingWritten($results['name 101'], "{$layout}, name 101");
            self::assertStringContainsString('must not exceed 100', self::messageOf($results['name 101']), $layout);
            self::assertSame(str_repeat('n', 100), $results['name 100']['row']['name'], $layout);
            self::assertSame(str_repeat('é', 100), $results['accents 100']['row']['name'], "{$layout}: counted in letters");
            self::assertNothingWritten($results['country 101'], "{$layout}, country 101");
            self::assertSame(str_repeat('c', 100), $results['country 100']['row']['country'], $layout);
            self::assertSame('https://new.test/', $results['unchanged 128']['row']['url'], "{$layout}: an imported long name doesn't block other edits");
            self::assertSame(str_repeat('n', 128), $results['unchanged 128']['row']['name'], $layout);
            self::assertSame('New Name', $results['unchanged country']['row']['name'], $layout);
        }
    }

    public function testEditMemberStillRefusesANameTooLongForANarrowerTable(): void
    {
        $r = $this->runOne(['layout' => 'owned', 'action' => 'edit', 'column_types' => ['name' => 'VARCHAR(50)'], 'post' => ['name' => str_repeat('n', 51)]]);

        self::assertNothingWritten($r, 'name 51 of 50');
        self::assertStringContainsString('Name is too long', self::messageOf($r));
    }

    public function testTheEditMemberFormAsksForAtMost100(): void
    {
        $r = $this->runOne(['layout' => 'owned', 'action' => 'edit_get']);

        self::assertMatchesRegularExpression('/name="name"[^>]*maxlength="100"/', $r['body']);
    }

    public function testAFormRefusedForLengthKeepsWhatWasTyped(): void
    {
        $r = $this->runOne(['layout' => 'owned', 'action' => 'join', 'member' => null, 'render' => 'join', 'column_types' => ['email' => 'VARCHAR(64)'],
            'post' => ['_luster_embed_form' => 'join:1', 'email' => self::address(65), 'name' => 'Kept Name']]);

        self::assertSame('Kept Name', $r['form']['name'] ?? null);
        self::assertSame(self::address(65), $r['form']['email'] ?? null);
        self::assertStringContainsString('email address is too long', (string) ($r['flashes']['lstr_flash_error:join:1'] ?? ''));
    }

    public function testTheWidthCheckLeavesTheDatesLusterStampsAlone(): void
    {
        // Only the width check: SQLite doesn't enforce a column's length, so this can't
        // show what a real server does with a short date column. See MemberWidths::OWN_STAMPS.
        $stamps  =['column_types' => ['added' => 'VARCHAR(10)', 'updated' => 'VARCHAR(10)']];
        $results = $this->runScenarios([
            'join'  => $stamps + ['layout' => 'enth', 'action' => 'join', 'member' => null],
            'add'   => $stamps + ['layout' => 'enth', 'action' => 'add', 'member' => null],
            'owned' => $stamps + ['layout' => 'owned', 'action' => 'join', 'member' => null],
        ]);

        foreach ($results as $name => $r) {
            self::assertNull($r['error'], $name);
            self::assertSame(1, $r['row_count'], $name);
        }
    }

    public function testEachValueIsNamedInItsError(): void
    {
        $narrow = ['column_types' => ['name' => 'VARCHAR(2)', 'url' => 'VARCHAR(2)', 'country' => 'VARCHAR(2)']];
        $public = $narrow + ['layout' => 'owned', 'action' => 'update'];
        $admin  = $narrow + ['layout' => 'owned', 'action' => 'edit', 'member' => ['name' => 'Ax', 'url' => 'ht', 'country' => 'Ca']];
        $keep   = ['name' => 'Ax', 'url' => '', 'country' => 'Ca', 'email' => 'ann@example.com'];

        $results = $this->runScenarios([
            'fan name'      => $public + ['member' => ['name' => 'Ax', 'url' => '', 'country' => 'Ca'], 'post' => ['new_name' => 'Long Name']],
            'fan website'   => $public + ['member' => ['name' => 'Ax', 'url' => '', 'country' => 'Ca'], 'post' => ['url' => 'https://long.test/']],
            'fan country'   => $public + ['member' => ['name' => 'Ax', 'url' => '', 'country' => 'Ca'], 'post' => ['country' => 'Canada']],
            'admin name'    => $admin + ['post' => ['name' => 'Long Name'] + $keep],
            'admin website' => $admin + ['post' => ['url' => 'https://long.test/'] + $keep],
            'admin country' => $admin + ['post' => ['country' => 'Canada'] + $keep],
        ]);

        self::assertStringContainsString('Your name is too long', self::messageOf($results['fan name']));
        self::assertStringContainsString('Your website address is too long', self::messageOf($results['fan website']));
        self::assertStringContainsString('Your country is too long', self::messageOf($results['fan country']));
        self::assertStringContainsString('Name is too long', self::messageOf($results['admin name']));
        self::assertStringContainsString('Website address is too long', self::messageOf($results['admin website']));
        self::assertStringContainsString('Country is too long', self::messageOf($results['admin country']));
    }

    public function testAddMemberExplainsAPasswordColumnThatIsTooNarrow(): void
    {
        $r = $this->runOne(['layout' => 'owned', 'action' => 'add', 'member' => null, 'column_types' => ['password' => 'VARCHAR(32)']]);

        self::assertStringContainsString("This listing's password column is too narrow to hold a password", self::messageOf($r));
    }
}
