<?php declare(strict_types=1);
use Luster\Core\View;
$b  = rtrim(View::e($base_url ?? ''), '/');
$fe = $field_errors ?? [];
$currentSource = $cat->source ?? 'custom';
$currentSourceLabel = in_array($currentSource, ['tfl', 'tafl'], true) ? strtoupper($currentSource) : $currentSource;

$networkOptions = [
    ['value' => 'tfl',  'label' => 'TFL'],
    ['value' => 'tafl', 'label' => 'TAFL'],
];
$customNets = $custom_networks ?? [];
sort($customNets, SORT_STRING | SORT_FLAG_CASE);
foreach ($customNets as $net) {
    $networkOptions[] = ['value' => $net, 'label' => ucfirst($net)];
}
$currentSlug   = $cat->slug ?? '';
$currentParent = isset($cat->parent_id) && $cat->parent_id !== null ? (int) $cat->parent_id : null;
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
    <div class="pc-section">

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

      <div class="listing-field-row">
        <label class="listing-field-label" for="cat-slug">URL Slug</label>
        <div>
          <input id="cat-slug" type="text" name="slug" class="form"
                 value="<?= View::e($currentSlug) ?>" maxlength="100">
          <p class="form-hint"><strong>Warning:</strong> changing the slug breaks existing <code>?cat=<?= View::e($currentSlug ?: 'slug') ?></code> links. Leave blank to keep the current slug.</p>
        </div>
      </div>

      <div class="listing-field-row listing-field-row--top">
        <label class="listing-field-label" for="cat-parent">Parent Category</label>
        <div>
          <div class="select-wrap">
            <select id="cat-parent" name="parent_id">
              <option value="">None (top-level)</option>
              <?php foreach ($indented_list ?? [] as $item): ?>
                <?php if ($item['depth'] === 0 && (int) $item['id'] !== (int) ($cat->id ?? 0)): ?>
                  <option value="<?= (int) $item['id'] ?>"
                          <?= $currentParent === (int) $item['id'] ? 'selected' : '' ?>>
                    <?= View::e($item['indent_name']) ?>
                  </option>
                <?php endif; ?>
              <?php endforeach; ?>
            </select>
          </div>
          <p class="form-hint">Max depth is one level. Only top-level categories can be parents.</p>
        </div>
      </div>

      <div class="listing-field-row listing-field-row--top">
        <label class="listing-field-label" for="add-network-toggle">Change Network?</label>
        <div>
          <div class="select-wrap">
            <select id="add-network-toggle" name="add_network">
              <option value="no">Keep current (<?= View::e($currentSourceLabel) ?>)</option>
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

      <div class="listing-field-row listing-field-row--top is-hidden" id="replacement-network-row">
        <label class="listing-field-label" for="replacement-network">New Network</label>
        <div>
          <div class="cat-row">
            <div class="select-wrap" data-role="cat-network-wrap">
              <select id="replacement-network" name="replacement_network">
                <?php foreach ($networkOptions as $opt): ?>
                  <option value="<?= View::e($opt['value']) ?>"><?= View::e($opt['label']) ?></option>
                <?php endforeach; ?>
                <option value="other">Other (add new)</option>
              </select>
            </div>
            <input id="cat-network" type="text" name="network_name" data-role="cat-network-other"
                   maxlength="50" placeholder="New network name" style="display:none">
          </div>
          <p class="form-hint">Choose an existing network, or "Other (add new)" to create one.</p>
        </div>
      </div>

    </div>

    <div class="composer-footer">
      <button type="submit" class="btn btn-primary">Save Changes</button>
      <button type="button" class="btn btn-ghost js-form-reset">Clear</button>
      <a href="<?= $b ?>/admin/categories" class="btn btn-ghost">Cancel</a>
      <span class="composer-footer-spacer"></span>
      <button type="submit"
              form="del-category-<?= (int) ($cat->id ?? 0) ?>"
              class="btn btn-danger">Delete Category</button>
    </div>
  </div>
</form>

<form id="del-category-<?= (int) ($cat->id ?? 0) ?>" method="POST"
      action="<?= $b ?>/admin/categories/<?= (int) ($cat->id ?? 0) ?>/delete"
      data-del-modal data-del-entity="category"
      data-del-name="<?= View::e($cat->name ?? '') ?>">
  <input type="hidden" name="_csrf" value="<?= View::e($csrf_token ?? '') ?>">
</form>
