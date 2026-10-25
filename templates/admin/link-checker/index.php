<?php
/*
 Luster -- https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Admin link checker page. Checks affiliate, joined-listing, and member URLs for
 dead links and redirects. Displays results across tabbed views (Active, Moved,
 Dead) with one-click URL update for redirected links and bulk re-check controls.
*/

declare(strict_types=1);

use Luster\Core\View;

$b = rtrim(View::e($base_url), '/');

$lcBaseQs = static function (array $extra) use ($b, $listing_id_filter, $status, $listed_filter, $aff_reciprocal_filter, $search, $aff_sort, $aff_dir, $joined_sort, $joined_dir, $mem_sort, $mem_dir): string {
    $qs = http_build_query(array_filter(array_merge([
        'listing_id'           => $listing_id_filter !== null ? (string) $listing_id_filter : '',
        'status'               => $status,
        'listed_filter'        => $listed_filter,
        'aff_reciprocal_filter'=> $aff_reciprocal_filter,
        'search'               => $search,
        'aff_sort'             => $aff_sort !== 'name'          ? $aff_sort    : '',
        'aff_dir'              => $aff_dir  !== 'asc'           ? $aff_dir     : '',
        'joined_sort'          => $joined_sort !== 'name'        ? $joined_sort : '',
        'joined_dir'           => $joined_dir  !== 'asc'        ? $joined_dir  : '',
        'mem_sort'             => $mem_sort !== 'member_name'   ? $mem_sort    : '',
        'mem_dir'              => $mem_dir  !== 'asc'           ? $mem_dir     : '',
    ], $extra), static fn(string $v): bool => $v !== ''));
    return $b . '/admin/link-checker' . ($qs !== '' ? '?' . $qs : '');
};

$lcListedUrl = static function (string $s) use ($lcBaseQs, $listed_filter): string {
    $newFilter = ($listed_filter === $s) ? '' : $s;
    return $lcBaseQs(['tab' => 'joined', 'listed_filter' => $newFilter]);
};

$lcReciprocalUrl = static function (string $s) use ($lcBaseQs, $aff_reciprocal_filter): string {
    $newFilter = ($aff_reciprocal_filter === $s) ? '' : $s;
    return $lcBaseQs(['tab' => 'affiliates', 'aff_reciprocal_filter' => $newFilter]);
};

$lcAffPageUrl = static function (int $p) use ($lcBaseQs): string {
    return $lcBaseQs(['tab' => 'affiliates', 'aff_page' => $p > 1 ? (string) $p : '']);
};

$lcJoinedPageUrl = static function (int $p) use ($lcBaseQs): string {
    return $lcBaseQs(['tab' => 'joined', 'joined_page' => $p > 1 ? (string) $p : '']);
};

$lcAffSortUrl = static function (string $col) use ($lcBaseQs, $aff_sort, $aff_dir): string {
    $newDir = ($aff_sort === $col && $aff_dir === 'asc') ? 'desc' : 'asc';
    return $lcBaseQs([
        'tab'      => 'affiliates',
        'aff_sort' => $col !== 'name' ? $col : '',
        'aff_dir'  => $newDir !== 'asc' ? $newDir : '',
    ]);
};

$lcJoinedSortUrl = static function (string $col) use ($lcBaseQs, $joined_sort, $joined_dir): string {
    $newDir = ($joined_sort === $col && $joined_dir === 'asc') ? 'desc' : 'asc';
    return $lcBaseQs([
        'tab'         => 'joined',
        'joined_sort' => $col !== 'name' ? $col : '',
        'joined_dir'  => $newDir !== 'asc' ? $newDir : '',
    ]);
};

$lcMemPageUrl = static function (int $p) use ($lcBaseQs): string {
    return $lcBaseQs(['tab' => 'members', 'mem_page' => $p > 1 ? (string) $p : '']);
};

$lcMemSortUrl = static function (string $col) use ($lcBaseQs, $mem_sort, $mem_dir): string {
    $newDir = ($mem_sort === $col && $mem_dir === 'asc') ? 'desc' : 'asc';
    return $lcBaseQs([
        'tab'      => 'members',
        'mem_sort' => $col !== 'member_name' ? $col : '',
        'mem_dir'  => $newDir !== 'asc' ? $newDir : '',
    ]);
};

$lcMemSumPageUrl = static function (int $p) use ($b, $listing_id_filter, $mem_sum_sort, $mem_sum_dir): string {
    $qs = http_build_query(array_filter([
        'listing_id'   => $listing_id_filter !== null ? (string) $listing_id_filter : '',
        'tab'          => 'members',
        'mem_sum_page' => $p > 1 ? (string) $p : '',
        'mem_sum_sort' => ($mem_sum_sort ?? 'id') !== 'id' ? ($mem_sum_sort ?? '') : '',
        'mem_sum_dir'  => ($mem_sum_dir  ?? 'asc') !== 'asc' ? ($mem_sum_dir ?? '') : '',
    ], static fn(string $v): bool => $v !== ''));
    return $b . '/admin/link-checker' . ($qs !== '' ? '?' . $qs : '');
};

$lcMemSumSortUrl = static function (string $col) use ($b, $listing_id_filter, $mem_sum_sort, $mem_sum_dir): string {
    $newDir = ($mem_sum_sort === $col && $mem_sum_dir === 'asc') ? 'desc' : 'asc';
    $qs = http_build_query(array_filter([
        'listing_id'   => $listing_id_filter !== null ? (string) $listing_id_filter : '',
        'tab'          => 'members',
        'mem_sum_sort' => $col !== 'id' ? $col : '',
        'mem_sum_dir'  => $newDir !== 'asc' ? $newDir : '',
    ], static fn(string $v): bool => $v !== ''));
    return $b . '/admin/link-checker' . ($qs !== '' ? '?' . $qs : '');
};

$lcStatusUrl = static function (string $s, string $tab = '') use ($b, $listing_id_filter, $status, $listed_filter, $aff_reciprocal_filter, $search, $aff_sort, $aff_dir, $joined_sort, $joined_dir, $mem_sort, $mem_dir): string {
    $newStatus = ($status === $s) ? '' : $s;
    $qs = http_build_query(array_filter([
        'listing_id'            => $listing_id_filter !== null ? (string) $listing_id_filter : '',
        'status'                => $newStatus,
        'listed_filter'         => $listed_filter,
        'aff_reciprocal_filter' => $aff_reciprocal_filter,
        'search'                => $search,
        'tab'                   => $tab,
        'aff_sort'              => $aff_sort !== 'name' ? $aff_sort : '',
        'aff_dir'               => $aff_dir  !== 'asc'  ? $aff_dir  : '',
        'joined_sort'           => $joined_sort !== 'name'       ? $joined_sort : '',
        'joined_dir'            => $joined_dir  !== 'asc'        ? $joined_dir  : '',
        'mem_sort'              => $mem_sort !== 'member_name'  ? $mem_sort    : '',
        'mem_dir'               => $mem_dir  !== 'asc'          ? $mem_dir     : '',
    ], static fn(string $v): bool => $v !== ''));
    return $b . '/admin/link-checker' . ($qs !== '' ? '?' . $qs : '');
};

$totalMoved    = count($moved_affiliates) + count($moved_joined)  + count($moved_members  ?? []);
$totalDead     = count($dead_affiliates)  + count($dead_joined)   + count($dead_members   ?? []);
$filtersActive = $listing_id_filter !== null || $status !== '' || $listed_filter !== '' || $aff_reciprocal_filter !== '' || $search !== '';

$listingObjMap = [];
foreach ($listings ?? [] as $_lst) {
    $listingObjMap[$_lst->id] = $_lst;
}
$filterLabel = match (true) {
    $listing_id_filter === 0    => 'Collective',
    $listing_id_filter !== null => ($listingObjMap[$listing_id_filter] ?? null)?->subject ?? '',
    default                     => null,
};

$statusLabel = static function (string $s): string {
    return match ($s) {
        'ok'        => 'Active',
        'dead'      => 'Dead',
        'redirect'  => 'Redirect',
        'found'     => 'Backlinked',
        'missing'   => 'No Backlink',
        'no_url'    => 'No URL',
        default     => 'Unchecked',
    };
};

