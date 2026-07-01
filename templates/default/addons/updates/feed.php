<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 News Feed public feed content fragment (News Feed add-on). Displays
 per-listing or collective news/update entries with title, date, author,
 category tags, body text, and pagination. Rendered via
 View::renderFragment() from embed/updates.php or
 embed/collective-updates.php. Supports custom
 updates templates.
*/

declare(strict_types=1);

use Luster\Core\View;

$_activeCat   = $active_cat ?? '';

// When a custom updates_template has been rendered by the embed/controller, use it directly.
// Guard with !empty($updates) so the hardcoded empty state is shown for zero-result pages
// even when a custom template is configured (the template loop produces no rows, not a message).
if (isset($updates_template_html) && $updates_template_html !== null) {
    echo '<div class="fl-embed fl-embed--updates">';
    echo $updates_template_html . ($pagination ?? '');
    echo '</div>';
    return;
}
?>
<div class="fl-embed fl-embed--updates">
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
