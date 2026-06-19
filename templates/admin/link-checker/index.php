<?php

declare(strict_types=1);

use Luster\Core\View;

/** @var \Luster\Models\Affiliate[]      $affiliates */
/** @var \Luster\Models\JoinedListing[]  $joined */
/** @var object[]                        $members */
/** @var \Luster\Models\Affiliate[]      $moved_affiliates */
/** @var \Luster\Models\JoinedListing[]  $moved_joined */
/** @var object[]                        $moved_members */
/** @var \Luster\Models\Affiliate[]      $dead_affiliates */
/** @var \Luster\Models\JoinedListing[]  $dead_joined */
/** @var object[]                        $dead_members */
/** @var array{ok:int,dead:int,redirect:int,unchecked:int} $aff_stats */
/** @var array{ok:int,dead:int,redirect:int,unchecked:int} $joined_stats */
/** @var array{ok:int,dead:int,redirect:int,unchecked:int} $mem_stats */
/** @var int    $aff_total */
/** @var int    $joined_total */
/** @var int    $mem_total */
/** @var int    $aff_page */
/** @var int    $joined_page */
/** @var int    $mem_page */
/** @var int    $aff_total_pages */
/** @var int    $joined_total_pages */
/** @var int    $mem_total_pages */
/** @var ?\Luster\Models\Listing        $mem_listing */
/** @var \Luster\Models\Listing[]        $listings */
/** @var ?int                            $listing_id_filter */
/** @var string $owner_url */
/** @var string $csrf_token */
/** @var string $base_url */
/** @var string $status */
/** @var string $search */
/** @var string $aff_sort */
/** @var string $aff_dir */
/** @var string $joined_sort */
/** @var string $joined_dir */
/** @var string $mem_sort */
/** @var string $mem_dir */
/** @var string $success */
/** @var string $error */

$b = rtrim(View::e($base_url), '/');

$lcBaseQs = static function (array $extra) use ($b, $listing_id_filter, $status, $search, $aff_sort, $aff_dir, $joined_sort, $joined_dir, $mem_sort, $mem_dir): string {
    $qs = http_build_query(array_filter(array_merge([
        'listing_id'  => $listing_id_filter !== null ? (string) $listing_id_filter : '',
        'status'      => $status,
        'search'      => $search,
        'aff_sort'    => $aff_sort !== 'name'          ? $aff_sort    : '',
        'aff_dir'     => $aff_dir  !== 'asc'           ? $aff_dir     : '',
        'joined_sort' => $joined_sort !== 'name'        ? $joined_sort : '',
        'joined_dir'  => $joined_dir  !== 'asc'        ? $joined_dir  : '',
        'mem_sort'    => $mem_sort !== 'member_name'   ? $mem_sort    : '',
        'mem_dir'     => $mem_dir  !== 'asc'           ? $mem_dir     : '',
    ], $extra), static fn(string $v): bool => $v !== ''));
    return $b . '/admin/link-checker' . ($qs !== '' ? '?' . $qs : '');
};

$lcAffPageUrl = static function (int $p) use ($lcBaseQs): string {
    return $lcBaseQs(['tab' => 'affiliates', 'aff_page' => $p > 1 ? (string) $p : '']);
};

$lcJoinedPageUrl = static function (int $p) use ($lcBaseQs): string {
    return $lcBaseQs(['tab' => 'joined', 'joined_page' => $p > 1 ? (string) $p : '']);
};

$lcAffSortUrl = static function (string $col) use ($b, $listing_id_filter, $status, $search, $aff_sort, $aff_dir, $joined_sort, $joined_dir): string {
    $newDir = ($aff_sort === $col && $aff_dir === 'asc') ? 'desc' : 'asc';
    $qs = http_build_query(array_filter([
        'listing_id' => $listing_id_filter !== null ? (string) $listing_id_filter : '',
        'status'     => $status,
        'search'     => $search,
        'aff_sort'   => $col !== 'name' ? $col : '',
        'aff_dir'    => $newDir !== 'asc' ? $newDir : '',
        'joined_sort'=> $joined_sort !== 'name' ? $joined_sort : '',
        'joined_dir' => $joined_dir  !== 'asc'  ? $joined_dir  : '',
        'tab'        => 'affiliates',
    ], static fn(string $v): bool => $v !== ''));
    return $b . '/admin/link-checker' . ($qs !== '' ? '?' . $qs : '');
};

