<?php

declare(strict_types=1);

namespace Luster\Support;

use Luster\Addons\Updates\Update;
use Luster\Models\AdditionalField;
use Luster\Models\Affiliate;
use Luster\Models\Member;

/**
 * Generic, listing-independent sample data for the Global Listing Templates
 * preview (see ListingController::globalTemplatesPreview()). Lets admins see
 * how a template renders without selecting a real listing or touching the
 * database.
 */
final class SampleTemplateData
{
    private function __construct()
    {
    }

    /**
     * @return AdditionalField[]
     */
    public static function additionalFields(): array
    {
        return [
            new AdditionalField('favorite_character', 'Favorite Character', 'text', false),
        ];
    }

    /**
     * @return Member[]
     */
    public static function members(): array
    {
        $rows = [
            ['name' => 'Sora Akiyama', 'email' => 'sora@example.com',  'url' => 'https://example.com/sora',  'country' => 'Japan',         'fave' => 'Sailor Moon'],
            ['name' => 'Jamie Lee',    'email' => 'jamie@example.com', 'url' => '',                           'country' => 'United States', 'fave' => 'Naruto'],
            ['name' => 'Priya Sharma', 'email' => 'priya@example.com', 'url' => 'https://example.com/priya', 'country' => '',              'fave' => 'Totoro'],
            ['name' => 'Alex Morgan',  'email' => 'alex@example.com',  'url' => '',                           'country' => '',              'fave' => 'Pikachu'],
            ['name' => 'Yuki Tanaka',  'email' => 'yuki@example.com',  'url' => 'https://example.com/yuki',  'country' => 'Canada',        'fave' => 'Goku'],
        ];

        $members = [];
        foreach ($rows as $i => $row) {
            $members[] = new Member(
                id: $i + 1,
                name: $row['name'],
                email: $row['email'],
                url: $row['url'],
                country: $row['country'],
                showurl: true,
                password: '',
                approved: true,
                rejected: false,
                added: '2026-01-15',
                updated: '',
                additionalFields: ['favorite_character' => $row['fave']],
            );
        }

        return $members;
    }

    /**
     * @return Affiliate[]
     */
    public static function affiliates(): array
    {
        return [
            self::makeAffiliate(1, 'Star Crossed Listings',   'https://example.com/star-crossed',  'affiliate-placeholder.svg'),
            self::makeAffiliate(2, 'Moonlit Path Fanlisting', 'https://example.com/moonlit-path',  'affiliate-placeholder.svg'),
            self::makeAffiliate(3, 'Crimson Tide Network',    'https://example.com/crimson-tide',  'affiliate-placeholder.svg'),
        ];
    }

    private static function makeAffiliate(int $id, string $name, string $url, ?string $image): Affiliate
    {
        return new Affiliate(
            id: $id,
            listingId: null,
            name: $name,
            url: $url,
            email: null,
            image: $image,
            sortOrder: $id,
            active: true,
            status: 'approved',
            createdAt: '2026-01-01 00:00:00',
            updatedAt: '2026-01-01 00:00:00',
            linkStatus: 'unchecked',
            linkCheckedAt: null,
            linkRedirectUrl: null,
            reciprocalStatus: 'unchecked',
            reciprocalCheckedAt: null,
            pending: false,
            applicationMessage: null,
        );
    }

    /**
     * @return Update[]
     */
    public static function updates(): array
    {
        return [
            new Update(1, null, 'Site Relaunch', 'We have relaunched the fanlisting with a brand new layout and updated member list. Thanks for your patience!', 'News, Site', 'Admin', '2026-06-10', '2026-06-10 09:00:00'),
            new Update(2, null, 'New Members Welcome', 'A warm welcome to everyone who joined this month!', 'Members', '', '2026-06-05', '2026-06-05 09:00:00'),
            new Update(3, null, 'Affiliates Updated', 'Added a few new affiliates to the directory.', '', 'Admin', '2026-05-28', '2026-05-28 09:00:00'),
        ];
    }
}
