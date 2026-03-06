<?php
/** @var \Luster\Models\JoinedFanlisting[] $joined */
/** @var string[] $categories */
/** @var string $filter_cat */
/** @var string $success */
/** @var string $error */
/** @var string $base_url */
/** @var string $csrf_token */
$b = rtrim(\Luster\Core\View::e($base_url), '/');

// Group by category for display
$grouped = [];
foreach ($joined as $jf) {
    $cat = $jf->category !== '' ? $jf->category : 'Uncategorised';
    $grouped[$cat][] = $jf;
}
ksort($grouped);
?>
<style>
  .toolbar { display:flex;justify-content:space-between;align-items:center;margin-bottom:1.25rem;gap:1rem; }
  .filter-row { display:flex;gap:.5rem;align-items:center; }
  .filter-row select { padding:.4rem .65rem;border:1px solid #d1d5db;border-radius:6px;font-size:.875rem;color:#374151; }
  .btn { display:inline-block;padding:.45rem 1rem;border-radius:6px;font-size:.875rem;font-weight:500;text-decoration:none;cursor:pointer;border:none; }
  .btn-primary { background:#4f46e5;color:#fff; }
  .btn-primary:hover { background:#4338ca; }
  .card { background:#fff;border:1px solid #e5e7eb;border-radius:8px;margin-bottom:1.25rem; }
  .card-header { padding:.75rem 1.25rem;border-bottom:1px solid #e5e7eb;font-size:.8125rem;font-weight:700;color:#374151;text-transform:uppercase;letter-spacing:.05em; }
  table { width:100%;border-collapse:collapse; }
  th { text-align:left;padding:.6rem 1rem;font-size:.75rem;font-weight:600;color:#6b7280;text-transform:uppercase;letter-spacing:.04em;border-bottom:1px solid #e5e7eb; }
  td { padding:.6rem 1rem;font-size:.875rem;border-bottom:1px solid #f3f4f6;vertical-align:middle; }
  tr:last-child td { border-bottom:none; }
  .badge { display:inline-block;padding:.15rem .55rem;border-radius:9999px;font-size:.7rem;font-weight:600; }
  .badge-active { background:#f0fdf4;color:#15803d; }
  .badge-inactive { background:#f9fafb;color:#6b7280; }
  .btn-link { background:none;border:none;cursor:pointer;font-size:.8125rem;padding:0; }
  .alert-success { background:#f0fdf4;border:1px solid #86efac;border-radius:6px;padding:.65rem 1rem;margin-bottom:1rem;color:#15803d;font-size:.875rem; }
  .alert-error   { background:#fef2f2;border:1px solid #fca5a5;border-radius:6px;padding:.65rem 1rem;margin-bottom:1rem;color:#b91c1c;font-size:.875rem; }
  .empty { color:#6b7280;font-size:.875rem;padding:1rem 1.25rem; }
</style>

<?php if ($success !== ''): ?>
  <div class="alert-success"><?= \Luster\Core\View::e($success) ?></div>
<?php endif; ?>
<?php if ($error !== ''): ?>
  <div class="alert-error"><?= \Luster\Core\View::e($error) ?></div>
<?php endif; ?>

<div class="toolbar">
  <form method="GET" action="<?= $b ?>/admin/joined" class="filter-row">
    <select name="category" onchange="this.form.submit()">
      <option value="">All categories</option>
      <?php foreach ($categories as $cat): ?>
        <option value="<?= \Luster\Core\View::e($cat) ?>"
                <?= $filter_cat === $cat ? 'selected' : '' ?>>
          <?= \Luster\Core\View::e($cat) ?>
        </option>
      <?php endforeach; ?>
    </select>
  </form>
  <a href="<?= $b ?>/admin/joined/add" class="btn btn-primary">+ Add Joined FL</a>
</div>

<?php if (empty($joined)): ?>
  <div class="card"><p class="empty">No joined fanlistings found<?= $filter_cat !== '' ? ' in this category' : '' ?>.</p></div>
<?php else: ?>
  <?php foreach ($grouped as $catName => $items): ?>
  <div class="card">
    <div class="card-header"><?= \Luster\Core\View::e($catName) ?> (<?= count($items) ?>)</div>
    <table>
      <thead>
        <tr>
          <th style="width:70px">Image</th>
          <th style="width:40px">ID</th>
          <th>Name</th>
          <th>URL</th>
          <th>Category</th>
          <th>Date Joined</th>
          <th>Status</th>
          <th style="text-align:right">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($items as $jf): ?>
        <tr>
          <td>
            <?php if ($jf->image !== null && $jf->image !== ''): ?>
              <img src="/luster/public/uploads/<?= \Luster\Core\View::e($jf->image) ?>"
                   alt="" style="max-height:50px;width:auto;display:block;border-radius:3px">
            <?php else: ?>
              <span style="color:#9ca3af">&mdash;</span>
            <?php endif; ?>
          </td>
          <td style="color:#9ca3af"><?= (int) $jf->id ?></td>
          <td style="font-weight:500;color:#111827"><?= \Luster\Core\View::e($jf->name) ?></td>
          <td>
            <?php if ($jf->url !== ''): ?>
              <a href="<?= \Luster\Core\View::e($jf->url) ?>" target="_blank" rel="noopener noreferrer"
                 style="color:#4f46e5;font-size:.8125rem">Visit</a>
            <?php else: ?>
              <span style="color:#9ca3af">&mdash;</span>
            <?php endif; ?>
          </td>
          <td style="color:#6b7280">
            <?= $jf->category !== '' ? \Luster\Core\View::e($jf->category) : '<span style="color:#9ca3af">&mdash;</span>' ?>
          </td>
          <td style="color:#6b7280">
            <?= $jf->dateJoined !== null ? \Luster\Core\View::e($jf->dateJoined) : '&mdash;' ?>
          </td>
          <td>
            <span class="badge <?= $jf->active ? 'badge-active' : 'badge-inactive' ?>">
              <?= $jf->active ? 'Active' : 'Inactive' ?>
            </span>
          </td>
          <td style="text-align:right;white-space:nowrap">
            <a href="<?= $b ?>/admin/joined/<?= (int) $jf->id ?>/edit"
               style="color:#4f46e5;font-size:.8125rem;margin-right:.5rem">Edit</a>

            <form method="POST"
                  action="<?= $b ?>/admin/joined/<?= (int) $jf->id ?>/toggle"
                  style="display:inline">
              <input type="hidden" name="_csrf" value="<?= \Luster\Core\View::e($csrf_token ?? '') ?>">
              <button type="submit" class="btn-link" style="color:#d97706;margin-right:.5rem">
                <?= $jf->active ? 'Deactivate' : 'Activate' ?>
              </button>
            </form>

            <form method="POST"
                  action="<?= $b ?>/admin/joined/<?= (int) $jf->id ?>/delete"
                  style="display:inline"
                  onsubmit="return confirm('Delete this joined fanlisting?')">
              <input type="hidden" name="_csrf" value="<?= \Luster\Core\View::e($csrf_token ?? '') ?>">
              <button type="submit" class="btn-link" style="color:#dc2626">Delete</button>
            </form>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endforeach; ?>
<?php endif; ?>
