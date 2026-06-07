<?php
use Luster\Core\View;
/**
 * Public wishlist fragment template.
 *
 * Variables (injected via extract($data) by View::renderFragment):
 * @var \Luster\Models\Wishlist[] $entries
 * @var string $filter_type   'all' | 'wanted' | 'top' | 'granted'
 * @var string $base_url
 * @var string|null $wishlist_template_html  pre-rendered HTML from embed (null = use default)
 */

// When a custom wishlist_template has been rendered by the embed, use it directly.
if (isset($wishlist_template_html) && $wishlist_template_html !== null) {
    echo $wishlist_template_html;
    return;
}

if (empty($entries)) {
    echo '<p class="fl-empty">No wishlist entries to display.</p>';
    return;
}

$typeLabels = [
    'top'     => 'Top Wanted',
    'granted' => 'Granted',
    'wanted'  => 'Wanted',
];

if ($filter_type === 'all') {
    // Group by type in a defined order
    $groups = ['top' => [], 'wanted' => [], 'granted' => []];
    foreach ($entries as $entry) {
        $groups[$entry->type][] = $entry;
    }

    foreach ($groups as $type => $groupEntries) {
        if (empty($groupEntries)) {
            continue;
        }
        echo '<div class="fl-wishlist-group">';
        echo '<h3 class="fl-wishlist-group-label">' . htmlspecialchars($typeLabels[$type] ?? $type, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</h3>';
        echo '<ul class="fl-wishlist">';
        foreach ($groupEntries as $entry) {
            echo '<li class="fl-wishlist-item">';
            if ($entry->image !== null && $entry->image !== '') {
                echo '<img class="fl-wishlist-img" src="' . htmlspecialchars($base_url . '/uploads/wishlist/' . $entry->image, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"'
                   . ' alt="' . htmlspecialchars($entry->subject, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '">';
            }
            echo '<div class="fl-wishlist-info">';
            if ($entry->url !== '') {
                echo '<a class="fl-wishlist-subject" href="' . htmlspecialchars($entry->url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"'
                   . ' rel="noopener noreferrer">' . htmlspecialchars($entry->subject, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</a>';
            } else {
                echo '<span class="fl-wishlist-subject">' . htmlspecialchars($entry->subject, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</span>';
            }
            if ($entry->category !== '') {
                echo ' <span class="fl-wishlist-cat">(' . htmlspecialchars($entry->category, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . ')</span>';
            }
            if ($entry->description !== null) {
                echo '<p class="fl-wishlist-desc">' . htmlspecialchars($entry->description, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>';
            }
            echo '</div>';
            echo '</li>';
        }
        echo '</ul>';
        echo '</div>';
    }
} else {
    // Single-type flat list
    echo '<ul class="fl-wishlist">';
    foreach ($entries as $entry) {
        echo '<li class="fl-wishlist-item">';
        if ($entry->image !== null && $entry->image !== '') {
            echo '<img class="fl-wishlist-img" src="' . htmlspecialchars($base_url . '/uploads/wishlist/' . $entry->image, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"'
               . ' alt="' . htmlspecialchars($entry->subject, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '">';
        }
        echo '<div class="fl-wishlist-info">';
        if ($entry->url !== '') {
            echo '<a class="fl-wishlist-subject" href="' . htmlspecialchars($entry->url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"'
               . ' rel="noopener noreferrer">' . htmlspecialchars($entry->subject, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</a>';
        } else {
            echo '<span class="fl-wishlist-subject">' . htmlspecialchars($entry->subject, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</span>';
        }
        if ($entry->category !== '') {
            echo ' <span class="fl-wishlist-cat">(' . htmlspecialchars($entry->category, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . ')</span>';
        }
        if ($entry->description !== null) {
            echo '<p class="fl-wishlist-desc">' . htmlspecialchars($entry->description, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>';
        }
        echo '</div>';
        echo '</li>';
    }
    echo '</ul>';
}
