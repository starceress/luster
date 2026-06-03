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
$reorderMode  = (bool) ($reorder_mode ?? false);
$fid          = $filter_fid ?? 'all';
$isAllView    = $fid === 'all' || $fid === '';
$showSortCol  = !$isAllView;
$defaultSort  = $isAllView ? 'name' : 'sort_order';

// Omit fanlisting_id from URL only when it equals the default ('all').
$fidForUrl = static fn(string $f): string => ($f !== 'all' && $f !== '') ? $f : '';

// URL to return to normal view (same scope, no reorder flag)
$normalUrl = $b . '/admin/affiliates?' . http_build_query(array_filter([
    'fanlisting_id' => $fidForUrl($fid),
], static fn(string $v): bool => $v !== ''));

// URL to enter reorder mode (same scope + reorder=1)
$reorderEntryUrl = $b . '/admin/affiliates?' . http_build_query(array_filter([
    'fanlisting_id' => $fidForUrl($fid),
    'reorder'       => '1',
], static fn(string $v): bool => $v !== ''));

// Pagination + sort URL builder (normal mode only)
$filterQs = http_build_query(array_filter([
    'fanlisting_id' => $fidForUrl($fid),
    'search'        => $search ?? '',
    'sort'          => $sort !== $defaultSort ? $sort : '',
    'dir'           => $dir  !== 'asc'        ? $dir  : '',
], static fn(string $v): bool => $v !== ''));

$pageUrl = static function (int $p) use ($b, $filterQs): string {
    $qs = $filterQs !== '' ? $filterQs . '&page=' . $p : 'page=' . $p;
    return $b . '/admin/affiliates?' . $qs;
};

$sortUrl = static function (string $col) use ($b, $fid, $fidForUrl, $search, $sort, $dir, $defaultSort): string {
    $newDir = ($sort === $col && $dir === 'asc') ? 'desc' : 'asc';
    $qs = http_build_query(array_filter([
        'fanlisting_id' => $fidForUrl($fid),
        'search'        => $search ?? '',
        'sort'          => $col !== $defaultSort ? $col : '',
        'dir'           => $newDir,
    ], static fn(string $v): bool => $v !== ''));
    return $b . '/admin/affiliates?' . $qs;
};

// Reorder mode is only meaningful for collective or a specific listing - not cross-scope.
$canReorder = ($search ?? '') === '' && !$isAllView && $fid !== '';
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
<?php $filtersActive = ($search ?? '') !== '' || !$isAllView; ?>
<div class="toolbar">
  <div class="actions-row">
    <button type="button" class="btn btn-preview btn-sm filter-toggle" id="fl-filter-toggle"
            aria-expanded="true"
            aria-controls="filter-panel">
      <?= View::icon('filter') ?>
      Filter<?php if ($filtersActive): ?><span class="filter-dot" aria-hidden="true"></span><?php endif; ?>
    </button>
  </div>
  <div class="actions-row">
    <?php if ($canReorder && !empty($affiliates)): ?>
    <a href="<?= View::e($reorderEntryUrl) ?>" class="btn btn-quaternary btn-sm">Reorder</a>
    <?php endif; ?>
    <a href="<?= $b ?>/admin/affiliates/email" class="btn btn-secondary btn-sm">Email Affiliates</a>
    <a href="<?= $b ?>/admin/affiliates/add" class="btn btn-primary btn-sm">+ Add Affiliate</a>
  </div>
</div>
<div class="filter-panel" id="filter-panel">
  <form method="GET" action="<?= $b ?>/admin/affiliates" class="filter-row">
    <?php if ($sort !== 'sort_order'): ?>
      <input type="hidden" name="sort" value="<?= View::e($sort) ?>">
    <?php endif; ?>
    <?php if ($dir !== 'asc'): ?>
      <input type="hidden" name="dir" value="<?= View::e($dir) ?>">
    <?php endif; ?>
    <div class="select-wrap">
    <select name="fanlisting_id" class="js-autosubmit">
      <option value="all"<?= $fid === 'all' || $fid === '' ? ' selected' : '' ?>>All Affiliates</option>
      <option value="collective"<?= $fid === 'collective' ? ' selected' : '' ?>><?= View::e(($site_name ?? 'Collective') . ' (Collective) Affiliates') ?></option>
      <?php foreach ($listings as $fl): ?>
        <option value="<?= (int) $fl->id ?>"
                <?= $fid === (string) $fl->id ? ' selected' : '' ?>>
          <?= View::e($fl->name) ?>
        </option>
      <?php endforeach; ?>
    </select>
    </div>
    <div class="search-field-wrap">
      <?= View::icon('search', ['class' => 'search-field-icon', 'aria-hidden' => 'true']) ?>
      <input type="text" name="search" class="form" placeholder="Search name, URL, email&hellip;"
             value="<?= View::e($search ?? '') ?>">
    </div>
    <button type="submit" class="btn btn-secondary btn-sm">Search</button>
    <a href="<?= $b ?>/admin/affiliates" class="btn btn-ghost btn-sm">Reset</a>
  </form>
</div>

<?php endif; ?>

<?php if (empty($affiliates)): ?>
  <div class="card">
    <div class="empty">
      <div class="empty-icon"><?= View::icon('link', ['width' => 32, 'height' => 32]) ?></div>
      <div class="empty-title">No affiliates yet.</div>
      <div>Add your first affiliate to display them on your site.</div>
      <a href="<?= $b ?>/admin/affiliates/add" class="btn btn-primary btn-sm"><?= View::icon('add') ?> Add Affiliate</a>
    </div>
  </div>

