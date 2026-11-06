<?php

declare(strict_types=1);

namespace Luster\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Runs the real JoinController::submit() on both schemas, with a chosen and a
 * generated password, and checks what a new join does now that every join
 * waits for approval:
 *  - the member is saved as pending (pending=1 / approved=0)
 *  - exactly one email goes out: the join email, still showing the password
 *  - no "reset your password" follow-up email
 *  - the listing's Last Updated date doesn't move
 *  - the fan sees the "awaiting approval" message
 *
 * Each case runs Support/join-controller-harness.php in a child PHP process
 * started with -d sendmail_path pointing at Support/capture-mail.php, so mail()
 * writes to a temp file and nothing is ever really sent. (sendmail_path can't
 * be changed once PHP is running, hence the child process.)
 */
final class JoinControllerSubmitTest extends TestCase
{
    private const MAIL_SEPARATOR = '--LSTR-MAIL-END--';

    /** @return array<string, array{0: string, 1: string}> */
    public static function joinCaseProvider(): array
    {
        return [
            'unprefixed schema, chosen password'    => ['owned', 'chosen'],
            'unprefixed schema, generated password' => ['owned', 'generated'],
            'standard schema, chosen password'      => ['enth', 'chosen'],
            'standard schema, generated password'   => ['enth', 'generated'],
        ];
    }

    /**
     * @dataProvider joinCaseProvider
     */
    public function testNewJoinIsPendingAndSendsOnlyTheJoinEmail(string $schema, string $passwordMode): void
    {
        [$result, $mails] = $this->runHarness($schema, $passwordMode);

        self::assertNull($result['error'], 'The controller should not throw');
        self::assertNull($result['flash_error'], 'The join should not be rejected');

        // Saved, and waiting for approval.
        self::assertCount(1, $result['members']);
        $member = $result['members'][0];
        if ($schema === 'owned') {
            self::assertSame(1, (int) $member['pending']);
        } else {
            self::assertSame(0, (int) $member['approved']);
        }
        self::assertSame(0, (int) $member['showemail'], 'Nobody agreed to show this address');

        // One email, to the fan: the join email, with the password still filled in.
        self::assertCount(1, $mails, 'Only the join email should be sent');
        self::assertStringContainsString('newfan@example.com', $mails[0]);
        self::assertStringContainsString('Thank you for joining', $mails[0]);
        self::assertStringNotContainsString('set a new password', $mails[0], 'No reset email');
        self::assertSame(1, preg_match('/Password:\s*(\S+)/', $mails[0], $m), 'The join email shows the password');
        if ($passwordMode === 'chosen') {
            self::assertSame($result['chosen_password'], $m[1]);
        }
        self::assertTrue(password_verify($m[1], (string) $member['password']),
            'The emailed password is the one saved for the member');

        // Joining alone doesn't count as an update.
        self::assertSame('2020-01-01', $result['last_updated']);

        self::assertStringContainsString('awaiting approval', (string) $result['success_html']);
    }

    /**
     * @return array{0: array<string, mixed>, 1: list<string>} harness result, captured mails
     */
    private function runHarness(string $schema, string $passwordMode): array
    {
        if (!function_exists('proc_open')) {
            self::markTestSkipped('proc_open is not available');
        }

        $support  = __DIR__ . DIRECTORY_SEPARATOR . 'Support' . DIRECTORY_SEPARATOR;
        $mailFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'lstr_join_mail_' . uniqid('', true) . '.txt';

        // mail() runs this through the shell. On Windows, cmd.exe strips the
        // outer pair of quotes, so the whole command gets one extra pair.
        if (DIRECTORY_SEPARATOR === '\\') {
            $sendmail = '""' . PHP_BINARY . '" "' . $support . 'capture-mail.php" "' . $mailFile . '""';
        } else {
            $sendmail = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($support . 'capture-mail.php') . ' ' . escapeshellarg($mailFile);
        }

        $process = proc_open(
            [PHP_BINARY, '-d', 'sendmail_path=' . $sendmail, $support . 'join-controller-harness.php', $schema, $passwordMode],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        self::assertIsResource($process, 'Could not start the harness process');
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        $raw = is_file($mailFile) ? (string) file_get_contents($mailFile) : '';
        if (is_file($mailFile)) {
            unlink($mailFile);
        }

        self::assertSame(0, $exitCode, "Harness failed:\n" . $stderr);
        $result = json_decode($stdout, true);
        self::assertIsArray($result, "Harness output was not JSON:\n" . $stdout . "\n" . $stderr);

        $mails = array_values(array_filter(
            array_map('trim', explode(self::MAIL_SEPARATOR, $raw)),
            static fn(string $mail): bool => $mail !== '',
        ));

        return [$result, $mails];
    }
}
