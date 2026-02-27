<?php
/** @var \Luster\Models\Affiliate[] $affiliates */
/** @var array<int,string> $fan_map */
/** @var string $success */
/** @var string $error */
/** @var string $base_url */
$b = rtrim(\Luster\Core\View::e($base_url), '/');
?>
<style>
  .aff-table { width:100%;border-collapse:collapse;background:#fff;border:1px solid #e5e7eb;border-radius:8px;overflow:hidden; }
  .aff-table th { background:#f9fafb;text-align:left;padding:.65rem .9rem;font-size:.75rem;color:#6b7280;border-bottom:1px solid #e5e7eb; }
  .aff-table td { padding:.65rem .9rem;font-size:.8rem;color:#374151;border-bottom:1px solid #f3f4f6;vertical-align:middle; }
  .aff-table tr:last-child td { border-bottom:none; }
  .aff-table tr:hover td { background:#fafafa; }
  .btn { display:inline-block;padding:.35rem .85rem;border-radius:6px;font-size:.75rem;font-weight:500;text-decoration:none;cursor:pointer;border:none; }
  .btn-primary { background:#4f46e5;color:#fff; }
  .btn-sm-gray { background:#f3f4f6;color:#374151;border:1px solid #d1d5db; }
  .btn-sm-gray:hover { background:#e5e7eb; }
  .btn-red { background:#dc2626;color:#fff; }
  .badge-active   { display:inline-block;padding:.15rem .5rem;border-radius:999px;font-size:.7rem;font-weight:600;background:#f0fdf4;color:#15803d; }
  .badge-inactive { display:inline-block;padding:.15rem .5rem;border-radius:999px;font-size:.7rem;font-weight:600;background:#f3f4f6;color:#6b7280; }
  .alert-success { background:#f0fdf4;border:1px solid #86efac;border-radius:6px;padding:.6rem 1rem;margin-bottom:.75rem;color:#15803d;font-size:.8125rem; }
  .alert-error   { background:#fef2f2;border:1px solid #fca5a5;border-radius:6px;padding:.6rem 1rem;margin-bottom:.75rem;color:#b91c1c;font-size:.8125rem; }
</style>

<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1rem;">
  <span style="font-size:.875rem;color:#6b7280"><?= count($affiliates) ?> affiliate(s)</span>
  <a href="<?= $b ?>/admin/affiliates/add" class="btn btn-primary">+ Add Affiliate</a>
</div>

<?php if ($success !== ''): ?>
  <div class="alert-success"><?= \Luster\Core\View::e($success) ?></div>
<?php endif; ?>
<?php if ($error !== ''): ?>
  <div class="alert-error"><?= \Luster\Core\View::e($error) ?></div>
<?php endif; ?>

<?php if (empty($affiliates)): ?>
  <p style="color:#6b7280;font-size:.875rem">No affiliates yet. <a href="<?= $b ?>/admin/affiliates/add" style="color:#4f46e5">Add one</a>.</p>
<?php else: ?>
<table class="aff-table">
  <thead>
    <tr>
      <th>#</th>
      <th>Name</th>
      <th>URL</th>
      <th>Fanlisting</th>
      <th>Order</th>
      <th>Status</th>
      <th>Actions</th>
    </tr>
  </thead>
  <tbody>
    <?php foreach ($affiliates as $aff): ?>
    <tr>
      <td style="color:#9ca3af"><?= (int) $aff->id ?></td>
      <td><?= \Luster\Core\View::e($aff->name) ?></td>
      <td>
        <a href="<?= \Luster\Core\View::e($aff->url) ?>" target="_blank" rel="noopener noreferrer"
           style="color:#4f46e5;font-size:.8rem;word-break:break-all">
          <?= \Luster\Core\View::e($aff->url) ?>
        </a>
      </td>
      <td><?= \Luster\Core\View::e($fan_map[$aff->fanlistingId] ?? '—') ?></td>
      <td style="color:#9ca3af"><?= (int) $aff->sortOrder ?></td>
      <td>
        <?php if ($aff->active): ?>
          <span class="badge-active">Active</span>
        <?php else: ?>
          <span class="badge-inactive">Inactive</span>
        <?php endif; ?>
      </td>
      <td style="white-space:nowrap">
        <a href="<?= $b ?>/admin/affiliates/<?= (int) $aff->id ?>/edit" class="btn btn-sm-gray">Edit</a>

        <form method="POST" action="<?= $b ?>/admin/affiliates/<?= (int) $aff->id ?>/toggle" style="display:inline;margin-left:.25rem">
          <input type="hidden" name="_csrf" value="<?= \Luster\Core\View::e($csrf_token ?? '') ?>">
          <button type="submit" class="btn btn-sm-gray"><?= $aff->active ? 'Disable' : 'Enable' ?></button>
        </form>

        <form method="POST" action="<?= $b ?>/admin/affiliates/<?= (int) $aff->id ?>/delete" style="display:inline;margin-left:.25rem">
          <input type="hidden" name="_csrf" value="<?= \Luster\Core\View::e($csrf_token ?? '') ?>">
          <button type="submit" class="btn btn-red"
                  onclick="return confirm('Delete this affiliate?')">Delete</button>
        </form>
      </td>
    </tr>
    <?php endforeach; ?>
  </tbody>
</table>
<?php endif; ?>
