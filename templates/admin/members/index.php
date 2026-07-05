<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Unified members page. Serves three view modes from a single template: overview
 (all listings with member/pending counts), per-listing member table (sortable,
 filterable, with status tabs and bulk actions), and cross-listing search results.
 Also shows pending member updates when viewing a specific listing.
*/
use Luster\Core\View;

$b      = rtrim(View::e($base_url), '/');
$lid    = (int) ($listing_id ?? 0);
$status = $status ?? 'all';
$search = $search ?? '';
$sort   = $sort   ?? 'name';
$dir    = $dir    ?? 'asc';
$puPage = (int) ($pu_page ?? 1);

$tabCounts      = $tab_counts   ?? ['all' => 0, 'pending' => 0, 'approved' => 0];
$pendingPUs     = $listing_pending_updates ?? [];
$puMemberMap    = $pending_member_data     ?? [];
$pendingPUTotal = (int) ($pending_updates_total ?? count($pendingPUs));
$pendingPUPages = (int) ($pending_updates_pages ?? 1);

// --- URL builders
$buildUrl = static function (array $overrides) use ($b, $lid, $status, $search, $sort, $dir, $page, $puPage): string {
    $p = [
        'listing_id' => $lid  > 0    ? $lid    : '',
        'status'     => $status,
        'search'     => $search,
        'sort'       => $sort !== 'name' ? $sort : '',
        'dir'        => $dir  !== 'asc'  ? $dir  : '',
        'page'       => ($page ?? 1) > 1 ? ($page ?? 1) : '',
        'pu_page'    => $puPage > 1 ? $puPage : '',
    ];
    $merged = array_merge($p, $overrides);
    $qs = http_build_query(array_filter($merged, static fn($v) => $v !== '' && $v !== 0 && $v !== null));
    return $b . '/admin/members' . ($qs !== '' ? '?' . $qs : '');
};

$tabUrl  = static fn(string $t) => $buildUrl(['status' => $t, 'page' => '', 'pu_page' => '']);
$sortUrl = static function (string $col) use ($buildUrl, $sort, $dir): string {
    $newDir = ($sort === $col && $dir === 'asc') ? 'desc' : 'asc';
    return $buildUrl(['sort' => $col !== 'name' ? $col : '', 'dir' => $newDir !== 'asc' ? $newDir : '', 'page' => '']);
};
$resetUrl = $b . '/admin/members?status=' . urlencode($status);

$tabDefs = [
    'all'      => ['label' => 'All',      'badge' => 'badge-inactive'],
    'pending'  => ['label' => 'Pending',  'badge' => 'badge-pending'],
    'approved' => ['label' => 'Approved', 'badge' => 'badge-current'],
    'rejected' => ['label' => 'Rejected', 'badge' => 'badge-error'],
];
?>

<?php if ($success !== ''): ?>
  <div class="alert alert-success"><?= View::e($success) ?></div>
<?php endif; ?>
<?php if ($error !== ''): ?>
  <div class="alert alert-error"><?= View::e($error) ?></div>
<?php endif; ?>

<!-- ──── TOOLBAR ─────────────────────────────────────────────────────────── -->
<div class="toolbar">
  <div class="actions-row">
    <button type="button" class="btn btn-preview btn-sm filter-toggle" id="mem-filter-toggle"
            aria-expanded="true" aria-controls="mem-filter-panel">
      <?= View::icon('filter') ?>
      Filter<?php if ($filters_active ?? false): ?><span class="filter-dot" aria-hidden="true"></span><?php endif; ?>
    </button>
  </div>
  <div class="actions-row">
    <?php if ($lid > 0): ?>
      <a href="<?= $b ?>/admin/import-members" class="btn btn-quaternary btn-sm">&#8595; Import Members</a>
      <a href="<?= $b ?>/admin/members/<?= $lid ?>/export" class="btn btn-quaternary btn-sm">&#8593; Export CSV</a>
    <?php else: ?>
      <a href="<?= $b ?>/admin/members/export-pending" class="btn btn-quaternary btn-sm">&#8593; Export Pending</a>
    <?php endif; ?>
    <a href="<?= $b ?>/admin/members/settings" class="btn btn-quaternary btn-sm"><?= View::icon('gear') ?>Member Settings</a>
  </div>
</div>

<!-- ──── FILTER PANEL ────────────────────────────────────────────────────── -->
<div class="filter-panel" id="mem-filter-panel">
  <form method="GET" action="<?= $b ?>/admin/members" id="mem-filter-form" class="filter-row">
    <input type="hidden" name="status" value="<?= View::e($status) ?>">
    <?php if ($sort !== 'name'): ?><input type="hidden" name="sort" value="<?= View::e($sort) ?>"><?php endif; ?>
    <?php if ($dir  !== 'asc'):  ?><input type="hidden" name="dir"  value="<?= View::e($dir) ?>"><?php endif; ?>

    <div class="select-wrap">
      <select name="listing_id" id="mem-listing-sel">
        <?php $_memListingCounts = $listing_counts ?? []; ?>
        <?php $_allMemTotal = array_sum(array_map(static fn($c) => ($c['approved'] ?? 0) + ($c['pending'] ?? 0) + ($c['rejected'] ?? 0), $_memListingCounts)); ?>
        <option value="0"<?= $lid === 0 ? ' selected' : '' ?>>All Listings<?= $_allMemTotal > 0 ? ' (' . $_allMemTotal . ')' : '' ?></option>
        <?php
          $_memListings = array_values(array_filter($all_listings, static fn($fl) => $fl->dbprefix !== ''));
          usort($_memListings, static fn($a, $b) => strcasecmp($a->subject ?: $a->title, $b->subject ?: $b->title));
        ?>
        <?php foreach ($_memListings as $fl): ?>
          <?php $_flC = $_memListingCounts[(int) $fl->id] ?? null; ?>
          <?php $_flT = $_flC !== null ? (($_flC['approved'] ?? 0) + ($_flC['pending'] ?? 0) + ($_flC['rejected'] ?? 0)) : null; ?>
          <option value="<?= (int) $fl->id ?>"<?= (int) $fl->id === $lid ? ' selected' : '' ?>>
            <?= View::e($fl->subject ?: $fl->title) ?><?= $_flT !== null ? ' (' . $_flT . ')' : '' ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="search-field-wrap" style="flex:1">
      <?= View::icon('search', ['class' => 'search-field-icon', 'aria-hidden' => 'true']) ?>
      <input type="text" name="search" class="form"
             placeholder="<?= $lid > 0 ? 'Search members&hellip;' : 'Search all listings&hellip;' ?>"
             value="<?= View::e($search) ?>">
    </div>

    <button type="submit" class="btn btn-secondary btn-sm">Search</button>
    <a href="<?= View::e($resetUrl) ?>" class="btn btn-quaternary btn-sm">Reset</a>
  </form>
</div>

