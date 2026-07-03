<?php
/*
 Luster -- https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Admin codes management page. Displays fan-submitted graphic codes in a tabbed
 view (pending, approved, rejected) with global and per-listing filtering.
 Supports bulk approve, reject, and delete actions along with individual code
 upload.
*/

declare(strict_types=1);

use Luster\Core\View;

$b          = rtrim(View::e($base_url), '/');
$lid        = (int) ($listing_id ?? 0);
$viewMode   = $view_mode ?? 'global';
$tab        = $tab ?? 'pending';
$codes      = $codes ?? [];
$allListing = $all_listings ?? [];

$buildUrl = static function (array $overrides) use ($b, $lid, $tab, $page): string {
    $p = [
        'listing_id' => $lid > 0 ? $lid : '',
        'tab'        => $tab,
        'page'       => ($page ?? 1) > 1 ? ($page ?? 1) : '',
    ];
    $merged = array_merge($p, $overrides);
    $qs = http_build_query(array_filter($merged, static fn($v) => $v !== '' && $v !== 0 && $v !== null));
    return $b . '/admin/codes' . ($qs !== '' ? '?' . $qs : '');
};

$tabUrl       = static fn(string $t) => $buildUrl(['tab' => $t, 'page' => '']);
$pageUrl      = static fn(int $p)    => $buildUrl(['page' => $p > 1 ? $p : '']);
$tabCounts    = $tab_counts    ?? [];
$donorMap     = $donor_map     ?? [];
$uploadsUrl   = $uploads_url   ?? ($b . '/uploads/codes');
$filterOpts   = $filter_options ?? ['sizes' => [], 'donors' => []];
$filterSize   = $filter_size   ?? '';
$filterDonor  = $filter_donor  ?? '';
$filterSearch = $filter_search ?? '';
$filtersActive = $filters_active ?? false;
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
    <button type="button" class="btn btn-preview btn-sm filter-toggle"
            id="codes-filter-toggle" aria-expanded="true" aria-controls="codes-filter-panel">
      <?= View::icon('filter') ?>
      Filter<?php if ($lid > 0 || $filtersActive || $viewMode === 'collective'): ?><span class="filter-dot" aria-hidden="true"></span><?php endif; ?>
    </button>
  </div>
  <?php if ($viewMode !== 'collective'): ?>
  <div class="actions-row actions-row--right">
    <a href="<?= $b ?>/admin/codes?collective=1" class="btn btn-quaternary btn-sm"><?= View::icon('image') ?> Collective Codes</a>
  </div>
  <?php endif; ?>
</div>

