<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Keep In Mind (KIM) admin overview. Displays all KIM members across listings in a
 unified tabbed view (all, pending, approved, granted) with per-listing filtering,
 search, sortable columns, pagination, and bulk actions for approving, emailing,
 or deleting entries.
*/

/**
 * Variables:
 *   $members          KimMember[]
 *   $fanlistings      Listing[]
 *   $listing_map      array<int, string>   id => subject
 *   $selected_listing ?Listing
 *   $listing_id       int                  0 = all listings
 *   $tab              string               all|pending|approved|granted
 *   $counts           array{all:int, pending:int, approved:int, granted:int}
 *   $search           string
 *   $filters_active   bool
 *   $sort             string               name|status|listing|added_at
 *   $dir              string               asc|desc
 *   $page             int
 *   $total_pages      int
 *   $total            int
 *   $per_page         int
 *   $global_pending   int
 *   $kim_section      string
 *   $success          string
 *   $error            string
 *   $base_url         string
 *   $csrf_token       string
 */

declare(strict_types=1);

use Luster\Core\View;

$b = rtrim(View::e($base_url), '/');

$statusTabs = [
    'all'      => ['label' => 'All',      'badge' => 'badge-inactive'],
    'pending'  => ['label' => 'Pending',  'badge' => 'badge-pending'],
    'approved' => ['label' => 'Approved', 'badge' => 'badge-current'],
    'granted'  => ['label' => 'Granted',  'badge' => 'badge-redirect'],
];

// Always include tab in URL so 'all' is honoured explicitly
$buildUrl = static function (array $overrides) use ($b, $tab, $listing_id, $search, $sort, $dir): string {
    $p = [
        'tab'        => $tab,
        'listing_id' => $listing_id > 0 ? $listing_id : '',
        'search'     => $search,
        'sort'       => $sort !== 'added_at' ? $sort : '',
        'dir'        => $dir  !== 'desc'     ? $dir  : '',
    ];
    $merged = array_merge($p, $overrides);
    $qs = http_build_query(array_filter(
        $merged,
        static fn($v) => $v !== '' && $v !== 0 && $v !== null
    ));
    return $b . '/admin/kim' . ($qs !== '' ? '?' . $qs : '');
};

// tabUrl always writes an explicit tab= so the controller doesn't auto-default
$tabUrl  = static fn(string $t) => $buildUrl(['tab' => $t, 'page' => '']);
$pageUrl = static fn(int $p)    => $buildUrl(['page' => $p > 1 ? $p : '']);
$resetUrl = $b . '/admin/kim?tab=' . View::e($tab);

// Sort header URL: flip direction if already sorted by this column
$sortUrl = static function (string $col) use ($buildUrl, $sort, $dir): string {
    $newDir = ($sort === $col && $dir === 'asc') ? 'desc' : 'asc';
    return $buildUrl(['sort' => $col !== 'added_at' ? $col : '', 'dir' => $newDir !== 'desc' ? $newDir : '', 'page' => '']);
};

$hasBulkApprove  = in_array($tab, ['all', 'pending'], true);
$nonPendingCount = ($counts['approved'] ?? 0) + ($counts['granted'] ?? 0);
$showEmailBulk   = true;

// Bulk form action + return URL
$bulkAction = $listing_id > 0
    ? $b . '/admin/kim/' . $listing_id . '/bulk'
    : $b . '/admin/kim/bulk';
$returnUrl  = $buildUrl([]);

$rangeStart = $total > 0 ? ($page - 1) * $per_page + 1 : 0;
$rangeEnd   = min($total, $page * $per_page);
$dateFmt    = $admin_date_format ?? 'm-d-Y';
?>

<?php if ($success !== ''): ?>
  <div class="alert alert-success"><?= View::e($success) ?></div>
<?php endif; ?>
<?php if ($error !== ''): ?>
  <div class="alert alert-error"><?= View::e($error) ?></div>
<?php endif; ?>

