<?php
/** @var \Luster\Models\Fanlisting        $fanlisting */
/** @var \Luster\Models\Member             $member */
/** @var \Luster\Models\AdditionalField[] $fields */
/** @var string $error */
/** @var array  $old */
/** @var string $base_url */
$b  = rtrim(\Luster\Core\View::e($base_url), '/');
$id = (int) $fanlisting->id;

function oldVal(array $old, \Luster\Models\Member $m, string $key): string {
    return $old[$key] ?? (string) ($m->$key ?? '');
}
?>
<style>
  .form-card { background:#fff;border:1px solid #e5e7eb;border-radius:8px;padding:1.5rem;max-width:640px; }
  .form-group { margin-bottom:1.1rem; }
  .form-group label { display:block;font-size:.8125rem;font-weight:600;color:#374151;margin-bottom:.35rem; }
  .form-group input, .form-group textarea { width:100%;padding:.5rem .75rem;border:1px solid #d1d5db;border-radius:6px;font-size:.9rem;color:#111827; }
  .form-group input[type=checkbox] { width:auto; }
  .form-group input:focus, .form-group textarea:focus { outline:none;border-color:#6366f1;box-shadow:0 0 0 2px rgba(99,102,241,.15); }
  .form-hint { font-size:.75rem;color:#6b7280;margin-top:.2rem; }
  .form-check { display:flex;align-items:center;gap:.5rem; }
  .btn-row { display:flex;gap:.75rem;align-items:center;margin-top:1.5rem; }
  .btn { display:inline-block;padding:.5rem 1.25rem;border-radius:6px;font-size:.875rem;font-weight:500;cursor:pointer;border:none; }
  .btn-primary { background:#4f46e5;color:#fff; }
  .btn-primary:hover { background:#4338ca; }
  .btn-cancel { color:#6b7280;text-decoration:none;font-size:.875rem; }
  .alert-error { background:#fef2f2;border:1px solid #fca5a5;border-radius:6px;padding:.65rem 1rem;margin-bottom:1rem;color:#b91c1c;font-size:.875rem; }
  .ro-field { background:#f9fafb;padding:.45rem .75rem;border:1px solid #e5e7eb;border-radius:6px;font-size:.9rem;color:#6b7280; }
  fieldset { border:1px solid #e5e7eb;border-radius:6px;padding:.75rem 1rem;margin-bottom:1.1rem; }
  legend { font-size:.8rem;font-weight:600;color:#6b7280;padding:0 .25rem; }
</style>

<p style="margin-bottom:1rem">
  <a href="<?= $b ?>/admin/members/<?= $id ?>" style="color:#6b7280;font-size:.875rem;text-decoration:none">&larr; Members — <?= \Luster\Core\View::e($fanlisting->name) ?></a>
</p>

<?php if ($error !== ''): ?>
  <div class="alert-error"><?= \Luster\Core\View::e($error) ?></div>
<?php endif; ?>

<div class="form-card">
  <form method="POST" action="<?= $b ?>/admin/members/<?= $id ?>/edit">
    <input type="hidden" name="_csrf"        value="<?= \Luster\Core\View::e($csrf_token ?? '') ?>">
    <input type="hidden" name="member_email" value="<?= \Luster\Core\View::e($member->email) ?>">

    <!-- Read-only: email -->
    <div class="form-group">
      <label>Email (identifier — read-only)</label>
      <div class="ro-field"><?= \Luster\Core\View::e($member->email) ?></div>
    </div>

    <div class="form-group">
      <label for="m-name">Name</label>
      <input id="m-name" type="text" name="name" maxlength="255" required
             value="<?= \Luster\Core\View::e($old['name'] ?? $member->name) ?>">
    </div>

    <div class="form-group">
      <label for="m-url">Website URL</label>
      <input id="m-url" type="url" name="url" maxlength="500" placeholder="https://"
             value="<?= \Luster\Core\View::e($old['url'] ?? $member->url) ?>">
    </div>

    <div class="form-group">
      <label for="m-country">Country</label>
      <input id="m-country" type="text" name="country" maxlength="100"
             value="<?= \Luster\Core\View::e($old['country'] ?? $member->country) ?>">
    </div>

    <div class="form-group">
      <div class="form-check">
        <input id="m-showurl" type="checkbox" name="showurl" value="1"
               <?= ($old['showurl'] ?? ($member->showurl ? '1' : '0')) === '1' ? 'checked' : '' ?>>
        <label for="m-showurl" style="margin-bottom:0;font-weight:400">Show website URL publicly</label>
      </div>
    </div>

    <?php if (!empty($fields)): ?>
    <fieldset>
      <legend>Additional Fields</legend>
      <?php foreach ($fields as $field): ?>
      <div class="form-group">
        <label for="field-<?= \Luster\Core\View::e($field->name) ?>"><?= \Luster\Core\View::e($field->label) ?></label>
        <?php $fieldVal = $old['field_' . $field->name] ?? ($member->additionalFields[$field->name] ?? ''); ?>
        <?php if ($field->type === 'textarea'): ?>
          <textarea id="field-<?= \Luster\Core\View::e($field->name) ?>"
                    name="field_<?= \Luster\Core\View::e($field->name) ?>"
                    rows="3"><?= \Luster\Core\View::e((string) $fieldVal) ?></textarea>
        <?php else: ?>
          <input id="field-<?= \Luster\Core\View::e($field->name) ?>"
                 type="text"
                 name="field_<?= \Luster\Core\View::e($field->name) ?>"
                 maxlength="500"
                 value="<?= \Luster\Core\View::e((string) $fieldVal) ?>">
        <?php endif; ?>
      </div>
      <?php endforeach; ?>
    </fieldset>
    <?php endif; ?>

    <div class="btn-row">
      <button type="submit" class="btn btn-primary">Save Changes</button>
      <a href="<?= $b ?>/admin/members/<?= $id ?>" class="btn-cancel">Cancel</a>
    </div>
  </form>
</div>
