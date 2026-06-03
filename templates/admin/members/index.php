<?php
use Luster\Core\View;

/** @var string   $view_mode      'overview' | 'listing' | 'search' */
/** @var \Luster\Models\Listing|null $listing */
/** @var int      $listing_id */
/** @var array    $all_listings */
/** @var string   $search */
/** @var string   $status */
/** @var string   $success */
/** @var string   $error */
/** @var string   $base_url */
/** @var string   $csrf_token */

// listing-view only
/** @var \Luster\Models\Member[]         $members */
/** @var \Luster\Models\AdditionalField[] $fields */
/** @var int      $total */
/** @var int      $page */
/** @var int      $pages */
/** @var int      $per_page */
/** @var int      $pending_count */
/** @var string   $sort */
/** @var string   $dir */

// overview / search
/** @var array    $grouped */
/** @var array    $search_results */
/** @var array    $pending_updates */
/** @var array    $fl_by_id */

$b         = rtrim(View::e($base_url), '/');
$lid       = (int) ($listing_id ?? 0);
$viewMode  = $view_mode ?? 'overview';
$search    = $search    ?? '';
$status    = $status    ?? ($lid > 0 ? 'all' : 'pending');
$sort      = $sort      ?? 'name';
$dir       = $dir       ?? 'asc';

$filtersActive = $lid > 0 || ($viewMode !== 'overview' && $search !== '');

// Sort URL builder (listing view only)
$memSortUrl = static function (string $col) use ($b, $lid, $sort, $dir, $search, $status): string {
    $newDir = ($sort === $col && $dir === 'asc') ? 'desc' : 'asc';
    $qs = http_build_query(array_filter([
        'listing_id' => $lid > 0 ? $lid : '',
        'search'     => $search,
        'status'     => $status !== 'all' ? $status : '',
        'sort'       => $col,
        'dir'        => $newDir,
    ], static fn($v): bool => $v !== '' && $v !== 0));
    return $b . '/admin/members' . ($qs !== '' ? '?' . $qs : '');
};

// Overview sort URL builder
$pendingSortUrl = static function (string $col) use ($b, $sort, $dir): string {
    $newDir = ($sort === $col && $dir === 'asc') ? 'desc' : 'asc';
    return $b . '/admin/members?' . http_build_query(['sort' => $col, 'dir' => $newDir]);
};
?>

<?php if ($success !== ''): ?>
  <div class="alert alert-success"><?= View::e($success) ?></div>
<?php endif; ?>
<?php if ($error !== ''): ?>
  <div class="alert alert-error"><?= View::e($error) ?></div>
<?php endif; ?>

<!-- ──── TOOLBAR ────────────────────────────────────────────────────── -->
<div class="toolbar">
  <div class="actions-row">
    <button type="button" class="btn btn-preview btn-sm filter-toggle" id="mem-filter-toggle"
            aria-expanded="true"
            aria-controls="mem-filter-panel">
      <?= View::icon('filter') ?>
      Filter<?php if ($filtersActive): ?><span class="filter-dot" aria-hidden="true"></span><?php endif; ?>
    </button>
  </div>
  <div class="actions-row">
    <?php if ($viewMode === 'listing' && $listing !== null): ?>
      <a href="<?= $b ?>/admin/members/<?= $lid ?>/import" class="btn btn-quaternary btn-sm">&#8595; Import CSV</a>
      <a href="<?= $b ?>/admin/members/<?= $lid ?>/export" class="btn btn-quaternary btn-sm">&#8593; Export CSV</a>
    <?php else: ?>
      <a href="<?= $b ?>/admin/members/export-pending" class="btn btn-quaternary btn-sm">&#8593; Export Pending</a>
    <?php endif; ?>
    <a href="<?= $b ?>/admin/members/settings" class="btn btn-quaternary btn-sm">Display Settings</a>
  </div>
</div>