$lcJoinedSortUrl = static function (string $col) use ($b, $listing_id_filter, $status, $search, $aff_sort, $aff_dir, $joined_sort, $joined_dir): string {
    $newDir = ($joined_sort === $col && $joined_dir === 'asc') ? 'desc' : 'asc';
    $qs = http_build_query(array_filter([
        'listing_id'  => $listing_id_filter !== null ? (string) $listing_id_filter : '',
        'status'      => $status,
        'search'      => $search,
        'aff_sort'    => $aff_sort !== 'name' ? $aff_sort : '',
        'aff_dir'     => $aff_dir  !== 'asc'  ? $aff_dir  : '',
        'joined_sort' => $col !== 'name' ? $col : '',
        'joined_dir'  => $newDir !== 'asc' ? $newDir : '',
        'tab'         => 'joined',
    ], static fn(string $v): bool => $v !== ''));
    return $b . '/admin/link-checker' . ($qs !== '' ? '?' . $qs : '');
};

$lcMemPageUrl = static function (int $p) use ($lcBaseQs): string {
    return $lcBaseQs(['tab' => 'members', 'mem_page' => $p > 1 ? (string) $p : '']);
};

$lcMemSortUrl = static function (string $col) use ($b, $listing_id_filter, $status, $search, $aff_sort, $aff_dir, $joined_sort, $joined_dir, $mem_sort, $mem_dir): string {
    $newDir = ($mem_sort === $col && $mem_dir === 'asc') ? 'desc' : 'asc';
    $qs = http_build_query(array_filter([
        'listing_id'  => $listing_id_filter !== null ? (string) $listing_id_filter : '',
        'status'      => $status,
        'search'      => $search,
        'aff_sort'    => $aff_sort !== 'name'        ? $aff_sort    : '',
        'aff_dir'     => $aff_dir  !== 'asc'         ? $aff_dir     : '',
        'joined_sort' => $joined_sort !== 'name'      ? $joined_sort : '',
        'joined_dir'  => $joined_dir  !== 'asc'      ? $joined_dir  : '',
        'mem_sort'    => $col !== 'member_name'      ? $col         : '',
        'mem_dir'     => $newDir !== 'asc'           ? $newDir      : '',
        'tab'         => 'members',
    ], static fn(string $v): bool => $v !== ''));
    return $b . '/admin/link-checker' . ($qs !== '' ? '?' . $qs : '');
};

$lcStatusUrl = static function (string $s) use ($b, $listing_id_filter, $status, $search, $aff_sort, $aff_dir, $joined_sort, $joined_dir, $mem_sort, $mem_dir): string {
    $newStatus = ($status === $s) ? '' : $s;
    $qs = http_build_query(array_filter([
        'listing_id'  => $listing_id_filter !== null ? (string) $listing_id_filter : '',
        'status'      => $newStatus,
        'search'      => $search,
        'aff_sort'    => $aff_sort !== 'name' ? $aff_sort : '',
        'aff_dir'     => $aff_dir  !== 'asc'  ? $aff_dir  : '',
        'joined_sort' => $joined_sort !== 'name'       ? $joined_sort : '',
        'joined_dir'  => $joined_dir  !== 'asc'        ? $joined_dir  : '',
        'mem_sort'    => $mem_sort !== 'member_name'  ? $mem_sort    : '',
        'mem_dir'     => $mem_dir  !== 'asc'          ? $mem_dir     : '',
    ], static fn(string $v): bool => $v !== ''));
    return $b . '/admin/link-checker' . ($qs !== '' ? '?' . $qs : '');
};

$totalMoved    = count($moved_affiliates) + count($moved_joined)  + count($moved_members  ?? []);
$totalDead     = count($dead_affiliates)  + count($dead_joined)   + count($dead_members   ?? []);
$filtersActive = $listing_id_filter !== null || $status !== '' || $search !== '';

$listingNameMap = [];
foreach ($listings ?? [] as $_lst) {
    $listingNameMap[$_lst->id] = $_lst->subject;
}
$filterLabel = match (true) {
    $listing_id_filter === 0    => 'Collective',
    $listing_id_filter !== null => $listingNameMap[$listing_id_filter] ?? '',
    default                     => null,
};

$statusLabel = static function (string $s): string {
    return match ($s) {
        'ok'        => 'Active',
        'dead'      => 'Dead',
        'redirect'  => 'Redirect',
        'found'     => 'Found',
        'missing'   => 'Missing',
        default     => 'Unchecked',
    };
};