<!-- ──── STATUS TABS ─────────────────────────────────────────────────────── -->
<div class="tabs-wrap">
  <div class="tabs">
    <?php foreach ($tabDefs as $key => $td): ?>
      <a href="<?= View::e($tabUrl($key)) ?>"
         class="tabs-btn<?= $status === $key ? ' active' : '' ?>">
        <?= View::e($td['label']) ?>
        <?php $cnt = (int) ($tabCounts[$key] ?? 0); ?>
        <?php if ($cnt > 0): ?>
          <span class="badge <?= $td['badge'] ?> badge-inline-sm"><?= $cnt ?></span>
        <?php endif; ?>
      </a>
    <?php endforeach; ?>
  </div>
</div>
<div class="tabs-select-wrap">
  <span class="overview-section-title">Status</span>
  <div class="select-wrap flex-fill">
    <select class="js-nav-select">
      <?php foreach ($tabDefs as $key => $td): ?>
        <option value="<?= View::e($tabUrl($key)) ?>"<?= $status === $key ? ' selected' : '' ?>>
          <?= View::e($td['label']) ?> (<?= (int) ($tabCounts[$key] ?? 0) ?>)
        </option>
      <?php endforeach; ?>
    </select>
  </div>
</div>

<!-- ════════════════════════════════════════════════════════════════════════ -->
<?php if ($lid > 0 && $listing !== null): ?>
<!-- ── PER-LISTING VIEW ────────────────────────────────────────────────── -->

<?php if ($status === 'pending'): ?>
<?php
$pendingNew    = array_values(array_filter($members ?? [], static fn($m) => !$m->approved));
$pendingNewCnt = count($pendingNew);
?>

<?php if ((int) $total === 0 && $pendingPUTotal === 0): ?>
<div class="card">
  <div class="empty">
    <div class="empty-icon"><?= View::icon('pending', ['width' => 32, 'height' => 32]) ?></div>
    <div><strong>Nothing pending.</strong></div>
    <div>You're all caught up.</div>
  </div>
</div>

<?php else: ?>

<?php if ($pendingNewCnt > 0): ?>
<!-- ── Section 1: Pending new joins ─────────────────────────────── -->
<form method="POST" action="<?= $b ?>/admin/members/<?= $lid ?>/bulk"
      data-del-bulk data-del-entity="member_reject">
  <input type="hidden" name="_csrf"            value="<?= View::e($csrf_token ?? '') ?>">
  <input type="hidden" name="sa_filter_status" value="pending">
  <input type="hidden" name="sa_filter_search" value="<?= View::e($search) ?>">

  <div class="card">
    <div class="pending-section-head">
      <span class="composer-label"><?= View::icon('pending') ?> New Join Requests</span>
      <?php
        $_plFrom = (int) $total > 0 ? ($page - 1) * $per_page + 1 : 0;
        $_plTo   = min((int) $total, $page * $per_page);
      ?>
      <span class="card-head-sub">Showing <?= $_plFrom ?>&ndash;<?= $_plTo ?> of <?= number_format((int) $total) ?> member<?= (int) $total !== 1 ? 's' : '' ?></span>
      <div class="actions-row actions-row--right actions-row--wrap">
        <form method="POST" action="<?= $b ?>/admin/members/<?= $lid ?>/approve-all-pending"
              class="action-form-inline"
              data-confirm="Approve all <?= $pendingNewCnt ?> pending member(s)? This will send approval emails.">
          <input type="hidden" name="_csrf" value="<?= View::e($csrf_token ?? '') ?>">
          <button type="submit" class="btn btn-success btn-sm">Approve All Pending</button>
        </form>
      </div>
    </div>
    <div class="bulk">
      Bulk Actions:
      <div class="select-wrap">
        <select name="bulk_action" class="select-xs" id="new-join-bulk-action">
          <option value="" disabled selected>&mdash; Select &mdash;</option>
          <option value="approve">Approve</option>
          <option value="delete">Reject</option>
          <option value="email_selected">Email</option>
        </select>
      </div>
      <button type="submit" class="btn btn-quaternary btn-sm">Apply to Selected</button>
    </div>
    <div class="table-wrap">
      <table class="data-table mem-table">
        <thead>
          <tr>
            <th class="checkbox-col"><input type="checkbox" id="new-join-check-all"></th>
            <th><a href="<?= View::e($sortUrl('name')) ?>" class="sort-th<?= $sort === 'name' ? ' sort-active' : '' ?>">Name <?= View::sortIcon('name', $sort, $dir) ?></a></th>
            <th><a href="<?= View::e($sortUrl('email')) ?>" class="sort-th<?= $sort === 'email' ? ' sort-active' : '' ?>">Email <?= View::sortIcon('email', $sort, $dir) ?></a></th>
            <th><a href="<?= View::e($sortUrl('country')) ?>" class="sort-th<?= $sort === 'country' ? ' sort-active' : '' ?>">Country <?= View::sortIcon('country', $sort, $dir) ?></a></th>
            <?php foreach ($fields as $field): ?>
              <th><?= View::e($field->label) ?></th>
            <?php endforeach; ?>
            <th class="actions-col">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($pendingNew as $m): ?>
          <tr>
            <td class="checkbox-col" data-label=""><input type="checkbox" name="emails[]" value="<?= View::e($m->email) ?>" data-name="<?= View::e($m->name) ?>"></td>
            <td data-label="Name">
              <div class="subject-title-stack">
                <b><?= View::e($m->name) ?></b>
                <?php if ($m->url): ?>
                  <a href="<?= View::e($m->url) ?>" target="_blank" rel="noopener noreferrer" class="link mem-url"><?= View::e($m->url) ?></a>
                <?php endif; ?>
              </div>
            </td>
            <td data-label="Email"><?= View::emailWrap($m->email) ?></td>
            <td class="text-muted" data-label="Country"><?= View::e($m->country) ?></td>
            <?php foreach ($fields as $field): ?>
              <td class="text-muted" data-label="<?= View::e($field->label) ?>"><?= View::e($m->additionalFields[$field->name] ?? '') ?></td>
            <?php endforeach; ?>
            <td class="actions-col" data-label="">
              <div class="actions-row">
                <button type="submit" form="approve-member-<?= md5($m->email) ?>" class="btn btn-success btn-xs">Approve</button>
                <button type="submit" form="reject-member-<?= md5($m->email) ?>" class="btn btn-warning btn-xs">Reject</button>
                <a href="<?= $b ?>/admin/members/<?= $lid ?>/edit?email=<?= urlencode($m->email) ?>" class="btn btn-tertiary btn-xs">Edit</a>
                <a href="<?= $b ?>/admin/members/<?= $lid ?>/emailmember?email=<?= urlencode($m->email) ?>" class="btn btn-secondary btn-xs">Email</a>
                <button type="submit" form="del-member-<?= md5($m->email) ?>" class="btn btn-danger btn-xs">Delete</button>
              </div>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</form>