<!-- ──── FILTER PANEL ───────────────────────────────────────────────── -->
<div class="filter-panel" id="codes-filter-panel">
  <form method="GET" action="<?= $b ?>/admin/codes" class="filter-row" id="codes-filter-form">
    <?php if ($lid > 0): ?>
      <input type="hidden" name="listing_id" value="<?= $lid ?>">
    <?php endif; ?>

    <div class="select-wrap">
      <?php
        $_apl           = $approved_per_listing ?? [];
        $_aplCollective = (int) ($_apl[0] ?? 0);
        $_aplTotal      = array_sum($_apl) - $_aplCollective;
      ?>
      <select name="listing_id" id="codes-listing-sel">
        <option value="0"<?= $lid === 0 && $viewMode !== 'collective' ? ' selected' : '' ?>>All Listings<?= $_aplTotal > 0 ? ' (' . $_aplTotal . ')' : '' ?></option>
        <option value="collective"<?= $viewMode === 'collective' ? ' selected' : '' ?>><?= ($site_name ?? '') !== '' ? View::e($site_name) . ' (Collective)' : 'Collective' ?><?= $_aplCollective > 0 ? ' (' . $_aplCollective . ')' : '' ?></option>
        <?php foreach ($allListing as $fl): ?>
          <?php $_flApproved = (int) ($_apl[(int) $fl->id] ?? 0); ?>
          <option value="<?= (int) $fl->id ?>"<?= $lid === (int) $fl->id ? ' selected' : '' ?>>
            <?= View::e($fl->subject ?: $fl->title) ?><?= $_flApproved > 0 ? ' (' . $_flApproved . ')' : '' ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>

    <?php if ($viewMode !== 'collective' && !empty($filterOpts['sizes'])): ?>
    <div class="select-wrap">
      <select name="size" id="codes-size-sel">
        <option value="">All Sizes</option>
        <?php foreach ($filterOpts['sizes'] as $sz): ?>
          <?php $_szVal = is_array($sz) ? $sz['value'] : $sz; $_szCnt = is_array($sz) ? $sz['count'] : null; ?>
          <option value="<?= View::e($_szVal) ?>"<?= $filterSize === $_szVal ? ' selected' : '' ?>>
            <?= View::e(str_replace('x', '×', $_szVal)) ?><?= $_szCnt !== null ? ' (' . $_szCnt . ')' : '' ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <?php endif; ?>

    <?php if ($viewMode !== 'collective' && !empty($filterOpts['donors'])): ?>
    <div class="select-wrap">
      <select name="donor" id="codes-donor-sel">
        <option value="">All Donors</option>
        <?php foreach ($filterOpts['donors'] as $dn): ?>
          <?php $_dnVal = is_array($dn) ? $dn['value'] : $dn; $_dnCnt = is_array($dn) ? $dn['count'] : null; ?>
          <option value="<?= View::e($_dnVal) ?>"<?= $filterDonor === $_dnVal ? ' selected' : '' ?>>
            <?= View::e($_dnVal) ?><?= $_dnCnt !== null ? ' (' . $_dnCnt . ')' : '' ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <?php endif; ?>

    <div class="search-field-wrap">
      <?= View::icon('search', ['class' => 'search-field-icon', 'aria-hidden' => 'true']) ?>
      <input type="text" name="search" class="form"
             placeholder="<?= $viewMode === 'collective' ? 'Search donor or filename&hellip;' : 'Search listing or donor&hellip;' ?>"
             value="<?= View::e($filterSearch) ?>">
    </div>

    <?php if ($viewMode === 'global'): ?>
      <input type="hidden" name="tab" id="codes-global-tab" value="<?= View::e($tab) ?>">
    <?php endif; ?>
    <button type="submit" class="btn btn-secondary btn-sm">Search</button>
    <?php if ($viewMode === 'collective'): ?>
      <a href="<?= $b ?>/admin/codes?collective=1" class="btn btn-quaternary btn-sm">Reset</a>
    <?php elseif ($viewMode === 'global'): ?>
      <a href="<?= $b ?>/admin/codes?tab=<?= urlencode($tab) ?>" class="btn btn-quaternary btn-sm">Reset</a>
    <?php else: ?>
      <a href="<?= $b ?>/admin/codes?listing_id=<?= $lid ?>" class="btn btn-quaternary btn-sm">Reset</a>
    <?php endif; ?>
  </form>
</div>

<?php if ($viewMode === 'collective'): ?>
<!-- ══════════════════════════════════════════════════════════════════ -->
<!-- COLLECTIVE VIEW: full tile-grid layout (mirrors per-listing view)  -->
<!-- ══════════════════════════════════════════════════════════════════ -->

<?php
$_colCounts         = $code_status_counts ?? ['approved' => 0, 'pending' => 0, 'rejected' => 0];
$_colPending        = (int) $_colCounts['pending'];
$_colApproved       = (int) $_colCounts['approved'];
$_colRejected       = (int) $_colCounts['rejected'];
$_colGrouped        = $code_grouped ?? [];
$_colNonEmpty       = array_filter($_colGrouped, fn($g) => !empty($g['codes']));
$_colGroupCount     = count($_colNonEmpty);
$_colStatusFilter   = $code_status_filter ?? 'approved';
$_colTotal          = $total_codes ?? 0;
$_colBaseUrl        = $b . '/admin/codes?collective=1';
$_colUploads        = $uploads_url ?? ($b . '/uploads/codes');
?>

<form id="codes-bulk-form" method="POST" action="<?= $b ?>/admin/codes/collective/bulk">
  <input type="hidden" name="_csrf" value="<?= View::e($csrf_token) ?>">
  <input type="hidden" name="_tab"  value="<?= View::e($_colStatusFilter) ?>">
</form>
<form id="codes-reorder-form" method="POST" action="<?= $b ?>/admin/codes/collective/reorder">
  <input type="hidden" name="_csrf"     value="<?= View::e($csrf_token) ?>">
  <input type="hidden" name="_tab"      value="<?= View::e($_colStatusFilter) ?>">
  <input type="hidden" id="reorder-ids" name="ordered_ids" value="">
</form>

