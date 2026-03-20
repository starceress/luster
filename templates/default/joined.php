<?php

declare(strict_types=1);

use Luster\Core\View;

/**
 * Joined listings list — content fragment.
 *
 * Variables injected by embed/joined.php:
 *   $listings        JoinedListing[]              flat list (single-category or selector mode)
 *   $grouped            array<string, JoinedListing[]>  category-keyed list (show-all mode, sorted)
 *                       When non-empty, $listings is unused.
 *   $categories         string[]            all available category names (for the selector)
 *   $current_category   string              '' = no category chosen | 'all' = grouped | other = filter
 *   $show_list          bool                show category selector as bulleted list (vs dropdown)
 *   $uploads_url        string              base URL for listing images
 *   $form_action        string              URL the category filter form/links point to
 *   $base_url           string
 *   $theme_path         string
 */

// Grouped mode = 'all' keyword (no selector) OR show-all-by-default with no category picked
$_isGroupedMode    = !empty($grouped) && $current_category === 'all';
$_selectorWithAll  = !empty($grouped) && $current_category === '';  // dropdown + grouped below
$_hasCategory      = $current_category !== '' && $current_category !== 'all' && !$_isGroupedMode;
$_showSelector     = ($current_category === '' || $_hasCategory) && !empty($categories);

/** Renders one JoinedListing item — shared by both display modes */
$_renderItem = static function ($fl) use ($uploads_url): void {
    if ($fl->image !== null && $fl->image !== '') {
        echo '<a class="fl-affiliate-link" href="' . View::e($fl->url) . '" target="_blank" rel="noopener noreferrer">';
        echo '<img class="fl-affiliate-img" src="' . View::e($uploads_url . '/' . $fl->image) . '" alt="' . View::e($fl->name) . '">';
        echo '</a>';
    } else {
        echo '<a class="fl-link" href="' . View::e($fl->url) . '" target="_blank" rel="noopener noreferrer">';
        echo View::e($fl->name);
        echo '</a>';
    }
};
?>
<div class="fl-wrap">

  <?php if (($listing_html ?? null) !== null && $listing_html !== ''): ?>
    <!-- Custom DB template listing rows -->
    <ul class="fl-fanlisting-list">
      <?= $listing_html ?>
    </ul>

  <?php elseif ($_isGroupedMode): ?>
    <?php /* 'all' mode: grouped by category, no selector */ ?>
    <?php if (empty($grouped)): ?>
      <p class="fl-empty">No listings to display.</p>
    <?php else: ?>
      <?php foreach ($grouped as $catName => $items): ?>
        <h3 class="fl-category-heading"><?= View::e($catName) ?></h3>
        <ul class="fl-fanlisting-list">
          <?php foreach ($items as $fl): ?>
            <li class="fl-fanlisting"><?php $_renderItem($fl); ?></li>
          <?php endforeach; ?>
        </ul>
      <?php endforeach; ?>
    <?php endif; ?>

  <?php else: ?>
    <?php /* Selector + results mode */ ?>

    <?php if ($_showSelector): ?>
      <?php $_selector = static function () use ($categories, $current_category, $form_action, $show_list): void { ?>
        <div class="fl-filter">
          <?php if ($show_list): ?>
            <ul class="fl-category-list">
              <?php foreach ($categories as $catName): ?>
                <li>
                  <a class="fl-link<?= $current_category === $catName ? ' active' : '' ?>"
                     href="<?= View::e($form_action . '?cat=' . urlencode($catName)) ?>">
                    <?= View::e($catName) ?>
                  </a>
                </li>
              <?php endforeach; ?>
            </ul>
          <?php else: ?>
            <form method="GET" action="<?= View::e($form_action) ?>" class="fl-filter-form">
              <label class="fl-label" for="fl-joined-cat">Category:</label>
              <select class="fl-select" id="fl-joined-cat" name="cat"
                      onchange="this.form.submit()">
                <option value="">All categories&hellip;</option>
                <?php foreach ($categories as $catName): ?>
                  <option value="<?= View::e($catName) ?>"
                    <?= $current_category === $catName ? 'selected' : '' ?>>
                    <?= View::e($catName) ?>
                  </option>
                <?php endforeach; ?>
              </select>
              <noscript><button class="fl-btn" type="submit">Go</button></noscript>
            </form>
          <?php endif; ?>
        </div>
      <?php }; $_selector(); ?>
    <?php endif; ?>

    <?php if ($_selectorWithAll): ?>
      <?php /* show-all-by-default: grouped listings below the selector */ ?>
      <?php if (empty($grouped)): ?>
        <p class="fl-empty">No listings to display.</p>
      <?php else: ?>
        <?php foreach ($grouped as $catName => $items): ?>
          <h3 class="fl-category-heading"><?= View::e($catName) ?></h3>
          <ul class="fl-fanlisting-list">
            <?php foreach ($items as $fl): ?>
              <li class="fl-fanlisting"><?php $_renderItem($fl); ?></li>
            <?php endforeach; ?>
          </ul>
        <?php endforeach; ?>
      <?php endif; ?>
    <?php elseif ($_hasCategory): ?>
      <?php if (empty($listings)): ?>
        <p class="fl-empty">No listings in this category.</p>
      <?php else: ?>
        <ul class="fl-fanlisting-list">
          <?php foreach ($listings as $fl): ?>
            <li class="fl-fanlisting"><?php $_renderItem($fl); ?></li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    <?php elseif (!$_showSelector): ?>
      <p class="fl-empty">No listings to display.</p>
    <?php endif; ?>

  <?php endif; ?>

</div>
