<?php

declare(strict_types=1);

use Luster\Core\View;

/**
 * Joined listings list — content fragment.
 *
 * Variables injected by embed/joined.php:
 *   $category_html       string      pre-rendered category navigator (from PublicCategoryPresenter)
 *   $show_listings       bool        false when browse-index is rendered (no listing rows)
 *   $listings            JoinedListing[]              flat list (single-category or selector mode)
 *   $grouped             array<string, JoinedListing[]>  category-keyed list (show-all mode)
 *   $listing_html        string|null  pre-rendered rows via admin HTML template (null = fallback)
 *   $sections_mode       bool         true when joined_sections_template produced the output
 *   $categories          string[]     all available category names
 *   $category_counts     array<string, int>
 *   $total_count         int
 *   $current_category    string       '' | 'all' | exact name
 *   $show_list           bool
 *   $browse_by_category  bool
 *   $uploads_url         string
 *   $form_action         string
 *   $base_url            string
 *   $theme_path          string
 */

/** Renders one JoinedListing item */
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

  <?= $category_html ?? '' ?>

  <?php if ($current_category === 'all' && !empty($categories)): ?>
    <form method="GET" action="<?= View::e($form_action) ?>" class="fl-category-nav fl-category-dropdown fl-joined-nav">
      <label for="fl-joined-cat"><b>Choose Category:</b></label>
      <select id="fl-joined-cat" name="cat">
        <option value="all" selected>All Listings (<?= (int) $total_count ?>)</option>
        <?php foreach ($categories as $_catName): ?>
          <option value="<?= View::e($_catName) ?>"><?= View::e($_catName) ?> (<?= (int) ($category_counts[$_catName] ?? 0) ?>)</option>
        <?php endforeach; ?>
      </select>
      <noscript><button type="submit">Go</button></noscript>
    </form>
    <h2 class="fl-cat-heading">All Listings (<?= (int) $total_count ?>)</h2>
  <?php endif; ?>

  <?php if (($browse_by_category ?? false) && $current_category !== '' && $current_category !== 'all' && !empty($categories)): ?>
    <form method="GET" action="<?= View::e($form_action) ?>" class="fl-category-nav fl-category-dropdown fl-joined-nav">
      <label for="fl-joined-cat"><b>Choose Category:</b></label>
      <select id="fl-joined-cat" name="cat">
        <option value="all">All Listings (<?= (int) $total_count ?>)</option>
        <?php foreach ($categories as $_catName): ?>
          <option value="<?= View::e($_catName) ?>"<?= $_catName === $current_category ? ' selected' : '' ?>><?= View::e($_catName) ?> (<?= (int) ($category_counts[$_catName] ?? 0) ?>)</option>
        <?php endforeach; ?>
      </select>
      <noscript><button type="submit">Go</button></noscript>
    </form>
    <h2 class="fl-cat-heading"><?= View::e($current_category) ?> (<?= (int) ($category_counts[$current_category] ?? 0) ?>)</h2>
  <?php endif; ?>

  <?php if ($show_listings ?? true): ?>

    <?php if (($sections_mode ?? false)): ?>
      <!-- Sections mode: output is authoritative from the sections template -->
      <?= $listing_html ?? '' ?>

    <?php elseif (($listing_html ?? null) !== null && $listing_html !== ''): ?>
      <!-- Custom DB template listing rows -->
      <ul>
        <?= $listing_html ?>
      </ul>

    <?php elseif ($current_category === 'all' || (!empty($grouped) && $current_category === '')): ?>
      <?php /* show-all mode: grouped by category */ ?>
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

    <?php elseif ($current_category !== '' && $current_category !== 'all'): ?>
      <?php /* Single-category mode */ ?>
      <?php if (empty($listings)): ?>
        <p>No listings in this category.</p>
      <?php else: ?>
        <ul>
          <?php foreach ($listings as $fl): ?>
            <li><?php $_renderItem($fl); ?></li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>

    <?php else: ?>
      <p>No listings to display.</p>
    <?php endif; ?>

  <?php endif; ?>

</div>
<?php if (!defined('FL_EMBED_JS_LOADED')): define('FL_EMBED_JS_LOADED', true); ?>
<script src="<?= View::e($base_url) ?>/assets/js/fl-embed.js"></script>
<?php endif; ?>
