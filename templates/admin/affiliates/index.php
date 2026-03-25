<?php
/** @var \Luster\Models\Affiliate[]  $affiliates */
/** @var \Luster\Models\Listing[]    $listings */
/** @var array<int,string>           $fan_map */
/** @var string                      $filter_fid */
/** @var string                      $search */
/** @var string                      $sort */
/** @var string                      $dir */
/** @var int                         $page */
/** @var int                         $per_page */
/** @var int                         $total_pages */
/** @var int                         $total */
/** @var string                      $site_name */
/** @var bool                        $reorder_mode */
/** @var string                      $success */
/** @var string                      $error */
/** @var string                      $reorder_url */
/** @var string                      $base_url */
/** @var string                      $csrf_token */
$b = rtrim(\Luster\Core\View::e($base_url), '/');

$sort = $sort ?? 'sort_order';
$dir  = $dir  ?? 'asc';
$reorderMode = (bool) ($reorder_mode ?? false);

// URL to return to normal view (same scope, no reorder flag)
$normalUrl = $b . '/admin/affiliates?' . http_build_query(array_filter([
    'fanlisting_id' => ($filter_fid ?? 'collective') !== 'collective' ? ($filter_fid ?? '') : '',
], static fn(string $v): bool => $v !== ''));

// URL to enter reorder mode (same scope + reorder=1)
$reorderEntryUrl = $b . '/admin/affiliates?' . http_build_query(array_filter([
    'fanlisting_id' => ($filter_fid ?? 'collective') !== 'collective' ? ($filter_fid ?? '') : '',
    'reorder'       => '1',
], static fn(string $v): bool => $v !== ''));

// Pagination + sort URL builder (normal mode only)
$filterQs = http_build_query(array_filter([
    'fanlisting_id' => ($filter_fid ?? 'collective') !== 'collective' ? ($filter_fid ?? '') : '',
    'search'        => $search ?? '',
    'sort'          => $sort !== 'sort_order' ? $sort : '',
    'dir'           => $dir  !== 'asc'        ? $dir  : '',
], static fn(string $v): bool => $v !== ''));

$pageUrl = static function (int $p) use ($b, $filterQs): string {
    $qs = $filterQs !== '' ? $filterQs . '&page=' . $p : 'page=' . $p;
    return $b . '/admin/affiliates?' . $qs;
};

$sortUrl = static function (string $col) use ($b, $filter_fid, $search, $sort, $dir): string {
    $newDir = ($sort === $col && $dir === 'asc') ? 'desc' : 'asc';
    $qs = http_build_query(array_filter([
        'fanlisting_id' => ($filter_fid ?? 'collective') !== 'collective' ? ($filter_fid ?? '') : '',
        'search'        => $search ?? '',
        'sort'          => $col,
        'dir'           => $newDir,
    ], static fn(string $v): bool => $v !== ''));
    return $b . '/admin/affiliates?' . $qs;
};

$sortIcon = static function (string $col) use ($sort, $dir): string {
    if ($sort !== $col) {
        return '<svg viewBox="0 0 10 14" width="9" height="9" fill="currentColor" style="opacity:.35;flex-shrink:0"><path d="M5 0l3 4H2zM5 14l-3-4h6z"/></svg>';
    }
    if ($dir === 'asc') {
        return '<svg viewBox="0 0 10 14" width="9" height="9" fill="currentColor" style="flex-shrink:0"><path d="M5 0l3 4H2z"/><path d="M5 14l-3-4h6z" fill-opacity=".25"/></svg>';
    }
    return '<svg viewBox="0 0 10 14" width="9" height="9" fill="currentColor" style="flex-shrink:0"><path d="M5 0l3 4H2z" fill-opacity=".25"/><path d="M5 14l-3-4h6z"/></svg>';
};

// Reorder mode is only meaningful for collective or a specific listing — not cross-scope.
$canReorder = ($search ?? '') === '' && ($filter_fid ?? 'collective') !== '';
?>

<?php if (($success ?? '') !== ''): ?>
  <div class="alert alert-success"><?= \Luster\Core\View::e($success) ?></div>
<?php endif; ?>
<?php if (($error ?? '') !== ''): ?>
  <div class="alert alert-error"><?= \Luster\Core\View::e($error) ?></div>
<?php endif; ?>

<?php if ($reorderMode): ?>

<!-- ── Reorder mode banner ───────────────────────────────────────────── -->
<div class="neb-reorder-banner">
  <div style="display:flex;align-items:center;gap:.6rem">
    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/></svg>
    <strong>Reorder mode</strong> — drag rows to rearrange. Changes are saved automatically.
  </div>
  <a href="<?= \Luster\Core\View::e($normalUrl) ?>" class="btn btn-tertiary btn-sm">Done</a>
