<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Immutable value object that carries every option chosen in the
 import wizard's configure step. Built from POST data by
 ImportController via the fromPost() factory and passed through
 to ImportSourceInterface methods. Covers all six import sources
 and their source-specific settings (BellaBuffs file paths,
 Fanatically table prefix, CodeSort image paths, etc.).
*/

declare(strict_types=1);

namespace Luster\Import;

/**
 * Immutable value object that carries every option chosen in the import wizard.
 *
 * Built from raw POST data by ImportController via fromPost() and passed through
 * to ImportSourceInterface::preview() and run(). The constructor parameters cover
 * all six import sources; source-specific settings (BellaBuffs file paths,
 * Fanatically table prefix, FanUpdate blog table name, etc.) are populated only
 * when the matching source slug is active and ignored by other adapters.
 */
final class ImportOptions
{
    /**
     * @param string $source              Source slug: 'enthusiast', 'listing-admin', 'fanupdate', 'codesort', 'fanatically'
     * @param string $conflictStrategy    'skip' | 'overwrite'
     * @param bool   $importCategories
     * @param bool   $importAffiliates
     * @param bool   $importJoined
     * @param bool   $importOwnedListings Enthusiast only: copy owned/enth_fanlistings rows into target `owned` table
     * @param bool   $importSettings      Per-listing settings (lstr_fanlisting_settings) — Enthusiast only
     * @param bool   $importTemplates      Email templates (lstr_email_templates) — Enthusiast only
     * @param bool   $importSavedTemplates Saved/reusable email templates (lstr_saved_emails) — Erin's-fork Enthusiast only
     * @param bool   $importListings      Listing Admin / Fanatically: create owned rows + member tables
     * @param bool   $importMembers       Listing Admin / Fanatically: migrate members into per-listing tables
     * @param bool   $importUpdates       LA / FanUpdate: requires News Feed add-on
     * @param bool   $importWishlist       LA / Fanatically: owner's wishlist → lstr_wishlist (requires Wishlist add-on)
     * @param bool   $importKim           LA / Fanatically: fan adoption interest → lstr_kim_members (requires KIM add-on)
     * @param bool   $importRules         LA only: per-listing join rules → lstr_rules (requires Rules add-on)
     * @param bool   $importCodeCategories CodeSort only: import code categories
     * @param bool   $importCodeSizes     CodeSort only: import code sizes
     * @param bool   $importCodeDonors    CodeSort only: import code donors
     * @param bool   $importCodes         CodeSort / Fanatically: import codes (fan graphics)
     * @param string   $fanUpdatePrefix          FanUpdate blog table base name (default 'blog')
     * @param string   $imageMode                '' | 'auto' | 'zip'  ('auto' = try path first, fall back to URL)
     * @param string   $imagePath                Absolute server path to old script root (tried first in auto mode)
     * @param string   $imageUrl                 Base URL of old script (fallback in auto mode)
     * @param string   $fnTablePrefix            Fanatically: table prefix (default 'fanatic_')
     * @param bool     $fnKimActive              Fanatically: import KIM entries as active (true) or pending (false)
     * @param bool     $fnImportAdoption         Fanatically: import adoption applications → lstr_adoption_applications (requires Adoption add-on)
     * @param bool     $fnImportSiteSettings     Fanatically: import non-sensitive site settings into lstr_site_settings
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
        public readonly bool   $importSavedTemplates,
        public readonly bool   $importListings,
        public readonly bool   $importMembers,
        public readonly bool   $importUpdates,
        public readonly bool   $importWishlist,
        public readonly bool   $importKim,
        public readonly bool   $importRules,
        public readonly bool   $importCodeCategories,
        public readonly bool   $importCodeSizes,
        public readonly bool   $importCodeDonors,
        public readonly bool   $importCodes,
        public readonly string $fanUpdatePrefix,
        public readonly string $imageMode,
        public readonly string $imagePath,
        public readonly string $imageUrl,
        public readonly string $tablePrefix          = '',
        public readonly string $schemaVariant        = '',
        public readonly string $laTablePrefix        = '',
        /** Absolute path to a pre-uploaded ZIP file (mode=zip). Empty when not used. */
        public readonly string $zipPath              = '',
        // --- BellaBuffs-specific
        /** lstr_fanlistings / owned listingid to import into. */
        public readonly int    $bbTargetListingId  = 0,
        /** Import approved members from members.txt. */
        public readonly bool   $bbImportApproved   = true,
        /** Import pending members from newbies.txt. */
        public readonly bool   $bbImportPending    = false,
        /** Import affiliates from affiliates.txt (server-path mode only). */
        public readonly bool   $bbImportAffiliates = false,
        /** Import codes/buttons from buttons.txt (server-path mode only). */
        public readonly bool   $bbImportCodes      = false,
        /** 'path' = server folder, 'upload' = session temp files. */
        public readonly string $bbMode             = '',
        /** Absolute path to members.txt (path mode) or session temp file (upload mode). */
        public readonly string $bbMembersPath      = '',
        /** Absolute path to newbies.txt, or '' if not available. */
        public readonly string $bbNewbiesPath      = '',
        /** Absolute path to affiliates.txt, or '' if not available. */
        public readonly string $bbAffiliatesPath   = '',
        /** Absolute path to buttons.txt, or '' if not available. */
        public readonly string $bbButtonsPath      = '',
        /** Absolute path to buttons/ image directory, or '' if not available. */
        public readonly string $bbButtonsDir       = '',
        // --- Fanatically-specific
        /** Table prefix for Fanatically tables (default 'fanatic_'). */
        public readonly string $fnTablePrefix          = 'fanatic_',
        /** When true, KIM entries are imported as active (pending=0); false = pending=1. */
        public readonly bool   $fnKimActive             = true,
        /** Import adoption applications (requires Adoption add-on). */
        public readonly bool   $fnImportAdoption        = false,
        /** Import non-sensitive site settings into lstr_site_settings. */
        public readonly bool   $fnImportSiteSettings    = false,
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
            'bellabuffs'    => 'bellabuffs',
            'fanatically'   => 'fanatically',
            default         => 'enthusiast',
        };

        $conflict = match ($post['conflict_strategy'] ?? '') {
            'overwrite' => 'overwrite',
            default     => 'skip',
        };

        $zipPath = (string) ($post['_zip_path'] ?? '');
        $enabled = (bool) ($post['import_images'] ?? false);

        // Enthusiast, Listing Admin, and Fanatically always attempt auto migration — images
        // come from the source DB's own settings/options table, so no user opt-in needed.
        $alwaysAuto = in_array($source, ['enthusiast', 'listing-admin', 'fanatically'], true);

        // ZIP takes precedence (pre-uploaded); otherwise auto mode (path → URL fallback).
        if ($zipPath !== '') {
            $imageMode = 'zip';
        } elseif ($alwaysAuto || $enabled) {
            $imageMode = 'auto';
        } else {
            $imageMode = '';
        }

        $fuPrefix = trim((string) ($post['fu_prefix'] ?? 'blog'));
        if ($fuPrefix === '') {
            $fuPrefix = 'blog';
        }

        // --- Fanatically field resolution
        $fnTablePrefix       = 'fanatic_';
        $fnKimActive         = true;
        $fnImportAdoption    = false;
        $fnImportSiteSettings = false;

        if ($source === 'fanatically') {
            $rawPrefix = trim((string) ($post['fn_table_prefix'] ?? 'fanatic_'));
            $fnTablePrefix       = $rawPrefix; // empty string is valid (prefix-less Fanatically install)
            $fnKimActive         = ($post['fn_kim_status'] ?? 'active') !== 'pending';
            $fnImportAdoption    = !empty($post['fn_import_adoption']);
            $fnImportSiteSettings = !empty($post['fn_import_site_settings']);
        }

        // --- BellaBuffs field resolution
        $bbMode             = '';
        $bbTargetListingId  = 0;
        $bbImportApproved   = true;
        $bbImportPending    = false;
        $bbImportAffiliates = false;
        $bbImportCodes      = false;
        $bbMembersPath      = '';
        $bbNewbiesPath      = '';
        $bbAffiliatesPath   = '';
        $bbButtonsPath      = '';
        $bbButtonsDir       = '';

        if ($source === 'bellabuffs') {
            $bbMode            = (string) ($post['bb_mode'] ?? 'path');
            $bbTargetListingId = (int) ($post['bb_target_listing_id'] ?? 0);
            $bbImportApproved  = true; // always import approved members
            $bbImportPending   = !empty($post['bb_import_pending']);
            $bbImportAffiliates = !empty($post['bb_import_affiliates']);
            $bbImportCodes      = !empty($post['bb_import_codes']);

            if ($bbMode === 'path') {
                $folder = rtrim(trim((string) ($post['bb_folder_path'] ?? '')), '/\\');
                if ($folder !== '') {
                    $bbMembersPath    = $folder . '/members.txt';
                    $newbies          = $folder . '/newbies.txt';
                    $bbNewbiesPath    = (is_readable($newbies))                   ? $newbies               : '';
                    $affiliatesFile   = $folder . '/affiliates.txt';
                    $bbAffiliatesPath = (is_readable($affiliatesFile))            ? $affiliatesFile        : '';
                    $buttonsFile      = $folder . '/buttons.txt';
                    $bbButtonsPath    = (is_readable($buttonsFile))               ? $buttonsFile           : '';
                    $buttonsDir       = $folder . '/buttons';
                    $bbButtonsDir     = (is_dir($buttonsDir))                     ? $buttonsDir            : '';
                }
            } else {
                // Upload mode — paths injected by the controller from session.
                // _bb_affiliates_path, _bb_buttons_path, _bb_buttons_dir can also be
                // injected directly (used in tests; not set by the normal upload flow).
                $bbMembersPath    = (string) ($post['_bb_approved_path']   ?? '');
                $bbNewbiesPath    = (string) ($post['_bb_pending_path']    ?? '');
                $bbAffiliatesPath = (string) ($post['_bb_affiliates_path'] ?? '');
                $bbButtonsPath    = (string) ($post['_bb_buttons_path']    ?? '');
                $bbButtonsDir     = (string) ($post['_bb_buttons_dir']     ?? '');
            }
        }

        return new self(
            source:               $source,
            conflictStrategy:     $conflict,
            importCategories:     (bool) ($post['import_categories']       ?? false),
            importAffiliates:     (bool) ($post['import_affiliates']       ?? false),
            importJoined:         (bool) ($post['import_joined']           ?? false),
            importOwnedListings:  (bool) ($post['import_owned_listings']   ?? false),
            importSettings:       (bool) ($post['import_settings']         ?? false),
            importTemplates:      (bool) ($post['import_templates']        ?? false),
            importSavedTemplates: (bool) ($post['import_saved_templates']  ?? false),
            importListings:       (bool) ($post['import_listings']        ?? false),
            importMembers:        (bool) ($post['import_members']         ?? false),
            importUpdates:        (bool) ($post['import_updates']         ?? false),
            importWishlist:       (bool) ($post['import_wishlist']         ?? false),
            importKim:            (bool) ($post['import_kim']             ?? false),
            importRules:          (bool) ($post['import_rules']           ?? false),
            importCodeCategories: (bool) ($post['import_code_categories'] ?? false),
            importCodeSizes:      (bool) ($post['import_code_sizes']      ?? false),
            importCodeDonors:     (bool) ($post['import_code_donors']     ?? false),
            importCodes:          (bool) ($post['import_codes']           ?? false),
            fanUpdatePrefix:      $fuPrefix,
            imageMode:            $imageMode,
            imagePath:            rtrim(trim((string) ($post['image_path'] ?? '')), '/\\'),
            imageUrl:             rtrim(trim((string) ($post['image_url'] ?? '')), '/'),
            tablePrefix:          (string) ($post['_dump_table_prefix']   ?? ''),
            schemaVariant:        (string) ($post['_dump_schema_variant'] ?? ''),
            laTablePrefix:        trim((string) ($post['la_table_prefix'] ?? '')),
            zipPath:              (string) ($post['_zip_path']            ?? ''),
            bbTargetListingId:    $bbTargetListingId,
            bbImportApproved:     $bbImportApproved,
            bbImportPending:      $bbImportPending,
            bbImportAffiliates:   $bbImportAffiliates,
            bbImportCodes:        $bbImportCodes,
            bbMode:               $bbMode,
            bbMembersPath:        $bbMembersPath,
            bbNewbiesPath:        $bbNewbiesPath,
            bbAffiliatesPath:     $bbAffiliatesPath,
            bbButtonsPath:        $bbButtonsPath,
            bbButtonsDir:         $bbButtonsDir,
            fnTablePrefix:        $fnTablePrefix,
            fnKimActive:          $fnKimActive,
            fnImportAdoption:     $fnImportAdoption,
            fnImportSiteSettings: $fnImportSiteSettings,
        );
    }
}
