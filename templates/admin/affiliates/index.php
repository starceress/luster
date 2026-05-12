<?php
use Luster\Core\View;
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
$b = rtrim(View::e($base_url), '/');

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

// Reorder mode is only meaningful for collective or a specific listing - not cross-scope.
$canReorder = ($search ?? '') === '' && ($filter_fid ?? 'collective') !== '';
?>

<?php if (($success ?? '') !== ''): ?>
  <div class="alert alert-success"><?= View::e($success) ?></div>
<?php endif; ?>
<?php if (($error ?? '') !== ''): ?>
  <div class="alert alert-error"><?= View::e($error) ?></div>
<?php endif; ?>

<?php if (!empty($pending_count)): ?>
  <div class="alert alert-warning">
    <?= (int) $pending_count ?> pending affiliate application<?= (int) $pending_count !== 1 ? 's' : '' ?> await<?= (int) $pending_count === 1 ? 's' : '' ?> review.
    <a href="<?= $b ?>/admin/affiliates/pending" class="btn btn-sm btn-quaternary" style="margin-left:.5rem">Review</a>
  </div>
<?php endif; ?>

<?php if ($reorderMode): ?>
<!-- ──── REORDER MODE BANNER ────────────────────────────────────────── -->
<div class="neb-reorder-banner">
  <div class="reorder-banner-copy">
    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/></svg>
    <strong>Reorder mode</strong> - drag rows to rearrange. Changes are saved automatically.
  </div>
  <a href="<?= View::e($normalUrl) ?>" class="btn btn-quaternary btn-sm">Done</a>
</div>

<!-- Hidden CSRF for AJAX reorder save -->
<input type="hidden" name="_csrf" value="<?= View::e($csrf_token) ?>">

<?php else: ?>
<!-- ──── NORMAL MODE TOOLBAR ────────────────────────────────────────── -->
<div class="toolbar">
  <form method="GET" action="<?= $b ?>/admin/affiliates" class="filter-row">
    <?php if ($sort !== 'sort_order'): ?>
      <input type="hidden" name="sort" value="<?= View::e($sort) ?>">
    <?php endif; ?>
    <?php if ($dir !== 'asc'): ?>
      <input type="hidden" name="dir" value="<?= View::e($dir) ?>">
    <?php endif; ?>

    <div class="select-wrap">
    <select name="fanlisting_id" class="js-autosubmit">
      <option value="collective"<?= ($filter_fid ?? 'collective') === 'collective' ? ' selected' : '' ?>><?= View::e(($site_name ?? 'Collective') . ' (Collective) Affiliates') ?></option>
      <?php foreach ($listings as $fl): ?>
        <option value="<?= (int) $fl->id ?>"
                <?= ($filter_fid ?? '') === (string) $fl->id ? ' selected' : '' ?>>
          <?= View::e($fl->name) ?>
        </option>
      <?php endforeach; ?>
    </select>
    </div>

    <input type="text" name="search" class="form" placeholder="Search name, URL, email&hellip;"
           value="<?= View::e($search ?? '') ?>">
    <button type="submit" class="btn btn-quaternary btn-sm">Search</button>
    <a href="?" class="btn btn-quaternary btn-sm">Reset</a>
  </form>
  <div class="actions-row">
    <?php if ($canReorder && !empty($affiliates)): ?>
    <a href="<?= View::e($reorderEntryUrl) ?>" class="btn btn-quaternary btn-sm">Reorder</a>
    <?php endif; ?>
    <a href="<?= $b ?>/admin/affiliates/add" class="btn btn-primary btn-sm">+ Add Affiliate</a>
  </div>
</div>

<?php endif; ?>

<?php if (empty($affiliates)): ?>
  <div class="card">
    <div class="empty">
      <svg class="empty-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg>
      No affiliates yet.
      <a href="<?= $b ?>/admin/affiliates/add" class="btn btn-primary btn-sm">+ Add Affiliate</a>
    </div>
  </div>
<?php else: ?>

<div class="card">

<?php if ($reorderMode): ?>
<!-- ──── REORDER TABLE ──────────────────────────────────────────────── -->
  <div class="table-wrap">
  <table class="data-table mem-table" data-reorder-url="<?= View::e($reorder_url) ?>">
    <thead>
      <tr>
        <th class="table-col-xs"></th>
        <th>Name</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($affiliates as $aff): ?>
      <tr draggable="true" data-id="<?= (int) $aff->id ?>">
        <td class="table-col-xs table-cell-center">
          <span class="drag-handle" title="Drag to reorder">Â Â¿</span>
        </td>
        <td>
          <span class="text-strong"><?= View::e($aff->name) ?></span>
          <?php if (!$aff->active): ?>
            <span class="badge badge-inactive badge-inline-sm">Inactive</span>
          <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  </div>

