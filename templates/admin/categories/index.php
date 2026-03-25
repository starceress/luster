<?php declare(strict_types=1);
/** @var array  $items */
/** @var int    $total */
/** @var int    $page */
/** @var int    $total_pages */
/** @var string $source */
/** @var string $search */
/** @var array  $custom_sources */
/** @var string $success */
/** @var string $error */
/** @var string $base_url */
/** @var string $csrf_token */
$b = rtrim(\Luster\Core\View::e($base_url ?? ''), '/');

$sourceBadge = static function (string $s): string {
    return match ($s) {
        'tfl'   => '<span class="neb-badge badge-tfl">TFL</span>',
        'tafl'  => '<span class="neb-badge badge-tafl">TAFL</span>',
        default => '<span class="neb-badge badge-inactive">' . htmlspecialchars(ucfirst($s), ENT_QUOTES, 'UTF-8') . '</span>',
    };
};

$sort = $sort ?? 'source';
$dir  = $dir  ?? 'asc';

$filtersActive = ($source ?? '') !== '' || ($search ?? '') !== '';

$catFilterQs = http_build_query(array_filter([
    'source' => $source ?? '',
    'search' => $search ?? '',
    'sort'   => $sort !== 'source' ? $sort : '',
    'dir'    => $dir  !== 'asc'   ? $dir  : '',
], static fn(string $v): bool => $v !== ''));
$catPageUrl = static function (int $p) use ($b, $catFilterQs): string {
    $qs = $catFilterQs !== '' ? $catFilterQs . '&page=' . $p : 'page=' . $p;
    return $b . '/admin/categories?' . $qs;
};

$catSortUrl = static function (string $col) use ($b, $sort, $dir, $source, $search): string {
    $newDir = ($sort === $col && $dir === 'asc') ? 'desc' : 'asc';
    $qs = http_build_query(array_filter([
        'source' => $source ?? '',
        'search' => $search ?? '',
        'sort'   => $col,
        'dir'    => $newDir,
    ], static fn(string $v): bool => $v !== ''));
    return $b . '/admin/categories?' . $qs;
};

$catSortIcon = static function (string $col) use ($sort, $dir): string {
    if ($sort !== $col) {
        return '<svg viewBox="0 0 10 14" width="9" height="9" fill="currentColor" style="opacity:.35;flex-shrink:0"><path d="M5 0l3 4H2zM5 14l-3-4h6z"/></svg>';
    }
    if ($dir === 'asc') {
        return '<svg viewBox="0 0 10 14" width="9" height="9" fill="currentColor" style="flex-shrink:0"><path d="M5 0l3 4H2z"/><path d="M5 14l-3-4h6z" fill-opacity=".25"/></svg>';
    }
    return '<svg viewBox="0 0 10 14" width="9" height="9" fill="currentColor" style="flex-shrink:0"><path d="M5 0l3 4H2z" fill-opacity=".25"/><path d="M5 14l-3-4h6z"/></svg>';
};
?>

<?php if (($success ?? '') !== ''): ?>
  <div class="alert alert-success"><?= \Luster\Core\View::e($success ?? '') ?></div>
<?php endif; ?>
<?php if (($error ?? '') !== ''): ?>
  <div class="alert alert-error"><?= \Luster\Core\View::e($error ?? '') ?></div>
<?php endif; ?>

<!-- Toolbar -->
<div class="neb-toolbar">
  <div class="neb-actions">
    <button type="button" class="btn btn-tertiary btn-sm fl-filter-toggle" id="fl-filter-toggle"
            aria-expanded="<?= $filtersActive ? 'true' : 'false' ?>"
            aria-controls="fl-filter-panel">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="13" height="13"><line x1="4" y1="6" x2="20" y2="6"/><line x1="8" y1="12" x2="16" y2="12"/><line x1="11" y1="18" x2="13" y2="18"/></svg>
      Filter<?php if ($filtersActive): ?><span class="fl-filter-dot" aria-hidden="true"></span><?php endif; ?>
    </button>
  </div>
  <div class="neb-actions">
    <a href="<?= $b ?>/admin/categories/add" class="btn btn-primary btn-sm">+ Add New Category</a>
  </div>
