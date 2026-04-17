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
  <div class="wizard-steps-progress wizard-steps-progress--preview"></div>
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
  <div class="alert alert-error import-stack-gap"><?= View::e($error) ?></div>
  <a href="<?= $b ?>/admin/import/<?= $src ?>/configure" class="btn btn-secondary">&#8592; Back to Configure</a>
<?php elseif ($preview === null): ?>
  <div class="alert alert-error import-stack-gap">Preview data is unavailable.</div>
  <a href="<?= $b ?>/admin/import/<?= $src ?>/configure" class="btn btn-secondary">&#8592; Back to Configure</a>
<?php else: ?>

  <?php if ($preview->sameDatabase): ?>
    <div class="alert alert-info import-stack-gap">
      <?= View::icon('alert') ?>
      Luster is already connected to this database. Member tables are already readable &mdash; no member data will be moved.
    </div>
  <?php endif; ?>

  <?php $allWarnings = $preview->allWarnings(); ?>
  <?php if (!empty($allWarnings)): ?>
    <div class="callout import-stack-gap">
      <strong>Notices</strong>
      <ul class="list-spaced">
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
  <div class="stats-grid stats-grid-row stats-grid--plain import-stack-gap">
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

  <div class="import-preview-schema">
    <?= View::icon('database', ['class' => 'import-preview-schema-icon']) ?>
    Schema detected: <strong><?= View::e($preview->schemaVariant) ?></strong>
  </div>

  <div class="gradient-card">
    <div class="pc-head">
      <div class="pc-head-icon pc-head-icon-bg"><?= View::icon('filter') ?></div>
      <div class="pc-head-meta">
        <div class="pc-head-title">Import Preview</div>
        <div class="pc-head-sub"><?= number_format($totalImport) ?> record(s) would be imported across <?= count($preview->rows) ?> data type(s).</div>
      </div>
    </div>
    <div class="import-scroll-x">
      <table class="data-table">
        <thead>
          <tr>
            <th>Data Type</th>
            <th class="table-num--plain">Found</th>
            <th class="table-num--plain">Would Import</th>
            <th class="table-num--plain">Would Skip</th>
            <th>Notes</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($preview->rows as $row): ?>
          <tr>
            <td><?= View::e($row['type']) ?></td>
            <td class="table-num--plain"><?= number_format($row['found']) ?></td>
            <td class="table-num--plain"><?= number_format($row['import']) ?></td>
            <td class="table-num--plain"><?= number_format($row['skip']) ?></td>
            <td class="table-cell-notes">
              <?= implode('<br>', array_map([View::class, 'e'], $row['warnings'])) ?: '&mdash;' ?>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <div class="import-preview-note-bar">
      <span><span class="import-preview-note-accent"><?= View::icon('alert') ?> <strong>Image files are copied by filename only.</strong></span> Original files remain in place on your old server. Luster assigns new randomised filenames to all copied images.</span>
    </div>
  </div>

  <form method="POST" action="<?= $b ?>/admin/import/<?= $src ?>/run">
    <input type="hidden" name="_csrf" value="<?= View::e($csrf_token) ?>">
    <div class="btn-row import-action-row">
      <a href="<?= $b ?>/admin/import/<?= $src ?>/configure" class="btn btn-ghost">&#8592; Back</a>
      <button type="submit" class="btn btn-primary">Proceed with Import &#8594;</button>
    </div>
  </form>

<?php endif; ?>