<div class="filter-panel" id="mem-filter-panel">
  <form method="GET" action="<?= $b ?>/admin/members" id="mem-filter-form" class="filter-row">

    <div class="select-wrap">
      <select name="listing_id" id="mem-listing-sel">
        <option value="0"<?= $lid === 0 ? ' selected' : '' ?>>All Listings</option>
        <?php foreach ($all_listings as $fl): ?>
          <option value="<?= (int) $fl->id ?>"<?= (int) $fl->id === $lid ? ' selected' : '' ?>>
            <?= View::e($fl->name) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="select-wrap">
      <select name="status" id="mem-status-sel"<?= $lid === 0 ? ' disabled' : '' ?>>
        <option value="all"     <?= $status === 'all'      ? 'selected' : '' ?>>All Members</option>
        <option value="pending" <?= $status === 'pending'  ? 'selected' : '' ?>>Pending</option>
        <option value="approved"<?= $status === 'approved' ? 'selected' : '' ?>>Approved</option>
      </select>
    </div>

    <div class="search-field-wrap">
      <?= View::icon('search', ['class' => 'search-field-icon', 'aria-hidden' => 'true']) ?>
      <input type="text" name="search" class="form toolbar-input-sm"
             placeholder="<?= $lid > 0 ? 'Search members&hellip;' : 'Search all listings&hellip;' ?>"
             value="<?= View::e($search) ?>">
    </div>

    <button type="submit" class="btn btn-secondary btn-sm">Search</button>
    <a href="<?= $b ?>/admin/members" class="btn btn-quaternary btn-sm">Reset</a>

  </form>
</div>

<!-- ──── PENDING BANNER (listing view only) ─────────────────────────── -->
<?php if ($viewMode === 'listing' && ($pending_count ?? 0) > 0 && $status !== 'pending'): ?>
<div class="reminder-banner">
  <div class="reminder-banner-head">
    <div class="reminder-banner-left">
      <span class="reminder-banner-icon"><?= View::icon('bell') ?></span>
      <div>
        <span class="reminder-banner-title">Action Required!</span>
        <span class="reminder-banner-msg"><?= (int) $pending_count ?> member<?= $pending_count !== 1 ? 's are' : ' is' ?> awaiting approval</span>
      </div>
    </div>
    <div class="inline-actions-row">
      <a href="?listing_id=<?= $lid ?>&status=pending" class="btn btn-preview btn-sm">View Pending &rarr;</a>
      <form method="POST" action="<?= $b ?>/admin/members/<?= $lid ?>/approve-all-pending"
            data-confirm="Approve all <?= (int) $pending_count ?> pending member(s)?">
        <input type="hidden" name="_csrf" value="<?= View::e($csrf_token ?? '') ?>">
        <button type="submit" class="btn btn-success btn-sm">Approve All &rarr;</button>
      </form>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- ════════════════════════════════════════════════════════════════════ -->
<?php if ($viewMode === 'listing'): ?>
<!-- ──── LISTING VIEW: paginated member table ────────────────────────── -->

<?php if (empty($members)): ?>
  <div class="card">
    <div class="empty">
      <div class="empty-icon"><?= View::icon('members', ['width' => 32, 'height' => 32]) ?></div>
      <?php if ($search !== '' || $status !== 'all'): ?>
        <div class="empty-title">No members match those filters.</div>
        <a href="?listing_id=<?= $lid ?>" class="btn btn-ghost btn-sm">Clear Filters</a>
      <?php else: ?>
        <div class="empty-title">No members yet.</div>
        <div>They'll appear here after the first fan joins.</div>
        <a href="<?= $b ?>/admin/members/<?= $lid ?>/import" class="btn btn-primary btn-sm"><?= View::icon('import') ?> Import CSV</a>
      <?php endif; ?>
    </div>
  </div>
<?php else: ?>

<?php $from = ($page - 1) * $per_page + 1; $to = ($page - 1) * $per_page + count($members); ?>

