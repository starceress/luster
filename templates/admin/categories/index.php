<?php
/** @var array<int, \Luster\Models\Category> $parents */
/** @var array<int, \Luster\Models\Category[]> $children */
/** @var \Luster\Models\Category[] $all */
/** @var string $success */
/** @var string $error */
/** @var string $base_url */
/** @var string $csrf_token */
$b = rtrim(\Luster\Core\View::e($base_url), '/');

$sourceBadge = static function (string $source): string {
    return match ($source) {
        'tfl'   => '<span style="background:#eff6ff;color:#1d4ed8;padding:.15rem .5rem;border-radius:9999px;font-size:.7rem;font-weight:600">TFL</span>',
        'tafl'  => '<span style="background:#fdf4ff;color:#7e22ce;padding:.15rem .5rem;border-radius:9999px;font-size:.7rem;font-weight:600">TAFL</span>',
        default => '<span style="background:#f3f4f6;color:#374151;padding:.15rem .5rem;border-radius:9999px;font-size:.7rem;font-weight:600">Custom</span>',
    };
};
?>
<style>
  .info-card   { background:#fff;border:1px solid #e5e7eb;border-radius:8px;padding:1.25rem;margin-bottom:1.25rem; }
  .btn         { display:inline-block;padding:.45rem 1rem;border-radius:6px;font-size:.875rem;font-weight:500;text-decoration:none;cursor:pointer;border:none; }
  .btn-primary { background:#4f46e5;color:#fff; }
  .btn-primary:hover { background:#4338ca; }
  .alert-success { background:#f0fdf4;border:1px solid #86efac;border-radius:6px;padding:.65rem 1rem;margin-bottom:1rem;color:#15803d;font-size:.875rem; }
  .alert-error   { background:#fef2f2;border:1px solid #fca5a5;border-radius:6px;padding:.65rem 1rem;margin-bottom:1rem;color:#b91c1c;font-size:.875rem; }
  .cat-row     { display:flex;align-items:center;justify-content:space-between;padding:.5rem .5rem;border-bottom:1px solid #f3f4f6; }
  .cat-row:last-child { border-bottom:none; }
  .cat-child   { padding-left:1.75rem; }
</style>

<?php if ($success !== ''): ?>
  <div class="alert-success"><?= \Luster\Core\View::e($success) ?></div>
<?php endif; ?>
<?php if ($error !== ''): ?>
  <div class="alert-error"><?= \Luster\Core\View::e($error) ?></div>
<?php endif; ?>

<!-- Category list -->
<div class="info-card">
  <h2 style="font-size:1rem;font-weight:700;color:#111827;margin-bottom:1rem">
    All Categories <span style="color:#6b7280;font-size:.875rem;font-weight:400">(<?= count($all) ?>)</span>
  </h2>

  <?php if (empty($parents)): ?>
    <p style="color:#6b7280;font-size:.875rem">No categories yet. Add one below.</p>
  <?php else: ?>
    <?php foreach ($parents as $parent): ?>
      <!-- Parent row -->
      <div class="cat-row">
        <div style="display:flex;align-items:center;gap:.6rem">
          <?= $sourceBadge($parent->source) ?>
          <span style="font-size:.9rem;color:#111827"><?= \Luster\Core\View::e($parent->name) ?></span>
        </div>
        <form method="POST"
              action="<?= $b ?>/admin/categories/<?= (int) $parent->id ?>/delete"
              onsubmit="return confirm('Delete category &quot;<?= \Luster\Core\View::e(addslashes($parent->name)) ?>&quot;? This will unlink any joined fanlistings in this category.')">
          <input type="hidden" name="_csrf" value="<?= \Luster\Core\View::e($csrf_token) ?>">
          <button type="submit"
                  style="background:none;border:none;color:#dc2626;font-size:.8125rem;cursor:pointer;padding:0">
            Delete
          </button>
        </form>
      </div>

      <!-- Child rows (e.g. TAFL sub-categories under Anime/Manga) -->
      <?php foreach ($children[$parent->id] ?? [] as $child): ?>
        <div class="cat-row cat-child">
          <div style="display:flex;align-items:center;gap:.6rem">
            <span style="color:#d1d5db;font-size:.8rem">&#8627;</span>
            <?= $sourceBadge($child->source) ?>
            <span style="font-size:.875rem;color:#374151"><?= \Luster\Core\View::e($child->name) ?></span>
          </div>
          <form method="POST"
                action="<?= $b ?>/admin/categories/<?= (int) $child->id ?>/delete"
                onsubmit="return confirm('Delete category &quot;<?= \Luster\Core\View::e(addslashes($child->name)) ?>&quot;?')">
            <input type="hidden" name="_csrf" value="<?= \Luster\Core\View::e($csrf_token) ?>">
            <button type="submit"
                    style="background:none;border:none;color:#dc2626;font-size:.8125rem;cursor:pointer;padding:0">
              Delete
            </button>
          </form>
        </div>
      <?php endforeach; ?>
    <?php endforeach; ?>
  <?php endif; ?>
</div>

<!-- Add category form -->
<div class="info-card">
  <h2 style="font-size:1rem;font-weight:700;color:#111827;margin-bottom:1rem">Add Category</h2>

  <form method="POST" action="<?= $b ?>/admin/categories/add">
    <input type="hidden" name="_csrf" value="<?= \Luster\Core\View::e($csrf_token) ?>">

    <div style="display:grid;grid-template-columns:1fr 1fr auto;gap:.75rem;align-items:end">
      <div>
        <label for="cat_name"
               style="font-size:.75rem;font-weight:600;color:#6b7280;text-transform:uppercase;letter-spacing:.04em;display:block;margin-bottom:.35rem">
          Name <span aria-hidden="true">*</span>
        </label>
        <input type="text" id="cat_name" name="name" maxlength="150" required
               style="width:100%;border:1px solid #d1d5db;border-radius:6px;padding:.45rem .75rem;font-size:.875rem;color:#111827">
      </div>

      <div>
        <label for="cat_parent"
               style="font-size:.75rem;font-weight:600;color:#6b7280;text-transform:uppercase;letter-spacing:.04em;display:block;margin-bottom:.35rem">
          Parent (optional)
        </label>
        <select id="cat_parent" name="parent_id"
                style="width:100%;border:1px solid #d1d5db;border-radius:6px;padding:.45rem .75rem;font-size:.875rem;color:#111827;background:#fff">
          <option value="0">— None (top level) —</option>
          <?php foreach ($parents as $parent): ?>
            <option value="<?= (int) $parent->id ?>">
              <?= \Luster\Core\View::e($parent->name) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div>
        <button type="submit" class="btn btn-primary">Add Category</button>
      </div>
    </div>
  </form>
</div>
