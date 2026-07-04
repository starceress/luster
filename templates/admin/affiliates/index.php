<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Affiliates index page. Displays all affiliates in a filterable, paginated table
 with status tabs (all, pending, approved, rejected), scope filtering (all,
 collective, per-listing), search, and bulk actions for approving, rejecting, or
 deleting affiliates. Highlights pending affiliates with duplicate URLs.
*/

declare(strict_types=1);

use Luster\Core\View;

$b = rtrim(View::e($base_url), '/');

$tab           = $tab           ?? 'all';
$scope         = $scope         ?? 'all';
$search        = $search        ?? '';
$sort          = $sort          ?? 'name';
$dir           = $dir           ?? 'asc';
$page          = $page          ?? 1;
$per_page      = $per_page      ?? 10;
$total_pages   = $total_pages   ?? 1;
$total         = $total         ?? 0;
$counts        = $counts        ?? [];
$conflict_ids  = $conflict_ids  ?? [];
$filters_active = $filters_active ?? false;
$affiliates    = $affiliates    ?? [];
$listing_map   = $listing_map   ?? [];
$listings      = $listings      ?? [];

$statusTabs = [
    'all'      => ['label' => 'All',      'badge' => 'badge-inactive'],
    'pending'  => ['label' => 'Pending',  'badge' => 'badge-pending'],
    'approved' => ['label' => 'Approved', 'badge' => 'badge-current'],
    'inactive' => ['label' => 'Inactive', 'badge' => 'badge-inactive'],
    'rejected' => ['label' => 'Rejected', 'badge' => 'badge-error'],
];

$buildUrl = static function (array $overrides) use ($b, $tab, $scope, $search, $sort, $dir, $page): string {
    $defaultSort = 'name';
    $p = [
        'tab'    => $tab,
        'scope'  => $scope  !== 'all'         ? $scope  : '',
        'search' => $search,
        'sort'   => $sort   !== $defaultSort   ? $sort   : '',
        'dir'    => $dir    !== 'asc'          ? $dir    : '',
        'page'   => $page   > 1               ? $page   : '',
    ];
    $merged = array_merge($p, $overrides);
    $qs = http_build_query(array_filter($merged, static fn($v) => $v !== '' && $v !== 0 && $v !== null));
    return $b . '/admin/affiliates' . ($qs !== '' ? '?' . $qs : '');
};

$tabUrl  = static fn(string $t) => $buildUrl(['tab' => $t, 'search' => '', 'sort' => '', 'dir' => '', 'page' => '']);
$pageUrl = static fn(int $p) => $buildUrl(['page' => $p > 1 ? $p : '']);
$sortUrl = static function (string $col) use ($buildUrl, $sort, $dir): string {
    $newDir = ($sort === $col && $dir === 'asc') ? 'desc' : 'asc';
    return $buildUrl(['sort' => $col !== 'name' ? $col : '', 'dir' => $newDir !== 'asc' ? $newDir : '', 'page' => '']);
};
$resetUrl = $b . '/admin/affiliates?tab=' . View::e($tab);
$returnUrl = $buildUrl([]);

$rangeStart = $total > 0 ? ($page - 1) * $per_page + 1 : 0;
$rangeEnd   = min($total, $page * $per_page);
?>

<?php $_tableOnly = $_aff_table_only ?? false; ?>
<?php if (!$_tableOnly): ?>
<?php if ($success !== ''): ?>
  <div class="alert alert-success"><?= View::e($success) ?></div>
<?php endif; ?>
<?php if ($error !== ''): ?>
  <div class="alert alert-error"><?= View::e($error) ?></div>
<?php endif; ?>

<!-- ──── TOOLBAR ────────────────────────────────────────────────────── -->
<div class="toolbar">
  <div class="actions-row">
    <button type="button" class="btn btn-preview btn-sm filter-toggle"
            id="aff-filter-toggle" aria-expanded="true"
            aria-controls="aff-filter-panel">
      <?= View::icon('filter') ?>
      Filter<?php if ($filters_active): ?><span class="filter-dot" aria-hidden="true"></span><?php endif; ?>
    </button>
  </div>
  <div class="actions-row actions-row--right">
    <a href="<?= $b ?>/admin/affiliates/email?scope=<?= View::e($scope) ?>" class="btn btn-secondary btn-sm">Email Affiliates</a>
    <a href="<?= $b ?>/admin/affiliates/add<?= $scope !== 'all' && $scope !== 'collective' ? '?listing=' . View::e($scope) : '' ?>" class="btn btn-primary btn-sm"><?= View::icon('add') ?> Add Affiliate</a>
  </div>
