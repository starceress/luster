<?php
/** @var \Luster\Models\Fanlisting $fanlisting */
/** @var bool                      $table_exists */
/** @var string                    $error */
/** @var string                    $success */
/** @var string                    $base_url */
/** @var string                    $csrf_token */
$b = rtrim(\Luster\Core\View::e($base_url), '/');
?>
<style>
  .form-card { background:#fff;border:1px solid #e5e7eb;border-radius:8px;padding:1.5rem;margin-bottom:1.25rem; }
  .form-group { margin-bottom:1.1rem; }
  .form-group label { display:block;font-size:.8125rem;font-weight:600;color:#374151;margin-bottom:.35rem; }
  .form-group input[type=text] { width:100%;padding:.5rem .75rem;border:1px solid #d1d5db;border-radius:6px;font-size:.9rem;color:#111827;box-sizing:border-box; }
  .form-group input[type=text]:focus { outline:none;border-color:#6366f1;box-shadow:0 0 0 2px rgba(99,102,241,.15); }
  .form-group input[readonly] { background:#f9fafb;color:#6b7280;cursor:not-allowed; }
  .form-hint { font-size:.75rem;color:#6b7280;margin-top:.2rem; }
  .btn { display:inline-block;padding:.45rem 1rem;border-radius:6px;font-size:.875rem;font-weight:500;text-decoration:none;cursor:pointer;border:none; }
  .btn-danger { background:#dc2626;color:#fff; }
  .btn-danger:hover { background:#b91c1c; }
  .btn-row { display:flex;gap:.75rem;align-items:center;margin-top:1.5rem; }
  .btn-cancel { color:#6b7280;text-decoration:none;font-size:.875rem; }
  .alert-error   { background:#fef2f2;border:1px solid #fca5a5;border-radius:6px;padding:.65rem 1rem;margin-bottom:1rem;color:#b91c1c;font-size:.875rem; }
  .alert-warning { background:#fffbeb;border:1px solid #fde68a;border-radius:8px;padding:1rem 1.25rem;margin-bottom:1.25rem;color:#92400e; }
</style>

<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1rem;">
  <a href="<?= $b ?>/admin/fanlistings/<?= (int) $fanlisting->id ?>/edit"
     style="color:#6b7280;font-size:.875rem;text-decoration:none">&larr; Back to <?= \Luster\Core\View::e($fanlisting->name) ?></a>
</div>

<h1 style="font-size:1.25rem;font-weight:700;color:#111827;margin-bottom:1.25rem">Change Table Prefix</h1>

<?php if ($error !== ''): ?>
  <div class="alert-error"><?= \Luster\Core\View::e($error) ?></div>
<?php endif; ?>

<div class="alert-warning">
  <strong>&#9888; Destructive operation.</strong>
  This renames the member table in your database. If anything goes wrong your member data may become
  unreachable. There is no undo. Back up your database before proceeding.
</div>

<?php if (!$table_exists): ?>
  <div class="alert-error">
    <strong>Warning:</strong> the current member table
    <code><?= \Luster\Core\View::e($fanlisting->dbprefix) ?></code>
    does not exist in the database. Proceeding may cause errors.
  </div>
<?php endif; ?>

<div class="form-card">
  <form method="POST"
        action="<?= $b ?>/admin/fanlistings/<?= (int) $fanlisting->id ?>/edit-prefix">
    <input type="hidden" name="_csrf" value="<?= \Luster\Core\View::e($csrf_token ?? '') ?>">

    <div class="form-group">
      <label>Current Prefix</label>
      <input type="text" value="<?= \Luster\Core\View::e($fanlisting->dbprefix) ?>" readonly>
      <p class="form-hint">This is the existing member table name in the database.</p>
    </div>

    <div class="form-group">
      <label for="ep-new-prefix">New Prefix</label>
      <input id="ep-new-prefix" type="text" name="new_prefix"
             pattern="[a-zA-Z0-9_]+"
             placeholder="e.g. fl_music_"
             required>
      <p class="form-hint">Letters, numbers, and underscores only. Must not already exist as a table.</p>
    </div>

    <div class="form-group">
      <label for="ep-confirm">Confirm New Prefix</label>
      <input id="ep-confirm" type="text" name="confirm_prefix"
             placeholder="Re-enter new prefix"
             required>
      <p class="form-hint">Re-enter the new prefix to confirm.</p>
    </div>

    <div class="btn-row">
      <button type="submit" class="btn btn-danger">Rename Table</button>
      <a href="<?= $b ?>/admin/fanlistings/<?= (int) $fanlisting->id ?>/edit" class="btn-cancel">Cancel</a>
    </div>
  </form>
</div>
