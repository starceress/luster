<?php
/*
 Luster -- https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Import members preview page. Shows a summary of the parsed member file,
 including detected format, column mapping, row counts, and a sample of the
 data before the admin confirms the import.
*/
use Luster\Core\View;
$b      = rtrim(View::e($base_url), '/');
$total   = (int) ($counts['total']    ?? 0);
$approved = (int) ($counts['approved'] ?? 0);
$pending  = (int) ($counts['pending']  ?? 0);

$formatLabels = ['enthusiast' => 'Enthusiast', 'listing_admin' => 'Listing Admin'];
$formatLabel  = $formatLabels[$format] ?? 'Unknown';
?>

<div class="toolbar">
  <a href="<?= $b ?>/admin/import-members" class="link">&larr; Back</a>
</div>

<div class="card card--gradient" style="margin-bottom:1.5rem">
  <div class="panel-header">
    <div class="panel-header-icon"><?= View::icon('import') ?></div>
    <div class="panel-header-meta">
      <div class="panel-header-title">Ready to Import</div>
      <div class="panel-header-sub">Check what Luster found before it adds members.</div>
    </div>
  </div>

  <div class="card-body">
    <div class="section-label">Summary</div>

    <div class="stat-row">
      <div class="stat-tile">
        <span class="stat-label">Total rows</span>
        <div class="stat-value"><?= number_format($total) ?></div>
      </div>
      <div class="stat-tile">
        <span class="stat-label">Approved</span>
        <div class="stat-value"><?= number_format($approved) ?></div>
      </div>
      <div class="stat-tile">
        <span class="stat-label">Pending</span>
        <div class="stat-value"><?= number_format($pending) ?></div>
      </div>
    </div>

    <div class="field-row">
      <span class="field-label">Destination</span>
      <div><?= View::e($listing->title) ?> <span class="text-dim">(member table: <code><?= View::e($listing->dbprefix) ?></code>)</span></div>
    </div>
    <div class="field-row">
      <span class="field-label">Detected format</span>
      <div><span class="badge badge-info"><?= View::e($formatLabel) ?></span></div>
    </div>
    <div class="field-row">
      <span class="field-label">Duplicates</span>
      <div>Skipped by email address. Existing members are not overwritten.</div>
    </div>
  </div>

  <div class="card-body">
    <div class="section-label">Column Matching</div>
    <p class="form-hint">This shows which old file column will fill each Luster member field.</p>
    <table class="data-table" style="max-width:480px">
      <thead><tr><th>Source column</th><th>Luster column</th></tr></thead>
      <tbody>
        <?php
        $displayCols = ['email','name','country','url','pending','password'];
        foreach ($displayCols as $lusterCol): ?>
        <tr>
          <td><code><?= View::e((string) ($mapping[$lusterCol] ?? '—')) ?></code></td>
          <td><code><?= View::e($lusterCol) ?></code></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <?php if (!empty($samples)): ?>
  <div class="card-body">
    <div class="section-label">Sample Rows</div>
    <p class="form-hint">A small peek at the uploaded data so you can confirm this is the right file.</p>
    <div style="overflow-x:auto">
      <table class="data-table">
        <thead>
          <tr>
            <?php foreach (array_keys($samples[0]) as $col): ?>
            <th><?= View::e($col) ?></th>
            <?php endforeach; ?>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($samples as $row): ?>
          <tr>
            <?php foreach ($row as $val): ?>
            <td><?= View::e((string) $val) ?></td>
            <?php endforeach; ?>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
  <?php endif; ?>

  <form method="POST" action="<?= $b ?>/admin/import-members/run" class="card-body">
    <input type="hidden" name="_csrf" value="<?= View::e($csrf_token) ?>">
    <div class="composer-footer">
      <button type="submit" class="btn btn-primary">Import <?= number_format($total) ?> Members &rarr;</button>
      <a href="<?= $b ?>/admin/import-members" class="btn btn-ghost">Cancel</a>
    </div>
  </form>
</div>
