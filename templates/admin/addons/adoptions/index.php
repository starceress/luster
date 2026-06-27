<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Adoptions admin overview. Displays adoption applications across all listings in a
 tabbed interface (all, pending, approved, rejected, granted). Includes
 per-listing filtering, search, sortable columns, and bulk actions for managing
 adoption requests.
*/

declare(strict_types=1);

use Luster\Core\View;

/**
 * @var string                    $tab              'all'|'pending'|'approved'|'rejected'|'granted'|'listings'|'settings'
 * @var array[]                   $applications     Filtered + sorted applications (app view)
 * @var array                     $counts           {all, pending, approved, rejected, granted}
 * @var \Luster\Models\Listing[]  $fanlistings      All listings (for dropdown)
 * @var array                     $listing_map      [id => Listing]
 * @var int                       $listing_id       Active listing filter (0 = all)
 * @var string                    $search           Active search query
 * @var bool                      $filters_active
 * @var string                    $sort             Active sort column
 * @var string                    $dir              'asc'|'desc'
 * @var array[]                   $adoption_listings (listings view)
 * @var string                    $success
 * @var string                    $error
 * @var string                    $base_url
 * @var string                    $csrf_token
 */

$b         = rtrim(View::e($base_url), '/');
$appTabs   = ['all', 'pending', 'approved', 'rejected', 'granted'];
$isAppView = in_array($tab, $appTabs, true);

$listing_id      = $listing_id      ?? 0;
$search          = $search          ?? '';
$sort            = $sort            ?? 'applied_at';
$dir             = $dir             ?? 'desc';
$filters_active  = $filters_active  ?? false;
$applications    = $applications    ?? [];
$counts          = $counts          ?? [];
$listing_map     = $listing_map     ?? [];
$fanlistings     = $fanlistings     ?? [];
$selected_listing = $selected_listing ?? null;
$page            = $page            ?? 1;
$total_pages     = $total_pages     ?? 1;
$total           = $total           ?? 0;
$per_page        = $per_page        ?? 10;

// Canonical tabs
$statusTabs = [
    'all'      => 'All',
    'pending'  => 'Pending',
    'approved' => 'Approved',
    'rejected' => 'Rejected',
    'granted'  => 'Granted',
];

// Full label map — 'new' kept as legacy fallback for un-migrated rows
$statusLabels = [
    'all'      => 'All',
    'pending'  => 'Pending',
    'new'      => 'Pending',   // legacy: pre-migration rows
    'approved' => 'Approved',
    'rejected' => 'Rejected',
    'granted'  => 'Granted',
];
$statusBadge = [
    'pending'  => 'badge-pending',
    'new'      => 'badge-pending',  // legacy
    'approved' => 'badge-current',
    'rejected' => 'badge-error',
    'granted'  => 'badge-redirect',
];

$page = $page ?? 1;
$buildUrl = static function (array $overrides) use ($b, $tab, $listing_id, $search, $sort, $dir, $page): string {
    $p = [
        'tab'        => $tab,
        'listing_id' => $listing_id > 0 ? $listing_id : '',
        'search'     => $search,
        'sort'       => $sort !== 'applied_at' ? $sort : '',
        'dir'        => $dir  !== 'desc'       ? $dir  : '',
        'page'       => $page > 1 ? $page : '',
    ];
    $merged = array_merge($p, $overrides);
    $qs = http_build_query(array_filter(
        $merged,
        static fn($v) => $v !== '' && $v !== 0 && $v !== null
    ));
    return $b . '/admin/adoptions' . ($qs !== '' ? '?' . $qs : '');
};

$tabUrl   = static fn(string $t) => $buildUrl(['tab' => $t, 'search' => '', 'sort' => '', 'dir' => '', 'page' => '']);
$resetUrl = $b . '/admin/adoptions?tab=' . View::e($isAppView ? $tab : 'all') . ($listing_id > 0 ? '&listing_id=' . $listing_id : '');
$returnUrl = $buildUrl([]);

