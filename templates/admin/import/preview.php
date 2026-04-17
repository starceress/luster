<?php
use Luster\Core\View;
use Luster\Import\ImportPreview;
/** @var string             $source */
/** @var string             $source_name */
/** @var ImportPreview|null $preview */
/** @var string             $error */
/** @var string             $base_url */
/** @var string             $csrf_token */
$b   = rtrim(View::e($base_url), '/');
$src = View::e($source);
?>

<div class="wizard-steps">
  <div class="wizard-steps-line"></div>
  <div class="wizard-steps-progress" style="width:66%"></div>
  <div class="wizard-step-node wizard-step-node--done">
    <div class="wizard-step-bubble">&#10003;</div>
    <div class="wizard-step-label">Choose Source</div>
  </div>
  <div class="wizard-step-node wizard-step-node--done">
    <div class="wizard-step-bubble">&#10003;</div>
    <div class="wizard-step-label">Configure</div>
  </div>
  <div class="wizard-step-node wizard-step-node--active">
    <div class="wizard-step-bubble">3</div>
    <div class="wizard-step-label">Preview</div>
  </div>
  <div class="wizard-step-node">
    <div class="wizard-step-bubble">4</div>
    <div class="wizard-step-label">Import</div>
  </div>
</div>

<?php if ($error !== ''): ?>
  <div class="alert alert-error" style="margin-bottom:1.25rem"><?= View::e($error) ?></div>
  <a href="<?= $b ?>/admin/import/<?= $src ?>/configure" class="btn btn-secondary">&#8592; Back to Configure</a>
<?php elseif ($preview === null): ?>
  <div class="alert alert-error" style="margin-bottom:1.25rem">Preview data is unavailable.</div>
  <a href="<?= $b ?>/admin/import/<?= $src ?>/configure" class="btn btn-secondary">&#8592; Back to Configure</a>
<?php else: ?>

  <?php if ($preview->sameDatabase): ?>
    <div class="alert alert-info" style="margin-bottom:1.25rem">
      <?= View::icon('alert') ?>
      Luster is already connected to this database. Member tables are already readable &mdash; no member data will be moved.
    </div>
  <?php endif; ?>

  <?php $allWarnings = $preview->allWarnings(); ?>
  <?php if (!empty($allWarnings)): ?>
    <div class="callout" style="margin-bottom:1.25rem">
      <strong>Notices</strong>
      <ul style="margin:.375rem 0 0;padding-left:1.25rem">
        <?php foreach ($allWarnings as $w): ?>
          <li><?= View::e($w) ?></li>
        <?php endforeach; ?>
      </ul>
    </div>
  <?php endif; ?>

  <?php
    $totalFound  = (int) array_sum(array_column($preview->rows, 'found'));
    $totalImport = $preview->totalToImport();
    $totalSkip   = (int) array_sum(array_column($preview->rows, 'skip'));
  ?>
  <div class="stats-grid stats-grid-row stats-grid--plain" style="margin-bottom:1.25rem">
    <div class="stat-card">
      <div class="stat-header">
        <span class="stat-icon"><?= View::icon('database') ?></span>
        <span class="stat-label">Found</span>
      </div>
      <div class="stat-value"><?= number_format($totalFound) ?></div>
      <div class="stat-pills"><span class="badge badge-inactive">In source</span></div>
    </div>
    <div class="stat-card">
      <div class="stat-header">
        <span class="stat-icon"><?= View::icon('import') ?></span>
        <span class="stat-label">Would Import</span>
      </div>
      <div class="stat-value"><?= number_format($totalImport) ?></div>
      <div class="stat-pills">
        <?php if ($totalFound > 0): ?>
          <span class="badge badge-current"><?= number_format(round($totalImport / $totalFound * 100)) ?>% of found</span>
        <?php else: ?>
          <span class="badge badge-inactive">Nothing to import</span>
        <?php endif; ?>
      </div>
    </div>
    <div class="stat-card">
      <div class="stat-header">
        <span class="stat-icon"><?= View::icon('cancel') ?></span>
        <span class="stat-label">Would Skip</span>
      </div>
      <div class="stat-value"><?= number_format($totalSkip) ?></div>
      <div class="stat-pills">
        <?php if ($totalSkip > 0): ?>
          <span class="badge badge-pending">Already exist</span>
        <?php else: ?>
          <span class="badge badge-current">None</span>
        <?php endif; ?>
      </div>
    </div>
    <div class="stat-card">
      <div class="stat-header">
        <span class="stat-icon"><?= View::icon('image') ?></span>
        <span class="stat-label">Images</span>
      </div>
      <div class="stat-value">&mdash;</div>
      <div class="stat-pills"><span class="badge badge-inactive">Counted at run</span></div>
    </div>
  </div>

  <div style="display:inline-flex;align-items:center;gap:.4rem;font-size:var(--fs-base);color:var(--text-muted);margin-bottom:1.125rem">
    <?= View::icon('database', ['style' => 'width:13px;height:13px;flex-shrink:0;color:var(--accent);opacity:.7']) ?>
    Schema detected: <strong style="color:var(--text)"><?= View::e($preview->schemaVariant) ?></strong>
  </div>

  <div class="gradient-card">
    <div class="pc-head">
      <div class="pc-head-icon pc-head-icon-bg"><?= View::icon('filter') ?></div>
      <div class="pc-head-meta">
        <div class="pc-head-title">Import Preview</div>
        <div class="pc-head-sub"><?= number_format($totalImport) ?> record(s) would be imported across <?= count($preview->rows) ?> data type(s).</div>
      </div>
    </div>
    <div style="overflow-x:auto">
      <table class="data-table">
        <thead>
          <tr>
            <th>Data Type</th>
            <th style="text-align:right">Found</th>
            <th style="text-align:right">Would Import</th>
            <th style="text-align:right">Would Skip</th>
            <th>Notes</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($preview->rows as $row): ?>
          <tr>
            <td><?= View::e($row['type']) ?></td>
            <td style="text-align:right"><?= number_format($row['found']) ?></td>
            <td style="text-align:right"><?= number_format($row['import']) ?></td>
            <td style="text-align:right"><?= number_format($row['skip']) ?></td>
            <td style="font-size:.8rem;color:var(--text-dim)">
              <?= implode('<br>', array_map([View::class, 'e'], $row['warnings'])) ?: '&mdash;' ?>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <div style="padding:.75rem 1.5rem;border-top:1px solid var(--border);font-size:.8rem;color:var(--text-muted);display:flex;align-items:center;gap:.5rem">
      <span><span style="color: var(--warn)";><?= View::icon('alert') ?> <strong>Image files are copied by filename only.</strong></span> Original files remain in place on your old server. Luster assigns new randomised filenames to all copied images.</span>
    </div>
  </div>

  <form method="POST" action="<?= $b ?>/admin/import/<?= $src ?>/run">
    <input type="hidden" name="_csrf" value="<?= View::e($csrf_token) ?>">
    <div class="btn-row" style="align-items:center;flex-wrap:wrap">
      <a href="<?= $b ?>/admin/import/<?= $src ?>/configure" class="btn btn-ghost">&#8592; Back</a>
      <button type="submit" class="btn btn-primary">Proceed with Import &#8594;</button>
    </div>
  </form>

<?php endif; ?>
