<?php

declare(strict_types=1);

use Luster\Core\View;

/**
 * Admin - Additional Fields index for one listing.
 *
 * Variables provided by FieldController::index() via layout():
 * @var \Luster\Models\Listing $listing
 * @var \Luster\Models\AdditionalField[] $fields
 * @var string $success
 * @var string $error
 * @var string $base_url
 * @var string $csrf_token
 */

$b  = rtrim(View::e($base_url), '/');
$id = (int) $listing->id;
?>

<div class="page-topbar">
  <a href="<?= $b ?>/admin/listings/<?= $id ?>" class="link">
    &larr; <?= View::e($listing->title) ?>
  </a>
</div>

<?php if ($success !== ''): ?>
  <div class="alert alert-success" role="status"><?= View::e($success) ?></div>
<?php endif; ?>
<?php if ($error !== ''): ?>
  <div class="alert alert-error" role="alert"><?= View::e($error) ?></div>
<?php endif; ?>

<div class="card">
  <div class="card-head">Existing Fields</div>
  <?php if (empty($fields)): ?>
    <div class="empty">
      <div class="empty-icon"><?= View::icon('tag', ['width' => 32, 'height' => 32]) ?></div>
      <div class="empty-title">No additional fields defined yet.</div>
      <div>Extra member questions you add or import will appear here.</div>
    </div>
  <?php else: ?>
    <div class="table-wrap">
      <table class="data-table">
        <thead>
          <tr>
            <th>Label</th>
            <th>Field Name</th>
            <th>Type</th>
            <th>Required</th>
            <th class="act-col">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($fields as $field): ?>
            <?php $safeName = View::e($field->name); ?>
            <tr>
              <td><?= View::e($field->label) ?></td>
              <td><code><?= $safeName ?></code></td>
              <td class="text-muted"><?= View::e($field->type) ?></td>
              <td>
                <?php if ($field->required): ?>
                  <span class="badge badge-current">Yes</span>
                <?php else: ?>
                  <span class="badge badge-inactive">No</span>
                <?php endif; ?>
              </td>
              <td class="act-col">
                <a href="<?= $b ?>/admin/listings/<?= $id ?>/fields/<?= urlencode($field->name) ?>/edit"
                   class="btn btn-tertiary btn-sm">Edit</a>

                <form method="POST"
                      action="<?= $b ?>/admin/listings/<?= $id ?>/fields/<?= urlencode($field->name) ?>/delete"
                      class="inline-form-gap"
                      data-del-modal data-del-entity="field"
                      data-del-name="<?= $safeName ?>">
                  <input type="hidden" name="_csrf" value="<?= View::e($csrf_token) ?>">
                  <input type="hidden" name="field_name" value="<?= $safeName ?>">
                  <button type="submit" class="btn btn-danger btn-sm">Delete</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<form method="POST" action="<?= $b ?>/admin/listings/<?= $id ?>/fields/add" class="js-warn-unsaved">
  <input type="hidden" name="_csrf" value="<?= View::e($csrf_token) ?>">

  <div class="gradient-card">
    <div class="pc-head">
      <div class="pc-head-icon pc-head-icon-bg"><?= View::icon('add') ?></div>
      <div class="pc-head-meta">
        <div class="pc-head-title">Add New Field</div>
        <div class="pc-head-sub">Add an extra question to this listing's join and update forms.</div>
      </div>
    </div>

    <div class="listing-field-section">
      <div class="listing-field-row listing-field-row--top">
        <label class="listing-field-label" for="af-name">Field Name <span class="req">*</span></label>
        <div>
          <input type="text" class="form" id="af-name" name="name"
                 maxlength="30" pattern="[a-zA-Z0-9_]+"
                 placeholder="e.g. fave_song" required
                 title="Letters, numbers and underscores only">
          <p class="form-hint">Short internal name for this field. Use letters, numbers, and underscores only. Members will see the label below instead.</p>
        </div>
      </div>

      <div class="listing-field-row listing-field-row--top">
        <label class="listing-field-label" for="af-label">Label <span class="req">*</span></label>
        <div>
          <input type="text" class="form" id="af-label" name="label"
                 maxlength="100" placeholder="e.g. Favourite Song" required>
          <p class="form-hint">This is the text members will see on forms and profile pages.</p>
        </div>
      </div>

      <div class="listing-field-row listing-field-row--top">
        <label class="listing-field-label" for="af-type">Type</label>
        <div>
          <div class="select-wrap">
            <select  id="af-type" name="type">
              <option value="text">Text</option>
              <option value="dropdown">Drop Down</option>
              <option value="checkbox">Checkbox</option>
              <option value="radio">Radio Button</option>
            </select>
          </div>
          <p class="form-hint">Choose how members will answer this field.</p>
        </div>
      </div>

      <div id="af-options-row" class="listing-field-row listing-field-row--top is-hidden">
        <label class="listing-field-label" for="af-options">Options</label>
        <div>
          <textarea class="form" id="af-options" name="options" rows="2"
                    placeholder="e.g. Option A, Option B, Option C"></textarea>
          <p class="form-hint">For dropdown, checkbox, and radio fields, write the choices separated by commas.</p>
        </div>
      </div>

      <div class="listing-field-row listing-field-row--top">
        <span class="listing-field-label">Required</span>
        <div>
          <div class="flex-row">
            <label class="form-check">
              <input type="radio" name="required" value="1">
              <span class="form-check-label">Yes</span>
            </label>
            <label class="form-check">
              <input type="radio" name="required" value="0" checked>
              <span class="form-check-label">No</span>
            </label>
          </div>
          <p class="form-hint">Choose Yes when members must answer this before they can submit the form.</p>
        </div>
      </div>
    </div>

    <div class="composer-footer">
      <button type="submit" class="btn btn-primary">Add Field</button>
      <button type="button" class="btn btn-ghost js-form-reset">Clear</button>
      <a href="<?= $b ?>/admin/listings/<?= $id ?>" class="btn btn-ghost">Cancel</a>
    </div>
  </div>
</form>