$sortUrl = static function (string $col) use ($buildUrl, $sort, $dir): string {
    $newDir = ($sort === $col && $dir === 'asc') ? 'desc' : 'asc';
    return $buildUrl(['sort' => $col !== 'applied_at' ? $col : '', 'dir' => $newDir !== 'desc' ? $newDir : '', 'page' => '']);
};
?>

<?php if ($success !== ''): ?>
  <div class="alert alert-success"><?= View::e($success) ?></div>
<?php endif; ?>
<?php if ($error !== ''): ?>
  <div class="alert alert-error"><?= View::e($error) ?></div>
<?php endif; ?>

<!-- ──── TOOLBAR ─────────────────────────────────────────────────── -->
<div class="toolbar">
  <div class="actions-row">
    <?php if ($isAppView): ?>
    <button type="button" class="btn btn-preview btn-sm filter-toggle"
            id="adopt-filter-toggle"
            aria-expanded="true"
            aria-controls="adopt-filter-panel">
      <?= View::icon('filter') ?>
      Filter<?php if ($filters_active): ?><span class="filter-dot" aria-hidden="true"></span><?php endif; ?>
    </button>
    <?php endif; ?>
  </div>
  <div class="actions-row actions-row--right">
    <?php if ($tab === 'listings'): ?>
      <a href="<?= $b ?>/admin/adoptions" class="btn btn-primary btn-sm">All Applications</a>
    <?php else: ?>
      <a href="<?= View::e($tabUrl('listings')) ?>" class="btn btn-primary btn-sm">Toggle Listing View</a>
    <?php endif; ?>
    <a href="<?= $b ?>/admin/adoptions/settings" class="btn btn-quaternary btn-sm"><?= View::icon('gear') ?>Adoptions Settings</a>
  </div>
</div>

<?php if ($isAppView): ?>
<!-- ──── FILTER PANEL ────────────────────────────────────────────── -->
<div class="filter-panel" id="adopt-filter-panel">
  <form method="GET" action="<?= $b ?>/admin/adoptions" class="filter-row" id="adopt-filter-form">
    <input type="hidden" name="tab" value="<?= View::e($tab) ?>">
    <?php if ($sort !== 'applied_at'): ?><input type="hidden" name="sort" value="<?= View::e($sort) ?>"><?php endif; ?>
    <?php if ($dir  !== 'desc'):      ?><input type="hidden" name="dir"  value="<?= View::e($dir) ?>"><?php endif; ?>

    <?php if (!empty($fanlistings)): ?>
    <div class="select-wrap">
      <?php
        $_adoptCounts = $adoption_listing_counts ?? [];
        $_adoptTotal  = array_sum(array_map(
            static fn(array $c): int => (int) ($c['approved'] ?? 0) + (int) ($c['granted'] ?? 0),
            $_adoptCounts
        ));
      ?>
      <select name="listing_id" id="adopt-listing-sel">
        <option value="0"><?= $_adoptTotal > 0 ? 'All Listings (' . $_adoptTotal . ')' : 'All Listings' ?></option>
        <?php foreach ($fanlistings as $fl): ?>
          <?php
            $_lc    = $_adoptCounts[(int) $fl->id] ?? [];
            $_adoptN = (int) ($_lc['approved'] ?? 0) + (int) ($_lc['granted'] ?? 0);
          ?>
          <option value="<?= (int) $fl->id ?>"
                  <?= $listing_id === $fl->id ? 'selected' : '' ?>>
            <?= View::e($fl->subject ?: $fl->title) ?><?= $_adoptN > 0 ? ' (' . $_adoptN . ')' : '' ?>
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
    <?php foreach ($statusTabs as $key => $label): ?>
      <a href="<?= View::e($tabUrl($key)) ?>"
         class="tabs-btn<?= $tab === $key ? ' active' : '' ?>">
        <?= View::e($label) ?>
        <?php
          $cnt      = (int) ($counts[$key] ?? 0);
          $badgeCls = $key === 'all' ? 'badge-inactive' : ($statusBadge[$key] ?? 'badge-inactive');
        ?>
        <?php if ($cnt > 0): ?>
          <span class="badge <?= $badgeCls ?> badge-inline-sm"><?= $cnt ?></span>
        <?php endif; ?>
      </a>
    <?php endforeach; ?>
  </div>