<?php foreach ($codes as $code): ?>
<dialog id="edit-dialog-<?= (int)$code->id ?>" class="modal code-edit-dialog">
  <div class="modal-header modal-header-sticky">
    <span class="modal-title">Edit code &mdash; <?= View::e($code->filename) ?></span>
    <button type="button" class="modal-close" data-close-dialog aria-label="Close">&times;</button>
  </div>
  <div class="modal-body">
    <div class="code-preview">
      <img src="<?= View::e($_colUploads . '/' . $code->filename) ?>"
           alt="<?= View::e($code->filename) ?>"
           class="code-preview-img">
      <?php if ($code->width > 0 && $code->height > 0): ?>
      <div class="code-preview-meta"><?= (int)$code->width ?>&times;<?= (int)$code->height ?>px</div>
      <?php endif; ?>
    </div>
    <form method="POST"
          action="<?= $b ?>/admin/codes/collective/<?= (int)$code->id ?>/update"
          class="code-modal-form">
      <input type="hidden" name="_csrf" value="<?= View::e($csrf_token) ?>">
      <input type="hidden" name="_tab"  value="<?= View::e($_colStatusFilter) ?>">
      <div>
        <label class="field-label code-modal-label">Label <span class="text-dim">(optional)</span></label>
        <input class="form" type="text" name="name" value="<?= View::e($code->name ?? '') ?>"
               placeholder="<?= View::e($code->filename) ?>">
      </div>
      <div>
        <label class="field-label code-modal-label">Status</label>
        <div class="select-wrap">
          <select name="status">
            <option value="approved" <?= $code->status === 'approved' ? 'selected' : '' ?>>Live</option>
            <option value="pending"  <?= $code->status === 'pending'  ? 'selected' : '' ?>>Pending</option>
            <option value="rejected" <?= $code->status === 'rejected' ? 'selected' : '' ?>>Rejected</option>
          </select>
        </div>
      </div>
      <?php $_hasDonorInfo = $code->submitterName !== null || $code->submitterEmail !== null || $code->submitterUrl !== null; ?>
      <details<?= $_hasDonorInfo ? ' open' : '' ?> class="code-donor-details">
        <summary class="link" style="font-size:var(--fs-sm);margin-bottom:.5rem;display:inline-block;list-style:none;cursor:pointer"><?= $_hasDonorInfo ? 'Donor information' : '+ Add Donor Information' ?></summary>
        <div>
          <label class="field-label code-modal-label">Donor Name</label>
          <input class="form" type="text" name="submitter_name"
                 value="<?= View::e($code->submitterName ?? '') ?>" placeholder="Display name&hellip;">
        </div>
        <div style="margin-top:.5rem">
          <label class="field-label code-modal-label">Donor Email</label>
          <input class="form" type="email" name="submitter_email"
                 value="<?= View::e($code->submitterEmail ?? '') ?>" placeholder="Optional">
        </div>
        <div style="margin-top:.5rem">
          <label class="field-label code-modal-label">Donor Website</label>
          <input class="form" type="url" name="submitter_url"
                 value="<?= View::e($code->submitterUrl ?? '') ?>" placeholder="https://&hellip;">
        </div>
      </details>
      <div class="composer-footer" style="margin-top:1rem">
        <button type="submit" class="btn btn-primary btn-sm">Save changes</button>
        <button type="button" class="btn btn-danger btn-sm code-dialog-delete-btn">Delete</button>
      </div>
    </form>
    <form class="code-delete-form"
          method="POST"
          action="<?= $b ?>/admin/codes/collective/<?= (int)$code->id ?>/delete"
          data-del-modal data-del-entity="code"
          data-del-name="<?= View::e($code->filename) ?>" style="display:none">
      <input type="hidden" name="_csrf" value="<?= View::e($csrf_token) ?>">
      <input type="hidden" name="_tab"  value="<?= View::e($_colStatusFilter) ?>">
    </form>
  </div>
</dialog>
<?php endforeach; ?>

<div class="codes-page-layout">