$statusBadgeClass = static function (string $s): string {
    return match ($s) {
        'ok', 'found'      => 'badge-current',
        'dead', 'missing' => 'badge-error',
        'redirect'        => 'badge-redirect',
        'no_url'          => 'badge-inactive',
        default           => 'badge-inactive',
    };
};

$sumCell = static function (?array $ms, string $key): string {
    if ($ms === null) {
        return '<span class="text-dim">&mdash;</span>';
    }
    $v = (int) ($ms[$key] ?? 0);
    if ($v === 0) {
        return '<span class="text-dim">0</span>';
    }
    if ($key === 'dead') {
        return '<span class="badge badge-error">' . $v . '</span>';
    }
    if ($key === 'redirect') {
        return '<span class="badge badge-redirect">' . $v . '</span>';
    }
    return (string) $v;
};
?>

<?php if ($success !== ''): ?>
  <div class="alert alert-success"><?= View::e($success) ?></div>
<?php endif; ?>
<?php if ($error !== ''): ?>
  <div class="alert alert-error"><?= View::e($error) ?></div>
<?php endif; ?>

<!-- ── TOOLBAR ────────────────────────────────────────────────────────────── -->
<div class="toolbar">
  <div class="actions-row">
    <button type="button" class="btn btn-preview btn-sm filter-toggle" id="lc-filter-toggle"
            aria-expanded="true"
            aria-controls="lc-filter-panel">
      <?= View::icon('filter') ?>
      Filter<?php if ($filtersActive): ?><span class="filter-dot" aria-hidden="true"></span><?php endif; ?>
    </button>
  </div>
  <div class="actions-row">
    <a href="<?= $b ?>/admin/link-checker/settings" class="btn btn-quaternary btn-sm"><?= View::icon('gear') ?>Link Checker Settings</a>
  </div>
</div>

<!-- ── FILTER PANEL ────────────────────────────────────────────────────────── -->
<div class="filter-panel" id="lc-filter-panel">
  <form method="GET" action="<?= $b ?>/admin/link-checker" id="lc-filter-form" class="filter-row filter-row--compact">
    <input type="hidden" name="tab" value="members" id="lc-filter-tab">
    <input type="hidden" name="listed_filter" value="<?= View::e($listed_filter) ?>" id="lc-filter-listed">
    <input type="hidden" name="aff_reciprocal_filter" value="<?= View::e($aff_reciprocal_filter) ?>" id="lc-filter-aff-reciprocal">
    <?php if (!empty($listings)): ?>
    <div class="select-wrap">
      <?php $_lcSc = $aff_scope_counts ?? []; $_lcTotal = array_sum($_lcSc); ?>
      <select name="listing_id" id="lc-listing-sel" aria-label="Filter by listing">
        <option value=""><?= $_lcTotal > 0 ? 'All Listings (' . $_lcTotal . ')' : 'All Listings' ?></option>
        <?php $_lcCollN = (int) ($_lcSc[0] ?? 0); ?>
        <?php $_lcCollLabel = ($site_name ?? '') !== '' ? View::e($site_name) . ' (Collective)' : 'Collective'; ?>
        <option value="0" <?= $listing_id_filter === 0 ? 'selected' : '' ?>><?= $_lcCollLabel ?><?= $_lcCollN > 0 ? ' (' . $_lcCollN . ')' : '' ?></option>
        <?php foreach ($listings as $lst): ?>
          <?php $_lcN = (int) ($_lcSc[(int) $lst->id] ?? 0); ?>
          <option value="<?= (int) $lst->id ?>" <?= $listing_id_filter === $lst->id ? 'selected' : '' ?>>
            <?= View::e($lst->subject) ?><?= $_lcN > 0 ? ' (' . $_lcN . ')' : '' ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <?php endif; ?>
    <div class="select-wrap">
      <?php
        $_lcAffStats    = $aff_stats    ?? ['ok' => 0, 'dead' => 0, 'redirect' => 0, 'unchecked' => 0];
        $_lcJoinedStats = $joined_stats ?? ['ok' => 0, 'dead' => 0, 'redirect' => 0, 'unchecked' => 0];
        $_lcCombOk        = (int) $_lcAffStats['ok']        + (int) $_lcJoinedStats['ok'];
        $_lcCombRedirect  = (int) $_lcAffStats['redirect']  + (int) $_lcJoinedStats['redirect'];
        $_lcCombDead      = (int) $_lcAffStats['dead']      + (int) $_lcJoinedStats['dead'];
        $_lcCombUnchecked = (int) $_lcAffStats['unchecked'] + (int) $_lcJoinedStats['unchecked'];
        $_lcCombTotal     = $_lcCombOk + $_lcCombRedirect + $_lcCombDead + $_lcCombUnchecked;
      ?>
      <select name="status" id="lc-status-sel" aria-label="Filter by link status">
        <option value="">All Statuses</option>
        <option value="ok"        <?= $status === 'ok'        ? 'selected' : '' ?>>Active<?=        $_lcCombOk        > 0 ? ' (' . $_lcCombOk        . ')' : '' ?></option>
        <option value="redirect"  <?= $status === 'redirect'  ? 'selected' : '' ?>>Redirect<?=      $_lcCombRedirect  > 0 ? ' (' . $_lcCombRedirect  . ')' : '' ?></option>
        <option value="dead"      <?= $status === 'dead'      ? 'selected' : '' ?>>Dead<?=          $_lcCombDead      > 0 ? ' (' . $_lcCombDead      . ')' : '' ?></option>
        <option value="unchecked" <?= $status === 'unchecked' ? 'selected' : '' ?>>Unchecked<?=     $_lcCombUnchecked > 0 ? ' (' . $_lcCombUnchecked . ')' : '' ?></option>
      </select>
    </div>
    <div class="search-field-wrap">
      <?= View::icon('search', ['class' => 'search-field-icon', 'aria-hidden' => 'true']) ?>
      <input type="text" name="search" class="form" placeholder="Search by name&hellip;"
             value="<?= View::e($search) ?>">
    </div>
    <button type="submit" class="btn btn-secondary btn-sm">Search</button>
    <a href="<?= $b ?>/admin/link-checker" class="btn btn-quaternary btn-sm">Reset</a>
  </form>
</div>

<!-- ── TAB NAVIGATION ─────────────────────────────────────────────────────── -->
<div class="tabs-wrap">
  <div class="tabs">
    <button class="tabs-btn active" data-tab="members">Members</button>
    <button class="tabs-btn" data-tab="affiliates">Affiliates</button>
    <button class="tabs-btn" data-tab="joined">Joined</button>
    <span style="display:block;width:1px;background:var(--border);margin:4px .5rem;align-self:stretch;flex-shrink:0" aria-hidden="true"></span>
    <button class="tabs-btn" data-tab="moved">Redirected<?php if ($totalMoved > 0): ?> <span class="badge badge-redirect"><?= $totalMoved ?></span><?php endif; ?></button>
    <button class="tabs-btn" data-tab="dead">Dead<?php if ($totalDead > 0): ?> <span class="badge badge-error"><?= $totalDead ?></span><?php endif; ?></button>
  </div>
</div>
<div class="tabs-select-wrap">
  <span class="overview-section-title">Section</span>
  <div class="select-wrap flex-fill">
    <select class="tabs-select" aria-label="Select tab">
      <option value="members">Members<?= (int) ($mem_total ?? 0) > 0 ? ' (' . (int) $mem_total . ')' : '' ?></option>
      <option value="affiliates">Affiliates<?= (int) ($aff_total ?? 0) > 0 ? ' (' . (int) $aff_total . ')' : '' ?></option>
      <option value="joined">Joined<?= (int) ($joined_total ?? 0) > 0 ? ' (' . (int) $joined_total . ')' : '' ?></option>
      <option value="moved">Redirected<?= $totalMoved > 0 ? ' (' . $totalMoved . ')' : '' ?></option>
      <option value="dead">Dead<?= $totalDead > 0 ? ' (' . $totalDead . ')' : '' ?></option>
    </select>
  </div>
</div>

