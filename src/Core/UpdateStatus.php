<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Immutable value object returned by UpdateChecker. Carries the installed
 version, the latest available version from GitHub, the release page URL,
 and flags indicating whether an update is available, whether the last
 check failed, and whether a check has ever been attempted.
*/

declare(strict_types=1);

namespace Luster\Core;

final class UpdateStatus
{
    public function __construct(
        /** Installed version string (from Version::CURRENT). */
        public readonly string  $installed,
        /** Latest version fetched from GitHub, or null if never checked / check failed. */
        public readonly ?string $latest,
        /** URL to the GitHub release page, or null when unavailable. */
        public readonly ?string $releaseUrl,
        /** GitHub release title/name, or null when unavailable. */
        public readonly ?string $releaseTitle,
        /** Unix timestamp of the last check attempt (success or failure), or null if never run. */
        public readonly ?int    $checkedAt,
        /** True only when latest > installed and both are valid semver-like strings. */
        public readonly bool    $updateAvailable,
        /** True when the last fetch attempt failed. Cached values are preserved. */
        public readonly bool    $checkFailed,
        /** True when no check has ever been attempted. */
        public readonly bool    $neverChecked,
        /** Human-readable failure reason, set only when checkFailed is true. */
        public readonly ?string $error,
    ) {}
}
