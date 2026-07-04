<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Edit database table prefix page for a single listing. Allows changing the member
 table name (dbtable) with validation, and shows whether the target table already
 exists in the database. This is a rarely used maintenance operation.
*/
use Luster\Core\View;
$b = rtrim(View::e($base_url), '/');
?>

<div class="toolbar">
  <a href="<?= $b ?>/admin/listings/<?= (int) $listing->id ?>/edit" class="link">
    &larr; Back to <?= View::e($listing->title) ?>
  </a>
</div>

<?php if (($error ?? '') !== ''): ?>
  <div class="alert alert-error"><?= View::e($error) ?></div>
<?php endif; ?>

<?php if (!$table_exists): ?>
  <div class="alert alert-error" style="margin-bottom:1.25rem">
    <strong>Warning:</strong> the current member storage
    <code><?= View::e($listing->dbprefix) ?></code>
    could not be found. Changing this now may cause more problems.
  </div>
<?php endif; ?>

<form method="POST" action="<?= $b ?>/admin/listings/<?= (int) $listing->id ?>/edit-prefix" class="js-warn-unsaved"
      data-del-modal
      data-del-entity="rename_prefix"
      data-del-name="<?= View::e($listing->dbprefix) ?>"
      data-del-context-from="new_prefix">
  <input type="hidden" name="_csrf" value="<?= View::e($csrf_token ?? '') ?>">

  <div class="card card--gradient">
    <div class="panel-header">
      <div class="panel-header-icon"><?= View::icon('database') ?></div>
      <div class="panel-header-meta">
        <div class="panel-header-title">Rename Member Storage</div>
        <div class="panel-header-sub">Changes the internal name Luster uses for this listing's member records.</div>
      </div>
    </div>

    <div class="card-body">
      <div class="callout callout-err"><?= View::icon('alert') ?>
        <div><strong>High-risk change.</strong> This renames where this listing's members are stored. If anything goes wrong, Luster may not be able to find those members. There is no undo - <b>back up your database before proceeding.</b></div>
    </div>

      <div class="field-row">
        <span class="field-label">Current Prefix</span>
        <div>
          <div class="readonly-field"><?= View::e($listing->dbprefix) ?></div>
          <p class="form-hint">The current internal name for this listing's member records.</p>
        </div>
      </div>

      <div class="field-row">
        <label class="field-label" for="ep-new-prefix">
          New Prefix <span class="req">*</span>
        </label>
        <div>
          <input id="ep-new-prefix" type="text" name="new_prefix" class="form" pattern="[a-zA-Z0-9_]+" placeholder="e.g. fl_music_" required>
          <p class="form-hint">Use letters, numbers, and underscores only. Choose a name Luster is not already using.</p>
        </div>
      </div>

      <div class="field-row">
        <label class="field-label" for="ep-confirm">
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
