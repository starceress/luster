<?php declare(strict_types=1);
use Luster\Core\View;
$b  = rtrim(View::e($base_url ?? ''), '/');
$fe = $field_errors ?? [];
$currentSource = $cat->source ?? 'custom';
?>

<div class="page-topbar">
  <a href="<?= $b ?>/admin/categories" class="link">
    &larr; Back to Categories
  </a>
</div>

<?php if (($success ?? '') !== ''): ?>
  <div class="alert alert-success"><?= View::e($success ?? '') ?></div>
<?php endif; ?>
<?php if (($error ?? '') !== ''): ?>
  <div class="alert alert-error"><?= View::e($error ?? '') ?></div>
<?php endif; ?>

<form method="POST" id="edit-form" class="js-warn-unsaved">
  <input type="hidden" name="_csrf" value="<?= View::e($csrf_token ?? '') ?>">
  <input type="hidden" name="current_source" value="<?= View::e($currentSource) ?>">

  <div class="gradient-card" style="margin-bottom:1rem">
    <div class="listing-field-section">

      <!-- Category Name -->
      <div class="listing-field-row<?= !empty($fe['name']) ? ' has-error' : '' ?>">
        <label class="listing-field-label" for="cat-name">
          Category Name <span class="req">*</span>
        </label>
        <div>
          <input id="cat-name" type="text" name="name" class="form-input"
                 value="<?= View::e($cat->name ?? '') ?>" required maxlength="150">
          <?php if (!empty($fe['name'])): ?>
            <p class="field-error"><?= View::e($fe['name'][0]) ?></p>
          <?php endif; ?>
        </div>
      </div>

      <!-- Parent Category -->
      <div class="listing-field-row listing-field-row--top">
        <label class="listing-field-label" for="cat-parent">Parent Category</label>
        <div>
          <div class="select-wrap">
            <select id="cat-parent" name="parent_id" class="form-select">
              <option value="">None</option>
              <?php foreach ($all_cats ?? [] as $c): ?>
                <?php if ($c->parent_id === null && $c->id !== ($cat->id ?? 0)): ?>
                  <option value="<?= (int) $c->id ?>" <?= (int) ($cat->parent_id ?? 0) === (int) $c->id ? 'selected' : '' ?>>
                    <?= View::e($c->name ?? '') ?>
                  </option>
                <?php endif; ?>
              <?php endforeach; ?>
            </select>
          </div>
          <p class="form-hint">Optional. Select a parent to nest this as a sub-category.</p>
        </div>
      </div>

      <!-- Change Network? -->
      <div class="listing-field-row listing-field-row--top">
        <label class="listing-field-label" for="add-network-toggle">Change Network?</label>
        <div>
          <div class="select-wrap">
            <select id="add-network-toggle" name="add_network" class="form-select">
              <option value="no">Keep current (<?= View::e($currentSource) ?>)</option>
              <option value="yes">Change network</option>
            </select>
          </div>
          <?php if ($currentSource !== 'custom'): ?>
            <div style="display:flex;gap:.5rem;align-items:center;margin-top:.5rem">
              <span class="badge badge-<?= View::e(strtolower($currentSource)) ?>"><?= View::e(strtoupper($currentSource)) ?></span>
              <span style="font-size:.78rem;color:var(--text-dim)">Currently assigned to network: <?= View::e($currentSource) ?></span>
            </div>
          <?php endif; ?>
        </div>
      </div>

      <!-- New Network Name (hidden until toggled) -->
      <div class="listing-field-row" id="network-name-row" style="display:none">
        <label class="listing-field-label" for="cat-network">New Network Name</label>
        <input id="cat-network" type="text" name="network_name" class="form-input"
               maxlength="50" placeholder="e.g. MyNetwork">
      </div>

    </div><!-- /.listing-field-section -->

    <div class="composer-footer">
      <button type="submit" class="btn btn-primary">Save Changes</button>
      <button type="button" class="btn btn-ghost js-form-reset">Clear</button>
      <a href="<?= $b ?>/admin/categories" class="btn btn-ghost">Cancel</a>
    </div>
  </div>
</form>

<?php if (!in_array($currentSource, ['tfl', 'tafl'], true)): ?>
  <form method="POST" action="<?= $b ?>/admin/categories/<?= (int) ($cat->id ?? 0) ?>/delete"
        data-del-modal data-del-entity="category"
        data-del-name="<?= View::e($cat->name ?? '') ?>">
    <input type="hidden" name="_csrf" value="<?= View::e($csrf_token ?? '') ?>">
    <button type="submit" class="btn btn-danger">Delete Category</button>
  </form>
<?php endif; ?>
