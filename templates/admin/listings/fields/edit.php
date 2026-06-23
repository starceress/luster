<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Edit a single additional field for a listing. Allows changing the field's label,
 type (text, textarea, select), required status, and select options. The field name
 itself cannot be changed because it corresponds to a database column.
*/

declare(strict_types=1);

use Luster\Core\View;

$b       = rtrim(View::e($base_url), '/');
$id      = (int) $listing->id;
$encName = urlencode($field->name);
?>

<div class="toolbar">
  <a href="<?= $b ?>/admin/listings/<?= $id ?>/fields" class="link">
    &larr; <?= View::e($listing->title) ?> &mdash; Fields
  </a>
</div>

<?php if ($error !== ''): ?>
  <div class="alert alert-error" role="alert"><?= View::e($error) ?></div>
<?php endif; ?>

<form method="POST"
      action="<?= $b ?>/admin/listings/<?= $id ?>/fields/<?= $encName ?>/edit"
      class="js-warn-unsaved">
  <input type="hidden" name="_csrf" value="<?= View::e($csrf_token) ?>">
  <input type="hidden" name="field_name" value="<?= View::e($field->name) ?>">

  <div class="card card--gradient">
    <div class="panel-header">
      <div class="panel-header-icon panel-header-icon--filled"><?= View::icon('tag') ?></div>
      <div class="panel-header-meta">
        <div class="panel-header-title">Edit Field</div>
        <div class="panel-header-sub">Update the question members see and how they answer it.</div>
      </div>
    </div>

    <div class="card-body">
      <div class="field-row field-row--top">
        <span class="field-label">Field Name</span>
        <div>
          <div class="readonly-field"><?= View::e($field->name) ?></div>
          <p class="form-hint">This internal name cannot be changed because existing member answers are saved under it.</p>
        </div>
      </div>

      <div class="field-row field-row--top">
        <label class="field-label" for="ef-label">Label <span class="req">*</span></label>
        <div>
          <input type="text" id="ef-label" name="label" class="form"
                 maxlength="100" required
                 value="<?= View::e($field->label) ?>">
          <p class="form-hint">This is the text members see on forms and profile pages.</p>
        </div>
      </div>

      <div class="field-row field-row--top">
        <label class="field-label" for="ef-type">Type</label>
        <div>
          <div class="select-wrap">
            <select id="ef-type" name="type" >
              <option value="text"     <?= $field->type === 'text' ? 'selected' : '' ?>>Text Field</option>
              <option value="dropdown" <?= $field->type === 'dropdown' ? 'selected' : '' ?>>Dropdown</option>
              <option value="checkbox" <?= $field->type === 'checkbox' ? 'selected' : '' ?>>Checkbox</option>
              <option value="radio"    <?= $field->type === 'radio' ? 'selected' : '' ?>>Radio Button</option>
            </select>
          </div>
          <p class="form-hint">Choose how members answer this field.</p>
        </div>
      </div>

      <div id="ef-options-row" class="field-row field-row--top is-hidden">
        <label class="field-label" for="ef-options">Options</label>
        <div>
          <textarea id="ef-options" name="options" class="form" rows="3"
                    placeholder="e.g. Option A, Option B, Option C"><?= View::e($options) ?></textarea>
          <p class="form-hint">For dropdown, checkbox, and radio fields, write the choices separated by commas.</p>
        </div>
      </div>

      <div class="field-row field-row--top">
        <span class="field-label">Required</span>
        <div>
          <div class="flex-row">
            <label class="form-check">
              <input type="radio" name="required" value="1" <?= $field->required ? 'checked' : '' ?>>
              <span class="form-check-label">Yes</span>
            </label>
            <label class="form-check">
              <input type="radio" name="required" value="0" <?= !$field->required ? 'checked' : '' ?>>
              <span class="form-check-label">No</span>
            </label>
          </div>
          <p class="form-hint">Choose Yes when members must answer this before they can submit the form.</p>
        </div>
      </div>
    </div>

    <div class="composer-footer">
      <button type="submit" class="btn btn-primary">Save Changes</button>
      <a href="<?= $b ?>/admin/listings/<?= $id ?>/fields" class="btn btn-ghost">Cancel</a>
    </div>
  </div>
</form>
