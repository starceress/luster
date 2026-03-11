<?php
/** @var \Luster\Models\Fanlisting        $fanlisting */
/** @var \Luster\Models\Member             $member */
/** @var \Luster\Models\AdditionalField[] $fields */
/** @var string $error */
/** @var array  $old */
/** @var string $base_url */
/** @var string $csrf_token */
$b  = rtrim(\Luster\Core\View::e($base_url), '/');
$id = (int) $fanlisting->id;

function oldVal(array $old, \Luster\Models\Member $m, string $key): string {
    return $old[$key] ?? (string) ($m->$key ?? '');
}
?>

<p style="margin-bottom:1rem">
  <a href="<?= $b ?>/admin/members/<?= $id ?>" class="neb-link">&larr; Members &mdash; <?= \Luster\Core\View::e($fanlisting->name) ?></a>
</p>

<?php if ($error !== ''): ?>
  <div class="alert alert-error"><?= \Luster\Core\View::e($error) ?></div>
<?php endif; ?>

<div class="form-card">
  <form method="POST" action="<?= $b ?>/admin/members/<?= $id ?>/edit">
    <input type="hidden" name="_csrf"        value="<?= \Luster\Core\View::e($csrf_token ?? '') ?>">
    <input type="hidden" name="member_email" value="<?= \Luster\Core\View::e($member->email) ?>">

    <!-- Read-only: email -->
    <div class="form-group">
      <label class="form-label">Email (identifier &mdash; read-only)</label>
      <div class="ro-field"><?= \Luster\Core\View::e($member->email) ?></div>
    </div>

    <div class="form-group">
      <label class="form-label" for="m-name">Name <span>*</span></label>
      <input id="m-name" type="text" name="name" class="form-input" maxlength="255" required
             value="<?= \Luster\Core\View::e($old['name'] ?? $member->name) ?>">
    </div>

    <div class="form-group">
      <label class="form-label" for="m-url">Website URL</label>
      <input id="m-url" type="url" name="url" class="form-input" maxlength="500" placeholder="https://"
             value="<?= \Luster\Core\View::e($old['url'] ?? $member->url) ?>">
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
      <button type="button" class="btn btn-ghost" onclick="this.form.reset()">Clear Form</button>
      <a href="<?= $b ?>/admin/members/<?= (int) ($fanlisting->id ?? 0) ?>" class="neb-link">Cancel</a>
    </div>
  </form>
</div>
