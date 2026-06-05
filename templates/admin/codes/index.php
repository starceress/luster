<?php

declare(strict_types=1);

use Luster\Core\View;

/**
 * Global Codes admin page.
 *
 * @var string       $view_mode      'global' | 'listing'
 * @var int          $listing_id     0 = global
 * @var ?\Luster\Models\Listing  $listing
 * @var \Luster\Models\Listing[] $all_listings
 * @var array        $listing_map    [id => Listing]  (global mode)
 * @var object[]     $codes
 * @var string       $tab            pending|all|approved|rejected
 * @var int          $pending_count
 * @var int          $total
 * @var int          $page
 * @var int          $total_pages
 * @var string       $success
 * @var string       $error
 * @var string       $base_url
 * @var string       $csrf_token
 */

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

$tabUrl     = static fn(string $t) => $buildUrl(['tab' => $t, 'page' => '']);
$pageUrl    = static fn(int $p)    => $buildUrl(['page' => $p > 1 ? $p : '']);
$tabCounts  = $tab_counts ?? [];
$donorMap   = $donor_map  ?? [];
$uploadsUrl = $uploads_url ?? ($b . '/uploads/codes');
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
      Filter<?php if ($lid > 0): ?><span class="filter-dot" aria-hidden="true"></span><?php endif; ?>
    </button>
  </div>
</div>

<!-- ──── FILTER PANEL ───────────────────────────────────────────────── -->
<div class="filter-panel" id="codes-filter-panel">
  <form method="GET" action="<?= $b ?>/admin/codes" class="filter-row" id="codes-filter-form">
    <div class="select-wrap">
      <?php $_ppl = $pending_per_listing ?? []; $_pplTotal = array_sum($_ppl); ?>
      <select name="listing_id" id="codes-listing-sel">
        <option value="0"<?= $lid === 0 ? ' selected' : '' ?>>All Listings</option>
        <?php foreach ($allListing as $fl): ?>
          <?php $_flPending = (int) ($_ppl[(int) $fl->id] ?? 0); ?>
          <option value="<?= (int) $fl->id ?>"<?= $lid === (int) $fl->id ? ' selected' : '' ?>>
            <?= View::e($fl->subject ?: $fl->title) ?><?= $_flPending > 0 ? ' (' . $_flPending . ')' : '' ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <a href="<?= $b ?>/admin/codes" class="btn btn-quaternary btn-sm">Reset</a>
  </form>
</div>

<?php if ($viewMode === 'global'): ?>
<!-- ══════════════════════════════════════════════════════════════════ -->
<!-- GLOBAL VIEW: pending donations across all listings                 -->
<!-- ══════════════════════════════════════════════════════════════════ -->

<?php if (empty($codes)): ?>
<div class="card">
  <div class="empty">
    <div class="empty-icon"><?= View::icon('image', ['width' => 32, 'height' => 32]) ?></div>
    <div class="empty-title">No pending code donations.</div>
    <div>Codes will appear here — select a listing above to manage it.</div>
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
      <span class="row-sub"><?= $_flCount ?> pending donation<?= $_flCount !== 1 ? 's' : '' ?></span>
      <div class="actions-row--right">
        <form method="POST" action="<?= $b ?>/admin/codes/approve-all" class="action-form-inline"
              data-confirm="Approve all <?= $_flCount ?> pending donation(s) for this listing?">
          <input type="hidden" name="_csrf"      value="<?= View::e($csrf_token) ?>">
          <input type="hidden" name="listing_id" value="<?= $_flId ?>">
          <input type="hidden" name="_tab"       value="pending">
          <button type="submit" class="btn btn-success btn-xs"><?= View::icon('check') ?> Approve All</button>
        </form>
      </div>
    </div>

    <div class="bulk bulk--flush">
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
            <th class="cb-col"><input type="checkbox" class="codes-cb-all"></th>
            <th>Image</th>
            <th>Size</th>
            <th>Donor</th>
            <th>Email</th>
            <th>Submitted</th>
            <th class="act-col">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($_flCodes as $code): ?>
          <tr>
            <td class="cb-col"><input type="checkbox" name="code_ids[]" value="<?= (int) $code->id ?>"></td>
            <td data-label="Image">
              <img src="<?= $b ?>/uploads/codes/<?= View::e($code->filename) ?>"
                   alt="" style="max-width:200px;max-height:200px;display:block;border-radius:3px;object-fit:contain">
            </td>
            <td data-label="Size" class="text-muted" style="white-space:nowrap">
              <?= ($code->width ?? 0) > 0 ? (int)$code->width . '&times;' . (int)$code->height : '<span class="text-dim">&mdash;</span>' ?>
            </td>
            <td data-label="Donor">
              <div class="subject-title-stack">
                <?= ($code->submitter_name ?? '') !== '' ? View::e($code->submitter_name) : '<span class="text-dim">&mdash;</span>' ?>
                <?php if (($code->submitter_url ?? '') !== ''): ?>
                  <a href="<?= View::e($code->submitter_url) ?>" target="_blank" rel="noopener noreferrer"
                     class="link text-dim mem-url"><?= View::e(mb_strimwidth($code->submitter_url, 0, 40, '…')) ?></a>
                <?php endif; ?>
              </div>
            </td>
            <td data-label="Email">
              <?= ($code->submitter_email ?? '') !== '' ? View::emailWrap($code->submitter_email) : '<span class="text-dim">&mdash;</span>' ?>
            </td>
            <td class="text-muted" data-label="Submitted">
              <?= ($code->created_at ?? '') !== '' ? View::e(date($admin_date_format, strtotime($code->created_at))) : '&mdash;' ?>
            </td>
            <td class="act-col" data-label="">
              <div class="data-actions">
                <form method="POST" action="<?= $b ?>/admin/codes/<?= (int) $code->id ?>/approve" class="action-form-inline">
                  <input type="hidden" name="_csrf"      value="<?= View::e($csrf_token) ?>">
                  <input type="hidden" name="listing_id" value="0">
                  <input type="hidden" name="_tab"       value="pending">
                  <button type="submit" class="btn btn-success btn-xs">Approve</button>
                </form>
                <form method="POST" action="<?= $b ?>/admin/codes/<?= (int) $code->id ?>/reject" class="action-form-inline">
                  <input type="hidden" name="_csrf"      value="<?= View::e($csrf_token) ?>">
                  <input type="hidden" name="listing_id" value="0">
                  <input type="hidden" name="_tab"       value="pending">
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

