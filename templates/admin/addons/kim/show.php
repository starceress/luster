<?php
/**
 * KIM List admin - show members for one fanlisting.
 *
 * Variables:
 *   $listing         Listing
 *   $members         KimMember[]
 *   $pending_count   int
 *   $approved_count  int
 *   $base_url        string
 *   $csrf_token      string
 *   $success         string
 *   $error           string
 */

declare(strict_types=1);

use Luster\Core\View;

$b   = rtrim(View::e($base_url), '/');
$fid = (int) $listing->id;

$pendingMembers  = array_values(array_filter($members, static fn($m) =>  $m->pending));
$approvedMembers = array_values(array_filter($members, static fn($m) => !$m->pending));
?>

<?php if ($success !== ''): ?>
  <div class="alert alert-success"><?= View::e($success) ?></div>
<?php endif; ?>
<?php if ($error !== ''): ?>
  <div class="alert alert-error"><?= View::e($error) ?></div>
<?php endif; ?>

<div class="page-topbar">
  <a href="<?= $b ?>/admin/kim" class="link">&larr; Back to KIM List</a>
  <?php if ($approved_count > 0): ?>
  <div class="topbar-actions">
    <a href="<?= $b ?>/admin/kim/<?= $fid ?>/mass-email"
       class="btn btn-quaternary btn-sm">Bulk Email All (<?= (int) $approved_count ?>)</a>
  </div>
  <?php endif; ?>
</div>

<!-- â”€â”€ Pending Sign-Ups â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ -->

<div class="overview-section-head">
    <div class="overview-section-title">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" width="15" height="15"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M19 8v6m3-3h-6"/></svg>
      Pending Sign-Ups
    </div>
    <div class="overview-section-sub">New sign-up requests awaiting your approval decision.</div>
</div>

<?php if (empty($pendingMembers)): ?>
  <div class="card">
    <div class="empty">
      <?= View::icon('check-badge', ['width' => '40', 'height' => '40']) ?>
      No pending sign-ups - all caught up.
    </div>
  </div>
<?php else: ?>
  <div class="card">
    <div class="card-head">
      <span><?= View::e($listing->subject) ?></span>
      <span class="text-dim">&mdash; <?= (int) $pending_count ?> pending</span>
    </div>
    <form method="POST" action="<?= $b ?>/admin/kim/<?= $fid ?>/bulk"
          data-del-bulk data-del-entity="kim_member"
          data-del-context="<?= View::e($listing->subject) ?>">
      <input type="hidden" name="_csrf" value="<?= View::e($csrf_token) ?>">
      <div class="bulk bulk--flush">
        Bulk Actions:
        <div class="select-wrap">
          <select name="bulk_action" class="select-xs">
            <option value="" disabled selected>&mdash; Select &mdash;</option>
            <option value="approve">Approve</option>
            <option value="email_selected">Email</option>
            <option value="delete">Delete</option>
          </select>
        </div>
        <button type="submit" class="btn btn-quaternary btn-sm">Apply</button>
      </div>
      <div class="table-wrap">
      <table class="data-table mem-table">
        <thead>
          <tr>
            <th class="cb-col"><input type="checkbox" class="sel-all" data-fid="kim-p-<?= $fid ?>"></th>
            <th>Name</th>
            <th>Email</th>
            <th>URL</th>
            <th>Date</th>
            <th class="act-col">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($pendingMembers as $m): ?>
          <tr>
            <td class="cb-col">
              <input type="checkbox" class="row-cb-kim-p-<?= $fid ?>" name="ids[]" value="<?= (int) $m->id ?>">
            </td>
            <td class="text-strong" data-label="Name"><?= View::e($m->name) ?></td>
            <td class="text-muted" data-label="Email"><?= View::e($m->email) ?></td>
            <td data-label="URL">
              <?php if ($m->url !== ''): ?>
                <a href="<?= View::e($m->url) ?>" target="_blank" rel="noopener noreferrer"
                   class="link link-break"><?= View::e($m->url) ?></a>
              <?php else: ?>
                <span class="text-dim">&mdash;</span>
              <?php endif; ?>
            </td>
            <td class="text-dim nowrap" data-label="Date"><?= View::e(date($admin_date_format, strtotime($m->addedAt))) ?></td>
            <td class="act-col" data-label="">
              <div class="data-actions">
                <button type="submit" form="approve-kim-<?= (int) $m->id ?>" class="btn btn-success btn-sm">Approve</button>
                <a href="<?= $b ?>/admin/kim/<?= (int) $m->id ?>/edit" class="btn btn-tertiary btn-sm">Edit</a>
                <a href="<?= $b ?>/admin/kim/<?= (int) $m->id ?>/email-member" class="btn btn-quaternary btn-sm">Email</a>
                <button type="submit" form="del-kim-<?= (int) $m->id ?>" class="btn btn-danger btn-sm">Delete</button>
              </div>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      </div>
    </form>
  </div>