</div>
<div class="tabs-select-wrap">
  <span class="overview-section-title">Status</span>
  <div class="select-wrap flex-fill">
    <select onchange="window.location.href=this.value">
      <?php foreach ($statusTabs as $key => $label): ?>
        <option value="<?= View::e($tabUrl($key)) ?>"<?= $tab === $key ? ' selected' : '' ?>>
          <?= View::e($label) ?> (<?= (int) ($counts[$key] ?? 0) ?>)
        </option>
      <?php endforeach; ?>
    </select>
  </div>
</div>

<!-- ──── APPLICATIONS TABLE ──────────────────────────────────────── -->
<?php if (empty($applications)): ?>
  <div class="card">
    <div class="empty">
      <div class="empty-icon"><?= View::icon('check-badge', ['width' => 32, 'height' => 32]) ?></div>
      <?php if ($filters_active || $tab !== 'all'): ?>
        <div class="empty-title">No applications found.</div>
        <a href="<?= View::e($resetUrl) ?>" class="btn btn-ghost btn-sm">Clear Filter</a>
      <?php else: ?>
        <div class="empty-title">No applications yet.</div>
        <div>Fans who submit adoption applications will appear here.</div>
      <?php endif; ?>
    </div>
  </div>
<?php else: ?>

<form method="POST" action="<?= $b ?>/admin/adoptions/bulk"
      data-del-bulk data-del-entity="adoption_application"
      data-del-context="applications">
  <input type="hidden" name="_csrf"    value="<?= View::e($csrf_token) ?>">
  <input type="hidden" name="_return"  value="<?= View::e($returnUrl) ?>">

  <?php
    $rangeStart = $total > 0 ? ($page - 1) * $per_page + 1 : 0;
    $rangeEnd   = min($total, $page * $per_page);
  ?>
  <div class="card">
    <div class="card-head">
      <?php if ($tab === 'all'): ?>All Applications
      <?php elseif ($tab === 'pending'): ?>Pending Applications
      <?php elseif ($tab === 'approved'): ?>Approved Applications
      <?php elseif ($tab === 'rejected'): ?>Rejected Applications
      <?php else: ?>Granted Applications
      <?php endif; ?>
      <?php if ($selected_listing !== null): ?>&mdash; <?= View::e($selected_listing->subject ?: $selected_listing->title) ?><?php endif; ?>
      <span class="card-head-sub">Showing <?= $rangeStart ?>&ndash;<?= $rangeEnd ?> of <?= $total ?> Application<?= $total !== 1 ? 's' : '' ?></span>
      <?php
        // Use unfiltered per-listing totals so the badge is not scoped by active search.
        $_alc = $adoption_listing_counts[$listing_id] ?? [];
        $emailAllCount = (int) ($_alc['approved'] ?? 0) + (int) ($_alc['granted'] ?? 0);
        if ($listing_id > 0 && $emailAllCount > 0):
      ?>
        <a href="<?= $b ?>/admin/adoptions/<?= (int) $listing_id ?>/email-all"
           class="btn btn-secondary btn-sm actions-row--right">
          Email All (<?= $emailAllCount ?>)
        </a>
      <?php endif; ?>
    </div>

    <div class="bulk bulk--flush">
      Bulk Actions:
      <div class="select-wrap">
        <select name="bulk_action" class="select-xs" id="adopt-bulk-action">
          <option value="" disabled selected>&mdash; Select &mdash;</option>
          <option value="email">Email</option>
          <option value="approve">Approve</option>
          <option value="reject">Reject</option>
          <option value="delete">Delete</option>
        </select>
      </div>
      <button type="submit" class="btn btn-quaternary btn-sm">Apply to Selected</button>
    </div>

    <div class="table-wrap">
      <table class="data-table mem-table">
        <thead>
          <tr>
            <th class="checkbox-col"><input type="checkbox" class="sel-all" data-fid="adopt-apps"></th>
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
            <?php if ($tab === 'pending'): ?>
            <th>Application</th>
            <?php endif; ?>
            <th>
              <a href="<?= View::e($sortUrl('applied_at')) ?>"
                 class="sort-th<?= $sort === 'applied_at' ? ' sort-active' : '' ?>">
                Applied <?= View::sortIcon('applied_at', $sort, $dir) ?>
              </a>
            </th>
            <th class="actions-col">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($applications as $app): ?>
          <?php
            $appId    = (int) $app['id'];
            $fl       = $listing_map[(int) $app['fanlisting_id']] ?? null;
            $isPending  = $app['status'] === 'pending';
            $isRejected = $app['status'] === 'rejected';
          ?>
          <tr<?= $isRejected ? ' class="row-inactive"' : '' ?>>
            <td class="checkbox-col">
              <input type="checkbox" class="row-cb-adopt-apps"
                     name="ids[]" value="<?= $appId ?>"
                     data-name="<?= View::e($app['name']) ?>">
            </td>

            <td data-label="Name">
              <div class="subject-title-stack">
                <b><?= View::e($app['name']) ?></b>
                <?php if ($app['url'] !== ''): ?>
                  <a href="<?= View::e($app['url']) ?>" target="_blank" rel="noopener noreferrer"
                     class="mem-url link link-break"><?= View::e($app['url']) ?></a>
                <?php endif; ?>
              </div>
            </td>

            <td data-label="Email" class="text-muted">
              <?= View::emailWrap($app['email']) ?>
            </td>

            <?php if ($listing_id === 0): ?>
            <td data-label="Listing">
              <?php if ($fl !== null): ?>
                <div class="subject-title-stack">
                  <a href="<?= $b ?>/admin/listings/<?= (int) $fl->id ?>/edit" class="link"><b><?= View::e($fl->subject ?: $fl->title) ?></b></a>
                  <?php if ($fl->subject !== ''): ?>
                    <span class="mem-url"><?= View::e($fl->title) ?></span>
                  <?php endif; ?>
                </div>
              <?php else: ?>
                <span class="text-dim">#<?= (int) $app['fanlisting_id'] ?></span>
              <?php endif; ?>
            </td>
            <?php endif; ?>

            <?php if ($tab === 'all'): ?>
            <td data-label="Status">
              <span class="badge <?= $statusBadge[$app['status']] ?? 'badge-inactive' ?>">
                <?= View::e($statusLabels[$app['status']] ?? $app['status']) ?>
              </span>
            </td>
            <?php endif; ?>

            <?php if ($tab === 'pending'): ?>
            <td data-label="Application">
              <?php $msg = (string) ($app['message'] ?? ''); ?>
              <button type="button" class="link js-adopt-app-open"
                      data-app-name="<?= View::e($app['name']) ?>"
                      data-app-url="<?= View::e((string) ($app['url'] ?? '')) ?>"
                      data-app-email="<?= View::e($app['email']) ?>"
                      data-app-listing="<?= $fl !== null ? View::e($fl->subject ?: $fl->title) : '' ?>"
                      data-app-date="<?= View::e(date($admin_date_format, strtotime((string) $app['applied_at']))) ?>"
                      data-app-msg="<?= View::e($msg) ?>"><?= $msg !== '' ? 'Click to Read' : 'View Details' ?></button>
            </td>
            <?php endif; ?>

            <td class="text-dim nowrap" data-label="Applied">
              <?= View::e(date($admin_date_format, strtotime((string) $app['applied_at']))) ?>
            </td>

            <td class="actions-col" data-label="">
              <div class="actions-row">
                <?php if ($isPending): ?>
                  <button type="submit" form="approve-adopt-<?= $appId ?>" class="btn btn-success btn-xs">Approve</button>
                  <button type="submit" form="reject-adopt-<?= $appId ?>" class="btn btn-danger btn-xs">Reject</button>
                <?php endif; ?>
                <a href="<?= $b ?>/admin/adoptions/<?= $appId ?>/edit" class="btn btn-tertiary btn-xs">Edit</a>
                <a href="<?= $b ?>/admin/adoptions/<?= $appId ?>/email" class="btn btn-secondary btn-xs">Email</a>
                <button type="submit" form="del-adopt-<?= $appId ?>" class="btn btn-danger btn-xs">Delete</button>
              </div>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</form>

