<?php declare(strict_types=1);
use Luster\Core\View;
$b  = rtrim(View::e($base_url ?? ''), '/');
$fe = $field_errors ?? [];
?>

<?php if (($success ?? '') !== ''): ?>
  <div class="alert alert-success"><?= View::e($success ?? '') ?></div>
<?php endif; ?>
<?php if (($error ?? '') !== ''): ?>
  <div class="alert alert-error"><?= View::e($error ?? '') ?></div>
<?php endif; ?>

<p class="page-back">
  <a href="<?= $b ?>/admin/categories" class="link">&larr; Back to Categories</a>
</p>

<div class="card card-padded" style="max-width:500px">
  <h2 class="card-title" style="margin-bottom:1.25rem">Add New Category</h2>
  <form method="POST" id="add-form" class="js-warn-unsaved">
    <input type="hidden" name="_csrf" value="<?= View::e($csrf_token ?? '') ?>">

    <div class="form-group<?= !empty($fe['name']) ? ' has-error' : '' ?>">
      <label class="form-label">Category Name</label>
      <input type="text" name="name" class="form-input" required maxlength="150">
      <?php if (!empty($fe['name'])): ?><p class="field-error"><?= View::e($fe['name'][0]) ?></p><?php endif; ?>
    </div>

    <div class="form-group">
      <label class="form-label">Parent Category <span style="font-weight:400;color:var(--text-dim)">(optional)</span></label>
      <div class="select-wrap">
        <select name="parent_id" class="form-select">
          <option value="">None</option>
          <?php foreach ($all_cats ?? [] as $c): ?>
            <?php if ($c->parent_id === null): ?>
              <option value="<?= (int) $c->id ?>"><?= View::e($c->name ?? '') ?></option>
            <?php endif; ?>
          <?php endforeach; ?>
        </select>
      </div>
    </div>

    <div class="form-group">
      <label class="form-label">Add to a Network?</label>
      <div class="select-wrap">
        <select name="add_network" id="add-network-toggle" class="form-select">
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
      <button type="button" class="btn btn-ghost js-form-reset">Clear</button>
      <a href="<?= $b ?>/admin/categories" class="btn btn-ghost">Cancel</a>
    </div>
  </form>
</div>