</div>

<!-- ──── FILTER PANEL ───────────────────────────────────────────────── -->
<div class="filter-panel" id="aff-filter-panel">
  <form method="GET" action="<?= $b ?>/admin/affiliates" class="filter-row" id="aff-filter-form">
    <input type="hidden" name="tab" value="<?= View::e($tab) ?>">
    <?php if ($sort !== 'name'): ?><input type="hidden" name="sort" value="<?= View::e($sort) ?>"><?php endif; ?>
    <?php if ($dir  !== 'asc'):  ?><input type="hidden" name="dir"  value="<?= View::e($dir) ?>"><?php endif; ?>

    <div class="select-wrap">
      <select name="scope" id="aff-scope-sel">
        <?php $_sc = $scope_counts ?? []; $_scTotal = array_sum($_sc); ?>
        <option value="all" <?= $scope === 'all' ? 'selected' : '' ?>><?= $_scTotal > 0 ? 'All Types (' . $_scTotal . ')' : 'All Types' ?></option>
        <?php $_scColl = (int) ($_sc[0] ?? 0); ?>
        <?php $_scCollLabel = ($site_name ?? '') !== '' ? View::e($site_name) . ' (Collective)' : 'Collective'; ?>
        <option value="collective"<?= $scope === 'collective' ? ' selected' : '' ?>><?= $_scCollLabel ?><?= $_scColl > 0 ? ' (' . $_scColl . ')' : '' ?></option>
        <?php foreach ($listings as $fl): ?>
          <?php $_scN = (int) ($_sc[(int) $fl->id] ?? 0); ?>
          <option value="<?= (int) $fl->id ?>"
                  <?= $scope === (string) $fl->id ? 'selected' : '' ?>>
            <?= View::e($fl->subject ?: $fl->title) ?><?= $_scN > 0 ? ' (' . $_scN . ')' : '' ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="search-field-wrap">
      <?= View::icon('search', ['class' => 'search-field-icon', 'aria-hidden' => 'true']) ?>
      <input type="text" name="search" class="form" placeholder="Search name, URL, email&hellip;"
             value="<?= View::e($search) ?>">
    </div>
    <button type="submit" class="btn btn-secondary btn-sm">Search</button>
    <a href="<?= View::e($resetUrl) ?>" class="btn btn-quaternary btn-sm">Reset</a>
  </form>
</div>

<!-- ──── STATUS TABS ─────────────────────────────────────────────────── -->
<div class="tabs-wrap">
  <div class="tabs">
    <?php foreach ($statusTabs as $key => $tabData): ?>
      <a href="<?= View::e($tabUrl($key)) ?>"
         class="tabs-btn<?= $tab === $key ? ' active' : '' ?>">
        <?= View::e($tabData['label']) ?>
        <?php $cnt = (int) ($counts[$key] ?? 0); ?>
        <?php if ($cnt > 0): ?>
          <span class="badge <?= $tabData['badge'] ?> badge-inline-sm"><?= $cnt ?></span>
        <?php endif; ?>
      </a>
    <?php endforeach; ?>
  </div>
</div>
<div class="tabs-select-wrap">
  <span class="overview-section-title">Status</span>
  <div class="select-wrap flex-fill">
    <select class="js-nav-select">
      <?php foreach ($statusTabs as $key => $tabData): ?>
        <option value="<?= View::e($tabUrl($key)) ?>"<?= $tab === $key ? ' selected' : '' ?>>
          <?= View::e($tabData['label']) ?> (<?= (int) ($counts[$key] ?? 0) ?>)
        </option>
      <?php endforeach; ?>
    </select>
  </div>
</div>

<?php endif; // !$_tableOnly ?>

