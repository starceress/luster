<?php
/*
 Luster -- https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Import wizard step 5 -- results. Displays the final import report with counts
 of imported, skipped, and failed records per data type, along with any
 warnings or errors encountered during the run.
*/
use Luster\Core\View;
use Luster\Import\ImportResult;
$b       = rtrim(View::e($base_url), '/');
$src     = View::e($source);
$srcName = View::e($source_name);
$hasErr  = $result->hasErrors();
?>

<div class="import-wizard">

<div class="wizard-steps">
  <div class="wizard-steps-line"></div>
  <div class="wizard-steps-progress wizard-steps-progress--complete"></div>
  <div class="wizard-step-node wizard-step-node--done">
    <div class="wizard-step-bubble"><?= View::icon('check') ?></div>
    <div class="wizard-step-label" data-step-sub="Pick an import source">Source</div>
  </div>
  <div class="wizard-step-node wizard-step-node--done">
    <div class="wizard-step-bubble"><?= View::icon('check') ?></div>
    <div class="wizard-step-label" data-step-sub="Connect or upload data">Connect</div>
  </div>
  <div class="wizard-step-node wizard-step-node--done">
    <div class="wizard-step-bubble"><?= View::icon('check') ?></div>
    <div class="wizard-step-label" data-step-sub="Choose what to import">Options</div>
  </div>
  <div class="wizard-step-node wizard-step-node--done">
    <div class="wizard-step-bubble"><?= View::icon('check') ?></div>
    <div class="wizard-step-label" data-step-sub="Review the dry run">Preview</div>
  </div>
  <div class="wizard-step-node wizard-step-node--active">
    <div class="wizard-step-bubble">5</div>
    <div class="wizard-step-label" data-step-sub="Results and summary">Done</div>
  </div>
</div>
<!-- ──── RESULT BANNER ──────────────────────────────────────────────── -->
<div class="card card--gradient">
  <div class="card-body" style="display:flex;align-items:center;gap:1.375rem;flex-wrap:wrap">
    <div style="width:52px;height:52px;border-radius:50%;flex-shrink:0;display:flex;align-items:center;justify-content:center">
      <?php if ($hasErr): ?><?= View::icon('alert') ?>
      <?php else: ?>
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" width="26" height="26"><polyline points="20 6 9 17 4 12"/></svg>
      <?php endif; ?>
    </div>
    <div style="flex:1;min-width:0">
      <div class="panel-header-title" style="margin-bottom:.25rem"><?= $hasErr ? 'Import Completed with Errors' : 'Import Complete' ?></div>
      <div class="panel-header-sub">
        <?= $srcName ?> &middot;
        <?= View::e($result->schemaVariant) ?> &middot;
        Completed in <?= number_format($result->duration, 2) ?>s &middot;
        <?= number_format($result->totalImported()) ?> imported, <?= number_format($result->totalSkipped()) ?> skipped
      </div>
    </div>
    <div class="actions-row" style="flex-shrink:0">
      <a href="<?= $b ?>/admin/import/<?= $src ?>/report" class="btn btn-secondary btn-sm">
        <?= View::icon('download') ?> Download Report
      </a>
    </div>
  </div>
</div>
<!-- ──── STAT TILES ─────────────────────────────────────────────────── -->
<div class="stats-grid stats-grid-row stats-grid-plain">
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
      <?php if ($result->totalImagesFailed() > 0): ?>
        <span class="badge badge-error"><?= number_format($result->totalImagesFailed()) ?> failed</span>
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


<div class="overview-section-divider"></div>

<!-- ──── RESULT TABLE ───────────────────────────────────────────────── -->
<div class="card card--gradient">
  <div class="panel-header">
    <div class="panel-header-icon"><?= View::icon('import') ?></div>
    <div class="panel-header-meta">
      <div class="panel-header-title">Import Details</div>
      <div class="panel-header-sub">See what was added, what was skipped, and whether any images or records need attention.</div>
    </div>
  </div>
  <div class="table-wrap">
    <table class="data-table">
      <thead>
        <tr>
          <th style="min-width:160px">Data Type</th>
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
          <td class="<?= $hasSkipDetail ? 'skip-summary-cell' : '' ?>" style="font-weight:600"><?= View::e($row['type']) ?></td>
          <td class="table-num<?= $hasSkipDetail ? ' skip-summary-cell' : '' ?>"><?= (int)$row['imported'] === 0 ? '<span class="text-dim">' . number_format($row['imported']) . '</span>' : number_format($row['imported']) ?></td>
          <td class="table-num<?= $hasSkipDetail ? ' skip-summary-cell' : '' ?>"><?= (int)$totalSkipped  === 0 ? '<span class="text-dim">' . number_format($totalSkipped)  . '</span>' : number_format($totalSkipped) ?></td>
          <td class="table-cell-notes">
            <?php foreach ($row['warnings'] as $w): ?>
              <span class="text-muted"><?= View::e($w) ?></span><br>
            <?php endforeach; ?>
            <?php foreach ($row['errors'] as $e): ?>
              <span style="color:var(--danger);display:inline-flex;align-items:center;gap:.3rem"><?= View::icon('alert') ?> <?= View::e($e) ?></span><br>
            <?php endforeach; ?>
            <?php if ($hasSkipDetail): ?>
              <?php if (!empty($row['warnings']) || !empty($row['errors'])): ?><br><?php endif; ?>
              <button type="button" class="skip-toggle btn btn-xs btn-quaternary" data-target="skip-<?= md5($row['type']) ?>">
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
                  <span style="font-weight:400;text-transform:none;letter-spacing:0">&mdash; Showing <?= count($showItems) ?> of <?= number_format($totalSkipped) ?><br>
                  <a href="<?= $b ?>/admin/import/<?= $src ?>/report" class="link">Download full report</a> for complete list</span>
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
              <span style="color:var(--danger);display:inline-flex;align-items:center;gap:.3rem"><?= View::icon('alert') ?> <?= View::e($e) ?></span><br>
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

<?php if (empty($result->imageRows) && $result->totalImported() > 0): ?>
  <div class="callout callout-warn">
    <?= View::icon('alert') ?>
    <div><strong>No images were migrated.</strong> To copy images too, <a href="<?= $b ?>/admin/import/<?= $src ?>/connect" class="link">run the import again</a> and provide a server path, old site URL, or ZIP of your image folder.</div>
  </div>
<?php endif; ?>
<!-- ──── ACTIONS ────────────────────────────────────────────────────── -->
<div class="actions-row actions-row--wrap" style="justify-content:space-between;margin-top:1.5rem">
  <a href="<?= $b ?>/admin/import" class="btn btn-secondary">&larr; Run Another Import </a>
  <a href="<?= $b ?>/admin" class="btn btn-primary">Go to Dashboard &rarr;</a>
</div>

</div>
