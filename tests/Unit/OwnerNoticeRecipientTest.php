<?php

declare(strict_types=1);

namespace Luster\Tests\Unit;

use Luster\Repositories\EmailTemplateRepo;
use Luster\Tests\Unit\Support\RunsMemberAuthHarness;
use PHPUnit\Framework\TestCase;

/**
 * Who hears about what a fan just did on a public form, through the real controllers
 * with mail captured.
 *
 * A listing's notices go to the Email saved on the listing, then to the Contact Email
 * under Settings > Owner Information (owner_email). They never go to the address the
 * owner logs in with, which is a separate setting in lstr_admin_users. The collective's
 * own forms go to the Contact Email. A notice someone switched off stays off, and one
 * that can't be delivered or has nowhere to go shows up in the panel's Error Log.
 */
final class OwnerNoticeRecipientTest extends TestCase
{
    use RunsMemberAuthHarness;

    private const LISTING = 'moon-owner@x.test';
    private const CONTACT = 'contact-email@x.test';
    private const LOGIN   = 'star@x.test'; // The admin_user step's login address.
    private const FAN     = 'a@x.test';
    private const REFUSED = 'not an address'; // The mailer refuses this, the way a real send can fail.
    private const PASS    = 'a-password-1';
    private const NEW_PW  = 'chosen-by-fan-a-99';

    /**
     * What each form needs, the steps that use it, and the subject of its owner notice.
     *
     * @return array<string, array{0: list<array<string,mixed>>, 1: string}>
     */
    private static function forms(): array
    {
        $member = ['do' => 'insert_member', 'member' => ['listing' => 1, 'email' => self::FAN, 'name' => 'Fan A', 'password' => self::PASS]];

        return [
            'Join' => [[['do' => 'join', 'email' => 'new@x.test']], 'New member joined Moon FL'],
            'Contact' => [[['do' => 'contact']], '[Moon FL] Contact Form - A question'],
            'Update' => [[$member, ['do' => 'signed_in'], ['do' => 'submit', 'use_token' => true, 'post' => ['email' => self::FAN, 'new_name' => 'New Name']]], 'Member update for Moon FL'],
            'Code donation' => [[['do' => 'codes']], 'New code donation for Moon FL'],
            'Affiliate application' => [[['do' => 'affiliate']], 'New affiliate application for Moon FL'],
            'Adoption application' => [[['do' => 'adopt']], 'New adoption application for The Moon FL'],
            'Adoption, collective form' => [[['do' => 'adopt_collective']], 'New adoption application for The Moon FL'],
            'KIM sign-up' => [[['do' => 'kim_signup']], 'New KIM List sign-up for The Moon FL'],
            'KIM update' => [[
                ['do' => 'kim_entry', 'as' => 'mine', 'name' => 'Fan A', 'password' => self::PASS],
                ['do' => 'kim_sign_in', 'password' => self::PASS],
                ['do' => 'kim_save', 'entries' => ['mine'], 'post' => ['new_name' => 'New Name']],
            ], 'KIM List entry updated'],
            'Collective update' => [[
                $member,
                ['do' => 'collective_token'],
                ['do' => 'collective', 'post' => ['listing_ids' => ['1'], 'name' => 'New Name']],
            ], 'A member updated their details at Test Collective'],
        ];
    }

    /** @return array<string, array{0: list<array<string,mixed>>, 1: string}> */
    public static function formProvider(): array
    {
        return array_map(static fn(array $form): array => $form, self::forms());
    }

    /** @return array<int, array{to: string, subject: string, raw: string}> */
    private static function mails(array $r): array
    {
        $mails = [];
        foreach (explode('--LSTR-MAIL-END--', (string) $r['mail']) as $raw) {
            if (trim($raw) === '') {
                continue;
            }
            preg_match('/^To:\s*(.+)$/mi', $raw, $to);
            // A long subject carries on over the next line, which starts with a space.
            preg_match('/^Subject:\s*(.+(?:\R[ 	].+)*)$/mi', $raw, $subject);
            $mails[] = [
                'to'      => self::addressIn(trim($to[1] ?? '')),
                'subject' => trim((string) iconv_mime_decode(trim($subject[1] ?? ''), 0, 'UTF-8')),
                'raw'     => $raw,
            ];
        }

        return $mails;
    }

    private static function addressIn(string $header): string
    {
        return preg_match('/<([^>]+)>/', $header, $m) === 1 ? $m[1] : $header;
    }

    /** @return list<array{to: string, subject: string, raw: string}> The mails with this subject. */
    private static function withSubject(array $r, string $subject): array
    {
        return array_values(array_filter(self::mails($r), static fn(array $m): bool => $m['subject'] === $subject));
    }

    /** @param list<array<string,mixed>> $steps */
    private static function scenario(array $steps, array $more = []): array
    {
        return $more + [
            'layout'        => 'owned',
            'owner_notices' => true,
            'owner_email'   => self::CONTACT,
            'steps'         => [['do' => 'admin_user'], ...$steps],
        ];
    }

    private static function assertRanCleanly(array $r, string $why): void
    {
        self::assertNull($r['error'], $why);
        self::assertSame([], $r['error_log'], $why);
    }

    // ------------------------------------------------------------------
    // Where they go
    // ------------------------------------------------------------------

    /**
     * @dataProvider formProvider
     * @param list<array<string,mixed>> $steps
     */
    public function testANoticeGoesToTheListingsEmailWhenItHasOne(array $steps, string $subject): void
    {
        $r = $this->runOne(self::scenario($steps, ['listing_emails' => [1 => self::LISTING]]));

        self::assertRanCleanly($r, $subject);
        $notices = self::withSubject($r, $subject);
        self::assertCount(1, $notices, $subject . ': one notice');
        self::assertSame(self::LISTING, $notices[0]['to']);
    }

