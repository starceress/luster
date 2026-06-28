<?php
/*
 Luster -- https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Admin category add form. Provides fields for creating a new listing category,
 including name, source network, slug, and optional parent category.
*/
declare(strict_types=1);
use Luster\Core\View;
$b  = rtrim(View::e($base_url ?? ''), '/');
$fe = $field_errors ?? [];
?>

<div class="toolbar">
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

<form method="POST" id="add-form" class="js-warn-unsaved">
  <input type="hidden" name="_csrf" value="<?= View::e($csrf_token ?? '') ?>">

  <div class="card card--gradient">
    <div class="card-body">

      <div class="field-row<?= !empty($fe['name']) ? ' has-error' : '' ?>">
        <label class="field-label" for="cat-name">
          Category Name <span class="req">*</span>
        </label>
        <div>
          <input id="cat-name" type="text" name="name" class="form"
                 maxlength="150" required
                 placeholder="e.g. Anime"
                 value="<?= View::e($old['name'] ?? '') ?>">
          <?php if (!empty($fe['name'])): ?>
            <p class="field-error"><?= View::e($fe['name'][0]) ?></p>
          <?php endif; ?>
        </div>
      </div>

      <div class="field-row">
        <label class="field-label" for="cat-slug">URL Slug</label>
        <div>
          <input id="cat-slug" type="text" name="slug" class="form"
                 maxlength="100"
                 placeholder="auto-generated from name if left blank"
                 value="<?= View::e($old['slug'] ?? '') ?>">
          <p class="form-hint">Used in <code>?cat=slug</code> links. Auto-generated from the name if left blank. <strong>Do not change after publishing.</strong></p>
        </div>
      </div>

      <div class="field-row">
        <label class="field-label" for="cat-parent">Parent Category</label>
        <div>
          <div class="select-wrap">
            <select id="cat-parent" name="parent_id">
              <option value="">None (top-level)</option>
              <?php foreach ($indented_list ?? [] as $cat): ?>
                <?php if ($cat['depth'] === 0): ?>
                  <option value="<?= (int) $cat['id'] ?>"
                          <?= (string) ($old['parent_id'] ?? '') === (string) $cat['id'] ? 'selected' : '' ?>>
                    <?= View::e($cat['indent_name']) ?>
                  </option>
                <?php endif; ?>
              <?php endforeach; ?>
            </select>
          </div>
          <p class="form-hint">Max depth is one level. Only top-level categories are available as parents.</p>
        </div>
      </div>

      <div class="field-row">
        <label class="field-label" for="add-network-toggle">Add to a Network?</label>
        <div>
          <div class="select-wrap">
            <select id="add-network-toggle" name="add_network">
              <option value="no">No - use Custom</option>
              <option value="yes">Yes - specify network</option>
            </select>
          </div>
          <p class="form-hint">Use Custom to keep this category outside the built-in networks.</p>
        </div>
      </div>

      <div class="field-row is-hidden" id="network-name-row">
        <label class="field-label" for="cat-network">Network Name</label>
        <div>
          <input id="cat-network" type="text" name="network_name" class="form"
                 maxlength="50" placeholder="e.g. MyNetwork">
        </div>
      </div>

    </div>

    <div class="composer-footer">
      <button type="submit" class="btn btn-primary">Add Category</button>
      <button type="button" class="btn btn-ghost js-form-reset">Clear</button>
      <a href="<?= $b ?>/admin/categories" class="btn btn-ghost">Cancel</a>
    </div>
  </div>
</form>
