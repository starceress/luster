<?php
use Luster\Core\View;
/** @var \Luster\Models\Listing[] $listings */
/** @var string                   $error */
/** @var string                   $base_url */
/** @var string                   $csrf_token */
$b = rtrim(View::e($base_url), '/');
?>

<div class="toolbar">
  <a href="<?= $b ?>/admin/import" class="link">&larr; Back to Import Data Wizard</a>
</div>

<?php if ($error !== ''): ?>
<div class="alert alert-error">
  <?= View::icon('alert') ?>
  <div><?= View::e($error) ?></div>
</div>
<?php endif; ?>

<div class="gradient-card">
  <div class="pc-head">
    <div class="pc-head-icon pc-head-icon-bg"><?= View::icon('importmembers') ?></div>
    <div class="pc-head-meta">
      <div class="pc-head-title">Import Members Only</div>
      <div class="pc-head-sub">
        Use this smaller tool when you only need to add members to
        an existing Luster listing. For a full site migration,
        use the <a href="<?= $b ?>/admin/import" class="link">Import Data Wizard</a> instead.
      </div>
    </div>
  </div>

  <form id="im-upload-form" method="POST" action="<?= $b ?>/admin/import-members/upload" enctype="multipart/form-data">
    <input type="hidden" name="_csrf" value="<?= View::e($csrf_token) ?>">

    <div class="pc-section">
      <div class="callout callout-info">
        <?= View::icon('notes') ?>
        <div>
          <strong>How duplicates are handled:</strong> members with an email address already
          already present in the selected listing are skipped. Existing members are not changed,
          so re-running the same file is safe.
        </div>
      </div>

      <div class="listing-field-row">
        <label class="listing-field-label" for="im-listing">Destination Listing <span class="req">*</span></label>
        <div>
          <div class="select-wrap select-auto">
            <select id="im-listing" name="listing_id" required>
              <option value="">— Select —</option>
              <?php foreach ($listings as $fl): ?>
              <option value="<?= (int) $fl->id ?>"><?= View::e($fl->title) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <p class="form-hint">Choose the Luster listing these members should belong to.</p>
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
            <strong>Drag &amp; drop or click to browse</strong>
            <span class="form-hint">.SQL &mdash; max 10 MB</span>
          </label>
          <p class="form-hint" style="margin-top:.5rem">Upload a SQL export of a member table from Enthusiast or Listing Admin.</p>
        </div>
      </div>
    </div>
  </form>

  <div class="composer-footer">
    <button type="submit" form="im-upload-form" class="btn btn-primary">Upload &amp; Analyse</button>
    <a href="<?= $b ?>/admin/import" class="btn btn-ghost">Cancel</a>
  </div>
</div>
