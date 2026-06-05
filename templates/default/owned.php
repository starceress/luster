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
 *   $sections_mode       bool        true when owned_sections_template produced the output
 *   $categories          array       [{id, name, slug, source, parent_id, parent_name, listing_count}]
 *   $current_category    int         selected category ID (0 = all)
 *   $status              string      active status filter: current|upcoming|pending|all
 *   $form_action         string      URL the category filter form/links point to
 *   $uploads_url         string      base URL for listing images
 *   $base_url            string
 *   $theme_path          string
 */
?>
<div>

  <?= $category_html ?? '' ?>

  <?php if ($show_listings ?? true): ?>

    <?php if (($sections_mode ?? false)): ?>
      <!-- Sections mode: output is authoritative from the sections template -->
      <?= $listing_html ?? '' ?>

    <?php elseif (($listing_html ?? null) !== null && $listing_html !== ''): ?>
      <!-- Custom DB template listing rows -->
      <ul>
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
