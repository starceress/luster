<?php

declare(strict_types=1);

use Luster\Core\View;

/**
 * Codes list — content fragment.
 *
 * Variables injected by embed/codes.php:
 *   $listing_name  string
 *   $listing_url   string
 *   $codes         object[]     all approved codes
 *   $grouped       array        ['WxH' => ['label' => string, 'codes' => object[]]]
 *   $categories    object[]
 *   $cat_map       array        [code_id => [cat_id, ...]]
 *   $category_map  array        [cat_id => category object]
 *   $donors        object[]
 *   $donor_map     array        [donor_id => donor object]
 *   $uploads_url   string
 *   $join_url      string
 *   $base_url      string
 */

// When a custom codes_template has been applied in the embed, use it directly.
if (isset($codes_template_html) && $codes_template_html !== null) {
    echo $codes_template_html;
    return;
}

$_hasCategories = !empty($categories);
$_hasDonors     = !empty($donors);
$_showHeadings  = count($grouped) > 1;
?>
<div>
<?php if (empty($codes)): ?>
  <p>No codes available yet.</p>
<?php else: ?>

  <?php foreach ($grouped as $_group): ?>
    <?php if (empty($_group['codes'])) continue; ?>

    <?php if ($_showHeadings): ?>
      <h2 class="fl-code-size-heading"><?= View::e($_group['label']) ?></h2>
    <?php endif; ?>

    <div class="fl-codes-group">
    <?php foreach ($_group['codes'] as $_code): ?>
      <?php
      $_sizeTitle = ($_code->width > 0 && $_code->height > 0)
          ? (int) $_code->width . 'x' . (int) $_code->height
          : '';

      // Resolve donor attribution
      $_dnrObj    = ($_hasDonors && $_code->donorId !== null) ? ($donor_map[$_code->donorId] ?? null) : null;
      $_donorName = $_code->submitterName ?? ($_dnrObj !== null ? $_dnrObj->name : null);
      $_donorUrl  = null;
      if ($_code->submitterUrl !== null && $_code->submitterUrl !== '') {
          $_donorUrl = $_code->submitterUrl;
      } elseif ($_dnrObj !== null && $_dnrObj->url !== null && $_dnrObj->url !== '') {
          $_donorUrl = $_dnrObj->url;
      }

      $_altText = $_donorName !== null
          ? 'Donated by ' . $_donorName
          : ($_sizeTitle !== '' ? $_sizeTitle : ($listing_name . ' code'));
      ?>
      <figure class="fl-code">
        <img src="<?= View::e($uploads_url . '/' . $_code->filename) ?>"
             alt="<?= View::e($_altText) ?>"
             <?php if ($_sizeTitle !== '' && $_donorName === null): ?>title="<?= View::e($_sizeTitle) ?>"<?php endif; ?>
             <?php if ($_code->width > 0): ?>width="<?= (int) $_code->width ?>"<?php endif; ?>
             <?php if ($_code->height > 0): ?>height="<?= (int) $_code->height ?>"<?php endif; ?>
             loading="lazy">

        <?php if ($_donorName !== null): ?>
          <figcaption>by <?php if ($_donorUrl !== null): ?><a href="<?= View::e($_donorUrl) ?>" target="_blank" rel="noopener noreferrer"><?= View::e($_donorName) ?></a><?php else: ?><?= View::e($_donorName) ?><?php endif; ?></figcaption>
        <?php endif; ?>

        <?php if (($_code->name ?? '') !== ''): ?>
          <figcaption><?= View::e((string) $_code->name) ?></figcaption>
        <?php endif; ?>

        <?php
        $_codeCatIds = $cat_map[$_code->id] ?? [];
        if ($_hasCategories && !empty($_codeCatIds)):
        ?>
          <figcaption>
            <?php foreach ($_codeCatIds as $_catId): ?>
              <?php if (isset($category_map[$_catId])): ?>
                <span><?= View::e($category_map[$_catId]->name) ?></span>
              <?php endif; ?>
            <?php endforeach; ?>
          </figcaption>
        <?php endif; ?>
      </figure>
    <?php endforeach; ?>
    </div><!-- /.fl-codes-group -->
  <?php endforeach; ?>

<?php endif; ?>
</div>