<!-- ── Upload Card ─────────────────────────────────────────────────── -->
<div class="card">
  <div class="card-head">Upload Codes</div>
  <form method="POST" action="<?= $b ?>/admin/codes/collective-upload" enctype="multipart/form-data">
    <input type="hidden" name="_csrf" value="<?= View::e($csrf_token) ?>">
    <div class="card-body">
      <div class="file-drop-zone">
        <input type="file" id="codes-input" name="codes[]" multiple accept="image/jpeg,image/png,image/gif,image/webp">
        <span class="file-drop-icon"><?= View::icon('image') ?></span>
        <strong>Drag &amp; drop or click to browse</strong>
        <span class="form-hint">JPEG, PNG, GIF, WebP &mdash; max 500 KB each</span>
      </div>
      <p class="panel-hint" style="margin-top:.5rem">Multiple files allowed. Dimensions are detected automatically.</p>
    </div>

    <div class="card-body">
      <?php $_colSubmitters = $code_submitter_names ?? []; ?>
      <div class="panel-field">
        <label class="panel-label" for="cc-donor">Donor</label>
        <div class="select-wrap">
          <select id="cc-donor" name="donor_id">
            <option value="">None</option>
            <?php if (!empty($_colSubmitters)): ?>
              <optgroup label="Past Submitters">
                <?php foreach ($_colSubmitters as $sn): ?>
                  <option value="s:<?= View::e($sn) ?>"><?= View::e($sn) ?></option>
                <?php endforeach; ?>
              </optgroup>
            <?php endif; ?>
          </select>
        </div>
        <p class="panel-hint"><a href="#" id="upload-add-donor-toggle" class="link btn"><?= View::icon('add') ?> Add New Donor</a></p>
      </div>

      <div id="upload-new-donor-wrap" class="is-hidden">
        <div class="panel-field">
          <label class="panel-label" for="cc-donor-name">Donor Name <span class="req">*</span></label>
          <input class="form" type="text" id="cc-donor-name" name="new_donor_name" placeholder="Display name&hellip;">
        </div>
        <div class="panel-field">
          <label class="panel-label" for="cc-donor-email">Email</label>
          <input class="form" type="email" id="cc-donor-email" name="new_donor_email" placeholder="Optional">
        </div>
        <div class="panel-field">
          <label class="panel-label" for="cc-donor-url">Website</label>
          <input class="form" type="url" id="cc-donor-url" name="new_donor_url" placeholder="https://&hellip;">
        </div>
      </div>
    </div>

    <div class="composer-footer">
      <button type="submit" class="btn btn-primary">Add Code</button>
      <button type="reset" class="btn btn-ghost">Clear</button>
    </div>
  </form>
</div>

