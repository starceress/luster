<?php
use Luster\Core\View;
/**
 * Admin — Additional Fields index for one listing.
 *
 * Variables provided by FieldController::index() via layout():
 * @var \Luster\Models\Listing        $listing
 * @var \Luster\Models\AdditionalField[] $fields
 * @var string $success   Value of ?success= query param (empty when absent)
 * @var string $error     Value of ?error=   query param (empty when absent)
 * @var string $base_url
 * @var string $csrf_token
 */
$b  = rtrim(View::e($base_url), '/');
$id = (int) $listing->id;
?>

<!-- Breadcrumb -->
<p class="page-back">
  <a href="<?= $b ?>/admin/listings/<?= $id ?>" class="link">
    &larr; <?= View::e($listing->name) ?>
  </a>
</p>

<!-- Flash messages -->
<?php if ($success !== ''): ?>
  <div class="alert alert-success" role="status"><?= View::e($success) ?></div>
<?php endif; ?>
<?php if ($error !== ''): ?>
  <div class="alert alert-error" role="alert"><?= View::e($error) ?></div>
<?php endif; ?>

<!-- Fields table -->
<?php if (empty($fields)): ?>
  <div class="card">
    <div class="empty">
      <svg class="empty-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="M3 10h18M8 5v14"/></svg>
      No additional fields defined yet. Use the form below to add one.
    </div>
  </div>
<?php else: ?>
  <div class="card">
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
            <td class="text-strong"><?= View::e($field->label) ?></td>
            <td><code class="neb-code"><?= $safeName ?></code></td>
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
                    style="display:inline;margin-left:.25rem"
                    data-del-modal data-del-entity="field"
                    data-del-name="<?= $safeName ?>">
                <input type="hidden" name="_csrf"      value="<?= View::e($csrf_token) ?>">
                <input type="hidden" name="field_name" value="<?= $safeName ?>">
                <button type="submit" class="btn btn-danger btn-sm">Delete</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  </div>
<?php endif; ?>

<!-- Add New Field form -->
<div class="card" style="padding:1.25rem 1.5rem;margin-top:1.5rem">
  <h3 class="card-title" style="margin-bottom:1rem">Add New Field</h3>
  <form method="POST" action="<?= $b ?>/admin/listings/<?= $id ?>/fields/add">
    <input type="hidden" name="_csrf" value="<?= View::e($csrf_token) ?>">
    <div class="add-grid">

      <!-- Field Name -->
      <div class="fg">
        <label for="af-name">
          Field Name
          <span class="form-hint" style="display:inline">(letters, numbers, underscores)</span>
        </label>
        <input type="text" id="af-name" name="name"
               maxlength="30" pattern="[a-zA-Z0-9_]+"
               placeholder="e.g. fave_song" required
               title="Letters, numbers and underscores only">
      </div>

      <!-- Label -->
      <div class="fg">
        <label for="af-label">Label</label>
        <input type="text" id="af-label" name="label"
               maxlength="100" placeholder="e.g. Favourite Song" required>
      </div>

      <!-- Type -->
      <div class="fg">
        <label for="af-type">Type</label>
        <div class="select-wrap">
        <select id="af-type" name="type">
          <option value="text">text</option>
          <option value="dropdown">Drop Down</option>
          <option value="checkbox">checkbox</option>
        </select>
        </div>
      </div>

      <!-- Options (shown only when type = dropdown/checkbox) -->
      <div id="af-options-row" style="display:none;grid-column:1 / -1">
        <div class="fg">
          <label for="af-options">
            Options
            <span class="form-hint" style="display:inline">(comma-separated, for select type only)</span>
          </label>
          <textarea id="af-options" name="options" rows="2"
                    placeholder="e.g. Option A, Option B, Option C"></textarea>
        </div>
      </div>

      <!-- Required checkbox -->
      <div class="fg-check">
        <label for="af-required">Required</label>
        <label class="form-check" style="margin-top:.15rem">
          <input type="checkbox" id="af-required" name="required" value="1">
          <span class="form-check-label">Yes</span>
        </label>
      </div>

      <!-- Submit -->
      <div>
        <button type="submit" class="btn btn-primary btn-sm">Add Field</button>
      </div>

    </div><!-- /.add-grid -->
  </form>
</div><!-- /.card -->