<!-- ──── TOOLBAR ────────────────────────────────────────────────── -->
<div class="toolbar">
  <div class="actions-row">
    <button type="button" class="btn btn-preview btn-sm filter-toggle"
            id="kim-filter-toggle"
            aria-expanded="true"
            aria-controls="kim-filter-panel">
      <?= View::icon('filter') ?>
      Filter<?php if ($filters_active): ?><span class="filter-dot" aria-hidden="true"></span><?php endif; ?>
    </button>
  </div>
  <div class="actions-row actions-row--right">
    <a href="<?= $b ?>/admin/kim/listings" class="btn btn-primary btn-sm">Toggle Listing View</a>
    <a href="<?= $b ?>/admin/kim/add" class="btn btn-secondary btn-sm"><?= View::icon('add') ?> Add KIM Entry</a>
    <a href="<?= $b ?>/admin/kim/settings" class="btn btn-quaternary btn-sm"><?= View::icon('gear') ?>KIM Settings</a>
  </div>
</div>

<!-- ──── FILTER PANEL ────────────────────────────────────────────── -->
<div class="filter-panel" id="kim-filter-panel">
  <form method="GET" action="<?= $b ?>/admin/kim" class="filter-row" id="kim-filter-form">
    <input type="hidden" name="tab" value="<?= View::e($tab) ?>">
    <?php if ($sort !== 'added_at'): ?><input type="hidden" name="sort" value="<?= View::e($sort) ?>"><?php endif; ?>
    <?php if ($dir !== 'desc'):      ?><input type="hidden" name="dir"  value="<?= View::e($dir) ?>"><?php endif; ?>

    <?php if (!empty($fanlistings)): ?>
    <div class="select-wrap">
      <select name="listing_id" id="kim-listing-sel">
        <?php $_kimCounts = $kim_listing_counts ?? []; $_kimTotal = array_sum($_kimCounts); ?>
        <option value="0"><?= $_kimTotal > 0 ? 'All Listings (' . $_kimTotal . ')' : 'All Listings' ?></option>
        <?php foreach ($fanlistings as $fl): ?>
          <?php $_kimN = (int) ($_kimCounts[(int) $fl->id] ?? 0); ?>
          <option value="<?= (int) $fl->id ?>"
                  <?= $listing_id === $fl->id ? 'selected' : '' ?>>
            <?= View::e($fl->subject) ?><?= $_kimN > 0 ? ' (' . $_kimN . ')' : '' ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <?php endif; ?>

    <div class="search-field-wrap">
      <?= View::icon('search', ['class' => 'search-field-icon', 'aria-hidden' => 'true']) ?>
      <input type="text" name="search" class="form" placeholder="Search by name or email&hellip;"
             value="<?= View::e($search) ?>">
    </div>
    <button type="submit" class="btn btn-secondary btn-sm">Search</button>
    <a href="<?= View::e($resetUrl) ?>" class="btn btn-quaternary btn-sm">Reset</a>
  </form>
</div>

<!-- ──── STATUS TABS ─────────────────────────────────────────────── -->
<div class="tabs-wrap">
  <div class="tabs">
    <?php foreach ($statusTabs as $key => $tabData): ?>
      <a href="<?= View::e($tabUrl($key)) ?>"
         class="tabs-btn<?= $tab === $key ? ' active' : '' ?>">
        <?= View::e($tabData['label']) ?>
        <?php if ($counts[$key] > 0): ?>
          <span class="badge <?= $tabData['badge'] ?> badge-inline-sm"><?= (int) $counts[$key] ?></span>
        <?php endif; ?>
      </a>
    <?php endforeach; ?>
  </div>
  <div class="tabs-select-wrap">
    <span class="overview-section-title">Status</span>
    <div class="select-wrap flex-fill">
      <select onchange="window.location.href=this.value">
        <?php foreach ($statusTabs as $key => $tabData): ?>
          <option value="<?= View::e($tabUrl($key)) ?>"
                  <?= $tab === $key ? 'selected' : '' ?>>
            <?= View::e($tabData['label']) ?> (<?= (int) $counts[$key] ?>)
          </option>
        <?php endforeach; ?>
      </select>
    </div>
  </div>