<!-- ──── TABLE ───────────────────────────────────────────────────────── -->
<?php if (empty($affiliates)): ?>
<div class="card">
  <div class="empty">
    <div class="empty-icon"><?= View::icon('link', ['width' => 32, 'height' => 32]) ?></div>
    <?php if (!$_tableOnly && ($filters_active || $tab !== 'all')): ?>
      <div><strong>No affiliates found for this filter.</strong></div>
      <a href="<?= View::e($resetUrl) ?>" class="btn btn-ghost btn-sm">Clear Filter</a>
    <?php else: ?>
      <div><strong>No affiliates yet.</strong></div>
      <?php if (!$_tableOnly): ?>
      <div>Add your first affiliate to display them on your site.</div>
      <a href="<?= $b ?>/admin/affiliates/add<?= $scope !== 'all' && $scope !== 'collective' ? '?listing=' . View::e($scope) : '' ?>" class="btn btn-primary btn-sm"><?= View::icon('add') ?> Add Affiliate</a>
      <?php endif; ?>
    <?php endif; ?>
  </div>
</div>
<?php else: ?>

<form method="POST" action="<?= $b ?>/admin/affiliates/bulk" id="aff-bulk-form"
      data-del-bulk data-del-entity="affiliate">
  <input type="hidden" name="_csrf"   value="<?= View::e($csrf_token) ?>">
  <input type="hidden" name="scope"   value="<?= View::e($scope) ?>">
  <input type="hidden" name="_return" value="<?= View::e($returnUrl) ?>">

  <?php
    // In embedded (table-only) mode drag infrastructure is always rendered;
    // visibility is toggled via JS + the 'aff-drag-off' class.
    $dragEnabled  = $_tableOnly
                    ? ($scope !== 'all')
                    : ($tab === 'approved' && $scope !== 'all' && $sort === 'sort_order' && $dir === 'asc' && $search === '');
    $showDragHint = !$_tableOnly && $tab === 'approved' && $scope !== 'all' && !$dragEnabled && $search === '';
  ?>
  <div class="card">
    <div class="card-head">
      <?php if ($_tableOnly): ?>Current Affiliates
      <?php elseif ($tab === 'all'): ?>All Affiliates
      <?php elseif ($tab === 'pending'): ?>Pending Applications
      <?php elseif ($tab === 'approved'): ?>Approved Affiliates
      <?php elseif ($tab === 'inactive'): ?>Inactive Affiliates
      <?php else: ?>Rejected Applications
      <?php endif; ?>
      <?php $_affNoun = in_array($tab, ['pending', 'rejected'], true) ? 'Application' : 'Affiliate'; ?>
      <span class="card-head-sub">Showing <?= $rangeStart ?>&ndash;<?= $rangeEnd ?> of <?= $total ?> <?= $total !== 1 ? $_affNoun . 's' : $_affNoun ?></span>
      <?php if ($_tableOnly && $dragEnabled): ?>
        <button type="button" id="aff-embed-reorder-exit"
                class="btn btn-preview btn-xs actions-row--right" style="display:none">
          <?= View::icon('grip') ?> Exit reorder
        </button>
        <button type="button" id="aff-embed-reorder-start"
                class="btn btn-preview btn-xs actions-row--right">
          <?= View::icon('grip') ?> Click to Reorder
        </button>
      <?php elseif ($dragEnabled): ?>
        <a href="<?= View::e($_aff_reorder_off_url ?? $buildUrl(['sort' => '', 'dir' => '', 'page' => ''])) ?>"
           class="btn btn-preview btn-xs actions-row--right"><?= View::icon('grip') ?> Exit reorder</a>
      <?php elseif ($showDragHint): ?>
        <a href="<?= View::e($_aff_reorder_on_url ?? $buildUrl(['sort' => 'sort_order', 'dir' => '', 'page' => ''])) ?>"
           class="btn btn-preview btn-xs actions-row--right"
           id="aff-reorder-hint-btn"
           data-reorder-url="<?= View::e($b . '/admin/affiliates/reorder') ?>"
           data-csrf="<?= View::e($csrf_token) ?>"
           data-scope="<?= View::e($scope) ?>"
           data-tab="<?= View::e($tab) ?>"
           data-page="<?= (int) $page ?>"
           data-per-page="<?= (int) $per_page ?>"><?= View::icon('grip') ?> Click to Reorder</a>
      <?php endif; ?>
    </div>

    <div class="bulk">
      Bulk Actions:
      <div class="select-wrap">
        <select name="action" class="select-xs" id="aff-bulk-action">
          <option value="" disabled selected>&mdash; Select &mdash;</option>
          <?php if (in_array($tab, ['all', 'pending'], true)): ?>
            <option value="approve">Approve</option>
            <option value="reject">Reject</option>
          <?php endif; ?>
          <?php if ($tab === 'rejected'): ?>
            <option value="re-approve">Re-approve</option>
          <?php endif; ?>
          <?php if (in_array($tab, ['approved', 'inactive', 'all'], true)): ?>
            <option value="activate">Activate</option>
            <option value="deactivate">Deactivate</option>
          <?php endif; ?>
          <?php if ($tab !== 'pending'): ?>
            <option value="delete">Delete</option>
          <?php endif; ?>
          <option value="email_selected">Email Selected</option>
        </select>
      </div>
      <button type="submit" class="btn btn-quaternary btn-sm">Apply to Selected</button>
    </div>

    <div class="table-wrap">
    <table class="data-table mem-table<?= ($_tableOnly && $dragEnabled) ? ' aff-drag-off' : '' ?>"
      <?php if ($dragEnabled): ?>
        data-aff-reorder-url="<?= View::e($b . '/admin/affiliates/reorder') ?>"
        data-aff-csrf="<?= View::e($csrf_token) ?>"
        data-aff-scope="<?= View::e($scope) ?>"
        data-aff-tab="<?= View::e($tab) ?>"
        data-aff-page="<?= (int) $page ?>"
        data-aff-per-page="<?= (int) $per_page ?>"
        <?= $_tableOnly ? 'data-aff-embed="1"' : '' ?>
      <?php endif; ?>>
      <thead>
        <tr>
          <?php if ($dragEnabled): ?><th class="table-col-xs"></th><?php endif; ?>
          <th class="checkbox-col"><input type="checkbox" id="aff-select-all"></th>
          <th>Image</th>
          <th>
            <a href="<?= View::e($sortUrl('name')) ?>"
               class="sort-th<?= $sort === 'name' ? ' sort-active' : '' ?>">
              Name <?= View::sortIcon('name', $sort, $dir) ?>
            </a>
          </th>
          <th>
            <a href="<?= View::e($sortUrl('email')) ?>"
               class="sort-th<?= $sort === 'email' ? ' sort-active' : '' ?>">
              Email <?= View::sortIcon('email', $sort, $dir) ?>
            </a>
          </th>
          <th>
            <a href="<?= View::e($sortUrl('listing')) ?>"
               class="sort-th<?= $sort === 'listing' ? ' sort-active' : '' ?>">
              Listing <?= View::sortIcon('listing', $sort, $dir) ?>
            </a>
          </th>
          <?php if ($tab === 'pending'): ?>
            <th>Application</th>
            <th>Submitted</th>
          <?php endif; ?>
          <?php if ($tab === 'all'): ?>
            <th>
              <a href="<?= View::e($sortUrl('status')) ?>"
                 class="sort-th<?= $sort === 'status' ? ' sort-active' : '' ?>">
                Status <?= View::sortIcon('status', $sort, $dir) ?>
              </a>
            </th>
          <?php endif; ?>
          <th class="actions-col">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($affiliates as $aff): ?>
        <?php
          $fl = $aff->listingId !== null ? ($listing_map[$aff->listingId] ?? null) : null;
          $isConflict = isset($conflict_ids[$aff->id]);
          $rowClass = $aff->status === 'rejected' ? 'row-inactive' : '';
        ?>
        <tr class="<?= $rowClass ?>"<?= $dragEnabled ? ' draggable="true" data-id="' . (int) $aff->id . '"' : '' ?>>
          <?php if ($dragEnabled): ?>
          <td class="table-col-xs table-cell-center" data-label="">
            <span class="drag-handle" title="Drag to reorder"><?= View::icon('grip') ?></span>
          </td>
          <?php endif; ?>
          <td class="checkbox-col">
            <input type="checkbox" name="selected[]" value="<?= (int) $aff->id ?>"
                   class="aff-row-cb" data-name="<?= View::e($aff->name) ?>">
          </td>

          <!-- Image -->
          <td data-label="Image">
            <?php if ($aff->image !== null && $aff->image !== ''): ?>
              <img src="<?= $b ?>/uploads/affiliates/<?= View::e($aff->image) ?>"
                   alt="" style="max-width:200px;max-height:200px;display:block;border-radius:3px;object-fit:contain">
            <?php else: ?>
              <span class="text-dim">&mdash;</span>
            <?php endif; ?>
          </td>

          <!-- Name + URL -->
          <td data-label="Name">
            <div class="subject-title-stack">
              <b><?= View::e($aff->name) ?></b>
              <?php if ($aff->status === 'approved' && !$aff->active): ?>
                <span class="badge badge-inactive">Inactive</span>
              <?php endif; ?>
              <?php if ($isConflict): ?>
                <span class="badge badge-inactive" title="An active affiliate with this URL already exists for this listing.">Duplicate URL</span>
              <?php endif; ?>
              <?php if ($aff->url !== ''): ?>
                <a href="<?= View::e($aff->url) ?>" target="_blank" rel="noopener noreferrer"
                   class="mem-url link link-break"><?= View::e(mb_strimwidth($aff->url, 0, 60, '…')) ?></a>
              <?php endif; ?>
            </div>
          </td>

          <!-- Email -->
          <td class="text-muted" data-label="Email">
            <?= ($aff->email !== null && $aff->email !== '') ? View::emailWrap($aff->email) : '<span class="text-dim">&mdash;</span>' ?>
          </td>

          <!-- Listing -->
          <td data-label="Listing">
            <?php if ($fl !== null): ?>
              <a href="<?= $b ?>/admin/listings/<?= (int) $fl->id ?>/edit" class="link">
                <?= View::e($fl->subject ?: $fl->title) ?>
              </a>
              <div class="mem-url">Listing</div>
            <?php else: ?>
              <a href="<?= $b ?>/admin/collective" class="link text-strong"><?= ($site_name ?? '') !== '' ? View::e($site_name) : 'Collective' ?></a>
              <div class="mem-url">Collective</div>
            <?php endif; ?>
          </td>

          <!-- Pending-only: Message + Submitted -->
          <?php if ($tab === 'pending'): ?>
          <td data-label="Application">
            <?php $msg = (string) ($aff->applicationMessage ?? ''); ?>
            <button type="button" class="link js-aff-msg-open"
                    data-aff-name="<?= View::e($aff->name) ?>"
                    data-aff-url="<?= View::e($aff->url) ?>"
                    data-aff-email="<?= View::e($aff->email ?? '') ?>"
                    data-aff-image="<?= ($aff->image !== null && $aff->image !== '') ? View::e($b . '/uploads/affiliates/' . $aff->image) : '' ?>"
                    data-aff-listing="<?= $fl !== null ? View::e($fl->subject ?: $fl->title) : View::e(($site_name ?? '') !== '' ? $site_name : 'Collective') ?>"
                    data-aff-date="<?= $aff->createdAt !== '' ? View::e(date($admin_date_format, strtotime($aff->createdAt))) : '' ?>"
                    data-aff-msg="<?= View::e($msg) ?>"><?= $msg !== '' ? 'Click to Read' : 'View Details' ?></button>
          </td>
          <td class="text-dim nowrap" data-label="Submitted">
            <?= $aff->createdAt !== '' ? View::e(date($admin_date_format, strtotime($aff->createdAt))) : '&mdash;' ?>
          </td>
          <?php endif; ?>


          <!-- All-tab: Status -->
          <?php if ($tab === 'all'): ?>
          <td data-label="Status">
            <?php if ($aff->status === 'pending'): ?>
              <span class="badge badge-pending">Pending</span>
            <?php elseif ($aff->status === 'approved'): ?>
              <span class="badge badge-current">Approved</span>
            <?php else: ?>
              <span class="badge badge-error">Rejected</span>
            <?php endif; ?>
          </td>
          <?php endif; ?>

          <!-- Actions: Approve / Reject / Edit / Email / Delete -->
          <td class="actions-col" data-label="">
            <div class="actions-row">
              <?php if ($aff->status === 'pending'): ?>
                <form method="POST" action="<?= $b ?>/admin/affiliates/<?= (int) $aff->id ?>/approve"
                      class="action-form-inline">
                  <input type="hidden" name="_csrf"   value="<?= View::e($csrf_token) ?>">
                  <input type="hidden" name="_return" value="<?= View::e($returnUrl) ?>">
                  <button type="submit" class="btn btn-success btn-xs">Approve</button>
                </form>
                <form method="POST" action="<?= $b ?>/admin/affiliates/<?= (int) $aff->id ?>/reject"
                      class="action-form-inline"
                      data-del-modal data-del-entity="affiliate_application"
                      data-del-name="<?= View::e($aff->name) ?>">
                  <input type="hidden" name="_csrf"   value="<?= View::e($csrf_token) ?>">
                  <input type="hidden" name="_return" value="<?= View::e($returnUrl) ?>">
                  <button type="submit" class="btn btn-danger btn-xs">Reject</button>
                </form>
              <?php endif; ?>

              <?php if ($aff->status === 'rejected'): ?>
                <form method="POST" action="<?= $b ?>/admin/affiliates/<?= (int) $aff->id ?>/re-approve"
                      class="action-form-inline">
                  <input type="hidden" name="_csrf"   value="<?= View::e($csrf_token) ?>">
                  <input type="hidden" name="_return" value="<?= View::e($returnUrl) ?>">
                  <button type="submit" class="btn btn-success btn-xs">Re-approve</button>
                </form>
              <?php endif; ?>

              <a href="<?= $b ?>/admin/affiliates/<?= (int) $aff->id ?>/edit<?= $_tableOnly && is_numeric($scope) ? '?from_listing=' . (int) $scope : '' ?>"
                 class="btn btn-tertiary btn-xs">Edit</a>

              <?php if ($aff->email !== null && $aff->email !== ''): ?>
                <button type="submit" form="aff-email-<?= (int) $aff->id ?>"
                        class="btn btn-secondary btn-xs">Email</button>
              <?php endif; ?>

              <form method="POST" action="<?= $b ?>/admin/affiliates/<?= (int) $aff->id ?>/delete"
                    class="action-form-inline"
                    data-del-modal data-del-entity="affiliate"
                    data-del-name="<?= View::e($aff->name) ?>">
                <input type="hidden" name="_csrf" value="<?= View::e($csrf_token) ?>">
                <button type="submit" class="btn btn-danger btn-xs">Delete</button>
              </form>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  </div>
