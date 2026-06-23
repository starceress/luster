<?php
use Luster\Core\View;
/** @var string $source */
/** @var string $source_name */
/** @var string $error */
/** @var string $conn_mode */
/** @var string $base_url */
/** @var string $csrf_token */
$b       = rtrim(View::e($base_url), '/');
$bbMode  = ($conn_mode ?? 'path') === 'upload' ? 'upload' : 'path';
?>

<div class="import-wizard">

<div class="wizard-steps">
  <div class="wizard-steps-line"></div>
  <div class="wizard-steps-progress wizard-steps-progress--connect"></div>
  <div class="wizard-step-node wizard-step-node--done">
    <div class="wizard-step-bubble"><?= View::icon('check') ?></div>
    <div class="wizard-step-label" data-step-sub="Pick an import source">Source</div>
  </div>
  <div class="wizard-step-node wizard-step-node--active">
    <div class="wizard-step-bubble">2</div>
    <div class="wizard-step-label" data-step-sub="Choose files or folder">Connect</div>
  </div>
  <div class="wizard-step-node">
    <div class="wizard-step-bubble">3</div>
    <div class="wizard-step-label" data-step-sub="Choose what to import">Options</div>
  </div>
  <div class="wizard-step-node">
    <div class="wizard-step-bubble">4</div>
    <div class="wizard-step-label" data-step-sub="Review the dry run">Preview</div>
  </div>
  <div class="wizard-step-node">
    <div class="wizard-step-bubble">5</div>
    <div class="wizard-step-label" data-step-sub="Results and summary">Done</div>
  </div>
</div>

<?php if ($error !== ''): ?>
  <div class="alert alert-error"><?= View::e($error) ?></div>
<?php endif; ?>

<form id="import-bb-connect-form" method="POST"
      action="<?= $b ?>/admin/import/bellabuffs/connect"
      enctype="multipart/form-data">
  <input type="hidden" name="_csrf" value="<?= View::e($csrf_token) ?>">

  <div class="card card--gradient">
    <div class="pc-head">
      <div class="pc-head-icon pc-head-icon-bg"><?= View::icon('import') ?></div>
      <div class="pc-head-meta">
        <div class="pc-head-title">Connect to BellaBuffs</div>
        <div class="pc-head-sub">BellaBuffs keeps its data in text files, so no database login is needed.</div>
      </div>
    </div>

    <!-- ── Source mode selection ─────────────────────────────────────── -->
    <div class="card-body">
      <p>Luster only reads the BellaBuffs files. It does not edit or delete anything in the old install.</p>
      <div class="import-choice card-body">
        <label class="import-choice-item">
          <input type="radio" name="bb_conn_mode" value="path" id="js-bb-mode-path"<?= $bbMode === 'path' ? ' checked' : '' ?>>
          <div>
            <strong>Server folder path</strong>
            <span class="form-hint" style="margin:2px 0 0">Use this when BellaBuffs is on the same server. This supports members, affiliates, fan codes, and button images.</span>
          </div>
        </label>
        <label class="import-choice-item">
          <input type="radio" name="bb_conn_mode" value="upload" id="js-bb-mode-upload"<?= $bbMode === 'upload' ? ' checked' : '' ?>>
          <div>
            <strong>Upload files</strong>
            <span class="form-hint" style="margin:2px 0 0">Use this when BellaBuffs is on another server. Upload mode can import approved and pending members only.</span>
          </div>
        </label>
      </div>
    </div>

    <!-- ── Server path section ─────────────────────────────────────── -->
    <div id="js-bb-path-section" class="card-body<?= $bbMode === 'upload' ? ' is-hidden' : '' ?>">
      <div class="pc-section-label">BellaBuffs Folder Path</div>
      <div class="card-body">
      <div class="field-row field-row--top">
        <label class="field-label" for="bb_folder_path">Folder Path</label>
        <div>
          <input id="bb_folder_path" type="text" name="bb_folder_path" class="form"
                 placeholder="/home/user/public_html/myfanlisting">
          <p class="form-hint">The server folder where BellaBuffs is installed. It should contain
            <code>members.txt</code>, <code>affiliates.txt</code>, <code>buttons.txt</code>,
            and the <code>buttons/</code> image directory.</p>
        </div>
      </div>
    </div>
    </div>

    <!-- ── File upload section ─────────────────────────────────────── -->
    <div id="js-bb-upload-section" class="card-body<?= $bbMode === 'upload' ? '' : ' is-hidden' ?>">
      <div class="pc-section-label">Upload BellaBuffs Files</div>

      <div class="callout callout-warn">
        <?= View::icon('alert') ?>
        <div><strong>Upload mode imports members only.</strong> To migrate affiliates and fan codes, use the server folder path instead.</div>
      </div>

      <div class="field-row field-row--top">
        <label class="field-label" for="members_file"><code>members.txt</code> <span class="req">*</span></label>
        <div>
          <label class="file-drop-zone" for="members_file">
            <input id="members_file" type="file" name="members_file" accept=".txt">
            <span class="file-drop-icon"><?= View::icon('import') ?></span>
            <strong>Drag &amp; drop or click to browse</strong>
            <span class="form-hint">.TXT &mdash; approved members</span>
          </label>
        </div>
      </div>
      <div class="field-row field-row--top" style="margin-top:.5rem">
        <label class="field-label" for="newbies_file"><code>newbies.txt</code> <span class="text-dim">(optional)</span></label>
        <div>
          <label class="file-drop-zone" for="newbies_file">
            <input id="newbies_file" type="file" name="newbies_file" accept=".txt">
            <span class="file-drop-icon"><?= View::icon('import') ?></span>
            <strong>Drag &amp; drop or click to browse</strong>
            <span class="form-hint">.TXT &mdash; pending members, leave empty to skip</span>
          </label>
        </div>
      </div>
    </div>

    <div class="composer-footer">
      <a href="<?= $b ?>/admin/import" class="btn btn-quaternary">&#8592; Back</a>
      <button type="submit" class="btn btn-primary">Continue to Options &#8594;</button>
    </div>
  </div>
</form>

</div>