</div>

<!-- Hidden CSRF for AJAX reorder save -->
<input type="hidden" name="_csrf" value="<?= \Luster\Core\View::e($csrf_token) ?>">

<?php else: ?>

<!-- ── Normal mode toolbar ───────────────────────────────────────────── -->
<div class="neb-toolbar">
  <form method="GET" action="<?= $b ?>/admin/affiliates" class="neb-filter-row">
    <?php if ($sort !== 'sort_order'): ?>
      <input type="hidden" name="sort" value="<?= \Luster\Core\View::e($sort) ?>">
    <?php endif; ?>
    <?php if ($dir !== 'asc'): ?>
      <input type="hidden" name="dir" value="<?= \Luster\Core\View::e($dir) ?>">
    <?php endif; ?>

    <div class="toolbar-select-wrap">
    <select name="fanlisting_id" class="neb-select js-autosubmit">
      <option value="collective"<?= ($filter_fid ?? 'collective') === 'collective' ? ' selected' : '' ?>><?= \Luster\Core\View::e(($site_name ?? 'Collective') . ' (Collective) Affiliates') ?></option>
      <?php foreach ($listings as $fl): ?>
        <option value="<?= (int) $fl->id ?>"
                <?= ($filter_fid ?? '') === (string) $fl->id ? ' selected' : '' ?>>
          <?= \Luster\Core\View::e($fl->name) ?>
        </option>
      <?php endforeach; ?>
    </select>
    </div>

    <input type="text" name="search" class="neb-input" placeholder="Search name, URL, email&hellip;"
           value="<?= \Luster\Core\View::e($search ?? '') ?>">
    <button type="submit" class="btn btn-tertiary btn-sm">Search</button>
    <a href="?" class="btn btn-tertiary btn-sm">Reset</a>
  </form>
  <div class="neb-actions">
    <?php if ($canReorder && !empty($affiliates)): ?>
    <a href="<?= \Luster\Core\View::e($reorderEntryUrl) ?>" class="btn btn-tertiary btn-sm">Reorder</a>
    <?php endif; ?>
    <a href="<?= $b ?>/admin/affiliates/add" class="btn btn-primary btn-sm">+ Add Affiliate</a>
  </div>
</div>

<?php endif; ?>

<?php if (empty($affiliates)): ?>
  <div class="neb-card">
    <div class="neb-empty">
      <svg class="empty-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg>
      No affiliates yet.
      <a href="<?= $b ?>/admin/affiliates/add" class="btn btn-primary btn-sm">+ Add Affiliate</a>
    </div>
  </div>
<?php else: ?>

<div class="neb-card">

<?php if ($reorderMode): ?>
  <!-- ── Reorder table ──────────────────────────────────────────────── -->
  <div class="neb-table-wrap">
  <table class="data-table mem-table" data-reorder-url="<?= \Luster\Core\View::e($reorder_url) ?>">
    <thead>
      <tr>
        <th style="width:40px"></th>
        <th>Name</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($affiliates as $aff): ?>
      <tr draggable="true" data-id="<?= (int) $aff->id ?>">
        <td style="width:40px;text-align:center">
          <span class="drag-handle" title="Drag to reorder">⠿</span>
        </td>
        <td>
          <span class="text-strong"><?= \Luster\Core\View::e($aff->name) ?></span>
          <?php if (!$aff->active): ?>
            <span class="neb-badge badge-inactive" style="margin-left:.4rem;font-size:.7rem">Inactive</span>
          <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  </div>

