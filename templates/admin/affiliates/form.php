<?php
/** @var \Luster\Models\Fanlisting[] $fanlistings */
/** @var \Luster\Models\Affiliate|null $affiliate  null = add mode */
/** @var int $context_fid  non-zero = came from a specific fanlisting */
/** @var string $error */
/** @var array  $old */
/** @var string $base_url */
/** @var string $csrf_token */
$b          = rtrim(\Luster\Core\View::e($base_url), '/');
$isEdit     = $affiliate !== null;
$contextFid = (int) ($context_fid ?? 0);

// Form action: fanlisting-context add uses the dedicated route
if ($isEdit) {
    $action = $b . '/admin/affiliates/' . (int) $affiliate->id . '/edit';
} elseif ($contextFid > 0) {
    $action = $b . '/admin/fanlistings/' . $contextFid . '/affiliates/add';
} else {
    $action = $b . '/admin/affiliates/add';
}

// Back/cancel destination
$backUrl = $contextFid > 0
    ? $b . '/admin/fanlistings/' . $contextFid
    : $b . '/admin/affiliates';
$backLabel = $contextFid > 0 ? '&larr; Fanlisting' : '&larr; Affiliates';

// Value helper: prefer $old (re-populate on error), then model, then ''
$v = static function (string $key, $model, string $prop, string $default = '') use ($old): string {
    if (isset($old[$key])) {
        return (string) $old[$key];
    }
    if ($model !== null && property_exists($model, $prop)) {
        return (string) ($model->$prop ?? $default);
    }
    return $default;
};
?>
<style>
  .form-card { background:#fff;border:1px solid #e5e7eb;border-radius:8px;padding:1.5rem;max-width:580px; }
  .form-group { margin-bottom:1.1rem; }
  .form-group label { display:block;font-size:.8125rem;font-weight:600;color:#374151;margin-bottom:.35rem; }
  .form-group input, .form-group select { width:100%;padding:.5rem .75rem;border:1px solid #d1d5db;border-radius:6px;font-size:.9rem;color:#111827; }
  .form-group input:focus, .form-group select:focus { outline:none;border-color:#6366f1;box-shadow:0 0 0 2px rgba(99,102,241,.15); }
  .form-hint { font-size:.75rem;color:#6b7280;margin-top:.2rem; }
  .btn-row { display:flex;gap:.75rem;align-items:center;margin-top:1.5rem; }
  .btn { display:inline-block;padding:.5rem 1.25rem;border-radius:6px;font-size:.875rem;font-weight:500;cursor:pointer;border:none; }
  .btn-primary { background:#4f46e5;color:#fff; }
  .btn-primary:hover { background:#4338ca; }
  .btn-cancel { color:#6b7280;text-decoration:none;font-size:.875rem; }
  .alert-error { background:#fef2f2;border:1px solid #fca5a5;border-radius:6px;padding:.65rem 1rem;margin-bottom:1rem;color:#b91c1c;font-size:.875rem; }
</style>

<p style="margin-bottom:1rem">
  <a href="<?= $backUrl ?>" style="color:#6b7280;font-size:.875rem;text-decoration:none"><?= $backLabel ?></a>
</p>

<?php if ($error !== ''): ?>
  <div class="alert-error"><?= \Luster\Core\View::e($error) ?></div>
<?php endif; ?>

<div class="form-card">
  <form method="POST" action="<?= $action ?>">
    <input type="hidden" name="_csrf" value="<?= \Luster\Core\View::e($csrf_token ?? '') ?>">

    <?php if ($isEdit && $contextFid > 0): ?>
      <input type="hidden" name="from_fanlisting" value="<?= $contextFid ?>">
    <?php endif; ?>

    <?php if ($contextFid === 0): ?>
    <div class="form-group">
      <label for="aff-fanlisting">Fanlisting</label>
      <select id="aff-fanlisting" name="fanlisting_id">
        <option value="0">— Collective (no specific fanlisting) —</option>
        <?php foreach ($fanlistings as $fl): ?>
          <?php
          $selId = (int) ($old['fanlisting_id'] ?? ($affiliate?->fanlistingId ?? 0));
          ?>
          <option value="<?= (int) $fl->id ?>"
                  <?= (int) $fl->id === $selId ? 'selected' : '' ?>>
            <?= \Luster\Core\View::e($fl->name) ?>
          </option>
        <?php endforeach; ?>
      </select>
      <p class="form-hint">Leave as "Collective" if this affiliate belongs to all fanlistings.</p>
    </div>
    <?php else: ?>
      <input type="hidden" name="fanlisting_id" value="<?= $contextFid ?>">
    <?php endif; ?>

    <div class="form-group">
      <label for="aff-name">Affiliate Name <span style="color:#dc2626">*</span></label>
      <input id="aff-name" type="text" name="name" maxlength="255" required
             value="<?= \Luster\Core\View::e($v('name', $affiliate, 'name')) ?>">
    </div>

    <div class="form-group">
      <label for="aff-url">URL <span style="color:#dc2626">*</span></label>
      <input id="aff-url" type="url" name="url" maxlength="500" placeholder="https://" required
             value="<?= \Luster\Core\View::e($v('url', $affiliate, 'url')) ?>">
    </div>

    <div class="form-group">
      <label for="aff-email">Email (optional)</label>
      <input id="aff-email" type="email" name="email" maxlength="255"
             value="<?= \Luster\Core\View::e($v('email', $affiliate, 'email')) ?>">
    </div>

    <div class="form-group">
      <label for="aff-image">Image filename (optional)</label>
      <input id="aff-image" type="text" name="image" maxlength="255"
             value="<?= \Luster\Core\View::e($v('image', $affiliate, 'image')) ?>">
      <p class="form-hint">Filename only, e.g. <code>example.png</code>. Store images in <code>public/uploads/</code>.</p>
    </div>

    <div class="form-group">
      <label for="aff-sort">Sort Order</label>
      <input id="aff-sort" type="number" name="sort_order" min="0" max="9999" style="max-width:100px"
             value="<?= (int) ($old['sort_order'] ?? ($affiliate?->sortOrder ?? 0)) ?>">
      <p class="form-hint">Lower numbers appear first.</p>
    </div>

    <div class="btn-row">
      <button type="submit" class="btn btn-primary"><?= $isEdit ? 'Save Changes' : 'Add Affiliate' ?></button>
      <a href="<?= $backUrl ?>" class="btn-cancel">Cancel</a>
    </div>
  </form>
</div>