<?php
  $_delReturn = $b . '/admin/adoptions?' . http_build_query(array_filter([
      'listing_id' => $listing_id ?? '',
      'tab'        => $tab ?? '',
      'search'     => $search ?? '',
      'page'       => ($page ?? 1) > 1 ? (string) $page : '',
  ], static fn(string $v): bool => $v !== ''));
?>
<?php foreach ($applications as $app): ?>
<form id="approve-adopt-<?= (int) $app['id'] ?>" method="POST"
      action="<?= $b ?>/admin/adoptions/<?= (int) $app['id'] ?>/approve">
  <input type="hidden" name="_csrf"   value="<?= View::e($csrf_token) ?>">
  <input type="hidden" name="_return" value="<?= View::e($returnUrl) ?>">
</form>
<form id="reject-adopt-<?= (int) $app['id'] ?>" method="POST"
      action="<?= $b ?>/admin/adoptions/<?= (int) $app['id'] ?>/reject"
      data-del-modal data-del-entity="adoption_application"
      data-del-name="<?= View::e($app['name']) ?>">
  <input type="hidden" name="_csrf"   value="<?= View::e($csrf_token) ?>">
  <input type="hidden" name="_return" value="<?= View::e($returnUrl) ?>">
</form>
<form id="del-adopt-<?= (int) $app['id'] ?>" method="POST"
      action="<?= $b ?>/admin/adoptions/<?= (int) $app['id'] ?>/delete"
      data-del-modal data-del-entity="adoption_application"
      data-del-name="<?= View::e($app['name']) ?>">
  <input type="hidden" name="_csrf" value="<?= View::e($csrf_token) ?>">
  <input type="hidden" name="_return" value="<?= View::e($_delReturn) ?>">