<?php elseif ($reorderMode): ?>
<!-- ──── REORDER TABLE ──────────────────────────────────────────────── -->
<div class="card">
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
          <span class="drag-handle" title="Drag to reorder"><?= View::icon('grip') ?></span>
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
</div>

<?php else: ?>
<!-- ──── NORMAL TABLE (with bulk form) ──────────────────────────────── -->
<?php
$_affLabel = $isAllView ? 'All Affiliates' : ($fid === 'collective' ? (View::e($site_name ?? 'Collective') . ' Affiliates') : (($fan_map[(int) $fid] ?? '') !== '' ? View::e($fan_map[(int) $fid]) . ' Affiliates' : 'Affiliates'));
$_affFrom  = ($page - 1) * $per_page + 1;
$_affTo    = ($page - 1) * $per_page + count($affiliates);
?>
<form method="POST" action="<?= $b ?>/admin/affiliates/bulk" id="bulk-form"
      data-del-bulk data-del-entity="affiliate">
  <input type="hidden" name="_csrf" value="<?= View::e($csrf_token ?? '') ?>">
  <input type="hidden" name="scope" value="<?= View::e($filter_fid ?? 'collective') ?>">
  <input type="hidden" name="select_all" value="0">
  <input type="hidden" name="sa_filter_search" value="<?= View::e($search ?? '') ?>">

  <div class="card">
    <div class="card-head">
      <span><?= $_affLabel ?></span>
      <span class="row-sub">
        Showing <?= $_affFrom ?>&#8211;<?= $_affTo ?> of <?= number_format((int) ($total ?? 0)) ?> affiliate<?= (int) ($total ?? 0) !== 1 ? 's' : '' ?><?php if (($total_pages ?? 1) > 1): ?> &mdash; page <?= (int) $page ?> of <?= (int) $total_pages ?><?php endif; ?>
      </span>
    </div>
    <div class="bulk bulk--flush">
      Bulk Actions:
      <div class="select-wrap">
        <select id="aff-bulk-action" name="action">
          <option value="" disabled selected>&mdash; Select &mdash;</option>
          <option value="activate">Activate</option>
          <option value="deactivate">Deactivate</option>
          <option value="delete">Delete</option>
        </select>
      </div>
      <button type="submit" class="btn btn-quaternary btn-sm">Apply to Selected</button>
    </div>
    <div class="select-all-banner" id="aff-select-all-sa-banner" data-total="<?= (int) ($total ?? 0) ?>" style="display:none">
      <span class="sa-page">All <strong class="sa-page-count">0</strong> items on this page are selected.&ensp;<button type="button" class="link sa-select-all-btn">Select all <strong class="sa-total-count">0</strong> affiliates</button></span>
      <span class="sa-all" style="display:none">All <strong class="sa-total-count">0</strong> affiliates are selected.&ensp;<button type="button" class="link sa-clear-btn">Clear selection</button></span>
    </div>
    <div class="table-wrap">
    <table class="data-table mem-table">
      <thead>
        <tr>
          <th class="cb-col"><input type="checkbox" id="aff-select-all"></th>
          <?php if ($showSortCol): ?>
          <th class="table-col-sm">
            <a href="<?= View::e($sortUrl('sort_order')) ?>" class="sort-th<?= $sort === 'sort_order' ? ' sort-active' : '' ?>">
              Sort # <?= View::sortIcon('sort_order', $sort, $dir) ?>
            </a>
          </th>
          <?php endif; ?>
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
          <td class="cb-col" data-label="">
            <input type="checkbox" name="selected[]" value="<?= (int) $aff->id ?>"
                   class="aff-row-cb" data-name="<?= View::e($aff->name) ?>">
          </td>
          <?php if ($showSortCol): ?>
          <td class="text-dim" data-label="Sort #"><?= (int) $aff->sortOrder ?></td>
          <?php endif; ?>
          <td data-label="Name">
            <div class="subject-title-stack">
              <?= View::e($aff->name) ?>
              <?php if ($aff->url !== ''): ?>
                <a href="<?= View::e($aff->url) ?>" target="_blank" rel="noopener noreferrer"
                   class="link text-dim mem-url"><?= View::e($aff->url) ?></a>
              <?php endif; ?>
            </div>
          </td>
          <td class="text-muted" data-label="Scope">
            <?php if ($aff->listingId !== null): ?>
              <span class="badge badge-info-pink">Fanlisting</span>
              <span class="badge-sub"><?= $scopeLabel ?></span>
            <?php else: ?>
              <span class="badge badge-info">Collective</span>
            <?php endif; ?>
          </td>
          <td class="text-muted" data-label="Email">
            <?= ($aff->email !== null && $aff->email !== '') ? View::emailWrap($aff->email) : '<span class="text-dim">&mdash;</span>' ?>
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
              <button type="submit" class="btn btn-sm <?= $aff->active ? 'btn-warn' : 'btn-success' ?>"><?= $aff->active ? 'Deactivate' : 'Activate' ?></button>
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
  </div><!-- /.card -->

</form>

<?php endif; ?>

<?php if (!$reorderMode): ?>
<?php $pg_current = $page; $pg_total = $total_pages; $pg_url = $pageUrl;
include LUSTER_BASE . '/templates/admin/_pagination.php'; ?>
<?php endif; ?>
