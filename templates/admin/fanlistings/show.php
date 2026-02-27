<?php
/** @var \Luster\Models\Fanlisting $fanlisting */
/** @var int $approved */
/** @var int $pending */
/** @var bool $auto_approve */
/** @var \Luster\Models\Affiliate[] $affiliates */
/** @var string $base_url */
/** @var string $csrf_token */
/** @var string $success */
$b = rtrim(\Luster\Core\View::e($base_url), '/');
?>
<style>
  .info-card { background:#fff;border:1px solid #e5e7eb;border-radius:8px;padding:1.25rem;margin-bottom:1.25rem; }
  .info-grid { display:grid;grid-template-columns:1fr 1fr;gap:.75rem 1.5rem; }
  .info-row label { font-size:.75rem;font-weight:600;color:#6b7280;text-transform:uppercase;letter-spacing:.04em;display:block;margin-bottom:.1rem; }
  .info-row span { font-size:.9rem;color:#111827; }
  .stat-row { display:flex;gap:1.25rem;margin-bottom:1.25rem; }
  .mini-stat { background:#fff;border:1px solid #e5e7eb;border-radius:8px;padding:1rem 1.25rem;min-width:120px; }
  .mini-stat .v { font-size:1.75rem;font-weight:700;color:#111827; }
  .mini-stat .l { font-size:.75rem;color:#6b7280;margin-top:.1rem; }
  .btn { display:inline-block;padding:.45rem 1rem;border-radius:6px;font-size:.875rem;font-weight:500;text-decoration:none;cursor:pointer;border:none; }
  .btn-primary { background:#4f46e5;color:#fff; }
  .btn-primary:hover { background:#4338ca; }
  .btn-secondary { background:#f3f4f6;color:#374151;border:1px solid #d1d5db; }
  .btn-secondary:hover { background:#e5e7eb; }
  .alert-success { background:#f0fdf4;border:1px solid #86efac;border-radius:6px;padding:.65rem 1rem;margin-bottom:1rem;color:#15803d;font-size:.875rem; }
</style>

<p style="margin-bottom:1rem">
  <a href="<?= $b ?>/admin/fanlistings" style="color:#6b7280;font-size:.875rem;text-decoration:none">&larr; All Fanlistings</a>
</p>

<?php if ($success !== ''): ?>
  <div class="alert-success"><?= \Luster\Core\View::e($success) ?></div>
<?php endif; ?>

<!-- Stats row -->
<div class="stat-row">
  <div class="mini-stat">
    <div class="v"><?= (int) $approved ?></div>
    <div class="l">Approved</div>
  </div>
  <div class="mini-stat">
    <div class="v" style="<?= (int) $pending > 0 ? 'color:#d97706' : '' ?>"><?= (int) $pending ?></div>
    <div class="l">Pending</div>
  </div>
</div>

<!-- Details card -->
<div class="info-card">
  <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:1rem;">
    <h2 style="font-size:1.1rem;font-weight:700;color:#111827"><?= \Luster\Core\View::e($fanlisting->name) ?></h2>
    <div style="display:flex;gap:.5rem;">
      <a href="<?= $b ?>/admin/fanlistings/<?= (int) $fanlisting->id ?>/edit" class="btn btn-primary">Edit</a>
      <a href="<?= $b ?>/admin/members/<?= (int) $fanlisting->id ?>" class="btn btn-secondary">Members</a>
    </div>
  </div>

  <div class="info-grid">
    <div class="info-row">
      <label>Subject</label>
      <span><?= \Luster\Core\View::e($fanlisting->subject) ?></span>
    </div>
    <div class="info-row">
      <label>Status</label>
      <span>
        <?php if ($fanlisting->upcoming): ?>Upcoming
        <?php elseif ($fanlisting->trouble): ?>Trouble
        <?php else: ?>Active<?php endif; ?>
      </span>
    </div>
    <div class="info-row">
      <label>URL</label>
      <span>
        <?php if ($fanlisting->url !== ''): ?>
          <a href="<?= \Luster\Core\View::e($fanlisting->url) ?>" target="_blank" rel="noopener noreferrer" style="color:#4f46e5">
            <?= \Luster\Core\View::e($fanlisting->url) ?>
          </a>
        <?php else: ?>—<?php endif; ?>
      </span>
    </div>
    <div class="info-row">
      <label>Email</label>
      <span><?= \Luster\Core\View::e($fanlisting->email !== '' ? $fanlisting->email : '—') ?></span>
    </div>
    <div class="info-row">
      <label>Table</label>
      <span style="font-family:monospace;font-size:.85rem"><?= \Luster\Core\View::e($fanlisting->dbprefix) ?></span>
    </div>
    <div class="info-row">
      <label>Auto-Approve</label>
      <span><?= $auto_approve ? 'Yes' : 'No' ?></span>
    </div>
  </div>
</div>

<?php if ((int) $pending > 0): ?>
<div style="background:#fffbeb;border:1px solid #fde68a;border-radius:8px;padding:1rem 1.25rem;margin-bottom:1.25rem;">
  <strong style="color:#92400e">&#9888; <?= (int) $pending ?> member(s) awaiting approval.</strong>
  <a href="<?= $b ?>/admin/members/<?= (int) $fanlisting->id ?>?status=pending" style="color:#4f46e5;margin-left:.5rem;font-size:.875rem">Review now &rarr;</a>
</div>
<?php endif; ?>

<!-- Affiliates section -->
<div class="info-card">
  <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1rem;">
    <h2 style="font-size:1rem;font-weight:700;color:#111827">Affiliates</h2>
    <a href="<?= $b ?>/admin/fanlistings/<?= (int) $fanlisting->id ?>/affiliates/add"
       class="btn btn-primary" style="font-size:.8125rem;padding:.35rem .8rem">+ Add Affiliate</a>
  </div>

  <?php if (empty($affiliates)): ?>
    <p style="color:#6b7280;font-size:.875rem">No affiliates for this fanlisting yet.</p>
  <?php else: ?>
    <table style="width:100%;border-collapse:collapse;font-size:.875rem">
      <thead>
        <tr style="border-bottom:1px solid #e5e7eb">
          <th style="text-align:left;padding:.5rem .5rem;color:#6b7280;font-weight:600;font-size:.75rem">NAME</th>
          <th style="text-align:left;padding:.5rem .5rem;color:#6b7280;font-weight:600;font-size:.75rem">STATUS</th>
          <th style="text-align:right;padding:.5rem .5rem;color:#6b7280;font-weight:600;font-size:.75rem">ACTIONS</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($affiliates as $aff): ?>
        <tr style="border-bottom:1px solid #f3f4f6">
          <td style="padding:.5rem .5rem">
            <a href="<?= \Luster\Core\View::e($aff->url) ?>" target="_blank" rel="noopener noreferrer"
               style="color:#4f46e5;font-weight:500"><?= \Luster\Core\View::e($aff->name) ?></a>
          </td>
          <td style="padding:.5rem .5rem">
            <?php if ($aff->active): ?>
              <span style="background:#f0fdf4;color:#15803d;padding:.15rem .55rem;border-radius:9999px;font-size:.7rem;font-weight:600">Active</span>
            <?php else: ?>
              <span style="background:#f9fafb;color:#6b7280;padding:.15rem .55rem;border-radius:9999px;font-size:.7rem;font-weight:600">Inactive</span>
            <?php endif; ?>
          </td>
          <td style="padding:.5rem .5rem;text-align:right">
            <a href="<?= $b ?>/admin/affiliates/<?= (int) $aff->id ?>/edit?from_fanlisting=<?= (int) $fanlisting->id ?>"
               style="color:#4f46e5;font-size:.8125rem;margin-right:.5rem">Edit</a>

            <form method="POST"
                  action="<?= $b ?>/admin/affiliates/<?= (int) $aff->id ?>/toggle"
                  style="display:inline">
              <input type="hidden" name="_csrf" value="<?= \Luster\Core\View::e($csrf_token ?? '') ?>">
              <input type="hidden" name="from_fanlisting" value="<?= (int) $fanlisting->id ?>">
              <button type="submit" style="background:none;border:none;color:#d97706;font-size:.8125rem;cursor:pointer;padding:0;margin-right:.5rem">
                <?= $aff->active ? 'Deactivate' : 'Activate' ?>
              </button>
            </form>

            <form method="POST"
                  action="<?= $b ?>/admin/affiliates/<?= (int) $aff->id ?>/delete"
                  style="display:inline"
                  onsubmit="return confirm('Delete this affiliate?')">
              <input type="hidden" name="_csrf" value="<?= \Luster\Core\View::e($csrf_token ?? '') ?>">
              <input type="hidden" name="from_fanlisting" value="<?= (int) $fanlisting->id ?>">
              <button type="submit" style="background:none;border:none;color:#dc2626;font-size:.8125rem;cursor:pointer;padding:0">Delete</button>
            </form>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>
