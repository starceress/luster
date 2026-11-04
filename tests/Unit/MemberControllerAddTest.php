<?php

declare(strict_types=1);

namespace Luster\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * The admin "Add Member" page (MemberController::add()) on both schemas, plus
 * the Members page's Add button and the Approve button's approval email, which
 * Add Member now shares.
 *
 * Every case runs the real controller in Support/member-add-harness.php, a
 * child PHP process started with -d sendmail_path pointing at
 * Support/capture-mail.php and -d error_log at a temp file, so mail() writes
 * to a file and nothing is ever really sent. (sendmail_path can't be changed
 * once PHP is running, hence the child process.) Each test sends all its
 * scenarios to one child; every scenario gets a fresh database.
 *
 * The fixture's listings: 1 Moon (every field type), 2 Sun (shares the field
 * name "fave" with Moon), 3 Closed, 4 Adopted Out, 5 with no member table,
 * 6 Star (a field named "123", and a radio "pet" like Moon's).
 */
final class MemberControllerAddTest extends TestCase
{
    private const MAIL_SEPARATOR = '--LSTR-MAIL-END--';
    private const DONE           = 'https://luster.test/admin/members?listing_id=1&status=all';
    private const DB_ERROR       = "Couldn't save this member because of a database error. Nothing was added.";

    /** A valid Add Member post for Moon, with $over on top. */
    private static function post(array $over = []): array
    {
        return array_merge([
            'listing_id' => '1',
            'email'      => 'ann@example.com',
            'name'       => 'Ann Example',
            'url'        => 'https://ann.test/',
            'country'    => 'Canada',
            'showurl'    => '1',
            'status'     => 'approved',
            'email_fan'  => '0',
        ], $over);
    }

    /** One add scenario per schema, keyed by schema. */
    private static function onBothSchemas(array $scenario): array
    {
        return [
            'owned' => ['schema' => 'owned', 'action' => 'add'] + $scenario,
            'enth'  => ['schema' => 'enth',  'action' => 'add'] + $scenario,
        ];
    }

    /** The member's status from its raw row, on either schema. */
    private static function statusOf(array $row): string
    {
        if (array_key_exists('pending', $row)) {
            return ['approved', 'pending', 'rejected'][(int) $row['pending']];
        }
        return (int) $row['approved'] === 1 ? 'approved' : 'pending';
    }

    /** The column an extra field is saved under on $schema. */
    private static function col(string $schema, string $field): string
    {
        return $schema === 'owned' ? $field : 'additional_' . $field;
    }

    private static function lastUpdatedTouches(array $r): int
    {
        return count(array_filter($r['statements'], static fn(string $sql): bool => str_contains($sql, 'ON DUPLICATE KEY UPDATE last_updated')));
    }

    private static function memberInserts(array $r): int
    {
        return count(array_filter($r['statements'], static fn(string $sql): bool => preg_match('/^INSERT INTO fl_/', $sql) === 1));
    }

    /** Nothing saved, the form shown again with $message, and no flash. */
    private static function assertRefused(array $r, string $message, string $why): void
    {
        self::assertNull($r['error'], $why . ': the controller should not throw');
        self::assertSame(200, $r['status'], $why . ': the form is shown again');
        self::assertStringContainsString(htmlspecialchars($message, ENT_QUOTES), $r['body'], $why);
        self::assertSame(0, self::memberInserts($r), $why . ': no member insert');
        foreach ($r['members'] as $id => $rows) {
            self::assertSame([], array_values(array_filter($rows, static fn(array $m): bool => $m['email'] === 'ann@example.com')),
                "{$why}: nothing saved in listing #{$id}");
        }
        self::assertNull($r['flash_success'], $why . ': nothing reported as added');
        self::assertSame([], $r['activity'], $why . ': nothing logged as added');
        self::assertSame([], $r['mails'], $why . ': no email');
    }

    // ------------------------------------------------------------------
    // Saving and emailing
    // ------------------------------------------------------------------

    public function testApprovedWithEmailOffSavesAnApprovedMemberAndSendsNothing(): void
    {
        foreach ($this->runScenarios(self::onBothSchemas(['post' => self::post()])) as $schema => $r) {
            self::assertNull($r['error'], $schema);
            self::assertSame(self::DONE, $r['redirect'], $schema);
            self::assertSame('Member added.', $r['flash_success'], $schema);

            self::assertCount(1, $r['members'][1], $schema);
            $m = $r['members'][1][0];
            self::assertSame('approved', self::statusOf($m), $schema);
            self::assertSame(['ann@example.com', 'Ann Example', 'https://ann.test/', 'Canada', 1],
                [$m['email'], $m['name'], $m['url'], $m['country'], (int) $m['showurl']], $schema);
            self::assertSame('argon2id', password_get_info((string) $m['password'])['algoName'], $schema);
            self::assertNotSame('', (string) $m['added'], $schema);

            self::assertSame([], $r['mails'], $schema . ': email off sends nothing');
            self::assertSame(1, self::lastUpdatedTouches($r), $schema . ': Last Updated moves for an approved member');
            self::assertSame([['action' => 'member.add', 'description' => 'Added member ann@example.com to listing #1 as approved']],
                $r['activity'], $schema);
            self::assertSame([['listing_id' => 1, 'member_email' => 'ann@example.com', 'member_name' => 'Ann Example', 'member_url' => 'https://ann.test/']],
                $r['link_checks'], $schema);
        }
    }

    public function testRadiosThatArentPostedTakeTheFormsDefaults(): void
    {
        $post = self::post(['url' => '']);
        unset($post['status'], $post['email_fan'], $post['showurl']);

        foreach ($this->runScenarios(self::onBothSchemas(['post' => $post])) as $schema => $r) {
            self::assertSame(self::DONE, $r['redirect'], $schema);
            self::assertSame('approved', self::statusOf($r['members'][1][0]), $schema . ': Approved by default');
            self::assertSame(1, (int) $r['members'][1][0]['showurl'], $schema . ': Show by default');
            self::assertSame([], $r['mails'], $schema . ': Email Fan? is off by default');
            self::assertSame([], $r['link_checks'], $schema . ': no URL, no link check');
        }
    }

    public function testApprovedWithEmailOnSendsOnlyTheApprovalEmailWithTheFansDetails(): void
    {
        $template = ['fanlisting_id' => 1, 'type' => 'approve', 'enabled' => 1,
            'subject' => 'Welcome {fan_name}',
            'body'    => 'URL={fan_url} COUNTRY={fan_country} RESET={reset_url} PASSWORD=[{fan_password}]'];

        foreach ($this->runScenarios(self::onBothSchemas(['post' => self::post(['email_fan' => '1']), 'templates' => [$template]])) as $schema => $r) {
            self::assertSame('Member added and emailed.', $r['flash_success'], $schema);
            self::assertCount(1, $r['mails'], $schema . ': only the approval email, no owner notification');
            $mail = $r['mails'][0];
            self::assertStringContainsString('ann@example.com', $mail, $schema);
            self::assertStringContainsString('Subject: Welcome Ann Example', $mail, $schema);
            self::assertStringContainsString('URL=https://ann.test/ COUNTRY=Canada RESET=https://fans.test/moon/reset.php PASSWORD=[]', $mail, $schema);
            self::assertSame('approved', self::statusOf($r['members'][1][0]), $schema);
        }
    }

    public function testPendingWithEmailOffIsPendingSendsNothingAndLeavesLastUpdated(): void
    {
        foreach ($this->runScenarios(self::onBothSchemas(['post' => self::post(['status' => 'pending'])])) as $schema => $r) {
            self::assertSame(self::DONE, $r['redirect'], $schema);
            self::assertSame('Member added.', $r['flash_success'], $schema);
            self::assertSame('pending', self::statusOf($r['members'][1][0]), $schema);
            self::assertSame([], $r['mails'], $schema);
            self::assertSame(0, self::lastUpdatedTouches($r), $schema . ': a pending member is not listing activity');
            self::assertSame([], $r['link_checks'], $schema . ': no link check until approved');
            self::assertSame('Added member ann@example.com to listing #1 as pending', $r['activity'][0]['description'], $schema);
        }
    }

    public function testPendingWithEmailOnSendsTheJoinEmailWithAPasswordThatWorks(): void
    {
        foreach ($this->runScenarios(self::onBothSchemas(['post' => self::post(['status' => 'pending', 'email_fan' => '1'])])) as $schema => $r) {
            self::assertSame('Member added and emailed.', $r['flash_success'], $schema);
            self::assertCount(1, $r['mails'], $schema . ': only the join email, no owner notification');
            $mail = $r['mails'][0];
            self::assertStringContainsString('Subject: Thank you for joining Moon FL!', $mail, $schema);
            self::assertStringContainsString('in queue for approval', $mail, $schema);
            self::assertStringContainsString('https://fans.test/moon/reset.php', $mail, $schema);
            self::assertSame(1, preg_match('/Password:\s*(\S+)/', $mail, $pw), $schema . ': the join email shows the password');
            self::assertTrue(password_verify($pw[1], (string) $r['members'][1][0]['password']), $schema . ': the emailed password is the saved one');
        }
    }

    public function testATurnedOffTemplateOrAFailedSendStillSavesTheMember(): void
    {
        $off = static fn(string $type): array => ['fanlisting_id' => 1, 'type' => $type, 'enabled' => 0, 'subject' => 'x', 'body' => 'x'];
        $results = $this->runScenarios([
            'approve off'  => ['schema' => 'owned', 'action' => 'add', 'post' => self::post(['email_fan' => '1']), 'templates' => [$off('approve')]],
            'join off'     => ['schema' => 'enth', 'action' => 'add', 'post' => self::post(['email_fan' => '1', 'status' => 'pending']), 'templates' => [$off('join')]],
            'smtp approve' => ['schema' => 'owned', 'action' => 'add', 'post' => self::post(['email_fan' => '1']), 'smtp_fail' => true],
            'smtp join'    => ['schema' => 'enth', 'action' => 'add', 'post' => self::post(['email_fan' => '1', 'status' => 'pending']), 'smtp_fail' => true],
        ]);

        foreach (['approve off', 'join off'] as $case) {
            self::assertSame('Member added. No email went out because that email template is turned off.', $results[$case]['flash_success'], $case);
        }
        foreach (['smtp approve', 'smtp join'] as $case) {
            self::assertSame("Member added, but the email couldn't be sent.", $results[$case]['flash_success'], $case);
        }
        self::assertStringContainsString('[Luster] Approve email failed', $results['smtp approve']['log']);
        self::assertStringContainsString('[Luster] Join email failed', $results['smtp join']['log']);

        foreach ($results as $case => $r) {
            self::assertSame(self::DONE, $r['redirect'], $case);
            self::assertCount(1, $r['members'][1], $case . ': the member is saved either way');
            self::assertSame([], $r['mails'], $case);
        }
    }

    /** The Approve button now goes through the same approval-email helper. */
    public function testTheApproveButtonStillSendsOneApprovalEmailNowWithTheFansDetails(): void
    {
        $existing = [['listing' => 1, 'email' => 'kai@example.com', 'name' => 'Kai', 'url' => 'https://kai.test/', 'country' => 'Japan', 'status' => 'pending']];
        $custom   = ['fanlisting_id' => 1, 'type' => 'approve', 'enabled' => 1,
            'subject' => 'Approved: {fan_name}', 'body' => 'URL={fan_url} COUNTRY={fan_country} RESET={reset_url}'];
        $approve  = ['action' => 'approve', 'listing' => 1, 'post' => ['member_email' => 'kai@example.com'], 'existing' => $existing];

        $results = $this->runScenarios([
            'owned default' => ['schema' => 'owned'] + $approve,
            'enth default'  => ['schema' => 'enth'] + $approve,
            'owned custom'  => ['schema' => 'owned', 'templates' => [$custom]] + $approve,
            'enth custom'   => ['schema' => 'enth', 'templates' => [$custom]] + $approve,
        ]);

        foreach ($results as $case => $r) {
            self::assertNull($r['error'], $case);
            self::assertSame('Member approved.', $r['flash_success'], $case);
            self::assertSame('approved', self::statusOf($r['members'][1][0]), $case);
            self::assertSame(1, self::lastUpdatedTouches($r), $case);
            self::assertCount(1, $r['mails'], $case);
        }
        foreach (['owned default', 'enth default'] as $case) {
            self::assertStringContainsString('Subject: Your Moon FL membership has been approved', $results[$case]['mails'][0], $case);
            self::assertStringContainsString('Hi Kai,', $results[$case]['mails'][0], $case);
            self::assertStringContainsString('has been approved!', $results[$case]['mails'][0], $case);
        }
        foreach (['owned custom', 'enth custom'] as $case) {
            self::assertStringContainsString('Subject: Approved: Kai', $results[$case]['mails'][0], $case);
            self::assertStringContainsString('URL=https://kai.test/ COUNTRY=Japan RESET=https://fans.test/moon/reset.php', $results[$case]['mails'][0], $case);
        }
    }

    // ------------------------------------------------------------------
    // Additional fields
    // ------------------------------------------------------------------

    public function testExtraFieldsAreSavedUnderEachSchemasColumnsAndRequiredOnesCanBeBlank(): void
    {
        $four = array_map(static fn(string $c): string => str_repeat($c, 60), ['a', 'b', 'c', 'd']);
        $post = self::post([
            'field_1_fave'     => '  Moonlight  ',
            'field_1_mood'     => 'Sad',
            'field_1_era'      => 'New',
            'field_1_pet'      => 'Dog',
            'field_1_likes'    => ['Red', ' Green ', ''],
            'field_1_tags'     => $four,
            'field_1_agree'    => '1',
            'field_1_bio'      => 'Hello there',
            'field_1_musthave' => '',
        ]);

        foreach ($this->runScenarios(self::onBothSchemas(['post' => $post])) as $schema => $r) {
            self::assertSame(self::DONE, $r['redirect'], $schema . ': ' . strip_tags($r['body']));
            $m = $r['members'][1][0];
            $saved = [];
            foreach (['fave', 'mood', 'era', 'pet', 'likes', 'tags', 'agree', 'bio', 'musthave'] as $f) {
                $saved[$f] = $m[self::col($schema, $f)];
            }
            self::assertSame([
                'fave'     => 'Moonlight',
                'mood'     => 'Sad',
                'era'      => 'New',
                'pet'      => 'Dog',
                'likes'    => 'Red, Green',
                'tags'     => implode(', ', $four),
                'agree'    => '1',
                'bio'      => 'Hello there',
                'musthave' => '',
            ], $saved, $schema);
            self::assertSame(246, strlen($saved['tags']), 'Four 60-letter picks joined: just under the limit');
        }
    }

    public function testBadExtraFieldAnswersAreRefused(): void
    {
        $five    = array_map(static fn(string $c): string => str_repeat($c, 60), ['a', 'b', 'c', 'd', 'e']);
        $cases   = [
            'joined picks over 255'      => [['field_1_tags'  => $five],      'Tags can be at most 255 characters.'],
            'array to a text field'      => [['field_1_fave'  => ['x']],      "Favourite song: that answer isn't valid."],
            'array to a select'          => [['field_1_mood'  => ['Sad']],    "Mood: that answer isn't valid."],
            'nested checkbox picks'      => [['field_1_likes' => [['Red']]],  "Likes: that answer isn't valid."],
            'select-type field off list' => [['field_1_era'   => 'Middle'],   'Era: pick one of the listed options.'],
            'dropdown off list'          => [['field_1_mood'  => 'Angry'],    'Mood: pick one of the listed options.'],
            'radio off list'             => [['field_1_pet'   => 'Fish'],     'Pet: pick one of the listed options.'],
            'checkbox pick off list'     => [['field_1_likes' => ['Purple']], 'Likes: pick from the listed options.'],
            'single checkbox not 1'      => [['field_1_agree' => 'yes'],      "Agree: that answer isn't valid."],
            'long text'                  => [['field_1_fave'  => str_repeat('x', 256)], 'Favourite song can be at most 255 characters.'],
        ];
        $scenarios = [];
        foreach ($cases as $name => [$over]) {
            foreach (['owned', 'enth'] as $schema) {
                $scenarios["{$name} ({$schema})"] = ['schema' => $schema, 'action' => 'add', 'post' => self::post($over)];
            }
        }

        $results = $this->runScenarios($scenarios);
        foreach ($cases as $name => [, $message]) {
            foreach (['owned', 'enth'] as $schema) {
                self::assertRefused($results["{$name} ({$schema})"], $message, "{$name} ({$schema})");
            }
        }
    }

    public function testOnlyThePostedListingsOwnFieldsAreRead(): void
    {
        // Every listing's answers posted at once (a crafted post; the form only
        // posts the selected group), plus an unscoped name from before the
        // names carried the listing id.
        $everything = [
            'field_1_fave' => 'Moon song', 'field_1_mood' => 'Angry', 'field_1_pet' => 'Dog',
            'field_2_fave' => 'Sun song', 'field_2_sunonly' => 'ray',
            'field_6_pet'  => 'Cat',
            'field_fave'   => 'Unscoped',
        ];
        $scenarios = [];
        foreach (['owned', 'enth'] as $schema) {
            // Moon's own mood answer is off its list, so Moon is posted without it.
            $scenarios["moon {$schema}"] = ['schema' => $schema, 'action' => 'add', 'post' => self::post(['field_1_mood' => 'Sad'] + $everything)];
            $scenarios["sun {$schema}"]  = ['schema' => $schema, 'action' => 'add', 'post' => self::post(['listing_id' => '2'] + $everything)];
            $scenarios["star {$schema}"] = ['schema' => $schema, 'action' => 'add', 'post' => self::post(['listing_id' => '6'] + $everything)];
        }
        $results = $this->runScenarios($scenarios);

        foreach (['owned', 'enth'] as $schema) {
            $moon = $results["moon {$schema}"];
            self::assertSame(self::DONE, $moon['redirect'], $schema . ': ' . strip_tags($moon['body']));
            self::assertSame(['Moon song', 'Sad', 'Dog'], [
                $moon['members'][1][0][self::col($schema, 'fave')],
                $moon['members'][1][0][self::col($schema, 'mood')],
                $moon['members'][1][0][self::col($schema, 'pet')],
            ], $schema . ": Moon's own answers, not Star's pet or the unscoped fave");
            self::assertArrayNotHasKey(self::col($schema, 'sunonly'), $moon['members'][1][0], $schema);

            $sun = $results["sun {$schema}"];
            self::assertSame('https://luster.test/admin/members?listing_id=2&status=all', $sun['redirect'], $schema . ": Moon's off-list mood isn't checked for Sun");
            self::assertSame(['Sun song', 'ray'], [
                $sun['members'][2][0][self::col($schema, 'fave')],
                $sun['members'][2][0][self::col($schema, 'sunonly')],
            ], $schema . ': the shared name goes to Sun');
            self::assertSame([], $sun['members'][1], $schema);

            $star = $results["star {$schema}"];
            self::assertSame('https://luster.test/admin/members?listing_id=6&status=all', $star['redirect'], $schema);
            self::assertSame('Cat', $star['members'][6][0][self::col($schema, 'pet')], $schema . ": Star's pet, not Moon's");
        }
    }

    /**
     * Enthusiast allows a field named like "123". PHP turns such a column name
     * into an int array key, which used to reach string-only code and crash
     * (saving, and reading the member back) on the unprefixed schema.
     */
    public function testAFieldWithANumericNameIsSavedAndReadBack(): void
    {
        $kai  = ['listing' => 6, 'email' => 'kai@example.com', 'name' => 'Kai', 'status' => 'approved', 'extra' => ['123' => '7']];
        $add  = static fn(array $over, array $extra = []): array => ['action' => 'add', 'post' => self::post(['listing_id' => '6'] + $over)] + $extra;
        $scenarios = [];
        foreach (['owned', 'enth'] as $schema) {
            $scenarios["filled {$schema}"]     = ['schema' => $schema] + $add(['field_6_123' => ' 42 ', 'field_6_pet' => 'Dog']);
            $scenarios["blank {$schema}"]      = ['schema' => $schema] + $add([]);
            $scenarios["emailed {$schema}"]    = ['schema' => $schema] + $add(['field_6_123' => '42', 'email_fan' => '1'], ['existing' => [$kai]]);
            $scenarios["duplicate {$schema}"]  = ['schema' => $schema] + $add([], ['existing' => [['email' => 'ann@example.com'] + $kai]]);
            $scenarios["page {$schema}"]       = ['schema' => $schema, 'action' => 'overview', 'query' => ['listing_id' => '6', 'status' => 'all'], 'existing' => [$kai]];
        }
        $results = $this->runScenarios($scenarios);
        $done    = 'https://luster.test/admin/members?listing_id=6&status=all';

        foreach (['owned', 'enth'] as $schema) {
            $col = self::col($schema, '123');

            $r = $results["filled {$schema}"];
            self::assertNull($r['error'], $schema);
            self::assertSame($done, $r['redirect'], $schema);
            self::assertSame(['42', 'Dog'], [$r['members'][6][0][$col], $r['members'][6][0][self::col($schema, 'pet')]], $schema);

            $r = $results["blank {$schema}"];
            self::assertNull($r['error'], $schema . ': blank');
            self::assertSame($done, $r['redirect'], $schema . ': blank');
            self::assertSame('', $r['members'][6][0][$col], $schema . ': blank');

            // The approval email reads the saved row back as a Member.
            $r = $results["emailed {$schema}"];
            self::assertNull($r['error'], $schema . ': emailed');
            self::assertSame('Member added and emailed.', $r['flash_success'], $schema . ': emailed');
            self::assertCount(2, $r['members'][6], $schema . ': emailed');
            self::assertCount(1, $r['mails'], $schema . ': emailed');

            // The duplicate check reads the existing member back.
            $r = $results["duplicate {$schema}"];
            self::assertNull($r['error'], $schema . ': duplicate');
            self::assertSame(200, $r['status'], $schema . ': duplicate');
            self::assertStringContainsString('This email is already in this listing (Approved).', $r['body'], $schema . ': duplicate');
            self::assertSame(0, self::memberInserts($r), $schema . ': duplicate');
            self::assertCount(1, $r['members'][6], $schema . ': duplicate');

            $r = $results["page {$schema}"];
            self::assertNull($r['error'], $schema . ': Members page');
            self::assertSame(200, $r['status'], $schema . ': Members page');
            self::assertStringContainsString('kai@example.com', $r['body'], $schema . ': Members page');
        }
    }

    // ------------------------------------------------------------------
    // Limits and refusals
    // ------------------------------------------------------------------

    public function testRefusals(): void
    {
        $cases = [
            'no listing'           => [['listing_id' => ''],    'Choose a listing.'],
            'listing id as array'  => [['listing_id' => ['1']], 'Choose a listing.'],
            'unknown listing'      => [['listing_id' => '99'],  "That listing can't take new members: it's closed, adopted out, or has no member table."],
            'closed listing'       => [['listing_id' => '3'],   "That listing can't take new members: it's closed, adopted out, or has no member table."],
            'adopted out listing'  => [['listing_id' => '4'],   "That listing can't take new members: it's closed, adopted out, or has no member table."],
            'no member table'      => [['listing_id' => '5'],   "That listing can't take new members: it's closed, adopted out, or has no member table."],
            'name of 101'          => [['name' => str_repeat('n', 101)], 'The name field must not exceed 100 characters.'],
            'blank name'           => [['name' => ''],          'The name field is required.'],
            'name as array'        => [['name' => ['Ann']],     "That value isn't valid."],
            'bad email'            => [['email' => 'nope'],     'Please enter a valid email address.'],
            'bad url'              => [['url' => 'not a url'],  'Please enter a valid URL'],
            'unknown country'      => [['country' => 'Atlantis'], 'Choose a country from the list.'],
            'status rejected'      => [['status' => 'rejected'], 'Choose Approved or Pending.'],
            'status nonsense'      => [['status' => 'banana'],  'Choose Approved or Pending.'],
            'email fan nonsense'   => [['email_fan' => '2'],    'Choose Off or On.'],
            'show website nonsense'=> [['showurl' => 'maybe'],  'Choose Show or Hide.'],
        ];
        $scenarios = [];
        foreach ($cases as $name => [$over]) {
            foreach (['owned', 'enth'] as $schema) {
                // Email Fan? on, so a refusal that slipped through would show as mail.
                $scenarios["{$name} ({$schema})"] = ['schema' => $schema, 'action' => 'add', 'post' => self::post($over + ['email_fan' => '1'])];
            }
        }

        $results = $this->runScenarios($scenarios);
        foreach ($cases as $name => [, $message]) {
            foreach (['owned', 'enth'] as $schema) {
                self::assertRefused($results["{$name} ({$schema})"], $message, "{$name} ({$schema})");
            }
        }
    }

    public function testANameOfExactly100IsFine(): void
    {
        foreach ($this->runScenarios(self::onBothSchemas(['post' => self::post(['name' => str_repeat('n', 100)])])) as $schema => $r) {
            self::assertSame(self::DONE, $r['redirect'], $schema);
            self::assertSame(str_repeat('n', 100), $r['members'][1][0]['name'], $schema);
        }
    }

    public function testAnEmailAlreadyInTheListingIsRefusedWhateverItsStatus(): void
    {
        $scenarios = [];
        // The standard schema has no rejected state.
        foreach (['owned' => ['pending', 'approved', 'rejected'], 'enth' => ['pending', 'approved']] as $schema => $statuses) {
            foreach ($statuses as $status) {
                $scenarios["{$schema} {$status}"] = ['schema' => $schema, 'action' => 'add', 'post' => self::post(['email_fan' => '1']),
                    'existing' => [['listing' => 1, 'email' => 'ann@example.com', 'status' => $status]]];
            }
        }

        foreach ($this->runScenarios($scenarios) as $case => $r) {
            $label = ucfirst(explode(' ', $case)[1]);
            self::assertSame(200, $r['status'], $case);
            self::assertStringContainsString(htmlspecialchars("This email is already in this listing ({$label}).", ENT_QUOTES), $r['body'], $case);
            self::assertSame(0, self::memberInserts($r), $case);
            self::assertCount(1, $r['members'][1], $case . ': only the existing member');
            self::assertNull($r['flash_success'], $case);
            self::assertSame([], $r['mails'], $case);
        }
    }

    public function testTheSameEmailCanBeAddedToAnotherListing(): void
    {
        $existing = [['listing' => 2, 'email' => 'ann@example.com', 'status' => 'approved']];
        foreach ($this->runScenarios(self::onBothSchemas(['post' => self::post(), 'existing' => $existing])) as $schema => $r) {
            self::assertSame(self::DONE, $r['redirect'], $schema);
            self::assertCount(1, $r['members'][1], $schema);
            self::assertCount(1, $r['members'][2], $schema);
        }
    }

    // ------------------------------------------------------------------
    // Database failures
    // ------------------------------------------------------------------

    public function testOnlyMySqlsDuplicateEntryErrorCountsAsAlreadyHere(): void
    {
        $failing = static fn(array $info): array => ['post' => self::post(['email_fan' => '1']), 'fail_on' => '/^INSERT INTO fl_moon/', 'fail_error_info' => $info];
        $results = $this->runScenarios([
            'duplicate owned' => ['schema' => 'owned', 'action' => 'add'] + $failing(['23000', 1062, "Duplicate entry 'ann@example.com' for key 'PRIMARY'"]),
            'duplicate enth'  => ['schema' => 'enth', 'action' => 'add'] + $failing(['23000', 1062, "Duplicate entry 'ann@example.com' for key 'email'"]),
            'null owned'      => ['schema' => 'owned', 'action' => 'add'] + $failing(['23000', 1048, "Column 'name' cannot be null"]),
            'null enth'       => ['schema' => 'enth', 'action' => 'add'] + $failing(['23000', 1048, "Column 'name' cannot be null"]),
            'too long owned'  => ['schema' => 'owned', 'action' => 'add'] + $failing(['22001', 1406, "Data too long for column 'name'"]),
            'too long enth'   => ['schema' => 'enth', 'action' => 'add'] + $failing(['22001', 1406, "Data too long for column 'name'"]),
        ]);

        foreach ($results as $case => $r) {
            self::assertNull($r['error'], $case);
            self::assertSame(200, $r['status'], $case);
            self::assertSame(1, self::memberInserts($r), $case . ': the insert was tried');
            self::assertSame([], $r['members'][1], $case);
            self::assertNull($r['flash_success'], $case . ': nothing reported as added');
            self::assertSame([], $r['activity'], $case);
            self::assertSame([], $r['mails'], $case);
            self::assertSame(0, self::lastUpdatedTouches($r), $case);
        }
        foreach (['duplicate owned', 'duplicate enth'] as $case) {
            self::assertStringContainsString('This email is already in this listing.', $results[$case]['body'], $case);
            self::assertStringNotContainsString(htmlspecialchars(self::DB_ERROR, ENT_QUOTES), $results[$case]['body'], $case);
            self::assertStringNotContainsString('Add Member', $results[$case]['log'], $case . ': a duplicate is not logged');
        }
        foreach (['null owned', 'null enth', 'too long owned', 'too long enth'] as $case) {
            self::assertStringContainsString(htmlspecialchars(self::DB_ERROR, ENT_QUOTES), $results[$case]['body'], $case);
            self::assertStringNotContainsString('already in this listing', $results[$case]['body'], $case . ': not guessed to be a duplicate');
            self::assertStringContainsString('[Luster] Add Member: saving ann@example.com to listing #1 failed', $results[$case]['log'], $case);
        }
    }

    public function testALinkCheckerFailureDoesntStopTheAdd(): void
    {
        foreach ($this->runScenarios(self::onBothSchemas(['post' => self::post(), 'fail_on' => '/lstr_member_link_checks/'])) as $schema => $r) {
            self::assertNull($r['error'], $schema);
            self::assertSame(self::DONE, $r['redirect'], $schema);
            self::assertSame('Member added.', $r['flash_success'], $schema);
            self::assertCount(1, $r['members'][1], $schema);
            self::assertNotSame([], array_filter($r['statements'], static fn(string $s): bool => str_contains($s, 'lstr_member_link_checks')), $schema . ': the link check was tried');
        }
    }

    // ------------------------------------------------------------------
    // The pages
    // ------------------------------------------------------------------

    public function testTheFormOffersOnlyListingsThatCanTakeMembersAndPreselects(): void
    {
        $results = $this->runScenarios([
            'moon'     => ['schema' => 'owned', 'action' => 'get', 'query' => ['listing_id' => '1']],
            'closed'   => ['schema' => 'owned', 'action' => 'get', 'query' => ['listing_id' => '3']],
            'none'     => ['schema' => 'enth', 'action' => 'get'],
        ]);

        foreach ($results as $case => $r) {
            self::assertNull($r['error'], $case);
            self::assertSame(200, $r['status'], $case);
            $listingSelect = self::between($r['body'], '<select id="ma-listing"', '</select>');
            self::assertMatchesRegularExpression('/<option value="2"/', $listingSelect, $case);
            foreach (['3', '4', '5'] as $id) {
                self::assertDoesNotMatchRegularExpression('/<option value="' . $id . '"/', $listingSelect, "{$case}: listing {$id} can't take members");
            }
            // The form's defaults: Approved, Email Fan? off, website shown.
            self::assertMatchesRegularExpression('/name="status" value="approved" data-ma-default checked/', $r['body'], $case);
            self::assertMatchesRegularExpression('/name="email_fan" value="0" data-ma-default checked/', $r['body'], $case);
            self::assertMatchesRegularExpression('/name="showurl" value="1" data-ma-default checked/', $r['body'], $case);
        }

        $moon = $results['moon']['body'];
        self::assertMatchesRegularExpression('/<option value="1" selected>/', $moon);
        self::assertStringContainsString('<div class="card-body" data-member-add-fields="1">', $moon, 'Moon\'s fields shown');
        self::assertStringContainsString('<div class="card-body" data-member-add-fields="2" hidden>', $moon, 'Sun\'s fields hidden');
        self::assertMatchesRegularExpression('/<select id="ma-f-1-era" name="field_1_era">\s*<option value="">[^<]*<\/option>\s*<option value="Old">Old<\/option>\s*<option value="New">New<\/option>/', $moon, 'A select-type field is a select with its options');
        self::assertMatchesRegularExpression('/id="ma-f-1-likes-2"\s+type="checkbox"\s+name="field_1_likes\[\]"\s+value="Green"/', $moon, 'Checkbox groups are numbered and skip blank options');
        self::assertMatchesRegularExpression('/id="ma-f-1-pet-1"\s+type="radio"\s+name="field_1_pet"\s+value="Dog"/', $moon);
        self::assertMatchesRegularExpression('/<input id="ma-f-2-fave" type="text" name="field_2_fave"\s+class="form" maxlength="255"\s+value="" disabled>/', $moon, 'Sun\'s inputs are disabled');
        self::assertStringNotContainsString('id="ma-f-1-musthave" type="text" name="field_1_musthave" required', $moon);
        self::assertDoesNotMatchRegularExpression('/data-member-add-fields="1".*?required/s', self::between($moon, 'data-member-add-fields="1"', 'data-member-add-fields="2"'), 'No required attribute on extra fields');

        // Every control carries its listing's id, so no two listings share a
        // name: browsers treat radios with one name as one group even while
        // hidden and disabled, and picking Star's pet would untick Moon's.
        preg_match_all('/data-member-add-fields="(\d+)"(.*?)(?=data-member-add-fields=|<div class="composer-footer">)/s', $moon, $groups, PREG_SET_ORDER);
        $namesByGroup = [];
        foreach ($groups as [, $id, $html]) {
            preg_match_all('/\bname="([^"]+)"/', $html, $names);
            $namesByGroup[(int) $id] = array_values(array_unique($names[1]));
        }
        self::assertSame([1, 2, 6], array_keys($namesByGroup));
        foreach ($namesByGroup as $id => $names) {
            self::assertNotSame([], $names, "listing {$id}");
            foreach ($names as $name) {
                self::assertStringStartsWith("field_{$id}_", $name, "listing {$id}");
            }
        }
        self::assertContains('field_1_pet', $namesByGroup[1]);
        self::assertSame(['field_6_123', 'field_6_pet'], $namesByGroup[6]);

        foreach (['closed', 'none'] as $case) {
            self::assertDoesNotMatchRegularExpression('/<option value="\d+" selected>/', self::between($results[$case]['body'], '<select id="ma-listing"', '</select>'), $case . ': nothing preselected');
            self::assertStringContainsString('<div class="card-body" data-member-add-fields="1" hidden>', $results[$case]['body'], $case);
        }
    }

    public function testAnErrorKeepsWhatWasTypedInTheSelectedListingsFieldsOnly(): void
    {
        [$r] = array_values($this->runScenarios([[
            'schema' => 'owned', 'action' => 'add',
            'post'   => self::post(['name' => '', 'field_1_fave' => 'Moonlight', 'field_1_likes' => ['Blue'], 'status' => 'pending']),
        ]]));

        self::assertSame(200, $r['status']);
        self::assertMatchesRegularExpression('/<input id="ma-f-1-fave" type="text" name="field_1_fave"\s+class="form" maxlength="255"\s+value="Moonlight">/', $r['body']);
        self::assertMatchesRegularExpression('/<input id="ma-f-2-fave" type="text" name="field_2_fave"\s+class="form" maxlength="255"\s+value="" disabled>/', $r['body'], 'Not copied into Sun\'s field of the same name');
        self::assertMatchesRegularExpression('/value="Blue" checked>/', $r['body']);
        self::assertMatchesRegularExpression('/name="status" value="pending" checked/', $r['body']);
        self::assertMatchesRegularExpression('/id="ma-email" type="email" name="email" class="form"\s+maxlength="255" required\s+value="ann@example.com"/', $r['body']);
    }

    public function testNameComesBeforeEmailOnTheFormAndInTheErrorSummary(): void
    {
        [$r] = array_values($this->runScenarios([[
            'schema' => 'owned', 'action' => 'add', 'post' => self::post(['name' => '', 'email' => 'nope']),
        ]]));

        self::assertSame(200, $r['status']);
        self::assertLessThan(strpos($r['body'], 'id="ma-email"'), strpos($r['body'], 'id="ma-name"'), 'Name is the first field after the listing');
        self::assertStringContainsString(
            '<div class="alert alert-error">' . htmlspecialchars('The name field is required. Please enter a valid email address.', ENT_QUOTES) . '</div>',
            $r['body'],
        );
    }

    public function testTheMembersPageHasAnAddButtonExceptOnAnArchivedListing(): void
    {
        $results = $this->runScenarios([
            'all listings' => ['schema' => 'owned', 'action' => 'overview', 'query' => ['status' => 'all']],
            'moon, empty'  => ['schema' => 'owned', 'action' => 'overview', 'query' => ['listing_id' => '1', 'status' => 'all']],
            'closed'       => ['schema' => 'owned', 'action' => 'overview', 'query' => ['listing_id' => '3', 'status' => 'all']],
            'adopted out'  => ['schema' => 'enth', 'action' => 'overview', 'query' => ['listing_id' => '4', 'status' => 'all']],
        ]);

        foreach ($results as $case => $r) {
            self::assertNull($r['error'], $case);
            self::assertSame(200, $r['status'], $case);
        }
        self::assertStringContainsString('/admin/members/add" class="btn btn-primary btn-sm">', $results['all listings']['body']);
        self::assertSame(2, substr_count($results['moon, empty']['body'], '/admin/members/add?listing_id=1" class="btn btn-primary btn-sm">'),
            'The toolbar button and the "No members yet" one');
        self::assertStringContainsString('No members yet.', $results['moon, empty']['body']);
        foreach (['closed', 'adopted out'] as $case) {
            self::assertStringNotContainsString('/admin/members/add', $results[$case]['body'], $case);
        }
    }

    // ------------------------------------------------------------------

    /** The text between the first $from and the next $to after it. */
    private static function between(string $haystack, string $from, string $to): string
    {
        $start = strpos($haystack, $from);
        self::assertNotFalse($start, "Missing: {$from}");
        $end = strpos($haystack, $to, $start + strlen($from));
        return substr($haystack, $start, $end === false ? null : $end - $start);
    }

    /**
     * Runs the scenarios in one harness process. Each result gets 'mails', the
     * messages that scenario sent.
     *
     * @param array<string|int, array<string, mixed>> $scenarios
     * @return array<string|int, array<string, mixed>>
     */
    private function runScenarios(array $scenarios): array
    {
        if (!function_exists('proc_open')) {
            self::markTestSkipped('proc_open is not available');
        }

        $support      = __DIR__ . DIRECTORY_SEPARATOR . 'Support' . DIRECTORY_SEPARATOR;
        $tmp          = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'lstr_member_add_' . uniqid('', true);
        $scenarioFile = $tmp . '.json';
        $mailFile     = $tmp . '.mail.txt';
        $logFile      = $tmp . '.log.txt';
        file_put_contents($scenarioFile, json_encode(array_values($scenarios), JSON_THROW_ON_ERROR));

        // mail() runs this through the shell. On Windows, cmd.exe strips the
        // outer pair of quotes, so the whole command gets one extra pair.
        if (DIRECTORY_SEPARATOR === '\\') {
            $sendmail = '""' . PHP_BINARY . '" "' . $support . 'capture-mail.php" "' . $mailFile . '""';
        } else {
            $sendmail = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($support . 'capture-mail.php') . ' ' . escapeshellarg($mailFile);
        }

        $process = proc_open(
            [PHP_BINARY, '-d', 'sendmail_path=' . $sendmail, '-d', 'log_errors=1', '-d', 'error_log=' . $logFile,
                $support . 'member-add-harness.php', $scenarioFile, $mailFile, $logFile],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        self::assertIsResource($process, 'Could not start the harness process');
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        foreach ([$scenarioFile, $mailFile, $logFile] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }

        self::assertSame(0, $exitCode, "Harness failed:\n" . $stderr);
        $results = json_decode($stdout, true);
        self::assertIsArray($results, "Harness output was not JSON:\n" . $stdout . "\n" . $stderr);
        self::assertCount(count($scenarios), $results);

        $keyed = array_combine(array_keys($scenarios), $results);
        foreach ($keyed as &$r) {
            $r['mails'] = array_values(array_filter(
                array_map('trim', explode(self::MAIL_SEPARATOR, (string) $r['mail'])),
                static fn(string $mail): bool => $mail !== '',
            ));
        }
        unset($r);

        return $keyed;
    }
}
