<?php

declare(strict_types=1);

use Luster\Core\View;

/**
 * Owned listings list — content fragment.
 *
 * Variables injected by embed/owned.php:
 *   $category_html       string      pre-rendered category navigator (from PublicCategoryPresenter)
 *   $show_listings       bool        false when the presenter is showing a browse-index (no rows)
 *   $listings            Listing[]   filtered list of owned listings
 *   $listing_html        string|null pre-rendered rows (null = use fallback loop)
 *   $sections_mode       bool        true when grouped Current/Upcoming/Pending output was built ($status='all')
 *   $categories          array       [{id, name, slug, source, parent_id, parent_name, listing_count}]
 *   $current_category    int         selected category ID (0 = all)
 *   $browse_show_all     bool        true when ?cat=all shows all listings in browse mode
 *   $total_count         int         total listing count across all categories
 *   $status              string      active status filter: current|upcoming|pending|all
 *   $form_action         string      URL the category filter form/links point to
 *   $uploads_url         string      base URL for listing images
 *   $base_url            string
 *   $theme_path          string
 */
?>
<div>

  <?= $category_html ?? '' ?>

  <?php if ((int) ($current_category ?? 0) > 0 && !empty($categories)):
    $_ownedCurrentCat = null;
    foreach ($categories as $_oc) {
        if ((int) $_oc['id'] === (int) $current_category) {
            $_ownedCurrentCat = $_oc;
            break;
        }
    }
    if ($_ownedCurrentCat !== null):
  ?>
    <form method="GET" action="<?= View::e($form_action) ?>" class="fl-category-nav fl-category-dropdown fl-joined-nav">
      <label for="fl-owned-cat"><b>Choose Category:</b></label>
      <select id="fl-owned-cat" name="cat">
        <option value="all">All Categories</option>
        <?php foreach ($categories as $_oc):
          $_ocVal = ($_ = $_oc['slug'] ?? '') !== '' ? $_ : (string) $_oc['id'];
        ?>
          <option value="<?= View::e($_ocVal) ?>"<?= (int) $_oc['id'] === (int) $current_category ? ' selected' : '' ?>><?= View::e($_oc['name']) ?> (<?= (int) $_oc['listing_count'] ?>)</option>
        <?php endforeach; ?>
      </select>
      <noscript><button type="submit">Go</button></noscript>
    </form>
    <h2 class="fl-cat-heading"><?= View::e($_ownedCurrentCat['name']) ?> (<?= count($listings) ?>)</h2>
  <?php endif; endif; ?>

  <?php if (($browse_show_all ?? false) && !empty($categories)):
    $_tcOwned = (int) ($total_count ?? count($listings));
  ?>
    <form method="GET" action="<?= View::e($form_action) ?>" class="fl-category-nav fl-category-dropdown fl-joined-nav">
      <label for="fl-owned-cat"><b>Choose Category:</b></label>
      <select id="fl-owned-cat" name="cat">
        <option value="all" selected>All Categories (<?= $_tcOwned ?>)</option>
        <?php foreach ($categories as $_oc):
          $_ocVal = ($_ = $_oc['slug'] ?? '') !== '' ? $_ : (string) $_oc['id'];
        ?>
          <option value="<?= View::e($_ocVal) ?>"><?= View::e($_oc['name']) ?> (<?= (int) $_oc['listing_count'] ?>)</option>
        <?php endforeach; ?>
      </select>
      <noscript><button type="submit">Go</button></noscript>
    </form>
    <h2 class="fl-cat-heading">All Categories (<?= $_tcOwned ?>)</h2>
  <?php endif; ?>

  <?php if ($show_listings ?? true): ?>

    <?php if (($sections_mode ?? false)): ?>
      <!-- Sections mode: grouped Current/Upcoming/Pending output is authoritative -->
      <?= $listing_html ?? '' ?>

    <?php elseif (($listing_html ?? null) !== null && $listing_html !== ''): ?>
      <!-- Custom DB template listing rows -->
      <ul class="fl-listing-cards">
        <?= $listing_html ?>
      </ul>

    <?php elseif (empty($listings)): ?>
      <p>No listings to display.</p>

    <?php else: ?>
      <ul>
        <?php foreach ($listings as $fl): ?>
          <li>
            <a href="<?= View::e($fl->url) ?>" target="_blank" rel="noopener noreferrer">
              <?= View::e($fl->title) ?>
            </a>
            <?php if ($fl->subject !== ''): ?>
              &mdash; <?= View::e($fl->subject) ?>
            <?php endif; ?>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>

  <?php endif; ?>

</div>
<?php if (!defined('FL_EMBED_JS_LOADED')): define('FL_EMBED_JS_LOADED', true); ?>
<script src="<?= View::e($base_url) ?>/assets/js/fl-embed.js"></script>
<?php endif; ?>
