<?php declare(strict_types=1);
$b = rtrim(\Luster\Core\View::e($base_url ?? ''), '/');
?>

<?php if (($success ?? '') !== ''): ?>
  <div class="alert alert-success"><?= \Luster\Core\View::e($success ?? '') ?></div>
<?php endif; ?>
<?php if (($error ?? '') !== ''): ?>
  <div class="alert alert-error"><?= \Luster\Core\View::e($error ?? '') ?></div>
<?php endif; ?>

<p style="margin:0 0 .75rem">
  <a href="<?= $b ?>/admin/categories" class="neb-link">&larr; Back to Categories</a>
</p>

<div class="form-card" style="max-width:500px">
  <h2 style="margin:0 0 1.25rem;font-size:1.1rem;font-weight:600;color:var(--text)">Add New Category</h2>
  <form method="POST" id="add-form">
    <input type="hidden" name="_csrf" value="<?= \Luster\Core\View::e($csrf_token ?? '') ?>">

    <div class="form-group">
      <label class="form-label">Category Name</label>
      <input type="text" name="name" class="form-input" required maxlength="150">
    </div>

    <div class="form-group">
      <label class="form-label">Parent Category <span style="font-weight:400;color:var(--text-dim)">(optional)</span></label>
      <div class="select-wrap">
        <select name="parent_id" class="form-select">
          <option value="">None</option>
          <?php foreach ($all_cats ?? [] as $c): ?>
            <?php if ($c->parent_id === null): ?>
              <option value="<?= (int) $c->id ?>"><?= \Luster\Core\View::e($c->name ?? '') ?></option>
            <?php endif; ?>
          <?php endforeach; ?>
        </select>
      </div>
    </div>

    <div class="form-group">
      <label class="form-label">Add to a Network?</label>
      <div class="select-wrap">
        <select name="add_network" id="add-network-toggle" class="form-select"
                onchange="document.getElementById('network-name-row').style.display=this.value==='yes'?'block':'none'">
          <option value="no">No — use Custom</option>
          <option value="yes">Yes — specify network</option>
        </select>
      </div>
    </div>

    <div class="form-group" id="network-name-row" style="display:none">
      <label class="form-label">Network Name</label>
      <input type="text" name="network_name" class="form-input" maxlength="50" placeholder="e.g. MyNetwork">
    </div>

    <div class="btn-row">
      <button type="submit" class="btn btn-primary">Add Category</button>
      <button type="button" class="btn btn-ghost" onclick="document.getElementById('add-form').reset()">Clear</button>
      <a href="<?= $b ?>/admin/categories" class="btn btn-ghost">Cancel</a>
    </div>
  </form>
</div>
