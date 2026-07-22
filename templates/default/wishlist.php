<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Wishlist content fragment (Wishlist add-on). Displays the collective owner's
 wishlist entries grouped by type (top wanted, wanted, granted) or filtered to
 a single type, with images, descriptions, and pagination. Rendered as an
 embeddable fragment via View::renderFragment() from embed/wishlist.php.
 Supports custom wishlist templates.
*/

use Luster\Core\View;

// When a custom wishlist_template has been rendered by the embed, use it directly.
if (isset($wishlist_template_html) && $wishlist_template_html !== null) {
    echo '<div class="fl-embed fl-embed--wishlist">';
    echo $wishlist_template_html;
    if ($pagination !== '') {
        echo '<div class="fl-pagination-wrap">' . $pagination . '</div>';
    }
    echo '</div>';
    return;
}

if (empty($entries)) {
    echo '<div class="fl-embed fl-embed--wishlist"><p class="fl-empty">No wishlist entries to display.</p></div>';
    return;
}

echo '<div class="fl-embed fl-embed--wishlist">';

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
        echo '<div class="fl-wishlist-group fl-wishlist-group--' . htmlspecialchars($type, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '">';
        echo '<h3 class="fl-wishlist-group-label">' . htmlspecialchars($typeLabels[$type] ?? $type, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</h3>';
        echo '<ul class="fl-wishlist-list">';
        foreach ($groupEntries as $entry) {
            echo '<li class="fl-wishlist-item fl-wishlist-item--' . htmlspecialchars($entry->type, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '">';
            if ($entry->image !== null && $entry->image !== '') {
                echo '<img class="fl-wishlist-image" src="' . htmlspecialchars('?luster_img=wishlist/' . $entry->image, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"'
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
                echo ' <span class="fl-wishlist-category">(' . htmlspecialchars($entry->category, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . ')</span>';
            }
            if ($entry->description !== null) {
                echo '<p class="fl-wishlist-description">' . htmlspecialchars($entry->description, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>';
            }
            echo '</div>';
            echo '</li>';
        }
        echo '</ul>';
        echo '</div>';
    }
} else {
    // Single-type flat list
    echo '<ul class="fl-wishlist-list">';
    foreach ($entries as $entry) {
        echo '<li class="fl-wishlist-item fl-wishlist-item--' . htmlspecialchars($entry->type, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '">';
        if ($entry->image !== null && $entry->image !== '') {
            echo '<img class="fl-wishlist-image" src="' . htmlspecialchars('?luster_img=wishlist/' . $entry->image, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"'
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
            echo ' <span class="fl-wishlist-category">(' . htmlspecialchars($entry->category, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . ')</span>';
        }
        if ($entry->description !== null) {
            echo '<p class="fl-wishlist-description">' . htmlspecialchars($entry->description, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>';
        }
        echo '</div>';
        echo '</li>';
    }
    echo '</ul>';
}

if ($pagination !== '') {
    echo '<div class="fl-pagination-wrap">' . $pagination . '</div>';
}
echo '</div>';