$statusBadgeClass = static function (string $s): string {
    return match ($s) {
        'ok', 'found'      => 'badge-current',
        'dead', 'missing' => 'badge-error',
        'redirect'        => 'badge-redirect',
        default           => 'badge-inactive',
    };
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
    <button id="lc-run-btn" class="btn btn-primary btn-sm">Run Link Checker</button>
    <a href="<?= $b ?>/admin/link-checker/settings" class="btn btn-quaternary btn-sm"><?= View::icon('gear') ?>Link Checker Settings</a>
  </div>
</div>

<!-- ── FILTER PANEL ────────────────────────────────────────────────────────── -->
<div class="filter-panel" id="lc-filter-panel">
  <form method="GET" action="<?= $b ?>/admin/link-checker" id="lc-filter-form" class="filter-row">
    <input type="hidden" name="tab" value="affiliates" id="lc-filter-tab">
    <?php if (!empty($listings)): ?>
    <div class="select-wrap">
      <?php $_lcSc = $aff_scope_counts ?? []; $_lcTotal = array_sum($_lcSc); ?>
      <select name="listing_id" id="lc-listing-sel" aria-label="Filter by listing">
        <option value=""><?= $_lcTotal > 0 ? 'All Listings (' . $_lcTotal . ')' : 'All Listings' ?></option>
        <?php $_lcCollN = (int) ($_lcSc[0] ?? 0); ?>
        <?php $_lcCollLabel = ($app_name ?? '') !== '' ? View::e($app_name) . ' (Collective)' : 'Collective'; ?>
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
    <button class="tabs-btn active" data-tab="affiliates">Affiliates</button>
    <button class="tabs-btn" data-tab="joined">Joined</button>
    <?php if ($listing_id_filter !== null && $listing_id_filter > 0): ?>
    <button class="tabs-btn" data-tab="members">Members<?php if ((int) ($mem_total ?? 0) > 0): ?> <span class="badge badge-inactive"><?= (int) $mem_total ?></span><?php endif; ?></button>
    <?php endif; ?>
    <button class="tabs-btn" data-tab="moved">Redirected<?php if ($totalMoved > 0): ?> <span class="badge badge-redirect"><?= $totalMoved ?></span><?php endif; ?></button>
    <button class="tabs-btn" data-tab="dead">Dead<?php if ($totalDead > 0): ?> <span class="badge badge-error"><?= $totalDead ?></span><?php endif; ?></button>
  </div>
</div>
<div class="tabs-select-wrap">
  <span class="overview-section-title">Section</span>
  <div class="select-wrap flex-fill">
    <select class="tabs-select" aria-label="Select tab">
      <option value="affiliates">Affiliates<?= (int) ($aff_total ?? 0) > 0 ? ' (' . (int) $aff_total . ')' : '' ?></option>
      <option value="joined">Joined<?= (int) ($joined_total ?? 0) > 0 ? ' (' . (int) $joined_total . ')' : '' ?></option>
      <?php if ($listing_id_filter !== null && $listing_id_filter > 0): ?>
      <option value="members">Members<?= (int) ($mem_total ?? 0) > 0 ? ' (' . (int) $mem_total . ')' : '' ?></option>
      <?php endif; ?>
      <option value="moved">Redirected<?= $totalMoved > 0 ? ' (' . $totalMoved . ')' : '' ?></option>
      <option value="dead">Dead<?= $totalDead > 0 ? ' (' . $totalDead . ')' : '' ?></option>
    </select>
  </div>
</div>

  <!-- ── Affiliates panel ─────────────────────────────────────────────────── -->
  <div id="tab-affiliates" class="tabs-panel active" role="tabpanel">
    <button id="lc-check-affiliates" style="display:none"
            data-csrf="<?= View::e($csrf_token) ?>"
            data-endpoint="<?= $b ?>/admin/link-checker/check-affiliate"
            data-ids-url="<?= View::e($b . '/admin/link-checker/all-ids?panel=affiliates' . ($listing_id_filter !== null ? '&listing_id=' . $listing_id_filter : '')) ?>"
            data-panel="affiliates"></button>
    <div class="lc-progress-wrap is-hidden" id="lc-prog-affiliates">
      <div class="lc-progress" role="progressbar" aria-valuemin="0" aria-valuemax="100">
        <div class="lc-progress-bar" id="lc-prog-bar-affiliates" style="width:0%"></div>
      </div>
      <span class="lc-prog-label" id="lc-prog-label-affiliates">0 / <?= count($affiliates) ?></span>
    </div>

    <?php if (empty($affiliates)): ?>
    <div class="card">
      <div class="empty">
        <div class="empty-icon"><?= View::icon('link', ['width' => 32, 'height' => 32]) ?></div>
        <div class="empty-title">No affiliates found.</div>
        <div>Add some affiliates to start checking links.</div>
        <a href="<?= $b ?>/admin/affiliates/add" class="btn btn-primary btn-sm"><?= View::icon('add') ?> Add Affiliate</a>
      </div>
    </div>
    <?php else: ?>
      <div class="card">
      <div class="card-head">
        <span><?= $filterLabel !== null ? View::e($filterLabel) : 'All Listings' ?></span>
        <span class="row-sub">Showing <?= ($aff_page - 1) * $lc_per_page + 1 ?>&#8211;<?= ($aff_page - 1) * $lc_per_page + count($affiliates) ?> of <?= number_format($aff_total) ?> affiliate<?= $aff_total !== 1 ? 's' : '' ?></span>
        <div class="stat-pills" style="margin-left:auto">
          <a href="<?= View::e($lcStatusUrl('ok')) ?>" class="badge badge-current<?= $status === 'ok' ? ' badge--active' : '' ?>"><?= $aff_stats['ok'] ?> Active</a>
          <a href="<?= View::e($lcStatusUrl('dead')) ?>" class="badge badge-error<?= $status === 'dead' ? ' badge--active' : '' ?>"><?= $aff_stats['dead'] ?> Dead</a>
          <a href="<?= View::e($lcStatusUrl('redirect')) ?>" class="badge badge-redirect<?= $status === 'redirect' ? ' badge--active' : '' ?>"><?= $aff_stats['redirect'] ?> Redirect</a>
          <a href="<?= View::e($lcStatusUrl('unchecked')) ?>" class="badge badge-inactive<?= $status === 'unchecked' ? ' badge--active' : '' ?>"><?= $aff_stats['unchecked'] ?> Unchecked</a>
        </div>
      </div>
      <div class="table-wrap">
        <table class="data-table mem-table">
          <thead>
            <tr>
              <th><a href="<?= View::e($lcAffSortUrl('name')) ?>" class="sort-th<?= $aff_sort === 'name' ? ' sort-active' : '' ?>">Name <?= View::sortIcon('name', $aff_sort, $aff_dir) ?></a></th>
              <th>URL</th>
              <th><a href="<?= View::e($lcAffSortUrl('link_status')) ?>" class="sort-th<?= $aff_sort === 'link_status' ? ' sort-active' : '' ?>">Link Status <?= View::sortIcon('link_status', $aff_sort, $aff_dir) ?></a></th>
              <th><a href="<?= View::e($lcAffSortUrl('reciprocal')) ?>" class="sort-th<?= $aff_sort === 'reciprocal' ? ' sort-active' : '' ?>">Reciprocal <?= View::sortIcon('reciprocal', $aff_sort, $aff_dir) ?></a></th>
              <th><a href="<?= View::e($lcAffSortUrl('last_checked')) ?>" class="sort-th<?= $aff_sort === 'last_checked' ? ' sort-active' : '' ?>">Last Checked <?= View::sortIcon('last_checked', $aff_sort, $aff_dir) ?></a></th>
              <th class="act-col">Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($affiliates as $aff): ?>
            <tr id="lc-aff-<?= $aff->id ?>">
              <td data-label="Name">
                <div class="subject-title-stack">
                  <span class="text-strong"><?= View::e($aff->name) ?></span>
                  <?php if ($listing_id_filter === null): ?>
                    <span class="mem-url"><?= $aff->listingId === null ? 'Collective' : View::e($listingNameMap[$aff->listingId] ?? '') ?></span>
                  <?php endif; ?>
                </div>
              </td>
              <td data-label="URL">
                <a href="<?= View::e($aff->url) ?>" target="_blank" rel="noopener noreferrer" class="link">
                  <?= View::e($aff->url) ?>
                </a>
                <?php if ($aff->linkRedirectUrl !== null): ?>
                  <div class="lc-redirect-info">
                    &rarr; <a href="<?= View::e($aff->linkRedirectUrl) ?>" target="_blank" rel="noopener noreferrer" class="link"><?= View::e($aff->linkRedirectUrl) ?></a>
                  </div>
                <?php endif; ?>
              </td>
              <td data-label="Link Status">
                <span class="badge <?= View::e($statusBadgeClass($aff->linkStatus)) ?>" data-lc-link-badge>
                  <?= View::e($statusLabel($aff->linkStatus)) ?>
                </span>
              </td>
              <td data-label="Reciprocal">
                <span class="badge <?= View::e($statusBadgeClass($aff->reciprocalStatus)) ?>" data-lc-recip-badge>
                  <?= View::e($statusLabel($aff->reciprocalStatus)) ?>
                </span>
              </td>
              <td class="lc-checked-cell text-muted" data-label="Last Checked" data-lc-checked>
                <?= $aff->linkCheckedAt !== null ? View::e(date($admin_date_format, strtotime($aff->linkCheckedAt))) : '<span class="text-dim">&mdash;</span>' ?>
              </td>
              <td class="act-col" data-label="">
                <button class="btn btn-quaternary btn-xs js-lc-single"
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
    <div class="lc-progress-wrap is-hidden" id="lc-prog-joined">
      <div class="lc-progress" role="progressbar" aria-valuemin="0" aria-valuemax="100">
        <div class="lc-progress-bar" id="lc-prog-bar-joined" style="width:0%"></div>
      </div>
      <span class="lc-prog-label" id="lc-prog-label-joined">0 / <?= count($joined) ?></span>
    </div>

    <?php if (empty($joined)): ?>
    <div class="card">
      <div class="empty">
        <div class="empty-icon"><?= View::icon('heart', ['width' => 32, 'height' => 32]) ?></div>
        <div class="empty-title">No joined listings found.</div>
        <div>Add some joined listings to start checking links.</div>
        <a href="<?= $b ?>/admin/joined/add" class="btn btn-primary btn-xs"><?= View::icon('add') ?> Add Joined Listing</a>
      </div>
    </div>
    <?php else: ?>
      <div class="card">
      <div class="card-head">
        <span>Joined Listings</span>
        <span class="row-sub">Showing <?= ($joined_page - 1) * $lc_per_page + 1 ?>&#8211;<?= ($joined_page - 1) * $lc_per_page + count($joined) ?> of <?= number_format($joined_total) ?> listing<?= $joined_total !== 1 ? 's' : '' ?></span>
        <div class="stat-pills" style="margin-left:auto">
          <a href="<?= View::e($lcStatusUrl('ok')) ?>" class="badge badge-current<?= $status === 'ok' ? ' badge--active' : '' ?>"><?= $joined_stats['ok'] ?> Active</a>
          <a href="<?= View::e($lcStatusUrl('dead')) ?>" class="badge badge-error<?= $status === 'dead' ? ' badge--active' : '' ?>"><?= $joined_stats['dead'] ?> Dead</a>
          <a href="<?= View::e($lcStatusUrl('redirect')) ?>" class="badge badge-redirect<?= $status === 'redirect' ? ' badge--active' : '' ?>"><?= $joined_stats['redirect'] ?> Redirect</a>
          <a href="<?= View::e($lcStatusUrl('unchecked')) ?>" class="badge badge-inactive<?= $status === 'unchecked' ? ' badge--active' : '' ?>"><?= $joined_stats['unchecked'] ?> Unchecked</a>
        </div>
      </div>
      <div class="table-wrap">
        <table class="data-table mem-table">
          <thead>
            <tr>
              <th><a href="<?= View::e($lcJoinedSortUrl('name')) ?>" class="sort-th<?= $joined_sort === 'name' ? ' sort-active' : '' ?>">Name <?= View::sortIcon('name', $joined_sort, $joined_dir) ?></a></th>
              <th>URL</th>
              <th><a href="<?= View::e($lcJoinedSortUrl('link_status')) ?>" class="sort-th<?= $joined_sort === 'link_status' ? ' sort-active' : '' ?>">Status <?= View::sortIcon('link_status', $joined_sort, $joined_dir) ?></a></th>
              <th><a href="<?= View::e($lcJoinedSortUrl('last_checked')) ?>" class="sort-th<?= $joined_sort === 'last_checked' ? ' sort-active' : '' ?>">Last Checked <?= View::sortIcon('last_checked', $joined_sort, $joined_dir) ?></a></th>
              <th class="act-col">Actions</th>
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
                  <div class="lc-redirect-info">
                    &rarr; <a href="<?= View::e($jl->linkRedirectUrl) ?>" target="_blank" rel="noopener noreferrer" class="link"><?= View::e($jl->linkRedirectUrl) ?></a>
                  </div>
                <?php endif; ?>
              </td>
              <td data-label="Status">
                <span class="badge <?= View::e($statusBadgeClass($jl->linkStatus)) ?>" data-lc-link-badge>
                  <?= View::e($statusLabel($jl->linkStatus)) ?>
                </span>
              </td>
              <td class="lc-checked-cell text-muted" data-label="Last Checked" data-lc-checked>
                <?= $jl->linkCheckedAt !== null ? View::e(date($admin_date_format, strtotime($jl->linkCheckedAt))) : '<span class="text-dim">&mdash;</span>' ?>
              </td>
              <td class="act-col" data-label="">
                <button class="btn btn-quaternary btn-xs js-lc-single"
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

  <!-- ── Members panel ───────────────────────────────────────────────────── -->
  <div id="tab-members" class="tabs-panel" role="tabpanel">
    <?php if ($listing_id_filter === null || $listing_id_filter <= 0): ?>
    <div class="card">
      <div class="empty">
        <div class="empty-icon"><?= View::icon('members', ['width' => 32, 'height' => 32]) ?></div>
        <div class="empty-title">Select a listing to check member URLs.</div>
        <div>Use the Listing filter above to choose a specific listing first.</div>
      </div>
    </div>
    <?php else: ?>
      <button id="lc-check-members" style="display:none"
              data-csrf="<?= View::e($csrf_token) ?>"
              data-endpoint="<?= $b ?>/admin/link-checker/check-member"
              data-ids-url="<?= View::e($b . '/admin/link-checker/all-ids?panel=members&listing_id=' . $listing_id_filter) ?>"
              data-panel="members"></button>
      <div class="lc-progress-wrap is-hidden" id="lc-prog-members">
        <div class="lc-progress" role="progressbar" aria-valuemin="0" aria-valuemax="100">
          <div class="lc-progress-bar" id="lc-prog-bar-members" style="width:0%"></div>
        </div>
        <span class="lc-prog-label" id="lc-prog-label-members">0 / <?= count($members ?? []) ?></span>
      </div>
      <div class="card">
        <div class="card-head">
          <span>Member URLs<?php if ($mem_listing !== null): ?> &mdash; <?= View::e($mem_listing->title) ?><?php endif; ?></span>
          <?php if ($mem_total > 0): ?>
          <span class="row-sub">Showing <?= ($mem_page - 1) * $lc_per_page + 1 ?>&#8211;<?= ($mem_page - 1) * $lc_per_page + count($members ?? []) ?> of <?= number_format($mem_total) ?> member<?= $mem_total !== 1 ? 's' : '' ?></span>
          <?php endif; ?>
          <div class="stat-pills" style="margin-left:auto">
            <a href="<?= View::e($lcStatusUrl('ok')) ?>" class="badge badge-current<?= $status === 'ok' ? ' badge--active' : '' ?>"><?= (int) ($mem_stats['ok'] ?? 0) ?> Active</a>
            <a href="<?= View::e($lcStatusUrl('dead')) ?>" class="badge badge-error<?= $status === 'dead' ? ' badge--active' : '' ?>"><?= (int) ($mem_stats['dead'] ?? 0) ?> Dead</a>
            <a href="<?= View::e($lcStatusUrl('redirect')) ?>" class="badge badge-redirect<?= $status === 'redirect' ? ' badge--active' : '' ?>"><?= (int) ($mem_stats['redirect'] ?? 0) ?> Redirect</a>
            <a href="<?= View::e($lcStatusUrl('unchecked')) ?>" class="badge badge-inactive<?= $status === 'unchecked' ? ' badge--active' : '' ?>"><?= (int) ($mem_stats['unchecked'] ?? 0) ?> Unchecked</a>
          </div>
          <form method="POST" action="<?= $b ?>/admin/link-checker/scan-members" class="action-form-inline" style="margin-left:.5rem">
            <input type="hidden" name="_csrf"       value="<?= View::e($csrf_token) ?>">
            <input type="hidden" name="listing_id"  value="<?= (int) $listing_id_filter ?>">
            <button type="submit" class="btn btn-secondary btn-sm"><?= View::icon('refresh') ?> Scan</button>
          </form>
        </div>
        <?php if (empty($members)): ?>
        <div class="empty">
          <div class="empty-icon"><?= View::icon('members', ['width' => 32, 'height' => 32]) ?></div>
          <div class="empty-title">No member URLs scanned yet.</div>
          <div>Click <strong>Scan</strong> to sync member URLs from this listing into the check table, then run the checker.</div>
        </div>
        <?php else: ?>
        <div class="table-wrap">
          <table class="data-table mem-table">
            <thead>
              <tr>
                <th><a href="<?= View::e($lcMemSortUrl('member_name')) ?>" class="sort-th<?= $mem_sort === 'member_name' ? ' sort-active' : '' ?>">Name <?= View::sortIcon('member_name', $mem_sort, $mem_dir) ?></a></th>
                <th><a href="<?= View::e($lcMemSortUrl('member_url')) ?>" class="sort-th<?= $mem_sort === 'member_url' ? ' sort-active' : '' ?>">URL <?= View::sortIcon('member_url', $mem_sort, $mem_dir) ?></a></th>
                <th><a href="<?= View::e($lcMemSortUrl('link_status')) ?>" class="sort-th<?= $mem_sort === 'link_status' ? ' sort-active' : '' ?>">Status <?= View::sortIcon('link_status', $mem_sort, $mem_dir) ?></a></th>
                <th><a href="<?= View::e($lcMemSortUrl('link_checked_at')) ?>" class="sort-th<?= $mem_sort === 'link_checked_at' ? ' sort-active' : '' ?>">Last Checked <?= View::sortIcon('link_checked_at', $mem_sort, $mem_dir) ?></a></th>
                <th class="act-col">Actions</th>
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
                    <div class="lc-redirect-info">
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
                </td>
                <td class="lc-checked-cell text-muted" data-label="Last Checked" data-lc-checked>
                  <?= $mem->link_checked_at !== null ? View::e(date($admin_date_format, strtotime((string) $mem->link_checked_at))) : '<span class="text-dim">&mdash;</span>' ?>
                </td>
                <td class="act-col" data-label="">
                  <button class="btn btn-quaternary btn-xs js-lc-single"
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
      <?php if ($mem_total > 0): ?>
      <div class="actions-row actions-row--right" style="margin-top:.75rem">
        <form method="POST" action="<?= $b ?>/admin/link-checker/reset-members" onsubmit="return confirm('Reset all member link check statuses for this listing?')">
          <input type="hidden" name="_csrf"       value="<?= View::e($csrf_token) ?>">
          <input type="hidden" name="listing_id"  value="<?= (int) $listing_id_filter ?>">
          <button type="submit" class="btn btn-tertiary btn-sm">Reset Member Statuses</button>
        </form>
      </div>
      <?php endif; ?>
    <?php endif; ?>
  </div>

  <!-- ── Redirected Links panel ──────────────────────────────────────────── -->
  <div id="tab-moved" class="tabs-panel" role="tabpanel">
    <p>These URLs respond with an HTTP redirect, meaning the content has moved to a new address. Click <strong>Update URL</strong> to replace the stored URL with the redirect target. The record will be re-checked on the next run.</p>
    <?php if ($totalMoved === 0): ?>
    <div class="card">
      <div class="empty">
        <div class="empty-icon"><?= View::icon('link', ['width' => 32, 'height' => 32]) ?></div>
        <div class="empty-title">No redirected links found.</div>
        <div>Run the checker on the Affiliates or Joined Listings tabs first.</div>
      </div>
    </div>
    <?php else: ?>
      <div class="card">
      <div class="card-head">
        <span>Redirected Links</span>
        <span class="row-sub">Showing 1&#8211;<?= $totalMoved ?> of <?= $totalMoved ?> link<?= $totalMoved !== 1 ? 's' : '' ?></span>
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
              <td data-label="Type"><span class="badge badge-info-pink">Affiliate</span></td>
              <td data-label="Name"><span class="text-strong"><?= View::e($aff->name) ?></span></td>
              <td data-label="Current URL">
                <a href="<?= View::e($aff->url) ?>" target="_blank" rel="noopener noreferrer" class="link lc-old-url">
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
                <div class="data-actions">
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
                <a href="<?= View::e($jl->url) ?>" target="_blank" rel="noopener noreferrer" class="link lc-old-url">
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
                <div class="data-actions">
                  <?php if ($jl->linkRedirectUrl !== null): ?>
                  <form method="POST" action="<?= $b ?>/admin/link-checker/apply-joined-redirect" class="action-form-inline">
                    <input type="hidden" name="_csrf" value="<?= View::e($csrf_token) ?>">
                    <input type="hidden" name="id"    value="<?= $jl->id ?>">
                    <button type="submit" class="btn btn-primary btn-xs">Update</button>
                  </form>
                  <?php endif; ?>
                  <a href="<?= $b ?>/admin/joined/<?= $jl->id ?>/edit" class="btn btn-tertiary btn-xs">Edit</a>
                </div>
              </td>
            </tr>
            <?php endforeach; ?>
            <?php foreach ($moved_members ?? [] as $mem): ?>
            <tr>
              <td data-label="Type"><span class="badge badge-inactive">Member</span></td>
              <td data-label="Name"><span class="text-strong"><?= View::e((string) $mem->member_name) ?></span></td>
              <td data-label="Current URL">
                <a href="<?= View::e((string) $mem->member_url) ?>" target="_blank" rel="noopener noreferrer" class="link lc-old-url">
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
              <td data-label="">
                <div class="data-actions">
                  <?php if ($mem->link_redirect_url !== null): ?>
                  <form method="POST" action="<?= $b ?>/admin/link-checker/apply-member-redirect" class="action-form-inline">
                    <input type="hidden" name="_csrf"       value="<?= View::e($csrf_token) ?>">
                    <input type="hidden" name="id"          value="<?= (int) $mem->id ?>">
                    <input type="hidden" name="listing_id"  value="<?= (int) $mem->listing_id ?>">
                    <button type="submit" class="btn btn-primary btn-xs">Update</button>
                  </form>
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
  </div>

  <!-- ── Dead Links panel ─────────────────────────────────────────────────── -->
  <div id="tab-dead" class="tabs-panel" role="tabpanel">
    <?php if ($totalDead === 0): ?>
    <div class="card">
      <div class="empty">
        <div class="empty-icon"><?= View::icon('caution', ['width' => 32, 'height' => 32]) ?></div>
        <div class="empty-title">No dead links found.</div>
        <div>Run the checker on the Affiliates or Joined Listings tabs first.</div>
      </div>
    </div>
    <?php else: ?>
        <p>These URLs returned an error or could not be reached. Review and remove or replace the records as needed.</p>
      <div class="card">
      <div class="card-head">
        <span>Dead Links</span>
        <span class="row-sub">Showing 1&#8211;<?= $totalDead ?> of <?= $totalDead ?> link<?= $totalDead !== 1 ? 's' : '' ?></span>
      </div>
      <div class="table-wrap">
        <table class="data-table mem-table">
          <thead>
            <tr>
              <th>Type</th>
              <th>Name</th>
              <th>URL</th>
              <th>Checked</th>
              <th class="act-col">Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($dead_affiliates as $aff): ?>
            <tr>
              <td data-label="Type"><span class="badge badge-info-pink">Affiliate</span></td>
              <td data-label="Name"><span class="text-strong"><?= View::e($aff->name) ?></span></td>
              <td data-label="URL">
                <a href="<?= View::e($aff->url) ?>" target="_blank" rel="noopener noreferrer" class="link lc-old-url">
                  <?= View::e($aff->url) ?>
                </a>
              </td>
              <td class="text-muted" data-label="Checked"><?= $aff->linkCheckedAt !== null ? View::e(date($admin_date_format, strtotime($aff->linkCheckedAt))) : '<span class="text-dim">&mdash;</span>' ?></td>
              <td class="act-col" data-label="">
                <a href="<?= $b ?>/admin/affiliates/<?= $aff->id ?>/edit" class="btn btn-tertiary btn-xs">Edit</a>
              </td>
            </tr>
            <?php endforeach; ?>
            <?php foreach ($dead_joined as $jl): ?>
            <tr>
              <td data-label="Type"><span class="badge badge-info">Joined</span></td>
              <td data-label="Name"><span class="text-strong"><?= View::e($jl->name) ?></span></td>
              <td data-label="URL">
                <a href="<?= View::e($jl->url) ?>" target="_blank" rel="noopener noreferrer" class="link lc-old-url">
                  <?= View::e($jl->url) ?>
                </a>
              </td>
              <td class="text-muted" data-label="Checked"><?= $jl->linkCheckedAt !== null ? View::e(date($admin_date_format, strtotime($jl->linkCheckedAt))) : '<span class="text-dim">&mdash;</span>' ?></td>
              <td class="act-col" data-label="">
                <a href="<?= $b ?>/admin/joined/<?= $jl->id ?>/edit" class="btn btn-tertiary btn-xs">Edit</a>
              </td>
            </tr>
            <?php endforeach; ?>
            <?php foreach ($dead_members ?? [] as $mem): ?>
            <tr>
              <td data-label="Type"><span class="badge badge-inactive">Member</span></td>
              <td data-label="Name"><span class="text-strong"><?= View::e((string) $mem->member_name) ?></span></td>
              <td data-label="URL">
                <a href="<?= View::e((string) $mem->member_url) ?>" target="_blank" rel="noopener noreferrer" class="link lc-old-url">
                  <?= View::e((string) $mem->member_url) ?>
                </a>
              </td>
              <td class="text-muted" data-label="Checked"><?= $mem->link_checked_at !== null ? View::e(date($admin_date_format, strtotime((string) $mem->link_checked_at))) : '<span class="text-dim">&mdash;</span>' ?></td>
              <td class="act-col" data-label="">&mdash;</td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      </div>
    <?php endif; ?>
  </div>

<script>
(function () {
  var tabInput = document.getElementById('lc-filter-tab');
  var runBtn   = document.getElementById('lc-run-btn');

  function onTabChange(tabId) {
    if (tabInput) tabInput.value = tabId;
    if (runBtn) runBtn.style.display = (tabId === 'affiliates' || tabId === 'joined' || tabId === 'members') ? '' : 'none';
  }

  document.querySelectorAll('.tabs-btn[data-tab]').forEach(function (btn) {
    btn.addEventListener('click', function () { onTabChange(btn.dataset.tab); });
  });

  var tabSelect = document.querySelector('.tabs-select');
  if (tabSelect) {
    tabSelect.addEventListener('change', function () { onTabChange(tabSelect.value); });
  }
})();
</script>
