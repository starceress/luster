<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Provides realistic but fictional sample data (members, affiliates, updates,
 additional fields) for the admin template preview screens. This lets admins
 see how a template renders without needing a real listing or any database
 records. All data is static and deterministic so previews are consistent.
*/

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
    public const LISTING_TITLE   = 'Silver Crystal';
    public const LISTING_SUBJECT = 'Sailor Moon';
    public const LISTING_URL     = 'https://example.com/silver-crystal';

    public const LISTING_TITLE_A = 'Silver Crystal';
    public const LISTING_TITLE_B = 'Jedi Order';

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
            ['name' => 'Usagi',   'email' => 'usagi@example.com',   'url' => 'https://example.com/usagi',   'country' => 'Japan',         'fave' => 'Sailor Moon'],
            ['name' => 'Misty',   'email' => 'misty@example.com',   'url' => '',                              'country' => 'United States', 'fave' => 'Togepi'],
            ['name' => 'Sakura',  'email' => 'sakura@example.com',  'url' => 'https://example.com/sakura',  'country' => '',              'fave' => 'Kero'],
            ['name' => 'Winry',   'email' => 'winry@example.com',   'url' => '',                              'country' => '',              'fave' => 'Automail'],
            ['name' => 'Naminé',  'email' => 'namine@example.com',  'url' => 'https://example.com/namine',  'country' => 'Canada',        'fave' => 'Sora'],
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
            self::makeAffiliate(1, 'Moon Kingdom',    'https://example.com/moon-kingdom',   'affiliate-placeholder.svg'),
            self::makeAffiliate(2, 'Pallet Town',    'https://example.com/pallet-town',   'affiliate-placeholder.svg'),
            self::makeAffiliate(3, 'Jedi Archives',  'https://example.com/jedi-archives',  'affiliate-placeholder.svg'),
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
            new Update(1, null, 'Site Relaunch', 'The fanlisting has been relaunched with a new layout inspired by the Crystal Tokyo arc. Welcome back, everyone!', 'News, Site', 'Admin', '2026-06-10', '2026-06-10 09:00:00'),
            new Update(2, null, 'New Members Welcome', 'Welcome to all the new members who joined this month! The Silver Crystal keeps growing.', 'Members', '', '2026-06-05', '2026-06-05 09:00:00'),
            new Update(3, null, 'Affiliates Updated', 'Added Pallet Town and Jedi Archives to our affiliates.', '', 'Admin', '2026-05-28', '2026-05-28 09:00:00'),
        ];
    }
}
