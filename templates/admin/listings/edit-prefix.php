<?php
use Luster\Core\View;
/** @var \Luster\Models\Listing $listing */
/** @var bool                   $table_exists */
/** @var string                 $error */
/** @var string                 $success */
/** @var string                 $base_url */
/** @var string                 $csrf_token */
$b = rtrim(View::e($base_url), '/');
?>

<div class="page-topbar">
  <a href="<?= $b ?>/admin/listings/<?= (int) $listing->id ?>/edit" class="link">
    &larr; Back to <?= View::e($listing->name) ?>
  </a>
</div>

<?php if (($error ?? '') !== ''): ?>
  <div class="alert alert-error"><?= View::e($error) ?></div>
<?php endif; ?>

<?php if (!$table_exists): ?>
  <div class="alert alert-error mb-section">
    <strong>Warning:</strong> the current member table
    <code><?= View::e($listing->dbprefix) ?></code>
    does not exist in the database. Proceeding may cause further errors.
  </div>
<?php endif; ?>

<form method="POST" action="<?= $b ?>/admin/listings/<?= (int) $listing->id ?>/edit-prefix" class="js-warn-unsaved"
      data-del-modal
      data-del-entity="rename_prefix"
      data-del-name="<?= View::e($listing->dbprefix) ?>"
      data-del-context-from="new_prefix">
  <input type="hidden" name="_csrf" value="<?= View::e($csrf_token ?? '') ?>">

  <div class="gradient-card">
    <div class="pc-head">
      <div class="pc-head-icon pc-head-icon-bg"><?= View::icon('database') ?></div>
      <div class="pc-head-meta">
        <div class="pc-head-title">Rename Member Table</div>
        <div class="pc-head-sub">Updates the database table prefix used for this listing's member records.</div>
      </div>
    </div>

    <div class="card-body">
      <div class="callout callout-inline">
        <?= View::icon('alert', ['width' => 24, 'height' => 24, 'style' => 'color:var(--warn)']) ?>
        <div><strong>Destructive Operation.</strong> This renames the member table in your database. If anything goes wrong your member
        data may become unreachable. There is no undo - back up your database before proceeding.
      </div>
    </div>

      <div class="listing-field-row listing-field-row--top">
        <span class="listing-field-label">Current Prefix</span>
        <div>
          <div class="ro-field"><?= View::e($listing->dbprefix) ?></div>
          <p class="form-hint">The existing member table name in the database.</p>
        </div>
      </div>

      <div class="listing-field-row listing-field-row--top">
        <label class="listing-field-label" for="ep-new-prefix">
          New Prefix <span class="req">*</span>
        </label>
        <div>
          <input id="ep-new-prefix" type="text" name="new_prefix" class="form" pattern="[a-zA-Z0-9_]+" placeholder="e.g. fl_music_" required>
          <p class="form-hint">Letters, numbers, and underscores only. Must not already exist as a table.</p>
        </div>
      </div>

      <div class="listing-field-row listing-field-row--top">
        <label class="listing-field-label" for="ep-confirm">
          Confirm Prefix <span class="req">*</span>
        </label>
        <div>
          <input id="ep-confirm" type="text" name="confirm_prefix" class="form" placeholder="Re-enter new prefix" required>
          <p class="form-hint">Re-enter the new prefix to confirm the change.</p>
        </div>
      </div>
    </div>

    <div class="composer-footer">
      <button type="submit" class="btn btn-danger">Rename Table</button>
      <a href="<?= $b ?>/admin/listings/<?= (int) $listing->id ?>/edit" class="btn btn-ghost">Cancel</a>
    </div>
  </div>
</form>