<form method="POST" id="bulk-form" action="<?= $b ?>/admin/members/<?= $lid ?>/bulk"
      data-del-bulk data-del-entity="member"
      data-del-context="<?= View::e($listing->name) ?>">
  <input type="hidden" name="_csrf" value="<?= View::e($csrf_token ?? '') ?>">
  <input type="hidden" name="select_all" value="0">
  <input type="hidden" name="sa_filter_status" value="<?= View::e($status ?? 'all') ?>">
  <input type="hidden" name="sa_filter_search" value="<?= View::e($search ?? '') ?>">

  <div class="card">
    <div class="card-head">
      Members
      <span class="row-sub">
        Showing <?= $from ?>&#8211;<?= $to ?> of <?= number_format((int) $total) ?> member<?= (int) $total !== 1 ? 's' : '' ?><?php if (($pages ?? 1) > 1): ?> &mdash; page <?= (int) $page ?> of <?= (int) $pages ?><?php endif; ?>
      </span>
    </div>
    <div class="bulk bulk--flush">
      Bulk Actions:
      <div class="select-wrap">
      <select id="bulk-action-sel" name="bulk_action">
        <option value="" disabled selected>&mdash; Select &mdash;</option>
        <option value="approve">Approve</option>
        <option value="pending">Pending</option>
        <option value="delete">Delete</option>
        <option value="email_selected">Email</option>
      </select>
      </div>
      <button type="submit" class="btn btn-quaternary btn-sm">Apply to Selected</button>
    </div>
    <div class="select-all-banner" id="check-all-sa-banner" data-total="<?= (int) ($total ?? 0) ?>" style="display:none">
      <span class="sa-page">All <strong class="sa-page-count">0</strong> items on this page are selected.&ensp;<button type="button" class="link sa-select-all-btn">Select all <strong class="sa-total-count">0</strong> members</button></span>
      <span class="sa-all" style="display:none">All <strong class="sa-total-count">0</strong> members are selected.&ensp;<button type="button" class="link sa-clear-btn">Clear selection</button></span>
    </div>
    <div class="table-wrap">
    <table class="data-table mem-table">
      <thead>
        <tr>
          <th class="cb-col"><input type="checkbox" id="check-all"></th>
          <th><a href="<?= View::e($memSortUrl('name')) ?>" class="sort-th<?= $sort === 'name' ? ' sort-active' : '' ?>">Name <?= View::sortIcon('name', $sort, $dir) ?></a></th>
          <th><a href="<?= View::e($memSortUrl('email')) ?>" class="sort-th<?= $sort === 'email' ? ' sort-active' : '' ?>">Email <?= View::sortIcon('email', $sort, $dir) ?></a></th>
          <th><a href="<?= View::e($memSortUrl('country')) ?>" class="sort-th<?= $sort === 'country' ? ' sort-active' : '' ?>">Country <?= View::sortIcon('country', $sort, $dir) ?></a></th>
          <?php foreach ($fields as $field): ?>
            <th><?= View::e($field->label) ?></th>
          <?php endforeach; ?>
          <th><a href="<?= View::e($memSortUrl('status')) ?>" class="sort-th<?= $sort === 'status' ? ' sort-active' : '' ?>">Status <?= View::sortIcon('status', $sort, $dir) ?></a></th>
          <th class="act-col">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($members as $m): ?>
        <tr>
          <td class="cb-col" data-label="">
            <input type="checkbox" name="emails[]" value="<?= View::e($m->email) ?>"
                   data-name="<?= View::e($m->name) ?>">
          </td>
          <td data-label="Name">
            <div class="subject-title-stack">
              <?= View::e($m->name) ?>
              <?php if ($m->url): ?>
                <a href="<?= View::e($m->url) ?>" target="_blank" rel="noopener noreferrer"
                   class="link text-dim mem-url"><?= View::e($m->url) ?></a>
              <?php endif; ?>
            </div>
          </td>
          <td data-label="Email"><?= View::emailWrap($m->email) ?></td>
          <td class="text-muted" data-label="Country"><?= View::e($m->country) ?></td>
          <?php foreach ($fields as $field): ?>
            <td class="text-muted" data-label="<?= View::e($field->label) ?>"><?= View::e($m->additionalFields[$field->name] ?? '') ?></td>
          <?php endforeach; ?>
          <td data-label="Status">
            <span class="badge <?= $m->approved ? 'badge-current' : 'badge-pending' ?>">
              <?= $m->approved ? 'Approved' : 'Pending' ?>
            </span>
          </td>
          <td class="act-col" data-label="">
            <div class="data-actions">
              <?php if (!$m->approved): ?>
                <button type="submit" form="approve-member-<?= md5($m->email) ?>" class="btn btn-success btn-sm">Approve</button>
              <?php endif; ?>
              <a href="<?= $b ?>/admin/members/<?= $lid ?>/edit?email=<?= urlencode($m->email) ?>"
                 class="btn btn-tertiary btn-sm">Edit</a>
              <a href="<?= $b ?>/admin/members/<?= $lid ?>/emailmember?email=<?= urlencode($m->email) ?>"
                 class="btn btn-quaternary btn-sm">Email</a>
              <button type="submit" form="del-member-<?= md5($m->email) ?>" class="btn btn-danger btn-sm">Delete</button>
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
  <form id="approve-member-<?= md5($m->email) ?>" method="POST"
        action="<?= $b ?>/admin/members/<?= $lid ?>/approve" class="action-form-inline">
    <input type="hidden" name="_csrf"        value="<?= View::e($csrf_token ?? '') ?>">
    <input type="hidden" name="member_email" value="<?= View::e($m->email) ?>">
    <input type="hidden" name="_page"        value="<?= (int) $page ?>">
    <input type="hidden" name="_status"      value="<?= View::e($status) ?>">
  </form>
  <?php endif; ?>
  <form id="del-member-<?= md5($m->email) ?>" method="POST"
        action="<?= $b ?>/admin/members/<?= $lid ?>/delete"
        data-del-modal data-del-entity="member"
        data-del-name="<?= View::e($m->name) ?>"
        data-del-context="<?= View::e($listing->name) ?>">
    <input type="hidden" name="_csrf"        value="<?= View::e($csrf_token ?? '') ?>">
    <input type="hidden" name="member_email" value="<?= View::e($m->email) ?>">
    <input type="hidden" name="_page"        value="<?= (int) $page ?>">
    <input type="hidden" name="_status"      value="<?= View::e($status) ?>">
  </form>
