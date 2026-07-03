<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Joined listings content fragment. Displays the collective's joined fanlistings
 with category navigation (selector, browse-by-category, or grouped-all modes),
 image thumbnails or text-link fallbacks, and status filtering. Supports custom
 joined-listing row templates. Rendered as an embeddable fragment via
 View::renderFragment() from embed/joined.php.
*/

declare(strict_types=1);

use Luster\Core\View;

/** Renders one JoinedListing item (plain link or image) */
$_renderItem = static function ($fl) use ($uploads_url): void {
    if ($fl->image !== null && $fl->image !== '') {
        echo '<a href="' . View::e($fl->url) . '" target="_blank" rel="noopener noreferrer">';
        echo '<img class="fl-joined-image" src="' . View::e($uploads_url . '/' . $fl->image) . '" alt="' . View::e($fl->name) . '">';
        echo '</a>';
    } else {
        echo '<a class="fl-joined-title" href="' . View::e($fl->url) . '" target="_blank" rel="noopener noreferrer">';
        echo View::e($fl->name);
        echo '</a>';
    }
};
?>
<div class="fl-embed fl-embed--joined">
<?= $category_html ?? '' ?>

<?php if (($view ?? '') === 'pending'): ?>
  <h2 class="fl-cat-heading">Pending Listings (<?= count($listings) ?>)</h2>
<?php endif; ?>

<?php if ($current_category === 'all' && !empty($categories)): ?>
  <form method="GET" action="<?= View::e($form_action) ?>" class="fl-category-nav fl-category-dropdown">
    <label class="fl-label" for="fl-joined-cat"><b>Choose Category:</b></label>
    <select class="fl-select" id="fl-joined-cat" name="cat">
      <option value="all" selected>All Listings (<?= (int) $total_count ?>)</option>
      <?php foreach ($categories as $_catName): ?>
        <option value="<?= View::e($_catName) ?>"><?= View::e($_catName) ?> (<?= (int) ($category_counts[$_catName] ?? 0) ?>)</option>
      <?php endforeach; ?>
    </select>
    <noscript><button type="submit" class="fl-btn">Go</button></noscript>
  </form>
  <h2 class="fl-cat-heading">All Listings (<?= (int) $total_count ?>)</h2>
<?php endif; ?>

<?php if (($browse_by_category ?? false) && $current_category !== '' && $current_category !== 'all' && !empty($categories)): ?>
  <form method="GET" action="<?= View::e($form_action) ?>" class="fl-category-nav fl-category-dropdown">
    <label class="fl-label" for="fl-joined-cat"><b>Choose Category:</b></label>
    <select class="fl-select" id="fl-joined-cat" name="cat">
      <option value="all">All Listings (<?= (int) $total_count ?>)</option>
      <?php foreach ($categories as $_catName): ?>
        <option value="<?= View::e($_catName) ?>"<?= $_catName === $current_category ? ' selected' : '' ?>><?= View::e($_catName) ?> (<?= (int) ($category_counts[$_catName] ?? 0) ?>)</option>
      <?php endforeach; ?>
    </select>
    <noscript><button type="submit" class="fl-btn">Go</button></noscript>
  </form>
  <h2 class="fl-cat-heading"><?= View::e($current_category) ?> (<?= (int) ($category_counts[$current_category] ?? 0) ?>)</h2>
<?php endif; ?>

<?php if ($show_listings ?? true): ?>

  <?php if (($view ?? '') === 'pending'): ?>
    <?php if (empty($listings)): ?>
      <p class="fl-empty">No pending listings to display.</p>
    <?php else: ?>
      <ul class="fl-joined-list">
        <?php foreach ($listings as $fl): ?>
          <li class="fl-joined-item"><?php $_renderItem($fl); ?></li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>

  <?php elseif (($listing_html ?? null) !== null && $listing_html !== ''): ?>
    <!-- joined_template output — fully self-contained, including its own wrapper markup -->
    <?= $listing_html ?>

  <?php elseif ($current_category === 'all' || (!empty($grouped) && $current_category === '')): ?>
    <?php /* show-all mode: grouped by category */ ?>
    <?php if (empty($grouped)): ?>
      <p class="fl-empty">No listings to display.</p>
    <?php else: ?>
      <?php foreach ($grouped as $catName => $items): ?>
        <h3 class="fl-cat-heading"><?= View::e($catName) ?></h3>
        <ul class="fl-joined-list">
          <?php foreach ($items as $fl): ?>
            <li class="fl-joined-item"><?php $_renderItem($fl); ?></li>
          <?php endforeach; ?>
        </ul>
      <?php endforeach; ?>
    <?php endif; ?>

  <?php elseif ($current_category !== '' && $current_category !== 'all'): ?>
    <?php /* Single-category mode */ ?>
    <?php if (empty($listings)): ?>
      <p class="fl-empty">No listings in this category.</p>
    <?php else: ?>
      <ul class="fl-joined-list">
        <?php foreach ($listings as $fl): ?>
          <li class="fl-joined-item"><?php $_renderItem($fl); ?></li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>

  <?php else: ?>
    <p class="fl-empty">No listings to display.</p>
  <?php endif; ?>

<?php endif; ?>
<?php if (!defined('FL_EMBED_JS_LOADED')): define('FL_EMBED_JS_LOADED', true); ?>
<script src="<?= View::e($base_url) ?>/assets/js/fl-embed.js"></script>
<?php endif; ?>
</div>
