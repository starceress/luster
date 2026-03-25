<?php

declare(strict_types=1);

/** @var array                       $grouped          [{listing: Listing, members: Member[]}] */
/** @var array                       $search_results   [{listing: Listing, member: Member}] */
/** @var array                       $pending_updates  pending update objects */
/** @var array                       $fl_by_id         listing id → Listing */
/** @var \Luster\Models\Listing[]    $all_listings */
/** @var string                      $search */
/** @var string                      $success */
/** @var string                      $error */
/** @var string                      $base_url */
/** @var string                      $csrf_token */

$b          = rtrim(\Luster\Core\View::e($base_url), '/');
$isSearch   = $search !== '';
$sort       = $sort ?? 'name';
$dir        = $dir  ?? 'asc';

$pendingSortUrl = static function (string $col) use ($b, $sort, $dir): string {
    $newDir = ($sort === $col && $dir === 'asc') ? 'desc' : 'asc';
    return $b . '/admin/members?' . http_build_query(['sort' => $col, 'dir' => $newDir]);
};

$pendingSortIcon = static function (string $col) use ($sort, $dir): string {
    if ($sort !== $col) {
        return '<svg viewBox="0 0 10 14" width="9" height="9" fill="currentColor" style="opacity:.35;flex-shrink:0"><path d="M5 0l3 4H2zM5 14l-3-4h6z"/></svg>';
    }
    if ($dir === 'asc') {
        return '<svg viewBox="0 0 10 14" width="9" height="9" fill="currentColor" style="flex-shrink:0"><path d="M5 0l3 4H2z"/><path d="M5 14l-3-4h6z" fill-opacity=".25"/></svg>';
    }
    return '<svg viewBox="0 0 10 14" width="9" height="9" fill="currentColor" style="flex-shrink:0"><path d="M5 0l3 4H2z" fill-opacity=".25"/><path d="M5 14l-3-4h6z"/></svg>';
};
?>

<?php if (($success ?? '') !== ''): ?>
  <div class="alert alert-success"><?= \Luster\Core\View::e($success) ?></div>
<?php endif; ?>
<?php if (($error ?? '') !== ''): ?>
  <div class="alert alert-error"><?= \Luster\Core\View::e($error) ?></div>
<?php endif; ?>

<div class="neb-toolbar">
  <form method="GET" action="<?= $b ?>/admin/members" class="neb-filter-row">
    <div class="toolbar-select-wrap">
      <select class="neb-select js-nav-select">
        <option value="<?= $b ?>/admin/members">All pending members</option>
        <?php foreach ($all_listings as $fl): ?>
          <option value="<?= $b ?>/admin/members/<?= (int) $fl->id ?>?status=all">
            <?= \Luster\Core\View::e($fl->name) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <input type="text" name="search" value="<?= \Luster\Core\View::e($search ?? '') ?>"
           placeholder="Search all listings&hellip;" class="neb-input" style="max-width:240px">
    <button type="submit" class="btn btn-tertiary btn-sm">Search</button>
    <?php if ($isSearch): ?>
      <a href="<?= $b ?>/admin/members" class="btn btn-tertiary btn-sm">Clear</a>
    <?php endif; ?>
  </form>
  <div class="neb-actions">
    <a href="<?= $b ?>/admin/members/export-pending" class="btn btn-tertiary btn-sm">&#8595; Export CSV</a>
    <a href="<?= $b ?>/admin/emails?tab=bulk" class="btn btn-tertiary btn-sm">Bulk Email</a>
    <a href="<?= $b ?>/admin/members/settings" class="btn btn-tertiary btn-sm">Display Settings</a>
  </div>
</div>