<?php endforeach; ?>

<?php
$memFilterQs = http_build_query(array_filter([
    'listing_id' => $lid > 0 ? $lid : '',
    'search'     => $search,
    'status'     => $status !== 'all' ? $status : '',
], static fn($v): bool => $v !== '' && $v !== 0));
$memPageUrl = static function (int $p) use ($b, $memFilterQs): string {
    $qs = $memFilterQs !== '' ? $memFilterQs . '&page=' . $p : 'page=' . $p;
    return $b . '/admin/members?' . $qs;
};
$pg_current = $page; $pg_total = $pages; $pg_url = $memPageUrl;
include LUSTER_BASE . '/templates/admin/_pagination.php';
?>

<?php endif; /* end listing view */ ?>


<?php elseif ($viewMode === 'search'): ?>
<!-- ──── SEARCH VIEW: cross-listing results ──────────────────────────── -->

<?php $total = count($search_results ?? []); ?>
<p class="result-count member-results-count">
  <?= $total ?> result<?= $total !== 1 ? 's' : '' ?> for
  &ldquo;<?= View::e($search) ?>&rdquo; across all listings
</p>

<?php if ($total === 0): ?>
  <div class="card">
    <div class="empty">
      <div class="empty-icon"><?= View::icon('members', ['width' => 32, 'height' => 32]) ?></div>
      <div class="empty-title">No members found matching that search.</div>
      <a href="<?= $b ?>/admin/members" class="btn btn-ghost btn-sm">Clear Search</a>
    </div>
  </div>