<?php foreach ($pendingNew as $m): ?>
  <form id="approve-member-<?= md5($m->email) ?>" method="POST" action="<?= $b ?>/admin/members/<?= $lid ?>/approve" class="action-form-inline">
    <input type="hidden" name="_csrf" value="<?= View::e($csrf_token ?? '') ?>">
    <input type="hidden" name="member_email" value="<?= View::e($m->email) ?>">
    <input type="hidden" name="_status" value="pending">
  </form>
  <form id="reject-member-<?= md5($m->email) ?>" method="POST" action="<?= $b ?>/admin/members/<?= $lid ?>/reject" class="action-form-inline">
    <input type="hidden" name="_csrf" value="<?= View::e($csrf_token ?? '') ?>">
    <input type="hidden" name="member_email" value="<?= View::e($m->email) ?>">
    <input type="hidden" name="_status" value="pending">
  </form>
  <form id="del-member-<?= md5($m->email) ?>" method="POST" action="<?= $b ?>/admin/members/<?= $lid ?>/delete"
        data-del-modal data-del-entity="member" data-del-name="<?= View::e($m->name) ?>" data-del-context="<?= View::e($listing->title) ?>">
    <input type="hidden" name="_csrf" value="<?= View::e($csrf_token ?? '') ?>">
    <input type="hidden" name="member_email" value="<?= View::e($m->email) ?>">
    <input type="hidden" name="_status" value="pending">
  </form>
<?php endforeach; ?>
<?php endif; // pendingNewCnt ?>

<?php if ($pendingPUTotal > 0): ?>
<?php if ((int) $total > 0): ?><hr class="pending-section-divider"><?php endif; ?>
<!-- ── Section 2: Pending member updates ──────────────────────── -->
<form method="POST" action="<?= $b ?>/admin/pending-updates/bulk" data-del-bulk data-del-entity="pending_update_reject">
  <input type="hidden" name="_csrf"       value="<?= View::e($csrf_token ?? '') ?>">
  <input type="hidden" name="_listing_id" value="<?= $lid ?>">
  <div class="card">
    <div class="pending-section-head">
      <span class="composer-label"><?= View::icon('pendinginfo') ?> Pending Updates</span>
      <?php
        $_puFrom = $pendingPUTotal > 0 ? ($puPage - 1) * $per_page + 1 : 0;
        $_puTo   = min($pendingPUTotal, $puPage * $per_page);
      ?>
      <span class="card-head-sub">Showing <?= $_puFrom ?>&ndash;<?= $_puTo ?> of <?= number_format($pendingPUTotal) ?> update<?= $pendingPUTotal !== 1 ? 's' : '' ?></span>
      <div class="actions-row actions-row--right actions-row--wrap">
        <form method="POST" action="<?= $b ?>/admin/pending-updates/approve-all"
              class="action-form-inline"
              data-confirm="Approve all <?= $pendingPUTotal ?> pending update(s)? Changes will be applied immediately.">
          <input type="hidden" name="_csrf"       value="<?= View::e($csrf_token ?? '') ?>">
          <input type="hidden" name="_listing_id" value="<?= $lid ?>">
          <button type="submit" class="btn btn-success btn-sm">Approve All Updates</button>
        </form>
      </div>
    </div>
    <div class="bulk">
      Bulk Actions:
      <div class="select-wrap">
        <select name="bulk_action" class="select-xs">
          <option value="" disabled selected>&mdash; Select &mdash;</option>
          <option value="approve">Approve</option>
          <option value="reject">Reject</option>
        </select>
      </div>
      <button type="submit" class="btn btn-quaternary btn-sm">Apply to Selected</button>
    </div>
    <div class="table-wrap">
      <table class="data-table mem-table">
        <thead>
          <tr>
            <th class="checkbox-col"><input type="checkbox" class="sel-all" data-fid="pu" title="Select all"></th>
            <th>Member</th>
            <th>Changes</th>
            <th class="actions-col">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($pendingPUs as $pu):
            $puMember = $puMemberMap[$pu->member_email] ?? null;
          ?>
          <tr>
            <td class="checkbox-col" data-label=""><input type="checkbox" name="ids[]" class="row-cb-pu" value="<?= (int) $pu->id ?>" data-name="<?= View::e($pu->member_email) ?>"></td>
            <td data-label="Member">
              <span class="text-strong"><?= View::e($puMember ? $puMember->name : $pu->member_email) ?></span>
              <?= View::emailWrap($pu->member_email) ?>
              <span class="text-dim" style="font-size:var(--fs-xs)"><?= View::e(date($admin_date_format, strtotime($pu->submitted_at))) ?></span>
            </td>
            <td data-label="Changes">
              <?php if (empty($pu->changes)): ?>
                <span class="text-dim">&mdash;</span>
              <?php else: ?>
                <div class="diff-changes">
                  <?php foreach ($pu->changes as $field => $newVal):
                    $newVal = (string) $newVal;
                    $oldVal = '';
                    if ($puMember !== null) {
                        $oldVal = match ($field) {
                            'name'    => $puMember->name,
                            'url'     => $puMember->url,
                            'country' => $puMember->country,
                            default   => $puMember->additionalFields[$field] ?? '',
                        };
                    }
                    $isClearing = $newVal === '' && $oldVal !== '';
                  ?>
                  <div class="diff-row">
                    <span class="diff-field"><?= View::e(ucfirst((string) $field)) ?></span>
                    <?php if ($isClearing): ?>
                      <span class="diff-del"><?= View::e($oldVal) ?></span>
                    <?php elseif ($oldVal !== '' && $oldVal !== $newVal): ?>
                      <span class="diff-old"><?= View::e($oldVal) ?></span>
                      <span class="diff-arrow">&rarr;</span>
                      <span class="diff-new"><?= View::e($newVal) ?></span>
                    <?php elseif ($oldVal === ''): ?>
                      <span class="diff-add"><?= View::e($newVal) ?></span>
                    <?php else: ?>
                      <span class="diff-new"><?= View::e($newVal) ?></span>
                    <?php endif; ?>
                  </div>
                  <?php endforeach; ?>
                </div>
              <?php endif; ?>
            </td>
            <td class="actions-col" data-label="">
              <div class="actions-row">
                <form method="POST" action="<?= $b ?>/admin/pending-updates/<?= (int) $pu->id ?>/approve" class="action-form-inline">
                  <input type="hidden" name="_csrf"       value="<?= View::e($csrf_token ?? '') ?>">
                  <input type="hidden" name="_listing_id" value="<?= $lid ?>">
                  <button type="submit" class="btn btn-success btn-xs">Approve</button>
                </form>
                <a href="<?= $b ?>/admin/members/<?= $lid ?>/edit?email=<?= urlencode($pu->member_email) ?>" class="btn btn-tertiary btn-xs">Edit</a>
                <form method="POST" action="<?= $b ?>/admin/pending-updates/<?= (int) $pu->id ?>/reject"
                      data-del-modal data-del-entity="pending_update_reject" data-del-name="<?= View::e($pu->member_email) ?>">
                  <input type="hidden" name="_csrf"       value="<?= View::e($csrf_token ?? '') ?>">
                  <input type="hidden" name="_listing_id" value="<?= $lid ?>">
                  <button type="submit" class="btn btn-danger btn-xs">Reject</button>
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
<?php
if ($pendingPUPages > 1):
    $pg_current = $puPage;
    $pg_total   = $pendingPUPages;
    $pg_url     = static fn(int $p) => $buildUrl(['pu_page' => $p > 1 ? $p : '']);
    include LUSTER_BASE . '/templates/admin/_pagination.php';
endif;
?>
<?php endif; // pendingPUTotal ?>

