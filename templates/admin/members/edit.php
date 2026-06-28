<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Edit member page. Provides a form to update a single member's name, email, URL,
 country, password, approval status, and any additional custom fields defined for
 the listing. Includes a danger zone for permanent member deletion.
*/

use Luster\Core\View;

$b  = rtrim(View::e($base_url), '/');
$fe = $field_errors ?? [];
$id = (int) $listing->id;
?>

<div class="toolbar">
  <a href="<?= $b ?>/admin/members?listing_id=<?= (int) $id ?>&status=all" class="link">
    &larr; Back to Members: <?= View::e($listing->title) ?>
  </a>
</div>

<?php if (($success ?? '') !== ''): ?>
  <div class="alert alert-success"><?= View::e($success) ?></div>
<?php endif; ?>
<?php if ($error !== ''): ?>
  <div class="alert alert-error"><?= View::e($error) ?></div>
<?php endif; ?>

<form method="POST" action="<?= $b ?>/admin/members/<?= $id ?>/edit"
      class="js-warn-unsaved member-edit-form">
  <input type="hidden" name="_csrf"        value="<?= View::e($csrf_token ?? '') ?>">
  <input type="hidden" name="member_email" value="<?= View::e($member->email) ?>">

  <div class="card card--gradient">
    <div class="panel-header">
      <div class="panel-header-icon panel-header-icon-filled"><?= View::icon('members') ?></div>
      <div class="panel-header-meta">
        <div class="panel-header-title"><?= View::e($member->name) ?></div>
        <div class="panel-header-sub">Editing member &mdash; <?= View::e($listing->subject) ?></div>
      </div>
    </div>
    <div class="card-body">
      <div class="section-label">General Information</div>

      <div class="field-row">
        <span class="field-label">Email</span>
        <div>
          <div class="readonly-field"><?= View::e($member->email) ?></div>
          <p class="form-hint">Email is the member identifier and cannot be changed here.</p>
        </div>
      </div>

      <div class="field-row<?= !empty($fe['name']) ? ' has-error' : '' ?>">
        <label class="field-label" for="m-name">
          Name <span class="req">*</span>
        </label>
        <div>
          <input id="m-name" type="text" name="name" class="form"
                 maxlength="255" required
                 value="<?= View::e($old['name'] ?? $member->name) ?>">
          <?php if (!empty($fe['name'])): ?>
            <p class="field-error"><?= View::e($fe['name'][0]) ?></p>
          <?php endif; ?>
        </div>
      </div>

      <div class="field-row<?= !empty($fe['url']) ? ' has-error' : '' ?>">
        <label class="field-label" for="m-url">Website URL</label>
        <div>
          <input id="m-url" type="url" name="url" class="form"
                 maxlength="500" placeholder="https://"
                 value="<?= View::e($old['url'] ?? $member->url) ?>">
          <?php if (!empty($fe['url'])): ?>
            <p class="field-error"><?= View::e($fe['url'][0]) ?></p>
          <?php endif; ?>
        </div>
      </div>

      <div class="field-row">
        <label class="field-label" for="m-country">Country</label>
        <div class="select-wrap">
          <select id="m-country" name="country" >
            <option value="">&mdash; Select Country &mdash;</option>
            <?php foreach (\Luster\Core\Countries::list() as $c): ?>
              <option value="<?= View::e($c) ?>"
                      <?= $c === ($old['country'] ?? ($member->country ?? '')) ? 'selected' : '' ?>>
                <?= View::e($c) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>

      <div class="field-row">
        <span class="field-label">Show Website?</span>
        <div class="choice-inline-group">
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
    </div>

    <?php if (!empty($fields)): ?>
    <div class="card-body">
      <div class="section-label">Additional Fields</div>

      <?php foreach ($fields as $field):
        $fKey     = 'field_' . $field->name;
        $fieldVal = $old[$fKey] ?? ($member->additionalFields[$field->name] ?? '');
      ?>
        <div class="field-row<?= !empty($fe[$fKey]) ? ' has-error' : '' ?>">
          <label class="field-label" for="field-<?= View::e($field->name) ?>">
            <?= View::e($field->label) ?>
          </label>
          <?php if ($field->type === 'textarea'): ?>
            <textarea id="field-<?= View::e($field->name) ?>"
                      name="<?= View::e($fKey) ?>"
                      class="form"
                      rows="3"><?= View::e((string) $fieldVal) ?></textarea>
          <?php else: ?>
            <input id="field-<?= View::e($field->name) ?>"
                   type="text"
                   name="<?= View::e($fKey) ?>"
                   class="form"
                   maxlength="500"
                   value="<?= View::e((string) $fieldVal) ?>">
          <?php endif; ?>
          <?php if (!empty($fe[$fKey])): ?>
            <p class="field-error"><?= View::e($fe[$fKey][0]) ?></p>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <div class="composer-footer">
      <button type="submit" class="btn btn-primary">Save Changes</button>
      <a href="<?= $b ?>/admin/members?listing_id=<?= (int) $id ?>&status=all" class="btn btn-ghost">Cancel</a>
    </div>
  </div>
</form>

<details class="card form-details form-details--flush">
  <summary>
    <?= View::icon('merge') ?> Merge duplicate member
  </summary>
  <div class="form-details-body">
    <p class="form-details-desc">
      If this member joined again under a different email, enter that email below to delete the
      duplicate. <strong><?= View::e($member->email) ?></strong> will be kept; the duplicate will
      be permanently removed.
    </p>
    <form method="POST" action="<?= $b ?>/admin/members/<?= $id ?>/merge"
          data-del-modal data-del-entity="merge_member"
          data-del-name="<?= View::e($member->email) ?>">
      <input type="hidden" name="_csrf"        value="<?= View::e($csrf_token ?? '') ?>">
      <input type="hidden" name="member_email" value="<?= View::e($member->email) ?>">
      <div class="field-row">
        <label class="field-label" for="merge-dupe-email">Duplicate email to delete</label>
        <div>
          <input id="merge-dupe-email" type="email" name="duplicate_email" class="form"
                 maxlength="255" placeholder="duplicate&#64;example.com" required>
        </div>
      </div>
      <div class="composer-footer">
        <button type="submit" class="btn btn-danger">Delete Duplicate</button>
      </div>
    </form>
  </div>
</details>