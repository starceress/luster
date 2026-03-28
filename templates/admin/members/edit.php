<?php
use Luster\Core\View;
/** @var \Luster\Models\Listing          $listing */
/** @var \Luster\Models\Member           $member */
/** @var \Luster\Models\AdditionalField[] $fields */
/** @var string $error */
/** @var string $success */
/** @var array  $old */
/** @var array  $field_errors */
/** @var string $base_url */
/** @var string $csrf_token */
$b  = rtrim(View::e($base_url), '/');
$fe = $field_errors ?? [];
$id = (int) $listing->id;
?>

<div class="page-topbar">
  <a href="<?= $b ?>/admin/members/<?= $id ?>" class="back-link">
    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="15 18 9 12 15 6"/></svg>
    Members &mdash; <?= View::e($listing->name) ?>
  </a>
</div>

<?php if (($success ?? '') !== ''): ?>
  <div class="alert alert-success"><?= View::e($success) ?></div>
<?php endif; ?>
<?php if ($error !== ''): ?>
  <div class="alert alert-error"><?= View::e($error) ?></div>
<?php endif; ?>

<form method="POST" action="<?= $b ?>/admin/members/<?= $id ?>/edit" class="js-warn-unsaved" style="margin-bottom:1rem">
  <input type="hidden" name="_csrf"        value="<?= View::e($csrf_token ?? '') ?>">
  <input type="hidden" name="member_email" value="<?= View::e($member->email) ?>">

  <div class="card listing-field-card" style="margin-bottom:0">

    <!-- General Information -->
    <div class="listing-field-section">
      <div class="pc-section-label">General Information</div>

      <!-- Email — read only -->
      <div class="listing-field-row listing-field-row--top">
        <span class="listing-field-label">Email</span>
        <div>
          <div class="ro-field"><?= View::e($member->email) ?></div>
          <p class="form-hint">Email is the member identifier and cannot be changed here.</p>
        </div>
      </div>

      <!-- Name -->
      <div class="listing-field-row<?= !empty($fe['name']) ? ' has-error' : '' ?>">
        <label class="listing-field-label" for="m-name">Name <span class="req">*</span></label>
        <div>
          <input id="m-name" type="text" name="name" class="form-input" maxlength="255" required
                 value="<?= View::e($old['name'] ?? $member->name) ?>">
          <?php if (!empty($fe['name'])): ?>
            <p class="field-error"><?= View::e($fe['name'][0]) ?></p>
          <?php endif; ?>
        </div>
      </div>

      <!-- URL -->
      <div class="listing-field-row<?= !empty($fe['url']) ? ' has-error' : '' ?>">
        <label class="listing-field-label" for="m-url">Website URL</label>
        <div>
          <input id="m-url" type="url" name="url" class="form-input" maxlength="500" placeholder="https://"
                 value="<?= View::e($old['url'] ?? $member->url) ?>">
          <?php if (!empty($fe['url'])): ?>
            <p class="field-error"><?= View::e($fe['url'][0]) ?></p>
          <?php endif; ?>
        </div>
      </div>

      <!-- Country -->
      <div class="listing-field-row">
        <label class="listing-field-label" for="m-country">Country</label>
        <div class="select-wrap">
          <select id="m-country" name="country" class="form-select">
            <option value="">— Select Country —</option>
            <?php foreach (\Luster\Core\Countries::list() as $c): ?>
              <option value="<?= View::e($c) ?>"
                      <?= $c === ($old['country'] ?? ($member->country ?? '')) ? 'selected' : '' ?>>
                <?= View::e($c) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>

      <!-- Show URL -->
      <div class="listing-field-row listing-field-row--top">
        <span class="listing-field-label">Show Website URL?</span>
        <div style="display:flex;gap:1.25rem;margin-top:.1rem">
          <label class="form-check">
            <input type="radio" name="showurl" value="1"
                   <?= ($old['showurl'] ?? ($member->showurl ? '1' : '0')) !== '0' ? 'checked' : '' ?>>
            <span class="form-check-label">Show</span>
          </label>
          <label class="form-check">
            <input type="radio" name="showurl" value="0"
                   <?= ($old['showurl'] ?? ($member->showurl ? '1' : '0')) === '0' ? 'checked' : '' ?>>
            <span class="form-check-label">Hide</span>
          </label>
        </div>
      </div>

    </div><!-- /.listing-field-section -->

    <?php if (!empty($fields)): ?>
    <!-- Additional Fields -->
    <div class="listing-field-section" style="border-top:1px solid var(--border)">
      <div class="pc-section-label">Additional Fields</div>

      <?php foreach ($fields as $field): ?>
        <?php $fieldVal = $old['field_' . $field->name] ?? ($member->additionalFields[$field->name] ?? ''); ?>
        <div class="listing-field-row<?= !empty($fe['field_' . $field->name]) ? ' has-error' : '' ?>">
          <label class="listing-field-label" for="field-<?= View::e($field->name) ?>">
            <?= View::e($field->label) ?>
          </label>
          <?php if ($field->type === 'textarea'): ?>
            <textarea id="field-<?= View::e($field->name) ?>"
                      name="field_<?= View::e($field->name) ?>"
                      class="form-textarea"
                      rows="3"><?= View::e((string) $fieldVal) ?></textarea>
          <?php else: ?>
            <input id="field-<?= View::e($field->name) ?>"
                   type="text"
                   name="field_<?= View::e($field->name) ?>"
                   class="form-input"
                   maxlength="500"
                   value="<?= View::e((string) $fieldVal) ?>">
          <?php endif; ?>
        </div>
      <?php endforeach; ?>

    </div><!-- /.listing-field-section -->
    <?php endif; ?>

    <div class="composer-footer">
      <button type="submit" class="btn btn-primary">Save Changes</button>
      <button type="button" class="btn btn-ghost js-form-reset">Clear Form</button>
      <a href="<?= $b ?>/admin/members/<?= $id ?>" class="btn btn-ghost">Cancel</a>
    </div>
  </div>
</form>

<!-- Merge duplicate -->
<details class="card form-details" style="margin-bottom:0">
  <summary>
    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="flex-shrink:0"><circle cx="18" cy="18" r="3"/><circle cx="6" cy="6" r="3"/><path d="M13 6h3a2 2 0 0 1 2 2v7"/><line x1="6" y1="9" x2="6" y2="21"/></svg>
    Merge duplicate member
  </summary>
  <div class="form-details-body">
    <p class="form-hint" style="margin-bottom:1rem">
      If this member joined again under a different email, enter that email below to delete the duplicate.
      <strong><?= View::e($member->email) ?></strong> will be kept; the duplicate will be permanently removed.
    </p>
    <form method="POST" action="<?= $b ?>/admin/members/<?= $id ?>/merge"
          data-del-modal data-del-entity="merge_member"
          data-del-name="<?= View::e($member->email) ?>">
      <input type="hidden" name="_csrf"        value="<?= View::e($csrf_token ?? '') ?>">
      <input type="hidden" name="member_email" value="<?= View::e($member->email) ?>">
      <div style="display:flex;gap:.75rem;align-items:flex-end;flex-wrap:wrap">
        <div class="form-group" style="flex:1;min-width:200px;margin-bottom:0">
          <label class="form-label" for="merge-dupe-email">Duplicate email to delete</label>
          <input id="merge-dupe-email" type="email" name="duplicate_email" class="form-input"
                 maxlength="255" placeholder="duplicate@example.com" required>
        </div>
        <button type="submit" class="btn btn-danger btn-sm" style="margin-bottom:1px">Delete duplicate</button>
      </div>
    </form>
  </div>
</details>
