<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Immutable value object representing a single news feed entry from the
 lstr_updates table. Holds the title, body, optional fanlisting association,
 category tags (stored as a comma-separated string), author, and timestamps.
 The categories() helper splits the comma-separated string into an array.
*/

declare(strict_types=1);

namespace Luster\Addons\Updates;

/**
 * Represents a single update entry.
 */
final class Update
{
    public function __construct(
        public readonly int     $id,
        public readonly ?int    $fanlistingId,
        public readonly string  $title,
        public readonly string  $body,
        public readonly string  $category,
        public readonly string  $author,
        public readonly string  $postedAt,
        public readonly string  $createdAt,
    ) {}

    /**
     * Returns individual category values split from the comma-separated string.
     *
     * @return string[]
     */
    public function categories(): array
    {
        return array_values(array_filter(
            array_map('trim', explode(',', $this->category)),
            static fn(string $s): bool => $s !== '',
        ));
    }
}
