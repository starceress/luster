<?php
/** @var \Luster\Models\Fanlisting $fanlisting */
/** @var bool   $auto_approve */
/** @var string $error */
/** @var array  $old  */
/** @var string $base_url */
$b = rtrim(\Luster\Core\View::e($base_url), '/');

// Resolve display values: prefer $old (re-populates on validation fail) then model
$oldTitle  = $old['title']  ?? $fanlisting->name;
$oldUrl    = $old['url']    ?? $fanlisting->url;
$oldEmail  = $old['email']  ?? $fanlisting->email;

// Resolve current status string
if ($fanlisting->upcoming) {
    $currentStatus = 'upcoming';
} elseif ($fanlisting->trouble) {
    $currentStatus = 'trouble';
} else {
    $currentStatus = 'active';
}
$oldStatus = $old['status'] ?? $currentStatus;
?>
<style>
  .form-card { background:#fff;border:1px solid #e5e7eb;border-radius:8px;padding:1.5rem;max-width:640px; }
  .form-group { margin-bottom:1.1rem; }
  .form-group label { display:block;font-size:.8125rem;font-weight:600;color:#374151;margin-bottom:.35rem; }
  .form-group input, .form-group select { width:100%;padding:.5rem .75rem;border:1px solid #d1d5db;border-radius:6px;font-size:.9rem;color:#111827; }
  .form-group input:focus, .form-group select:focus { outline:none;border-color:#6366f1;box-shadow:0 0 0 2px rgba(99,102,241,.15); }
  .form-hint { font-size:.75rem;color:#6b7280;margin-top:.2rem; }
  .form-check { display:flex;align-items:center;gap:.5rem; }
  .form-check input[type=checkbox] { width:auto; }
  .btn-row { display:flex;gap:.75rem;align-items:center;margin-top:1.5rem; }
  .btn { display:inline-block;padding:.5rem 1.25rem;border-radius:6px;font-size:.875rem;font-weight:500;text-decoration:none;cursor:pointer;border:none; }
  .btn-primary { background:#4f46e5;color:#fff; }
  .btn-primary:hover { background:#4338ca; }
  .btn-cancel { color:#6b7280;text-decoration:none;font-size:.875rem; }
  .alert-error { background:#fef2f2;border:1px solid #fca5a5;border-radius:6px;padding:.65rem 1rem;margin-bottom:1rem;color:#b91c1c;font-size:.875rem; }
  fieldset { border:1px solid #e5e7eb;border-radius:6px;padding:.75rem 1rem;margin-bottom:1.1rem; }
  legend { font-size:.8rem;font-weight:600;color:#6b7280;padding:0 .25rem; }
</style>

<p style="margin-bottom:1rem">
  <a href="<?= $b ?>/admin/fanlistings/<?= (int) $fanlisting->id ?>" style="color:#6b7280;font-size:.875rem;text-decoration:none">&larr; <?= \Luster\Core\View::e($fanlisting->name) ?></a>
</p>

<?php if ($error !== ''): ?>
  <div class="alert-error"><?= \Luster\Core\View::e($error) ?></div>
<?php endif; ?>

<div class="form-card">
  <form method="POST" action="<?= $b ?>/admin/fanlistings/<?= (int) $fanlisting->id ?>/edit">
    <input type="hidden" name="_csrf" value="<?= \Luster\Core\View::e($csrf_token ?? '') ?>">

    <div class="form-group">
      <label for="fl-title">Display Name</label>
      <input id="fl-title" type="text" name="title"
             value="<?= \Luster\Core\View::e((string) $oldTitle) ?>"
             maxlength="255" required>
    </div>

    <div class="form-group">
      <label for="fl-url">Website URL</label>
      <input id="fl-url" type="url" name="url"
             value="<?= \Luster\Core\View::e((string) $oldUrl) ?>"
             maxlength="500" placeholder="https://example.com" required>
    </div>

    <div class="form-group">
      <label for="fl-email">Contact Email</label>
      <input id="fl-email" type="email" name="email"
             value="<?= \Luster\Core\View::e((string) $oldEmail) ?>"
             maxlength="255" required>
    </div>

    <div class="form-group">
      <label for="fl-status">Status</label>
      <select id="fl-status" name="status">
        <option value="active"   <?= $oldStatus === 'active'   ? 'selected' : '' ?>>Active</option>
        <option value="upcoming" <?= $oldStatus === 'upcoming' ? 'selected' : '' ?>>Upcoming</option>
        <option value="trouble"  <?= $oldStatus === 'trouble'  ? 'selected' : '' ?>>Trouble</option>
      </select>
      <p class="form-hint">
        Active = open for signups. Upcoming = not yet open. Trouble = hidden/inactive.
      </p>
    </div>

    <fieldset>
      <legend>Luster Settings</legend>
      <div class="form-check">
        <input id="auto-approve" type="checkbox" name="auto_approve" value="1"
               <?= $auto_approve ? 'checked' : '' ?>>
        <label for="auto-approve" style="margin-bottom:0;font-weight:400">
          Auto-approve new join requests
        </label>
      </div>
      <p class="form-hint" style="margin-top:.35rem">
        When enabled, members are approved immediately on join (no admin review).
        Requires <code>migrate.php</code> to have been run.
      </p>
    </fieldset>

    <div class="form-hint" style="margin-bottom:1rem;padding:.65rem .75rem;background:#f9fafb;border-radius:5px;border:1px solid #e5e7eb">
      <strong>Read-only fields</strong> (managed by Enthusiast):
      subject = <em><?= \Luster\Core\View::e($fanlisting->subject) ?></em>,
      table = <em><?= \Luster\Core\View::e($fanlisting->dbprefix) ?></em>
    </div>

    <div class="btn-row">
      <button type="submit" class="btn btn-primary">Save Changes</button>
      <a href="<?= $b ?>/admin/fanlistings/<?= (int) $fanlisting->id ?>" class="btn-cancel">Cancel</a>
    </div>
  </form>
</div>