<?php endif; // total === 0 && pendingPUTotal === 0 ?>

<?php else: ?>
<!-- ── All / Approved tab: paginated member table ─────────────── -->
<?php if (empty($members)): ?>
  <div class="card">
    <div class="empty">
      <div class="empty-icon"><?= View::icon('members', ['width' => 32, 'height' => 32]) ?></div>
      <?php if ($search !== ''): ?>
        <div><strong>No members match that search.</strong></div>
        <a href="<?= View::e($tabUrl($status)) ?>" class="btn btn-ghost btn-sm">Clear Search</a>
      <?php elseif ($status === 'approved'): ?>
        <div><strong>No approved members yet.</strong></div>
      <?php else: ?>
        <div><strong>No members yet.</strong></div>
        <div>They'll appear here after the first fan joins.</div>
        <a href="<?= $b ?>/admin/import-members" class="btn btn-primary btn-sm"><?= View::icon('import') ?> Import Members</a>
      <?php endif; ?>
    </div>
  </div>
<?php else: ?>
<?php $from = ($page - 1) * $per_page + 1; $to = ($page - 1) * $per_page + count($members); ?>
<form method="POST" id="bulk-form" action="<?= $b ?>/admin/members/<?= $lid ?>/bulk"
      data-del-bulk data-del-entity="member" data-del-context="<?= View::e($listing->title) ?>">
  <input type="hidden" name="_csrf"            value="<?= View::e($csrf_token ?? '') ?>">
  <input type="hidden" name="select_all"       value="0">
  <input type="hidden" name="sa_filter_status" value="<?= View::e($status) ?>">
  <input type="hidden" name="sa_filter_search" value="<?= View::e($search) ?>">

  <div class="card">
    <div class="card-head">
      <?= $status === 'approved' ? 'Approved Members' : ($status === 'rejected' ? 'Rejected Members' : 'All Members') ?>
      <span class="card-head-sub">
        Showing <?= $from ?>&#8211;<?= $to ?> of <?= number_format((int) $total) ?> member<?= (int) $total !== 1 ? 's' : '' ?><?php if (($pages ?? 1) > 1): ?> &mdash; page <?= (int) $page ?> of <?= (int) $pages ?><?php endif; ?>
      </span>
    </div>
    <div class="bulk">
      Bulk Actions:
      <div class="select-wrap">
        <select id="bulk-action-sel" name="bulk_action">
          <option value="" disabled selected>&mdash; Select &mdash;</option>
          <option value="approve">Approve</option>
          <option value="pending">Move to Pending</option>
          <option value="delete">Delete</option>
          <option value="email_selected">Email</option>
        </select>
      </div>
      <button type="submit" class="btn btn-quaternary btn-sm">Apply to Selected</button>
    </div>
    <div class="select-all-banner" id="check-all-sa-banner" data-total="<?= (int) $total ?>" style="display:none">
      <span class="sa-page">All <strong class="sa-page-count">0</strong> on this page are selected.&ensp;<button type="button" class="link sa-select-all-btn">Select all <strong class="sa-total-count">0</strong> members</button></span>
      <span class="sa-all" style="display:none">All <strong class="sa-total-count">0</strong> members are selected.&ensp;<button type="button" class="link sa-clear-btn">Clear selection</button></span>
    </div>
    <div class="table-wrap">
      <table class="data-table mem-table">
        <thead>
          <tr>
            <th class="checkbox-col"><input type="checkbox" id="check-all"></th>
            <th><a href="<?= View::e($sortUrl('name')) ?>" class="sort-th<?= $sort === 'name' ? ' sort-active' : '' ?>">Name <?= View::sortIcon('name', $sort, $dir) ?></a></th>
            <th><a href="<?= View::e($sortUrl('email')) ?>" class="sort-th<?= $sort === 'email' ? ' sort-active' : '' ?>">Email <?= View::sortIcon('email', $sort, $dir) ?></a></th>
            <th><a href="<?= View::e($sortUrl('country')) ?>" class="sort-th<?= $sort === 'country' ? ' sort-active' : '' ?>">Country <?= View::sortIcon('country', $sort, $dir) ?></a></th>
            <?php foreach ($fields as $field): ?>
              <th><?= View::e($field->label) ?></th>
            <?php endforeach; ?>
            <?php if ($status === 'all'): ?>
              <th><a href="<?= View::e($sortUrl('status')) ?>" class="sort-th<?= $sort === 'status' ? ' sort-active' : '' ?>">Status <?= View::sortIcon('status', $sort, $dir) ?></a></th>
            <?php endif; ?>
            <th class="actions-col">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($members as $m): ?>
          <tr class="<?= $m->rejected ? 'row-inactive' : '' ?>">
            <td class="checkbox-col" data-label=""><input type="checkbox" name="emails[]" value="<?= View::e($m->email) ?>" data-name="<?= View::e($m->name) ?>"></td>
            <td data-label="Name">
              <div class="subject-title-stack">
                <b><?= View::e($m->name) ?></b>
                <?php if ($m->url): ?>
                  <a href="<?= View::e($m->url) ?>" target="_blank" rel="noopener noreferrer" class="link mem-url"><?= View::e($m->url) ?></a>
                <?php endif; ?>
              </div>
            </td>
            <td data-label="Email"><?= View::emailWrap($m->email) ?></td>
            <td class="text-muted" data-label="Country"><?= View::e($m->country) ?></td>
            <?php foreach ($fields as $field): ?>
              <td class="text-muted" data-label="<?= View::e($field->label) ?>"><?= View::e($m->additionalFields[$field->name] ?? '') ?></td>
            <?php endforeach; ?>
            <?php if ($status === 'all'): ?>
              <td data-label="Status">
                <?php if ($m->approved): ?>
                  <span class="badge badge-current">Approved</span>
                <?php elseif ($m->rejected): ?>
                  <span class="badge badge-error">Rejected</span>
                <?php else: ?>
                  <span class="badge badge-pending">Pending</span>
                <?php endif; ?>
              </td>
            <?php endif; ?>
            <td class="actions-col" data-label="">
              <div class="actions-row">
                <?php if (!$m->approved && !$m->rejected): ?>
                  <button type="submit" form="approve-member-<?= md5($m->email) ?>" class="btn btn-success btn-xs">Approve</button>
                  <button type="submit" form="reject-member-<?= md5($m->email) ?>" class="btn btn-warning btn-xs">Reject</button>
                <?php elseif ($m->rejected): ?>
                  <button type="submit" form="approve-member-<?= md5($m->email) ?>" class="btn btn-success btn-xs">Re-approve</button>
                <?php endif; ?>
                <a href="<?= $b ?>/admin/members/<?= $lid ?>/edit?email=<?= urlencode($m->email) ?>" class="btn btn-tertiary btn-xs">Edit</a>
                <a href="<?= $b ?>/admin/members/<?= $lid ?>/emailmember?email=<?= urlencode($m->email) ?>" class="btn btn-quaternary btn-xs">Email</a>
                <button type="submit" form="del-member-<?= md5($m->email) ?>" class="btn btn-danger btn-xs">Delete</button>
              </div>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</form>

