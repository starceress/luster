<?php
use Luster\Core\View;
use Luster\Import\ImportPreview;
use Luster\Import\ImportOptions;
/** @var string             $source */
/** @var string             $source_name */
/** @var ImportPreview|null $preview */
/** @var ImportOptions      $options */
/** @var string             $error */
/** @var string             $conn_type   'Same database' | 'Live connection' | 'SQL dump' */
/** @var string             $conn_host */
/** @var string             $conn_db */
/** @var string             $base_url */
/** @var string             $csrf_token */
$b   = rtrim(View::e($base_url), '/');
$src = View::e($source);
?>

<div class="import-wizard">

<div class="wizard-steps">
  <div class="wizard-steps-line"></div>
  <div class="wizard-steps-progress wizard-steps-progress--preview"></div>
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
  <div class="wizard-step-node wizard-step-node--active">
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
  <a href="<?= $b ?>/admin/import/<?= $src ?>/options" class="btn btn-secondary">&#8592; Back to Options</a>
<?php elseif ($preview === null): ?>
  <div class="alert alert-error import-stack-gap">Preview data is unavailable.</div>
  <a href="<?= $b ?>/admin/import/<?= $src ?>/options" class="btn btn-secondary">&#8592; Back to Options</a>
<?php else: ?>

  <?php
    $importChips = [];
    if ($options->source === 'enthusiast') {
        if ($options->importOwnedListings) $importChips[] = 'Listings';
        if ($options->importMembers)       $importChips[] = 'Members';
        if ($options->importJoined)        $importChips[] = 'Joined';
        if ($options->importCategories)    $importChips[] = 'Categories';
        if ($options->importAffiliates)    $importChips[] = 'Affiliates';
        if ($options->importSettings)      $importChips[] = 'Settings';
        if ($options->importTemplates)     $importChips[] = 'Email Templates';
    } elseif ($options->source === 'listing-admin') {
        if ($options->importListings)   $importChips[] = 'Listings';
        if ($options->importMembers)    $importChips[] = 'Members';
        if ($options->importJoined)     $importChips[] = 'Joined';
        if ($options->importCategories) $importChips[] = 'Categories';
        if ($options->importAffiliates) $importChips[] = 'Affiliates';
        if ($options->importUpdates)    $importChips[] = 'Updates';
    } elseif ($options->source === 'fanupdate') {
        if ($options->importUpdates) $importChips[] = 'Updates';
    } elseif ($options->source === 'bellabuffs') {
        if ($options->bbImportApproved)   $importChips[] = 'Members (approved)';
        if ($options->bbImportPending)    $importChips[] = 'Members (pending)';
        if ($options->bbImportAffiliates) $importChips[] = 'Affiliates';
        if ($options->bbImportCodes)      $importChips[] = 'Codes / Buttons';
    } elseif ($options->source === 'codesort') {
        if ($options->importCodeCategories) $importChips[] = 'Categories';
        if ($options->importCodeSizes)      $importChips[] = 'Sizes';
        if ($options->importCodeDonors)     $importChips[] = 'Donors';
        if ($options->importCodes)          $importChips[] = 'Codes';
    }

    $totalFound  = (int) array_sum(array_column($preview->rows, 'found'));
    $totalImport = $preview->totalToImport();
    $totalSkip   = (int) array_sum(array_column($preview->rows, 'skip'));
  ?>


  <div class="stats-grid stats-grid-plain">
    <div class="stat-card">
      <div class="stat-header">
        <span class="stat-icon"><?= View::icon('database') ?></span>
        <span class="stat-label">Found</span>
      </div>
      <div class="stat-value"><?= number_format($totalFound) ?></div>
      <div class="stat-pills"><span class="badge badge-inactive">Records in source</span></div>
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
          <span class="badge badge-pending">Already exists</span>
        <?php else: ?>
          <span class="badge badge-current">None</span>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <div class="card">
    <div class="card-head">Ready to Import</div>
    <div class="card-body">
    <div class="listing-field-row listing-field-row--top">
      <div class="listing-field-label">Source</div>
      <div class="import-review-list-value">
        <?= View::e($source_name) ?> &mdash; 
        <?php if ($preview->schemaVariant !== ''): ?>
          <code><?= View::e(strtoupper($preview->schemaVariant)) ?></code>
        <?php endif; ?>
      </div>

      <div class="listing-field-label">Connection</div>
      <div class="import-review-list-value">
        <?php if ($conn_type === 'SQL dump'): ?>
          SQL dump
        <?php elseif ($conn_host !== ''): ?>
          <?= View::e($conn_host) ?>
          <?php if ($conn_db !== ''): ?>
            <span class="import-review-sep">&mdash;</span>
            <code><?= View::e($conn_db) ?></code>
          <?php endif; ?>
        <?php else: ?>
          <?= View::e($conn_type) ?>
        <?php endif; ?>
      </div>

      <?php if (!empty($importChips)): ?>
        <div class="listing-field-label">Importing</div>
        <div class="import-review-list-value">
          <?php foreach ($importChips as $chip): ?>
            <span class="badge badge-inactive"><?= View::e($chip) ?></span>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

      <div class="listing-field-label">Conflicts</div>
      <div class="import-review-list-value"><?= $options->conflictStrategy === 'overwrite' ? 'Overwrite existing records' : 'Skip existing records' ?></div>

      <div class="listing-field-label">Images</div>
      <div class="import-review-list-value">
        <?php if ($options->imageMode === 'path'): ?>
          Same-server path
          <?php if ($options->imagePath !== ''): ?>
            <span class="import-review-sep">&mdash;</span>
            <code><?= View::e($options->imagePath) ?></code>
          <?php endif; ?>
        <?php elseif ($options->imageMode === 'url'): ?>
          Remote URL
          <?php if ($options->imageUrl !== ''): ?>
            <span class="import-review-sep">&mdash;</span>
            <code><?= View::e($options->imageUrl) ?></code>
          <?php endif; ?>
        <?php elseif ($options->imageMode === 'zip'): ?>
          Pre-uploaded ZIP
        <?php else: ?>
          <span class="text-dim">None</span>
        <?php endif; ?>
      </div>

      <?php if ($preview->sameDatabase): ?>
        <div class="listing-field-label">Same database</div>
        <div class="import-review-list-value">Member tables are already readable, so no member data will be moved.</div>
      <?php endif; ?>
    </div>
  </div>
      </div>

      <div class="overview-section-divider"></div>


  <div class="gradient-card import-preview-results-card">
    <div class="pc-head">
      <div class="pc-head-icon pc-head-icon-bg"><?= View::icon('import') ?></div>
      <div class="pc-head-meta">
        <div class="pc-head-title">Dry Run Results</div>
        <div class="pc-head-sub"><?= number_format($totalImport) ?> record(s) would be imported across <?= count($preview->rows) ?> data type(s).</div>
      </div>
    </div>
    

    <div class="import-scroll-x">
      <table class="data-table">
        <thead>
          <tr>
            <th style="min-width:160px">Data Type</th>
            <th class="table-num--plain">Found</th>
            <th class="table-num--plain">Would Import</th>
            <th class="table-num--plain">Would Skip</th>
            <th>Notes</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($preview->rows as $row): ?>
          <tr>
            <td style="font-weight:600"><?= View::e($row['type']) ?></td>
            <td class="table-num--plain"><?= (int)$row['found']  === 0 ? '<span class="text-dim">' . number_format($row['found'])  . '</span>' : number_format($row['found']) ?></td>
            <td class="table-num--plain"><?= (int)$row['import'] === 0 ? '<span class="text-dim">' . number_format($row['import']) . '</span>' : number_format($row['import']) ?></td>
            <td class="table-num--plain"><?= (int)$row['skip']   === 0 ? '<span class="text-dim">' . number_format($row['skip'])   . '</span>' : number_format($row['skip']) ?></td>
            <td class="table-cell-notes">
              <span class="text-muted"><?= implode('<br>', array_map([View::class, 'e'], $row['warnings'])) ?: '&mdash;' ?></span>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <div class="pc-section"><div class="callout callout-warn">
      <?= View::icon('alert') ?>
      <span>This will write <strong><?= number_format($totalImport) ?> record(s)</strong> to your Luster database. Original source files are never modified or deleted.</span>
    </div></div>

    <form method="POST" action="<?= $b ?>/admin/import/<?= $src ?>/run">
      <input type="hidden" name="_csrf" value="<?= View::e($csrf_token) ?>">
      <div class="composer-footer">
        <a href="<?= $b ?>/admin/import/<?= $src ?>/options" class="btn btn-quaternary">&#8592; Back</a>
        <button type="submit" class="btn btn-primary">Run Import &#8594;</button>
      </div>
    </form>
  </div>

<?php endif; ?>

</div>
