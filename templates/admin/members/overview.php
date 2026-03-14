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
?>

<?php if (($success ?? '') !== ''): ?>
  <div class="alert alert-success"><?= \Luster\Core\View::e($success) ?></div>
<?php endif; ?>
<?php if (($error ?? '') !== ''): ?>
  <div class="alert alert-error"><?= \Luster\Core\View::e($error) ?></div>
<?php endif; ?>

<div class="neb-toolbar">
  <div class="neb-filter-row">
    <span style="font-weight:600;color:var(--text)">Manage:</span>
    <div class="select-wrap">
      <select class="form-select" onchange="location=this.value">
        <option value="<?= $b ?>/admin/members">All pending members</option>
        <?php foreach ($all_listings as $fl): ?>
          <option value="<?= $b ?>/admin/members/<?= (int) $fl->id ?>?status=all">
            <?= \Luster\Core\View::e($fl->name) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <form method="GET" action="<?= $b ?>/admin/members" style="display:flex;gap:.5rem;align-items:center">
      <input type="text" name="search" value="<?= \Luster\Core\View::e($search ?? '') ?>"
             placeholder="Search all listings&hellip;" class="form-input" style="max-width:240px">
      <button type="submit" class="btn btn-primary btn-sm">Search</button>
      <?php if ($isSearch): ?>
        <a href="<?= $b ?>/admin/members" class="btn btn-secondary btn-sm">Clear</a>
      <?php endif; ?>
    </form>
  </div>
  <div class="neb-actions">
    <a href="<?= $b ?>/admin/members/export-pending" class="btn btn-ghost">&#8595; Export CSV</a>
    <a href="<?= $b ?>/admin/members/settings" class="btn btn-ghost">Settings</a>
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
      <p class="text-muted" style="padding:1.25rem;font-size:.875rem">No members found matching that search.</p>
    </div>
  <?php else: ?>
    <div style="overflow-x:auto">
      <table class="neb-table">
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
            <td>
              <a href="<?= $b ?>/admin/members/<?= $flId ?>" class="neb-link">
                <?= \Luster\Core\View::e($fl->name) ?>
              </a>
            </td>
            <td class="text-strong"><?= \Luster\Core\View::e($m->name) ?></td>
            <td><?= \Luster\Core\View::e($m->email) ?></td>
            <td><?= \Luster\Core\View::e($m->country) ?></td>
            <td>
              <?php if ($m->approved): ?>
                <span class="badge badge-success">Approved</span>
              <?php else: ?>
                <span class="badge badge-warning">Pending</span>
              <?php endif; ?>
            </td>
            <td style="white-space:nowrap;font-size:.8rem;color:var(--text-muted)">
              <?= \Luster\Core\View::e($m->added ?? '') ?>
            </td>
            <td class="act-col" style="white-space:nowrap">
              <a href="<?= $b ?>/admin/members/<?= $flId ?>/edit?email=<?= urlencode($m->email) ?>"
                 class="btn btn-secondary btn-sm">Edit</a>
              <?php if (!$m->approved): ?>
                <form method="POST" action="<?= $b ?>/admin/members/<?= $flId ?>/approve"
                      style="display:inline;margin-left:.25rem">
                  <input type="hidden" name="_csrf"        value="<?= \Luster\Core\View::e($csrf_token ?? '') ?>">
                  <input type="hidden" name="member_email" value="<?= \Luster\Core\View::e($m->email) ?>">
                  <button type="submit" class="btn btn-primary btn-sm">Approve</button>
                </form>
              <?php endif; ?>
              <a href="<?= $b ?>/admin/members/<?= $flId ?>/emailmember?email=<?= urlencode($m->email) ?>"
                 class="btn btn-ghost btn-sm" style="margin-left:.25rem">Email</a>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>