<?php else: ?>
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
          <th class="act-col">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($search_results as $row):
          $fl   = $row['listing'];
          $m    = $row['member'];
          $flId = (int) $fl->id;
        ?>
        <tr>
          <td data-label="Listing">
            <a href="<?= $b ?>/admin/members?listing_id=<?= $flId ?>&status=all" class="link">
              <?= View::e($fl->name) ?>
            </a>
          </td>
          <td data-label="Name">
            <div class="subject-title-stack">
              <?= View::e($m->name) ?>
              <?php if ($m->url !== ''): ?>
                <a href="<?= View::e($m->url) ?>" target="_blank" rel="noopener noreferrer"
                   class="link text-dim mem-url"><?= View::e($m->url) ?></a>
              <?php endif; ?>
            </div>
          </td>
          <td data-label="Email"><?= View::emailWrap($m->email) ?></td>
          <td data-label="Country"><?= View::e($m->country) ?></td>
          <td data-label="Status">
            <?php if ($m->approved): ?>
              <span class="badge badge-current">Approved</span>
            <?php else: ?>
              <span class="badge badge-upcoming">Pending</span>
            <?php endif; ?>
          </td>
          <td data-label="Joined" class="cell-meta-nowrap">
            <?= View::e(($m->added ?? '') !== '' ? date($admin_date_format, strtotime($m->added)) : '') ?>
          </td>
          <td class="act-col" data-label="">
            <div class="data-actions">
              <?php if (!$m->approved): ?>
                <form method="POST" action="<?= $b ?>/admin/members/<?= $flId ?>/approve" class="action-form-inline">
                  <input type="hidden" name="_csrf"        value="<?= View::e($csrf_token ?? '') ?>">
                  <input type="hidden" name="member_email" value="<?= View::e($m->email) ?>">
                  <button type="submit" class="btn btn-success btn-sm">Approve</button>
                </form>
              <?php endif; ?>
              <a href="<?= $b ?>/admin/members/<?= $flId ?>/edit?email=<?= urlencode($m->email) ?>"
                 class="btn btn-tertiary btn-sm">Edit</a>
              <a href="<?= $b ?>/admin/members/<?= $flId ?>/emailmember?email=<?= urlencode($m->email) ?>"
                 class="btn btn-quaternary btn-sm">Email</a>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  </div>
<?php endif; ?>


<?php else: ?>
<!-- ──── OVERVIEW: pending grouped by listing + pending updates ───────── -->

<?php
$_totalNewMembers  = array_sum(array_map(static fn($g) => count($g['members']), $grouped ?? []));
$_totalUpdates     = count($pending_updates ?? []);
$_totalPending     = $_totalNewMembers + $_totalUpdates;
?>
<?php if ($_totalPending > 0): ?>
<div class="reminder-banner">
  <div class="reminder-banner-head">
    <div class="reminder-banner-left">
      <span class="reminder-banner-icon"><?= View::icon('bell') ?></span>
      <div>
        <span class="reminder-banner-title">Action Required!</span>
        <span class="reminder-banner-msg">
          <?php
            $parts = [];
            if ($_totalNewMembers > 0) { $parts[] = $_totalNewMembers . ' new member' . ($_totalNewMembers !== 1 ? 's' : ''); }
            if ($_totalUpdates > 0)    { $parts[] = $_totalUpdates    . ' pending update' . ($_totalUpdates !== 1 ? 's' : ''); }
            echo implode(' and ', $parts) . ' across all listings';
          ?>
        </span>
      </div>
    </div>
    <div class="inline-actions-row">
      <form method="POST" action="<?= $b ?>/admin/members/approve-all-overview"
            data-confirm="Approve all <?= $_totalPending ?> pending item(s) across all listings? This will send approval emails to each member.">
        <input type="hidden" name="_csrf" value="<?= View::e($csrf_token ?? '') ?>">
        <button type="submit" class="btn btn-success btn-sm">Approve All (<?= $_totalPending ?>) &rarr;</button>
      </form>
    </div>
  </div>
</div>
<?php endif; ?>

<div class="overview-section-head">
  <div class="overview-section-title">
    <?= View::icon('pending') ?> Pending New Members
  </div>
  <div class="overview-section-sub">New join requests awaiting your approval decision.</div>
</div>

<?php if (empty($grouped)): ?>
  <div class="card">
    <div class="empty">
      <div class="empty-icon"><?= View::icon('pending', ['width' => 32, 'height' => 32]) ?></div>
      <div class="empty-title">No pending members.</div>
      <div>You're all caught up.</div>
    </div>
  </div>
