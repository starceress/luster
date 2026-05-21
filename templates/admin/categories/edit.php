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

  <div class="gradient-card">
    <div class="listing-field-section">
      <div class="listing-field-row<?= !empty($fe['name']) ? ' has-error' : '' ?>">
        <label class="listing-field-label" for="cat-name">
          Category Name <span class="req">*</span>
        </label>
        <div>
          <input id="cat-name" type="text" name="name" class="form"
                 value="<?= View::e($cat->name ?? '') ?>" required maxlength="150">
          <?php if (!empty($fe['name'])): ?>
            <p class="field-error"><?= View::e($fe['name'][0]) ?></p>
          <?php endif; ?>
        </div>
      </div>

      <div class="listing-field-row listing-field-row--top">
        <label class="listing-field-label" for="add-network-toggle">Change Network?</label>
        <div>
          <div class="select-wrap">
            <select id="add-network-toggle" name="add_network" >
              <option value="no">Keep current (<?= View::e($currentSource) ?>)</option>
              <option value="yes">Change network</option>
            </select>
          </div>
          <p class="form-hint">Leave this set to keep the current network assignment.</p>
          <?php if ($currentSource !== 'custom'): ?>
            <div class="current-network-row">
              <span class="badge badge-<?= View::e(strtolower($currentSource)) ?>"><?= View::e(strtoupper($currentSource)) ?></span>
              <span class="text-dim-copy-sm">Currently assigned to network: <?= View::e($currentSource) ?></span>
            </div>
          <?php endif; ?>
        </div>
      </div>

      <div class="listing-field-row listing-field-row--top is-hidden" id="network-name-row">
        <label class="listing-field-label" for="cat-network">New Network Name</label>
        <div>
          <input id="cat-network" type="text" name="network_name" class="form"
                 maxlength="50" placeholder="e.g. MyNetwork">
        </div>
      </div>
    </div>

    <div class="composer-footer">
      <button type="submit" class="btn btn-primary">Save Changes</button>
      <button type="button" class="btn btn-ghost js-form-reset">Clear</button>
      <a href="<?= $b ?>/admin/categories" class="btn btn-ghost">Cancel</a>
    </div>
  </div>
</form>

<form method="POST" action="<?= $b ?>/admin/categories/<?= (int) ($cat->id ?? 0) ?>/delete"
      data-del-modal data-del-entity="category"
      data-del-name="<?= View::e($cat->name ?? '') ?>">
  <input type="hidden" name="_csrf" value="<?= View::e($csrf_token ?? '') ?>">
  <button type="submit" class="btn btn-danger">Delete Category</button>
</form>
