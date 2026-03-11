<?php
/** @var \Luster\Models\Fanlisting $fanlisting */
/** @var bool                      $table_exists */
/** @var string                    $error */
/** @var string                    $success */
/** @var string                    $base_url */
/** @var string                    $csrf_token */
$b = rtrim(\Luster\Core\View::e($base_url), '/');
?>

<p style="margin-bottom:1rem">
  <a href="<?= $b ?>/admin/fanlistings/<?= (int) $fanlisting->id ?>/edit"
     class="neb-link">&larr; Back to <?= \Luster\Core\View::e($fanlisting->name) ?></a>
</p>

<h1 style="font-size:1.25rem;font-weight:700;color:var(--text);margin-bottom:1.25rem">Change Table Prefix</h1>

<?php if ($error !== ''): ?>
  <div class="alert alert-error"><?= \Luster\Core\View::e($error) ?></div>
<?php endif; ?>

<div class="alert-warning">
  <strong>&#9888; Destructive operation.</strong>
  This renames the member table in your database. If anything goes wrong your member data may become
  unreachable. There is no undo. Back up your database before proceeding.
</div>

<?php if (!$table_exists): ?>
  <div class="alert alert-error">
    <strong>Warning:</strong> the current member table
    <code class="neb-code"><?= \Luster\Core\View::e($fanlisting->dbprefix) ?></code>
    does not exist in the database. Proceeding may cause errors.
  </div>
<?php endif; ?>

<div class="form-card">
  <form method="POST"
        action="<?= $b ?>/admin/fanlistings/<?= (int) $fanlisting->id ?>/edit-prefix">
    <input type="hidden" name="_csrf" value="<?= \Luster\Core\View::e($csrf_token ?? '') ?>">

    <div class="form-group">
      <label class="form-label">Current Prefix</label>
      <input type="text" class="form-input"
             value="<?= \Luster\Core\View::e($fanlisting->dbprefix) ?>" readonly
             style="color:var(--text-muted);cursor:not-allowed">
      <p class="form-hint">This is the existing member table name in the database.</p>
    </div>

    <div class="form-group">
      <label class="form-label" for="ep-new-prefix">New Prefix</label>
      <input id="ep-new-prefix" type="text" name="new_prefix" class="form-input"
             pattern="[a-zA-Z0-9_]+"
             placeholder="e.g. fl_music_"
             required>
      <p class="form-hint">Letters, numbers, and underscores only. Must not already exist as a table.</p>
    </div>

    <div class="form-group">
      <label class="form-label" for="ep-confirm">Confirm New Prefix</label>
      <input id="ep-confirm" type="text" name="confirm_prefix" class="form-input"
             placeholder="Re-enter new prefix"
             required>
      <p class="form-hint">Re-enter the new prefix to confirm.</p>
    </div>

    <div class="btn-row">
      <button type="submit" class="btn btn-danger">Rename Table</button>
      <a href="<?= $b ?>/admin/fanlistings/<?= (int) $fanlisting->id ?>/edit" class="neb-link">Cancel</a>
    </div>
  </form>
</div>
