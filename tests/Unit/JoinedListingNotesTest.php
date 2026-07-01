<?php

declare(strict_types=1);

namespace Luster\Tests\Unit;

use Luster\Models\JoinedListing;
use Luster\Repositories\JoinedListingRepo;
use Luster\Support\JoinedListingRowRenderer;
use Luster\Tests\Unit\Support\SqliteDatabase;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the description/comments private admin notes on lstr_joined
 * (preserved from Enthusiast's joined-listing `desc` and `comments` fields).
 *
 * Covers:
 *  - insert() persists both fields and getById() hydrates them back
 *  - update() replaces both fields when the keys are supplied
 *  - Empty-string input is normalized to NULL on both insert and update
 *  - update() leaves existing notes untouched when the keys are OMITTED
 *    entirely from $data — the backward-compatibility contract that
 *    distinguishes "caller doesn't know about this field" from
 *    "caller wants to clear it"
 *  - The public row renderer never exposes either field in its output
 */
final class JoinedListingNotesTest extends TestCase
{
    private const DDL = <<<SQL
CREATE TABLE lstr_joined (
    id                INTEGER PRIMARY KEY AUTOINCREMENT,
    name              TEXT    NOT NULL DEFAULT '',
    url               TEXT    NOT NULL DEFAULT '',
    image             TEXT    NULL,
    category          TEXT    NOT NULL DEFAULT '',
    categories_json   TEXT    NULL,
    date_joined       TEXT    NULL,
    active            INTEGER NOT NULL DEFAULT 1,
    status            TEXT    NOT NULL DEFAULT 'approved',
    created_at        TEXT    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    link_status       TEXT    NOT NULL DEFAULT 'unchecked',
    link_checked_at   TEXT    NULL,
    link_redirect_url TEXT    NULL,
    description       TEXT    NULL,
    comments          TEXT    NULL
)
SQL;

    private JoinedListingRepo $repo;

    protected function setUp(): void
    {
        $this->repo = new JoinedListingRepo(SqliteDatabase::make(self::DDL));
    }

    // -----------------------------------------------------------------------
    // insert() persists and getById() hydrates
    // -----------------------------------------------------------------------

    public function testInsertPersistsDescriptionAndComments(): void
    {
        $id = $this->repo->insert([
            'name'        => 'Test Listing',
            'url'         => 'https://example.test',
            'description' => 'A lovely fanlisting about cats.',
            'comments'    => 'Met the owner at a con once.',
        ]);

        $listing = $this->repo->getById($id);

        self::assertNotNull($listing);
        self::assertSame('A lovely fanlisting about cats.', $listing->description);
        self::assertSame('Met the owner at a con once.', $listing->comments);
    }

    public function testInsertWithoutDescriptionOrCommentsStoresNull(): void
    {
        $id = $this->repo->insert([
            'name' => 'No Notes',
            'url'  => 'https://nonotes.test',
        ]);

        $listing = $this->repo->getById($id);

        self::assertNotNull($listing);
        self::assertNull($listing->description);
        self::assertNull($listing->comments);
    }

    public function testInsertNormalizesEmptyStringToNull(): void
    {
        $id = $this->repo->insert([
            'name'        => 'Empty Notes',
            'url'         => 'https://empty.test',
            'description' => '',
            'comments'    => '',
        ]);

        $listing = $this->repo->getById($id);

        self::assertNotNull($listing);
        self::assertNull($listing->description);
        self::assertNull($listing->comments);
    }

    // -----------------------------------------------------------------------
    // update() replaces when keys are supplied
    // -----------------------------------------------------------------------

    public function testUpdateReplacesDescriptionAndComments(): void
    {
        $id = $this->repo->insert([
            'name'        => 'Original',
            'url'         => 'https://original.test',
            'description' => 'Old description.',
            'comments'    => 'Old comment.',
        ]);

        $this->repo->update($id, [
            'name'            => 'Original',
            'url'             => 'https://original.test',
            'image'           => null,
            'category'        => '',
            'categories_json' => null,
            'date_joined'     => null,
            'active'          => true,
            'status'          => 'approved',
            'description'     => 'New description.',
            'comments'        => 'New comment.',
        ]);

        $listing = $this->repo->getById($id);

        self::assertSame('New description.', $listing->description);
        self::assertSame('New comment.', $listing->comments);
    }

    public function testUpdateNormalizesEmptyStringToNull(): void
    {
        $id = $this->repo->insert([
            'name'        => 'Original',
            'url'         => 'https://original.test',
            'description' => 'Will be cleared.',
            'comments'    => 'Will also be cleared.',
        ]);

        $this->repo->update($id, [
            'name'            => 'Original',
            'url'             => 'https://original.test',
            'image'           => null,
            'category'        => '',
            'categories_json' => null,
            'date_joined'     => null,
            'active'          => true,
            'status'          => 'approved',
            'description'     => '',
            'comments'        => '',
        ]);

        $listing = $this->repo->getById($id);

        self::assertNull($listing->description);
        self::assertNull($listing->comments);
    }

    // -----------------------------------------------------------------------
    // update() leaves notes untouched when keys are OMITTED (P2 fix)
    // -----------------------------------------------------------------------

    /**
     * The core backward-compatibility contract: a caller that doesn't know
     * about description/comments (e.g. older code, or any future caller
     * that only updates a subset of fields) must not silently erase
     * existing imported notes just by omitting the keys.
     */
    public function testUpdateWithoutDescriptionKeyPreservesExistingDescription(): void
    {
        $id = $this->repo->insert([
            'name'        => 'Has Notes',
            'url'         => 'https://hasnotes.test',
            'description' => 'Imported from Enthusiast.',
            'comments'    => 'Imported admin note.',
        ]);

        // Simulates an older/partial caller that only updates name + url,
        // with no knowledge of description/comments at all.
        $this->repo->update($id, [
            'name'            => 'Has Notes (renamed)',
            'url'             => 'https://hasnotes.test',
            'image'           => null,
            'category'        => '',
            'categories_json' => null,
            'date_joined'     => null,
            'active'          => true,
            'status'          => 'approved',
        ]);

        $listing = $this->repo->getById($id);

        self::assertSame('Has Notes (renamed)', $listing->name, 'Sanity check: the update itself must still apply.');
        self::assertSame(
            'Imported from Enthusiast.',
            $listing->description,
            'Omitting the description key must NOT erase the existing stored value.'
        );
        self::assertSame(
            'Imported admin note.',
            $listing->comments,
            'Omitting the comments key must NOT erase the existing stored value.'
        );
    }

    public function testUpdateWithOnlyDescriptionKeyPreservesExistingComments(): void
    {
        $id = $this->repo->insert([
            'name'        => 'Partial Update',
            'url'         => 'https://partial.test',
            'description' => 'Original description.',
            'comments'    => 'Original comment.',
        ]);

        $this->repo->update($id, [
            'name'            => 'Partial Update',
            'url'             => 'https://partial.test',
            'image'           => null,
            'category'        => '',
            'categories_json' => null,
            'date_joined'     => null,
            'active'          => true,
            'status'          => 'approved',
            'description'     => 'Updated description.',
            // 'comments' intentionally omitted
        ]);

        $listing = $this->repo->getById($id);

        self::assertSame('Updated description.', $listing->description);
        self::assertSame('Original comment.', $listing->comments, 'Omitted comments key must preserve the original value.');
    }

    // -----------------------------------------------------------------------
    // Public rendering excludes both private fields
    // -----------------------------------------------------------------------

    public function testPublicRendererNeverExposesDescriptionOrComments(): void
    {
        $listing = new JoinedListing(
            id:              1,
            name:            'Secret Listing',
            url:             'https://secret.test',
            image:           null,
            category:        'Anime',
            categoriesJson:  null,
            dateJoined:      '2026-01-01',
            active:          true,
            status:          'approved',
            createdAt:       '2026-01-01 00:00:00',
            linkStatus:      'unchecked',
            linkCheckedAt:   null,
            linkRedirectUrl:  null,
            description:      'TOP SECRET DESCRIPTION TEXT',
            comments:         'TOP SECRET COMMENT TEXT',
            listedStatus:     'unchecked',
            listedCheckedAt:  null,
            listedMembersUrl: null,
        );

        $template = '{listing_subject} - {listing_url} - {listing_category} - {date_joined}';
        $html = JoinedListingRowRenderer::render($listing, $template, 'https://example.test/uploads');

        self::assertStringNotContainsString('TOP SECRET DESCRIPTION TEXT', $html);
        self::assertStringNotContainsString('TOP SECRET COMMENT TEXT', $html);
        self::assertStringContainsString('Secret Listing', $html, 'Sanity check: public fields must still render.');
    }

    public function testPublicRendererIgnoresDescriptionAndCommentsTokensIfPresentInTemplate(): void
    {
        $listing = new JoinedListing(
            id:              1,
            name:            'Listing',
            url:             'https://listing.test',
            image:           null,
            category:        'Anime',
            categoriesJson:  null,
            dateJoined:      null,
            active:          true,
            status:          'approved',
            createdAt:       '2026-01-01 00:00:00',
            linkStatus:      'unchecked',
            linkCheckedAt:   null,
            linkRedirectUrl:  null,
            description:      'PRIVATE DESCRIPTION',
            comments:         'PRIVATE COMMENT',
            listedStatus:     'unchecked',
            listedCheckedAt:  null,
            listedMembersUrl: null,
        );

        // Even if a site owner's custom template tries to reference these
        // (non-existent, undocumented) tokens, the renderer has no
        // {description}/{comments} substitution at all — the literal note
        // text can never leak into public output.
        $template = '{listing_subject} {description} {comments}';
        $html = JoinedListingRowRenderer::render($listing, $template, 'https://example.test/uploads');

        self::assertStringNotContainsString('PRIVATE DESCRIPTION', $html);
        self::assertStringNotContainsString('PRIVATE COMMENT', $html);
    }
}