<!-- ── Current Codes Card ───────────────────────────────────────────── -->
<div class="card codes-browser" style="min-width:0" data-initial-code-filter="<?= View::e($_colStatusFilter) ?>" data-codes-base-url="<?= View::e($_colBaseUrl) ?>">
  <div class="card-head">Current Codes
    <span class="badge badge-<?= $_colApproved > 0 ? 'current' : 'inactive' ?>"><?= $_colApproved ?> Approved</span>
    <?php if ($_colPending > 0): ?><span class="badge badge-pending"><?= $_colPending ?> Pending</span><?php endif; ?>
    <?php if ($_colRejected > 0): ?><span class="badge badge-error"><?= $_colRejected ?> Rejected</span><?php endif; ?>
    <?php if ($_colApproved > 0): ?><a href="<?= $b ?>/admin/codes/collective/export" class="btn btn-preview btn-xs" style="margin-left:auto"><?= View::icon('download') ?> Export Codes (ZIP)</a><?php endif; ?>
    <?php if ($_colGroupCount > 0): ?><span class="card-head-sub">Click and drag the top left corner to reorder codes &mdash; changes are effective immediately.</span><?php endif; ?>
  </div>

  <div class="codes-tabs">
    <a href="<?= View::e($_colBaseUrl . '&tab=approved') ?>" class="tabs-btn<?= $_colStatusFilter === 'approved' ? ' active' : '' ?>" data-code-filter="approved">
      Approved <span class="badge badge-<?= $_colApproved > 0 ? 'current' : 'inactive' ?>"><?= $_colApproved ?></span>
    </a>
    <a href="<?= View::e($_colBaseUrl . '&tab=pending') ?>" class="tabs-btn<?= $_colStatusFilter === 'pending' ? ' active' : '' ?>" data-code-filter="pending">
      Pending <span class="badge badge-<?= $_colPending > 0 ? 'pending' : 'inactive' ?>"><?= $_colPending ?></span>
    </a>
    <a href="<?= View::e($_colBaseUrl . '&tab=rejected') ?>" class="tabs-btn<?= $_colStatusFilter === 'rejected' ? ' active' : '' ?>" data-code-filter="rejected">
      Rejected <span class="badge badge-<?= $_colRejected > 0 ? 'error' : 'inactive' ?>"><?= $_colRejected ?></span>
    </a>
  </div>

  <div class="codes-bulk">
    <label class="codes-bulk-label">
      <input type="checkbox" id="codes-select-all">Select all </label>
    <div class="select-wrap">
      <select id="bulk-action-sel" class="select-xs">
        <option value="" disabled selected>&mdash; Select &mdash;</option>
        <option value="delete">Delete</option>
        <option value="approve">Approve</option>
        <option value="reject">Reject</option>
      </select>
    </div>
    <button type="button" id="bulk-apply-btn" class="btn btn-quaternary btn-sm">Apply to Selected</button>
    <div class="codes-bulk-right">
      <button type="button" id="save-order-btn" class="btn btn-quaternary btn-sm is-hidden">Save Order</button>
    </div>
  </div>

  <?php if ($_colTotal === 0): ?>
    <div class="card-empty-state">
      <div class="empty">
        <span class="empty-icon"><?= View::icon('image', ['width' => 32, 'height' => 32]) ?></span>
        No collective codes yet. Use the Upload Codes panel to add some.
      </div>
    </div>
  <?php else: ?>
    <?php $_colFilterEmpty = ($_colStatusFilter === 'approved' && $_colApproved === 0) || ($_colStatusFilter === 'pending' && $_colPending === 0) || ($_colStatusFilter === 'rejected' && $_colRejected === 0); ?>
    <div class="card-empty-state<?= $_colFilterEmpty ? '' : ' is-hidden' ?>" id="codes-filter-empty">
      <div class="empty">
        <span class="empty-icon"><?= View::icon('image', ['width' => 32, 'height' => 32]) ?></span>
        No codes match this filter &mdash; <a href="<?= View::e($_colBaseUrl . '&tab=approved') ?>" class="link" data-code-filter="approved">show approved</a>.
      </div>
    </div>
    <?php foreach ($_colGrouped as $group): ?>
      <?php if (empty($group['codes'])) continue; ?>
      <?php
        $firstCode = reset($group['codes']);
        $gridMin   = max((int)$firstCode->width + 32, 80) . 'px';
      ?>
      <div class="card-body" data-code-group>
        <div class="section-label"><?= View::e($group['label']) ?>
          <span class="badge badge-inactive" data-code-group-count data-total-count="<?= count($group['codes']) ?>"><?= count($group['codes']) ?> code<?= count($group['codes']) !== 1 ? 's' : '' ?></span>
        </div>
        <div class="code-grid" data-code-group-grid style="grid-template-columns:repeat(auto-fill,minmax(<?= $gridMin ?>,1fr))">
        <?php foreach ($group['codes'] as $code): ?>
        <?php $_isDonated = $code->submitterName !== null; ?>
        <div class="code-tile code-card"
             data-code-status="<?= View::e($code->status) ?>"
             data-code-id="<?= (int)$code->id ?>">
          <input type="checkbox" form="codes-bulk-form" name="selected[]" value="<?= (int)$code->id ?>" class="code-tile-cb code-check">
          <span class="code-drag-handle drag-handle" title="Drag to reorder">&#8942;&#8942;</span>
          <img src="<?= View::e($_colUploads . '/' . $code->filename) ?>"
               alt="<?= (int)$code->width ?>&times;<?= (int)$code->height ?>px"
               class="code-tile-img" loading="lazy">
          <?php if (($code->name ?? '') !== ''): ?>
          <div class="text-muted"><?= View::e($code->name) ?></div>
          <?php endif; ?>
          <?php if ($code->submitterName !== null): ?>
          <div class="badge badge-info2">&hearts; <?= View::e($code->submitterName) ?></div>
          <?php endif; ?>
          <?php if ($code->status === 'pending'): ?>
          <span class="badge badge-pending">Pending</span>
          <?php endif; ?>
          <button type="button" class="btn btn-quaternary btn-sm code-edit-btn" data-dialog="edit-dialog-<?= (int)$code->id ?>">
            Edit
          </button>
        </div>
        <?php endforeach; ?>
        </div>
      </div>
    <?php endforeach; ?>
  <?php endif; ?>
</div><!-- /codes-browser -->

</div><!-- /codes-page-layout -->