<?php endif; // empty($codes) ?>

<?php else: ?>
<!-- ══════════════════════════════════════════════════════════════════ -->
<!-- PER-LISTING VIEW: code browser + link to full management          -->
<!-- ══════════════════════════════════════════════════════════════════ -->

<div class="toolbar" style="justify-content:flex-end;margin-bottom:1rem">
  <a href="<?= $b ?>/admin/listings/<?= $lid ?>#tab-codes" class="btn btn-quaternary btn-sm">
    <?= View::icon('gear') ?> Upload &amp; Manage Categories
  </a>
</div>

<!-- Status Tabs -->
<div class="tabs-wrap">
  <div class="tabs">
    <?php
      $listingTabDefs = [
        'all'      => ['label' => 'All',      'badge' => 'badge-inactive'],
        'pending'  => ['label' => 'Pending',  'badge' => 'badge-pending'],
        'approved' => ['label' => 'Approved', 'badge' => 'badge-current'],
        'rejected' => ['label' => 'Rejected', 'badge' => 'badge-error'],
      ];
    ?>
    <?php foreach ($listingTabDefs as $tKey => $tDef): ?>
      <a href="<?= View::e($tabUrl($tKey)) ?>"
         class="tabs-btn<?= $tab === $tKey ? ' active' : '' ?>">
        <?= View::e($tDef['label']) ?>
        <?php $tCnt = (int) ($tabCounts[$tKey] ?? 0); ?>
        <?php if ($tCnt > 0): ?>
          <span class="badge <?= $tDef['badge'] ?> badge-inline-sm"><?= $tCnt ?></span>
        <?php endif; ?>
      </a>
    <?php endforeach; ?>
  </div>
</div>

<?php if (empty($codes)): ?>
<div class="card">
  <div class="empty">
    <div class="empty-icon"><?= View::icon('image', ['width' => 32, 'height' => 32]) ?></div>
    <div class="empty-title">No codes<?= $tab !== 'all' ? ' in this status' : '' ?>.</div>
  </div>
</div>
<?php else: ?>

<!-- Approve All (Pending tab only) -->
<?php if ($tab === 'pending' && !empty($codes)): ?>
<div style="display:flex;justify-content:flex-end;margin-bottom:1rem">
  <form method="POST" action="<?= $b ?>/admin/codes/approve-all" class="action-form-inline"
        data-confirm="Approve all <?= (int) ($tabCounts['pending'] ?? 0) ?> pending code(s)?">
    <input type="hidden" name="_csrf"      value="<?= View::e($csrf_token) ?>">
    <input type="hidden" name="listing_id" value="<?= $lid ?>">
    <input type="hidden" name="_tab"       value="pending">
    <button type="submit" class="btn btn-success btn-sm"><?= View::icon('check') ?> Approve All Pending</button>
  </form>
</div>
<?php endif; ?>