<?php else: ?>
  <?php foreach ($grouped as $g):
    $fl             = $g['listing'];
    $flId           = (int) $fl->id;
    $pendingMembers = $g['members'];
    $pendingCount   = count($pendingMembers);
    $approvedCount  = (int) ($g['approved_count'] ?? 0);
  ?>
  <div class="card">
    <div class="card-head">
      <a href="<?= $b ?>/admin/members?listing_id=<?= $flId ?>&status=all" class="link"><?= View::e($fl->name) ?></a>
      <span class="text-dim">
        &mdash; <?= $pendingCount ?> pending<?= $approvedCount > 0 ? ', ' . $approvedCount . ' approved' : '' ?>
      </span>
    </div>
    <form method="POST" action="<?= $b ?>/admin/members/<?= $flId ?>/bulk"
          data-del-bulk data-del-entity="member_reject">
    <input type="hidden" name="_csrf" value="<?= View::e($csrf_token ?? '') ?>">
    <input type="hidden" name="from" value="overview">
    <div class="bulk bulk--flush">
      Bulk Actions:
      <div class="select-wrap">
        <select name="bulk_action" class="select-compact">
          <option value="" disabled selected>&mdash; Select &mdash;</option>
          <option value="approve">Approve</option>
          <option value="delete">Reject</option>
        </select>
      </div>
      <button type="submit" class="btn btn-quaternary btn-sm">Apply</button>
    </div>
    <div class="table-wrap">
    <table class="data-table mem-table">
      <thead>
        <tr>
          <th class="cb-col">
            <input type="checkbox" class="sel-all" data-fid="<?= $flId ?>" title="Select all">
          </th>
          <th><a href="<?= View::e($pendingSortUrl('name')) ?>" class="sort-th<?= $sort === 'name' ? ' sort-active' : '' ?>">Name <?= View::sortIcon('name', $sort, $dir) ?></a></th>
          <th><a href="<?= View::e($pendingSortUrl('email')) ?>" class="sort-th<?= $sort === 'email' ? ' sort-active' : '' ?>">Email <?= View::sortIcon('email', $sort, $dir) ?></a></th>
          <th><a href="<?= View::e($pendingSortUrl('country')) ?>" class="sort-th<?= $sort === 'country' ? ' sort-active' : '' ?>">Country <?= View::sortIcon('country', $sort, $dir) ?></a></th>
          <th class="act-col">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($pendingMembers as $m): ?>
        <tr>
          <td class="cb-col" data-label="">
            <input type="checkbox" name="emails[]" class="row-cb-<?= $flId ?>"
                   value="<?= View::e($m->email) ?>"
                   data-name="<?= View::e($m->name) ?>">
          </td>
          <td class="" data-label="Name">
            <div class="subject-title-stack">
              <?= View::e($m->name) ?>
              <?php if ($m->url !== ''): ?>
                <a href="<?= View::e($m->url) ?>" target="_blank" rel="noopener noreferrer"
                   class="link text-dim mem-url"><?= View::e($m->url) ?></a>
              <?php endif; ?>
            </div>
          </td>
          <td data-label="Email"><?= View::emailWrap($m->email) ?></td>
          <td data-label="Country"><?= View::e($m->country) ?></td>
          <td class="act-col" data-label="">
            <div class="data-actions">
              <form method="POST" action="<?= $b ?>/admin/members/<?= $flId ?>/approve" class="action-form-inline">
                <input type="hidden" name="_csrf"        value="<?= View::e($csrf_token ?? '') ?>">
                <input type="hidden" name="member_email" value="<?= View::e($m->email) ?>">
                <button type="submit" class="btn btn-success btn-sm">Approve</button>
              </form>
              <a href="<?= $b ?>/admin/members/<?= $flId ?>/edit?email=<?= urlencode($m->email) ?>"
                 class="btn btn-tertiary btn-sm">Edit</a>
              <a href="<?= $b ?>/admin/members/<?= $flId ?>/emailmember?email=<?= urlencode($m->email) ?>"
                 class="btn btn-quaternary btn-sm">Email</a>
              <form method="POST"
                    action="<?= $b ?>/admin/members/<?= $flId ?>/delete"
                    data-del-modal data-del-entity="member_reject"
                    data-del-name="<?= View::e($m->name) ?>">
                <input type="hidden" name="_csrf"        value="<?= View::e($csrf_token ?? '') ?>">
                <input type="hidden" name="member_email" value="<?= View::e($m->email) ?>">
                <button type="submit" class="btn btn-danger btn-sm">Reject</button>
              </form>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    </form>
  </div>
  <?php endforeach; ?>
<?php endif; ?>

<div class="overview-section-divider"></div>
<div class="overview-section-head">
  <div class="overview-section-title">
    <?= View::icon('pendinginfo') ?> Pending Member Updates
  </div>
  <div class="overview-section-sub">Members requesting changes to their details - review each diff and accept or reject.</div>