<?php elseif ($viewMode === 'global'): ?>
<!-- ══════════════════════════════════════════════════════════════════ -->
<!-- GLOBAL VIEW: tab selector + All placeholder / Pending review      -->
<!-- ══════════════════════════════════════════════════════════════════ -->
<?php $_globalPending = (int) ($pending_count ?? 0); ?>

<div class="tabs-wrap">
  <div class="tabs">
    <a href="<?= View::e($tabUrl('all')) ?>" class="tabs-btn<?= $tab === 'all' ? ' active' : '' ?>">All</a>
    <a href="<?= View::e($tabUrl('pending')) ?>" class="tabs-btn<?= $tab === 'pending' ? ' active' : '' ?>">
      Pending <span class="badge badge-<?= $_globalPending > 0 ? 'pending' : 'inactive' ?>"><?= $_globalPending ?></span>
    </a>
  </div>
</div>
<div class="tabs-select-wrap">
  <span class="overview-section-title">View</span>
  <div class="select-wrap flex-fill">
    <select onchange="window.location.href=this.value">
      <option value="<?= View::e($tabUrl('all')) ?>"<?= $tab === 'all' ? ' selected' : '' ?>>All</option>
      <option value="<?= View::e($tabUrl('pending')) ?>"<?= $tab === 'pending' ? ' selected' : '' ?>>Pending (<?= $_globalPending ?>)</option>
    </select>
  </div>
</div>

<?php if ($tab === 'all'): ?>
<div class="card">
  <div class="empty">
    <div class="empty-icon"><?= View::icon('image', ['width' => 32, 'height' => 32]) ?></div>
    <div><strong>Use the dropdowns above to get started.</strong></div>
    <div>Choose a listing to view all of its codes.</div>
  </div>
</div>

<?php elseif (empty($codes)): ?>
<div class="card">
  <div class="empty">
    <div class="empty-icon"><?= View::icon('image', ['width' => 32, 'height' => 32]) ?></div>
    <div><strong>No pending code donations.</strong></div>
    <div>All caught up &mdash; select a listing above to manage its codes.</div>
  </div>
</div>

<?php else: ?>

<?php
  // Group codes by listing
  $_codesByListing = [];
  foreach ($codes as $_c) {
      $_codesByListing[(int) $_c->fanlisting_id][] = $_c;
  }

  // Global approve all
?>
<div style="display:flex;justify-content:flex-end;margin-bottom:1rem">
  <form method="POST" action="<?= $b ?>/admin/codes/approve-all"
        data-confirm="Approve All <?= (int) $pending_count ?> pending code donation(s)?">
    <input type="hidden" name="_csrf"      value="<?= View::e($csrf_token) ?>">
    <input type="hidden" name="listing_id" value="0">
    <input type="hidden" name="_tab"       value="pending">
    <button type="submit" class="btn btn-success btn-sm"><?= View::icon('check') ?> Approve All Pending</button>
  </form>
</div>

<?php foreach ($_codesByListing as $_flId => $_flCodes):
  $_flListing = ($listing_map ?? [])[$_flId] ?? null;
  $_flName    = $_flListing ? View::e($_flListing->subject ?: $_flListing->title) : '#' . $_flId;
  $_flCount   = count($_flCodes);