<form method="POST" action="<?= $b ?>/admin/codes/bulk" id="codes-listing-bulk-form">
  <input type="hidden" name="_csrf"      value="<?= View::e($csrf_token) ?>">
  <input type="hidden" name="listing_id" value="<?= $lid ?>">
  <input type="hidden" name="_tab"       value="<?= View::e($tab) ?>">

  <div class="card">
    <div class="card-head">
      <?= View::e($listing->subject ?: $listing->title) ?> — <?= View::e(ucfirst($tab)) ?> Codes
      <span class="row-sub"><?= (int) $total ?> code<?= (int) $total !== 1 ? 's' : '' ?></span>
    </div>
    <div class="bulk bulk--flush">
      Bulk Actions:
      <div class="select-wrap">
        <select name="bulk_action" class="select-xs">
          <option value="" disabled selected>&mdash; Select &mdash;</option>
          <?php if (in_array($tab, ['all', 'pending'], true)): ?><option value="approve">Approve</option><?php endif; ?>
          <?php if (in_array($tab, ['all', 'approved'], true)): ?><option value="reject">Reject</option><?php endif; ?>
          <?php if ($tab === 'pending'): ?><option value="reject">Reject</option><?php endif; ?>
        </select>
      </div>
      <button type="submit" class="btn btn-quaternary btn-sm">Apply to Selected</button>
    </div>
    <div class="table-wrap">
      <table class="data-table mem-table">
        <thead>
          <tr>
            <th class="cb-col"><input type="checkbox" id="codes-listing-cb-all"></th>
            <th class="table-col-image">Image</th>
            <th>Size</th>
            <th>Donor / Submitter</th>
            <?php if ($tab !== 'approved'): ?><th>Status</th><?php endif; ?>
            <th class="act-col">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($codes as $code):
            $codeDonor = isset($donorMap[$code->donorId ?? null]) ? $donorMap[$code->donorId] : null;
          ?>
          <tr>
            <td class="cb-col"><input type="checkbox" name="code_ids[]" value="<?= (int) $code->id ?>"></td>
            <td data-label="Image">
              <img src="<?= View::e($uploadsUrl . '/' . $code->filename) ?>"
                   alt="" style="max-height:40px;max-width:60px;display:block;border-radius:3px;object-fit:contain">
            </td>
            <td data-label="Size" class="text-muted">
              <?= ($code->width ?? 0) > 0 ? (int) $code->width . '×' . (int) $code->height : '<span class="text-dim">&mdash;</span>' ?>
            </td>
            <td data-label="Donor">
              <?php if ($codeDonor !== null): ?>
                <span class="badge badge-info-pink"><?= View::e($codeDonor->name) ?></span>
              <?php elseif (($code->submitterName ?? '') !== ''): ?>
                <div class="subject-title-stack">
                  <?= View::e($code->submitterName) ?>
                  <?php if (($code->submitterEmail ?? '') !== ''): ?>
                    <span class="mem-url"><?= View::emailWrap($code->submitterEmail) ?></span>
                  <?php endif; ?>
                </div>
              <?php else: ?>
                <span class="text-dim">&mdash;</span>
              <?php endif; ?>
            </td>
            <?php if ($tab !== 'approved'): ?>
            <td data-label="Status">
              <span class="badge <?= $code->status === 'pending' ? 'badge-pending' : 'badge-error' ?>">
                <?= View::e(ucfirst($code->status)) ?>
              </span>
            </td>
            <?php endif; ?>
            <td class="act-col" data-label="">
              <div class="data-actions">
                <?php if ($code->status === 'pending'): ?>
                  <form method="POST" action="<?= $b ?>/admin/codes/<?= (int) $code->id ?>/approve" class="action-form-inline">
                    <input type="hidden" name="_csrf"      value="<?= View::e($csrf_token) ?>">
                    <input type="hidden" name="listing_id" value="<?= $lid ?>">
                    <input type="hidden" name="_tab"       value="<?= View::e($tab) ?>">
                    <button type="submit" class="btn btn-success btn-xs">Approve</button>
                  </form>
                  <form method="POST" action="<?= $b ?>/admin/codes/<?= (int) $code->id ?>/reject" class="action-form-inline">
                    <input type="hidden" name="_csrf"      value="<?= View::e($csrf_token) ?>">
                    <input type="hidden" name="listing_id" value="<?= $lid ?>">
                    <input type="hidden" name="_tab"       value="<?= View::e($tab) ?>">
                    <button type="submit" class="btn btn-danger btn-xs">Reject</button>
                  </form>
                <?php elseif ($code->status === 'approved'): ?>
                  <form method="POST" action="<?= $b ?>/admin/codes/<?= (int) $code->id ?>/reject" class="action-form-inline">
                    <input type="hidden" name="_csrf"      value="<?= View::e($csrf_token) ?>">
                    <input type="hidden" name="listing_id" value="<?= $lid ?>">
                    <input type="hidden" name="_tab"       value="<?= View::e($tab) ?>">
                    <button type="submit" class="btn btn-warning btn-xs">Reject</button>
                  </form>
                <?php endif; ?>
                <a href="<?= $b ?>/admin/listings/<?= $lid ?>#tab-codes" class="btn btn-tertiary btn-xs">Edit</a>
              </div>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</form>

<?php endif; // empty($codes) ?>

<?php endif; // view_mode ?>

