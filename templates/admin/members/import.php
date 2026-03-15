<?php

declare(strict_types=1);

use Luster\Core\View;

/**
 * @var \Luster\Models\Listing  $listing
 * @var array|null              $results   null = form; array = {imported, skipped, errors[]}
 * @var string                  $error     top-level file/parse error
 * @var string                  $base_url
 * @var string                  $csrf_token
 */

$b  = rtrim(View::e($base_url ?? ''), '/');
$id = (int) $listing->id;
?>

<p class="page-back">
  <a href="<?= $b ?>/admin/members/<?= $id ?>" class="neb-link">&larr; Back to <?= View::e($listing->name) ?> members</a>
</p>

<?php if ($error !== ''): ?>
  <div class="alert alert-error"><?= View::e($error) ?></div>
<?php endif; ?>

<?php if ($results !== null): ?>
  <!-- ── Results ── -->
  <?php $errCount = count($results['errors']); ?>

  <?php if ($errCount === 0 && $results['imported'] > 0): ?>
    <div class="alert alert-success" style="margin-bottom:1.25rem">
      Import complete.
    </div>
  <?php elseif ($results['imported'] === 0 && $errCount === 0 && $results['skipped'] > 0): ?>
    <div class="alert alert-error" style="margin-bottom:1.25rem">
      No members were imported — all rows were duplicates.
    </div>
  <?php elseif ($errCount > 0): ?>
    <div class="alert alert-error" style="margin-bottom:1.25rem">
      Import finished with <?= $errCount ?> row error<?= $errCount !== 1 ? 's' : '' ?>.
    </div>
  <?php endif; ?>

  <div class="settings-card" style="margin-bottom:1.25rem">
    <div class="db-info-grid">
      <div>
        <div class="form-label" style="margin-bottom:.25rem">Imported</div>
        <div style="font-size:1.25rem;font-weight:700;color:var(--accent)"><?= (int) $results['imported'] ?></div>
      </div>
      <div>
        <div class="form-label" style="margin-bottom:.25rem">Skipped (duplicates)</div>
        <div style="font-size:1.25rem;font-weight:700;color:var(--text-muted)"><?= (int) $results['skipped'] ?></div>
      </div>
      <?php if ($errCount > 0): ?>
      <div>
        <div class="form-label" style="margin-bottom:.25rem">Row errors</div>
        <div style="font-size:1.25rem;font-weight:700;color:var(--danger)"><?= $errCount ?></div>
      </div>
      <?php endif; ?>
    </div>
  </div>

  <?php if ($errCount > 0): ?>
  <div class="settings-card" style="margin-bottom:1.25rem">
    <div style="font-weight:600;margin-bottom:.75rem">Rows with errors</div>
    <div style="overflow-x:auto">
      <table class="neb-table">
        <thead>
          <tr>
            <th style="width:60px">Row</th>
            <th>Email</th>
            <th>Reason</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($results['errors'] as $e): ?>
          <tr>
            <td><?= (int) $e['row'] ?></td>
            <td><?= View::e($e['email']) ?></td>
            <td><?= View::e($e['reason']) ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
  <?php endif; ?>

  <div class="btn-row">
    <a href="<?= $b ?>/admin/members/<?= $id ?>" class="btn btn-primary">View member list</a>
    <a href="<?= $b ?>/admin/members/<?= $id ?>/import" class="btn btn-secondary">Import another file</a>
  </div>

<?php else: ?>
  <!-- ── Upload form ── -->
  <div class="settings-card">
    <p style="margin:0 0 1.25rem;color:var(--text-muted);font-size:.9rem">
      Upload a CSV file to import members directly into <strong><?= View::e($listing->name) ?></strong>.
      All imported members are set to <strong>approved</strong> immediately.
      Rows where the email address already exists in the listing are silently skipped.
    </p>

    <form method="POST" action="<?= $b ?>/admin/members/<?= $id ?>/import"
          enctype="multipart/form-data">
      <input type="hidden" name="_csrf" value="<?= View::e($csrf_token ?? '') ?>">

      <div class="form-group">
        <label class="form-label" for="csv-file">CSV file <span>*</span></label>
        <input id="csv-file" type="file" name="csv" accept=".csv,text/csv"
               class="form-input" required>
        <p class="form-hint">Maximum 2 MB. Must include a <code>name</code> and <code>email</code> header column.</p>
      </div>

      <div class="btn-row">
        <button type="submit" class="btn btn-primary">Upload &amp; import</button>
        <a href="<?= $b ?>/admin/members/<?= $id ?>/import/template"
           class="btn btn-secondary">Download CSV template</a>
      </div>
    </form>
  </div>

  <div class="settings-card" style="margin-top:1.25rem">
    <div style="font-weight:600;margin-bottom:.75rem">Expected CSV format</div>
    <p style="font-size:.875rem;color:var(--text-muted);margin:0 0 .75rem">
      The first row must be a header row. Column names are case-insensitive.
    </p>
    <div style="overflow-x:auto">
      <table class="neb-table">
        <thead>
          <tr><th>Column</th><th>Required</th><th>Notes</th></tr>
        </thead>
        <tbody>
          <tr><td><code>name</code></td>   <td>Yes</td><td>Member's display name</td></tr>
          <tr><td><code>email</code></td>  <td>Yes</td><td>Must be a valid email address; rows with duplicate emails are skipped</td></tr>
          <tr><td><code>url</code></td>    <td>No</td> <td>Member's website URL</td></tr>
          <tr><td><code>country</code></td><td>No</td> <td>Country name or code</td></tr>
          <tr><td><code>password</code></td><td>No</td><td>Plain text — will be hashed; a random password is generated if omitted</td></tr>
          <tr><td><em>field name</em></td> <td>No</td> <td>Any additional field defined for this listing (use the field's internal name as the column header)</td></tr>
        </tbody>
      </table>
    </div>
    <p class="form-hint" style="margin-top:.75rem">
      Use the "Download CSV template" button above to get a file pre-filled with the correct headers for this listing.
    </p>
  </div>

<?php endif; ?>