?>
<form method="POST" action="<?= $b ?>/admin/codes/bulk" style="margin-bottom:1.5rem">
  <input type="hidden" name="_csrf"      value="<?= View::e($csrf_token) ?>">
  <input type="hidden" name="listing_id" value="0">
  <input type="hidden" name="_tab"       value="pending">

  <div class="card">
    <div class="card-head">
      <?php if ($_flListing): ?>
        <a href="<?= $b ?>/admin/codes?listing_id=<?= $_flId ?>&tab=pending" class="link"><?= $_flName ?></a>
      <?php else: ?>
        <?= $_flName ?>
      <?php endif; ?>
      <span class="card-head-sub"><?= $_flCount ?> pending donation<?= $_flCount !== 1 ? 's' : '' ?></span>
      <div class="actions-row--right">
        <button type="button" class="btn btn-success btn-xs"
                data-global-approve-listing="<?= $_flId ?>"
                data-global-approve-count="<?= $_flCount ?>"
                data-global-approve-csrf="<?= View::e($csrf_token) ?>"
                data-global-approve-url="<?= $b ?>/admin/codes/approve-all">
          <?= View::icon('check') ?> Approve All
        </button>
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
            <th class="checkbox-col"><input type="checkbox" class="codes-cb-all"></th>
            <th>Image</th>
            <th>Size</th>
            <th>Donor</th>
            <th>Email</th>
            <th>Submitted</th>
            <th class="actions-col">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($_flCodes as $code): ?>
          <tr>
            <td class="checkbox-col"><input type="checkbox" name="code_ids[]" value="<?= (int) $code->id ?>"></td>
            <td data-label="Image">
              <img src="<?= $b ?>/uploads/codes/<?= View::e($code->filename) ?>"
                   alt="" style="max-width:200px;max-height:200px;display:block;border-radius:3px;object-fit:contain">
            </td>
            <td data-label="Size" class="text-muted" style="white-space:nowrap">
              <?= ($code->width ?? 0) > 0 ? (int)$code->width . '&times;' . (int)$code->height : '<span class="text-dim">&mdash;</span>' ?>
            </td>
            <td data-label="Donor">
              <div class="subject-title-stack">
                <?= ($code->submitter_name ?? '') !== '' ? '<b>' . View::e($code->submitter_name) . '</b>' : '<span class="text-dim">&mdash;</span>' ?>
                <?php if (($code->submitter_url ?? '') !== ''): ?>
                  <a href="<?= View::e($code->submitter_url) ?>" target="_blank" rel="noopener noreferrer"
                     class="mem-url link"><?= View::e(mb_strimwidth($code->submitter_url, 0, 40, '…')) ?></a>
                <?php endif; ?>
              </div>
            </td>
            <td data-label="Email">
              <?= ($code->submitter_email ?? '') !== '' ? View::emailWrap($code->submitter_email) : '<span class="text-dim">&mdash;</span>' ?>
            </td>
            <td class="text-muted" data-label="Submitted">
              <?= ($code->created_at ?? '') !== '' ? View::e(date($admin_date_format, strtotime($code->created_at))) : '&mdash;' ?>
            </td>
            <td class="actions-col" data-label="">
              <div class="actions-row">
                <form method="POST" action="<?= $b ?>/admin/codes/<?= (int) $code->id ?>/approve" class="action-form-inline">
                  <input type="hidden" name="_csrf"        value="<?= View::e($csrf_token) ?>">
                  <input type="hidden" name="listing_id"  value="0">
                  <input type="hidden" name="_tab"         value="pending">
                  <input type="hidden" name="_from_global" value="1">
                  <button type="submit" class="btn btn-success btn-xs">Approve</button>
                </form>
                <form method="POST" action="<?= $b ?>/admin/codes/<?= (int) $code->id ?>/reject" class="action-form-inline">
                  <input type="hidden" name="_csrf"        value="<?= View::e($csrf_token) ?>">
                  <input type="hidden" name="listing_id"  value="0">
                  <input type="hidden" name="_tab"         value="pending">
                  <input type="hidden" name="_from_global" value="1">
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
<?php endforeach; ?>

<?php endif; // empty($codes) / tab all ?>

<?php else: ?>
<!-- ══════════════════════════════════════════════════════════════════ -->
<!-- PER-LISTING VIEW: full tile grid + upload panel                   -->
<!-- ══════════════════════════════════════════════════════════════════ -->

<?php
$_fid              = $lid;
$_from             = 'codes';
$_hasCats          = !empty($code_cats ?? []);
$_hasDonors        = !empty($code_donors ?? []);
$_pendingCount     = ($code_status_counts ?? [])['pending']  ?? 0;
$_approvedCount    = ($code_status_counts ?? [])['approved'] ?? 0;
$_rejectedCount    = ($code_status_counts ?? [])['rejected'] ?? 0;
$_groupedCodes     = $code_grouped ?? [];
$_nonEmptyGroups   = array_filter($_groupedCodes, fn($g) => !empty($g['codes']));
$_groupCount       = count($_nonEmptyGroups);
$_codesBaseUrl     = $b . '/admin/codes?listing_id=' . $_fid;
$_codeStatusFilter = $code_status_filter ?? '';
$_totalCodes       = $total_codes ?? 0;
$_codeCatIds       = $code_category_ids ?? [];
$_codeDonorMap     = $code_donor_map ?? [];
$_codeDonors       = $code_donors ?? [];
$_codeCats         = $code_cats ?? [];
$_uploadSubmitters = $code_submitter_names ?? [];
require LUSTER_BASE . '/templates/admin/codes/_listing_panel.php';
?>

<?php endif; // view_mode ?>
