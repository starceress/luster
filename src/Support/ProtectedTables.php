<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 The table names that can never be a listing's member table: Luster's own, and
 the ones Enthusiast keeps its registry and settings in. An import won't copy
 members into one, and deleting a listing won't drop one.
*/

declare(strict_types=1);

namespace Luster\Support;

/**
 * Knows which tables must never be treated as a listing's member table.
 *
 * A listing's table name comes from data: an imported row, or an old database. So
 * anything about to write to that table, or drop it, checks here first.
 */
final class ProtectedTables
{
    /** Enthusiast's own tables, in its default names and in the enth_ layout's. */
    private const NAMES = [
        'owned', 'category', 'joined', 'affiliates', 'settings', 'emailtemplate', 'errorlog',
        'enth_fanlistings', 'enth_category', 'enth_joined', 'enth_affiliates', 'enth_settings',
    ];

    private function __construct() {}

    /** True for a table that isn't a member table, whatever a listing says. Ignores case, as MySQL can. */
    public static function contains(string $table): bool
    {
        $lower = strtolower($table);

        return str_starts_with($lower, 'lstr_') || in_array($lower, self::NAMES, true);
    }
}
