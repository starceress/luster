<?php declare(strict_types=1);
$b = rtrim(\Luster\Core\View::e($base_url ?? ''), '/');
?>

<?php if (($success ?? '') !== ''): ?>
  <div class="alert alert-success"><?= \Luster\Core\View::e($success ?? '') ?></div>
<?php endif; ?>
<?php if (($error ?? '') !== ''): ?>
  <div class="alert alert-error"><?= \Luster\Core\View::e($error ?? '') ?></div>
<?php endif; ?>

<p class="page-back">
  <a href="<?= $b ?>/admin/categories" class="neb-link">&larr; Back to Categories</a>
</p>

<div class="form-card" style="max-width:500px">
  <h2 style="margin:0 0 1.25rem;font-size:1.1rem;font-weight:600;color:var(--text)">Edit Category</h2>
  <form method="POST" id="edit-form">
    <input type="hidden" name="_csrf" value="<?= \Luster\Core\View::e($csrf_token ?? '') ?>">

    <div class="form-group">
      <label class="form-label">Category Name</label>
      <input type="text" name="name" class="form-input"
             value="<?= \Luster\Core\View::e($cat->name ?? '') ?>" required maxlength="150">
    </div>

    <div class="form-group">
      <label class="form-label">Parent Category <span style="font-weight:400;color:var(--text-dim)">(optional)</span></label>
      <div class="select-wrap">
        <select name="parent_id" class="form-select">
          <option value="">None</option>
          <?php foreach ($all_cats ?? [] as $c): ?>
            <?php if ($c->parent_id === null && $c->id !== ($cat->id ?? 0)): ?>
              <option value="<?= (int) $c->id ?>" <?= (int) ($cat->parent_id ?? 0) === (int) $c->id ? 'selected' : '' ?>>
                <?= \Luster\Core\View::e($c->name ?? '') ?>
              </option>
            <?php endif; ?>
          <?php endforeach; ?>
        </select>
      </div>
    </div>

    <input type="hidden" name="current_source" value="<?= \Luster\Core\View::e($cat->source ?? 'custom') ?>">

    <div class="form-group">
      <label class="form-label">Change Network?</label>
      <div class="select-wrap">
        <select name="add_network" id="add-network-toggle" class="form-select"
                onchange="document.getElementById('network-name-row').style.display=this.value==='yes'?'block':'none'">
          <option value="no">Keep current (<?= \Luster\Core\View::e($cat->source ?? 'custom') ?>)</option>
          <option value="yes">Change network</option>
        </select>
      </div>
    </div>

    <div class="form-group" id="network-name-row" style="display:none">
      <label class="form-label">New Network Name</label>
      <input type="text" name="network_name" class="form-input" maxlength="50">
    </div>

    <div class="btn-row">
      <button type="submit" class="btn btn-primary">Save Changes</button>
      <button type="button" class="btn btn-ghost" onclick="document.getElementById('edit-form').reset()">Clear</button>
      <a href="<?= $b ?>/admin/categories" class="btn btn-ghost">Cancel</a>
    </div>
  </form>

  <?php if (!in_array($cat->source ?? '', ['tfl', 'tafl'], true)): ?>
    <div class="section-divider"></div>
    <form method="POST" action="<?= $b ?>/admin/categories/<?= (int) ($cat->id ?? 0) ?>/delete"
          onsubmit="return confirm('Delete this category? This will unlink any listings using it.')">
      <input type="hidden" name="_csrf" value="<?= \Luster\Core\View::e($csrf_token ?? '') ?>">
      <button type="submit" class="btn btn-danger">Delete Category</button>
    </form>
  <?php endif; ?>
</div>
