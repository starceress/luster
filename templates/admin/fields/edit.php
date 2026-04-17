<?php

declare(strict_types=1);

use Luster\Core\View;

/**
 * Admin - Edit a single additional field.
 *
 * Variables provided by FieldController::edit() via layout():
 * @var \Luster\Models\Listing $listing
 * @var \Luster\Models\AdditionalField $field
 * @var string $options
 * @var string $error
 * @var string $base_url
 * @var string $csrf_token
 */

$b       = rtrim(View::e($base_url), '/');
$id      = (int) $listing->id;
$encName = urlencode($field->name);
?>

<div class="page-topbar">
  <a href="<?= $b ?>/admin/listings/<?= $id ?>/fields" class="link">
    &larr; <?= View::e($listing->name) ?> &mdash; Fields
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

  <div class="gradient-card">
    <div class="pc-head">
      <div class="pc-head-icon pc-head-icon-bg"><?= View::icon('tag') ?></div>
      <div class="pc-head-meta">
        <div class="pc-head-title">Edit Field</div>
        <div class="pc-head-sub">Update how this field appears and behaves for members.</div>
      </div>
    </div>

    <div class="listing-field-section">
      <div class="listing-field-row listing-field-row--top">
        <span class="listing-field-label">Field Name</span>
        <div>
          <div class="ro-field"><?= View::e($field->name) ?></div>
          <p class="form-hint">Field names cannot be changed because they map to stored member data.</p>
        </div>
      </div>

      <div class="listing-field-row listing-field-row--top">
        <label class="listing-field-label" for="ef-label">Label <span class="req">*</span></label>
        <div>
          <input type="text" id="ef-label" name="label" class="form-input"
                 maxlength="100" required
                 value="<?= View::e($field->label) ?>">
        </div>
      </div>

      <div class="listing-field-row listing-field-row--top">
        <label class="listing-field-label" for="ef-type">Type</label>
        <div>
          <div class="select-wrap">
            <select id="ef-type" name="type" class="form-select">
              <option value="text"     <?= $field->type === 'text' ? 'selected' : '' ?>>Text</option>
              <option value="dropdown" <?= $field->type === 'dropdown' ? 'selected' : '' ?>>Drop Down</option>
              <option value="checkbox" <?= $field->type === 'checkbox' ? 'selected' : '' ?>>Checkbox</option>
              <option value="radio"    <?= $field->type === 'radio' ? 'selected' : '' ?>>Radio Buttons</option>
            </select>
          </div>
        </div>
      </div>

      <div id="ef-options-row" class="listing-field-row listing-field-row--top is-hidden">
        <label class="listing-field-label" for="ef-options">Options</label>
        <div>
          <textarea id="ef-options" name="options" class="form-textarea" rows="3"
                    placeholder="e.g. Option A, Option B, Option C"><?= View::e($options) ?></textarea>
          <p class="form-hint">Comma-separated values for drop down, checkbox, and radio button fields.</p>
        </div>
      </div>

      <div class="listing-field-row listing-field-row--top">
        <span class="listing-field-label">Required</span>
        <div>
          <label class="form-check">
            <input type="checkbox" id="ef-required" name="required" value="1"
                   <?= $field->required ? 'checked' : '' ?>>
            <span class="form-check-label">Members must fill in this field</span>
          </label>
        </div>
      </div>
    </div>

    <div class="composer-footer">
      <button type="submit" class="btn btn-primary">Save Changes</button>
      <a href="<?= $b ?>/admin/listings/<?= $id ?>/fields" class="btn btn-ghost">Cancel</a>
    </div>
  </div>
</form>