<?php else: ?>
<!-- ──── NORMAL TABLE ───────────────────────────────────────────────── -->
  <div class="table-wrap">
  <table class="data-table mem-table">
    <thead>
      <tr>
        <th class="table-col-sm">
          <a href="<?= View::e($sortUrl('sort_order')) ?>" class="sort-th<?= $sort === 'sort_order' ? ' sort-active' : '' ?>">
            Sort # <?= View::sortIcon('sort_order', $sort, $dir) ?>
          </a>
        </th>
        <th>
          <a href="<?= View::e($sortUrl('name')) ?>" class="sort-th<?= $sort === 'name' ? ' sort-active' : '' ?>">
            Name <?= View::sortIcon('name', $sort, $dir) ?>
          </a>
        </th>
        <th>Scope</th>
        <th>
          <a href="<?= View::e($sortUrl('email')) ?>" class="sort-th<?= $sort === 'email' ? ' sort-active' : '' ?>">
            Email <?= View::sortIcon('email', $sort, $dir) ?>
          </a>
        </th>
        <th>
          <a href="<?= View::e($sortUrl('status')) ?>" class="sort-th<?= $sort === 'status' ? ' sort-active' : '' ?>">
            Status <?= View::sortIcon('status', $sort, $dir) ?>
          </a>
        </th>
        <th class="act-col">Actions</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($affiliates as $aff): ?>
      <?php
        $scopeLabel = ($aff->listingId !== null && isset($fan_map[$aff->listingId]))
            ? View::e($fan_map[$aff->listingId])
            : View::e($site_name ?? 'Collective');
      ?>
      <tr>
        <td class="text-dim" data-label="Sort #"><?= (int) $aff->sortOrder ?></td>
        <td data-label="Name">
          <div class="inline-link-row">
            <a href="<?= $b ?>/admin/affiliates/<?= (int) $aff->id ?>/edit"
               class="link text-strong"><?= View::e($aff->name) ?></a>
            <?php if ($aff->url !== ''): ?>
            <a href="<?= View::e($aff->url) ?>" target="_blank" rel="noopener noreferrer"
               class="text-dim icon-link-inline" title="Visit site">
              <svg viewBox="0 0 12 12" fill="none" stroke="currentColor" stroke-width="1.5">
                <path d="M5 2H2a1 1 0 0 0-1 1v7a1 1 0 0 0 1 1h7a1 1 0 0 0 1-1V7M8 1h3m0 0v3m0-3L5 7"/>
              </svg>
            </a>
            <?php endif; ?>
          </div>
        </td>
        <td class="text-muted" data-label="Scope">
          <?php if ($aff->listingId !== null): ?>
            <span class="badge badge-current"><?= $scopeLabel ?></span>
          <?php else: ?>
            <span class="badge badge-pending">Collective</span>
          <?php endif; ?>
        </td>
        <td class="text-muted" data-label="Email">
          <?= ($aff->email !== null && $aff->email !== '') ? View::e($aff->email) : '<span class="text-dim">&mdash;</span>' ?>
        </td>
        <td data-label="Status">
          <?php if ($aff->active): ?>
            <span class="badge badge-current">Active</span>
          <?php else: ?>
            <span class="badge badge-inactive">Inactive</span>
          <?php endif; ?>
        </td>
        <td class="act-col" data-label="">
          <a href="<?= $b ?>/admin/affiliates/<?= (int) $aff->id ?>/edit"
             class="btn btn-tertiary btn-sm">Edit</a>

          <form method="POST" action="<?= $b ?>/admin/affiliates/<?= (int) $aff->id ?>/toggle"
                class="action-form-inline">
            <input type="hidden" name="_csrf" value="<?= View::e($csrf_token ?? '') ?>">
            <button type="submit" class="btn btn-sm <?= $aff->active ? 'btn-warn' : 'btn-quaternary' ?>"><?= $aff->active ? 'Deactivate' : 'Activate' ?></button>
          </form>

          <form method="POST" action="<?= $b ?>/admin/affiliates/<?= (int) $aff->id ?>/delete"
                class="action-form-inline"
                data-del-modal data-del-entity="affiliate"
                data-del-name="<?= View::e($aff->name) ?>">
            <input type="hidden" name="_csrf" value="<?= View::e($csrf_token ?? '') ?>">
            <button type="submit" class="btn btn-danger btn-sm">Delete</button>
          </form>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  </div>
<?php endif; ?>

</div><!-- /.card -->

<?php endif; ?>

<?php if (!$reorderMode): ?>
<?php $pg_current = $page; $pg_total = $total_pages; $pg_url = $pageUrl;
include LUSTER_BASE . '/templates/admin/_pagination.php'; ?>
<?php endif; ?>
