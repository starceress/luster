<?php

declare(strict_types=1);

namespace Luster\Tests\Unit;

use Luster\Import\ImageMigrator;
use PHPUnit\Framework\TestCase;

final class ImageMigratorTest extends TestCase
{
    private string $publicDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->publicDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'luster_img_test_' . uniqid('', true);
        mkdir($this->publicDir, 0777, true);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->deleteDir($this->publicDir);
    }

    /** A real 1×1 PNG, so finfo accepts it. */
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    /** Public IP literals: NetworkAddressGuard accepts them without a DNS lookup. */
    private const HOST_A = '93.184.215.14';
    private const HOST_B = '1.1.1.1';

    /** @var list<array<string, mixed>> Every request the fake transfer received. */
    private array $requests = [];

    public function testFetchFromUrlRejectsUnsupportedSchemes(): void
    {
        $migrator = $this->migratorWith([]);

        self::assertNull($migrator->fetchFromUrl('file:///etc/passwd', 'codes', 'cs_'));
        self::assertNull($migrator->fetchFromUrl('ftp://example.com/test.png', 'codes', 'cs_'));
        self::assertSame([], $this->requests);
    }

    public function testFetchFromUrlRejectsPrivateAndLoopbackHosts(): void
    {
        $migrator = $this->migratorWith([]);

        self::assertNull($migrator->fetchFromUrl('http://127.0.0.1/test.png', 'codes', 'cs_'));
        self::assertNull($migrator->fetchFromUrl('https://localhost/test.png', 'codes', 'cs_'));
        self::assertNull($migrator->fetchFromUrl('http://192.168.1.20/test.png', 'codes', 'cs_'));
        self::assertSame([], $this->requests);
    }

    public function testHttpToHttpsRedirectIsFollowedAndRepinnedForPort443(): void
    {
        $a = self::HOST_A;
        $migrator = $this->migratorWith([
            ['code' => 301, 'location' => "https://{$a}/codes/a.png", 'body' => '<html>Moved Permanently</html>'],
            ['code' => 200, 'location' => null, 'body' => base64_decode(self::PNG)],
        ]);

        $saved = $migrator->fetchFromUrl("http://{$a}/codes/a.png", 'codes', 'cd_');

        self::assertNotNull($saved);
        self::assertSame(base64_decode(self::PNG), file_get_contents($this->publicDir . '/uploads/codes/' . $saved));
        self::assertCount(2, $this->requests);
        self::assertSame("http://{$a}/codes/a.png", $this->requests[0]['url']);
        self::assertSame("{$a}:80:{$a}", $this->requests[0]['resolve']);
        self::assertSame("https://{$a}/codes/a.png", $this->requests[1]['url']);
        self::assertSame("{$a}:443:{$a}", $this->requests[1]['resolve']);

        foreach ($this->requests as $request) {
            self::assertTrue($request['verify_peer']);
            self::assertTrue($request['verify_host']);
            self::assertSame(10, $request['connect_timeout']);
            self::assertSame(10, $request['timeout']);
            self::assertSame(10 * 1024 * 1024, $request['max_bytes']);
        }

        // Only the image was stored, never the redirect page.
        self::assertCount(1, glob($this->publicDir . '/uploads/codes/cd_*') ?: []);
    }

    public function testExplicitPortIsKeptWhenPinning(): void
    {
        $a = self::HOST_A;
        $migrator = $this->migratorWith([
            ['code' => 302, 'location' => "https://{$a}:8443/a.png", 'body' => ''],
            ['code' => 200, 'location' => null, 'body' => base64_decode(self::PNG)],
        ]);

        self::assertNotNull($migrator->fetchFromUrl("http://{$a}:8080/a.png", 'codes', 'cd_'));
        self::assertSame("{$a}:8080:{$a}", $this->requests[0]['resolve']);
        self::assertSame("{$a}:8443:{$a}", $this->requests[1]['resolve']);
    }

    public function testRelativeRedirectsResolveAgainstTheCurrentAddress(): void
    {
        $a = self::HOST_A;
        $b = self::HOST_B;
        $cases = [
            '/images/b.png'   => ["https://{$a}/images/b.png", "{$a}:443:{$a}"],
            'b.png'           => ["https://{$a}/codes/b.png", "{$a}:443:{$a}"],
            '?version=2'      => ["https://{$a}/codes/a.png?version=2", "{$a}:443:{$a}"],
            "//{$b}/c.png"    => ["https://{$b}/c.png", "{$b}:443:{$b}"],
        ];

        foreach ($cases as $location => [$url, $resolve]) {
            $this->requests = [];
            $migrator = $this->migratorWith([
                ['code' => 301, 'location' => $location, 'body' => ''],
                ['code' => 200, 'location' => null, 'body' => base64_decode(self::PNG)],
            ]);

            self::assertNotNull($migrator->fetchFromUrl("https://{$a}/codes/a.png", 'codes', 'cd_'), $location);
            self::assertSame($url, $this->requests[1]['url'], $location);
            self::assertSame($resolve, $this->requests[1]['resolve'], $location);
        }
    }

    public function testRedirectToAPrivateHostStopsWithoutRequestingIt(): void
    {
        foreach (['http://127.0.0.1/a.png', 'https://localhost/a.png', 'http://10.0.0.5/a.png', 'http://192.168.1.20/a.png'] as $location) {
            $this->requests = [];
            $migrator = $this->migratorWith([
                ['code' => 302, 'location' => $location, 'body' => ''],
                ['code' => 200, 'location' => null, 'body' => base64_decode(self::PNG)],
            ]);

            self::assertNull($migrator->fetchFromUrl('https://' . self::HOST_A . '/a.png', 'codes', 'cd_'), $location);
            self::assertCount(1, $this->requests, $location);
        }
    }

    public function testThreeRedirectsAreFollowedButNotFour(): void
    {
        $a     = self::HOST_A;
        $hop   = static fn(int $n): array => ['code' => 302, 'location' => "https://{$a}/hop{$n}.png", 'body' => ''];
        $image = ['code' => 200, 'location' => null, 'body' => base64_decode(self::PNG)];

        $migrator = $this->migratorWith([$hop(1), $hop(2), $hop(3), $image]);
        self::assertNotNull($migrator->fetchFromUrl("https://{$a}/start.png", 'codes', 'cd_'));
        self::assertCount(4, $this->requests);

        $this->requests = [];
        $migrator = $this->migratorWith([$hop(1), $hop(2), $hop(3), $hop(4), $image]);
        self::assertNull($migrator->fetchFromUrl("https://{$a}/start.png", 'codes', 'cd_'));
        self::assertCount(4, $this->requests);
    }

    public function testRedirectWithoutLocationFails(): void
    {
        $migrator = $this->migratorWith([['code' => 301, 'location' => null, 'body' => '']]);

        self::assertNull($migrator->fetchFromUrl('https://' . self::HOST_A . '/a.png', 'codes', 'cd_'));
        self::assertCount(1, $this->requests);
    }

    public function testRedirectToAnotherSchemeStopsBeforeAnotherRequest(): void
    {
        foreach (['file:///etc/passwd', 'ftp://' . self::HOST_B . '/a.png', 'javascript:alert(1)', 'C:/x.png'] as $location) {
            $this->requests = [];
            $migrator = $this->migratorWith([
                ['code' => 301, 'location' => $location, 'body' => ''],
                ['code' => 200, 'location' => null, 'body' => base64_decode(self::PNG)],
            ]);

            self::assertNull($migrator->fetchFromUrl('https://' . self::HOST_A . '/codes/a.png', 'codes', 'cd_'), $location);
            self::assertCount(1, $this->requests, $location);
        }
    }

    public function testABodyOverTheCapFailsOnAnyHop(): void
    {
        $tooBig = str_repeat('x', 10 * 1024 * 1024 + 1);

        $migrator = $this->migratorWith([
            ['code' => 301, 'location' => 'https://' . self::HOST_A . '/b.png', 'body' => $tooBig],
            ['code' => 200, 'location' => null, 'body' => base64_decode(self::PNG)],
        ]);
        self::assertNull($migrator->fetchFromUrl('https://' . self::HOST_A . '/a.png', 'codes', 'cd_'));
        self::assertCount(1, $this->requests);

        $this->requests = [];
        $migrator = $this->migratorWith([['code' => 200, 'location' => null, 'body' => base64_decode(self::PNG) . $tooBig]]);
        self::assertNull($migrator->fetchFromUrl('https://' . self::HOST_A . '/a.png', 'codes', 'cd_'));
    }

    public function testErrorStatusesAndNonImagesAreNotStored(): void
    {
        foreach ([
            ['code' => 404, 'location' => null, 'body' => base64_decode(self::PNG)],
            ['code' => 0, 'location' => null, 'body' => ''],
            ['code' => 200, 'location' => null, 'body' => '<html>Not an image</html>'],
        ] as $response) {
            $migrator = $this->migratorWith([$response]);
            self::assertNull($migrator->fetchFromUrl('https://' . self::HOST_A . '/a.png', 'codes', 'cd_'));
        }
        self::assertSame([], glob($this->publicDir . '/uploads/codes/cd_*') ?: []);
    }

    /**
     * ensureDir() used to write its own, separate, weaker .htaccess (bare
     * "\.php$", Apache 2.2-only Deny-from-all syntax) instead of delegating
     * to UploadDirectoryGuard like the admin upload controllers do — since
     * neither ever overwrites an existing file, an import that ran first
     * could permanently shadow the stronger version a later admin upload
     * would otherwise have written to the same directory. Confirms it now
     * produces byte-identical content to the top-level public/uploads/.htaccess.
     */
    public function testEnsureDirWritesTheSharedStrongHtaccessNotAWeakerCopy(): void
    {
        $migrator = new ImageMigrator($this->publicDir);
        $m = new \ReflectionMethod($migrator, 'ensureDir');
        $m->setAccessible(true);

        $dir = $m->invoke($migrator, 'codes');

        self::assertDirectoryExists($dir);
        self::assertFileExists($dir . '/.htaccess');

        $generated = str_replace("\r\n", "\n", (string) file_get_contents($dir . '/.htaccess'));
        $topLevel  = str_replace("\r\n", "\n", (string) file_get_contents(
            dirname(__DIR__, 2) . '/public/uploads/.htaccess'
        ));

        self::assertSame($topLevel, $generated);
    }

    /**
     * An ImageMigrator whose HTTP transfer is replaced by $responses, returned in order.
     * Every request it gets is recorded in $this->requests; everything around the
     * transfer (scheme checks, NetworkAddressGuard, pinning, redirects) runs for real.
     *
     * @param list<array{code: int, location: ?string, body: string}> $responses
     */
    private function migratorWith(array $responses): ImageMigrator
    {
        return new ImageMigrator($this->publicDir, function (array $request) use (&$responses): array {
            $this->requests[] = $request;
            return array_shift($responses) ?? ['code' => 0, 'location' => null, 'body' => ''];
        });
    }

    private function deleteDir(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $items = scandir($path);
        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $child = $path . DIRECTORY_SEPARATOR . $item;
            if (is_dir($child)) {
                $this->deleteDir($child);
                continue;
            }

            unlink($child);
        }

        rmdir($path);
    }
}