    /**
     * @dataProvider formProvider
     * @param list<array<string,mixed>> $steps
     */
    public function testANoticeFallsBackToTheContactEmailNeverTheLogin(array $steps, string $subject): void
    {
        $r = $this->runOne(self::scenario($steps));

        self::assertRanCleanly($r, $subject);
        $notices = self::withSubject($r, $subject);
        self::assertCount(1, $notices, $subject . ': one notice');
        self::assertSame(self::CONTACT, $notices[0]['to']);

        self::assertNotContains(self::LOGIN, array_column(self::mails($r), 'to'), 'The login address is a different setting');
    }

    /** @return array<string, array{0: list<array<string,mixed>>, 1: string}> */
    public static function enthusiastFormProvider(): array
    {
        // The collective adoption form only knows the unprefixed layout, whatever it sends.
        return array_diff_key(self::forms(), ['Adoption, collective form' => true]);
    }

    /**
     * @dataProvider enthusiastFormProvider
     * @param list<array<string,mixed>> $steps
     */
    public function testAnEnthusiastStyleListingWorksTheSame(array $steps, string $subject): void
    {
        $r = $this->runOne(self::scenario($steps, ['layout' => 'enth']));

        self::assertRanCleanly($r, $subject);
        self::assertSame([self::CONTACT], array_column(self::withSubject($r, $subject), 'to'));
    }

    /**
     * Nothing to send to is a problem the owner can see, and the fan's own part still works.
     *
     * @dataProvider formProvider
     * @param list<array<string,mixed>> $steps
     */
    public function testWithNoAddressAtAllTheLoginStillIsntUsedAndTheOwnerCanSeeWhy(array $steps, string $subject): void
    {
        $r = $this->runOne(self::scenario($steps, ['owner_email' => '']));

        self::assertNull($r['error'], $subject);
        self::assertSame([], self::withSubject($r, $subject));
        self::assertNotContains(self::LOGIN, array_column(self::mails($r), 'to'));
        self::assertCount(1, $r['error_log'], $subject);
        self::assertStringContainsString('Contact Email', (string) $r['error_log'][0]['message']);
    }

    public function testACustomContactRecipientComesFirst(): void
    {
        $r = $this->runOne(self::scenario([
            ['do' => 'contact_recipient', 'address' => 'custom@x.test'],
            ['do' => 'contact'],
        ], ['listing_emails' => [1 => self::LISTING]]));

        self::assertRanCleanly($r, 'contact');
        self::assertSame(['custom@x.test'], array_column(self::mails($r), 'to'));
    }

