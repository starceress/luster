<?php

declare(strict_types=1);

/**
 * Updates add-on - update list (per-fanlisting or collective).
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

$addUrl = $is_collective
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

$latestDate = !empty($updates) ? date('M Y', strtotime($updates[0]->postedAt)) : null;
?>

<?php if ($success !== ''): ?>
  <div class="alert alert-success"><?= View::e($success) ?></div>
<?php endif; ?>
<?php if ($error !== ''): ?>
  <div class="alert alert-error"><?= View::e($error) ?></div>
<?php endif; ?>

<div class="page-topbar">
  <a href="<?= $b ?>/admin/updates" class="link">
    &larr; Back to Updates Feed Overview
  </a>
  <div class="topbar-actions">
    <a href="<?= View::e($addUrl) ?>" class="btn btn-primary btn-sm">
      <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
      Add Update
    </a>
  </div>
</div>

<?php if (empty($updates)): ?>

  <div class="card">
    <div class="empty">
      <svg class="empty-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M4 11a9 9 0 0 1 9 9"/><path d="M4 4a16 16 0 0 1 16 16"/><circle cx="5" cy="19" r="1"/></svg>
      <div style="font-weight:600;color:var(--text)">No updates posted yet</div>
      <div>Post your first update to let visitors know what&rsquo;s new.</div>
      <a href="<?= View::e($addUrl) ?>" class="btn btn-primary btn-sm" style="margin-top:.5rem">
        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
        Add Update
      </a>
    </div>
  </div>

<?php else: ?>

  <!-- Stats strip -->
  <div class="updates-stats-strip">
    <div class="ustat">
      <div class="ustat-val"><?= (int) $total ?></div>
      <div class="ustat-lbl">Total Updates</div>
    </div>
    <?php if ($latestDate !== null): ?>
    <div class="ustat">
      <div class="ustat-val" style="font-size:1.1rem;color:var(--accent)"><?= View::e($latestDate) ?></div>
      <div class="ustat-lbl">Latest Month</div>
    </div>
    <?php endif; ?>
    <?php if ($pages > 1): ?>
    <div class="ustat">
      <div class="ustat-val" style="font-size:1.1rem;color:var(--text-muted)"><?= (int) $page ?> / <?= (int) $pages ?></div>
      <div class="ustat-lbl">Page</div>
    </div>
    <?php endif; ?>
  </div>

  <!-- Timeline -->
  <div class="card">
    <div class="card-head">
      <div class="card-head-title">
        <?= $is_collective ? 'Collective Updates' : 'Updates &mdash; ' . View::e($listing->name) ?>
      </div>
      <span style="font-size:.72rem;color:var(--text-dim)"><?= (int) $total ?> update<?= $total === 1 ? '' : 's' ?></span>
    </div>
    <div class="updates-timeline">
      <?php foreach ($updates as $u): ?>
        <?php
          $dt    = strtotime($u->postedAt);
          $day   = date('j', $dt);
          $month = date('M', $dt);
          $year  = date('Y', $dt);
          $time  = date('g:i A', $dt);
        ?>
        <div class="update-card">
          <div class="update-date-col">
            <span class="ud-day"><?= $day ?></span>
            <span class="ud-month"><?= $month ?></span>
            <span class="ud-year"><?= $year ?></span>
            <span class="ud-time"><?= $time ?></span>
          </div>
          <div class="update-content-col">
            <div class="update-header">
              <span class="update-title"><?= View::e($u->title) ?></span>
              <div class="data-actions">
                <a href="<?= $b ?>/admin/updates/entry/<?= (int) $u->id ?>/edit"
                   class="btn btn-quaternary btn-sm">
                  <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                  Edit
                </a>
                <form method="POST"
                      action="<?= $b ?>/admin/updates/entry/<?= (int) $u->id ?>/delete"
                      style="display:inline"
                      data-confirm="<?= $confirmMsg($u->title) ?>">
                  <input type="hidden" name="_csrf" value="<?= View::e($csrf_token) ?>">
                  <button type="submit" class="btn btn-danger btn-sm">
                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6M14 11v6"/><path d="M9 6V4h6v2"/></svg>
                    Delete
                  </button>
                </form>
              </div>
            </div>
            <?php if (!empty($u->categories())): ?>
              <div class="update-cats">
                <?php foreach ($u->categories() as $cat): ?>
                  <span class="update-cat-chip"><?= View::e($cat) ?></span>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
            <div class="update-body"><?= nl2br(View::e($u->body)) ?></div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>

  <?php if ($pages > 1): ?>
  <nav class="pg" aria-label="Pagination">
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
