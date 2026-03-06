<?php
/** @var \Luster\Models\Fanlisting[] $fanlistings */
/** @var array<int,int> $member_counts */
/** @var array<int,int> $pending_counts */
/** @var array<int,string|null> $images */
/** @var array  $cfg */
/** @var string $base_url */
/** @var string $success */
$b = rtrim(\Luster\Core\View::e($base_url), '/');

$sizeMap   = ['small' => '50px', 'medium' => '100px', 'large' => '150px'];
$sizeKey   = (string) ($cfg['image_preview_size'] ?? 'medium');
$imgHeight = $sizeMap[$sizeKey] ?? '100px';
?>
<style>
  .fl-table { width:100%;border-collapse:collapse;background:#fff;border:1px solid #e5e7eb;border-radius:8px;overflow:hidden; }
  .fl-table th { background:#f9fafb;text-align:left;padding:.65rem 1rem;font-size:.8rem;color:#6b7280;border-bottom:1px solid #e5e7eb; }
  .fl-table td { padding:.65rem 1rem;font-size:.8125rem;color:#374151;border-bottom:1px solid #f3f4f6;vertical-align:middle; }
  .fl-table tr:last-child td { border-bottom:none; }
  .fl-table tr:hover td { background:#fafafa; }
  .badge { display:inline-block;padding:.15rem .5rem;border-radius:999px;font-size:.7rem;font-weight:600; }
  .badge-green { background:#f0fdf4;color:#15803d; }
  .badge-yellow { background:#fffbeb;color:#b45309; }
  .badge-red { background:#fef2f2;color:#b91c1c; }
  .badge-pending { background:#eff6ff;color:#1d4ed8; }
  .act-link { color:#4f46e5;text-decoration:none;font-size:.8rem;margin-right:.75rem; }
  .act-link:hover { text-decoration:underline; }
  .btn-link-danger { background:none;border:none;color:#dc2626;cursor:pointer;font-size:.875rem;padding:0; }
  .alert-success { background:#f0fdf4;border:1px solid #86efac;border-radius:6px;padding:.65rem 1rem;margin-bottom:1rem;color:#15803d;font-size:.875rem; }
</style>

<?php if ($success !== ''): ?>
  <div class="alert-success"><?= \Luster\Core\View::e($success) ?></div>
<?php endif; ?>

<?php if (empty($fanlistings)): ?>
  <p style="color:#6b7280">No fanlistings found. Add some via Enthusiast first.</p>
<?php else: ?>
<table class="fl-table">
  <thead>
    <tr>
      <th style="width:120px">Image</th>
      <th>#</th>
      <th>Title</th>
      <th>Subject</th>
      <th>Status</th>
      <th>Members</th>
      <th>Pending</th>
      <th>Actions</th>
    </tr>
  </thead>
  <tbody>
    <?php $loop = 0; foreach ($fanlistings as $fl): ++$loop; ?>
    <tr>
      <td style="width:120px">
        <?php $flImage = $images[$fl->id] ?? null; ?>
        <?php if ($flImage !== null): ?>
          <img src="<?= $b ?>/uploads/fanlistings/<?= \Luster\Core\View::e($flImage) ?>"
               alt="" style="max-height:<?= \Luster\Core\View::e($imgHeight) ?>;width:auto;display:block;border-radius:3px">
        <?php else: ?>
          <span style="color:#9ca3af">—</span>
        <?php endif; ?>
      </td>
      <td style="color:#9ca3af"><?= $loop ?></td>
      <td><strong><?= \Luster\Core\View::e($fl->name) ?></strong></td>
      <td><?= \Luster\Core\View::e($fl->subject) ?></td>
      <td>
        <?php if ($fl->upcoming): ?>
          <span class="badge badge-yellow">Upcoming</span>
        <?php elseif ($fl->trouble): ?>
          <span class="badge badge-red">Trouble</span>
        <?php else: ?>
          <span class="badge badge-green">Active</span>
        <?php endif; ?>
      </td>
      <td><?= (int) ($member_counts[$fl->id] ?? 0) ?></td>
      <td>
        <?php $pend = (int) ($pending_counts[$fl->id] ?? 0); ?>
        <?php if ($pend > 0): ?>
          <span class="badge badge-pending"><?= $pend ?></span>
        <?php else: ?>
          <span style="color:#9ca3af">—</span>
        <?php endif; ?>
      </td>
      <td>
        <a href="<?= $b ?>/admin/fanlistings/<?= (int) $fl->id ?>/edit" class="act-link">Edit</a>
        <a href="<?= $b ?>/admin/members/<?= (int) $fl->id ?>" class="act-link">Members</a>
        <form method="post"
              action="<?= $b ?>/admin/fanlistings/<?= (int) $fl->id ?>/delete"
              style="display:inline;margin-left:.5rem"
              onsubmit="return confirm('Delete this fanlisting and all its members? This cannot be undone.')">
          <input type="hidden" name="_csrf" value="<?= \Luster\Core\View::e($csrf_token ?? '') ?>">
          <button type="submit" class="btn-link-danger">Delete</button>
        </form>
      </td>
    </tr>
    <?php endforeach; ?>
  </tbody>
</table>
<?php endif; ?>