<?php else: ?>

  <!-- ═══ Pending new members by listing ═══ -->
  <h2 class="section-title" style="font-size:.8rem;font-weight:700;letter-spacing:.07em;text-transform:uppercase;color:var(--text-muted);margin-bottom:.75rem">
    Pending New Members
  </h2>
  <?php if (empty($grouped)): ?>
    <div class="neb-card" style="margin-bottom:1.5rem">
      <p class="text-muted" style="padding:1.25rem;font-size:.875rem">No pending members across any listing.</p>
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
    <div class="neb-card" style="margin-bottom:1.25rem;overflow:hidden">
      <div class="neb-card-header">
        <a href="<?= $b ?>/admin/members/<?= $flId ?>" class="neb-link"><?= \Luster\Core\View::e($fl->name) ?></a>
        <span class="text-dim" style="font-weight:400;text-transform:none;letter-spacing:0;margin-left:.25rem">
          &mdash; <?= $pendingCount ?> pending<?= $approvedCount > 0 ? ', ' . $approvedCount . ' approved' : '' ?>
        </span>
      </div>
      <form method="POST" action="<?= $b ?>/admin/members/<?= $flId ?>/bulk">
      <input type="hidden" name="_csrf" value="<?= \Luster\Core\View::e($csrf_token ?? '') ?>">
      <input type="hidden" name="from" value="overview">
      <table class="data-table">
        <thead>
          <tr>
            <th class="cb-col">
              <input type="checkbox" class="sel-all" data-fid="<?= $flId ?>" title="Select all">
            </th>
            <th>Name</th>
            <th>Email</th>
            <th>Country</th>
            <th>URL</th>
            <th class="act-col">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($pendingMembers as $m): ?>
          <tr>
            <td class="cb-col">
              <input type="checkbox" name="emails[]" class="row-cb-<?= $flId ?>"
                     value="<?= \Luster\Core\View::e($m->email) ?>">
            </td>
            <td class="text-strong"><?= \Luster\Core\View::e($m->name) ?></td>
            <td><?= \Luster\Core\View::e($m->email) ?></td>
            <td><?= \Luster\Core\View::e($m->country) ?></td>
            <td>
              <?php if ($m->url !== ''): ?>
                <a href="<?= \Luster\Core\View::e($m->url) ?>" target="_blank" rel="noopener noreferrer"
                   class="neb-link" style="font-size:.8rem"><?= \Luster\Core\View::e($m->url) ?></a>
              <?php else: ?>
                <span class="text-dim">&mdash;</span>
              <?php endif; ?>
            </td>
            <td class="act-col">
              <form method="POST" action="<?= $b ?>/admin/members/<?= $flId ?>/approve"
                    style="display:inline">
                <input type="hidden" name="_csrf"        value="<?= \Luster\Core\View::e($csrf_token ?? '') ?>">
                <input type="hidden" name="member_email" value="<?= \Luster\Core\View::e($m->email) ?>">
                <button type="submit" class="btn btn-primary btn-sm">Approve</button>
              </form>
              <form method="POST" action="<?= $b ?>/admin/members/<?= $flId ?>/delete"
                    style="display:inline;margin-left:.25rem">
                <input type="hidden" name="_csrf"        value="<?= \Luster\Core\View::e($csrf_token ?? '') ?>">
                <input type="hidden" name="member_email" value="<?= \Luster\Core\View::e($m->email) ?>">
                <button type="submit" class="btn btn-danger btn-sm"
                        onclick="return confirm('Reject this member?')">Reject</button>
              </form>
              <a href="<?= $b ?>/admin/members/<?= $flId ?>/emailmember?email=<?= urlencode($m->email) ?>"
                 class="btn btn-secondary btn-sm" style="margin-left:.25rem">Email</a>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <div class="neb-bulk">
        <span style="font-weight:600;margin-right:.5rem;color:var(--text)">Bulk:</span>
        <div class="select-wrap">
          <select name="bulk_action" class="form-select" style="padding:.3rem .6rem;font-size:.8125rem">
            <option value="approve">Approve</option>
            <option value="delete">Delete</option>
          </select>
        </div>
        <button type="submit" class="btn btn-ghost btn-sm" onclick="return confirmBulk()">Apply</button>
      </div>
      </form>
    </div>
    <?php endforeach; ?>
  <?php endif; ?>

  <!-- ═══ Pending info updates ═══ -->
  <h2 class="section-title" style="font-size:.8rem;font-weight:700;letter-spacing:.07em;text-transform:uppercase;color:var(--text-muted);margin-top:2rem;margin-bottom:.75rem">
    Pending Info Updates
  </h2>
  <?php if (empty($pending_updates)): ?>
    <div class="neb-card">
      <p class="text-muted" style="padding:1.25rem;font-size:.875rem">No pending info updates.</p>
    </div>
  <?php else: ?>
    <div class="neb-card" style="overflow:hidden">
      <div style="overflow-x:auto">
        <table class="data-table">
          <thead>
            <tr>
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
              <td>
                <?php if ($puListing !== null): ?>
                  <a href="<?= $b ?>/admin/members/<?= $puFlId ?>" class="neb-link">
                    <?= \Luster\Core\View::e($puListing->name) ?>
                  </a>
                <?php else: ?>
                  <span class="text-dim">#<?= $puFlId ?></span>
                <?php endif; ?>
              </td>
              <td><?= \Luster\Core\View::e($pu->member_email) ?></td>
              <td>
                <?php if ($puCount === 0): ?>
                  <span class="text-dim">—</span>
                <?php else: ?>
                  <details style="cursor:pointer">
                    <summary style="list-style:none;display:inline-flex;align-items:center;gap:.35rem">
                      <span class="badge badge-warning"><?= $puCount ?> field<?= $puCount !== 1 ? 's' : '' ?></span>
                    </summary>
                    <dl style="margin:.4rem 0 0;font-size:.78rem;line-height:1.6">
                      <?php foreach ($pu->changes as $field => $val): ?>
                        <dt style="font-weight:600;color:var(--text-muted)"><?= \Luster\Core\View::e((string) $field) ?></dt>
                        <dd style="margin:0 0 .2rem 1rem;word-break:break-all"><?= \Luster\Core\View::e((string) $val) ?></dd>
                      <?php endforeach; ?>
                    </dl>
                  </details>
                <?php endif; ?>
              </td>
              <td style="white-space:nowrap;font-size:.8rem;color:var(--text-muted)"
                  title="<?= \Luster\Core\View::e($pu->submitted_at) ?>">
                <?= \Luster\Core\View::e($pu->submitted_at) ?>
              </td>
              <td class="act-col" style="white-space:nowrap">
                <form method="POST"
                      action="<?= $b ?>/admin/pending-updates/<?= (int) $pu->id ?>/approve"
                      style="display:inline;margin-left:.25rem">
                  <input type="hidden" name="_csrf"  value="<?= \Luster\Core\View::e($csrf_token ?? '') ?>">
                  <input type="hidden" name="_from"  value="members">
                  <button type="submit" class="btn btn-primary btn-sm"
                          onclick="return confirm('Approve and apply this update?')">Approve</button>
                </form>
                <form method="POST"
                      action="<?= $b ?>/admin/pending-updates/<?= (int) $pu->id ?>/reject"
                      style="display:inline;margin-left:.25rem">
                  <input type="hidden" name="_csrf"  value="<?= \Luster\Core\View::e($csrf_token ?? '') ?>">
                  <input type="hidden" name="_from"  value="members">
                  <button type="submit" class="btn btn-danger btn-sm"
                          onclick="return confirm('Reject and discard this update?')">Reject</button>
                </form>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  <?php endif; ?>

<?php endif; ?>

<script>
document.querySelectorAll('.sel-all').forEach(function (selAll) {
  selAll.addEventListener('change', function () {
    var fid = this.dataset.fid;
    document.querySelectorAll('.row-cb-' + fid).forEach(function (cb) {
      cb.checked = selAll.checked;
    });
  });
});

window.confirmBulk = function () {
  var action = event.target.closest('form').querySelector('select[name="bulk_action"]').value;
  var checked = event.target.closest('form').querySelectorAll('input[name="emails[]"]:checked');
  if (!checked.length) { alert('No members selected.'); return false; }
  if (action === 'delete') {
    return confirm('Delete ' + checked.length + ' selected member(s)? This cannot be undone.');
  }
  return true;
};
</script>
