<?php

declare(strict_types=1);

namespace Luster\Tests\Unit\Support;

/**
 * Runs scenarios through member-auth-harness.php, in a child PHP process started with
 * -d sendmail_path pointing at capture-mail.php and -d error_log at a temp file, so
 * mail() writes to a file and nothing is ever really sent. All of a test's scenarios go
 * to one child, and every scenario gets a fresh database.
 */
trait RunsMemberAuthHarness
{
    private function runOne(array $scenario): array
    {
        return $this->runScenarios(['only' => $scenario])['only'];
    }

    /**
     * @param array<string, array<string, mixed>> $scenarios
     * @return array<string, array<string, mixed>> Each scenario's result, under the same key.
     */
    private function runScenarios(array $scenarios): array
    {
        if (!function_exists('proc_open')) {
            self::markTestSkipped('proc_open is not available');
        }

        $support      = __DIR__ . DIRECTORY_SEPARATOR;
        $tmp          = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'lstr_member_auth_' . uniqid('', true);
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
            [PHP_BINARY, '-d', 'sendmail_path=' . $sendmail, '-d', 'log_errors=1', '-d', 'display_errors=0', '-d', 'error_log=' . $logFile,
                $support . 'member-auth-harness.php', $scenarioFile, $mailFile, $logFile],
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

        self::assertSame(0, $exitCode, "Harness failed:\n" . $stderr . "\n" . substr($stdout, 0, 2000));
        $results = json_decode($stdout, true);
        self::assertIsArray($results, "Harness output was not JSON:\n" . substr($stdout, 0, 2000) . "\n" . $stderr);
        self::assertCount(count($scenarios), $results);

        return array_combine(array_keys($scenarios), $results);
    }

    /**
     * Where in the trace the first statement containing $needle is.
     *
     * @param array<string, mixed> $r
     */
    private static function firstAt(array $r, string $needle): int
    {
        foreach ($r['statements'] as $i => $sql) {
            if (str_contains((string) $sql, $needle)) {
                return (int) $i;
            }
        }
        self::fail("No statement contains: {$needle}");
    }

    /**
     * Where in the trace the last statement containing $needle is.
     *
     * @param array<string, mixed> $r
     */
    private static function lastAt(array $r, string $needle): int
    {
        $at = null;
        foreach ($r['statements'] as $i => $sql) {
            if (str_contains((string) $sql, $needle)) {
                $at = (int) $i;
            }
        }
        if ($at === null) {
            self::fail("No statement contains: {$needle}");
        }
        return $at;
    }

    /**
     * Every flash message from the whole scenario, in order.
     *
     * @param array<string, mixed> $r
     * @return list<string>
     */
    private static function flashes(array $r): array
    {
        $all = [];
        foreach ($r['flashes'] as $step) {
            foreach ($step as $message) {
                $all[] = (string) $message;
            }
        }
        return $all;
    }
}
