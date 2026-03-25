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
 */

declare(strict_types=1);

use Luster\Core\View;
?>
<div>

  <?php if (empty($affiliates)): ?>
    <p>No affiliates to display yet.</p>
  <?php elseif (($affiliates_html ?? null) !== null && $affiliates_html !== ''): ?>
    <!-- Custom DB template affiliate rows -->
    <p>
      <?= (int) $total ?> affiliate<?= $total !== 1 ? 's' : '' ?>
    </p>
    <ul>
      <?= $affiliates_html ?>
    </ul>
    <?php if ($pagination !== ''): ?>
      <div><?= $pagination ?></div>
    <?php endif; ?>
  <?php else: ?>
    <p>
      <?= (int) $total ?> affiliate<?= $total !== 1 ? 's' : '' ?>
    </p>

    <?php foreach ($affiliates as $affiliate): ?>
        <a href="<?= View::e($affiliate->url) ?>"
            target="_blank" rel="noopener noreferrer">
          <?php if ($affiliate->image !== null && $affiliate->image !== ''): ?>
            <img src="<?= View::e($uploads_url . '/' . $affiliate->image) ?>"
                  alt="<?= View::e($affiliate->name) ?>">
          <?php else: ?>
            <?= View::e($affiliate->name) ?>
          <?php endif; ?>
        </a>
    <?php endforeach; ?>

    <?php if ($pagination !== ''): ?>
      <div><?= $pagination ?></div>
    <?php endif; ?>
  <?php endif; ?>
</div>
