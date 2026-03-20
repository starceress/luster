<?php

declare(strict_types=1);

/**
 * Updates add-on — update list (per-fanlisting or collective).
 *
 * @var \Luster\Addons\Updates\Update[] $updates
 * @var int                             $total
 * @var int                             $page
 * @var int                             $pages
 * @var bool                            $is_collective
 * @var \Luster\Models\Listing|null     $listing
 * @var string                          $success
 * @var string                          $error
 * @var string                          $base_url
 * @var string                          $csrf_token
 */

use Luster\Core\View;

$b = rtrim(View::e($base_url), '/');

$addUrl  = $is_collective
    ? $b . '/admin/updates/collective/add'
    : $b . '/admin/updates/' . (int) $listing->id . '/add';

$pageUrl = static function (int $p) use ($b, $is_collective, $listing): string {
    $base = $is_collective
        ? $b . '/admin/updates/collective'
        : $b . '/admin/updates/' . (int) $listing->id;
    return $base . '?page=' . $p;
};

$confirmMsg = static function (string $title): string {
    return 'Delete the update "' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '"? This cannot be undone.';
};
?>

<?php if ($success !== ''): ?>
  <div class="alert alert-success"><?= View::e($success) ?></div>
<?php endif; ?>
<?php if ($error !== ''): ?>
  <div class="alert alert-error"><?= View::e($error) ?></div>
<?php endif; ?>

<div class="neb-toolbar" style="margin-bottom:1.25rem">
  <p style="margin:0">
    <a href="<?= $b ?>/admin/updates" class="neb-link">&larr; Back to Overview</a>
  </p>
  <div class="neb-actions">
    <a href="<?= View::e($addUrl) ?>" class="btn btn-primary btn-sm">+ Add Update</a>
  </div>
</div>

<?php if (empty($updates)): ?>
  <div class="neb-card">
    <div class="neb-empty">
      <svg class="empty-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M19 20H5a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h10l6 6v8a2 2 0 0 1-2 2z"/><path d="M17 20v-8H7v8M7 4v4h8"/></svg>
      No updates posted yet.
      <a href="<?= View::e($addUrl) ?>" class="btn btn-primary btn-sm">+ Add Update</a>
    </div>
  </div>
<?php else: ?>

  <p class="result-count">
    Showing <?= count($updates) ?> of <?= (int) $total ?>
    update<?= $total === 1 ? '' : 's' ?>
    <?php if ($pages > 1): ?>&mdash; page <?= (int) $page ?> of <?= (int) $pages ?><?php endif; ?>
  </p>

  <div class="neb-card">
    <table class="data-table">
      <thead>
        <tr>
          <th>Title</th>
          <th>Categories</th>
          <th>Author</th>
          <th>Posted</th>
          <th class="act-col">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($updates as $u): ?>
        <tr>
          <td class="text-strong"><?= View::e($u->title) ?></td>
          <td>
            <?php if ($u->category !== ''): ?>
              <?php foreach ($u->categories() as $cat): ?>
                <span class="neb-badge badge-inactive"><?= View::e($cat) ?></span>
              <?php endforeach; ?>
            <?php else: ?>
              <span class="text-dim">—</span>
            <?php endif; ?>
          </td>
          <td class="text-muted"><?= View::e($u->author !== '' ? $u->author : '—') ?></td>
          <td class="text-muted" style="white-space:nowrap;font-size:.85rem">
            <?= View::e(substr($u->postedAt, 0, 10)) ?>
          </td>
          <td class="act-col">
            <a href="<?= $b ?>/admin/updates/entry/<?= (int) $u->id ?>/edit"
               class="btn btn-primary btn-sm">Edit</a>
            <form method="POST"
                  action="<?= $b ?>/admin/updates/entry/<?= (int) $u->id ?>/delete"
                  style="display:inline;margin-left:.25rem"
                  data-confirm="<?= $confirmMsg($u->title) ?>">
              <input type="hidden" name="_csrf" value="<?= View::e($csrf_token) ?>">
              <button type="submit" class="btn btn-danger btn-sm">Delete</button>
            </form>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <?php if ($pages > 1): ?>
  <nav class="neb-pagination" aria-label="Pagination">
    <a href="<?= View::e($pageUrl($page - 1)) ?>"
       class="pg-link<?= $page <= 1 ? ' disabled' : '' ?>"
       <?= $page <= 1 ? 'aria-disabled="true"' : '' ?>>&lsaquo; Prev</a>

    <?php
    $window = 2;
    $start  = max(1, $page - $window);
    $end    = min($pages, $page + $window);
    if ($start > 1): ?>
      <a href="<?= View::e($pageUrl(1)) ?>" class="pg-link">1</a>
      <?php if ($start > 2): ?><span class="pg-link disabled">&hellip;</span><?php endif; ?>
    <?php endif; ?>

    <?php for ($i = $start; $i <= $end; $i++): ?>
      <a href="<?= View::e($pageUrl($i)) ?>"
         class="pg-link<?= $i === $page ? ' active' : '' ?>"
         <?= $i === $page ? 'aria-current="page"' : '' ?>><?= $i ?></a>
    <?php endfor; ?>

    <?php if ($end < $pages): ?>
      <?php if ($end < $pages - 1): ?><span class="pg-link disabled">&hellip;</span><?php endif; ?>
      <a href="<?= View::e($pageUrl($pages)) ?>" class="pg-link"><?= $pages ?></a>
    <?php endif; ?>

    <a href="<?= View::e($pageUrl($page + 1)) ?>"
       class="pg-link<?= $page >= $pages ? ' disabled' : '' ?>"
       <?= $page >= $pages ? 'aria-disabled="true"' : '' ?>>Next &rsaquo;</a>
  </nav>
  <?php endif; ?>

<?php endif; ?>