<?php else: ?>
  <!-- ── Normal table ───────────────────────────────────────────────── -->
  <div class="neb-table-wrap">
  <table class="data-table mem-table">
    <thead>
      <tr>
        <th style="width:56px">
          <a href="<?= \Luster\Core\View::e($sortUrl('sort_order')) ?>" class="sort-th<?= $sort === 'sort_order' ? ' sort-active' : '' ?>">
            Sort # <?= $sortIcon('sort_order') ?>
          </a>
        </th>
        <th>
          <a href="<?= \Luster\Core\View::e($sortUrl('name')) ?>" class="sort-th<?= $sort === 'name' ? ' sort-active' : '' ?>">
            Name <?= $sortIcon('name') ?>
          </a>
        </th>
        <th>Scope</th>
        <th>
          <a href="<?= \Luster\Core\View::e($sortUrl('email')) ?>" class="sort-th<?= $sort === 'email' ? ' sort-active' : '' ?>">
            Email <?= $sortIcon('email') ?>
          </a>
        </th>
        <th>
          <a href="<?= \Luster\Core\View::e($sortUrl('status')) ?>" class="sort-th<?= $sort === 'status' ? ' sort-active' : '' ?>">
            Status <?= $sortIcon('status') ?>
          </a>
        </th>
        <th class="act-col">Actions</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($affiliates as $aff): ?>
      <?php
        $scopeLabel = ($aff->listingId !== null && isset($fan_map[$aff->listingId]))
            ? \Luster\Core\View::e($fan_map[$aff->listingId])
            : \Luster\Core\View::e($site_name ?? 'Collective');
      ?>
      <tr>
        <td class="text-dim" data-label="Sort #"><?= (int) $aff->sortOrder ?></td>
        <td data-label="Name">
          <div style="display:inline-flex;align-items:center;gap:.4rem">
            <a href="<?= $b ?>/admin/affiliates/<?= (int) $aff->id ?>/edit"
               class="neb-link text-strong"><?= \Luster\Core\View::e($aff->name) ?></a>
            <?php if ($aff->url !== ''): ?>
            <a href="<?= \Luster\Core\View::e($aff->url) ?>" target="_blank" rel="noopener noreferrer"
               class="text-dim" title="Visit site" style="display:inline-flex;align-items:center;line-height:1">
              <svg viewBox="0 0 12 12" fill="none" stroke="currentColor" stroke-width="1.5"
                   style="width:11px;height:11px;flex-shrink:0">
                <path d="M5 2H2a1 1 0 0 0-1 1v7a1 1 0 0 0 1 1h7a1 1 0 0 0 1-1V7M8 1h3m0 0v3m0-3L5 7"/>
              </svg>
            </a>
            <?php endif; ?>
          </div>
        </td>
        <td class="text-muted" data-label="Scope">
          <?php if ($aff->listingId !== null): ?>
            <span class="neb-badge badge-current"><?= $scopeLabel ?></span>
          <?php else: ?>
            <span class="neb-badge badge-pending">Collective</span>
          <?php endif; ?>
        </td>
        <td class="text-muted" data-label="Email">
          <?= ($aff->email !== null && $aff->email !== '') ? \Luster\Core\View::e($aff->email) : '<span class="text-dim">&mdash;</span>' ?>
        </td>
        <td data-label="Status">
          <?php if ($aff->active): ?>
            <span class="neb-badge badge-current">Active</span>
          <?php else: ?>
            <span class="neb-badge badge-inactive">Inactive</span>
          <?php endif; ?>
        </td>
        <td class="act-col" data-label="">
          <a href="<?= $b ?>/admin/affiliates/<?= (int) $aff->id ?>/edit"
             class="btn btn-primary btn-sm">Edit</a>

          <form method="POST" action="<?= $b ?>/admin/affiliates/<?= (int) $aff->id ?>/toggle"
                style="display:contents">
            <input type="hidden" name="_csrf" value="<?= \Luster\Core\View::e($csrf_token ?? '') ?>">
            <button type="submit" class="btn btn-tertiary btn-sm"><?= $aff->active ? 'Disable' : 'Enable' ?></button>
          </form>

          <form method="POST" action="<?= $b ?>/admin/affiliates/<?= (int) $aff->id ?>/delete"
                style="display:contents"
                data-del-modal data-del-entity="affiliate"
                data-del-name="<?= \Luster\Core\View::e($aff->name) ?>">
            <input type="hidden" name="_csrf" value="<?= \Luster\Core\View::e($csrf_token ?? '') ?>">
            <button type="submit" class="btn btn-danger btn-sm">Delete</button>
          </form>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  </div>
<?php endif; ?>

</div><!-- /.neb-card -->

<?php endif; ?>

<?php if (!$reorderMode && ($total_pages ?? 1) > 1): ?>
<nav class="neb-pagination" aria-label="Pagination">
  <a href="<?= \Luster\Core\View::e($pageUrl($page - 1)) ?>"
     class="pg-link<?= $page <= 1 ? ' disabled' : '' ?>"
     <?= $page <= 1 ? 'aria-disabled="true"' : '' ?>>&lsaquo; Prev</a>

  <?php for ($i = 1; $i <= $total_pages; $i++): ?>
    <a href="<?= \Luster\Core\View::e($pageUrl($i)) ?>"
       class="pg-link<?= $i === $page ? ' active' : '' ?>"
       <?= $i === $page ? 'aria-current="page"' : '' ?>><?= $i ?></a>
  <?php endfor; ?>

  <a href="<?= \Luster\Core\View::e($pageUrl($page + 1)) ?>"
     class="pg-link<?= $page >= $total_pages ? ' disabled' : '' ?>"
     <?= $page >= $total_pages ? 'aria-disabled="true"' : '' ?>>Next &rsaquo;</a>
</nav>
<?php endif; ?>
