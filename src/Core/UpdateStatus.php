<?php

declare(strict_types=1);

namespace Luster\Core;

final readonly class UpdateStatus
{
    public function __construct(
        /** Installed version string (from Version::CURRENT). */
        public string  $installed,
        /** Latest version fetched from GitHub, or null if never checked / check failed. */
        public ?string $latest,
        /** URL to the GitHub release page, or null when unavailable. */
        public ?string $releaseUrl,
        /** GitHub release title/name, or null when unavailable. */
        public ?string $releaseTitle,
        /** Unix timestamp of the last check attempt (success or failure), or null if never run. */
        public ?int    $checkedAt,
        /** True only when latest > installed and both are valid semver-like strings. */
        public bool    $updateAvailable,
        /** True when the last fetch attempt failed. Cached values are preserved. */
        public bool    $checkFailed,
        /** True when no check has ever been attempted. */
        public bool    $neverChecked,
        /** Human-readable failure reason, set only when checkFailed is true. */
        public ?string $error,
    ) {}
}
