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
 * @var string                          $owner_name    site owner name (from config)
 * @var string                          $active_cat    currently filtered category ('' = none)
 * @var string                          $feed_url      base URL for pagination links
 * @var string                          $base_url
 * @var string                          $theme_path
 */

use Luster\Core\View;

$_activeCat = $active_cat ?? '';
$pageUrl = static function (int $p) use ($feed_url, $_activeCat): string {
    $qs = $_activeCat !== ''
        ? 'cat=' . rawurlencode($_activeCat) . '&page=' . $p
        : 'page=' . $p;
    return htmlspecialchars($feed_url . '?' . $qs, ENT_QUOTES, 'UTF-8');
};
?>
<div>
  <?php if ($_activeCat !== ''): ?>
    <p>Filtering by: <strong><?= View::e($_activeCat) ?></strong>
      &mdash; <a href="<?= htmlspecialchars($feed_url, ENT_QUOTES, 'UTF-8') ?>">Clear filter</a>
    </p>
  <?php endif; ?>

  <?php if (empty($updates)): ?>
    <p>No updates posted yet.</p>
  <?php else: ?>

    <div>
      <?php foreach ($updates as $u): ?>
      <article>
        <h3><?= View::e($u->title) ?></h3>
        <p>
          <time datetime="<?= View::e(substr($u->postedAt, 0, 10)) ?>">
            <?= View::e(date('F j, Y', strtotime($u->postedAt))) ?>
          </time>
          <?php $displayAuthor = ($owner_name ?? '') !== '' ? $owner_name : $u->author; ?>
          <?php if ($displayAuthor !== ''): ?>
            &mdash; <?= View::e($displayAuthor) ?>
          <?php endif; ?>
        </p>
        <?php if ($show_cats && !empty($u->categories())): ?>
          <p>Category:
            <?php foreach ($u->categories() as $_ci => $_cat): ?>
              <?php if ($_ci > 0): ?>, <?php endif; ?>
              <a href="<?= htmlspecialchars($feed_url . '?cat=' . rawurlencode($_cat), ENT_QUOTES, 'UTF-8') ?>"><?= View::e($_cat) ?></a>
            <?php endforeach; ?>
          </p>
        <?php endif; ?>
        <div>
          <?= nl2br(View::e($u->body)) ?>
        </div>
      </article>
      <?php endforeach; ?>
    </div>

    <?php if ($pages > 1): ?>
    <nav aria-label="Updates pagination">
      <?php if ($page > 1): ?>
        <a href="<?= $pageUrl($page - 1) ?>">&lsaquo; Prev</a>
      <?php endif; ?>

      <?php for ($p = 1; $p <= $pages; $p++): ?>
        <?php if ($p === $page): ?>
          <span aria-current="page"><?= $p ?></span>
        <?php else: ?>
          <a href="<?= $pageUrl($p) ?>"><?= $p ?></a>
        <?php endif; ?>
      <?php endfor; ?>

      <?php if ($page < $pages): ?>
        <a href="<?= $pageUrl($page + 1) ?>">Next &rsaquo;</a>
      <?php endif; ?>
    </nav>
    <?php endif; ?>

  <?php endif; ?>
</div>