<?php if ($isSearch): ?>

  <!-- ═══ Global search results ═══ -->
  <?php $total = count($search_results); ?>
  <p class="text-muted" style="font-size:.8rem;margin-bottom:.75rem">
    <?= $total ?> result<?= $total !== 1 ? 's' : '' ?> for
    &ldquo;<?= \Luster\Core\View::e($search) ?>&rdquo; across all listings
  </p>

  <?php if ($total === 0): ?>
    <div class="neb-card">
      <div class="neb-empty">
        <svg class="empty-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
        No members found matching that search.
        <a href="<?= $b ?>/admin/members" class="btn btn-tertiary btn-sm">Clear Search</a>
      </div>
    </div>
  <?php else: ?>
    <div class="neb-card" style="overflow-x:auto">
      <div class="neb-table-wrap">
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
              <a href="<?= $b ?>/admin/members/<?= $flId ?>" class="neb-link">
                <?= \Luster\Core\View::e($fl->name) ?>
              </a>
            </td>
            <td class="text-strong" data-label="Name"><?= \Luster\Core\View::e($m->name) ?></td>
            <td class="td-mono" data-label="Email"><?= \Luster\Core\View::e($m->email) ?></td>
            <td data-label="Country"><?= \Luster\Core\View::e($m->country) ?></td>
            <td data-label="Status">
              <?php if ($m->approved): ?>
                <span class="neb-badge badge-current">Approved</span>
              <?php else: ?>
                <span class="neb-badge badge-upcoming">Pending</span>
              <?php endif; ?>
            </td>
            <td class="td-mono" data-label="Joined" style="white-space:nowrap;color:var(--text-muted)">
              <?= \Luster\Core\View::e(($m->added ?? '') !== '' ? date($admin_date_format, strtotime($m->added)) : '') ?>
            </td>
            <td class="act-col" data-label="">
              <div class="row-actions">
                <?php if (!$m->approved): ?>
                  <form method="POST" action="<?= $b ?>/admin/members/<?= $flId ?>/approve" style="display:contents">
                    <input type="hidden" name="_csrf"        value="<?= \Luster\Core\View::e($csrf_token ?? '') ?>">
                    <input type="hidden" name="member_email" value="<?= \Luster\Core\View::e($m->email) ?>">
                    <button type="submit" class="btn btn-success btn-sm">Approve</button>
                  </form>
                <?php endif; ?>
                <a href="<?= $b ?>/admin/members/<?= $flId ?>/edit?email=<?= urlencode($m->email) ?>"
                   class="btn btn-primary btn-sm">Edit</a>
                <a href="<?= $b ?>/admin/members/<?= $flId ?>/emailmember?email=<?= urlencode($m->email) ?>"
                   class="btn btn-tertiary btn-sm">Email</a>
              </div>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      </div>
    </div><!-- /.neb-card -->
  <?php endif; ?>

