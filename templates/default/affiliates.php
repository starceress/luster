<?php
/**
 * Affiliates list — content fragment.
 *
 * Variables injected by AffiliatesController:
 *   $listing_name  string   listing name (or site name for collective)
 *   $listing_url   string
 *   $affiliates       Affiliate[]  active affiliates for this page
 *   $total            int      total active affiliate count
 *   $pagination       string   pre-built pagination HTML, or ''
 *   $is_collective    bool     true when showing site-wide collective affiliates
 *   $join_url         string   URL of the join form (empty for collective view)
 *   $uploads_url      string   base URL for affiliate images (e.g. /luster/public/uploads)
 *   $base_url         string
 *   $section_html     string|null  pre-rendered section from page-level template (null = use default output)
 */

declare(strict_types=1);

use Luster\Core\View;
?>
<div class="fl-embed fl-embed--affiliates">

  <?php if (($section_html ?? null) !== null): ?>
    <?= $section_html ?>
    <?php if (($pagination ?? '') !== ''): ?>
      <div class="fl-pagination-wrap"><?= $pagination ?></div>
    <?php endif; ?>
  <?php elseif (empty($affiliates)): ?>
    <p class="fl-empty">No affiliates to display yet.</p>
  <?php else: ?>
    <p class="fl-hint">
      <?= (int) $total ?> affiliate<?= $total !== 1 ? 's' : '' ?>
    </p>

    <ul class="fl-affiliate-list">
      <?php foreach ($affiliates as $affiliate): ?>
        <li class="fl-affiliate-item">
          <a href="<?= View::e($affiliate->url) ?>"
             target="_blank" rel="noopener noreferrer">
            <?php if ($affiliate->image !== null && $affiliate->image !== ''): ?>
              <img class="fl-affiliate-image"
                   src="<?= View::e($uploads_url . '/' . $affiliate->image) ?>"
                   alt="<?= View::e($affiliate->name) ?>">
            <?php else: ?>
              <span class="fl-affiliate-name"><?= View::e($affiliate->name) ?></span>
            <?php endif; ?>
          </a>
        </li>
      <?php endforeach; ?>
    </ul>

    <?php if ($pagination !== ''): ?>
      <div class="fl-pagination-wrap"><?= $pagination ?></div>
    <?php endif; ?>
  <?php endif; ?>
</div>
