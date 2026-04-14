<?php

declare(strict_types=1);

namespace Luster\Import;

/**
 * Value object carrying all options chosen in the import wizard configure step.
 *
 * Created from POST data by ImportController and passed to ImportSourceInterface
 * methods. Immutable after construction.
 */
final class ImportOptions
{
    /**
     * @param string $source              Source slug: 'enthusiast', 'listing-admin', 'fanupdate', 'codesort'
     * @param string $conflictStrategy    'skip' | 'overwrite'
     * @param bool   $importCategories
     * @param bool   $importAffiliates
     * @param bool   $importJoined
     * @param bool   $importOwnedListings Enthusiast only: copy owned/enth_fanlistings rows into target `owned` table
     * @param bool   $importSettings      Per-listing settings (lstr_fanlisting_settings) — Enthusiast only
     * @param bool   $importTemplates     Email templates (lstr_email_templates) — Enthusiast only
     * @param bool   $importListings      Listing Admin only: create owned rows + member tables
     * @param bool   $importMembers       Listing Admin only: migrate members into per-listing tables
     * @param bool   $importUpdates       LA / FanUpdate: requires Updates Feed add-on
     * @param bool   $importCodeCategories CodeSort only: import code categories
     * @param bool   $importCodeSizes     CodeSort only: import code sizes
     * @param bool   $importCodeDonors    CodeSort only: import code donors
     * @param bool   $importCodes         CodeSort only: import codes
     * @param string   $fanUpdatePrefix          FanUpdate blog table base name (default 'blog')
     * @param string   $imageMode                '' | 'path' | 'url' | 'zip'
     * @param string   $imagePath                Absolute server path to old script root (mode=path)
     * @param string   $imageUrl                 Base URL of old script (mode=url)
     * @param int|null $enthCategoryParentId     Enthusiast only: lstr_categories id to use as parent for
     *                                           all imported top-level categories (null = leave as top-level)
     */
    public function __construct(
        public readonly string $source,
        public readonly string $conflictStrategy,
        public readonly bool   $importCategories,
        public readonly bool   $importAffiliates,
        public readonly bool   $importJoined,
        public readonly bool   $importOwnedListings,
        public readonly bool   $importSettings,
        public readonly bool   $importTemplates,
        public readonly bool   $importListings,
        public readonly bool   $importMembers,
        public readonly bool   $importUpdates,
        public readonly bool   $importCodeCategories,
        public readonly bool   $importCodeSizes,
        public readonly bool   $importCodeDonors,
        public readonly bool   $importCodes,
        public readonly string $fanUpdatePrefix,
        public readonly string $imageMode,
        public readonly string $imagePath,
        public readonly string $imageUrl,
        public readonly ?int   $enthCategoryParentId = null,
    ) {}

    /**
     * Builds an ImportOptions from a raw POST array.
     * All values are sanitised/defaulted here so callers get a clean object.
     */
    public static function fromPost(array $post): self
    {
        $source = match ($post['source'] ?? '') {
            'listing-admin' => 'listing-admin',
            'fanupdate'     => 'fanupdate',
            'codesort'      => 'codesort',
            default         => 'enthusiast',
        };

        $conflict = match ($post['conflict_strategy'] ?? '') {
            'overwrite' => 'overwrite',
            default     => 'skip',
        };

        $imageMode = match ($post['image_mode'] ?? '') {
            'path' => 'path',
            'url'  => 'url',
            'zip'  => 'zip',
            default => '',
        };

        $enabled = (bool) ($post['import_images'] ?? false);
        if (!$enabled) {
            $imageMode = '';
        }

        $fuPrefix = trim((string) ($post['fu_prefix'] ?? 'blog'));
        if ($fuPrefix === '') {
            $fuPrefix = 'blog';
        }

        return new self(
            source:               $source,
            conflictStrategy:     $conflict,
            importCategories:     (bool) ($post['import_categories']       ?? false),
            importAffiliates:     (bool) ($post['import_affiliates']       ?? false),
            importJoined:         (bool) ($post['import_joined']           ?? false),
            importOwnedListings:  (bool) ($post['import_owned_listings']   ?? false),
            importSettings:       (bool) ($post['import_settings']         ?? false),
            importTemplates:      (bool) ($post['import_templates']       ?? false),
            importListings:       (bool) ($post['import_listings']        ?? false),
            importMembers:        (bool) ($post['import_members']         ?? false),
            importUpdates:        (bool) ($post['import_updates']         ?? false),
            importCodeCategories: (bool) ($post['import_code_categories'] ?? false),
            importCodeSizes:      (bool) ($post['import_code_sizes']      ?? false),
            importCodeDonors:     (bool) ($post['import_code_donors']     ?? false),
            importCodes:          (bool) ($post['import_codes']           ?? false),
            fanUpdatePrefix:      $fuPrefix,
            imageMode:            $imageMode,
            imagePath:            rtrim(trim((string) ($post['image_path'] ?? '')), '/\\'),
            imageUrl:             rtrim(trim((string) ($post['image_url'] ?? '')), '/'),
            enthCategoryParentId: ($post['category_parent_id'] ?? '') !== ''
                                    ? (int) $post['category_parent_id']
                                    : null,
        );
    }
}
