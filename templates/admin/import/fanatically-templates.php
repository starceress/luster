<?php
/*
 Luster -- https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Fanatically template rescue — step 1. Upload the Fanatically ZIP archive so
 Luster can extract and translate the template files into its own format.
*/
use Luster\Core\View;
$b = rtrim(View::e($base_url), '/');
$csrf = View::e($csrf_token);
?>

<div class="page-topbar">
  <a href="<?= $b ?>/admin/import" class="link">&larr; Back to Import</a>
</div>

<?php if ($error !== ''): ?>
  <div class="alert alert-error"><?= View::e($error) ?></div>
<?php endif; ?>

<div class="gradient-card listing-field-section">
    <div class="listing-field-row listing-field-row--top">
      <label class="listing-field-label">About this tool</label>
      <div>
        <p class="form-hint">Upload your Fanatically 1.x installation ZIP (or a ZIP of your templates folder) to extract and translate the template files into Luster's format. Templates that can be imported directly will be shown with an import option; others will be available to download for reference.</p>
        <p class="form-hint">Supported files: <code>templates/display/*.php</code>, <code>templates/email/*.txt</code>, and per-listing template overrides.</p>
      </div>
    </div>
    <div class="listing-field-row">
      <label class="listing-field-label">What gets translated</label>
      <div>
        <ul class="form-hint">
          <li><strong>Stats, collective stats, owned listings, joined listings</strong> — translated and importable.</li>
          <li><strong>Email templates</strong> — translated and importable.</li>
          <li><strong>Members, join form, update form, affiliates, codes</strong> — preview only (structural differences; download and adapt manually).</li>
        </ul>
      </div>
    </div>
  </div>

<form method="POST" action="<?= $b ?>/admin/import/fanatically/templates" enctype="multipart/form-data">
  <input type="hidden" name="_csrf" value="<?= $csrf ?>">

  <div class="gradient-card">
    <div class="listing-field-section">
      <div class="listing-field-row listing-field-row--top">
        <label class="listing-field-label" for="template_zip">Fanatically ZIP <span class="req">*</span></label>
        <div>
          <label class="file-drop-zone" for="template_zip">
            <input type="file" id="template_zip" name="template_zip" accept=".zip,application/zip">
            <span class="file-drop-zone__label">Choose ZIP file or drag and drop</span>
          </label>
          <p class="form-hint">Max 20 MB. ZIP must contain Fanatically template files.</p>
        </div>
      </div>
    </div>
    <div class="composer-footer">
      <button type="submit" class="btn btn-primary">Upload &amp; Analyse</button>
      <a href="<?= $b ?>/admin/import" class="btn btn-ghost">Cancel</a>
    </div>
  </div>
</form>
