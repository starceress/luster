<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Immutable value object representing a single newsfeed entry from the
 lstr_newsfeed table. Holds the title, body, optional fanlisting association,
 category tags (stored as a comma-separated string), author, and timestamps.
 The categories() helper splits the comma-separated string into an array.
*/

declare(strict_types=1);

namespace Luster\Addons\Newsfeed;

/**
 * Represents a single newsfeed entry.
 */
final class NewsfeedEntry
{
    public function __construct(
        public readonly int     $id,
        public readonly ?int    $fanlistingId,
        public readonly string  $title,
        /** Raw, unsanitized text — always pass through NewsfeedBodySanitizer::toDisplayHtml() before output, never echoed directly. */
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