<?php foreach ($members as $m): ?>
  <?php if (!$m->approved): ?>
  <form id="approve-member-<?= md5($m->email) ?>" method="POST" action="<?= $b ?>/admin/members/<?= $lid ?>/approve" class="action-form-inline">
    <input type="hidden" name="_csrf" value="<?= View::e($csrf_token ?? '') ?>">
    <input type="hidden" name="member_email" value="<?= View::e($m->email) ?>">
    <input type="hidden" name="_status" value="<?= View::e($status) ?>">
    <input type="hidden" name="_page"   value="<?= (int) $page ?>">
  </form>
  <?php endif; ?>
  <?php if (!$m->approved && !$m->rejected): ?>
  <form id="reject-member-<?= md5($m->email) ?>" method="POST" action="<?= $b ?>/admin/members/<?= $lid ?>/reject" class="action-form-inline">
    <input type="hidden" name="_csrf" value="<?= View::e($csrf_token ?? '') ?>">
    <input type="hidden" name="member_email" value="<?= View::e($m->email) ?>">
    <input type="hidden" name="_status" value="<?= View::e($status) ?>">
    <input type="hidden" name="_page"   value="<?= (int) $page ?>">
  </form>
  <?php endif; ?>
  <form id="del-member-<?= md5($m->email) ?>" method="POST" action="<?= $b ?>/admin/members/<?= $lid ?>/delete"
        data-del-modal data-del-entity="member" data-del-name="<?= View::e($m->name) ?>" data-del-context="<?= View::e($listing->title) ?>">
    <input type="hidden" name="_csrf" value="<?= View::e($csrf_token ?? '') ?>">
    <input type="hidden" name="member_email" value="<?= View::e($m->email) ?>">
    <input type="hidden" name="_status" value="<?= View::e($status) ?>">
    <input type="hidden" name="_page"   value="<?= (int) $page ?>">
  </form>
<?php endforeach; ?>

<?php
$memPageUrl = static function (int $p) use ($buildUrl): string {
    return $buildUrl(['page' => $p > 1 ? $p : '']);
};
$pg_current = $page; $pg_total = $pages; $pg_url = $memPageUrl;
include LUSTER_BASE . '/templates/admin/_pagination.php';
?>

<?php endif; // empty($members) ?>
<?php endif; // status === 'pending' (per-listing) ?>

<?php elseif (!empty($cross_listing_search ?? [])): ?>
<!-- ── CROSS-LISTING SEARCH RESULTS ───────────────────────────────────── -->
<?php $xTotal = count($cross_listing_search); ?>
<p class="result-count member-results-count">
  <?= $xTotal ?> result<?= $xTotal !== 1 ? 's' : '' ?> for &ldquo;<?= View::e($search) ?>&rdquo; across all listings
</p>
<div class="card">
  <div class="table-wrap">
    <table class="data-table mem-table">
      <thead>
        <tr>
          <th>Listing</th>
          <th>Name</th>
          <th>Email</th>
          <th>Country</th>
          <th>Status</th>
          <th>Joined</th>
          <th class="actions-col">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($cross_listing_search as $row):
          $fl   = $row['listing'];
          $m    = $row['member'];
          $flId = (int) $fl->id;
        ?>
        <tr class="<?= $m->rejected ? 'row-inactive' : '' ?>">
          <td data-label="Listing">
            <div class="subject-title-stack">
              <a href="<?= $b ?>/admin/members?listing_id=<?= $flId ?>" class="link"><b><?= View::e($fl->subject ?: $fl->title) ?></b></a>
              <?php if ($fl->subject !== ''): ?>
                <span class="mem-url"><?= View::e($fl->title) ?></span>
              <?php endif; ?>
            </div>
          </td>
          <td data-label="Name">
            <div class="subject-title-stack">
              <b><?= View::e($m->name) ?></b>
              <?php if ($m->url !== ''): ?>
                <a href="<?= View::e($m->url) ?>" target="_blank" rel="noopener noreferrer" class="link mem-url"><?= View::e($m->url) ?></a>
              <?php endif; ?>
            </div>
          </td>
          <td data-label="Email"><?= View::emailWrap($m->email) ?></td>
          <td data-label="Country"><?= View::e($m->country) ?></td>
          <td data-label="Status">
            <?php if ($m->approved): ?>
              <span class="badge badge-current">Approved</span>
            <?php elseif ($m->rejected): ?>
              <span class="badge badge-error">Rejected</span>
            <?php else: ?>
              <span class="badge badge-pending">Pending</span>
            <?php endif; ?>
          </td>
          <td data-label="Joined" class="cell-meta-nowrap">
            <?= View::e(($m->added ?? '') !== '' ? date($admin_date_format, strtotime($m->added)) : '') ?>
          </td>
          <td class="actions-col" data-label="">
            <div class="actions-row">
              <?php if (!$m->approved && !$m->rejected): ?>
                <form method="POST" action="<?= $b ?>/admin/members/<?= $flId ?>/approve" class="action-form-inline">
                  <input type="hidden" name="_csrf" value="<?= View::e($csrf_token ?? '') ?>">
                  <input type="hidden" name="member_email" value="<?= View::e($m->email) ?>">
                  <button type="submit" class="btn btn-success btn-xs">Approve</button>
                </form>
                <form method="POST" action="<?= $b ?>/admin/members/<?= $flId ?>/reject" class="action-form-inline">
                  <input type="hidden" name="_csrf" value="<?= View::e($csrf_token ?? '') ?>">
                  <input type="hidden" name="member_email" value="<?= View::e($m->email) ?>">
                  <button type="submit" class="btn btn-warning btn-xs">Reject</button>
                </form>
              <?php elseif ($m->rejected): ?>
                <form method="POST" action="<?= $b ?>/admin/members/<?= $flId ?>/approve" class="action-form-inline">
                  <input type="hidden" name="_csrf" value="<?= View::e($csrf_token ?? '') ?>">
                  <input type="hidden" name="member_email" value="<?= View::e($m->email) ?>">
                  <button type="submit" class="btn btn-success btn-xs">Re-approve</button>
                </form>
              <?php endif; ?>
              <a href="<?= $b ?>/admin/members/<?= $flId ?>/edit?email=<?= urlencode($m->email) ?>" class="btn btn-tertiary btn-xs">Edit</a>
              <a href="<?= $b ?>/admin/members/<?= $flId ?>/emailmember?email=<?= urlencode($m->email) ?>" class="btn btn-secondary btn-xs">Email</a>
              <form method="POST" action="<?= $b ?>/admin/members/<?= $flId ?>/delete"
                    data-del-modal data-del-entity="member" data-del-name="<?= View::e($m->name) ?>">
                <input type="hidden" name="_csrf" value="<?= View::e($csrf_token ?? '') ?>">
                <input type="hidden" name="member_email" value="<?= View::e($m->email) ?>">
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

<?php elseif ($status === 'pending'): ?>
<!-- ── CROSS-LISTING PENDING ──────────────────────────────────────────── -->
<?php
$_allPendingNew = $members ?? [];   // flat paginated slice from controller
$_totalNew      = (int) ($total ?? 0);
$_totalUpdates  = (int) ($pending_updates_total ?? count($pending_updates ?? []));
$_puPages       = (int) ($pending_updates_pages ?? 1);
$_totalPending  = $_totalNew + $_totalUpdates;
$_newFrom       = $_totalNew > 0 ? ($page - 1) * $per_page + 1 : 0;
$_newTo         = min($_totalNew, $page * $per_page);
?>

