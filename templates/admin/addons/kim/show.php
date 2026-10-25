<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Keep In Mind (KIM) admin per-listing detail page. Shows pending and approved KIM
 members for a single listing with bulk approve, email, and delete actions for
 each section. Pending members appear first with individual review controls.
*/

/**
 * Variables:
 *   $listing           Listing
 *   $pending_members   KimMember[]
 *   $approved_members  KimMember[]
 *   $pending_count     int
 *   $approved_count    int
 *   $page              int
 *   $total_pages       int
 *   $page_url          callable
 *   $base_url          string
 *   $csrf_token        string
 *   $success           string
 *   $error             string
 */

declare(strict_types=1);

use Luster\Core\View;

$b   = rtrim(View::e($base_url), '/');
$fid = (int) $listing->id;
?>

<?php if ($success !== ''): ?>
  <div class="alert alert-success"><?= View::e($success) ?></div>
<?php endif; ?>
<?php if ($error !== ''): ?>
  <div class="alert alert-error"><?= View::e($error) ?></div>
<?php endif; ?>

<div class="toolbar">
  <a href="<?= $b ?>/admin/kim" class="link">&larr; Back to KIM List</a>
  <?php if ($approved_count > 0): ?>
  <div class="actions-row">
    <a href="<?= $b ?>/admin/kim/<?= $fid ?>/bulk-email"
       class="btn btn-primary btn-sm">Bulk Email All (<?= (int) $approved_count ?>)</a>
  </div>
  <?php endif; ?>
</div>
<!-- ──── PENDING SIGN-UPS ───────────────────────────────────────────── -->
<div class="overview-section-head">
  <div class="overview-section-title">
    <?= View::icon('pending') ?> Pending Sign-Ups
  </div>
  <div class="panel-header-sub">New sign-up requests awaiting your approval decision.</div>
</div>

<?php if (empty($pending_members)): ?>
  <div class="card empty">
    <?= View::icon('check-badge', ['class' => 'empty-icon', 'width' => 32, 'height' => 32]) ?>
    <div><strong>No pending sign-ups.</strong></div>
    <div>All caught up.</div>
  </div>
<?php else: ?>
  <div class="card">
    <div class="card-head">
      <span><?= View::e($listing->subject) ?></span>
     <div class="card-head-sub"><span class="badge badge-pending"><?= (int) $pending_count ?> pending</span></div>
    </div>
    <form method="POST" action="<?= $b ?>/admin/kim/<?= $fid ?>/bulk"
          data-del-bulk data-del-entity="kim_member"
          data-del-context="<?= View::e($listing->subject) ?>">
      <input type="hidden" name="_csrf" value="<?= View::e($csrf_token) ?>">
      <div class="bulk">
        Bulk Actions:
        <div class="select-wrap">
          <select name="bulk_action" class="select-xs">
            <option value="" disabled selected>&mdash; Select &mdash;</option>
            <option value="approve">Approve</option>
            <option value="email_selected">Email</option>
            <option value="delete">Delete</option>
          </select>
        </div>
        <button type="submit" class="btn btn-quaternary btn-sm">Apply to Selected</button>
      </div>


      <div class="table-wrap">
      <table class="data-table mem-table">
        <thead>
          <tr>
            <th class="checkbox-col"><input type="checkbox" class="sel-all" data-fid="kim-p-<?= $fid ?>"></th>
            <th>Name</th>
            <th>Email</th>
            <th>URL</th>
            <th>Date</th>
            <th class="actions-col">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($pending_members as $m): ?>
          <tr>
            <td class="checkbox-col">
              <input type="checkbox" class="row-cb-kim-p-<?= $fid ?>" name="ids[]" value="<?= (int) $m->id ?>">
            </td>
            <td class="text-strong" data-label="Name"><?= View::e($m->name) ?></td>
            <td class="text-muted" data-label="Email"><?= View::emailWrap($m->email) ?></td>
            <td data-label="URL">
              <?php if ($m->url !== ''): ?>
                <a href="<?= View::e($m->url) ?>" target="_blank" rel="noopener noreferrer"
                   class="link link-break"><?= View::e($m->url) ?></a>
              <?php else: ?>
                <span class="text-dim">&mdash;</span>
              <?php endif; ?>
            </td>
            <td class="text-dim nowrap" data-label="Date"><?= View::e(date($admin_date_format, strtotime($m->addedAt))) ?></td>
            <td class="actions-col" data-label="">
              <div class="actions-row">
                <button type="submit" form="approve-kim-<?= (int) $m->id ?>" class="btn btn-success btn-xs">Approve</button>
                <a href="<?= $b ?>/admin/kim/<?= (int) $m->id ?>/edit" class="btn btn-tertiary btn-xs">Edit</a>
                <a href="<?= $b ?>/admin/kim/<?= (int) $m->id ?>/email-member" class="btn btn-secondary btn-xs">Email</a>
                <button type="submit" form="del-kim-<?= (int) $m->id ?>" class="btn btn-danger btn-xs">Delete</button>
              </div>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      </div>
    </form>
  </div>