</form>
<?php endforeach; ?>

<?php if ($total_pages > 1):
    $pg_current = $page; $pg_total = $total_pages;
    $pg_url = static fn(int $p) => $buildUrl(['page' => $p > 1 ? $p : '']);
    include LUSTER_BASE . '/templates/admin/_pagination.php';
endif; ?>

<!-- Adoption application modal -->
<div class="modal-backdrop is-hidden" id="adopt-app-modal" role="dialog" aria-modal="true" aria-labelledby="adopt-app-modal-title" data-managed-close>
  <div class="modal modal--wide">
    <div class="modal-header">
      <h3 class="modal-title" id="adopt-app-modal-title">Adoption Application</h3>
      <button type="button" class="modal-close" aria-label="Close">&times;</button>
    </div>
    <div class="modal-body">
      <table class="data-table" style="margin-bottom:.75rem">
        <tbody>
          <tr><th style="width:100px">Name</th><td id="adopt-app-modal-name"></td></tr>
          <tr><th>URL</th><td id="adopt-app-modal-url"></td></tr>
          <tr><th>Email</th><td id="adopt-app-modal-email"></td></tr>
          <tr><th>Listing</th><td id="adopt-app-modal-listing"></td></tr>
          <tr><th>Applied</th><td id="adopt-app-modal-date"></td></tr>
        </tbody>
      </table>
      <div id="adopt-app-modal-msg-wrap">
        <div style="font-weight:600;margin-bottom:.35rem;color:var(--text)">Message</div>
        <div id="adopt-app-modal-body" style="white-space:pre-wrap;color:var(--text-muted);line-height:1.5"></div>
      </div>
    </div>
  </div>
