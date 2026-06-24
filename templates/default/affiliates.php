<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Affiliates list content fragment. Displays approved affiliates for a listing
 or collective, with image buttons or text-link fallbacks and pagination. Served
 as an embeddable fragment via
 View::renderFragment() from embed/affiliates.php. Supports custom affiliates
 section templates.
*/

declare(strict_types=1);

use Luster\Core\View;
?>
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