<?php if ($_totalPending === 0): ?>
<div class="card">
  <div class="empty">
    <div class="empty-icon"><?= View::icon('pending', ['width' => 32, 'height' => 32]) ?></div>
    <div><strong>Nothing pending.</strong></div>
    <div>You're all caught up.</div>
  </div>
</div>
<?php else: ?>

<?php if ($_totalNew > 0): ?>
<!-- ── Pending new members: single unified table ─────────────────── -->
<form method="POST" action="<?= $b ?>/admin/members/pending-bulk" data-del-bulk data-del-entity="member_reject">
  <input type="hidden" name="_csrf" value="<?= View::e($csrf_token ?? '') ?>">
  <div class="card"><div class="card-head"><?= View::icon('pending') ?> Pending New Members</span>
      <span class="card-head-sub">Showing <?= $_newFrom ?>&ndash;<?= $_newTo ?> of <?= number_format($_totalNew) ?> member<?= $_totalNew !== 1 ? 's' : '' ?></span>
      <div class="actions-row actions-row--right actions-row--wrap">
        <form method="POST" action="<?= $b ?>/admin/members/approve-all-overview"
              class="action-form-inline"
              data-confirm="Approve all pending items across all listings? This will send approval emails.">
          <input type="hidden" name="_csrf" value="<?= View::e($csrf_token ?? '') ?>">
          <button type="submit" class="btn btn-success btn-sm">Approve All Pending</button>
        </form>
      </div>
    </div>
    <div class="bulk">
      Bulk Actions:
      <div class="select-wrap">
        <select name="action" class="select-xs" id="pending-bulk-action">
          <option value="" disabled selected>&mdash; Select &mdash;</option>
          <option value="approve">Approve</option>
          <option value="reject">Reject</option>
        </select>
      </div>
      <button type="submit" class="btn btn-quaternary btn-sm">Apply to Selected</button>
    </div>
    <div class="table-wrap">
      <table class="data-table mem-table">
        <thead>
          <tr>
            <th class="checkbox-col"><input type="checkbox" id="pending-check-all"></th>
            <th><a href="<?= View::e($sortUrl('name')) ?>"    class="sort-th<?= $sort === 'name'    ? ' sort-active' : '' ?>">Name <?= View::sortIcon('name', $sort, $dir) ?></a></th>
            <th><a href="<?= View::e($sortUrl('listing')) ?>" class="sort-th<?= $sort === 'listing' ? ' sort-active' : '' ?>">Listing <?= View::sortIcon('listing', $sort, $dir) ?></a></th>
            <th>Email</th>
            <th><a href="<?= View::e($sortUrl('country')) ?>" class="sort-th<?= $sort === 'country' ? ' sort-active' : '' ?>">Country <?= View::sortIcon('country', $sort, $dir) ?></a></th>
            <th class="actions-col">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($_allPendingNew as $row):
            $fl   = $row['listing'];
            $m    = $row['member'];
            $flId = (int) $fl->id;
            // cross-listing pending: member is a Member object inside the 'member' key
          ?>
          <tr>
            <td class="checkbox-col" data-label="">
              <input type="checkbox" name="items[]"
                     value="<?= $flId ?>:<?= View::e($m->email) ?>"
                     data-name="<?= View::e($m->name) ?>">
            </td>
            <td data-label="Name">
              <div class="subject-title-stack">
                <b><?= View::e($m->name) ?></b>
                <?php if ($m->url !== ''): ?>
                  <a href="<?= View::e($m->url) ?>" target="_blank" rel="noopener noreferrer"
                     class="link mem-url"><?= View::e($m->url) ?></a>
                <?php endif; ?>
              </div>
            </td>
            <td data-label="Listing">
              <div class="subject-title-stack">
                <a href="<?= $b ?>/admin/members?listing_id=<?= $flId ?>&status=pending" class="link"><b><?= View::e($fl->subject ?: $fl->title) ?></b></a>
                <?php if ($fl->subject !== ''): ?>
                  <span class="mem-url"><?= View::e($fl->title) ?></span>
                <?php endif; ?>
              </div>
            </td>
            <td data-label="Email"><?= View::emailWrap($m->email) ?></td>
            <td data-label="Country"><?= View::e($m->country) ?></td>
            <td class="actions-col" data-label="">
              <div class="actions-row">
                <form method="POST" action="<?= $b ?>/admin/members/<?= $flId ?>/approve" class="action-form-inline">
                  <input type="hidden" name="_csrf"        value="<?= View::e($csrf_token ?? '') ?>">
                  <input type="hidden" name="member_email" value="<?= View::e($m->email) ?>">
                  <input type="hidden" name="_from_global" value="1">
                  <button type="submit" class="btn btn-success btn-xs">Approve</button>
                </form>
                <form method="POST" action="<?= $b ?>/admin/members/<?= $flId ?>/reject" class="action-form-inline">
                  <input type="hidden" name="_csrf"        value="<?= View::e($csrf_token ?? '') ?>">
                  <input type="hidden" name="member_email" value="<?= View::e($m->email) ?>">
                  <input type="hidden" name="_from_global" value="1">
                  <button type="submit" class="btn btn-warning btn-xs">Reject</button>
                </form>
                <a href="<?= $b ?>/admin/members/<?= $flId ?>/edit?email=<?= urlencode($m->email) ?>"
                   class="btn btn-tertiary btn-xs">Edit</a>
                <a href="<?= $b ?>/admin/members/<?= $flId ?>/emailmember?email=<?= urlencode($m->email) ?>"
                   class="btn btn-secondary btn-xs">Email</a>
                <form method="POST" action="<?= $b ?>/admin/members/<?= $flId ?>/delete"
                      data-del-modal data-del-entity="member" data-del-name="<?= View::e($m->name) ?>">
                  <input type="hidden" name="_csrf"        value="<?= View::e($csrf_token ?? '') ?>">
                  <input type="hidden" name="member_email" value="<?= View::e($m->email) ?>">
                  <input type="hidden" name="_from_global" value="1">
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
<?php
if (($pages ?? 1) > 1):
    $pg_current = $page;
    $pg_total   = $pages;
    $pg_url     = static fn(int $p) => $buildUrl(['page' => $p > 1 ? $p : '']);
    include LUSTER_BASE . '/templates/admin/_pagination.php';
endif;
?>
<?php endif; // _totalNew ?>

