<?php
/** @var \Luster\Models\Listing        $listing */
/** @var \Luster\Models\Member             $member */
/** @var \Luster\Models\AdditionalField[] $fields */
/** @var string $error */
/** @var string $success */
/** @var array  $old */
/** @var array  $field_errors */
/** @var string $base_url */
/** @var string $csrf_token */
$b  = rtrim(\Luster\Core\View::e($base_url), '/');
$fe = $field_errors ?? [];
$id = (int) $listing->id;

function oldVal(array $old, \Luster\Models\Member $m, string $key): string {
    return $old[$key] ?? (string) ($m->$key ?? '');
}
?>

<p style="margin-bottom:1rem">
  <a href="<?= $b ?>/admin/members/<?= $id ?>" class="neb-link">&larr; Members &mdash; <?= \Luster\Core\View::e($listing->name) ?></a>
</p>

<?php if (($success ?? '') !== ''): ?>
  <div class="alert alert-success"><?= \Luster\Core\View::e($success) ?></div>
<?php endif; ?>
<?php if ($error !== ''): ?>
  <div class="alert alert-error"><?= \Luster\Core\View::e($error) ?></div>
<?php endif; ?>

<div class="form-card">
  <form method="POST" action="<?= $b ?>/admin/members/<?= $id ?>/edit" class="js-warn-unsaved">
    <input type="hidden" name="_csrf"        value="<?= \Luster\Core\View::e($csrf_token ?? '') ?>">
    <input type="hidden" name="member_email" value="<?= \Luster\Core\View::e($member->email) ?>">

    <!-- Read-only: email -->
    <div class="form-group">
      <label class="form-label">Email (identifier &mdash; read-only)</label>
      <div class="ro-field"><?= \Luster\Core\View::e($member->email) ?></div>
    </div>

    <div class="form-group<?= !empty($fe['name']) ? ' has-error' : '' ?>">
      <label class="form-label" for="m-name">Name <span>*</span></label>
      <input id="m-name" type="text" name="name" class="form-input" maxlength="255" required
             value="<?= \Luster\Core\View::e($old['name'] ?? $member->name) ?>">
      <?php if (!empty($fe['name'])): ?><p class="field-error"><?= \Luster\Core\View::e($fe['name'][0]) ?></p><?php endif; ?>
    </div>

    <div class="form-group<?= !empty($fe['url']) ? ' has-error' : '' ?>">
      <label class="form-label" for="m-url">Website URL</label>
      <input id="m-url" type="url" name="url" class="form-input" maxlength="500" placeholder="https://"
             value="<?= \Luster\Core\View::e($old['url'] ?? $member->url) ?>">
      <?php if (!empty($fe['url'])): ?><p class="field-error"><?= \Luster\Core\View::e($fe['url'][0]) ?></p><?php endif; ?>
    </div>

    <div class="form-group">
      <label class="form-label" for="m-country">Country</label>
      <div class="select-wrap">
        <select id="m-country" name="country" class="form-select">
          <option value="">— Select Country —</option>
          <?php foreach (\Luster\Core\Countries::list() as $c): ?>
            <option value="<?= \Luster\Core\View::e($c) ?>"
                    <?= $c === ($old['country'] ?? ($member->country ?? '')) ? 'selected' : '' ?>>
              <?= \Luster\Core\View::e($c) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>

    <div class="form-group">
      <label class="form-label">Show Website URL publicly?</label>
      <div style="display:flex;gap:1.25rem;margin-top:.25rem">
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

    <?php if (!empty($fields)): ?>
    <div class="section-divider"></div>
    <fieldset class="neb-fieldset">
      <legend class="neb-fieldset-legend">Additional Fields</legend>
      <?php foreach ($fields as $field): ?>
      <div class="form-group">
        <label class="form-label" for="field-<?= \Luster\Core\View::e($field->name) ?>"><?= \Luster\Core\View::e($field->label) ?></label>
        <?php $fieldVal = $old['field_' . $field->name] ?? ($member->additionalFields[$field->name] ?? ''); ?>
        <?php if ($field->type === 'textarea'): ?>
          <textarea id="field-<?= \Luster\Core\View::e($field->name) ?>"
                    name="field_<?= \Luster\Core\View::e($field->name) ?>"
                    class="form-textarea"
                    rows="3"><?= \Luster\Core\View::e((string) $fieldVal) ?></textarea>
        <?php else: ?>
          <input id="field-<?= \Luster\Core\View::e($field->name) ?>"
                 type="text"
                 name="field_<?= \Luster\Core\View::e($field->name) ?>"
                 class="form-input"
                 maxlength="500"
                 value="<?= \Luster\Core\View::e((string) $fieldVal) ?>">
        <?php endif; ?>
      </div>
      <?php endforeach; ?>
    </fieldset>
    <?php endif; ?>

    <div class="btn-row">
      <button type="submit" class="btn btn-primary">Save Changes</button>
      <button type="button" class="btn btn-ghost js-form-reset">Clear Form</button>
      <a href="<?= $b ?>/admin/members/<?= (int) ($listing->id ?? 0) ?>" class="neb-link">Cancel</a>
    </div>
  </form>
</div>

<!-- ── Merge duplicate ─────────────────────────────────────────── -->
<details class="neb-card" style="margin-top:1.25rem;padding:0">
  <summary style="padding:1rem 1.25rem;cursor:pointer;font-weight:600;font-size:.85rem;color:var(--text-muted);list-style:none;display:flex;align-items:center;gap:.5rem">
    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24"
         fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
         style="flex-shrink:0"><circle cx="18" cy="18" r="3"/><circle cx="6" cy="6" r="3"/>
      <path d="M13 6h3a2 2 0 0 1 2 2v7"/><line x1="6" y1="9" x2="6" y2="21"/>
    </svg>
    Merge duplicate member
  </summary>
  <div style="padding:0 1.25rem 1.25rem">
    <p class="form-hint" style="margin-bottom:1rem">
      If this member joined again under a different email, enter that email below to delete the duplicate.
      <strong><?= \Luster\Core\View::e($member->email) ?></strong> will be kept; the duplicate will be permanently removed.
    </p>
    <form method="POST" action="<?= $b ?>/admin/members/<?= $id ?>/merge"
          data-confirm="Delete the duplicate member? This cannot be undone.">
      <input type="hidden" name="_csrf"        value="<?= \Luster\Core\View::e($csrf_token ?? '') ?>">
      <input type="hidden" name="member_email" value="<?= \Luster\Core\View::e($member->email) ?>">
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
