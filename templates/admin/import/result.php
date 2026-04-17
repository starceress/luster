<?php
use Luster\Core\View;
use Luster\Import\ImportResult;
/** @var string       $source */
/** @var string       $source_name */
/** @var ImportResult $result */
/** @var bool         $show_zip_form */
/** @var string       $base_url */
/** @var string       $csrf_token */
$b       = rtrim(View::e($base_url), '/');
$src     = View::e($source);
$srcName = View::e($source_name);
$hasErr  = $result->hasErrors();
?>

<div class="wizard-steps">
  <div class="wizard-steps-line"></div>
  <div class="wizard-steps-progress wizard-steps-progress--complete"></div>
  <div class="wizard-step-node wizard-step-node--done">
    <div class="wizard-step-bubble">&#10003;</div>
    <div class="wizard-step-label">Choose Source</div>
  </div>
  <div class="wizard-step-node wizard-step-node--done">
    <div class="wizard-step-bubble">&#10003;</div>
    <div class="wizard-step-label">Configure</div>
  </div>
  <div class="wizard-step-node wizard-step-node--done">
    <div class="wizard-step-bubble">&#10003;</div>
    <div class="wizard-step-label">Preview</div>
  </div>
  <div class="wizard-step-node wizard-step-node--active">
    <div class="wizard-step-bubble">4</div>
    <div class="wizard-step-label">Import</div>
  </div>
</div>

<!-- Result banner -->
<div class="import-result-banner import-result-banner--<?= $hasErr ? 'error' : 'success' ?>">
  <div class="import-result-banner-inner">
    <div class="import-result-icon import-result-icon--<?= $hasErr ? 'error' : 'success' ?>">
      <?php if ($hasErr): ?><?= View::icon('alert') ?>
      <?php else: ?>
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
      <?php endif; ?>
    </div>
    <div class="import-result-text">
      <div class="import-result-title"><?= $hasErr ? 'Import Completed with Errors' : 'Import Complete' ?></div>
      <div class="import-result-sub">
        <?= $srcName ?> &middot;
        <?= View::e($result->schemaVariant) ?> &middot;
        Completed in <?= number_format($result->duration, 2) ?>s &middot;
        <?= number_format($result->totalImported()) ?> imported, <?= number_format($result->totalSkipped()) ?> skipped
      </div>
    </div>
    <div class="import-result-actions">
      <a href="<?= $b ?>/admin/import/<?= $src ?>/report" class="btn btn-secondary btn-sm">
        <?= View::icon('download') ?> Download Report
      </a>
    </div>
  </div>
</div>

<!-- Stat tiles -->
<div class="stats-grid stats-grid-row stats-grid--plain import-stack-gap">
  <div class="stat-card">
    <div class="stat-header">
      <span class="stat-icon"><?= View::icon('import') ?></span>
      <span class="stat-label">Imported</span>
    </div>
    <div class="stat-value"><?= number_format($result->totalImported()) ?></div>
    <div class="stat-pills">
      <?php if ($result->totalImported() > 0): ?>
        <span class="badge badge-current">Records added</span>
      <?php else: ?>
        <span class="badge badge-inactive">Nothing imported</span>
      <?php endif; ?>
    </div>
  </div>
  <div class="stat-card">
    <div class="stat-header">
      <span class="stat-icon"><?= View::icon('cancel') ?></span>
      <span class="stat-label">Skipped</span>
    </div>
    <div class="stat-value"><?= number_format($result->totalSkipped()) ?></div>
    <div class="stat-pills">
      <?php if ($result->totalSkipped() > 0): ?>
        <span class="badge badge-pending">Already existed</span>
      <?php else: ?>
        <span class="badge badge-current">None skipped</span>
      <?php endif; ?>
    </div>
  </div>
  <div class="stat-card">
    <div class="stat-header">
      <span class="stat-icon"><?= View::icon('image') ?></span>
      <span class="stat-label">Images Copied</span>
    </div>
    <div class="stat-value"><?= number_format($result->totalImagesCopied()) ?></div>
    <div class="stat-pills">
      <?php if ($result->totalImagesCopied() > 0): ?>
        <span class="badge badge-info">Files migrated</span>
      <?php else: ?>
        <span class="badge badge-inactive">None</span>
      <?php endif; ?>
    </div>
  </div>
  <div class="stat-card">
    <div class="stat-header">
      <span class="stat-icon"><?= View::icon('timer') ?></span>
      <span class="stat-label">Duration</span>
    </div>
    <div class="stat-value"><?= number_format($result->duration, 2) ?>s</div>
    <div class="stat-pills"><span class="badge badge-inactive">Seconds elapsed</span></div>
  </div>
</div>

