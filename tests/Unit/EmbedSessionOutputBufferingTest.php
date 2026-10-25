<?php

declare(strict_types=1);

namespace Luster\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Embeds that use a session (join, members, update, contact, wishlist, KIM,
 * ...) start it partway through the host page, after the site's own header
 * HTML. On hosts with output_buffering turned off that HTML has already been
 * sent, so session_start() fails and the page used to just stop.
 *
 * Each case runs in a separate PHP process with output_buffering=0 so it
 * behaves like those hosts — PHPUnit's own process can't, since it has
 * already printed output and has its own buffers.
 */
final class EmbedSessionOutputBufferingTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/luster_ob_test_' . bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->dir);
    }

    public function testSessionStartsWhenSetupLineRunsBeforeHostHtml(): void
    {
        $out = $this->runHostPage(true);

        self::assertStringContainsString('SESSION-ERROR=none', $out);
        self::assertStringContainsString('SESSION-ACTIVE=yes', $out);
        self::assertStringContainsString('FOOTER-REACHED', $out);
    }

    public function testMissingSetupLineShowsReadableErrorInsteadOfStopping(): void
    {
        $out = $this->runHostPage(false);

        self::assertStringContainsString('Luster embed error: this page sent some of its HTML', $out);
        self::assertStringContainsString('SESSION-ACTIVE=no', $out);
        self::assertStringContainsString('FOOTER-REACHED', $out);
        // Shown to visitors, so it must not leak where anything lives on the server.
        self::assertStringNotContainsString($this->dir, $out);
        self::assertStringNotContainsString(dirname(__DIR__, 2), $out);
    }

    public function testRunningSetupLineTwiceDoesNotStackBuffers(): void
    {
        $out = $this->runScript(
            'Luster\Core\View::serveEmbedAsset();' . "\n"
            . 'Luster\Core\View::serveEmbedAsset();' . "\n"
            . 'echo "LEVEL=" . ob_get_level();'
        );

        self::assertStringContainsString('LEVEL=1', $out);
    }

    private function runHostPage(bool $withSetupLine): string
    {
        return $this->runScript(
            ($withSetupLine ? 'Luster\Core\View::serveEmbedAsset();' . "\n" : '')
            . 'echo "<!DOCTYPE html><html><body><div id=\"intro\">\n";' . "\n"
            . '$err = Luster\Core\View::startEmbedSession(new Luster\Core\Session());' . "\n"
            . 'echo $err ?? "SESSION-ERROR=none";' . "\n"
            . 'echo "\nSESSION-ACTIVE=" . (session_status() === PHP_SESSION_ACTIVE ? "yes" : "no");' . "\n"
            . 'echo "\n</div><p>FOOTER-REACHED</p></body></html>";'
        );
    }

    private function runScript(string $body): string
    {
        $autoload = var_export(dirname(__DIR__, 2) . '/vendor/autoload.php', true);
        $script   = $this->dir . '/host.php';
        file_put_contents($script, "<?php\nrequire {$autoload};\n{$body}\n");

        $cmd = escapeshellarg(PHP_BINARY)
            . ' -d output_buffering=0'
            . ' -d display_errors=0'
            . ' -d session.save_path=' . escapeshellarg($this->dir)
            . ' ' . escapeshellarg($script) . ' 2>&1';

        $out = shell_exec($cmd);
        self::assertIsString($out, 'Could not run the host-page script');

        return $out;
    }
}