<?php foreach ($pendingMembers as $m): ?>
<form id="approve-kim-<?= (int) $m->id ?>" method="POST"
      action="<?= $b ?>/admin/kim/<?= (int) $m->id ?>/approve" class="action-form-inline">
  <input type="hidden" name="_csrf"    value="<?= View::e($csrf_token) ?>">
  <input type="hidden" name="from_fid" value="<?= $fid ?>">
</form>
<form id="del-kim-<?= (int) $m->id ?>" method="POST"
      action="<?= $b ?>/admin/kim/<?= (int) $m->id ?>/delete"
      data-del-modal data-del-entity="kim_member"
      data-del-name="<?= View::e($m->name) ?>">
  <input type="hidden" name="_csrf" value="<?= View::e($csrf_token) ?>">
</form>
<?php endforeach; ?>
<?php endif; ?>

<!-- â”€â”€ Approved Sign-Ups â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ -->

<div class="overview-section-divider"></div>
<div class="overview-section-head">
    <div class="overview-section-title">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" width="15" height="15"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
      Approved Sign-Ups
    </div>
    <div class="overview-section-sub">Members who have been approved and are on the list.</div>
</div>

<?php if (empty($approvedMembers)): ?>
  <div class="card">
    <div class="empty">
      <?= View::icon('check-badge', ['width' => '40', 'height' => '40']) ?>
      No approved sign-ups yet.
    </div>
  </div>
<?php else: ?>
  <div class="card">
    <div class="card-head">
      <span><?= View::e($listing->subject) ?></span>
      <span class="text-dim">&mdash; <?= (int) $approved_count ?> approved</span>
    </div>
    <form method="POST" action="<?= $b ?>/admin/kim/<?= $fid ?>/bulk"
          data-del-bulk data-del-entity="kim_member"
          data-del-context="<?= View::e($listing->subject) ?>">
      <input type="hidden" name="_csrf" value="<?= View::e($csrf_token) ?>">
      <div class="bulk bulk--flush">
        Bulk Actions:
        <div class="select-wrap">
          <select name="bulk_action" class="select-xs">
            <option value="" disabled selected>&mdash; Select &mdash;</option>
            <option value="email_selected">Email</option>
            <option value="delete">Delete</option>
          </select>
        </div>
        <button type="submit" class="btn btn-quaternary btn-sm">Apply</button>
      </div>
      <div class="table-wrap">
      <table class="data-table mem-table">
        <thead>
          <tr>
            <th class="cb-col"><input type="checkbox" class="sel-all" data-fid="kim-a-<?= $fid ?>"></th>
            <th>Name</th>
            <th>Email</th>
            <th>URL</th>
            <th>Date</th>
            <th class="act-col">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($approvedMembers as $m): ?>
          <tr>
            <td class="cb-col">
              <input type="checkbox" class="row-cb-kim-a-<?= $fid ?>" name="ids[]" value="<?= (int) $m->id ?>">
            </td>
            <td class="text-strong" data-label="Name"><?= View::e($m->name) ?></td>
            <td class="text-muted" data-label="Email"><?= View::e($m->email) ?></td>
            <td data-label="URL">
              <?php if ($m->url !== ''): ?>
                <a href="<?= View::e($m->url) ?>" target="_blank" rel="noopener noreferrer"
                   class="link link-break"><?= View::e($m->url) ?></a>
              <?php else: ?>
                <span class="text-dim">&mdash;</span>
              <?php endif; ?>
            </td>
            <td class="text-dim nowrap" data-label="Date"><?= View::e(date($admin_date_format, strtotime($m->addedAt))) ?></td>
            <td class="act-col" data-label="">
              <div class="data-actions">
                <a href="<?= $b ?>/admin/kim/<?= (int) $m->id ?>/edit" class="btn btn-tertiary btn-sm">Edit</a>
                <a href="<?= $b ?>/admin/kim/<?= (int) $m->id ?>/email-member" class="btn btn-quaternary btn-sm">Email</a>
                <button type="submit" form="del-kim-<?= (int) $m->id ?>" class="btn btn-danger btn-sm">Delete</button>
              </div>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      </div>
    </form>
  </div>
<?php foreach ($approvedMembers as $m): ?>
<form id="del-kim-<?= (int) $m->id ?>" method="POST"
      action="<?= $b ?>/admin/kim/<?= (int) $m->id ?>/delete"
      data-del-modal data-del-entity="kim_member"
      data-del-name="<?= View::e($m->name) ?>">
  <input type="hidden" name="_csrf" value="<?= View::e($csrf_token) ?>">
</form>
<?php endforeach; ?>
<?php endif; ?>
