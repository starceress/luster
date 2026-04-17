<?php

declare(strict_types=1);

use Luster\Core\View;

/**
 * Joined listings list — content fragment.
 *
 * Variables injected by embed/joined.php:
 *   $listings            JoinedListing[]              flat list (single-category or selector mode)
 *   $grouped             array<string, JoinedListing[]>  category-keyed list (show-all mode, sorted)
 *                        When non-empty, $listings is unused.
 *   $listing_html        string|null  pre-rendered rows via admin HTML template (null = fallback)
 *   $categories          string[]              all available category names (for the selector / browse index)
 *   $category_counts     array<string, int>    map of category name → active listing count
 *   $total_count         int                  total number of active joined listings
 *   $current_category    string                '' = no category chosen | 'all' = grouped | other = filter
 *   $show_list           bool         show category selector as bulleted list (vs dropdown)
 *   $browse_by_category  bool         browse-by-category mode: index view when no category selected
 *   $uploads_url         string       base URL for listing images
 *   $form_action         string       URL the category filter form/links point to
 *   $base_url            string
 *   $theme_path          string
 */

/** Renders one JoinedListing item — shared by both display modes */
$_renderItem = static function ($fl) use ($uploads_url): void {
    if ($fl->image !== null && $fl->image !== '') {
        echo '<a href="' . View::e($fl->url) . '" target="_blank" rel="noopener noreferrer">';
        echo '<img src="' . View::e($uploads_url . '/' . $fl->image) . '" alt="' . View::e($fl->name) . '">';
        echo '</a>';
    } else {
        echo '<a href="' . View::e($fl->url) . '" target="_blank" rel="noopener noreferrer">';
        echo View::e($fl->name);
        echo '</a>';
    }
};
?>
<div>

  <?php if ($browse_by_category): ?>
    <?php /* Browse-by-category mode */ ?>

    <?php if ($current_category === ''): ?>
      <?php /* Index: show list of all categories as links */ ?>
      <?php if (empty($categories)): ?>
        <p>No categories to display.</p>
      <?php else: ?>
        <ul>
          <li>
            <a href="<?= View::e($form_action . '?cat=all') ?>">All Categories</a>
            (<?= (int) ($total_count ?? 0) ?>)
          </li>
          <?php foreach ($categories as $catName): ?>
            <li>
              <a href="<?= View::e($form_action . '?cat=' . urlencode($catName)) ?>"><?= View::e($catName) ?></a> (<?= (int) (($category_counts ?? [])[$catName] ?? 0) ?>)
            </li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>

    <?php else: ?>
      <?php /* Category selected: show listings + back link */ ?>
      <p><a href="<?= View::e($form_action) ?>">&larr; All categories</a></p>

      <?php if (($listing_html ?? null) !== null && $listing_html !== ''): ?>
        <!-- Custom DB template listing rows -->
        <ul>
          <?= $listing_html ?>
        </ul>
      <?php elseif (empty($listings)): ?>
        <p>No listings in this category.</p>
      <?php else: ?>
        <ul>
          <?php foreach ($listings as $fl): ?>
            <li><?php $_renderItem($fl); ?></li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>

    <?php endif; ?>

  <?php else: ?>
    <?php /* Standard selector / grouped mode */ ?>
    <?php
    // Grouped mode = 'all' keyword (no selector) OR show-all-by-default with no category picked
    $_isGroupedMode   = !empty($grouped) && $current_category === 'all';
    $_selectorWithAll = !empty($grouped) && $current_category === '';  // dropdown + grouped below
    $_hasCategory     = $current_category !== '' && $current_category !== 'all' && !$_isGroupedMode;
    $_showSelector    = ($current_category === '' || $_hasCategory) && !empty($categories);
    ?>

    <?php if (($listing_html ?? null) !== null && $listing_html !== ''): ?>
      <!-- Custom DB template listing rows -->
      <ul>
        <?= $listing_html ?>
      </ul>

    <?php elseif ($_isGroupedMode): ?>
      <?php /* 'all' mode: grouped by category, no selector */ ?>
      <?php if (empty($grouped)): ?>
        <p>No listings to display.</p>
      <?php else: ?>
        <?php foreach ($grouped as $catName => $items): ?>
          <h3><?= View::e($catName) ?></h3>
          <ul>
            <?php foreach ($items as $fl): ?>
              <li><?php $_renderItem($fl); ?></li>
            <?php endforeach; ?>
          </ul>
        <?php endforeach; ?>
      <?php endif; ?>

    <?php else: ?>
      <?php /* Selector + results mode */ ?>

      <?php if ($_showSelector): ?>
        <?php $_selector = static function () use ($categories, $current_category, $form_action, $show_list): void { ?>
          <div>
            <?php if ($show_list): ?>
              <ul>
                <?php foreach ($categories as $catName): ?>
                  <li>
                    <a <?= $current_category === $catName ? 'aria-current="true"' : '' ?>
                       href="<?= View::e($form_action . '?cat=' . urlencode($catName)) ?>">
                      <?= View::e($catName) ?>
                    </a>
                  </li>
                <?php endforeach; ?>
              </ul>
            <?php else: ?>
              <form method="GET" action="<?= View::e($form_action) ?>">
                <label for="fl-joined-cat">Category:</label>
                <select id="fl-joined-cat" name="cat"
                        onchange="this.form.submit()">
                  <option value="">All categories&hellip;</option>
                  <?php foreach ($categories as $catName): ?>
                    <option value="<?= View::e($catName) ?>"
                      <?= $current_category === $catName ? 'selected' : '' ?>>
                      <?= View::e($catName) ?>
                    </option>
                  <?php endforeach; ?>
                </select>
                <noscript><button type="submit">Go</button></noscript>
              </form>
            <?php endif; ?>
          </div>
        <?php }; $_selector(); ?>
      <?php endif; ?>

      <?php if ($_selectorWithAll): ?>
        <?php /* show-all-by-default: grouped listings below the selector */ ?>
        <?php if (empty($grouped)): ?>
          <p>No listings to display.</p>
        <?php else: ?>
          <?php foreach ($grouped as $catName => $items): ?>
            <h3><?= View::e($catName) ?></h3>
            <ul>
              <?php foreach ($items as $fl): ?>
                <li><?php $_renderItem($fl); ?></li>
              <?php endforeach; ?>
            </ul>
          <?php endforeach; ?>
        <?php endif; ?>
      <?php elseif ($_hasCategory): ?>
        <?php if (empty($listings)): ?>
          <p>No listings in this category.</p>
        <?php else: ?>
          <ul>
            <?php foreach ($listings as $fl): ?>
              <li><?php $_renderItem($fl); ?></li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      <?php elseif (!$_showSelector): ?>
        <p>No listings to display.</p>
      <?php endif; ?>

    <?php endif; ?>

  <?php endif; ?>

</div>