</div>

<!-- Collapsible filter panel -->
<div class="fl-filter-panel<?= $filtersActive ? ' is-open' : '' ?>" id="fl-filter-panel">
  <form method="GET" action="<?= $b ?>/admin/categories" class="neb-filter-row">
    <?php if ($sort !== 'source'): ?><input type="hidden" name="sort" value="<?= \Luster\Core\View::e($sort) ?>"><?php endif; ?>
    <?php if ($dir !== 'asc'):    ?><input type="hidden" name="dir"  value="<?= \Luster\Core\View::e($dir) ?>"><?php endif; ?>

    <div class="toolbar-select-wrap">
    <select name="source" class="neb-select js-autosubmit">
      <option value="">All Networks</option>
      <option value="tfl"  <?= ($source ?? '') === 'tfl'  ? 'selected' : '' ?>>TFL</option>
      <option value="tafl" <?= ($source ?? '') === 'tafl' ? 'selected' : '' ?>>TAFL</option>
      <?php foreach ($custom_sources ?? [] as $cs): ?>
        <option value="<?= \Luster\Core\View::e($cs) ?>"
                <?= ($source ?? '') === $cs ? 'selected' : '' ?>>
          <?= \Luster\Core\View::e($cs) ?>
        </option>
      <?php endforeach; ?>
    </select>
    </div>

    <input type="text" name="search" class="neb-input" placeholder="Search&hellip;"
           value="<?= \Luster\Core\View::e($search ?? '') ?>">
    <button type="submit" class="btn btn-tertiary btn-sm">Search</button>
    <a href="<?= $b ?>/admin/categories" class="btn btn-tertiary btn-sm">Reset</a>

  </form>
</div>

<?php if (empty($items ?? [])): ?>
<div class="neb-card">
  <div class="neb-empty">
    <?php if (($search ?? '') !== '' || ($source ?? '') !== ''): ?>
      <svg class="empty-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><circle cx="11" cy="11" r="8"/><path d="M21 21l-4.35-4.35"/></svg>
      No categories match your search.
      <a href="<?= $b ?>/admin/categories" class="btn btn-tertiary btn-sm">Reset</a>
    <?php else: ?>
      <svg class="empty-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M3 7h18M3 12h18M3 17h18"/></svg>
      No categories yet. Add your first to start organising fanlistings.
      <a href="<?= $b ?>/admin/categories/add" class="btn btn-primary btn-sm">+ Add New Category</a>
    <?php endif; ?>
  </div>
</div>
<?php else: ?>

<p class="result-count">
  Showing <?= count($items ?? []) ?> of <?= (int) ($total ?? 0) ?> categor<?= (int) ($total ?? 0) === 1 ? 'y' : 'ies' ?>
  <?php if (($source ?? '') !== ''): ?>
    in <strong><?= \Luster\Core\View::e(strtoupper($source ?? '')) ?></strong>
  <?php endif; ?>
</p>