    public function testAContactFormWithNowhereToSendItTellsTheFanAndTheOwner(): void
    {
        $r = $this->runOne(self::scenario([['do' => 'contact']], ['owner_email' => '']));

        self::assertNull($r['error']);
        self::assertSame([], self::mails($r));
        self::assertContains('No contact email is configured. Please try again later.', self::flashes($r));
        self::assertCount(1, $r['error_log']);
        self::assertStringContainsString('Contact Email', (string) $r['error_log'][0]['message']);
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function collectiveFormProvider(): array
    {
        return [
            'code donation' => ['codes', 'New code donation for Test Collective'],
            'contact'       => ['contact_collective', '[Test Collective] Contact Form - A question'],
            'affiliate'     => ['affiliate_collective', 'New affiliate application for Test Collective'],
        ];
    }

    /** @dataProvider collectiveFormProvider */
    public function testTheCollectivesOwnFormsGoToTheContactEmail(string $step, string $subject): void
    {
        // Listing 0 is the collective, which has no address of its own.
        $r = $this->runOne(self::scenario([['do' => $step, 'listing' => 0]], ['listing_emails' => [1 => self::LISTING]]));

        self::assertRanCleanly($r, $subject);
        self::assertSame([self::CONTACT], array_column(self::withSubject($r, $subject), 'to'));
    }

    public function testACollectiveDonationsNoticeSaysItsWaiting(): void
    {
        $r = $this->runOne(self::scenario([['do' => 'codes', 'listing' => 0]]));

        self::assertSame([['fanlisting_id' => null, 'status' => 'pending']], $r['codes']);
        $mail = self::withSubject($r, 'New code donation for Test Collective')[0]['raw'];
        self::assertStringContainsString('Code Donor', $mail);
        self::assertStringContainsString('waiting for your approval', $mail);
    }

    public function testADonationsNoticeNamesTheDonorAndWhatsNext(): void
    {
        $r = $this->runOne(self::scenario([['do' => 'codes']]));

        self::assertSame([['fanlisting_id' => 1, 'status' => 'pending']], $r['codes']);
        $mail = self::withSubject($r, 'New code donation for Moon FL')[0]['raw'];
        self::assertStringContainsString('Code Donor', $mail);
        self::assertStringContainsString(self::FAN, $mail);
        self::assertStringContainsString('https://donor.test/', $mail);
    }

    // ------------------------------------------------------------------
    // Switched off stays off
    // ------------------------------------------------------------------

    /** @return array<string, array{0: list<array<string,mixed>>, 1: string, 2: string}> */
    public static function switchableProvider(): array
    {
        $forms = self::forms();

        return [
            'join'       => [$forms['Join'][0], 'notify_join', $forms['Join'][1]],
            'update'     => [$forms['Update'][0], 'notify_update', $forms['Update'][1]],
            'code'       => [$forms['Code donation'][0], 'notify_code', $forms['Code donation'][1]],
        ];
    }

    /**
     * @dataProvider switchableProvider
     * @param list<array<string,mixed>> $steps
     */
    public function testSwitchingANoticeOffForAllListingsIsHonoured(array $steps, string $type, string $subject): void
    {
        $r = $this->runOne(self::scenario([['do' => 'template', 'scope' => 0, 'type' => $type, 'enabled' => 0], ...$steps]));

        self::assertRanCleanly($r, $subject);
        self::assertNotContains(self::CONTACT, array_column(self::mails($r), 'to'), 'No other email takes its place');
    }

    /**
     * @dataProvider switchableProvider
     * @param list<array<string,mixed>> $steps
     */
    public function testSwitchingANoticeOffForOneListingIsHonoured(array $steps, string $type, string $subject): void
    {
        $r = $this->runOne(self::scenario([['do' => 'template', 'scope' => 1, 'type' => $type, 'enabled' => 0], ...$steps]));

        self::assertRanCleanly($r, $subject);
        self::assertNotContains(self::CONTACT, array_column(self::mails($r), 'to'), 'No other email takes its place');
    }

    /**
     * Off on purpose isn't a problem, even with nowhere it could have gone.
     *
     * @dataProvider switchableProvider
     * @param list<array<string,mixed>> $steps
     */
    public function testASwitchedOffNoticeWithNoAddressLogsNothing(array $steps, string $type, string $subject): void
    {
        $r = $this->runOne(self::scenario([['do' => 'template', 'scope' => 0, 'type' => $type, 'enabled' => 0], ...$steps], ['owner_email' => '']));

        self::assertRanCleanly($r, $subject);
    }

    /** @return array<string, array{0: list<array<string,mixed>>, 1: string, 2: string}> */
    public static function collectiveSwitchProvider(): array
    {
        $forms = self::forms();

        return [
            'KIM update'        => [$forms['KIM update'][0], 'notify_kim_update', $forms['KIM update'][1]],
            'Collective update' => [$forms['Collective update'][0], 'notify_coll_update', $forms['Collective update'][1]],
        ];
    }

    /**
     * @dataProvider collectiveSwitchProvider
     * @param list<array<string,mixed>> $steps
     */
    public function testTheCollectiveNoticesSwitchOffUnderTheCollectiveScope(array $steps, string $type, string $subject): void
    {
        $r = $this->runOne(self::scenario([['do' => 'template', 'scope' => -1, 'type' => $type, 'enabled' => 0], ...$steps]));

        self::assertRanCleanly($r, $subject);
        self::assertNotContains(self::CONTACT, array_column(self::mails($r), 'to'), 'No other email takes its place');
    }

    public function testACollectiveDonationNoticeSwitchesOffOnItsOwn(): void
    {
        $results = $this->runScenarios([
            'collective off' => self::scenario([['do' => 'template', 'scope' => -1, 'type' => 'notify_code', 'enabled' => 0], ['do' => 'codes', 'listing' => 0], ['do' => 'codes']]),
            'global off'     => self::scenario([['do' => 'template', 'scope' => 0, 'type' => 'notify_code', 'enabled' => 0], ['do' => 'codes', 'listing' => 0], ['do' => 'codes']]),
        ]);

        self::assertSame(['New code donation for Moon FL'], array_column(self::mails($results['collective off']), 'subject'));
        self::assertSame(['New code donation for Test Collective'], array_column(self::mails($results['global off']), 'subject'));
    }

    public function testAnOwnersOwnWordingIsUsed(): void
    {
        $r = $this->runOne(self::scenario([
            ['do' => 'template', 'scope' => 0, 'type' => 'notify_update', 'subject' => '{fan_name} changed on {listing_title}', 'body' => 'See: {changes}'],
            ['do' => 'insert_member', 'member' => ['email' => self::FAN, 'name' => 'Fan A', 'password' => self::PASS]],
            ['do' => 'signed_in'],
            ['do' => 'submit', 'use_token' => true, 'post' => ['email' => self::FAN, 'new_name' => 'New Name']],
        ]));

        self::assertRanCleanly($r, 'custom wording');
        $notice = self::withSubject($r, 'Fan A changed on Moon FL');
        self::assertCount(1, $notice);
        self::assertStringContainsString('Name: Fan A -> New Name', $notice[0]['raw']);
    }

    // ------------------------------------------------------------------
    // What fails shows up where the owner can see it
    // ------------------------------------------------------------------

    /**
     * @dataProvider formProvider
     * @param list<array<string,mixed>> $steps
     */
    public function testADeliveryFailureGoesInThePanelsErrorLog(array $steps, string $subject): void
    {
        $r = $this->runOne(self::scenario($steps, ['owner_email' => self::REFUSED]));

        self::assertNull($r['error'], $subject . ': the form still works');
        self::assertSame([], self::withSubject($r, $subject));
        $notices = array_values(array_filter(
            array_column($r['error_log'], 'message'),
            static fn(string $message): bool => str_starts_with($message, "Couldn't send"),
        ));
        self::assertNotSame([], $notices, $subject);
        foreach ($notices as $message) {
            self::assertStringNotContainsString(self::FAN, $message, "A fan's address stays out of the log");
            self::assertStringNotContainsString('new@x.test', $message);
        }
    }

    public function testAFormThatSavedStaysSavedWhenTheNoticeFails(): void
    {
        $results = $this->runScenarios([
            'join' => self::scenario([['do' => 'join', 'email' => 'new@x.test']], ['owner_email' => self::REFUSED]),
            'code' => self::scenario([['do' => 'codes']], ['owner_email' => self::REFUSED]),
        ]);

        self::assertCount(1, $results['join']['members'][1]);
        self::assertCount(1, $results['code']['codes']);
    }

    public function testAFailedAddressStopsTheRestAndSaysSo(): void
    {
        $r = $this->runOne(self::collective(['name' => 'New Name'], ['listing_emails' => [1 => self::REFUSED, 2 => 'sun@x.test']]));

        self::assertNull($r['error']);
        self::assertSame([], self::withSubject($r, self::COLLECTIVE_SUBJECT), 'A mail server that is down would make the fan wait once per address');
        $messages = array_column($r['error_log'], 'message');
        self::assertCount(2, $messages);
        self::assertStringStartsWith("Couldn't send the collective update notice", $messages[0]);
        self::assertSame("Didn't try the collective update notice for 1 more address(es), since the one before it failed.", $messages[1]);
    }

    public function testASaveAlreadyDoneStaysDoneWhenTheNoticeFails(): void
    {
        $r = $this->runOne(self::collective(['name' => 'New Name'], ['owner_email' => self::REFUSED]));

        self::assertNull($r['error']);
        self::assertSame('New Name', $r['members'][1][0]['name']);
        self::assertSame('New Name', $r['members'][2][0]['name']);
    }

    public function testAContactMessageThatCantBeDeliveredStillTellsTheFan(): void
    {
        $r = $this->runOne(self::scenario([['do' => 'contact']], ['owner_email' => self::REFUSED]));

        self::assertContains('Your message could not be sent. Please try again later.', self::flashes($r));
        self::assertCount(1, $r['error_log']);
    }

    // ------------------------------------------------------------------
    // Update: what it says, and when it says nothing
    // ------------------------------------------------------------------

    /** @param array<string, mixed> $post */
    private static function update(array $post, array $more = []): array
    {
        return self::scenario([
            ['do' => 'insert_member', 'member' => ['email' => self::FAN, 'name' => 'Fan A', 'password' => self::PASS, 'url' => 'https://old.test/', 'country' => 'Canada']],
            ['do' => 'signed_in'],
            ['do' => 'submit', 'use_token' => true, 'post' => $post + ['email' => self::FAN]],
        ], $more);
    }

    private const UPDATE_SUBJECT = 'Member update for Moon FL';

    public function testAnUpdateSaysWhatChanged(): void
    {
        $r = $this->runOne(self::update(['new_name' => 'New Name', 'url' => 'https://new.test/', 'country' => 'France']));

        self::assertRanCleanly($r, 'update');
        $mail = self::withSubject($r, self::UPDATE_SUBJECT)[0]['raw'];
        self::assertStringContainsString('Name: Fan A -> New Name', $mail);
        self::assertStringContainsString('Website: https://old.test/ -> https://new.test/', $mail);
        self::assertStringContainsString('Country: Canada -> France', $mail);
        self::assertStringNotContainsString('waiting for your approval', $mail);
        self::assertStringNotContainsString('showurl', $mail);
    }

    public function testAnUpdateUsesTheNameOfAnAdditionalField(): void
    {
        $r = $this->runOne(self::scenario([
            ['do' => 'sql', 'sql' => 'ALTER TABLE fl_moon ADD COLUMN pet TEXT'],
            ['do' => 'sql', 'sql' => "UPDATE owned SET additional = 'pet' WHERE listingid = 1"],
            ['do' => 'insert_member', 'member' => ['email' => self::FAN, 'name' => 'Fan A', 'password' => self::PASS]],
            ['do' => 'signed_in'],
            ['do' => 'submit', 'use_token' => true, 'post' => ['email' => self::FAN, 'field_pet' => 'Cat']],
        ]));

        self::assertRanCleanly($r, 'field');
        self::assertStringContainsString('Pet: (blank) -> Cat', self::withSubject($r, self::UPDATE_SUBJECT)[0]['raw']);
    }

    public function testAQueuedUpdateSaysItsWaitingForApproval(): void
    {
        $r = $this->runOne(self::update(['new_name' => 'New Name'], ['queue_updates' => true]));

        self::assertRanCleanly($r, 'queued');
        self::assertCount(1, $r['queue']);
        $mail = self::withSubject($r, self::UPDATE_SUBJECT)[0]['raw'];
        self::assertStringContainsString('Name: Fan A -> New Name (waiting for your approval)', $mail);
    }

    public function testAPasswordChangeSaysSoAndNeverWhatItIs(): void
    {
        $r = $this->runOne(self::update(['new_password' => self::NEW_PW, 'new_password_confirm' => self::NEW_PW]));

        self::assertRanCleanly($r, 'password');
        $mail = self::withSubject($r, self::UPDATE_SUBJECT)[0]['raw'];
        self::assertStringContainsString('Password: changed', $mail);
        self::assertStringNotContainsString(self::NEW_PW, $mail);
        self::assertStringNotContainsString(self::PASS, $mail);
        self::assertStringNotContainsString('$argon2', $mail);
    }

    public function testAQueuedUpdateWithAPasswordSaysWhichPartIsDone(): void
    {
        $r = $this->runOne(self::update(['new_name' => 'New Name', 'new_password' => self::NEW_PW, 'new_password_confirm' => self::NEW_PW], ['queue_updates' => true]));

        $mail = self::withSubject($r, self::UPDATE_SUBJECT)[0]['raw'];
        self::assertStringContainsString('Name: Fan A -> New Name (waiting for your approval)', $mail);
        self::assertSame(1, preg_match('/^\s*Password: changed\s*$/m', $mail), 'A password never waits');
    }

    public function testAnUpdateThatChangesNothingSendsNothing(): void
    {
        $r = $this->runOne(self::update(['new_name' => 'Fan A']));

        self::assertRanCleanly($r, 'no change');
        self::assertSame([], self::withSubject($r, self::UPDATE_SUBJECT));
    }

    public function testAnUpdateThatsRefusedSendsNothing(): void
    {
        $r = $this->runOne(self::update(['new_email' => 'not an email']));

        self::assertNotSame([], self::flashes($r));
        self::assertSame([], self::withSubject($r, self::UPDATE_SUBJECT));
    }

    public function testAnEmailChangeShowsBothAddressesToTheOwner(): void
    {
        $r = $this->runOne(self::update(['new_email' => 'b@x.test']));

        self::assertRanCleanly($r, 'email');
        self::assertStringContainsString('Email: ' . self::FAN . ' -> b@x.test', self::withSubject($r, self::UPDATE_SUBJECT)[0]['raw']);
    }

    public function testAMemberRemovingThemselvesIsAlsoANotice(): void
    {
        $r = $this->runOne(self::scenario([
            ['do' => 'insert_member', 'member' => ['email' => self::FAN, 'name' => 'Fan A', 'password' => self::PASS]],
            ['do' => 'signed_in'],
            ['do' => 'submit', 'use_token' => true, 'post' => ['email' => self::FAN, 'delete_membership' => '1']],
        ]));

        self::assertRanCleanly($r, 'removal');
        self::assertSame([], $r['members'][1]);
        $notice = self::withSubject($r, self::UPDATE_SUBJECT);
        self::assertCount(1, $notice);
        self::assertSame(self::CONTACT, $notice[0]['to']);
        self::assertStringContainsString('Removed their membership', $notice[0]['raw']);
    }

    public function testTheNoticeWaitsUntilTheLocksAreReleased(): void
    {
        $r = $this->runOne(self::update(['new_name' => 'New Name']));

        self::assertNotSame([], $r['mail_at_release']);
        foreach ($r['mail_at_release'] as $size) {
            self::assertSame(0, $size, 'No mail before the last lock goes');
        }
        self::assertCount(1, self::withSubject($r, self::UPDATE_SUBJECT));
    }

    // ------------------------------------------------------------------
    // Collective Update
    // ------------------------------------------------------------------

    private const COLLECTIVE_SUBJECT = 'A member updated their details at Test Collective';

    private static function collective(array $post, array $more = []): array
    {
        return self::scenario([
            ['do' => 'insert_member', 'member' => ['listing' => 1, 'email' => self::FAN, 'name' => 'Moon Name', 'password' => self::PASS]],
            ['do' => 'insert_member', 'member' => ['listing' => 2, 'email' => self::FAN, 'name' => 'Sun Name', 'password' => self::PASS]],
            ['do' => 'collective_token'],
            ['do' => 'collective', 'post' => $post + ['listing_ids' => ['1', '2']]],
        ], $more);
    }

    public function testTwoListingsThatFallBackShareOneNotice(): void
    {
        $r = $this->runOne(self::collective(['name' => 'New Name']));

        self::assertRanCleanly($r, 'shared');
        $notices = self::withSubject($r, self::COLLECTIVE_SUBJECT);
        self::assertCount(1, $notices, 'One email to one address');
        self::assertSame(self::CONTACT, $notices[0]['to']);
        self::assertStringContainsString('Moon FL', $notices[0]['raw']);
        self::assertStringContainsString('Name: Moon Name -> New Name', $notices[0]['raw']);
        self::assertStringContainsString('Sun FL', $notices[0]['raw']);
        self::assertStringContainsString('Name: Sun Name -> New Name', $notices[0]['raw']);
    }

    public function testEachListingsAddressHearsOnlyAboutItsOwnListing(): void
    {
        $r = $this->runOne(self::collective(['name' => 'New Name'], ['listing_emails' => [1 => self::LISTING]]));

        self::assertRanCleanly($r, 'split');
        $notices = self::withSubject($r, self::COLLECTIVE_SUBJECT);
        self::assertCount(2, $notices);
        $byAddress = array_column($notices, 'raw', 'to');
        self::assertStringContainsString('Moon FL', $byAddress[self::LISTING]);
        self::assertStringNotContainsString('Sun FL', $byAddress[self::LISTING]);
        self::assertStringContainsString('Sun FL', $byAddress[self::CONTACT]);
        self::assertStringNotContainsString('Moon FL', $byAddress[self::CONTACT]);
    }

    public function testACollectiveQueuedUpdateSaysItsWaiting(): void
    {
        $r = $this->runOne(self::collective(['name' => 'New Name'], ['queue_updates' => true]));

        self::assertRanCleanly($r, 'queued');
        self::assertStringContainsString('Name: Moon Name -> New Name (waiting for your approval)', self::withSubject($r, self::COLLECTIVE_SUBJECT)[0]['raw']);
    }

    public function testACollectivePasswordChangeNeverShowsThePassword(): void
    {
        $r = $this->runOne(self::collective(['new_password' => self::NEW_PW, 'new_password_confirm' => self::NEW_PW]));

        self::assertRanCleanly($r, 'password');
        $mail = self::withSubject($r, self::COLLECTIVE_SUBJECT)[0]['raw'];
        self::assertStringContainsString('Password: changed', $mail);
        self::assertStringNotContainsString(self::NEW_PW, $mail);
    }

    public function testAListingWhereNothingChangedIsLeftOut(): void
    {
        $r = $this->runOne(self::collective(['name' => 'Moon Name']));

        // Moon already has this name, so only Sun changed.
        $mail = self::withSubject($r, self::COLLECTIVE_SUBJECT)[0]['raw'];
        self::assertStringNotContainsString('Moon FL', $mail);
        self::assertStringContainsString('Sun FL', $mail);
    }

    public function testACollectiveRemovalIsANoticeToo(): void
    {
        $r = $this->runOne(self::collective(['delete_membership' => '1']));

        self::assertNull($r['error']);
        self::assertSame([], $r['members'][1]);
        $mail = self::withSubject($r, self::COLLECTIVE_SUBJECT)[0]['raw'];
        self::assertStringContainsString('Moon FL', $mail);
        self::assertStringContainsString('Removed their membership', $mail);
    }

    public function testASaveThatFailedOnAListingDoesntClaimItChanged(): void
    {
        $r = $this->runOne(self::scenario([
            ['do' => 'insert_member', 'member' => ['listing' => 1, 'email' => self::FAN, 'name' => 'Moon Name', 'password' => self::PASS]],
            ['do' => 'insert_member', 'member' => ['listing' => 2, 'email' => self::FAN, 'name' => 'Sun Name', 'password' => self::PASS]],
            ['do' => 'collective_token'],
            ['do' => 'fail_on', 'regex' => '/UPDATE `?fl_sun`?/'],
            ['do' => 'collective', 'post' => ['listing_ids' => ['1', '2'], 'name' => 'New Name', 'new_email' => 'moved@x.test']],
        ]));

        $notices = self::withSubject($r, self::COLLECTIVE_SUBJECT);
        self::assertCount(1, $notices, 'The listing that did change is still told');
        self::assertStringContainsString('Moon FL', $notices[0]['raw']);
        self::assertStringNotContainsString('Sun FL', $notices[0]['raw']);
    }

    // ------------------------------------------------------------------
    // KIM Update
    // ------------------------------------------------------------------

    private const KIM_SUBJECT = 'KIM List entry updated';

    private static function kim(array $save, array $more = []): array
    {
        return self::scenario([
            ['do' => 'kim_entry', 'as' => 'moon', 'name' => 'Moon Name', 'password' => self::PASS],
            ['do' => 'kim_entry', 'as' => 'sun', 'listing' => 2, 'name' => 'Sun Name', 'password' => self::PASS],
            ['do' => 'kim_sign_in', 'password' => self::PASS],
            ['do' => 'kim_save'] + $save,
        ], $more);
    }

    public function testKimEntriesThatFallBackShareOneNotice(): void
    {
        $r = $this->runOne(self::kim(['entries' => ['moon', 'sun'], 'post' => ['new_name' => 'New Name']]));

        self::assertRanCleanly($r, 'kim shared');
        $notices = self::withSubject($r, self::KIM_SUBJECT);
        self::assertCount(1, $notices);
        self::assertSame(self::CONTACT, $notices[0]['to']);
        self::assertStringContainsString('The Moon FL', $notices[0]['raw']);
        self::assertStringContainsString('Name: Moon Name -> New Name', $notices[0]['raw']);
        self::assertStringContainsString('The Sun FL', $notices[0]['raw']);
    }

    public function testEachKimListingsAddressHearsOnlyAboutItsOwnEntry(): void
    {
        $r = $this->runOne(self::kim(['entries' => ['moon', 'sun'], 'post' => ['new_name' => 'New Name']], ['listing_emails' => [1 => self::LISTING]]));

        self::assertRanCleanly($r, 'kim split');
        $byAddress = array_column(self::withSubject($r, self::KIM_SUBJECT), 'raw', 'to');
        self::assertCount(2, $byAddress);
        self::assertStringContainsString('The Moon FL', $byAddress[self::LISTING]);
        self::assertStringNotContainsString('The Sun FL', $byAddress[self::LISTING]);
        self::assertStringContainsString('The Sun FL', $byAddress[self::CONTACT]);
    }

    public function testAKimPasswordChangeNeverShowsThePassword(): void
    {
        $r = $this->runOne(self::kim(['entries' => ['moon'], 'post' => ['new_password' => self::NEW_PW, 'new_password_confirm' => self::NEW_PW]]));

        self::assertRanCleanly($r, 'kim password');
        $mail = self::withSubject($r, self::KIM_SUBJECT)[0]['raw'];
        self::assertStringContainsString('Password: changed', $mail);
        self::assertStringNotContainsString(self::NEW_PW, $mail);
    }

    public function testAKimEntryRemovedIsANoticeToo(): void
    {
        $r = $this->runOne(self::kim(['removes' => ['moon']]));

        self::assertRanCleanly($r, 'kim removal');
        $mail = self::withSubject($r, self::KIM_SUBJECT)[0]['raw'];
        self::assertStringContainsString('The Moon FL', $mail);
        self::assertStringContainsString('Removed their entry', $mail);
        self::assertStringNotContainsString('The Sun FL', $mail);
    }

    public function testAKimSaveWithTheSameDetailsSendsNothing(): void
    {
        $r = $this->runOne(self::kim(['entries' => ['moon'], 'post' => ['new_name' => 'Moon Name']]));

        self::assertSame([], self::withSubject($r, self::KIM_SUBJECT));
    }

    public function testAKimSaveThatWasRefusedSendsNothing(): void
    {
        $r = $this->runOne(self::kim(['entries' => ['moon'], 'post' => ['new_email' => 'not an email']]));

        self::assertSame([], self::withSubject($r, self::KIM_SUBJECT));
    }

    // ------------------------------------------------------------------
    // A fan can use a different name on each listing
    // ------------------------------------------------------------------

    private const SUN_OWNER = 'sun-owner@x.test';
    private const TWO_OWNERS = ['listing_emails' => [1 => self::LISTING, 2 => self::SUN_OWNER]];

    /** @return array<string, array{0: list<string>}> */
    public static function selectionOrderProvider(): array
    {
        return ['Moon first' => [['1', '2']], 'Sun first' => [['2', '1']]];
    }

    /** @param array<string, string> $byAddress */
    private static function assertEachOwnerSeesOnlyTheirOwn(array $byAddress, string $moonOnly, string $sunOnly, string $why): void
    {
        self::assertCount(2, $byAddress, $why);
        self::assertStringContainsString($moonOnly, $byAddress[self::LISTING], $why);
        self::assertStringNotContainsString($sunOnly, $byAddress[self::LISTING], $why);
        self::assertStringContainsString($sunOnly, $byAddress[self::SUN_OWNER], $why);
        self::assertStringNotContainsString($moonOnly, $byAddress[self::SUN_OWNER], $why);
    }

    /**
     * @dataProvider selectionOrderProvider
     * @param list<string> $order
     */
    public function testACollectiveNoticeOnlyUsesTheNameOnThatOwnersListing(array $order): void
    {
        $password = ['new_password' => self::NEW_PW, 'new_password_confirm' => self::NEW_PW];
        $results  = $this->runScenarios([
            'password only' => self::collective(['listing_ids' => $order] + $password, self::TWO_OWNERS),
            'new name'      => self::collective(['listing_ids' => $order, 'name' => 'New Name'], self::TWO_OWNERS),
            'removal'       => self::collective(['listing_ids' => $order, 'delete_membership' => '1'], self::TWO_OWNERS),
        ]);

        foreach ($results as $why => $r) {
            self::assertRanCleanly($r, $why);
            self::assertEachOwnerSeesOnlyTheirOwn(
                array_column(self::withSubject($r, self::COLLECTIVE_SUBJECT), 'raw', 'to'),
                'Moon Name',
                'Sun Name',
                $why,
            );
        }
    }

    public function testAnAddressWithBothListingsSeesBothNames(): void
    {
        $r = $this->runOne(self::collective(['new_password' => self::NEW_PW, 'new_password_confirm' => self::NEW_PW]));

        self::assertRanCleanly($r, 'both');
        self::assertStringContainsString('Name: Moon Name, Sun Name', self::withSubject($r, self::COLLECTIVE_SUBJECT)[0]['raw']);
    }

    public function testTheSameNameOnBothListingsIsWrittenOnce(): void
    {
        $r = $this->runOne(self::scenario([
            ['do' => 'insert_member', 'member' => ['listing' => 1, 'email' => self::FAN, 'name' => 'One Name', 'password' => self::PASS]],
            ['do' => 'insert_member', 'member' => ['listing' => 2, 'email' => self::FAN, 'name' => 'One Name', 'password' => self::PASS]],
            ['do' => 'collective_token'],
            ['do' => 'collective', 'post' => ['listing_ids' => ['1', '2'], 'new_password' => self::NEW_PW, 'new_password_confirm' => self::NEW_PW]],
        ]));

        self::assertRanCleanly($r, 'same name');
        self::assertStringNotContainsString('One Name, One Name', self::withSubject($r, self::COLLECTIVE_SUBJECT)[0]['raw']);
    }

    public function testABlankDetailOnOneEntryLeavesNoStrayComma(): void
    {
        $r = $this->runOne(self::scenario([
            ['do' => 'template', 'scope' => -1, 'type' => 'notify_kim_update', 'subject' => 'KIM notice', 'body' => "{kim_name} | {kim_url}\n{changes}"],
            ['do' => 'kim_entry', 'as' => 'moon', 'name' => 'Moon Name', 'password' => self::PASS],
            ['do' => 'kim_entry', 'as' => 'sun', 'listing' => 2, 'name' => 'Sun Name', 'url' => 'https://sun-kim.test/', 'password' => self::PASS],
            ['do' => 'kim_sign_in', 'password' => self::PASS],
            ['do' => 'kim_save', 'entries' => ['moon', 'sun'], 'post' => ['new_password' => self::NEW_PW, 'new_password_confirm' => self::NEW_PW]],
        ]));

        self::assertRanCleanly($r, 'blank detail');
        self::assertStringContainsString('Moon Name, Sun Name | https://sun-kim.test/', self::withSubject($r, 'KIM notice')[0]['raw']);
    }

    /** What a fan typed is only text. Tokens in it stay as they are. */
    public function testAFansOwnTextIsntFilledInAsTokens(): void
    {
        $results = $this->runScenarios([
            'collective' => self::collective(['listing_ids' => ['1'], 'name' => '{site_name}']),
            'kim'        => self::kim(['entries' => ['moon'], 'post' => ['new_name' => '{site_name}']]),
            'update'     => self::update(['new_name' => '{site_name}']),
        ]);

        self::assertStringContainsString('-> {site_name}', self::withSubject($results['collective'], self::COLLECTIVE_SUBJECT)[0]['raw']);
        self::assertStringContainsString('-> {site_name}', self::withSubject($results['kim'], self::KIM_SUBJECT)[0]['raw']);
        self::assertStringContainsString('-> {site_name}', self::withSubject($results['update'], self::UPDATE_SUBJECT)[0]['raw']);
    }

    /** @return array<string, array{0: array<string, mixed>}> */
    public static function kimSaveProvider(): array
    {
        $password = ['new_password' => self::NEW_PW, 'new_password_confirm' => self::NEW_PW];

        return [
            'password only, Moon first' => [['entries' => ['moon', 'sun'], 'post' => $password]],
            'password only, Sun first'  => [['entries' => ['sun', 'moon'], 'post' => $password]],
            'new name'                  => [['entries' => ['sun', 'moon'], 'post' => ['new_name' => 'New Name']]],
            'new website'               => [['entries' => ['moon', 'sun'], 'post' => ['new_url' => 'https://new-kim.test/']]],
            'removal, Moon first'       => [['removes' => ['moon', 'sun']]],
            'removal, Sun first'        => [['removes' => ['sun', 'moon']]],
        ];
    }

    /**
     * @dataProvider kimSaveProvider
     * @param array<string, mixed> $save
     */
    public function testAKimNoticeOnlyUsesTheDetailsOnThatOwnersEntry(array $save): void
    {
        $r = $this->runOne(self::scenario([
            ['do' => 'template', 'scope' => -1, 'type' => 'notify_kim_update', 'subject' => 'KIM notice', 'body' => "{kim_name} | {kim_url} | {kim_email}\n{changes}"],
            ['do' => 'kim_entry', 'as' => 'moon', 'name' => 'Moon Name', 'url' => 'https://moon-kim.test/', 'password' => self::PASS],
            ['do' => 'kim_entry', 'as' => 'sun', 'listing' => 2, 'name' => 'Sun Name', 'url' => 'https://sun-kim.test/', 'password' => self::PASS],
            ['do' => 'kim_sign_in', 'password' => self::PASS],
            ['do' => 'kim_save'] + $save,
        ], self::TWO_OWNERS));

        self::assertRanCleanly($r, 'kim identity');
        $byAddress = array_column(self::withSubject($r, 'KIM notice'), 'raw', 'to');
        self::assertEachOwnerSeesOnlyTheirOwn($byAddress, 'Moon Name', 'Sun Name', 'name');
        self::assertEachOwnerSeesOnlyTheirOwn($byAddress, 'https://moon-kim.test/', 'https://sun-kim.test/', 'website');
        self::assertStringContainsString(self::FAN, $byAddress[self::LISTING]);
    }

    // ------------------------------------------------------------------
    // The {listing_email} token and the template wiring
    // ------------------------------------------------------------------

    public function testAnEmailsListingAddressFallsBackToTheContactEmailToo(): void
    {
        $tokens = EmailTemplateRepo::buildTokens(listingName: 'Moon', listingUrl: '', siteName: '', ownerEmail: self::CONTACT, listingEmail: '');
        self::assertSame(self::CONTACT, $tokens['{listing_email}']);

        $tokens = EmailTemplateRepo::buildTokens(listingName: 'Moon', listingUrl: '', siteName: '', ownerEmail: self::CONTACT, listingEmail: self::LISTING);
        self::assertSame(self::LISTING, $tokens['{listing_email}']);
    }

    public function testABulkEmailsListingAddressFallsBackToo(): void
    {
        $steps = [
            ['do' => 'insert_member', 'member' => ['email' => self::FAN, 'name' => 'Fan A', 'password' => self::PASS]],
            ['do' => 'bulk_recipients'],
        ];
        $results = $this->runScenarios([
            'blank'  => self::scenario($steps),
            'filled' => self::scenario($steps, ['listing_emails' => [1 => self::LISTING]]),
        ]);

        self::assertNull($results['blank']['error']);
        self::assertSame([[self::CONTACT]], $results['blank']['tallies']);
        self::assertSame([[self::LISTING]], $results['filled']['tallies']);
    }

    public function testEveryNewTypeFitsTheColumn(): void
    {
        foreach ([
            EmailTemplateRepo::TYPE_NOTIFY_UPDATE,
            EmailTemplateRepo::TYPE_NOTIFY_CODE,
            EmailTemplateRepo::TYPE_NOTIFY_KIM_UPDATE,
            EmailTemplateRepo::TYPE_NOTIFY_COLL_UPDATE,
        ] as $type) {
            self::assertLessThanOrEqual(20, strlen($type), "{$type}: lstr_email_templates.type is VARCHAR(20)");
            self::assertContains($type, EmailTemplateRepo::TYPES);
            self::assertNotSame('', EmailTemplateRepo::defaultSubject($type));
            self::assertNotSame('', EmailTemplateRepo::defaultBody($type));
        }
    }

    public function testEachNewTypeShowsInTheRightScopes(): void
    {
        self::assertContains(EmailTemplateRepo::TYPE_NOTIFY_UPDATE, EmailTemplateRepo::LISTING_TYPES);
        self::assertNotContains(EmailTemplateRepo::TYPE_NOTIFY_UPDATE, EmailTemplateRepo::COLLECTIVE_TYPES);

        self::assertContains(EmailTemplateRepo::TYPE_NOTIFY_CODE, EmailTemplateRepo::LISTING_TYPES);
        self::assertContains(EmailTemplateRepo::TYPE_NOTIFY_CODE, EmailTemplateRepo::COLLECTIVE_TYPES);

        foreach ([EmailTemplateRepo::TYPE_NOTIFY_KIM_UPDATE, EmailTemplateRepo::TYPE_NOTIFY_COLL_UPDATE] as $type) {
            self::assertNotContains($type, EmailTemplateRepo::LISTING_TYPES);
            self::assertContains($type, EmailTemplateRepo::COLLECTIVE_TYPES);
            self::assertContains($type, EmailTemplateRepo::COLLECTIVE_ONLY_TYPES);
        }
    }

    public function testNoPublicFormEverReadsTheLoginAddress(): void
    {
        $root  = dirname(__DIR__, 2);
        $files = array_merge(
            glob($root . '/src/Controllers/Public/*.php') ?: [],
            glob($root . '/src/Addons/*/Controllers/*Public*.php') ?: [],
            glob($root . '/src/Addons/Kim/Controllers/Kim{Signup,Update}Controller.php', GLOB_BRACE) ?: [],
            [$root . '/src/Support/OwnerNotice.php'],
        );
        self::assertGreaterThan(8, count($files));

        foreach ($files as $file) {
            $code = (string) file_get_contents($file);
            self::assertStringNotContainsString('lstr_admin_users', $code, $file);
            self::assertStringNotContainsString('AdminUserRepo', $code, $file);
        }
    }
}
