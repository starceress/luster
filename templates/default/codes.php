<?php

declare(strict_types=1);

use Luster\Core\View;

/**
 * Codes list — content fragment.
 *
 * Variables injected by embed/codes.php:
 *   $listing_name  string
 *   $listing_url   string       target URL for code links
 *   $codes         object[]     CodesRepo rows (all approved codes)
 *   $donated       object[]     Subset of codes with submitterName set (fan-submitted)
 *   $grouped       array        ['WxH' => ['label' => string, 'codes' => object[]]] — regular codes only
 *   $categories    object[]     CodeCategoriesRepo rows
 *   $cat_map       array        [code_id => [cat_id, ...]] from getCategoryMapForListing()
 *   $category_map  array        [cat_id => category object]
 *   $donors        object[]     CodeDonorsRepo rows
 *   $donor_map     array        [donor_id => donor object]
 *   $uploads_url   string       base URL for /uploads/codes (no trailing slash)
 *   $join_url      string       URL of the join form (empty string if not applicable)
 *   $base_url      string
 */

$_totalCodes    = count($codes);
$_hasCategories = !empty($categories);
$_hasDonors     = !empty($donors);
$_showHeadings  = count($grouped) > 1;
$_donated       = $donated ?? [];
?>
<div>
<?php if (empty($codes)): ?>
  <p>No codes available yet.</p>
<?php else: ?>

  <?php foreach ($grouped as $_group): ?>
    <?php if (empty($_group['codes'])) continue; ?>

    <?php if ($_showHeadings): ?>
      <h2>
        <?= View::e($_group['label']) ?>
      </h2>
    <?php endif; ?>

    <?php foreach ($_group['codes'] as $_code): ?>
      <?php
      $_codeName  = ($_code->name !== null && $_code->name !== '') ? $_code->name : null;
      $_sizeTitle = ($_code->width > 0 && $_code->height > 0)
          ? (int) $_code->width . 'x' . (int) $_code->height
          : '';
      $_donor    = ($_hasDonors && $_code->donorId !== null) ? ($donor_map[$_code->donorId] ?? null) : null;
      $_donorUrl = ($_donor !== null && $_donor->url !== null && $_donor->url !== '') ? $_donor->url : null;
      ?>
      <?php if ($_donorUrl !== null): ?>
        <a href="<?= View::e($_donorUrl) ?>" target="_blank" rel="noopener noreferrer">
          <img src="<?= View::e($uploads_url . '/' . $_code->filename) ?>"
               alt="<?= View::e($_sizeTitle !== '' ? $_sizeTitle : ($listing_name . ' code')) ?>"
               title="<?= View::e('Donated by ' . $_donor->name) ?>"
               <?php if ($_code->width > 0): ?>width="<?= (int) $_code->width ?>"<?php endif; ?>
               <?php if ($_code->height > 0): ?>height="<?= (int) $_code->height ?>"<?php endif; ?>
               loading="lazy">
        </a>
      <?php else: ?>
        <img src="<?= View::e($uploads_url . '/' . $_code->filename) ?>"
             alt="<?= View::e($_sizeTitle !== '' ? $_sizeTitle : ($listing_name . ' code')) ?>"
             <?php if ($_sizeTitle !== ''): ?>title="<?= View::e($_sizeTitle) ?>"<?php endif; ?>
             <?php if ($_code->width > 0): ?>width="<?= (int) $_code->width ?>"<?php endif; ?>
             <?php if ($_code->height > 0): ?>height="<?= (int) $_code->height ?>"<?php endif; ?>
             loading="lazy">
      <?php endif; ?>

      <?php if ($_codeName !== null): ?>
        <p><?= View::e($_codeName) ?></p>
      <?php endif; ?>

      <?php
      $_codeCatIds = $cat_map[$_code->id] ?? [];
      if ($_hasCategories && !empty($_codeCatIds)):
      ?>
        <p>
          <?php foreach ($_codeCatIds as $_catId): ?>
            <?php if (isset($category_map[$_catId])): ?>
              <span><?= View::e($category_map[$_catId]->name) ?></span>
            <?php endif; ?>
          <?php endforeach; ?>
        </p>
      <?php endif; ?>

      <?php if ($_donor !== null): ?>
        <p>
          &#9829;
          <?php if ($_donorUrl !== null): ?>
            <a href="<?= View::e($_donorUrl) ?>" target="_blank" rel="noopener noreferrer"><?= View::e($_donor->name) ?></a>
          <?php else: ?>
            <?= View::e($_donor->name) ?>
          <?php endif; ?>
        </p>
      <?php endif; ?>
    <?php endforeach; ?>
  <?php endforeach; ?>

  <?php if (!empty($_donated)): ?>
    <h2>Donated</h2>

    <?php foreach ($_donated as $_code): ?>
      <?php
      $_codeName  = ($_code->name !== null && $_code->name !== '') ? $_code->name : null;
      $_dnrObj    = ($_hasDonors && $_code->donorId !== null) ? ($donor_map[$_code->donorId] ?? null) : null;
      $_donorName = $_code->submitterName ?? ($_dnrObj !== null ? $_dnrObj->name : null);
      $_donorUrl  = null;
      if ($_code->submitterUrl !== null && $_code->submitterUrl !== '') {
          $_donorUrl = $_code->submitterUrl;
      } elseif ($_dnrObj !== null && $_dnrObj->url !== null && $_dnrObj->url !== '') {
          $_donorUrl = $_dnrObj->url;
      }
      $_hoverTitle = $_donorName !== null ? 'Donated by ' . $_donorName : 'Donated';
      ?>
      <?php if ($_donorUrl !== null): ?>
        <a href="<?= View::e($_donorUrl) ?>" target="_blank" rel="noopener noreferrer">
          <img src="<?= View::e($uploads_url . '/' . $_code->filename) ?>"
               alt="<?= View::e($_hoverTitle) ?>"
               title="<?= View::e($_hoverTitle) ?>"
               <?php if ($_code->width > 0): ?>width="<?= (int) $_code->width ?>"<?php endif; ?>
               <?php if ($_code->height > 0): ?>height="<?= (int) $_code->height ?>"<?php endif; ?>
               loading="lazy">
        </a>
      <?php else: ?>
        <img src="<?= View::e($uploads_url . '/' . $_code->filename) ?>"
             alt="<?= View::e($_hoverTitle) ?>"
             title="<?= View::e($_hoverTitle) ?>"
             <?php if ($_code->width > 0): ?>width="<?= (int) $_code->width ?>"<?php endif; ?>
             <?php if ($_code->height > 0): ?>height="<?= (int) $_code->height ?>"<?php endif; ?>
             loading="lazy">
      <?php endif; ?>

      <?php if ($_codeName !== null): ?>
        <p><?= View::e($_codeName) ?></p>
      <?php endif; ?>
    <?php endforeach; ?>
  <?php endif; ?>

<?php endif; ?>
</div>