</form>

<?php if ($total_pages > 1):
    $pg_current = $page; $pg_total = $total_pages; $pg_url = $pageUrl;
    include LUSTER_BASE . '/templates/admin/_pagination.php';
endif; ?>

<?php foreach ($affiliates as $aff): ?>
  <?php if ($aff->email !== null && $aff->email !== ''): ?>
    <form id="aff-email-<?= (int) $aff->id ?>"
          method="POST" action="<?= $b ?>/admin/affiliates/bulk">
      <input type="hidden" name="_csrf"      value="<?= View::e($csrf_token) ?>">
      <input type="hidden" name="action"     value="email_selected">
      <input type="hidden" name="scope"      value="<?= View::e($scope) ?>">
      <input type="hidden" name="_return"    value="<?= View::e($returnUrl) ?>">
      <input type="hidden" name="selected[]" value="<?= (int) $aff->id ?>">
    </form>
  <?php endif; ?>
<?php endforeach; ?>

<?php endif; ?>

<!-- Affiliate application modal -->
<div class="modal-backdrop is-hidden" id="aff-msg-modal" role="dialog" aria-modal="true" aria-labelledby="aff-msg-modal-title" data-managed-close>
  <div class="modal modal--wide">
    <div class="modal-header">
      <h3 class="modal-title" id="aff-msg-modal-title">Affiliate Application</h3>
      <button type="button" class="modal-close" aria-label="Close">&times;</button>
    </div>
    <div class="modal-body">
      <div id="aff-msg-modal-image" style="margin-bottom:.75rem;text-align:center" hidden></div>
      <table class="data-table" style="margin-bottom:.75rem">
        <tbody>
          <tr><th style="width:100px">Name</th><td id="aff-msg-modal-name"></td></tr>
          <tr><th>URL</th><td id="aff-msg-modal-url"></td></tr>
          <tr><th>Email</th><td id="aff-msg-modal-email"></td></tr>
          <tr><th>Listing</th><td id="aff-msg-modal-listing"></td></tr>
          <tr><th>Submitted</th><td id="aff-msg-modal-date"></td></tr>
        </tbody>
      </table>
      <div id="aff-msg-modal-msg-wrap">
        <div style="font-weight:600;margin-bottom:.35rem;color:var(--text)">Message</div>
        <div id="aff-msg-modal-body" style="white-space:pre-wrap;color:var(--text-muted);line-height:1.5"></div>
      </div>
    </div>
  </div>
</div>
