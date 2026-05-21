<?php
use Luster\Core\View;
/** @var \Luster\Models\Listing[] $listings */
/** @var string                   $error */
/** @var string                   $base_url */
/** @var string                   $csrf_token */
$b = rtrim(View::e($base_url), '/');
?>

<div class="page-topbar">
  <a href="<?= $b ?>/admin/import" class="link">&larr; Back to Import</a>
</div>

<?php if ($error !== ''): ?>
<div class="callout callout-error" style="margin-bottom:1.25rem">
  <?= View::icon('alert') ?>
  <div><?= View::e($error) ?></div>
</div>
<?php endif; ?>

<div class="gradient-card">
  <div class="pc-head">
    <div class="pc-head-icon pc-head-icon-bg"><?= View::icon('import') ?></div>
    <div class="pc-head-meta">
      <div class="pc-head-title">Import Members Only</div>
      <div class="pc-head-sub">
        Supplemental tool — use this when you only need to import members into
        an existing Luster listing (e.g. after running the main Import Wizard,
        or to merge a standalone member table export). For a full site migration,
        use <a href="<?= $b ?>/admin/import" class="link">Import Data</a> instead.
      </div>
    </div>
  </div>

  <form id="im-upload-form" method="POST" action="<?= $b ?>/admin/import-members/upload"
        enctype="multipart/form-data">
    <input type="hidden" name="_csrf" value="<?= View::e($csrf_token) ?>">

    <div class="listing-field-section">
      <div class="listing-field-row">
        <label class="listing-field-label" for="im-listing">
          Destination Listing <span class="req">*</span>
        </label>
        <div>
          <div class="select-wrap">
            <select id="im-listing" name="listing_id" required>
              <option value="">— Select —</option>
              <?php foreach ($listings as $fl): ?>
              <option value="<?= (int) $fl->id ?>"><?= View::e($fl->name) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <p class="form-hint">Members will be inserted into this listing's member table.</p>
        </div>
      </div>

      <div class="listing-field-row listing-field-row--top">
        <label class="listing-field-label" for="im-file">
          Upload File <span class="req">*</span>
        </label>
        <div>
          <label class="file-drop-zone" for="im-file">
            <input id="im-file" type="file" name="sql_file" accept=".sql" required>
            <span class="file-drop-icon"><?= View::icon('import') ?></span>
            <strong>Click to upload</strong>
            <span class="form-hint">.SQL &mdash; max 10 MB</span>
          </label>
        </div>
      </div>
    </div>
  </form>

  <div class="composer-footer">
    <button type="submit" form="im-upload-form" class="btn btn-primary">Upload &amp; Analyse</button>
    <a href="<?= $b ?>/admin/import" class="btn btn-ghost">Cancel</a>
  </div>
</div>

<div class="callout callout-inline" style="margin-top:1rem">
  <?= View::icon('notes') ?>
  <div>
    <strong>How duplicates are handled:</strong> members with an email address already
    present in the target table are silently skipped (no overwrite). Re-running the
    import on the same dump is safe.
  </div>
</div>
