<?php
/**
 * Affiliates list — content fragment.
 *
 * Variables injected by AffiliatesController:
 *   $fanlisting_name  string   fanlisting name (or site name for collective)
 *   $fanlisting_url   string
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
<div class="fl-wrap">
  <h2 class="fl-title">
    <?php if ($is_collective): ?>
      Affiliates
    <?php else: ?>
      Affiliates of <?= View::e($fanlisting_name) ?>
    <?php endif; ?>
  </h2>

  <?php if (!$is_collective && $join_url !== ''): ?>
    <p><a class="fl-link" href="<?= View::e($join_url) ?>">Join this fanlisting</a></p>
  <?php endif; ?>

  <?php if (empty($affiliates)): ?>
    <p class="fl-empty">No affiliates to display yet.</p>
  <?php else: ?>
    <p class="fl-muted" style="font-size:.875rem">
      <?= (int) $total ?> affiliate<?= $total !== 1 ? 's' : '' ?>
    </p>

    <ul class="fl-affiliate-list">
      <?php foreach ($affiliates as $affiliate): ?>
        <li class="fl-affiliate">
          <a class="fl-affiliate-link" href="<?= View::e($affiliate->url) ?>"
             target="_blank" rel="noopener noreferrer">
            <?php if ($affiliate->image !== null && $affiliate->image !== ''): ?>
              <img class="fl-affiliate-img"
                   src="<?= View::e($uploads_url . '/' . $affiliate->image) ?>"
                   alt="<?= View::e($affiliate->name) ?>">
            <?php else: ?>
              <?= View::e($affiliate->name) ?>
            <?php endif; ?>
          </a>
        </li>
      <?php endforeach; ?>
    </ul>

    <?php if ($pagination !== ''): ?>
      <div class="fl-pagination"><?= $pagination ?></div>
    <?php endif; ?>
  <?php endif; ?>
</div>
