<?php

declare(strict_types=1);

namespace Luster\Tests\Unit;

use Luster\Core\SessionPathResolver;
use PHPUnit\Framework\TestCase;

/**
 * Tests the writable-session-path fallback chain: configured path, then
 * sys_get_temp_dir(), then a Luster-controlled storage/sessions/ directory.
 * Private helpers are exercised directly via reflection where the public
 * resolve() chain can't be steered portably (e.g. simulating an unwritable
 * OS temp directory without real permission changes).
 */
final class SessionPathResolverTest extends TestCase
{
    private string $originalSavePath = '';
    private array $tempDirs = [];

    protected function setUp(): void
    {
        $this->originalSavePath = (string) ini_get('session.save_path');
    }

    protected function tearDown(): void
    {
        ini_set('session.save_path', $this->originalSavePath);

        foreach ($this->tempDirs as $dir) {
            $this->removeDir($dir);
        }
        $this->tempDirs = [];
    }

    private function makeTempDir(): string
    {
        $dir = sys_get_temp_dir() . '/lstr_spr_test_' . bin2hex(random_bytes(6));
        mkdir($dir, 0755, true);
        $this->tempDirs[] = $dir;
        return $dir;
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->removeDir($path) : @unlink($path);
        }
        @rmdir($dir);
    }

    // -----------------------------------------------------------------------
    // resolve() — public chain
    // -----------------------------------------------------------------------

    public function testResolveUsesConfiguredPathWhenWritable(): void
    {
        $dir = $this->makeTempDir();
        ini_set('session.save_path', $dir);

        $resolver = new SessionPathResolver($this->makeTempDir());
        $result   = $resolver->resolve();

        self::assertSame($dir, $result['path']);
        self::assertFalse($result['usedFallback']);
    }

    public function testResolveFallsBackToTempWhenConfiguredPathDoesNotExist(): void
    {
        ini_set('session.save_path', sys_get_temp_dir() . '/lstr_spr_nonexistent_' . bin2hex(random_bytes(6)));

        $resolver = new SessionPathResolver($this->makeTempDir());
        $result   = $resolver->resolve();

        self::assertSame(sys_get_temp_dir(), $result['path']);
        self::assertTrue($result['usedFallback']);
    }

    public function testResolveFallsBackToTempWhenConfiguredPathEmpty(): void
    {
        ini_set('session.save_path', '');

        $resolver = new SessionPathResolver($this->makeTempDir());
        $result   = $resolver->resolve();

        self::assertSame(sys_get_temp_dir(), $result['path']);
        self::assertTrue($result['usedFallback']);
    }

    public function testResolveHandlesMultiLevelSavePathFormat(): void
    {
        $dir = $this->makeTempDir();
        // PHP's legacy "N;/path" multi-level save_path format.
        ini_set('session.save_path', '5;' . $dir);

        $resolver = new SessionPathResolver($this->makeTempDir());
        $result   = $resolver->resolve();

        self::assertSame($dir, $result['path']);
        self::assertFalse($result['usedFallback']);
    }

    // -----------------------------------------------------------------------
    // prepareStorageFallback() — last-resort path, via reflection
    // -----------------------------------------------------------------------

    public function testPrepareStorageFallbackCreatesDirectory(): void
    {
        $base     = $this->makeTempDir();
        $resolver = new SessionPathResolver($base);

        $method = new \ReflectionMethod($resolver, 'prepareStorageFallback');
        $method->setAccessible(true);
        $path = $method->invoke($resolver);

        self::assertSame($base . '/storage/sessions', $path);
        self::assertDirectoryExists($path);
    }

    public function testPrepareStorageFallbackIsIdempotent(): void
    {
        $base     = $this->makeTempDir();
        $resolver = new SessionPathResolver($base);

        $method = new \ReflectionMethod($resolver, 'prepareStorageFallback');
        $method->setAccessible(true);
        $first  = $method->invoke($resolver);
        $second = $method->invoke($resolver);

        self::assertSame($first, $second);
        self::assertDirectoryExists($second);
    }

    // -----------------------------------------------------------------------
    // isWritable() — probe-file validation, via reflection
    // -----------------------------------------------------------------------

    public function testIsWritableAcceptsRealWritableDirAndCleansUpProbe(): void
    {
        $dir      = $this->makeTempDir();
        $resolver = new SessionPathResolver($this->makeTempDir());

        $method = new \ReflectionMethod($resolver, 'isWritable');
        $method->setAccessible(true);

        self::assertTrue($method->invoke($resolver, $dir));

        $leftover = array_filter(
            scandir($dir) ?: [],
            static fn(string $f): bool => str_starts_with($f, '.lstr_probe_')
        );
        self::assertSame([], array_values($leftover), 'Probe file must be cleaned up after the writability check');
    }

    public function testIsWritableRejectsNonExistentPath(): void
    {
        $resolver = new SessionPathResolver($this->makeTempDir());

        $method = new \ReflectionMethod($resolver, 'isWritable');
        $method->setAccessible(true);

        self::assertFalse($method->invoke($resolver, sys_get_temp_dir() . '/lstr_spr_does_not_exist_xyz'));
    }

    public function testIsWritableRejectsEmptyPath(): void
    {
        $resolver = new SessionPathResolver($this->makeTempDir());

        $method = new \ReflectionMethod($resolver, 'isWritable');
        $method->setAccessible(true);

        self::assertFalse($method->invoke($resolver, ''));
    }

    // -----------------------------------------------------------------------
    // normalizeConfiguredPath() — via reflection
    // -----------------------------------------------------------------------

    public function testNormalizeConfiguredPathHandlesMultiLevelFormat(): void
    {
        $resolver = new SessionPathResolver($this->makeTempDir());

        $method = new \ReflectionMethod($resolver, 'normalizeConfiguredPath');
        $method->setAccessible(true);

        self::assertSame('/var/lib/php/sessions', $method->invoke($resolver, '5;/var/lib/php/sessions'));
        self::assertSame('/tmp/sess', $method->invoke($resolver, '0;N;/tmp/sess'));
    }

    public function testNormalizeConfiguredPathPassesThroughPlainPath(): void
    {
        $resolver = new SessionPathResolver($this->makeTempDir());

        $method = new \ReflectionMethod($resolver, 'normalizeConfiguredPath');
        $method->setAccessible(true);

        self::assertSame('/var/lib/php/sessions', $method->invoke($resolver, '/var/lib/php/sessions'));
        self::assertSame('', $method->invoke($resolver, ''));
        self::assertSame('', $method->invoke($resolver, '   '));
    }
}
