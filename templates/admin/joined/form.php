<?php
/** @var \Luster\Models\JoinedFanlisting|null $joined  null = add mode */
/** @var string $error */
/** @var array  $old */
/** @var string $base_url */
/** @var string $csrf_token */
$b      = rtrim(\Luster\Core\View::e($base_url), '/');
$isEdit = $joined !== null;
$action = $isEdit
    ? $b . '/admin/joined/' . (int) $joined->id . '/edit'
    : $b . '/admin/joined/add';

// Value helper: prefer $old (re-populate on error), then model, then default
$v = static function (string $key, $model, string $prop, string $default = '') use ($old): string {
    if (isset($old[$key])) {
        return (string) $old[$key];
    }
    if ($model !== null && property_exists($model, $prop)) {
        return (string) ($model->$prop ?? $default);
    }
    return $default;
};

// Active checkbox: prefer $old, then model, then default true
$activeVal = isset($old['active']) ? ($old['active'] === '1') : ($joined?->active ?? true);
?>
<style>
  .form-card { background:#fff;border:1px solid #e5e7eb;border-radius:8px;padding:1.5rem;max-width:580px; }
  .form-group { margin-bottom:1.1rem; }
  .form-group label { display:block;font-size:.8125rem;font-weight:600;color:#374151;margin-bottom:.35rem; }
  .form-group input[type=text],
  .form-group input[type=url],
  .form-group input[type=date],
  .form-group input[type=number] { width:100%;padding:.5rem .75rem;border:1px solid #d1d5db;border-radius:6px;font-size:.9rem;color:#111827; }
  .form-group input:focus { outline:none;border-color:#6366f1;box-shadow:0 0 0 2px rgba(99,102,241,.15); }
  .form-hint { font-size:.75rem;color:#6b7280;margin-top:.2rem; }
  .check-row { display:flex;align-items:center;gap:.5rem;margin-top:.2rem; }
  .check-row input[type=checkbox] { width:1rem;height:1rem;accent-color:#4f46e5; }
  .btn-row { display:flex;gap:.75rem;align-items:center;margin-top:1.5rem; }
  .btn { display:inline-block;padding:.5rem 1.25rem;border-radius:6px;font-size:.875rem;font-weight:500;cursor:pointer;border:none; }
  .btn-primary { background:#4f46e5;color:#fff; }
  .btn-primary:hover { background:#4338ca; }
  .btn-cancel { color:#6b7280;text-decoration:none;font-size:.875rem; }
  .alert-error { background:#fef2f2;border:1px solid #fca5a5;border-radius:6px;padding:.65rem 1rem;margin-bottom:1rem;color:#b91c1c;font-size:.875rem; }
</style>

<p style="margin-bottom:1rem">
  <a href="<?= $b ?>/admin/joined" style="color:#6b7280;font-size:.875rem;text-decoration:none">&larr; Joined Fanlistings</a>
</p>

<?php if ($error !== ''): ?>
  <div class="alert-error"><?= \Luster\Core\View::e($error) ?></div>
<?php endif; ?>

<div class="form-card">
  <form method="POST" action="<?= $action ?>">
    <input type="hidden" name="_csrf" value="<?= \Luster\Core\View::e($csrf_token ?? '') ?>">

    <div class="form-group">
      <label for="jf-name">Fanlisting Name <span style="color:#dc2626">*</span></label>
      <input id="jf-name" type="text" name="name" maxlength="255" required
             value="<?= \Luster\Core\View::e($v('name', $joined, 'name')) ?>">
    </div>

    <div class="form-group">
      <label for="jf-url">URL <span style="color:#dc2626">*</span></label>
      <input id="jf-url" type="url" name="url" maxlength="500" placeholder="https://" required
             value="<?= \Luster\Core\View::e($v('url', $joined, 'url')) ?>">
    </div>

    <div class="form-group">
      <label for="jf-category">Category</label>
      <input id="jf-category" type="text" name="category" maxlength="100"
             value="<?= \Luster\Core\View::e($v('category', $joined, 'category')) ?>"
             placeholder="e.g. Anime, Books, Music">
      <p class="form-hint">Used to group fanlistings on the index page.</p>
    </div>

    <div class="form-group">
      <label for="jf-date">Date Joined</label>
      <input id="jf-date" type="date" name="date_joined" style="max-width:180px"
             value="<?= \Luster\Core\View::e($v('date_joined', $joined, 'dateJoined')) ?>">
    </div>

    <div class="form-group">
      <label for="jf-image">Image filename (optional)</label>
      <input id="jf-image" type="text" name="image" maxlength="255"
             value="<?= \Luster\Core\View::e($v('image', $joined, 'image')) ?>"
             placeholder="e.g. fanlisting.png">
      <p class="form-hint">Filename only. Store images in <code>public/uploads/</code>.</p>
    </div>

    <div class="form-group">
      <label for="jf-sort">Sort Order</label>
      <input id="jf-sort" type="number" name="sort_order" min="0" max="9999" style="max-width:100px"
             value="<?= (int) ($old['sort_order'] ?? ($joined?->sortOrder ?? 0)) ?>">
      <p class="form-hint">Lower numbers appear first within a category.</p>
    </div>

    <div class="form-group">
      <label>Status</label>
      <div class="check-row">
        <input id="jf-active" type="checkbox" name="active" value="1"
               <?= $activeVal ? 'checked' : '' ?>>
        <label for="jf-active" style="margin-bottom:0;font-weight:400;font-size:.875rem">Active (visible on public site)</label>
      </div>
    </div>

    <div class="btn-row">
      <button type="submit" class="btn btn-primary"><?= $isEdit ? 'Save Changes' : 'Add Joined FL' ?></button>
      <a href="<?= $b ?>/admin/joined" class="btn-cancel">Cancel</a>
    </div>
  </form>
</div>