<?php else: ?>

  <!-- ═══ Pending new members by listing ═══ -->
  <div class="overview-section-head">
    <div>
      <div class="overview-section-title">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" width="15" height="15"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M19 8v6m3-3h-6"/></svg>
        Pending New Members
      </div>
      <div class="overview-section-sub">New join requests awaiting your approval decision.</div>
    </div>
  </div>
  <?php if (empty($grouped)): ?>
    <div class="neb-card">
      <div class="neb-empty">
        <svg class="empty-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
        No pending members — you're all caught up.
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
    <div class="neb-card">
      <div class="neb-card-header">
        <a href="<?= $b ?>/admin/members/<?= $flId ?>" class="neb-link"><?= \Luster\Core\View::e($fl->name) ?></a>
        <span class="text-dim">
          &mdash; <?= $pendingCount ?> pending<?= $approvedCount > 0 ? ', ' . $approvedCount . ' approved' : '' ?>
        </span>
      </div>
      <form method="POST" action="<?= $b ?>/admin/members/<?= $flId ?>/bulk"
            data-del-bulk data-del-entity="member_reject">
      <input type="hidden" name="_csrf" value="<?= \Luster\Core\View::e($csrf_token ?? '') ?>">
      <input type="hidden" name="from" value="overview">
      <div class="neb-bulk" style="margin:0 0 0 0;border-left:none;border-right:none;border-top:none;border-radius:0;border-bottom:1px solid var(--border)">
        <span style="font-weight:600;margin-right:.25rem;color:var(--text)">Bulk:</span>
        <div class="select-wrap">
          <select name="bulk_action" class="form-select" style="padding:.3rem 2rem .3rem .6rem;font-size:.8125rem">
            <option value="approve">Approve</option>
            <option value="delete">Reject</option>
          </select>
        </div>
        <button type="submit" class="btn btn-tertiary btn-sm">Apply</button>
      </div>
      <div class="neb-table-wrap">
      <table class="data-table mem-table">
        <thead>
          <tr>
            <th class="cb-col">
              <input type="checkbox" class="sel-all" data-fid="<?= $flId ?>" title="Select all">
            </th>
            <th><a href="<?= \Luster\Core\View::e($pendingSortUrl('name')) ?>" class="sort-th<?= $sort === 'name' ? ' sort-active' : '' ?>">Name <?= $pendingSortIcon('name') ?></a></th>
            <th><a href="<?= \Luster\Core\View::e($pendingSortUrl('email')) ?>" class="sort-th<?= $sort === 'email' ? ' sort-active' : '' ?>">Email <?= $pendingSortIcon('email') ?></a></th>
            <th><a href="<?= \Luster\Core\View::e($pendingSortUrl('country')) ?>" class="sort-th<?= $sort === 'country' ? ' sort-active' : '' ?>">Country <?= $pendingSortIcon('country') ?></a></th>
            <th>URL</th>
            <th class="act-col">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($pendingMembers as $m): ?>
          <tr>
            <td class="cb-col" data-label="">
              <input type="checkbox" name="emails[]" class="row-cb-<?= $flId ?>"
                     value="<?= \Luster\Core\View::e($m->email) ?>"
                     data-name="<?= \Luster\Core\View::e($m->name) ?>">
            </td>
            <td class="text-strong" data-label="Name"><?= \Luster\Core\View::e($m->name) ?></td>
            <td data-label="Email"><?= \Luster\Core\View::e($m->email) ?></td>
            <td data-label="Country"><?= \Luster\Core\View::e($m->country) ?></td>
            <td data-label="URL">
              <?php if ($m->url !== ''): ?>
                <a href="<?= \Luster\Core\View::e($m->url) ?>" target="_blank" rel="noopener noreferrer"
                   class="neb-link" style="word-break:break-all"><?= \Luster\Core\View::e($m->url) ?></a>
              <?php else: ?>
                <span class="text-dim">&mdash;</span>
              <?php endif; ?>
            </td>
            <td class="act-col" data-label="">
              <div class="row-actions">
                <form method="POST" action="<?= $b ?>/admin/members/<?= $flId ?>/approve" style="display:contents">
                  <input type="hidden" name="_csrf"        value="<?= \Luster\Core\View::e($csrf_token ?? '') ?>">
                  <input type="hidden" name="member_email" value="<?= \Luster\Core\View::e($m->email) ?>">
                  <button type="submit" class="btn btn-success btn-sm">Approve</button>
                </form>
                <a href="<?= $b ?>/admin/members/<?= $flId ?>/edit?email=<?= urlencode($m->email) ?>"
                   class="btn btn-primary btn-sm">Edit</a>
                <a href="<?= $b ?>/admin/members/<?= $flId ?>/emailmember?email=<?= urlencode($m->email) ?>"
                   class="btn btn-tertiary btn-sm">Email</a>
                <button type="submit" form="del-rej-<?= md5($m->email . $flId) ?>" class="btn btn-danger btn-sm">Reject</button>
              </div>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      </div>
      </form>
      <?php foreach ($pendingMembers as $m): ?>
      <form id="del-rej-<?= md5($m->email . $flId) ?>" method="POST"
            action="<?= $b ?>/admin/members/<?= $flId ?>/delete"
            style="display:none"
            data-del-modal data-del-entity="member_reject"
            data-del-name="<?= \Luster\Core\View::e($m->name) ?>">
        <input type="hidden" name="_csrf"        value="<?= \Luster\Core\View::e($csrf_token ?? '') ?>">
        <input type="hidden" name="member_email" value="<?= \Luster\Core\View::e($m->email) ?>">
      </form>
      <?php endforeach; ?>
    </div>
    <?php endforeach; ?>
  <?php endif; ?>

  <!-- ═══ Pending info updates ═══ -->
  <div class="overview-section-divider"></div>
  <div class="overview-section-head">
    <div>
      <div class="overview-section-title">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" width="15" height="15"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
        Pending Info Updates
      </div>
      <div class="overview-section-sub">Members requesting changes to their details — review each diff and accept or reject.</div>
    </div>
  </div>
  <?php if (empty($pending_updates)): ?>
    <div class="neb-card">
      <div class="neb-empty">
        <svg class="empty-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
        No pending info updates.
      </div>
    </div>
  <?php else: ?>
    <div class="neb-card" style="overflow:hidden">
      <form method="POST" action="<?= $b ?>/admin/pending-updates/bulk"
            data-del-bulk data-del-entity="pending_update_reject">
        <input type="hidden" name="_csrf" value="<?= \Luster\Core\View::e($csrf_token ?? '') ?>">
        <div class="neb-bulk" style="margin:0;border-left:none;border-right:none;border-top:none;border-radius:0;border-bottom:1px solid var(--border)">
          <span style="font-weight:600;margin-right:.25rem;color:var(--text)">Bulk:</span>
          <div class="select-wrap">
            <select name="bulk_action" class="form-select" style="padding:.3rem 2rem .3rem .6rem;font-size:.8125rem">
              <option value="approve">Approve</option>
              <option value="reject">Reject</option>
            </select>
          </div>
          <button type="submit" class="btn btn-tertiary btn-sm">Apply</button>
        </div>
        <div style="overflow-x:auto">
          <div class="neb-table-wrap">
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
                         data-name="<?= \Luster\Core\View::e($pu->member_email) ?>">
                </td>
                <td data-label="Listing">
                  <?php if ($puListing !== null): ?>
                    <a href="<?= $b ?>/admin/members/<?= $puFlId ?>" class="neb-link">
                      <?= \Luster\Core\View::e($puListing->name) ?>
                    </a>
                  <?php else: ?>
                    <span class="text-dim">#<?= $puFlId ?></span>
                  <?php endif; ?>
                </td>
                <td data-label="Email"><?= \Luster\Core\View::e($pu->member_email) ?></td>
                <td data-label="Changes">
                  <?php if ($puCount === 0): ?>
                    <span class="text-dim">—</span>
                  <?php else: ?>
                    <details style="cursor:pointer">
                      <summary style="list-style:none;display:inline-flex;align-items:center;gap:.3rem;font-size:.85rem;color:var(--text-muted)">
                        <?= $puCount ?> field<?= $puCount !== 1 ? 's' : '' ?>
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" width="11" height="11" style="flex-shrink:0;transition:transform .15s"><polyline points="6 9 12 15 18 9"/></svg>
                      </summary>
                      <div style="margin:.4rem 0 0;font-size:.78rem;line-height:1.8">
                        <?php foreach ($pu->changes as $field => $val): ?>
                          <div style="word-break:break-all">
                            <span style="font-weight:600;color:var(--text-muted)"><?= \Luster\Core\View::e(ucfirst((string) $field)) ?>:</span>
                            <?= \Luster\Core\View::e((string) $val) ?>
                          </div>
                        <?php endforeach; ?>
                      </div>
                    </details>
                  <?php endif; ?>
                </td>
                <td data-label="Submitted" style="white-space:nowrap;font-size:.8rem;color:var(--text-muted)"
                    title="<?= \Luster\Core\View::e($pu->submitted_at) ?>">
                  <?= \Luster\Core\View::e($pu->submitted_at) ?>
                </td>
                <td class="act-col" data-label="" style="white-space:nowrap">
                  <div class="row-actions">
                    <form method="POST"
                          action="<?= $b ?>/admin/pending-updates/<?= (int) $pu->id ?>/approve"
                          style="display:contents"
                          data-confirm="Approve and apply this update?">
                      <input type="hidden" name="_csrf"  value="<?= \Luster\Core\View::e($csrf_token ?? '') ?>">
                      <input type="hidden" name="_from"  value="members">
                      <button type="submit" class="btn btn-success btn-sm">Approve</button>
                    </form>
                    <a href="<?= $b ?>/admin/members/<?= $puFlId ?>/edit?email=<?= urlencode($pu->member_email) ?>"
                       class="btn btn-tertiary btn-sm">Edit</a>
                    <button type="submit" form="del-pu-<?= (int) $pu->id ?>" class="btn btn-danger btn-sm">Reject</button>
                  </div>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
          </div>
        </div>
      </form>
      <?php foreach ($pending_updates as $pu): ?>
      <form id="del-pu-<?= (int) $pu->id ?>" method="POST"
            action="<?= $b ?>/admin/pending-updates/<?= (int) $pu->id ?>/reject"
            style="display:none"
            data-del-modal data-del-entity="pending_update_reject"
            data-del-name="<?= \Luster\Core\View::e($pu->member_email) ?>">
        <input type="hidden" name="_csrf" value="<?= \Luster\Core\View::e($csrf_token ?? '') ?>">
        <input type="hidden" name="_from" value="members">
      </form>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

<?php endif; ?>

