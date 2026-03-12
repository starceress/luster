<?php

declare(strict_types=1);

use Luster\Core\View;

/**
 * Joined fanlistings list — content fragment.
 *
 * Variables injected by embed/joined.php:
 *   $fanlistings        JoinedFanlisting[]              flat list (single-category or selector mode)
 *   $grouped            array<string, JoinedFanlisting[]>  category-keyed list (show-all mode, sorted)
 *                       When non-empty, $fanlistings is unused.
 *   $categories         string[]            all available category names (for the selector)
 *   $current_category   string              '' = selector | 'all' = grouped | other = single cat
 *   $show_list          bool                show category selector as bulleted list (vs dropdown)
 *   $uploads_url        string              base URL for fanlisting images
 *   $form_action        string              URL the category filter form/links point to
 *   $base_url           string
 *   $theme_path         string
 */

$_isGroupedMode = !empty($grouped);
$_isSelectorMode = $current_category === '' && !$_isGroupedMode;

/** Renders one JoinedFanlisting item — shared by both display modes */
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

  <?php if ($_isSelectorMode && !empty($categories)): ?>
    <?php /* Selector mode: visitor must choose a category */ ?>
    <div class="fl-filter">
      <?php if ($show_list): ?>
        <ul class="fl-category-list">
          <?php foreach ($categories as $catName): ?>
            <li>
              <a class="fl-link"
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
            <option value="">Select a category&hellip;</option>
            <?php foreach ($categories as $catName): ?>
              <option value="<?= View::e($catName) ?>">
                <?= View::e($catName) ?>
              </option>
            <?php endforeach; ?>
          </select>
          <noscript><button class="fl-btn" type="submit">Go</button></noscript>
        </form>
      <?php endif; ?>
    </div>

  <?php elseif ($_isGroupedMode): ?>
    <?php /* Show-all mode: all active fanlistings grouped by category heading */ ?>
    <?php if (empty($grouped)): ?>
      <p class="fl-empty">No fanlistings to display.</p>
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
    <?php /* Single-category mode */ ?>
    <p class="fl-muted" style="font-size:.875rem">
      <?= View::e($current_category) ?>
      &mdash; <a class="fl-link" href="<?= View::e($form_action) ?>">All categories</a>
    </p>

    <?php if (empty($fanlistings)): ?>
      <p class="fl-empty">No fanlistings in this category.</p>
    <?php else: ?>
      <ul class="fl-fanlisting-list">
        <?php foreach ($fanlistings as $fl): ?>
          <li class="fl-fanlisting"><?php $_renderItem($fl); ?></li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  <?php endif; ?>

</div>