<?php if ($_totalUpdates > 0): ?>
<?php if ($_totalNew > 0): ?><div class="overview-section-divider" aria-hidden="true"></div><?php endif; ?>
<!-- ── Pending member updates ────────────────────────────────────── -->
<form method="POST" action="<?= $b ?>/admin/pending-updates/bulk" data-del-bulk data-del-entity="pending_update_reject">
  <input type="hidden" name="_csrf" value="<?= View::e($csrf_token ?? '') ?>">
  <div class="card">
    <div class="card-head"><?= View::icon('pendinginfo') ?> Pending Updates
      <?php
        $_puFrom = $_totalUpdates > 0 ? ($puPage - 1) * $per_page + 1 : 0;
        $_puTo   = min($_totalUpdates, $puPage * $per_page);
      ?>
      <span class="card-head-sub">Showing <?= $_puFrom ?>&ndash;<?= $_puTo ?> of <?= number_format($_totalUpdates) ?> update<?= $_totalUpdates !== 1 ? 's' : '' ?></span>
      <div class="actions-row actions-row--right actions-row--wrap">
        <form method="POST" action="<?= $b ?>/admin/pending-updates/approve-all"
              class="action-form-inline"
              data-confirm="Approve all <?= $_totalUpdates ?> pending update(s) across all listings? Changes will be applied immediately.">
          <input type="hidden" name="_csrf"       value="<?= View::e($csrf_token ?? '') ?>">
          <input type="hidden" name="_listing_id" value="0">
          <button type="submit" class="btn btn-success btn-sm">Approve All Updates</button>
        </form>
      </div>
    </div>
    <div class="bulk">
      Bulk Actions:
      <div class="select-wrap">
        <select name="bulk_action" class="select-xs">
          <option value="" disabled selected>&mdash; Select &mdash;</option>
          <option value="approve">Approve</option>
          <option value="reject">Reject</option>
        </select>
      </div>
      <button type="submit" class="btn btn-quaternary btn-sm">Apply to Selected</button>
    </div>
    <div class="table-wrap">
      <table class="data-table mem-table">
        <thead>
          <tr>
            <th class="checkbox-col"><input type="checkbox" class="sel-all" data-fid="pu"></th>
            <th><a href="<?= View::e($sortUrl('name')) ?>"    class="sort-th<?= $sort === 'name'    ? ' sort-active' : '' ?>">Name <?= View::sortIcon('name', $sort, $dir) ?></a></th>
            <th><a href="<?= View::e($sortUrl('listing')) ?>" class="sort-th<?= $sort === 'listing' ? ' sort-active' : '' ?>">Listing <?= View::sortIcon('listing', $sort, $dir) ?></a></th>
            <th>Email</th>
            <th>Fields Changed</th>
            <th>Submitted</th>
            <th class="actions-col">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php
            // Page-level sort by name when the DB can't do it (name/country live in per-listing tables)
            $_puSlice = $pending_updates ?? [];
            if (in_array($sort, ['name', 'country'], true) && !empty($_puSlice)) {
                $_puMmap = $pending_member_data ?? [];
                usort($_puSlice, static function ($a, $b) use ($sort, $dir, $_puMmap): int {
                    $ma = $_puMmap[$a->member_email] ?? null;
                    $mb = $_puMmap[$b->member_email] ?? null;
                    $av = $sort === 'country' ? ($ma?->country ?? '') : ($ma?->name ?? $a->member_email);
                    $bv = $sort === 'country' ? ($mb?->country ?? '') : ($mb?->name ?? $b->member_email);
                    $cmp = strcasecmp($av, $bv);
                    return $dir === 'desc' ? -$cmp : $cmp;
                });
            }
          ?>
          <?php foreach ($_puSlice as $pu):
            $puListing = ($fl_by_id ?? [])[(int) $pu->fanlisting_id] ?? null;
            $puFlId    = (int) $pu->fanlisting_id;
            $puCount   = is_array($pu->changes) ? count($pu->changes) : 0;
            $puMember  = ($pending_member_data ?? [])[$pu->member_email] ?? null;
          ?>
          <tr>
            <td class="checkbox-col" data-label=""><input type="checkbox" name="ids[]" class="row-cb-pu" value="<?= (int) $pu->id ?>" data-name="<?= View::e($pu->member_email) ?>"></td>
            <td data-label="Name">
              <div class="subject-title-stack">
                <b><?= View::e($puMember?->name ?? $pu->member_email) ?></b>
                <?php if (($puMember?->url ?? '') !== ''): ?>
                  <a href="<?= View::e($puMember->url) ?>" target="_blank" rel="noopener noreferrer"
                     class="link mem-url"><?= View::e($puMember->url) ?></a>
                <?php endif; ?>
              </div>
            </td>
            <td data-label="Listing">
              <?php if ($puListing !== null): ?>
                <div class="subject-title-stack">
                  <a href="<?= $b ?>/admin/members?listing_id=<?= $puFlId ?>&status=pending" class="link"><b><?= View::e($puListing->subject ?: $puListing->title) ?></b></a>
                  <?php if ($puListing->subject !== ''): ?>
                    <span class="mem-url"><?= View::e($puListing->title) ?></span>
                  <?php endif; ?>
                </div>
              <?php else: ?>
                <span class="text-dim">#<?= $puFlId ?></span>
              <?php endif; ?>
            </td>
            <td data-label="Email"><?= View::e($pu->member_email) ?></td>
            <td data-label="Changes">
              <?php if ($puCount === 0): ?>
                <span class="text-dim">-</span>
              <?php else: ?>
                <details class="changes-toggle">
                  <summary class="changes-toggle-summary"><?= $puCount ?> field<?= $puCount !== 1 ? 's' : '' ?> <?= View::icon('chevrondown') ?></summary>
                  <div class="changes-list">
                    <?php foreach ($pu->changes as $field => $val): ?>
                      <div class="changes-list-item"><span class="changes-list-label"><?= View::e(ucfirst((string) $field)) ?>:</span> <?= $field === 'password' ? '<em>Password change requested</em>' : View::e((string) $val) ?></div>
                    <?php endforeach; ?>
                  </div>
                </details>
              <?php endif; ?>
            </td>
            <td data-label="Submitted" class="cell-meta-nowrap"><?= View::e($pu->submitted_at) ?></td>
            <td class="actions-col" data-label="">
              <div class="actions-row">
                <form method="POST" action="<?= $b ?>/admin/pending-updates/<?= (int) $pu->id ?>/approve" class="action-form-inline">
                  <input type="hidden" name="_csrf" value="<?= View::e($csrf_token ?? '') ?>">
                  <button type="submit" class="btn btn-success btn-xs">Approve</button>
                </form>
                <a href="<?= $b ?>/admin/members/<?= $puFlId ?>/edit?email=<?= urlencode($pu->member_email) ?>" class="btn btn-tertiary btn-xs">Edit</a>
                <form method="POST" action="<?= $b ?>/admin/pending-updates/<?= (int) $pu->id ?>/reject"
                      data-del-modal data-del-entity="pending_update_reject" data-del-name="<?= View::e($pu->member_email) ?>">
                  <input type="hidden" name="_csrf" value="<?= View::e($csrf_token ?? '') ?>">
                  <button type="submit" class="btn btn-danger btn-xs">Reject</button>
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
<?php
if ($_puPages > 1):
    $pg_current = $puPage;
    $pg_total   = $_puPages;
    $pg_url     = static fn(int $p) => $buildUrl(['pu_page' => $p > 1 ? $p : '']);
    include LUSTER_BASE . '/templates/admin/_pagination.php';
endif;
?>
<?php endif; // _totalUpdates ?>