</div>

<!-- ──── TABLE ───────────────────────────────────────────────────── -->
<?php if (empty($members)): ?>
<div class="card">
  <div class="empty">
    <div class="empty-icon"><?= View::icon('kim', ['width' => 32, 'height' => 32]) ?></div>
    <?php if ($filters_active || $tab !== 'all'): ?>
      <div class="empty-title">No members found for this filter.</div>
    <?php else: ?>
      <div class="empty-title">No KIM members yet.</div>
      <div>Members who sign up via the KIM form will appear here for review.</div>
    <?php endif; ?>
  </div>
</div>
<?php else: ?>

<form method="POST" action="<?= View::e($bulkAction) ?>"
      data-del-bulk data-del-entity="kim_member"
      data-del-context="<?= $selected_listing !== null ? View::e($selected_listing->subject) : 'selected members' ?>">
  <input type="hidden" name="_csrf"   value="<?= View::e($csrf_token) ?>">
  <input type="hidden" name="_return" value="<?= View::e($returnUrl) ?>">

<div class="card">
  <div class="card-head">
    <?php if ($tab === 'all'): ?>All Sign-Ups
    <?php elseif ($tab === 'pending'): ?>Pending Sign-Ups
    <?php elseif ($tab === 'approved'): ?>Approved Sign-Ups
    <?php else: ?>Granted Sign-Ups
    <?php endif; ?>
    <?php if ($selected_listing !== null): ?>&mdash; <?= View::e($selected_listing->subject) ?><?php endif; ?>
    <span class="card-head-sub">Showing <?= $rangeStart ?>&ndash;<?= $rangeEnd ?> of <?= $total ?> Sign-Up<?= $total !== 1 ? 's' : '' ?></span>
    <?php
      // Use unfiltered per-listing count so the badge is not scoped by active search.
      $_kimEmailAllN = (int) ($kim_listing_counts[$listing_id] ?? 0);
    ?>
    <?php if ($listing_id > 0 && $_kimEmailAllN > 0): ?>
      <a href="<?= $b ?>/admin/kim/<?= $listing_id ?>/bulk-email"
         class="btn btn-secondary btn-sm actions-row--right">
        Email All (<?= $_kimEmailAllN ?>)
      </a>
    <?php endif; ?>
  </div>

  <div class="bulk bulk--flush">
    Bulk Actions:
    <div class="select-wrap">
      <select name="bulk_action" class="select-xs" id="kim-bulk-action">
        <option value="" disabled selected>&mdash; Select &mdash;</option>
        <?php if ($hasBulkApprove): ?>
          <option value="approve">Approve</option>
        <?php endif; ?>
        <?php if ($showEmailBulk): ?>
          <option value="email_selected">Email</option>
        <?php endif; ?>
        <option value="delete">Delete</option>
      </select>
    </div>
    <button type="submit" class="btn btn-quaternary btn-sm">Apply to Selected</button>
  </div>

  <div class="table-wrap">
  <table class="data-table mem-table">
    <thead>
      <tr>
        <th class="checkbox-col">
          <input type="checkbox" class="sel-all" data-fid="kim-members">
        </th>
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
        <?php if ($listing_id === 0): ?>
        <th>
          <a href="<?= View::e($sortUrl('listing')) ?>"
             class="sort-th<?= $sort === 'listing' ? ' sort-active' : '' ?>">
            Listing <?= View::sortIcon('listing', $sort, $dir) ?>
          </a>
        </th>
        <?php endif; ?>
        <?php if ($tab === 'all'): ?>
        <th>
          <a href="<?= View::e($sortUrl('status')) ?>"
             class="sort-th<?= $sort === 'status' ? ' sort-active' : '' ?>">
            Status <?= View::sortIcon('status', $sort, $dir) ?>
          </a>
        </th>
        <?php endif; ?>
        <th>
          <a href="<?= View::e($sortUrl('added_at')) ?>"
             class="sort-th<?= $sort === 'added_at' ? ' sort-active' : '' ?>">
            Signed Up <?= View::sortIcon('added_at', $sort, $dir) ?>
          </a>
        </th>
        <th class="actions-col">Actions</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($members as $m): ?>
      <tr>
        <td class="checkbox-col">
          <input type="checkbox" class="row-cb-kim-members"
                 name="ids[]" value="<?= (int) $m->id ?>"
                 data-name="<?= View::e($m->name) ?>">
        </td>

        <td data-label="Name">
          <div class="subject-title-stack">
            <b><?= View::e($m->name) ?></b>
            <?php if ($m->url !== ''): ?>
              <a href="<?= View::e($m->url) ?>" target="_blank" rel="noopener noreferrer"
                 class="mem-url link"><?= View::e($m->url) ?></a>
            <?php endif; ?>
          </div>
        </td>

        <td data-label="Email" class="text-muted">
          <?= View::emailWrap($m->email) ?>
        </td>

        <?php if ($listing_id === 0): ?>
        <td data-label="Listing">
          <?php $_kimFl = $listing_map[$m->fanlistingId] ?? null; ?>
          <?php if ($_kimFl !== null): ?>
            <div class="subject-title-stack">
              <a href="<?= $b ?>/admin/listings/<?= (int) $m->fanlistingId ?>/edit" class="link"><b><?= View::e($_kimFl->subject ?: $_kimFl->title) ?></b></a>
              <?php if ($_kimFl->subject !== ''): ?>
                <span class="mem-url"><?= View::e($_kimFl->title) ?></span>
              <?php endif; ?>
            </div>
          <?php else: ?>
            <span class="text-dim">—</span>
          <?php endif; ?>
        </td>
        <?php endif; ?>

        <?php if ($tab === 'all'): ?>
        <td data-label="Status">
          <?php if ($m->pending): ?>
            <span class="badge badge-pending">Pending</span>
          <?php elseif ($m->granted): ?>
            <span class="badge badge-redirect">Granted</span>
          <?php else: ?>
            <span class="badge badge-current">Approved</span>
          <?php endif; ?>
        </td>
        <?php endif; ?>

        <td class="text-dim nowrap" data-label="Signed Up">
          <?= View::e(date($dateFmt, strtotime($m->addedAt))) ?>
        </td>

        <td class="actions-col" data-label="">
          <div class="actions-row">
            <?php if ($m->pending): ?>
              <button type="submit" form="approve-kim-<?= (int) $m->id ?>"
                      class="btn btn-success btn-xs">Approve</button>
            <?php endif; ?>
            <a href="<?= $b ?>/admin/kim/<?= (int) $m->id ?>/edit"
               class="btn btn-tertiary btn-xs">Edit</a>
            <a href="<?= $b ?>/admin/kim/<?= (int) $m->id ?>/email-member"
               class="btn btn-secondary btn-xs">Email</a>
            <button type="submit" form="del-kim-<?= (int) $m->id ?>"
                    class="btn btn-danger btn-xs">Delete</button>
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

<?php foreach ($members as $m): ?>
  <?php if ($m->pending): ?>
  <form id="approve-kim-<?= (int) $m->id ?>" method="POST"
        action="<?= $b ?>/admin/kim/<?= (int) $m->id ?>/approve"
        class="action-form-inline">
    <input type="hidden" name="_csrf"    value="<?= View::e($csrf_token) ?>">
    <input type="hidden" name="from_fid" value="<?= (int) $m->fanlistingId ?>">
  </form>
  <?php endif; ?>
  <form id="del-kim-<?= (int) $m->id ?>" method="POST"
        action="<?= $b ?>/admin/kim/<?= (int) $m->id ?>/delete"
        data-del-modal data-del-entity="kim_member"
        data-del-name="<?= View::e($m->name) ?>">
    <input type="hidden" name="_csrf" value="<?= View::e($csrf_token) ?>">
  </form>
<?php endforeach; ?>

<?php endif; ?>
