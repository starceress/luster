<?php
use Luster\Core\View;
/** @var string $source */
/** @var string $source_name */
/** @var string $error */
/** @var string $base_url */
/** @var string $csrf_token */
$b = rtrim(View::e($base_url), '/');
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
  <div class="alert alert-error import-stack-gap"><?= View::e($error) ?></div>
<?php endif; ?>

<form id="import-bb-connect-form" method="POST"
      action="<?= $b ?>/admin/import/bellabuffs/connect"
      enctype="multipart/form-data">
  <input type="hidden" name="_csrf" value="<?= View::e($csrf_token) ?>">

  <div class="gradient-card">
    <div class="pc-head">
      <div class="pc-head-icon pc-head-icon-bg"><?= View::icon('import') ?></div>
      <div class="pc-head-meta">
        <div class="pc-head-title">Connect to BellaBuffs</div>
        <div class="pc-head-sub">BellaBuffs stores data in flat text files &mdash; no database credentials required.</div>
      </div>
    </div>

    <!-- ── Source mode selection ─────────────────────────────────────── -->
    <div class="pc-section">
      <div class="import-choice">
        <label class="import-choice-item">
          <input type="radio" name="bb_conn_mode" value="path" id="js-bb-mode-path" checked>
          <div>
            <div class="import-choice-title">Server folder path</div>
            <div class="import-choice-sub">Enter the absolute path to the BellaBuffs installation folder on this server. Supports members, affiliates, codes, and image migration.</div>
          </div>
        </label>
        <label class="import-choice-item">
          <input type="radio" name="bb_conn_mode" value="upload" id="js-bb-mode-upload">
          <div>
            <div class="import-choice-title">Upload files</div>
            <div class="import-choice-sub">Upload <code>members.txt</code> directly. Useful when BellaBuffs is on a different server. Members and pending members only &mdash; affiliate and code import require a folder path.</div>
          </div>
        </label>
      </div>
    </div>

    <!-- ── Server path section ─────────────────────────────────────── -->
    <div id="js-bb-path-section" class="pc-section">
      <div class="pc-section-label">BellaBuffs Folder Path</div>
      <div class="form-group">
        <input id="bb_folder_path" type="text" name="bb_folder_path" class="form import-input-wide"
               placeholder="/home/user/public_html/myfanlisting">
        <p class="field-hint-sm">
          Absolute server path to the BellaBuffs installation root — the folder containing
          <code>members.txt</code>, <code>affiliates.txt</code>, <code>buttons.txt</code>,
          and the <code>buttons/</code> image directory.
        </p>
      </div>
    </div>

    <!-- ── File upload section ─────────────────────────────────────── -->
    <div id="js-bb-upload-section" class="pc-section is-hidden">
      <div class="pc-section-label">Upload BellaBuffs Files</div>
      <div class="form-group">
        <label class="form-label" for="members_file">
          <code>members.txt</code> <span class="req">*</span>
        </label>
        <input id="members_file" type="file" name="members_file" accept=".txt"
               class="form import-input-wide">
        <p class="field-hint-sm">Approved members file. Required.</p>
      </div>
      <div class="form-group" style="margin-top:.75rem">
        <label class="form-label" for="newbies_file">
          <code>newbies.txt</code> <span class="text-dim">(optional)</span>
        </label>
        <input id="newbies_file" type="file" name="newbies_file" accept=".txt"
               class="form import-input-wide">
        <p class="field-hint-sm">Pending members awaiting approval. Leave empty to skip.</p>
      </div>
      <div class="callout callout-inline" style="margin-top:.75rem">
        <?= View::icon('alert', ['width' => 16, 'height' => 16, 'style' => 'color:var(--warn);flex-shrink:0']) ?>
        <span>Upload mode imports members only. To migrate affiliates and fan codes, use the server folder path instead.</span>
      </div>
    </div>

    <div class="composer-footer">
      <a href="<?= $b ?>/admin/import" class="btn btn-ghost">&#8592; Back</a>
      <button type="submit" class="btn btn-primary">Continue to Options &#8594;</button>
    </div>
  </div>
</form>

<p class="field-hint-sm import-stack-gap" style="text-align:center;color:var(--text-dim)">
  BellaBuffs data files are read-only &mdash; nothing in your BellaBuffs installation will be modified.
</p>

</div>

<script>
(function () {
  var pathSection   = document.getElementById('js-bb-path-section');
  var uploadSection = document.getElementById('js-bb-upload-section');
  var radios        = document.querySelectorAll('[name="bb_conn_mode"]');

  function update() {
    var upload = document.getElementById('js-bb-mode-upload').checked;
    pathSection.classList.toggle('is-hidden', upload);
    uploadSection.classList.toggle('is-hidden', !upload);
  }

  radios.forEach(function (r) { r.addEventListener('change', update); });
  update();
}());
</script>