<!-- Result table -->
<div class="gradient-card import-stack-gap">
  <div class="import-scroll-x">
    <table class="data-table">
      <thead>
        <tr>
          <th>Data Type</th>
          <th class="table-num">Imported</th>
          <th class="table-num">Skipped</th>
          <th>Notes / Errors</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($result->rows as $row): ?>
        <?php
          $hasSkipDetail = !empty($row['skipped_items']);
          $skipItems     = $row['skipped_items'] ?? [];
          $skipCount     = count($skipItems);
          $totalSkipped  = $row['skipped'];
          $overflow      = $totalSkipped - $skipCount; // items beyond cap not in array
          $showItems     = array_slice($skipItems, 0, 50);
          $moreInArray   = $skipCount - count($showItems);
        ?>
        <tr>
          <td class="<?= $hasSkipDetail ? 'skip-summary-cell' : '' ?>"><?= View::e($row['type']) ?></td>
          <td class="table-num<?= $hasSkipDetail ? ' skip-summary-cell' : '' ?>"><?= number_format($row['imported']) ?></td>
          <td class="table-num<?= $hasSkipDetail ? ' skip-summary-cell' : '' ?>"><?= number_format($totalSkipped) ?></td>
          <td class="table-cell-notes">
            <?php foreach ($row['warnings'] as $w): ?>
              <span class="table-note-muted"><?= View::e($w) ?></span><br>
            <?php endforeach; ?>
            <?php foreach ($row['errors'] as $e): ?>
              <span class="table-note-danger"><?= View::icon('alert') ?> <?= View::e($e) ?></span><br>
            <?php endforeach; ?>
            <?php if ($hasSkipDetail): ?>
              <?php if (!empty($row['warnings']) || !empty($row['errors'])): ?><br><?php endif; ?>
              <button type="button" class="skip-toggle link" data-target="skip-<?= md5($row['type']) ?>">
                View <?= number_format($totalSkipped) ?> skipped item<?= $totalSkipped !== 1 ? 's' : '' ?> <span class="skip-toggle-arrow">&#9658;</span>
              </button>
            <?php elseif (empty($row['warnings']) && empty($row['errors'])): ?>
              <span class="table-note-empty">&mdash;</span>
            <?php endif; ?>
          </td>
        </tr>
        <?php if ($hasSkipDetail): ?>
        <tr class="skip-detail-row table-row-hidden" id="skip-<?= md5($row['type']) ?>">
          <td colspan="4" class="skip-detail-cell">
            <div class="skip-detail-inner">
              <div class="skip-detail-head">
                Skipped <?= View::e($row['type']) ?>
                <?php if ($totalSkipped > 50 || $overflow > 0): ?>
                  &mdash; showing <?= count($showItems) ?> of <?= number_format($totalSkipped) ?>
                  &middot; <a href="<?= $b ?>/admin/import/<?= $src ?>/report" class="link">Download full report</a> for complete list
                <?php endif; ?>
              </div>
              <table class="skip-inner-table">
                <thead>
                  <tr>
                    <th>Item</th>
                    <th>Reason</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($showItems as $si): ?>
                  <tr>
                    <td><?= View::e($si['label']) ?></td>
                    <td class="text-muted"><?= View::e($si['reason']) ?></td>
                  </tr>
                  <?php endforeach; ?>
                  <?php if ($moreInArray > 0): ?>
                  <tr>
                    <td colspan="2" class="text-muted table-note-italic">
                      &hellip; and <?= number_format($moreInArray + $overflow) ?> more &mdash;
                      <a href="<?= $b ?>/admin/import/<?= $src ?>/report" class="link">download full report</a>
                    </td>
                  </tr>
                  <?php elseif ($overflow > 0): ?>
                  <tr>
                    <td colspan="2" class="text-muted table-note-italic">
                      &hellip; and <?= number_format($overflow) ?> more &mdash;
                      <a href="<?= $b ?>/admin/import/<?= $src ?>/report" class="link">download full report</a>
                    </td>
                  </tr>
                  <?php endif; ?>
                </tbody>
              </table>
            </div>
          </td>
        </tr>
        <?php endif; ?>
        <?php endforeach; ?>
        <?php foreach ($result->imageRows as $row): ?>
        <tr>
          <td><?= View::e($row['type']) ?></td>
          <td class="table-num"><?= number_format($row['copied']) ?></td>
          <td class="table-num"><?= number_format($row['skipped']) ?></td>
          <td class="table-cell-notes">
            <?php foreach ($row['errors'] as $e): ?>
              <span class="table-note-danger"><?= View::icon('alert') ?> <?= View::e($e) ?></span><br>
            <?php endforeach; ?>
            <?php if (empty($row['errors'])): ?>
              <span class="table-note-empty">&mdash;</span>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- ZIP image upload (optional, shown when image_mode=zip) -->
<?php if ($show_zip_form): ?>
<div class="gradient-card import-stack-gap">
  <div class="pc-head">
    <div class="pc-head-icon pc-head-icon-bg"><?= View::icon('embed') ?></div>
    <div class="pc-head-meta">
      <div class="pc-head-title">Upload Image Archive</div>
      <div class="pc-head-sub">Upload a ZIP containing your image files. Luster will match them to the imported records by filename.</div>
    </div>
  </div>
  <div class="pc-section">
    <p class="import-zip-intro">
      Create a ZIP containing all image files from your old installation (affiliate images,
      joined listing images, listing images). The ZIP may contain subdirectories &mdash; only filenames
      are used for matching. Maximum size: <strong>50 MB</strong>.
    </p>
    <form method="POST" action="<?= $b ?>/admin/import/<?= $src ?>/upload-images" enctype="multipart/form-data">
      <input type="hidden" name="_csrf" value="<?= View::e($csrf_token) ?>">
      <div class="form-group import-input-zip">
        <label class="form-label" for="zip_file">ZIP Archive</label>
        <input id="zip_file" type="file" name="zip_file" accept=".zip" class="form-input" required>
      </div>
      <button type="submit" class="btn btn-primary">Upload &amp; Apply Images</button>
    </form>
  </div>
</div>
<?php elseif (empty($result->imageRows)): ?>
  <div class="callout">
    <?= View::icon('alert', ['width' => 18, 'height' => 18, 'style' => 'color:var(--warn)']) ?>
    No images were migrated. If you need to migrate images, you can
    <a href="<?= $b ?>/admin/import/<?= $src ?>/configure" class="link">re-run the import</a>
    with image migration enabled.
  </div>
<?php endif; ?>

<!-- Actions -->
<div class="btn-row import-action-row">
  <a href="<?= $b ?>/admin" class="btn btn-primary">Go to Dashboard</a>
  <a href="<?= $b ?>/admin/import" class="btn btn-secondary">Run Another Import</a>
</div>
