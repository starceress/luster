<?php
/*
 Luster -- https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Import wizard step 4 -- dry-run preview. Shows a read-only summary of what
 the import would do, including row counts, warnings, and connection details,
 before the admin commits to running the actual import.
*/
use Luster\Core\View;
use Luster\Import\ImportPreview;
use Luster\Import\ImportOptions;
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

<?php if (($notice ?? '') !== ''): ?>
  <div class="alert alert-error"><?= View::e($notice) ?></div>
<?php endif; ?>

<?php if ($error !== ''): ?>
  <div class="alert alert-error"><?= View::e($error) ?></div>
  <a href="<?= $b ?>/admin/import/<?= $src ?>/options" class="btn btn-secondary">&#8592; Back to Options</a>
<?php elseif ($preview === null): ?>
  <div class="alert alert-error">Luster could not build a preview for this import.</div>
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
        if ($options->importWishlist)   $importChips[] = 'Wishlist';
        if ($options->importKim)        $importChips[] = 'KIM List';
        if ($options->importRules)      $importChips[] = 'Join Rules';
        if ($options->importCodes)      $importChips[] = 'Codes';
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
      <div class="stat-pills"><span class="badge badge-inactive">Rows found in the old data</span></div>
    </div>
    <div class="stat-card">
      <div class="stat-header">
        <span class="stat-icon"><?= View::icon('import') ?></span>
        <span class="stat-label">Would Import</span>
      </div>
      <div class="stat-value"><?= number_format($totalImport) ?></div>
      <div class="stat-pills">
        <?php if ($totalFound > 0): ?>
          <span class="badge badge-current"><?= number_format(round($totalImport / $totalFound * 100)) ?>% will be added or updated</span>
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
          <span class="badge badge-pending">Already in Luster, or see notes</span>
        <?php else: ?>
          <span class="badge badge-current">None</span>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <div class="card">
    <div class="card-head">Preview Summary</div>
    <div class="card-body">
    <div class="field-row">
      <div class="field-label">Source</div>
      <div style="display:flex;align-items:center;flex-wrap:wrap;gap:.35rem;color:var(--text);font-size:var(--fs-md)">
        <?= View::e($source_name) ?> &mdash; 
        <?php if ($preview->schemaVariant !== ''): ?>
          <code><?= View::e(strtoupper($preview->schemaVariant)) ?></code>
        <?php endif; ?>
      </div>

      <div class="field-label">Connection</div>
      <div style="display:flex;align-items:center;flex-wrap:wrap;gap:.35rem;color:var(--text);font-size:var(--fs-md)">
        <?php if ($conn_type === 'SQL dump'): ?>
          SQL dump
        <?php elseif ($conn_host !== ''): ?>
          <?= View::e($conn_host) ?>
          <?php if ($conn_db !== ''): ?>
            <span style="color:var(--text-dim)">&mdash;</span>
            <code><?= View::e($conn_db) ?></code>
          <?php endif; ?>
        <?php else: ?>
          <?= View::e($conn_type) ?>
        <?php endif; ?>
      </div>

      <?php if (!empty($importChips)): ?>
        <div class="field-label">Importing</div>
        <div style="display:flex;align-items:center;flex-wrap:wrap;gap:.35rem;color:var(--text);font-size:var(--fs-md)">
          <?php foreach ($importChips as $chip): ?>
            <span class="badge badge-inactive"><?= View::e($chip) ?></span>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

      <?php
        // BellaBuffs can only update its codes. Its members and affiliates are always kept.
        $conflictText = 'Skip existing records';
        if ($options->conflictStrategy === 'overwrite') {
            $conflictText = $options->source === 'bellabuffs'
                ? 'Update matching fan codes. Existing members and affiliates are kept'
                : 'Overwrite existing records';
        }
      ?>
      <div class="field-label">Conflicts</div>
      <div style="display:flex;align-items:center;flex-wrap:wrap;gap:.35rem;color:var(--text);font-size:var(--fs-md)"><?= View::e($conflictText) ?></div>

      <div class="field-label">Images</div>
      <div style="display:flex;align-items:center;flex-wrap:wrap;gap:.35rem;color:var(--text);font-size:var(--fs-md)">
        <?php if ($options->imageMode === 'path'): ?>
          Same-server path
          <?php if ($options->imagePath !== ''): ?>
            <span style="color:var(--text-dim)">&mdash;</span>
            <code><?= View::e($options->imagePath) ?></code>
          <?php endif; ?>
        <?php elseif ($options->imageMode === 'url'): ?>
          Remote URL
          <?php if ($options->imageUrl !== ''): ?>
            <span style="color:var(--text-dim)">&mdash;</span>
            <code><?= View::e($options->imageUrl) ?></code>
          <?php endif; ?>
        <?php elseif ($options->imageMode === 'zip'): ?>
          Pre-uploaded ZIP
        <?php elseif ($options->imageMode === 'auto'): ?>
          Automatic &mdash; Luster finds them from your <?= View::e($source_name) ?> settings
        <?php else: ?>
          <span class="text-dim">None</span>
        <?php endif; ?>
      </div>

      <?php if ($preview->sameDatabase): ?>
        <div class="field-label">Same database</div>
        <div style="display:flex;align-items:center;flex-wrap:wrap;gap:.35rem;color:var(--text);font-size:var(--fs-md)">The old member tables are already in Luster's database, so member rows do not need to be copied.</div>
      <?php endif; ?>
    </div>
  </div>
      </div>

      <div class="overview-section-divider"></div>


  <div id="js-import-preview-card" class="card card--gradient import-preview-results-card">
    <div class="panel-header">
      <div class="panel-header-icon"><?= View::icon('import') ?></div>
      <div class="panel-header-meta">
        <div class="panel-header-title">Dry Run Results</div>
        <div class="panel-header-sub">This is a preview only. Nothing has been written yet.</div>
      </div>
    </div>
    

    <div class="table-wrap">
      <table class="data-table">
        <thead>
          <tr>
            <th style="min-width:160px">Data Type</th>
            <th>Found</th>
            <th>Would Import</th>
            <th>Would Skip</th>
            <th>Notes</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($preview->rows as $row): ?>
          <tr>
            <td style="font-weight:600"><?= View::e($row['type']) ?></td>
            <td><?= (int)$row['found']  === 0 ? '<span class="text-dim">' . number_format($row['found'])  . '</span>' : number_format($row['found']) ?></td>
            <td><?= (int)$row['import'] === 0 ? '<span class="text-dim">' . number_format($row['import']) . '</span>' : number_format($row['import']) ?></td>
            <td><?= (int)$row['skip']   === 0 ? '<span class="text-dim">' . number_format($row['skip'])   . '</span>' : number_format($row['skip']) ?></td>
            <td class="table-cell-notes">
              <span class="text-muted"><?= implode('<br>', array_map([View::class, 'e'], $row['warnings'])) ?: '&mdash;' ?></span>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <div class="card-body"><div class="callout callout-warn">
      <?= View::icon('alert') ?>
      <span>Running the import will write <strong><?= number_format($totalImport) ?> record(s)</strong> to Luster. The old source files and old database are not modified.</span>
    </div></div>

    <form id="js-import-run-form" method="POST" action="<?= $b ?>/admin/import/<?= $src ?>/run">
      <input type="hidden" name="_csrf" value="<?= View::e($csrf_token) ?>">
      <?php if ($preview->memberConfirmation !== null): ?>
      <div class="card-body">
        <input type="hidden" name="member_location_fingerprint" value="<?= View::e($preview->memberConfirmation['fingerprint']) ?>">
        <div class="import-chk">
          <input type="checkbox" name="confirm_member_location" value="1" id="ck-member-location">
          <div class="import-chk-body">
            <label class="import-chk-lbl" for="ck-member-location">These members really are in the database or file I picked (it's a moved copy or a backup).</label>
            <div class="import-chk-sub">These listings say their members are saved somewhere else, so Luster won't touch them unless you tick this. It only covers this one run, and only these listings:</div>
            <ul class="import-chk-sub">
              <?php foreach ($preview->memberConfirmation['listings'] as $listing): ?>
              <li>#<?= (int) $listing['id'] ?> <?= View::e($listing['title']) ?>: table <code><?= View::e($listing['table']) ?></code>, saved as <?= View::e($listing['database'] !== '' ? $listing['database'] : '(no database)') ?> on <?= View::e($listing['server'] !== '' ? $listing['server'] : '(no server)') ?></li>
              <?php endforeach; ?>
            </ul>
          </div>
        </div>
      </div>
      <?php endif; ?>
      <div class="composer-footer">
        <a href="<?= $b ?>/admin/import/<?= $src ?>/options" class="btn btn-quaternary">&#8592; Back</a>
        <button type="submit" class="btn btn-primary">Run Import &#8594;</button>
      </div>
    </form>
  </div>

<?php endif; ?>

<div id="js-import-processing" class="import-processing">
  <div class="import-processing-spinner" role="status" aria-label="Importing"></div>
  <div class="import-processing-title">Importing&hellip;</div>
  <div class="import-processing-sub">Do not close or refresh this tab.</div>
  <div class="progress-track" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-label="Import in progress">
    <div class="progress-bar progress-bar--indeterminate"></div>
  </div>
  <p class="import-processing-note">Records are being written to your Luster database. Large imports with many members may take several minutes.</p>
</div>

</div>