<p class="form-hint" style="margin:.5rem 0 .75rem"><strong>Note:</strong> Do not navigate away from this page while the link checker is running. Large listings may take some time to complete.</p>

  <!-- ── Members panel ───────────────────────────────────────────────────── -->
  <div id="tab-members" class="tabs-panel active" role="tabpanel">
  <?php
    $_memSummary = $mem_summary ?? [];
    $_allListings = $listings ?? [];
    $_scanEndpoint = $b . '/admin/link-checker/scan-member-ajax';
    $_allListingIds = array_values(array_map(static fn($l) => (int) $l->id, $_allListings));
  ?>
  <?php if ($listing_id_filter === 0): ?>

    <!-- ── Collective — no member table ─────────────────────────────────── -->
    <div class="card empty">
      <?= View::icon('members', ['class' => 'empty-icon', 'width' => 32, 'height' => 32]) ?>
      <div><strong>Members are per-listing.</strong></div>
      <div>Select a specific listing from the filter above, or choose <strong>All Listings</strong> to see every listing's member URLs.</div>
    </div>

  <?php elseif ($listing_id_filter === null): ?>

    <!-- ── All Listings view ─────────────────────────────────────────────── -->

    <!-- Hidden check-all trigger -->
    <button id="lc-check-members" style="display:none"
            data-csrf="<?= View::e($csrf_token) ?>"
            data-endpoint="<?= $b ?>/admin/link-checker/check-member"
            data-ids-url="<?= View::e($b . '/admin/link-checker/all-ids?panel=members') ?>"
            data-panel="members"></button>

    <p>
      First, use "Scan All Listings" to import the current member URLs from every listing at once, or scan individual listings using the row buttons below.</p>

    <!-- Scan All + Run Link Checker buttons -->
    <div class="actions-row" style="margin-bottom:.75rem;gap:.5rem">
      <button id="lc-scan-all-btn" class="btn btn-secondary btn-sm"
              data-csrf="<?= View::e($csrf_token) ?>"
              data-endpoint="<?= View::e($_scanEndpoint) ?>"
              data-listing-ids="<?= View::e(json_encode($_allListingIds, JSON_THROW_ON_ERROR)) ?>">
        <?= View::icon('refresh', ['width' => 21, 'height' => 21]) ?> Scan All Listings
      </button>
      <button type="button" class="btn btn-primary btn-sm js-lc-run-panel" data-panel="members">
        <?= View::icon('linkcheck') ?> Run Link Checker
      </button>
    </div>
    <div class="lc-progress-wrap is-hidden" id="lc-scan-prog" style="margin-bottom:.75rem">
      <div class="progress-track" role="progressbar" aria-valuemin="0" aria-valuemax="100">
        <div class="progress-bar" id="lc-scan-prog-bar" style="width:0%"></div>
      </div>
      <span class="lc-prog-label text-muted nowrap" id="lc-scan-prog-label">Preparing&hellip;</span>
    </div>

    <!-- Check-all progress bar -->
    <div class="lc-progress-wrap is-hidden" id="lc-prog-members" style="margin-bottom:.75rem">
      <div class="progress-track" role="progressbar" aria-valuemin="0" aria-valuemax="100">
        <div class="progress-bar" id="lc-prog-bar-members" style="width:0%"></div>
      </div>
      <span class="lc-prog-label text-muted nowrap" id="lc-prog-label-members">0 / <?= (int) ($mem_total ?? 0) ?></span>
    </div>

    <div class="card">
      <div class="card-head">
        <span>All Listings</span>
        <?php
          $_sumStart = ($mem_sum_page - 1) * $lc_per_page + 1;
          $_sumEnd   = min($mem_sum_page * $lc_per_page, $mem_sum_total);
        ?>
        <?php if ($mem_sum_total > 0): ?>
        <span class="card-head-sub">Showing <?= $_sumStart ?>&#8211;<?= $_sumEnd ?> of <?= number_format($mem_sum_total) ?> listing<?= $mem_sum_total !== 1 ? 's' : '' ?></span>
        <?php endif; ?>
        <div class="stat-pills" style="margin-left:auto">
          <span class="badge badge-current"><?= (int) ($mem_stats['ok']        ?? 0) ?> Active</span>
          <span class="badge badge-error"><?= (int) ($mem_stats['dead']       ?? 0) ?> Dead</span>
          <span class="badge badge-redirect"><?= (int) ($mem_stats['redirect']   ?? 0) ?> Redirect</span>
          <span class="badge badge-inactive"><?= (int) ($mem_stats['unchecked']  ?? 0) ?> Unchecked</span>
          <span class="badge badge-inactive"><?= (int) ($mem_stats['no_url']  ?? 0) ?> No URL</span>
        </div>
      </div>
      <?php if (empty($_allListings)): ?>
      <div class="empty">
        <?= View::icon('linkcheck', ['class' => 'empty-icon', 'width' => 32, 'height' => 32]) ?>
        <div><strong>No listings found.</strong></div>
        <div>Add a listing to start checking member URLs.</div>
      </div>
      <?php else: ?>
      <div class="table-wrap">
        <?php $_mss = $mem_sum_sort ?? 'id'; $_msd = $mem_sum_dir ?? 'asc'; ?>
        <table class="data-table mem-table">
          <thead>
            <tr>
              <th><a href="<?= View::e($lcMemSumSortUrl('id')) ?>" class="sort-th<?= $_mss === 'id' ? ' sort-active' : '' ?>"># <?= View::sortIcon('id', $_mss, $_msd) ?></a></th>
              <th><a href="<?= View::e($lcMemSumSortUrl('listing')) ?>" class="sort-th<?= $_mss === 'listing' ? ' sort-active' : '' ?>">Listing <?= View::sortIcon('listing', $_mss, $_msd) ?></a></th>
              <th>Synced</th>
              <th>Active</th>
              <th>Dead</th>
              <th>Redirect</th>
              <th>Unchecked</th>
              <th>No URL</th>
              <th><a href="<?= View::e($lcMemSumSortUrl('last_checked')) ?>" class="sort-th<?= $_mss === 'last_checked' ? ' sort-active' : '' ?>">Last Checked <?= View::sortIcon('last_checked', $_mss, $_msd) ?></a></th>
              <th class="actions-col">Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($mem_sum_listings ?? [] as $_msIdx => $_lst): ?>
            <?php $_lsid = (int) $_lst->id; $_ms = $_memSummary[$_lsid] ?? null; ?>
            <tr id="lc-mem-sum-<?= $_lsid ?>" class="<?= ($lifecycle_map[$_lsid] ?? null) ? 'row-archived' : '' ?>">
              <td class="table-num text-dim" data-label="#"><?= (int) $_lst->id ?></td>
              <td data-label="Listing">
                <div class="subject-title-stack">
                  <a href="<?= $b ?>/admin/listings/<?= $_lsid ?>/edit" class="link"><b><?= View::e($_lst->subject ?: $_lst->title) ?></b></a>
                  <?php if ($_lst->subject !== ''): ?>
                    <span class="mem-url"><?= View::e($_lst->title) ?></span>
                  <?php endif; ?>
                </div>
              </td>
              <td data-label="Synced" class="lc-ms-total"><?= $sumCell($_ms, 'total') ?></td>
              <td data-label="Active" class="lc-ms-ok"><?= $sumCell($_ms, 'ok') ?></td>
              <td data-label="Dead" class="lc-ms-dead"><?= $sumCell($_ms, 'dead') ?></td>
              <td data-label="Redirect" class="lc-ms-redirect"><?= $sumCell($_ms, 'redirect') ?></td>
              <td data-label="Unchecked" class="lc-ms-unchecked"><?= $sumCell($_ms, 'unchecked') ?></td>
              <td data-label="No URL" class="lc-ms-no-url"><?= $sumCell($_ms, 'no_url') ?></td>
              <td data-label="Last Checked" class="text-muted lc-ms-checked">
                <?= $_ms !== null && $_ms['last_checked'] !== null
                    ? View::e(date($admin_date_format, strtotime((string) $_ms['last_checked'])))
                    : '<span class="text-dim">&mdash;</span>' ?>
              </td>
              <td class="actions-col" data-label="">
                <div class="actions-row">
                  <button type="button" class="btn btn-secondary btn-xs js-lc-scan-listing"
                          data-listing-id="<?= $_lsid ?>"
                          data-csrf="<?= View::e($csrf_token) ?>"
                          data-endpoint="<?= View::e($_scanEndpoint) ?>">
                    Scan
                  </button>
                  <a href="<?= View::e($b . '/admin/link-checker?' . http_build_query(['listing_id' => $_lsid, 'tab' => 'members'])) ?>"
                     class="btn btn-tertiary btn-xs">View</a>
                </div>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>
    </div>
    <?php if ($mem_sum_total_pages > 1): ?>
      <?php $pg_current = $mem_sum_page; $pg_total = $mem_sum_total_pages; $pg_url = $lcMemSumPageUrl;
      include LUSTER_BASE . '/templates/admin/_pagination.php'; ?>
    <?php endif; ?>

  <?php else: ?>

    <!-- ── Per-listing view ──────────────────────────────────────────────── -->

    <!-- Hidden check-all trigger -->
    <button id="lc-check-members" style="display:none"
            data-csrf="<?= View::e($csrf_token) ?>"
            data-endpoint="<?= $b ?>/admin/link-checker/check-member"
            data-ids-url="<?= View::e($b . '/admin/link-checker/all-ids?panel=members&listing_id=' . $listing_id_filter) ?>"
            data-panel="members"></button>
    <!-- Scan + Run buttons above card -->
    <div class="actions-row" style="margin-bottom:.75rem;gap:.5rem">
      <form method="POST" action="<?= $b ?>/admin/link-checker/scan-members" class="action-form-inline">
        <input type="hidden" name="_csrf"      value="<?= View::e($csrf_token) ?>">
        <input type="hidden" name="listing_id" value="<?= (int) $listing_id_filter ?>">
        <button type="submit" class="btn btn-secondary btn-sm"><?= View::icon('refresh') ?> Scan Members</button>
      </form>
      <button type="button" class="btn btn-primary btn-sm js-lc-run-panel" data-panel="members">
        <?= View::icon('linkcheck') ?> Run Link Checker
      </button>
      <form method="POST" action="<?= $b ?>/admin/link-checker/reset-members" class="action-form-inline"
            data-confirm="Reset all member link check statuses for this listing?">
        <input type="hidden" name="_csrf"      value="<?= View::e($csrf_token) ?>">
        <input type="hidden" name="listing_id" value="<?= (int) $listing_id_filter ?>">
        <button type="submit" class="btn btn-tertiary btn-sm">Reset Member Statuses</button>
      </form>
    </div>

    <!-- Check-all progress bar -->
    <div class="lc-progress-wrap is-hidden" id="lc-prog-members" style="margin-bottom:.75rem">
      <div class="progress-track" role="progressbar" aria-valuemin="0" aria-valuemax="100">
        <div class="progress-bar" id="lc-prog-bar-members" style="width:0%"></div>
      </div>
      <span class="lc-prog-label text-muted nowrap" id="lc-prog-label-members">0 / <?= count($members ?? []) ?></span>
    </div>

    <div class="card">
      <div class="card-head">
        <span>Member URLs<?php if ($mem_listing !== null): ?> &mdash; <?= View::e($mem_listing->subject ?: $mem_listing->title) ?><?php endif; ?></span>
        <?php if ($mem_total > 0): ?>
        <span class="card-head-sub">Showing <?= ($mem_page - 1) * $lc_per_page + 1 ?>&#8211;<?= ($mem_page - 1) * $lc_per_page + count($members ?? []) ?> of <?= number_format($mem_total) ?> member<?= $mem_total !== 1 ? 's' : '' ?></span>
        <?php endif; ?>
        <div class="stat-pills" style="margin-left:auto">
          <a href="<?= View::e($lcStatusUrl('ok', 'members')) ?>" class="badge badge-current<?= $status === 'ok' ? ' badge--active' : '' ?>"><?= (int) ($mem_stats['ok'] ?? 0) ?> Active</a>
          <a href="<?= View::e($lcStatusUrl('dead', 'members')) ?>" class="badge badge-error<?= $status === 'dead' ? ' badge--active' : '' ?>"><?= (int) ($mem_stats['dead'] ?? 0) ?> Dead</a>
          <a href="<?= View::e($lcStatusUrl('redirect', 'members')) ?>" class="badge badge-redirect<?= $status === 'redirect' ? ' badge--active' : '' ?>"><?= (int) ($mem_stats['redirect'] ?? 0) ?> Redirect</a>
          <a href="<?= View::e($lcStatusUrl('unchecked', 'members')) ?>" class="badge badge-inactive<?= $status === 'unchecked' ? ' badge--active' : '' ?>"><?= (int) ($mem_stats['unchecked'] ?? 0) ?> Unchecked</a>
          <a href="<?= View::e($lcStatusUrl('no_url', 'members')) ?>" class="badge badge-inactive<?= $status === 'no_url' ? ' badge--active' : '' ?>"><?= (int) ($mem_stats['no_url'] ?? 0) ?> No URL</a>
          <span class="badge badge-current" data-lc-backlink-pill="found"><?= (int) ($backlink_stats['found'] ?? 0) ?> Backlinked</span>
          <span class="badge badge-error" data-lc-backlink-pill="missing"><?= (int) ($backlink_stats['missing'] ?? 0) ?> No Backlink</span>
        </div>
      </div>
      <?php if (empty($members)): ?>
      <div class="empty">
        <?= View::icon('linkcheck', ['class' => 'empty-icon', 'width' => 32, 'height' => 32]) ?>
        <div><strong>No member URLs scanned yet.</strong></div>
        <div>Click <strong>Scan Members</strong> above to sync member URLs from this listing, then run the checker.</div>
      </div>
      <?php else: ?>
      <div class="table-wrap">
        <table class="data-table mem-table">
          <thead>
            <tr>
              <th><a href="<?= View::e($lcMemSortUrl('member_name')) ?>" class="sort-th<?= $mem_sort === 'member_name' ? ' sort-active' : '' ?>">Name <?= View::sortIcon('member_name', $mem_sort, $mem_dir) ?></a></th>
              <th><a href="<?= View::e($lcMemSortUrl('member_url')) ?>" class="sort-th<?= $mem_sort === 'member_url' ? ' sort-active' : '' ?>">URL <?= View::sortIcon('member_url', $mem_sort, $mem_dir) ?></a></th>
              <th><a href="<?= View::e($lcMemSortUrl('link_status')) ?>" class="sort-th<?= $mem_sort === 'link_status' ? ' sort-active' : '' ?>">Status <?= View::sortIcon('link_status', $mem_sort, $mem_dir) ?></a></th>
              <th>Backlink</th>
              <th><a href="<?= View::e($lcMemSortUrl('link_checked_at')) ?>" class="sort-th<?= $mem_sort === 'link_checked_at' ? ' sort-active' : '' ?>">Last Checked <?= View::sortIcon('link_checked_at', $mem_sort, $mem_dir) ?></a></th>
              <th class="actions-col">Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($members as $mem): ?>
            <tr id="lc-mem-<?= (int) $mem->id ?>">
              <td data-label="Name"><span class="text-strong"><?= View::e((string) $mem->member_name) ?></span></td>
              <td data-label="URL">
                <?php if ($mem->member_url !== ''): ?>
                <a href="<?= View::e((string) $mem->member_url) ?>" target="_blank" rel="noopener noreferrer" class="link"><?= View::e((string) $mem->member_url) ?></a>
                <?php if ($mem->link_redirect_url !== null): ?>
                  <div class="text-muted link-break">
                    &rarr; <a href="<?= View::e((string) $mem->link_redirect_url) ?>" target="_blank" rel="noopener noreferrer" class="link"><?= View::e((string) $mem->link_redirect_url) ?></a>
                  </div>
                <?php endif; ?>
                <?php else: ?>
                <span class="text-dim">&mdash;</span>
                <?php endif; ?>
              </td>
              <td data-label="Status">
                <span class="badge <?= View::e($statusBadgeClass((string) $mem->link_status)) ?>" data-lc-link-badge>
                  <?= View::e($statusLabel((string) $mem->link_status)) ?>
                </span>
                <span class="badge badge-pending" data-lc-content-badge hidden></span>
              </td>
              <td data-label="Backlink">
                <?php
                  $blStatus = (string) ($mem->backlink_status ?? 'unchecked');
                  $blClass  = match($blStatus) { 'found' => 'badge-current', 'missing' => 'badge-error', default => 'badge-inactive' };
                  $blLabel  = match($blStatus) { 'found' => 'Backlinked', 'missing' => 'No Backlink', default => '&mdash;' };
                ?>
                <span class="badge <?= $blClass ?>" data-lc-backlink-badge><?= $blLabel ?></span>
              </td>
              <td class="text-muted" data-label="Last Checked" data-lc-checked>
                <?= $mem->link_checked_at !== null ? View::e(date($admin_date_format, strtotime((string) $mem->link_checked_at))) : '<span class="text-dim">&mdash;</span>' ?>
              </td>
              <td class="actions-col" data-label="">
                <button class="btn btn-primary btn-xs js-lc-single"
                        data-id="<?= (int) $mem->id ?>"
                        data-type="member"
                        data-csrf="<?= View::e($csrf_token) ?>"
                        data-endpoint="<?= $b ?>/admin/link-checker/check-member">
                  Check
                </button>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>
    </div>
    <?php if ($mem_total_pages > 1): ?>
      <?php $pg_current = $mem_page; $pg_total = $mem_total_pages; $pg_url = $lcMemPageUrl;
      include LUSTER_BASE . '/templates/admin/_pagination.php'; ?>
    <?php endif; ?>

  <?php endif; ?>
  </div>

  <!-- ── Affiliates panel ─────────────────────────────────────────────────── -->
  <div id="tab-affiliates" class="tabs-panel" role="tabpanel">
    <button id="lc-check-affiliates" style="display:none"
            data-csrf="<?= View::e($csrf_token) ?>"
            data-endpoint="<?= $b ?>/admin/link-checker/check-affiliate"
            data-ids-url="<?= View::e($b . '/admin/link-checker/all-ids?panel=affiliates' . ($listing_id_filter !== null ? '&listing_id=' . $listing_id_filter : '')) ?>"
            data-panel="affiliates"></button>

    <div class="actions-row" style="margin-bottom:.75rem">
      <button type="button" class="btn btn-primary btn-sm js-lc-run-panel" data-panel="affiliates">
        <?= View::icon('linkcheck') ?> Run Link Checker
      </button>
      <form method="POST" action="<?= $b ?>/admin/link-checker/reset-affiliates"
            data-confirm="Reset all affiliate link check statuses to Unchecked?">
        <input type="hidden" name="_csrf" value="<?= View::e($csrf_token) ?>">
        <button type="submit" class="btn btn-tertiary btn-sm">Reset All Statuses</button>
      </form>
    </div>
    <div class="lc-progress-wrap is-hidden" id="lc-prog-affiliates">
      <div class="progress-track" role="progressbar" aria-valuemin="0" aria-valuemax="100">
        <div class="progress-bar" id="lc-prog-bar-affiliates" style="width:0%"></div>
      </div>
      <span class="lc-prog-label text-muted nowrap" id="lc-prog-label-affiliates">0 / <?= count($affiliates) ?></span>
    </div>

    <?php if (empty($affiliates)): ?>
    <div class="card empty">
      <?= View::icon('linkcheck', ['class' => 'empty-icon', 'width' => 32, 'height' => 32]) ?>
      <div><strong>No affiliates found.</strong></div>
      <div>Add some affiliates to start checking links.</div>
      <a href="<?= $b ?>/admin/affiliates/add" class="btn btn-primary btn-sm"><?= View::icon('add') ?> Add Affiliate</a>
    </div>
    <?php else: ?>
      <div class="card">
      <div class="card-head">
        <span><?= $filterLabel !== null ? View::e($filterLabel) : 'All Listings' ?></span>
        <span class="card-head-sub">Showing <?= ($aff_page - 1) * $lc_per_page + 1 ?>&#8211;<?= ($aff_page - 1) * $lc_per_page + count($affiliates) ?> of <?= number_format($aff_total) ?> affiliate<?= $aff_total !== 1 ? 's' : '' ?></span>
        <div class="stat-pills" style="margin-left:auto">
          <a href="<?= View::e($lcStatusUrl('ok', 'affiliates')) ?>" class="badge badge-current<?= $status === 'ok' ? ' badge--active' : '' ?>"><?= $aff_stats['ok'] ?> Active</a>
          <a href="<?= View::e($lcStatusUrl('dead', 'affiliates')) ?>" class="badge badge-error<?= $status === 'dead' ? ' badge--active' : '' ?>"><?= $aff_stats['dead'] ?> Dead</a>
          <a href="<?= View::e($lcStatusUrl('redirect', 'affiliates')) ?>" class="badge badge-redirect<?= $status === 'redirect' ? ' badge--active' : '' ?>"><?= $aff_stats['redirect'] ?> Redirect</a>
          <a href="<?= View::e($lcStatusUrl('unchecked', 'affiliates')) ?>" class="badge badge-inactive<?= $status === 'unchecked' ? ' badge--active' : '' ?>"><?= $aff_stats['unchecked'] ?> Unchecked</a>
          <a href="<?= View::e($lcReciprocalUrl('found')) ?>" class="badge badge-current<?= $aff_reciprocal_filter === 'found' ? ' badge--active' : '' ?>"><?= (int) ($aff_reciprocal_stats['found'] ?? 0) ?> Backlinked</a>
          <a href="<?= View::e($lcReciprocalUrl('missing')) ?>" class="badge badge-error<?= $aff_reciprocal_filter === 'missing' ? ' badge--active' : '' ?>"><?= (int) ($aff_reciprocal_stats['missing'] ?? 0) ?> No Backlink</a>
        </div>
      </div>
      <div class="table-wrap">
        <table class="data-table mem-table">
          <thead>
            <tr>
              <th><a href="<?= View::e($lcAffSortUrl('name')) ?>" class="sort-th<?= $aff_sort === 'name' ? ' sort-active' : '' ?>">Name <?= View::sortIcon('name', $aff_sort, $aff_dir) ?></a></th>
              <?php if ($listing_id_filter === null): ?><th><a href="<?= View::e($lcAffSortUrl('listing')) ?>" class="sort-th<?= $aff_sort === 'listing' ? ' sort-active' : '' ?>">Listing <?= View::sortIcon('listing', $aff_sort, $aff_dir) ?></a></th><?php endif; ?>
              <th>URL</th>
              <th><a href="<?= View::e($lcAffSortUrl('link_status')) ?>" class="sort-th<?= $aff_sort === 'link_status' ? ' sort-active' : '' ?>">Link Status <?= View::sortIcon('link_status', $aff_sort, $aff_dir) ?></a></th>
              <th><a href="<?= View::e($lcAffSortUrl('reciprocal')) ?>" class="sort-th<?= $aff_sort === 'reciprocal' ? ' sort-active' : '' ?>">Backlink <?= View::sortIcon('reciprocal', $aff_sort, $aff_dir) ?></a></th>
              <th><a href="<?= View::e($lcAffSortUrl('last_checked')) ?>" class="sort-th<?= $aff_sort === 'last_checked' ? ' sort-active' : '' ?>">Last Checked <?= View::sortIcon('last_checked', $aff_sort, $aff_dir) ?></a></th>
              <th class="actions-col">Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($affiliates as $aff): ?>
            <tr id="lc-aff-<?= $aff->id ?>">
              <td data-label="Name">
                <b><?= View::e($aff->name) ?></b>
              </td>
              <?php if ($listing_id_filter === null): ?>
              <td data-label="Listing">
                <?php $_affFl = $aff->listingId !== null ? ($listingObjMap[$aff->listingId] ?? null) : null; ?>
                <?php if ($_affFl !== null): ?>
                  <div class="subject-title-stack">
                    <a href="<?= $b ?>/admin/listings/<?= (int) $_affFl->id ?>/edit" class="link"><b><?= View::e($_affFl->subject ?: $_affFl->title) ?></b></a>
                    <?php if ($_affFl->subject !== ''): ?>
                      <span class="mem-url"><?= View::e($_affFl->title) ?></span>
                    <?php endif; ?>
                  </div>
                <?php else: ?>
                  <div class="subject-title-stack">
                    <a href="<?= $b ?>/admin/collective" class="link"><b><?= View::e($site_name ?? 'Collective') ?></b></a>
                    <span class="mem-url">Collective</span>
                  </div>
                <?php endif; ?>
              </td>
              <?php endif; ?>
              <td data-label="URL">
                <a href="<?= View::e($aff->url) ?>" target="_blank" rel="noopener noreferrer" class="link">
                  <?= View::e($aff->url) ?>
                </a>
                <?php if ($aff->linkRedirectUrl !== null): ?>
                  <div class="text-muted link-break">
                    &rarr; <a href="<?= View::e($aff->linkRedirectUrl) ?>" target="_blank" rel="noopener noreferrer" class="link"><?= View::e($aff->linkRedirectUrl) ?></a>
                  </div>
                <?php endif; ?>
              </td>
              <td data-label="Link Status">
                <?php if ($aff->linkStatus === 'unchecked'): ?>
                  <span class="text-dim" data-lc-link-badge>&mdash;</span>
                <?php else: ?>
                  <span class="badge <?= View::e($statusBadgeClass($aff->linkStatus)) ?>" data-lc-link-badge>
                    <?= View::e($statusLabel($aff->linkStatus)) ?>
                  </span>
                <?php endif; ?>
                <span class="badge badge-pending" data-lc-content-badge hidden></span>
              </td>
              <td data-label="Backlink">
                <?php if ($aff->reciprocalStatus === 'unchecked'): ?>
                  <span class="text-dim" data-lc-recip-badge>&mdash;</span>
                <?php else: ?>
                  <span class="badge <?= View::e($statusBadgeClass($aff->reciprocalStatus)) ?>" data-lc-recip-badge>
                    <?= View::e($statusLabel($aff->reciprocalStatus)) ?>
                  </span>
                <?php endif; ?>
              </td>
              <td class="text-muted" data-label="Last Checked" data-lc-checked>
                <?= $aff->linkCheckedAt !== null ? View::e(date($admin_date_format, strtotime($aff->linkCheckedAt))) : '<span class="text-dim">&mdash;</span>' ?>
              </td>
              <td class="actions-col" data-label="">
                <button class="btn btn-primary btn-xs js-lc-single"
                        data-id="<?= $aff->id ?>"
                        data-type="affiliate"
                        data-csrf="<?= View::e($csrf_token) ?>"
                        data-endpoint="<?= $b ?>/admin/link-checker/check-affiliate">
                  Check
                </button>
                <a href="<?= $b ?>/admin/affiliates/<?= $aff->id ?>/edit" class="btn btn-tertiary btn-xs">Edit</a>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      </div>
    <?php endif; ?>
    <?php if ($aff_total_pages > 1): ?>
      <?php $pg_current = $aff_page; $pg_total = $aff_total_pages; $pg_url = $lcAffPageUrl;
      include LUSTER_BASE . '/templates/admin/_pagination.php'; ?>
    <?php endif; ?>
  </div>

  <!-- ── Joined listings panel ────────────────────────────────────────────── -->
  <div id="tab-joined" class="tabs-panel" role="tabpanel">
    <button id="lc-check-joined" style="display:none"
            data-csrf="<?= View::e($csrf_token) ?>"
            data-endpoint="<?= $b ?>/admin/link-checker/check-joined"
            data-ids-url="<?= $b ?>/admin/link-checker/all-ids?panel=joined"
            data-panel="joined"></button>

    <div class="actions-row" style="margin-bottom:.75rem">
      <button type="button" class="btn btn-primary btn-sm js-lc-run-panel" data-panel="joined">
        <?= View::icon('linkcheck') ?> Run Link Checker
      </button>
      <form method="POST" action="<?= $b ?>/admin/link-checker/reset-joined"
            data-confirm="Reset all joined-listing link check statuses to Unchecked?">
        <input type="hidden" name="_csrf" value="<?= View::e($csrf_token) ?>">
        <button type="submit" class="btn btn-tertiary btn-sm">Reset All Statuses</button>
      </form>
    </div>
    <div class="lc-progress-wrap is-hidden" id="lc-prog-joined">
      <div class="progress-track" role="progressbar" aria-valuemin="0" aria-valuemax="100">
        <div class="progress-bar" id="lc-prog-bar-joined" style="width:0%"></div>
      </div>
      <span class="lc-prog-label text-muted nowrap" id="lc-prog-label-joined">0 / <?= count($joined) ?></span>
    </div>

    <?php if (empty($joined)): ?>
    <div class="card empty">
      <?= View::icon('linkcheck', ['class' => 'empty-icon', 'width' => 32, 'height' => 32]) ?>
      <div><strong>No joined listings found.</strong></div>
      <div>Add some joined listings to start checking links.</div>
      <a href="<?= $b ?>/admin/joined/add" class="btn btn-primary btn-xs"><?= View::icon('add') ?> Add Joined Listing</a>
    </div>
    <?php else: ?>
      <div class="card">
      <div class="card-head">
        <span>Joined Listings</span>
        <span class="card-head-sub">Showing <?= ($joined_page - 1) * $lc_per_page + 1 ?>&#8211;<?= ($joined_page - 1) * $lc_per_page + count($joined) ?> of <?= number_format($joined_total) ?> listing<?= $joined_total !== 1 ? 's' : '' ?></span>
        <div class="stat-pills" style="margin-left:auto">
          <a href="<?= View::e($lcStatusUrl('ok', 'joined')) ?>" class="badge badge-current<?= $status === 'ok' ? ' badge--active' : '' ?>"><?= $joined_stats['ok'] ?> Active</a>
          <a href="<?= View::e($lcStatusUrl('dead', 'joined')) ?>" class="badge badge-error<?= $status === 'dead' ? ' badge--active' : '' ?>"><?= $joined_stats['dead'] ?> Dead</a>
          <a href="<?= View::e($lcStatusUrl('redirect', 'joined')) ?>" class="badge badge-redirect<?= $status === 'redirect' ? ' badge--active' : '' ?>"><?= $joined_stats['redirect'] ?> Redirect</a>
          <a href="<?= View::e($lcStatusUrl('unchecked', 'joined')) ?>" class="badge badge-inactive<?= $status === 'unchecked' ? ' badge--active' : '' ?>"><?= $joined_stats['unchecked'] ?> Unchecked</a>
          <a href="<?= View::e($lcListedUrl('listed')) ?>" class="badge badge-current<?= $listed_filter === 'listed' ? ' badge--active' : '' ?>"><?= (int) ($listed_stats['listed'] ?? 0) ?> Listed</a>
          <a href="<?= View::e($lcListedUrl('not_listed')) ?>" class="badge badge-error<?= $listed_filter === 'not_listed' ? ' badge--active' : '' ?>"><?= (int) ($listed_stats['not_found'] ?? 0) ?> Not Listed</a>
        </div>
      </div>
      <div class="table-wrap">
        <table class="data-table mem-table">
          <thead>
            <tr>
              <th><a href="<?= View::e($lcJoinedSortUrl('name')) ?>" class="sort-th<?= $joined_sort === 'name' ? ' sort-active' : '' ?>">Name <?= View::sortIcon('name', $joined_sort, $joined_dir) ?></a></th>
              <th>URL</th>
              <th><a href="<?= View::e($lcJoinedSortUrl('link_status')) ?>" class="sort-th<?= $joined_sort === 'link_status' ? ' sort-active' : '' ?>">Status <?= View::sortIcon('link_status', $joined_sort, $joined_dir) ?></a></th>
              <th>Listed?</th>
              <th><a href="<?= View::e($lcJoinedSortUrl('last_checked')) ?>" class="sort-th<?= $joined_sort === 'last_checked' ? ' sort-active' : '' ?>">Last Checked <?= View::sortIcon('last_checked', $joined_sort, $joined_dir) ?></a></th>
              <th class="actions-col">Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($joined as $jl): ?>
            <tr id="lc-joined-<?= $jl->id ?>">
              <td data-label="Name"><span class="text-strong"><?= View::e($jl->name) ?></span></td>
              <td data-label="URL">
                <a href="<?= View::e($jl->url) ?>" target="_blank" rel="noopener noreferrer" class="link">
                  <?= View::e($jl->url) ?>
                </a>
                <?php if ($jl->linkRedirectUrl !== null): ?>
                  <div class="text-muted link-break">
                    &rarr; <a href="<?= View::e($jl->linkRedirectUrl) ?>" target="_blank" rel="noopener noreferrer" class="link"><?= View::e($jl->linkRedirectUrl) ?></a>
                  </div>
                <?php endif; ?>
              </td>
              <td data-label="Status">
                <?php if ($jl->linkStatus === 'unchecked'): ?>
                  <span class="text-dim" data-lc-link-badge>&mdash;</span>
                <?php else: ?>
                  <span class="badge <?= View::e($statusBadgeClass($jl->linkStatus)) ?>" data-lc-link-badge>
                    <?= View::e($statusLabel($jl->linkStatus)) ?>
                  </span>
                <?php endif; ?>
                <span class="badge badge-pending" data-lc-content-badge hidden></span>
              </td>
              <td data-label="Listed?">
                <?php $lsStatus = $jl->listedStatus; ?>
                <?php if ($lsStatus === 'listed'): ?>
                  <span class="badge badge-current" data-lc-listed-badge>Listed</span>
                <?php elseif ($lsStatus === 'not_found'): ?>
                  <span class="badge badge-error" data-lc-listed-badge>Not Listed</span>
                <?php else: ?>
                  <span class="text-dim" data-lc-listed-badge>&mdash;</span>
                <?php endif; ?>
                <?php if ($jl->listedMembersUrl !== null && $jl->listedMembersUrl !== ''): ?>
                <div class="text-muted link-break"><a href="<?= View::e($jl->listedMembersUrl) ?>" target="_blank" rel="noopener noreferrer" class="link"><?= View::e($jl->listedMembersUrl) ?></a></div>
                <?php endif; ?>
              </td>
              <td class="text-muted" data-label="Last Checked" data-lc-checked>
                <?= $jl->linkCheckedAt !== null ? View::e(date($admin_date_format, strtotime($jl->linkCheckedAt))) : '<span class="text-dim">&mdash;</span>' ?>
              </td>
              <td class="actions-col" data-label="">
                <button class="btn btn-primary btn-xs js-lc-single"
                        data-id="<?= $jl->id ?>"
                        data-type="joined"
                        data-csrf="<?= View::e($csrf_token) ?>"
                        data-endpoint="<?= $b ?>/admin/link-checker/check-joined">
                  Check
                </button>
                <a href="<?= $b ?>/admin/joined/<?= $jl->id ?>/edit" class="btn btn-tertiary btn-xs">Edit</a>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      </div>
    <?php endif; ?>
    <?php if ($joined_total_pages > 1): ?>
      <?php $pg_current = $joined_page; $pg_total = $joined_total_pages; $pg_url = $lcJoinedPageUrl;
      include LUSTER_BASE . '/templates/admin/_pagination.php'; ?>
    <?php endif; ?>
  </div>

  <!-- ── Redirected Links panel ──────────────────────────────────────────── -->
  <div id="tab-moved" class="tabs-panel" role="tabpanel">
    <p>These URLs respond with an HTTP redirect, meaning the content has moved to a new address. Click <strong>Update URL</strong> to replace the stored URL with the redirect target. The record will be re-checked on the next run.</p>
    <?php if ($totalMoved === 0): ?>
    <div class="card empty">
      <?= View::icon('linkcheck', ['class' => 'empty-icon', 'width' => 32, 'height' => 32]) ?>
      <div><strong>No redirected links found.</strong></div>
      <div>Run the checker on the Members, Affiliates, or Joined tabs first.</div>
    </div>
    <?php else: ?>
      <div class="card">
      <div class="card-head">
        <span>Redirected Links</span>
        <span class="card-head-sub">Showing 1&#8211;<?= $totalMoved ?> of <?= $totalMoved ?> link<?= $totalMoved !== 1 ? 's' : '' ?></span>
      </div>
      <div class="table-wrap">
        <table class="data-table mem-table">
          <thead>
            <tr>
              <th>Type</th>
              <th>Name</th>
              <th>Current URL</th>
              <th>Redirect Target</th>
              <th>Checked</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($moved_affiliates as $aff): ?>
            <tr>
              <td data-label="Type"><span class="badge badge-info2">Affiliate</span></td>
              <td data-label="Name"><span class="text-strong"><?= View::e($aff->name) ?></span></td>
              <td data-label="Current URL">
                <a href="<?= View::e($aff->url) ?>" target="_blank" rel="noopener noreferrer" class="link text-muted">
                  <?= View::e($aff->url) ?>
                </a>
              </td>
              <td data-label="Redirect Target">
                <?php if ($aff->linkRedirectUrl !== null): ?>
                  <a href="<?= View::e($aff->linkRedirectUrl) ?>" target="_blank" rel="noopener noreferrer" class="link">
                    <?= View::e($aff->linkRedirectUrl) ?>
                  </a>
                <?php else: ?>
                  <span class="text-dim">&mdash;</span>
                <?php endif; ?>
              </td>
              <td class="text-muted" data-label="Checked"><?= $aff->linkCheckedAt !== null ? View::e(date($admin_date_format, strtotime($aff->linkCheckedAt))) : '<span class="text-dim">&mdash;</span>' ?></td>
              <td data-label="">
                <div class="actions-row">
                  <?php if ($aff->linkRedirectUrl !== null): ?>
                  <form method="POST" action="<?= $b ?>/admin/link-checker/apply-affiliate-redirect" class="action-form-inline">
                    <input type="hidden" name="_csrf" value="<?= View::e($csrf_token) ?>">
                    <input type="hidden" name="id"    value="<?= $aff->id ?>">
                    <button type="submit" class="btn btn-primary btn-xs">Update</button>
                  </form>
                  <?php endif; ?>
                  <a href="<?= $b ?>/admin/affiliates/<?= $aff->id ?>/edit" class="btn btn-tertiary btn-xs">Edit</a>
                </div>
              </td>
            </tr>
            <?php endforeach; ?>
            <?php foreach ($moved_joined as $jl): ?>
            <tr>
              <td data-label="Type"><span class="badge badge-info">Joined</span></td>
              <td data-label="Name"><span class="text-strong"><?= View::e($jl->name) ?></span></td>
              <td data-label="Current URL">
                <a href="<?= View::e($jl->url) ?>" target="_blank" rel="noopener noreferrer" class="link text-muted">
                  <?= View::e($jl->url) ?>
                </a>
              </td>
              <td data-label="Redirect Target">
                <?php if ($jl->linkRedirectUrl !== null): ?>
                  <a href="<?= View::e($jl->linkRedirectUrl) ?>" target="_blank" rel="noopener noreferrer" class="link">
                    <?= View::e($jl->linkRedirectUrl) ?>
                  </a>
                <?php else: ?>
                  <span class="text-dim">&mdash;</span>
                <?php endif; ?>
              </td>
              <td class="text-muted" data-label="Checked"><?= $jl->linkCheckedAt !== null ? View::e(date($admin_date_format, strtotime($jl->linkCheckedAt))) : '<span class="text-dim">&mdash;</span>' ?></td>
              <td data-label="">
                <div class="actions-row">
                  <?php if ($jl->linkRedirectUrl !== null): ?>
                  <form method="POST" action="<?= $b ?>/admin/link-checker/apply-joined-redirect" class="action-form-inline">
                    <input type="hidden" name="_csrf" value="<?= View::e($csrf_token) ?>">
                    <input type="hidden" name="id"    value="<?= $jl->id ?>">
                    <button type="submit" class="btn btn-primary btn-xs">Update</button>
                  </form>
                  <?php endif; ?>
                  <button type="button" class="btn btn-secondary btn-xs lc-tfl-lookup-btn"
                    data-joined-id="<?= $jl->id ?>"
                    data-network="tfl"
                    data-endpoint="<?= $b ?>/admin/link-checker/tfl-lookup-joined"
                    data-apply-endpoint="<?= $b ?>/admin/link-checker/apply-tfl-url-joined"
                    data-csrf="<?= View::e($csrf_token) ?>">TFL</button>
                  <button type="button" class="btn btn-secondary btn-xs lc-tfl-lookup-btn"
                    data-joined-id="<?= $jl->id ?>"
                    data-network="tafl"
                    data-endpoint="<?= $b ?>/admin/link-checker/tfl-lookup-joined"
                    data-apply-endpoint="<?= $b ?>/admin/link-checker/apply-tfl-url-joined"
                    data-csrf="<?= View::e($csrf_token) ?>">TAFL</button>
                  <a href="<?= $b ?>/admin/joined/<?= $jl->id ?>/edit" class="btn btn-tertiary btn-xs">Edit</a>
                </div>
              </td>
            </tr>
            <tr id="lc-tfl-result-<?= $jl->id ?>" style="display:none">
              <td colspan="6" style="padding:.5rem 1rem .75rem">
                <div id="lc-tfl-result-inner-<?= $jl->id ?>" class="form-hint" style="margin:0"></div>
              </td>
            </tr>
            <?php endforeach; ?>
            <?php foreach ($moved_members ?? [] as $mem): ?>
            <tr>
              <td data-label="Type"><span class="badge badge-inactive">Member</span></td>
              <td data-label="Name"><span class="text-strong"><?= View::e((string) $mem->member_name) ?></span></td>
              <td data-label="Current URL">
                <a href="<?= View::e((string) $mem->member_url) ?>" target="_blank" rel="noopener noreferrer" class="link text-muted">
                  <?= View::e((string) $mem->member_url) ?>
                </a>
              </td>
              <td data-label="Redirect Target">
                <?php if ($mem->link_redirect_url !== null): ?>
                  <a href="<?= View::e((string) $mem->link_redirect_url) ?>" target="_blank" rel="noopener noreferrer" class="link">
                    <?= View::e((string) $mem->link_redirect_url) ?>
                  </a>
                <?php else: ?>
                  <span class="text-dim">&mdash;</span>
                <?php endif; ?>
              </td>
              <td class="text-muted" data-label="Checked"><?= $mem->link_checked_at !== null ? View::e(date($admin_date_format, strtotime((string) $mem->link_checked_at))) : '<span class="text-dim">&mdash;</span>' ?></td>
              <td class="actions-col" data-label="">
                <div class="actions-row">
                  <?php if ($mem->link_redirect_url !== null): ?>
                  <form method="POST" action="<?= $b ?>/admin/link-checker/apply-member-redirect" class="action-form-inline">
                    <input type="hidden" name="_csrf"       value="<?= View::e($csrf_token) ?>">
                    <input type="hidden" name="id"          value="<?= (int) $mem->id ?>">
                    <input type="hidden" name="listing_id"  value="<?= (int) $mem->listing_id ?>">
                    <button type="submit" class="btn btn-primary btn-xs">Update</button>
                  </form>
                  <?php endif; ?>
                  <a href="<?= $b ?>/admin/members/<?= (int) $mem->listing_id ?>/edit?email=<?= urlencode((string) $mem->member_email) ?>" class="btn btn-tertiary btn-xs">Edit</a>
                </div>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      </div>
    <?php endif; ?>
  </div>

  <!-- ── Dead Links panel ─────────────────────────────────────────────────── -->
  <div id="tab-dead" class="tabs-panel" role="tabpanel">
    <?php if ($totalDead === 0): ?>
    <div class="card empty">
      <?= View::icon('linkcheck', ['class' => 'empty-icon', 'width' => 32, 'height' => 32]) ?>
      <div><strong>No dead links found.</strong></div>
      <div>Run the checker on the Members, Affiliates, or Joined tabs first.</div>
    </div>
    <?php else: ?>
        <p>These URLs returned an error or could not be reached. Review and remove or replace the records as needed.</p>
      <div class="card">
      <div class="card-head">
        <span>Dead Links</span>
        <span class="card-head-sub">Showing 1&#8211;<?= $totalDead ?> of <?= $totalDead ?> link<?= $totalDead !== 1 ? 's' : '' ?></span>
      </div>
      <div class="table-wrap">
        <table class="data-table mem-table">
          <thead>
            <tr>
              <th>Type</th>
              <th>Name</th>
              <th>URL</th>
              <th>Checked</th>
              <th class="actions-col">Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($dead_affiliates as $aff): ?>
            <tr>
              <td data-label="Type"><span class="badge badge-info2">Affiliate</span></td>
              <td data-label="Name"><span class="text-strong"><?= View::e($aff->name) ?></span></td>
              <td data-label="URL">
                <a href="<?= View::e($aff->url) ?>" target="_blank" rel="noopener noreferrer" class="link text-muted">
                  <?= View::e($aff->url) ?>
                </a>
              </td>
              <td class="text-muted" data-label="Checked"><?= $aff->linkCheckedAt !== null ? View::e(date($admin_date_format, strtotime($aff->linkCheckedAt))) : '<span class="text-dim">&mdash;</span>' ?></td>
              <td class="actions-col" data-label="">
                <a href="<?= $b ?>/admin/affiliates/<?= $aff->id ?>/edit" class="btn btn-tertiary btn-xs">Edit</a>
              </td>
            </tr>
            <?php endforeach; ?>
            <?php foreach ($dead_joined as $jl): ?>
            <tr>
              <td data-label="Type"><span class="badge badge-info">Joined</span></td>
              <td data-label="Name"><span class="text-strong"><?= View::e($jl->name) ?></span></td>
              <td data-label="URL">
                <a href="<?= View::e($jl->url) ?>" target="_blank" rel="noopener noreferrer" class="link text-muted">
                  <?= View::e($jl->url) ?>
                </a>
              </td>
              <td class="text-muted" data-label="Checked"><?= $jl->linkCheckedAt !== null ? View::e(date($admin_date_format, strtotime($jl->linkCheckedAt))) : '<span class="text-dim">&mdash;</span>' ?></td>
              <td class="actions-col" data-label="">
                <div class="actions-row">
                  <button type="button" class="btn btn-secondary btn-xs lc-tfl-lookup-btn"
                    data-joined-id="<?= $jl->id ?>"
                    data-network="tfl"
                    data-endpoint="<?= $b ?>/admin/link-checker/tfl-lookup-joined"
                    data-apply-endpoint="<?= $b ?>/admin/link-checker/apply-tfl-url-joined"
                    data-csrf="<?= View::e($csrf_token) ?>">TFL</button>
                  <button type="button" class="btn btn-secondary btn-xs lc-tfl-lookup-btn"
                    data-joined-id="<?= $jl->id ?>"
                    data-network="tafl"
                    data-endpoint="<?= $b ?>/admin/link-checker/tfl-lookup-joined"
                    data-apply-endpoint="<?= $b ?>/admin/link-checker/apply-tfl-url-joined"
                    data-csrf="<?= View::e($csrf_token) ?>">TAFL</button>
                  <a href="<?= $b ?>/admin/joined/<?= $jl->id ?>/edit" class="btn btn-tertiary btn-xs">Edit</a>
                </div>
              </td>
            </tr>
            <tr id="lc-tfl-result-<?= $jl->id ?>" style="display:none">
              <td colspan="5" style="padding:.5rem 1rem .75rem">
                <div id="lc-tfl-result-inner-<?= $jl->id ?>" class="form-hint" style="margin:0"></div>
              </td>
            </tr>
            <?php endforeach; ?>
            <?php foreach ($dead_members ?? [] as $mem): ?>
            <tr>
              <td data-label="Type"><span class="badge badge-inactive">Member</span></td>
              <td data-label="Name"><span class="text-strong"><?= View::e((string) $mem->member_name) ?></span></td>
              <td data-label="URL">
                <a href="<?= View::e((string) $mem->member_url) ?>" target="_blank" rel="noopener noreferrer" class="link text-muted">
                  <?= View::e((string) $mem->member_url) ?>
                </a>
              </td>
              <td class="text-muted" data-label="Checked"><?= $mem->link_checked_at !== null ? View::e(date($admin_date_format, strtotime((string) $mem->link_checked_at))) : '<span class="text-dim">&mdash;</span>' ?></td>
              <td class="actions-col" data-label="">
                <a href="<?= $b ?>/admin/members/<?= (int) $mem->listing_id ?>/edit?email=<?= urlencode((string) $mem->member_email) ?>" class="btn btn-tertiary btn-xs">Edit</a>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      </div>
    <?php endif; ?>
  </div>

