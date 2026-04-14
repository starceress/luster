<?php
use Luster\Core\View;
/**
 * Admin — Edit a single additional field.
 *
 * Variables provided by FieldController::edit() via layout():
 * @var \Luster\Models\Listing      $listing
 * @var \Luster\Models\AdditionalField $field
 * @var string $options    Current options string (empty when not set)
 * @var string $error      Value of ?error= query param (empty when absent)
 * @var string $base_url
 * @var string $csrf_token
 */
$b       = rtrim(View::e($base_url), '/');
$id      = (int) $listing->id;
$encName = urlencode($field->name);
?>

<!-- Breadcrumb -->
<p class="page-back">
  <a href="<?= $b ?>/admin/listings/<?= $id ?>/fields" class="link">
    &larr; <?= View::e($listing->name) ?> &mdash; Fields
  </a>
</p>


<?php if ($error !== ''): ?>
  <div class="alert alert-error" role="alert"><?= View::e($error) ?></div>
<?php endif; ?>

<div class="card">
  <form method="POST"
        action="<?= $b ?>/admin/listings/<?= $id ?>/fields/<?= $encName ?>/edit">
    <input type="hidden" name="_csrf"      value="<?= View::e($csrf_token) ?>">
    <input type="hidden" name="field_name" value="<?= View::e($field->name) ?>">

    <!-- Field Name (read-only display) -->
    <div class="form-group">
      <label class="form-label">
        Field Name
        <small class="text-dim" style="font-weight:400"> (cannot be changed)</small>
      </label>
      <div class="ro-field"><?= View::e($field->name) ?></div>
    </div>

    <!-- Label -->
    <div class="form-group">
      <label class="form-label" for="ef-label">Label <span>*</span></label>
      <input type="text" id="ef-label" name="label" class="form-input"
             maxlength="100" required
             value="<?= View::e($field->label) ?>">
    </div>

    <!-- Type -->
    <div class="form-group">
      <label class="form-label" for="ef-type">Type</label>
      <div class="select-wrap">
        <select id="ef-type" name="type" class="form-select">
          <option value="text"     <?= $field->type === 'text'     ? 'selected' : '' ?>>Text</option>
          <option value="dropdown" <?= $field->type === 'dropdown' ? 'selected' : '' ?>>Drop Down</option>
          <option value="checkbox" <?= $field->type === 'checkbox' ? 'selected' : '' ?>>Checkbox</option>
          <option value="radio"    <?= $field->type === 'radio'    ? 'selected' : '' ?>>Radio Buttons</option>
        </select>
      </div>
    </div>

    <!-- Options (shown only when type = dropdown / checkbox / radio) -->
    <div id="ef-options-row" class="form-group" style="display:none">
      <label class="form-label" for="ef-options">
        Options
        <small class="text-dim" style="font-weight:400"> (comma-separated, for Drop Down, Checkbox, and Radio types)</small>
      </label>
      <textarea id="ef-options" name="options" class="form-textarea" rows="3"
                placeholder="e.g. Option A, Option B, Option C"><?= View::e($options) ?></textarea>
    </div>

    <!-- Required -->
    <div class="form-group">
      <label class="form-label">Required</label>
      <label class="form-check">
        <input type="checkbox" id="ef-required" name="required" value="1"
               <?= $field->required ? 'checked' : '' ?>>
        <label class="form-check-label" for="ef-required">Members must fill in this field</label>
      </label>
    </div>

    <div class="btn-row">
      <button type="submit" class="btn btn-primary">Save Changes</button>
      <a href="<?= $b ?>/admin/listings/<?= $id ?>/fields" class="btn btn-ghost">Cancel</a>
    </div>

  </form>
</div>

