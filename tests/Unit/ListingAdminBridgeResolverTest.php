<?php

declare(strict_types=1);

namespace Luster\Tests\Unit;

use Luster\Support\ListingAdminBridgeResolver;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * The lookup behind public/compat/listing-admin/luster-bridge.php.
 */
final class ListingAdminBridgeResolverTest extends TestCase
{
    private PDO $db;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec(
            'CREATE TABLE lstr_listing_external_refs (
                id INTEGER PRIMARY KEY AUTOINCREMENT, listing_id INTEGER NOT NULL,
                source TEXT NOT NULL, external_id TEXT NOT NULL,
                external_slug_or_url TEXT NOT NULL DEFAULT \'\', UNIQUE (source, external_id)
            )'
        );
    }

    private function link(string $source, int $laId, int $lusterId): void
    {
        $this->db->prepare('INSERT INTO lstr_listing_external_refs (listing_id, source, external_id) VALUES (?, ?, ?)')
            ->execute([$lusterId, $source, (string) $laId]);
    }

    public function testWithoutSourceKeyUsesSharedLinks(): void
    {
        $this->link('listing-admin', 7, 12);

        self::assertSame(12, ListingAdminBridgeResolver::resolve($this->db, 7, ''));
    }

    public function testWithSourceKeyUsesThatInstallsLinks(): void
    {
        $this->link('listing-admin', 7, 12);
        $this->link('listing-admin:second-site', 7, 30);

        self::assertSame(30, ListingAdminBridgeResolver::resolve($this->db, 7, 'second-site'));
    }

    public function testSourceKeyNeverFallsBackToTheSharedLink(): void
    {
        // The shared link belongs to install A; install B's own link is missing.
        // Falling back would make B's pages show A's listing.
        $this->link('listing-admin', 7, 12);
        $this->link('listing-admin:first-site', 7, 12);

        self::assertNull(ListingAdminBridgeResolver::resolve($this->db, 7, 'second-site'));
    }

    public function testInvalidSourceKeyResolvesNothing(): void
    {
        $this->link('listing-admin', 7, 12);

        self::assertNull(ListingAdminBridgeResolver::resolve($this->db, 7, 'Second_Site'));
    }

    public function testUnknownOrMissingListingIdResolvesNothing(): void
    {
        $this->link('listing-admin', 7, 12);

        self::assertNull(ListingAdminBridgeResolver::resolve($this->db, 8, ''));
        self::assertNull(ListingAdminBridgeResolver::resolve($this->db, 0, ''));
        self::assertNull(ListingAdminBridgeResolver::resolve($this->db, -3, ''));
    }
}
