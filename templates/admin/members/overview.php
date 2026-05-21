<?php

declare(strict_types=1);

use Luster\Core\View;

/** @var array                       $grouped          [{listing: Listing, members: Member[]}] */
/** @var array                       $search_results   [{listing: Listing, member: Member}] */
/** @var array                       $pending_updates  pending update objects */
/** @var array                       $fl_by_id         listing id â€ â€™ Listing */
/** @var \Luster\Models\Listing[]    $all_listings */
/** @var string                      $search */
/** @var string                      $success */
/** @var string                      $error */
/** @var string                      $base_url */
/** @var string                      $csrf_token */

$b          = rtrim(View::e($base_url), '/');
$isSearch   = $search !== '';
$sort       = $sort ?? 'name';
$dir        = $dir  ?? 'asc';

$pendingSortUrl = static function (string $col) use ($b, $sort, $dir): string {
    $newDir = ($sort === $col && $dir === 'asc') ? 'desc' : 'asc';
    return $b . '/admin/members?' . http_build_query(['sort' => $col, 'dir' => $newDir]);
};
?>

<?php if (($success ?? '') !== ''): ?>
  <div class="alert alert-success"><?= View::e($success) ?></div>
<?php endif; ?>
<?php if (($error ?? '') !== ''): ?>
  <div class="alert alert-error"><?= View::e($error) ?></div>
<?php endif; ?>

<div class="toolbar">
  <div class="actions-row">
    <button type="button" class="btn btn-preview btn-sm filter-toggle" id="fl-filter-toggle"
            aria-expanded="<?= $isSearch ? 'true' : 'false' ?>"
            aria-controls="filter-panel">
      <?= View::icon('filter') ?>
      Filter<?php if ($isSearch): ?><span class="filter-dot" aria-hidden="true"></span><?php endif; ?>
    </button>
  </div>
  <div class="actions-row">
    <a href="<?= $b ?>/admin/members/export-pending" class="btn btn-quaternary btn-sm">&#8593; Export CSV</a>
    <a href="<?= $b ?>/admin/members/settings" class="btn btn-quaternary btn-sm">Display Settings</a>
  </div>
</div>
<div class="filter-panel<?= $isSearch ? ' is-open' : '' ?>" id="filter-panel">
  <form method="GET" action="<?= $b ?>/admin/members" class="filter-row">
    <div class="select-wrap">
      <select class="js-nav-select">
        <option value="<?= $b ?>/admin/members">All pending members</option>
        <?php foreach ($all_listings as $fl): ?>
          <option value="<?= $b ?>/admin/members/<?= (int) $fl->id ?>?status=all">
            <?= View::e($fl->name) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="search-field-wrap">
      <?= View::icon('search', ['class' => 'search-field-icon', 'aria-hidden' => 'true']) ?>
      <input type="text" name="search" value="<?= View::e($search ?? '') ?>"
             placeholder="Search all listings&hellip;" class="form">
    </div>
    <button type="submit" class="btn btn-secondary btn-sm">Search</button>
    <a href="<?= $b ?>/admin/members" class="btn btn-quaternary btn-sm">Reset</a>
  </form>
</div>
<?php if ($isSearch): ?>

<!-- ──── GLOBAL SEARCH RESULTS ──────────────────────────────────────── -->
  <?php $total = count($search_results); ?>
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
            $fl = $row['listing'];
            $m  = $row['member'];
            $flId = (int) $fl->id;
          ?>
          <tr>
            <td data-label="Listing">
              <a href="<?= $b ?>/admin/members/<?= $flId ?>" class="link">
                <?= View::e($fl->name) ?>
              </a>
            </td>
            <td class="text-strong" data-label="Name"><?= View::e($m->name) ?></td>
            <td data-label="Email"><?= View::e($m->email) ?></td>
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

<!-- ──── PENDING NEW MEMBERS BY LISTING ─────────────────────────────── -->
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
    <?php foreach ($grouped as $g): ?>
    <?php
      $fl             = $g['listing'];
      $flId           = (int) $fl->id;
      $pendingMembers = $g['members'];
      $pendingCount   = count($pendingMembers);
      $approvedCount  = (int) ($g['approved_count'] ?? 0);
    ?>
    <div class="card">
      <div class="card-head">
        <a href="<?= $b ?>/admin/members/<?= $flId ?>" class="link"><?= View::e($fl->name) ?></a>
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
            <th>URL</th>
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
            <td class="text-strong" data-label="Name"><?= View::e($m->name) ?></td>
            <td data-label="Email"><?= View::e($m->email) ?></td>
            <td data-label="Country"><?= View::e($m->country) ?></td>
            <td data-label="URL">
              <?php if ($m->url !== ''): ?>
                <a href="<?= View::e($m->url) ?>" target="_blank" rel="noopener noreferrer"
                   class="link link-break"><?= View::e($m->url) ?></a>
              <?php else: ?>
                <span class="text-dim">&mdash;</span>
              <?php endif; ?>
            </td>
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

<!-- ──── PENDING INFO UPDATES ───────────────────────────────────────── -->
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
                <th class="cb-col">
                  <input type="checkbox" class="sel-all" data-fid="pu" title="Select all">
                </th>
                <th>Listing</th>
                <th>Member Email</th>
                <th>Fields Changed</th>
                <th>Submitted</th>
                <th class="act-col">Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($pending_updates as $pu):
                $puListing = $fl_by_id[(int) $pu->fanlisting_id] ?? null;
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
                    <a href="<?= $b ?>/admin/members/<?= $puFlId ?>" class="link">
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
                <td data-label="Submitted" class="cell-meta-nowrap"
                    title="<?= View::e($pu->submitted_at) ?>">
                  <?= View::e($pu->submitted_at) ?>
                </td>
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
<?php endif; ?>