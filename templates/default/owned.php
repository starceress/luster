<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Owned listings content fragment. Displays the collective's owned fanlistings
 with category navigation, browse-by-category mode, and status filtering
 (current, upcoming, pending, all). Supports custom owned-listing row templates
 via ListingRowRenderer. Rendered as an embeddable fragment via
 View::renderFragment() from embed/owned.php.
*/

declare(strict_types=1);

use Luster\Core\View;
?>
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
  <?php if ($browse_by_category ?? false): ?>
  <form method="GET" action="<?= View::e($form_action) ?>" class="fl-category-nav fl-category-dropdown">
    <label class="fl-label" for="fl-owned-cat"><b>Choose Category:</b></label>
    <select class="fl-select" id="fl-owned-cat" name="cat">
      <option value="all">All Categories</option>
      <?php foreach ($categories as $_oc):
        $_ocVal = ($_ = $_oc['slug'] ?? '') !== '' ? $_ : (string) $_oc['id'];
      ?>
        <option value="<?= View::e($_ocVal) ?>"<?= (int) $_oc['id'] === (int) $current_category ? ' selected' : '' ?>><?= View::e($_oc['name']) ?> (<?= (int) $_oc['listing_count'] ?>)</option>
      <?php endforeach; ?>
    </select>
    <noscript><button type="submit" class="fl-btn">Go</button></noscript>
  </form>
  <?php endif; ?>
  <h2 class="fl-cat-heading"><?= View::e($_ownedCurrentCat['name']) ?> (<?= count($listings) ?>)</h2>
<?php endif; endif; ?>

<?php if (($view ?? '') === 'pending' || ($view ?? '') === 'upcoming'): ?>
  <h2 class="fl-cat-heading">
    <?= ($view ?? '') === 'pending' ? 'Pending Listings' : 'Upcoming Listings' ?>
    (<?= count($listings) ?>)
  </h2>
<?php endif; ?>

<?php if (($browse_show_all ?? false) && !empty($categories)):
  $_tcOwned = (int) ($total_count ?? count($listings));
?>
  <form method="GET" action="<?= View::e($form_action) ?>" class="fl-category-nav fl-category-dropdown">
    <label class="fl-label" for="fl-owned-cat"><b>Choose Category:</b></label>
    <select class="fl-select" id="fl-owned-cat" name="cat">
      <option value="all" selected>All Categories (<?= $_tcOwned ?>)</option>
      <?php foreach ($categories as $_oc):
        $_ocVal = ($_ = $_oc['slug'] ?? '') !== '' ? $_ : (string) $_oc['id'];
      ?>
        <option value="<?= View::e($_ocVal) ?>"><?= View::e($_oc['name']) ?> (<?= (int) $_oc['listing_count'] ?>)</option>
      <?php endforeach; ?>
    </select>
    <noscript><button type="submit" class="fl-btn">Go</button></noscript>
  </form>
  <h2 class="fl-cat-heading">All Categories (<?= $_tcOwned ?>)</h2>
<?php endif; ?>

<?php if ($show_listings ?? true): ?>

  <?php if (($listing_html ?? null) !== null && $listing_html !== '' && !empty($listings)): ?>
    <!-- owned_template output — fully self-contained, including its own wrapper markup -->
    <?= $listing_html ?>

  <?php elseif (empty($listings)): ?>
    <p class="fl-empty">No listings to display.</p>

  <?php else: ?>
    <ul class="fl-listing-list">
      <?php foreach ($listings as $fl): ?>
        <li class="fl-listing-item">
          <a class="fl-listing-title" href="<?= View::e($fl->url) ?>" target="_blank" rel="noopener noreferrer">
            <?= View::e($fl->title) ?>
          </a>
          <?php if ($fl->subject !== ''): ?>
            <span class="fl-listing-subject"><?= View::e($fl->subject) ?></span>
          <?php endif; ?>
        </li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>

<?php endif; ?>
<?php if (!defined('FL_EMBED_JS_LOADED')): define('FL_EMBED_JS_LOADED', true); ?>
<script src="<?= View::e($base_url) ?>/assets/js/fl-embed.js"></script>
<?php endif; ?>