</div>
<?php if (empty($pending_updates)): ?>
  <div class="card">
    <div class="empty">
      <div class="empty-icon"><?= View::icon('pendinginfo', ['width' => 32, 'height' => 32]) ?></div>
      <div class="empty-title">No pending member updates.</div>
      <div>You're all caught up.</div>
    </div>
  </div>
<?php else: ?>
  <div class="card">
    <form method="POST" action="<?= $b ?>/admin/pending-updates/bulk"
          data-del-bulk data-del-entity="pending_update_reject">
      <input type="hidden" name="_csrf" value="<?= View::e($csrf_token ?? '') ?>">
      <div class="bulk bulk--flush">
        Bulk Actions:
        <div class="select-wrap">
          <select name="bulk_action" class="select-compact">
            <option value="" disabled selected>&mdash; Select &mdash;</option>
            <option value="approve">Approve</option>
            <option value="reject">Reject</option>
          </select>
        </div>
        <button type="submit" class="btn btn-quaternary btn-sm">Apply</button>
      </div>
      <div class="table-wrap">
        <table class="data-table mem-table">
          <thead>
            <tr>
              <th class="cb-col"><input type="checkbox" class="sel-all" data-fid="pu" title="Select all"></th>
              <th>Listing</th>
              <th>Member Email</th>
              <th>Fields Changed</th>
              <th>Submitted</th>
              <th class="act-col">Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($pending_updates as $pu):
              $puListing = ($fl_by_id ?? [])[(int) $pu->fanlisting_id] ?? null;
              $puFlId    = (int) $pu->fanlisting_id;
              $puCount   = is_array($pu->changes) ? count($pu->changes) : 0;
            ?>
            <tr>
              <td class="cb-col" data-label="">
                <input type="checkbox" name="ids[]" class="row-cb-pu"
                       value="<?= (int) $pu->id ?>"
                       data-name="<?= View::e($pu->member_email) ?>">
              </td>
              <td data-label="Listing">
                <?php if ($puListing !== null): ?>
                  <a href="<?= $b ?>/admin/members?listing_id=<?= $puFlId ?>&status=all" class="link">
                    <?= View::e($puListing->name) ?>
                  </a>
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
                    <summary class="changes-toggle-summary">
                      <?= $puCount ?> field<?= $puCount !== 1 ? 's' : '' ?>
                      <?= View::icon('chevrondown') ?>
                    </summary>
                    <div class="changes-list">
                      <?php foreach ($pu->changes as $field => $val): ?>
                        <div class="changes-list-item">
                          <span class="changes-list-label"><?= View::e(ucfirst((string) $field)) ?>:</span>
                          <?= View::e((string) $val) ?>
                        </div>
                      <?php endforeach; ?>
                    </div>
                  </details>
                <?php endif; ?>
              </td>
              <td data-label="Submitted" class="cell-meta-nowrap"><?= View::e($pu->submitted_at) ?></td>
              <td class="act-col" data-label="">
                <div class="data-actions">
                  <form method="POST"
                        action="<?= $b ?>/admin/pending-updates/<?= (int) $pu->id ?>/approve"
                        class="action-form-inline"
                        data-confirm="Approve and apply this update?">
                    <input type="hidden" name="_csrf"  value="<?= View::e($csrf_token ?? '') ?>">
                    <input type="hidden" name="_from"  value="members">
                    <button type="submit" class="btn btn-success btn-sm">Approve</button>
                  </form>
                  <a href="<?= $b ?>/admin/members/<?= $puFlId ?>/edit?email=<?= urlencode($pu->member_email) ?>"
                     class="btn btn-tertiary btn-sm">Edit</a>
                  <form method="POST"
                        action="<?= $b ?>/admin/pending-updates/<?= (int) $pu->id ?>/reject"
                        data-del-modal data-del-entity="pending_update_reject"
                        data-del-name="<?= View::e($pu->member_email) ?>">
                    <input type="hidden" name="_csrf" value="<?= View::e($csrf_token ?? '') ?>">
                    <input type="hidden" name="_from" value="members">
                    <button type="submit" class="btn btn-danger btn-sm">Reject</button>
                  </form>
                </div>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </form>
  </div>
<?php endif; ?>

<?php endif; /* end view_mode switch */ ?>
