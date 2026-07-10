<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Fan codes list content fragment. Displays approved graphic codes grouped by
 size, with donor attribution and category tags. Supports a custom codes display
 template; when one is set, the pre-rendered HTML is output directly. Served as
 a embeddable fragment via
 View::renderFragment() from embed/codes.php.
*/

declare(strict_types=1);

use Luster\Core\View;

// When a custom codes_template has been applied in the embed, use it directly.
if (isset($codes_template_html) && $codes_template_html !== null) {
    echo '<div class="fl-embed fl-embed--codes">';
    echo $codes_template_html;
    if (($pagination ?? '') !== '') {
        echo '<div class="fl-pagination-wrap">' . $pagination . '</div>';
    }
    echo '</div>';
    if (!defined('FL_EMBED_JS_LOADED')) {
        define('FL_EMBED_JS_LOADED', true);
        echo '<script src="' . htmlspecialchars($base_url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '/assets/js/fl-embed.js"></script>';
    }
    return;
}

$_hasCategories = !empty($categories);
$_hasDonors     = !empty($donors);
$_showHeadings  = count($grouped) > 1;
?>
<div class="fl-embed fl-embed--codes">
<?php if (empty($codes)): ?>
<p class="fl-empty">No codes available yet.</p>
<?php if (($codes_submit_toggle ?? '') !== '' || ($codes_submit_form ?? '') !== ''): ?>
  <?= $codes_submit_toggle ?? '' ?>
  <?= $codes_submit_form ?? '' ?>
<?php endif; ?>
<?php else: ?>
<div class="fl-codes">
<?php foreach ($grouped as $_group): ?>
  <?php if (empty($_group['codes'])) continue; ?>

  <div class="fl-code-group">
    <?php if ($_showHeadings): ?>
      <h2 class="fl-code-group-label"><?= View::e($_group['label']) ?></h2>
    <?php endif; ?>

    <ul class="fl-code-list">
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
          : ($_sizeTitle !== '' ? $_sizeTitle : ($listing_title . ' code'));
      ?>
      <li class="fl-code-item">
        <figure class="fl-code-image">
          <img src="<?= View::e($uploads_url . '/' . $_code->filename) ?>"
               alt="<?= View::e($_altText) ?>"
               <?php if ($_sizeTitle !== '' && $_donorName === null): ?>title="<?= View::e($_sizeTitle) ?>"<?php endif; ?>
               <?php if ($_code->width > 0): ?>width="<?= (int) $_code->width ?>"<?php endif; ?>
               <?php if ($_code->height > 0): ?>height="<?= (int) $_code->height ?>"<?php endif; ?>
               loading="lazy">
        </figure>

        <?php if ($_donorName !== null): ?>
          <span class="fl-code-caption">by <?php if ($_donorUrl !== null): ?><a href="<?= View::e($_donorUrl) ?>" target="_blank" rel="noopener noreferrer"><?= View::e($_donorName) ?></a><?php else: ?><?= View::e($_donorName) ?><?php endif; ?></span>
        <?php endif; ?>

        <?php if (($_code->name ?? '') !== ''): ?>
          <span class="fl-code-caption"><?= View::e((string) $_code->name) ?></span>
        <?php endif; ?>

        <?php
        $_codeCatIds = $cat_map[$_code->id] ?? [];
        if ($_hasCategories && !empty($_codeCatIds)):
        ?>
          <span class="fl-code-caption">
            <?php foreach ($_codeCatIds as $_catId): ?>
              <?php if (isset($category_map[$_catId])): ?>
                <span><?= View::e($category_map[$_catId]->name) ?></span>
              <?php endif; ?>
            <?php endforeach; ?>
          </span>
        <?php endif; ?>
      </li>
    <?php endforeach; ?>
    </ul>
  </div><!-- /.fl-code-group -->
<?php endforeach; ?>
</div><!-- /.fl-codes -->

<?php if (($pagination ?? '') !== ''): ?>
  <div class="fl-pagination-wrap"><?= $pagination ?></div>
<?php endif; ?>

<?php if (($codes_submit_toggle ?? '') !== '' || ($codes_submit_form ?? '') !== ''): ?>
  <?= $codes_submit_toggle ?? '' ?>
  <?= $codes_submit_form ?? '' ?>
<?php endif; ?>

<?php endif; ?>
</div>
<?php if (!defined('FL_EMBED_JS_LOADED')): define('FL_EMBED_JS_LOADED', true); ?>
<script src="<?= View::e($base_url) ?>/assets/js/fl-embed.js"></script>
<?php endif; ?>
