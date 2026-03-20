<?php

declare(strict_types=1);

use Luster\Core\View;

/**
 * Codes list — content fragment.
 *
 * Variables injected by embed/codes.php:
 *   $listing_name  string
 *   $listing_url   string       target URL for code links
 *   $codes         object[]     CodesRepo rows (filename, name, width, height, donorId)
 *   $grouped       array        ['WxH' => ['label' => string, 'codes' => object[]]]
 *   $categories    object[]     CodeCategoriesRepo rows
 *   $cat_map       array        [code_id => [cat_id, ...]] from getCategoryMapForListing()
 *   $category_map  array        [cat_id => category object]
 *   $donors        object[]     CodeDonorsRepo rows
 *   $donor_map     array        [donor_id => donor object]
 *   $uploads_url   string       base URL for /uploads/codes (no trailing slash)
 *   $join_url      string       URL of the join form (empty string if not applicable)
 *   $base_url      string
 */

$_totalCodes      = count($codes);
$_hasCategories   = !empty($categories);
$_hasDonors       = !empty($donors);
$_showHeadings    = count($grouped) > 1;
?>
<div class="fl-wrap">
  <h2 class="fl-title">Codes for <?= View::e($listing_name) ?></h2>

  <?php if ($join_url !== ''): ?>
    <p><a class="fl-link" href="<?= View::e($join_url) ?>">Join this listing</a></p>
  <?php endif; ?>

  <?php if (empty($codes)): ?>
    <p class="fl-empty">No codes available yet.</p>
  <?php else: ?>
    <p class="fl-muted" style="font-size:.875rem">
      <?= $_totalCodes ?> code<?= $_totalCodes !== 1 ? 's' : '' ?> available.
      Right-click an image to save, or link directly.
    </p>

    <?php foreach ($grouped as $_group): ?>
      <?php if (empty($_group['codes'])) continue; ?>

      <?php if ($_showHeadings): ?>
        <h3 class="fl-category-heading" style="margin:1.25em 0 .5em">
          <?= View::e($_group['label']) ?>
        </h3>
      <?php endif; ?>

      <ul class="fl-code-list">
        <?php foreach ($_group['codes'] as $_code): ?>
          <?php $_codeName = ($_code->name !== null && $_code->name !== '') ? $_code->name : null; ?>
          <li class="fl-code-item">
            <a class="fl-code-link"
               href="<?= View::e($listing_url !== '' ? $listing_url : '#') ?>"
               target="_blank" rel="noopener noreferrer">
              <img src="<?= View::e($uploads_url . '/' . $_code->filename) ?>"
                   alt="<?= View::e($_codeName ?? ($listing_name . ' code')) ?>"
                   <?php if ($_code->width > 0): ?>width="<?= (int) $_code->width ?>"<?php endif; ?>
                   <?php if ($_code->height > 0): ?>height="<?= (int) $_code->height ?>"<?php endif; ?>
                   loading="lazy">
            </a>
            <?php if ($_codeName !== null): ?>
              <p class="fl-code-name" style="margin:.2em 0 0;font-size:.75rem;text-align:center"><?= View::e($_codeName) ?></p>
            <?php endif; ?>

            <?php
            // Category badges for this code
            $_codeCatIds = $cat_map[$_code->id] ?? [];
            if ($_hasCategories && !empty($_codeCatIds)):
            ?>
              <p class="fl-code-cats" style="margin:.25em 0 0;font-size:.75rem">
                <?php foreach ($_codeCatIds as $_catId): ?>
                  <?php if (isset($category_map[$_catId])): ?>
                    <span class="fl-code-cat-badge"><?= View::e($category_map[$_catId]->name) ?></span>
                  <?php endif; ?>
                <?php endforeach; ?>
              </p>
            <?php endif; ?>

            <?php
            // Donor credit
            $_donor = ($_hasDonors && $_code->donorId !== null) ? ($donor_map[$_code->donorId] ?? null) : null;
            if ($_donor !== null):
            ?>
              <p class="fl-code-donor" style="margin:.2em 0 0;font-size:.75rem;opacity:.7">
                &#9829;
                <?php if ($_donor->url !== null && $_donor->url !== ''): ?>
                  <a class="fl-link" href="<?= View::e($_donor->url) ?>" target="_blank" rel="noopener noreferrer"><?= View::e($_donor->name) ?></a>
                <?php else: ?>
                  <?= View::e($_donor->name) ?>
                <?php endif; ?>
              </p>
            <?php endif; ?>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endforeach; ?>

  <?php endif; ?>
</div>
