<?php

declare(strict_types=1);

/**
 * Updates add-on — public feed fragment.
 *
 * Used by both the full-page public controller (via renderPublic) and embed
 * files (via renderFragment). The layout wrapper is added by renderPublic only.
 *
 * @var \Luster\Addons\Updates\Update[] $updates
 * @var bool                            $is_collective
 * @var bool                            $show_cats
 * @var string                          $owner_name
 * @var string                          $active_cat    currently filtered category ('' = none)
 * @var string                          $feed_url      base URL for category filter links
 * @var string                          $pagination    pre-rendered pagination HTML ('' = none)
 * @var string                          $base_url
 * @var string                          $theme_path
 */

use Luster\Core\View;

$_activeCat   = $active_cat ?? '';
$_embedClass  = ($is_collective ?? false) ? 'fl-embed--collective-updates' : 'fl-embed--updates';

// When a custom updates_template has been rendered by the embed/controller, use it directly.
if (isset($updates_template_html) && $updates_template_html !== null) {
    echo '<div class="fl-embed ' . $_embedClass . '">' . $updates_template_html . ($pagination ?? '') . '</div>';
    return;
}
?>
<div class="fl-embed <?= $_embedClass ?>">
<?php if (empty($updates)): ?>
  <p class="fl-empty">No updates posted yet.</p>
<?php else: ?>

  <div class="fl-updates">
  <?php foreach ($updates as $u): ?>
  <div class="fl-update-item">
    <div class="fl-update-header">
      <h2 class="fl-update-title"><?= View::e($u->title) ?></h2>
      <p class="fl-update-date">
        <time datetime="<?= View::e(substr($u->postedAt, 0, 10)) ?>"><?= View::e(date('F j, Y', strtotime($u->postedAt))) ?></time>
        <?php if ($owner_name !== ''): ?>
          &mdash; <?= View::e($owner_name) ?>
        <?php endif; ?>
      </p>
    </div>
    <?php if ($show_cats && !empty($u->categories())): ?>
      <p class="fl-update-meta">
        <span class="fl-update-categories">Category:
          <?php foreach ($u->categories() as $_ci => $_cat): ?>
            <?php if ($_ci > 0): ?>, <?php endif; ?>
            <a class="fl-update-category-tag" href="<?= htmlspecialchars($feed_url . '?cat=' . rawurlencode($_cat), ENT_QUOTES, 'UTF-8') ?>"><?= View::e($_cat) ?></a>
          <?php endforeach; ?>
        </span>
      </p>
    <?php endif; ?>
    <div class="fl-update-body"><?= nl2br(View::e($u->body)) ?></div>
  </div>
  <?php endforeach; ?>
  </div><!-- /.fl-updates -->

  <?php if (($pagination ?? '') !== ''): ?>
    <div class="fl-pagination-wrap"><?= $pagination ?></div>
  <?php endif; ?>

<?php endif; ?>
</div>