</div>

<?php endif; // applications ?>

<!-- ──── LISTINGS VIEW ────────────────────────────────────────────── -->
<?php elseif ($tab === 'listings'): ?>

<?php if (empty($adoption_listings)): ?>
  <div class="card">
    <div class="empty">
      <div class="empty-icon"><?= View::icon('adoptions', ['width' => 32, 'height' => 32]) ?></div>
      <div class="empty-title">No listings marked for adoption.</div>
      <div>Open a listing&rsquo;s edit page and enable &ldquo;Available for adoption&rdquo; to start accepting applications.</div>
      <a href="<?= $b ?>/admin/listings" class="btn btn-primary btn-sm"><?= View::icon('star') ?> Go to Listings</a>
    </div>
  </div>
<?php else: ?>
  <div class="card">
    <div class="card-head">Adoption Listings
      <span class="card-head-sub"><?= count($adoption_listings) ?> listing<?= count($adoption_listings) !== 1 ? 's' : '' ?></span>
    </div>
    <div class="table-wrap">
      <table class="data-table mem-table">
        <thead>
          <tr>
            <th class="table-num">#</th>
            <th>Listing</th>
            <th class="table-num">Pending</th>
            <th class="table-num">Approved</th>
            <th class="table-num">Granted</th>
            <th class="table-num">Total</th>
            <th class="actions-col"></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($adoption_listings as $i => $item): ?>
          <?php
            $fl          = $item['listing'];
            $emailAllN   = (int) $item['count_approved'] + (int) $item['count_granted'];
          ?>
          <tr>
            <td class="table-num text-dim"><?= $i + 1 ?></td>
            <td data-label="Listing">
              <div class="subject-title-stack">
                <a href="<?= $b ?>/admin/listings/<?= (int) $fl->id ?>/edit" class="link"><b><?= View::e($fl->subject ?: $fl->title) ?></b></a>
                <?php if ($fl->subject !== ''): ?>
                  <span class="mem-url"><?= View::e($fl->title) ?></span>
                <?php endif; ?>
              </div>
            </td>
            <td class="table-num" data-label="Pending">
              <?php if ($item['count_pending'] > 0): ?>
                <a href="<?= $b ?>/admin/adoptions?tab=pending&listing_id=<?= (int) $fl->id ?>" class="badge badge-pending"><?= (int) $item['count_pending'] ?></a>
              <?php else: ?>
                <span class="text-dim">&mdash;</span>
              <?php endif; ?>
            </td>
            <td class="table-num" data-label="Approved">
              <?php if ($item['count_approved'] > 0): ?>
                <a href="<?= $b ?>/admin/adoptions?tab=approved&listing_id=<?= (int) $fl->id ?>" class="badge badge-current"><?= (int) $item['count_approved'] ?></a>
              <?php else: ?>
                <span class="text-dim">&mdash;</span>
              <?php endif; ?>
            </td>
            <td class="table-num" data-label="Granted">
              <?php if ($item['count_granted'] > 0): ?>
                <a href="<?= $b ?>/admin/adoptions?tab=granted&listing_id=<?= (int) $fl->id ?>" class="badge badge-redirect"><?= (int) $item['count_granted'] ?></a>
              <?php else: ?>
                <span class="text-dim">&mdash;</span>
              <?php endif; ?>
            </td>
            <td class="table-num text-dim" data-label="Total"><?= (int) $item['count_all'] ?></td>
            <td class="actions-col" data-label="">
              <div class="actions-row">
                <a href="<?= $b ?>/admin/adoptions?listing_id=<?= (int) $fl->id ?>" class="btn btn-primary btn-xs">View</a>
                <?php if ($emailAllN > 0): ?>
                  <a href="<?= $b ?>/admin/adoptions/<?= (int) $fl->id ?>/email-all"
                     class="btn btn-secondary btn-xs">Email All (<?= $emailAllN ?>)</a>
                <?php endif; ?>
              </div>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
<?php endif; ?>

<?php endif; ?>