<?php foreach ($pending_members as $m): ?>
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
<!-- ──── APPROVED SIGN-UPS ──────────────────────────────────────────── -->
<div class="overview-section-divider"></div>
<div class="overview-section-head">
    <div class="overview-section-title">
      <?= View::icon('approved') ?> Approved Sign-Ups
    </div>
    <div class="panel-header-sub">Members who have been approved and are on the list.</div>
</div>

<?php if (empty($approved_members)): ?>
  <div class="card empty">
    <?= View::icon('check-badge', ['class' => 'empty-icon', 'width' => 32, 'height' => 32]) ?>
    <div><strong>No approved sign-ups yet.</strong></div>
    <div>Approved members will appear here once sign-ups are accepted.</div>
  </div>
<?php else: ?>
  <div class="card">
    <div class="card-head">
      <span><?= View::e($listing->subject) ?></span>
      <?php $granted_count = count(array_filter((array) $approved_members, fn($m) => $m->granted)); ?>
      <div class="card-head-sub"><span class="badge badge-current"><?= (int) $approved_count ?> approved</span>
      <?php if ($granted_count > 0): ?>
        <span class="badge badge-redirect"><?= $granted_count ?> Granted</span></div>
      <?php endif; ?>
    </div>
    <form method="POST" action="<?= $b ?>/admin/kim/<?= $fid ?>/bulk"
          data-del-bulk data-del-entity="kim_member"
          data-del-context="<?= View::e($listing->subject) ?>">
      <input type="hidden" name="_csrf" value="<?= View::e($csrf_token) ?>">
      <div class="bulk">
        Bulk Actions:
        <div class="select-wrap">
          <select name="bulk_action" class="select-xs">
            <option value="" disabled selected>&mdash; Select &mdash;</option>
            <option value="email_selected">Email</option>
            <option value="delete">Delete</option>
          </select>
        </div>
        <button type="submit" class="btn btn-quaternary btn-sm">Apply to Selected</button>
      </div>
      <div class="table-wrap">
      <table class="data-table mem-table">
        <thead>
          <tr>
            <th class="checkbox-col"><input type="checkbox" class="sel-all" data-fid="kim-a-<?= $fid ?>"></th>
            <th>Name</th>
            <th>Email</th>
            <th>URL</th>
            <th>Date</th>
            <th class="actions-col">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($approved_members as $m): ?>
          <tr>
            <td class="checkbox-col">
              <input type="checkbox" class="row-cb-kim-a-<?= $fid ?>" name="ids[]" value="<?= (int) $m->id ?>">
            </td>
            <td class="text-strong" data-label="Name">
              <?= View::e($m->name) ?>
              <?php if ($m->granted): ?>
                <span class="badge badge-redirect badge-inline-sm">Granted</span>
              <?php endif; ?>
            </td>
            <td class="text-muted" data-label="Email"><?= View::emailWrap($m->email) ?></td>
            <td data-label="URL">
              <?php if ($m->url !== ''): ?>
                <a href="<?= View::e($m->url) ?>" target="_blank" rel="noopener noreferrer"
                   class="link link-break"><?= View::e($m->url) ?></a>
              <?php else: ?>
                <span class="text-dim">&mdash;</span>
              <?php endif; ?>
            </td>
            <td class="text-dim nowrap" data-label="Date"><?= View::e(date($admin_date_format, strtotime($m->addedAt))) ?></td>
            <td class="actions-col" data-label="">
              <div class="actions-row">
                <a href="<?= $b ?>/admin/kim/<?= (int) $m->id ?>/edit" class="btn btn-tertiary btn-xs">Edit</a>
                <a href="<?= $b ?>/admin/kim/<?= (int) $m->id ?>/email-member" class="btn btn-secondary btn-xs">Email</a>
                <button type="submit" form="del-kim-<?= (int) $m->id ?>" class="btn btn-danger btn-xs">Delete</button>
              </div>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      </div>
    </form>
  </div>
  <?php $pg_current = $page; $pg_total = $total_pages; $pg_url = $page_url;
  include LUSTER_BASE . '/templates/admin/_pagination.php'; ?>
<?php foreach ($approved_members as $m): ?>
<form id="del-kim-<?= (int) $m->id ?>" method="POST"
      action="<?= $b ?>/admin/kim/<?= (int) $m->id ?>/delete"
      data-del-modal data-del-entity="kim_member"
      data-del-name="<?= View::e($m->name) ?>">
  <input type="hidden" name="_csrf" value="<?= View::e($csrf_token) ?>">
</form>
<?php endforeach; ?>
<?php endif; ?>