<div class="neb-card">
  <div class="neb-table-wrap">
  <table class="data-table mem-table">
    <thead>
      <tr>
        <th><a href="<?= \Luster\Core\View::e($catSortUrl('id')) ?>" class="sort-th<?= $sort === 'id' ? ' sort-active' : '' ?>">ID <?= $catSortIcon('id') ?></a></th>
        <th><a href="<?= \Luster\Core\View::e($catSortUrl('source')) ?>" class="sort-th<?= $sort === 'source' ? ' sort-active' : '' ?>">Network <?= $catSortIcon('source') ?></a></th>
        <th><a href="<?= \Luster\Core\View::e($catSortUrl('name')) ?>" class="sort-th<?= $sort === 'name' ? ' sort-active' : '' ?>">Category Name <?= $catSortIcon('name') ?></a></th>
        <th>Parent</th>
        <th class="act-col">Actions</th>
      </tr>
    </thead>
    <tbody>
      <?php
      // Build a quick id→name lookup for parent display
      $catIndex = [];
      foreach ($all_cats ?? [] as $_ac) { $catIndex[$_ac->id] = $_ac->name; }
      ?>
      <?php foreach ($items ?? [] as $cat): ?>
      <tr>
        <td class="text-dim" data-label="ID"><?= (int) $cat->id ?></td>
        <td data-label="Network"><?= $sourceBadge($cat->source ?? 'custom') ?></td>
        <td data-label="Name">
          <?php if ($cat->parentId !== null): ?>
            <span style="color:var(--text-dim);font-size:.8rem;margin-right:.25rem">└</span>
          <?php endif; ?>
          <?= \Luster\Core\View::e($cat->name ?? '') ?>
        </td>
        <td class="text-dim" data-label="Parent" style="font-size:.85rem">
          <?php if ($cat->parentId !== null && isset($catIndex[$cat->parentId])): ?>
            <?= \Luster\Core\View::e($catIndex[$cat->parentId]) ?>
          <?php else: ?>
            —
          <?php endif; ?>
        </td>
        <td class="act-col" data-label="">
          <a href="<?= $b ?>/admin/categories/<?= (int) $cat->id ?>/edit"
             class="btn btn-primary btn-sm">Edit</a>
          <?php if (!in_array($cat->source ?? '', ['tfl', 'tafl'], true)): ?>
            <form method="POST" action="<?= $b ?>/admin/categories/<?= (int) $cat->id ?>/delete"
                  style="display:contents"
                  data-del-modal data-del-entity="category"
                  data-del-name="<?= \Luster\Core\View::e($cat->name ?? '') ?>">
              <input type="hidden" name="_csrf" value="<?= \Luster\Core\View::e($csrf_token ?? '') ?>">
              <button type="submit" class="btn btn-danger btn-sm">Delete</button>
            </form>
          <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  </div>
</div>
<?php endif; ?>

<?php if (($total_pages ?? 1) > 1): ?>
<nav class="neb-pagination" aria-label="Pagination">
  <a href="<?= \Luster\Core\View::e($catPageUrl($page - 1)) ?>"
     class="pg-link<?= $page <= 1 ? ' disabled' : '' ?>"
     <?= $page <= 1 ? 'aria-disabled="true"' : '' ?>>&lsaquo; Prev</a>

  <?php
    $window = 2;
    $start  = max(1, $page - $window);
    $end    = min($total_pages, $page + $window);
    if ($start > 1): ?>
      <a href="<?= \Luster\Core\View::e($catPageUrl(1)) ?>" class="pg-link">1</a>
      <?php if ($start > 2): ?><span class="pg-link disabled">&hellip;</span><?php endif; ?>
  <?php endif; ?>

  <?php for ($i = $start; $i <= $end; $i++): ?>
    <a href="<?= \Luster\Core\View::e($catPageUrl($i)) ?>"
       class="pg-link<?= $i === $page ? ' active' : '' ?>"
       <?= $i === $page ? 'aria-current="page"' : '' ?>><?= $i ?></a>
  <?php endfor; ?>

  <?php if ($end < $total_pages): ?>
    <?php if ($end < $total_pages - 1): ?><span class="pg-link disabled">&hellip;</span><?php endif; ?>
    <a href="<?= \Luster\Core\View::e($catPageUrl($total_pages)) ?>" class="pg-link"><?= $total_pages ?></a>
  <?php endif; ?>

  <a href="<?= \Luster\Core\View::e($catPageUrl($page + 1)) ?>"
     class="pg-link<?= $page >= $total_pages ? ' disabled' : '' ?>"
     <?= $page >= $total_pages ? 'aria-disabled="true"' : '' ?>>Next &rsaquo;</a>
</nav>
<?php endif; ?>
