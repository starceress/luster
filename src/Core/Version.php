<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Application version constants. CURRENT is the installed version string
 compared against GitHub releases by UpdateChecker. BUILT_AT records the
 build timestamp. GITHUB_REPO and SUPPORT_EMAIL provide the canonical
 project coordinates used by the update checker and feedback system.
*/

declare(strict_types=1);

namespace Luster\Core;

/**
 * Application version constants.
 *
 * CURRENT is compared against GitHub release tags by UpdateChecker.
 * BUILT_AT is used as a fallback when two version strings cannot be ordered
 * by semver comparison alone (e.g. a version-number reset scenario).
 */
final class Version
{
    public const CURRENT          = '1.0.0-4';
    public const BUILT_AT         = '2026-07-02T00:00:00Z';
    public const GITHUB_REPO      = 'starceress/luster';
    public const SUPPORT_EMAIL    = 'support@luster.nu';
    public const SUPPORT_NAME     = 'Luster';
    /** Highest migration ID shipped with this version of Luster. */
    public const LATEST_MIGRATION = 109;
}
