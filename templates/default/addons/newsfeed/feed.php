<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Newsfeed public feed content fragment (Newsfeed add-on). Displays
 per-listing or collective newsfeed entries with title, date, author,
 category tags, body text, and pagination. Rendered via
 View::renderFragment() from embed/newsfeed.php or
 embed/collective-newsfeed.php. Supports custom
 newsfeed templates.
*/

declare(strict_types=1);

use Luster\Core\View;

$_activeCat   = $active_cat ?? '';

// When a custom newsfeed_template has been rendered by the embed/controller, use it directly.
// Guard with !empty($updates) so the hardcoded empty state is shown for zero-result pages
// even when a custom template is configured (the template loop produces no rows, not a message).
if (isset($newsfeed_template_html) && $newsfeed_template_html !== null) {
    echo '<div class="fl-embed fl-embed--newsfeed">';
    echo $newsfeed_template_html . ($pagination ?? '');
    echo '</div>';
    return;
}
?>
<div class="fl-embed fl-embed--newsfeed">
<?php if (empty($updates)): ?>
<p class="fl-empty">No entries yet.</p>
<?php else: ?>

<div class="fl-newsfeed-list">
<?php foreach ($updates as $u): ?>
<div class="fl-newsfeed-item">
  <h3 class="fl-newsfeed-title"><?= View::e($u->title) ?></h3>
  <div class="fl-newsfeed-meta">
    <span class="fl-newsfeed-date"><b>Date Posted:</b> <time datetime="<?= View::e(substr($u->postedAt, 0, 10)) ?>"><?= View::e(date('F j, Y', strtotime($u->postedAt))) ?></time></span>
    <?php if ($owner_name !== ''): ?> by <span class="fl-newsfeed-author"><?= View::e($owner_name) ?></span><?php endif; ?><br>
    <?php if ($show_cats && !empty($u->categories())): ?>
      <b>Categories:</b> <span class="fl-newsfeed-categories">
        <?php foreach ($u->categories() as $_ci => $_cat): ?>
          <?php if ($_ci > 0): ?>, <?php endif; ?>
          <a class="fl-newsfeed-category-tag" href="<?= htmlspecialchars($feed_url . '?cat=' . rawurlencode($_cat), ENT_QUOTES, 'UTF-8') ?>"><?= View::e($_cat) ?></a>
        <?php endforeach; ?>
      </span>
    <?php endif; ?>
  </div>

  <div class="fl-newsfeed-body"><?= nl2br(View::e($u->body)) ?></div>
</div>
<?php endforeach; ?>
</div><!-- /.fl-newsfeed-list -->

<?php if (($pagination ?? '') !== ''): ?>
  <div class="fl-pagination-wrap"><?= $pagination ?></div>
<?php endif; ?>

<?php endif; ?>
</div>
