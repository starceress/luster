<?php

declare(strict_types=1);

/**
 * Updates add-on — public feed fragment.
 *
 * Used by both the full-page public controller (via renderPublic) and embed
 * files (via renderFragment). The layout wrapper is added by renderPublic only.
 *
 * @var \Luster\Addons\Updates\Update[] $updates
 * @var int                             $total
 * @var int                             $page
 * @var int                             $pages
 * @var bool                            $is_collective
 * @var bool                            $show_cats
 * @var string                          $feed_url      base URL for pagination links
 * @var string                          $base_url
 * @var string                          $theme_path
 */

use Luster\Core\View;

$pageUrl = static function (int $p) use ($feed_url): string {
    return htmlspecialchars($feed_url . '?page=' . $p, ENT_QUOTES, 'UTF-8');
};
?>

<?php if (empty($updates)): ?>
  <p class="fl-empty">No updates posted yet.</p>
<?php else: ?>

  <div class="fl-updates">
    <?php foreach ($updates as $u): ?>
    <article class="fl-update">
      <header class="fl-update-header">
        <h2 class="fl-update-title"><?= View::e($u->title) ?></h2>
        <div class="fl-update-meta">
          <time datetime="<?= View::e(substr($u->postedAt, 0, 10)) ?>">
            <?= View::e(date('F j, Y', strtotime($u->postedAt))) ?>
          </time>
          <?php if ($u->author !== ''): ?>
            <span class="fl-update-author"> &mdash; <?= View::e($u->author) ?></span>
          <?php endif; ?>
        </div>
        <?php if ($show_cats && !empty($u->categories())): ?>
          <div class="fl-update-cats">
            <?php foreach ($u->categories() as $cat): ?>
              <span class="fl-tag"><?= View::e($cat) ?></span>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </header>
      <div class="fl-update-body">
        <?= nl2br(View::e($u->body)) ?>
      </div>
    </article>
    <?php endforeach; ?>
  </div>

  <?php if ($pages > 1): ?>
  <nav class="fl-pagination" aria-label="Updates pagination">
    <?php if ($page > 1): ?>
      <a href="<?= $pageUrl($page - 1) ?>" class="fl-page-link">&lsaquo; Prev</a>
    <?php endif; ?>

    <?php for ($p = 1; $p <= $pages; $p++): ?>
      <?php if ($p === $page): ?>
        <span class="fl-page-link fl-page-current" aria-current="page"><?= $p ?></span>
      <?php else: ?>
        <a href="<?= $pageUrl($p) ?>" class="fl-page-link"><?= $p ?></a>
      <?php endif; ?>
    <?php endfor; ?>

    <?php if ($page < $pages): ?>
      <a href="<?= $pageUrl($page + 1) ?>" class="fl-page-link">Next &rsaquo;</a>
    <?php endif; ?>
  </nav>
  <?php endif; ?>

<?php endif; ?>