<?php endif; // _totalPending === 0 ?>

<?php else: ?>
<!-- ── CROSS-LISTING ALL / APPROVED: listing summary ─────────────────── -->
<?php
$validListings  = array_values(array_filter($all_listings ?? [], static fn($fl) => $fl->dbprefix !== ''));
$_lcMap         = $listing_counts ?? [];

// Sort by active URL param; default = listing name asc
$_ovSort = in_array($sort, ['id', 'listing', 'total', 'pending', 'rejected'], true) ? $sort : 'id';
$_ovDir  = $dir === 'desc' ? 'desc' : 'asc';
usort($validListings, static function ($a, $b) use ($_ovSort, $_ovDir, $_lcMap): int {
    $aId = (int) $a->id; $bId = (int) $b->id;
    $aLc = $_lcMap[$aId] ?? ['approved' => 0, 'pending' => 0, 'rejected' => 0];
    $bLc = $_lcMap[$bId] ?? ['approved' => 0, 'pending' => 0, 'rejected' => 0];
    $cmp = match ($_ovSort) {
        'id'       => $aId <=> $bId,
        'total'    => (($status === 'rejected' ? $aLc['rejected'] : $aLc['approved']) <=> ($status === 'rejected' ? $bLc['rejected'] : $bLc['approved'])) ?: strcasecmp($a->subject ?: $a->title, $b->subject ?: $b->title),
        'pending'  => ($aLc['pending']  <=> $bLc['pending'])  ?: strcasecmp($a->subject ?: $a->title, $b->subject ?: $b->title),
        'rejected' => ($aLc['rejected'] <=> $bLc['rejected'])  ?: strcasecmp($a->subject ?: $a->title, $b->subject ?: $b->title),
        default    => strcasecmp($a->subject ?: $a->title, $b->subject ?: $b->title),
    };
    return $_ovDir === 'desc' ? -$cmp : $cmp;
});

$_ovSortUrl = static function (string $col) use ($buildUrl, $_ovSort, $_ovDir): string {
    $newDir = ($_ovSort === $col && $_ovDir === 'asc') ? 'desc' : 'asc';
    return $buildUrl(['sort' => $col !== 'listing' ? $col : '', 'dir' => $newDir !== 'asc' ? $newDir : '', 'page' => '']);
};
?>
<?php if (empty($validListings)): ?>
  <div class="card">
    <div class="empty">
      <div class="empty-icon"><?= View::icon('members', ['width' => 32, 'height' => 32]) ?></div>
      <div><strong>No listings yet.</strong></div>
    </div>
  </div>
<?php else: ?>
  <div class="card">
    <?php $vlCount = count($validListings); ?>
    <div class="card-head">
      <?= $status === 'approved' ? 'Approved Members by Listing' : 'All Members by Listing' ?>
      <span class="card-head-sub">Showing 1&ndash;<?= $vlCount ?> of <?= $vlCount ?> listing<?= $vlCount !== 1 ? 's' : '' ?></span>
    </div>
    <div class="table-wrap">
      <table class="data-table mem-table">
        <thead>
          <tr>
            <th class="table-num"><a href="<?= View::e($_ovSortUrl('id')) ?>" class="sort-th<?= $_ovSort === 'id' ? ' sort-active' : '' ?>">&num; <?= View::sortIcon('id', $_ovSort, $_ovDir) ?></a></th>
            <th><a href="<?= View::e($_ovSortUrl('listing')) ?>" class="sort-th<?= $_ovSort === 'listing' ? ' sort-active' : '' ?>">Listing <?= View::sortIcon('listing', $_ovSort, $_ovDir) ?></a></th>
            <th style="text-align:center;white-space:nowrap;width:9rem"><a href="<?= View::e($_ovSortUrl('total')) ?>" class="sort-th<?= $_ovSort === 'total' ? ' sort-active' : '' ?>"><?= $status === 'rejected' ? 'Rejected' : ($status === 'approved' ? 'Approved' : 'Total Members') ?> <?= View::sortIcon('total', $_ovSort, $_ovDir) ?></a></th>
            <?php if ($status === 'all'): ?>
              <th style="text-align:center;white-space:nowrap;width:7rem"><a href="<?= View::e($_ovSortUrl('pending')) ?>" class="sort-th<?= $_ovSort === 'pending' ? ' sort-active' : '' ?>">Pending <?= View::sortIcon('pending', $_ovSort, $_ovDir) ?></a></th>
              <th style="text-align:center;white-space:nowrap;width:7rem"><a href="<?= View::e($_ovSortUrl('rejected')) ?>" class="sort-th<?= $_ovSort === 'rejected' ? ' sort-active' : '' ?>">Rejected <?= View::sortIcon('rejected', $_ovSort, $_ovDir) ?></a></th>
            <?php endif; ?>
            <th class="actions-col"></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($validListings as $_vlIdx => $fl):
            $_flId  = (int) $fl->id;
            $_lc    = $_lcMap[$_flId] ?? ['approved' => 0, 'pending' => 0, 'rejected' => 0];
          ?>
          <tr>
            <td class="table-num text-dim" data-label="#"><?= $_flId ?></td>
            <td data-label="Listing">
              <div class="subject-title-stack">
                <a href="<?= $b ?>/admin/listings/<?= $_flId ?>/edit" class="link"><b><?= View::e($fl->subject ?: $fl->title) ?></b></a>
                <?php if ($fl->subject !== ''): ?>
                  <span class="mem-url"><?= View::e($fl->title) ?></span>
                <?php endif; ?>
              </div>
            </td>
            <td style="text-align:center;white-space:nowrap" data-label="Total Members">
              <?php $_ovCount = $status === 'rejected' ? $_lc['rejected'] : $_lc['approved']; ?>
              <?= $_ovCount > 0 ? '<span class="' . ($status === 'rejected' ? 'text-muted' : '') . '">' . number_format($_ovCount) . '</span>' : '<span class="text-dim">&mdash;</span>' ?>
            </td>
            <?php if ($status === 'all'): ?>
              <td style="text-align:center;white-space:nowrap" data-label="Pending">
                <?php if ($_lc['pending'] > 0): ?>
                  <a href="<?= $b ?>/admin/members?listing_id=<?= $_flId ?>&status=pending" class="badge badge-pending"><?= $_lc['pending'] ?></a>
                <?php else: ?>
                  <span class="text-dim">&mdash;</span>
                <?php endif; ?>
              </td>
              <td style="text-align:center;white-space:nowrap" data-label="Rejected">
                <?php if ($_lc['rejected'] > 0): ?>
                  <a href="<?= $b ?>/admin/members?listing_id=<?= $_flId ?>&status=rejected" class="badge badge-error"><?= $_lc['rejected'] ?></a>
                <?php else: ?>
                  <span class="text-dim">&mdash;</span>
                <?php endif; ?>
              </td>
            <?php endif; ?>
            <td class="actions-col" data-label="">
              <a href="<?= $b ?>/admin/members?listing_id=<?= $_flId ?>&status=<?= View::e($status) ?>" class="btn btn-primary btn-sm">View Members</a>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
<?php endif; ?>
<?php endif; // status branches (cross-listing) ?>
