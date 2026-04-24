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

<div class="page-topbar">
  <a href="<?= $b ?>/admin/members/<?= $id ?>" class="link">
    &larr; Back to Members: <?= View::e($listing->name) ?>
  </a>
</div>

<?php if ($error !== ''): ?>
  <div class="alert alert-error"><?= View::e($error) ?></div>
<?php endif; ?>

<?php if ($results !== null): ?>
  <?php $errCount = count($results['errors']); ?>

  <?php if ($errCount === 0 && $results['imported'] > 0): ?>
    <div class="alert alert-success">Import complete.</div>
  <?php elseif ($results['imported'] === 0 && $errCount === 0 && $results['skipped'] > 0): ?>
    <div class="alert alert-error">No members were imported - all rows were duplicates.</div>
  <?php elseif ($errCount > 0): ?>
    <div class="alert alert-error">
      Import finished with <?= $errCount ?> row error<?= $errCount !== 1 ? 's' : '' ?>.
    </div>
  <?php endif; ?>

  <div class="gradient-card">
    <div class="pc-head">
      <div class="pc-head-icon pc-head-icon-bg"><?= View::icon('check-badge') ?></div>
      <div class="pc-head-meta">
        <div class="pc-head-title">Import Results</div>
        <div class="pc-head-sub">Review the imported member count, duplicates, and any row-level errors.</div>
      </div>
    </div>

    <div class="listing-field-section">
      <div class="db-info-grid member-import-summary">
        <div>
          <div class="form-label form-label--tight">Imported</div>
          <div class="member-import-count"><?= (int) $results['imported'] ?></div>
        </div>
        <div>
          <div class="form-label form-label--tight">Skipped (duplicates)</div>
          <div class="member-import-count member-import-count--muted"><?= (int) $results['skipped'] ?></div>
        </div>
        <?php if ($errCount > 0): ?>
        <div>
          <div class="form-label form-label--tight">Row errors</div>
          <div class="member-import-count member-import-count--danger"><?= $errCount ?></div>
        </div>
        <?php endif; ?>
      </div>
    </div>

    <div class="composer-footer">
      <a href="<?= $b ?>/admin/members/<?= $id ?>" class="btn btn-primary">View Member List</a>
      <a href="<?= $b ?>/admin/members/<?= $id ?>/import" class="btn btn-quaternary">Import Another File</a>
    </div>
  </div>

  <?php if ($errCount > 0): ?>
  <div class="card">
    <div class="card-head">
      <div class="card-head-text">
        <div class="card-title">Rows With Errors</div>
        <div class="card-head-sub">These rows were skipped because one or more values could not be imported.</div>
      </div>
    </div>
    <div class="card-body">
      <div class="table-wrap">
        <table class="data-table">
          <thead>
            <tr>
              <th class="member-import-row-col">Row</th>
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
  </div>
  <?php endif; ?>

<?php else: ?>
  <form method="POST" action="<?= $b ?>/admin/members/<?= $id ?>/import"
        enctype="multipart/form-data"
        class="js-warn-unsaved">
    <input type="hidden" name="_csrf" value="<?= View::e($csrf_token ?? '') ?>">

    <div class="gradient-card">
      <div class="pc-head">
        <div class="pc-head-icon pc-head-icon-bg"><?= View::icon('members') ?></div>
        <div class="pc-head-meta">
          <div class="pc-head-title">Import Members</div>
          <div class="pc-head-sub">Upload a CSV file to add approved members directly to <?= View::e($listing->name) ?>.</div>
        </div>
      </div>

      <div class="listing-field-section">
        <p class="member-import-intro">
          Imported members are approved immediately. Rows whose email addresses already exist for this listing are skipped automatically.
        </p>

        <div class="listing-field-row listing-field-row--top">
          <label class="listing-field-label" for="csv-file">
            CSV File <span class="req">*</span>
          </label>
          <div>
            <input id="csv-file" type="file" name="csv" accept=".csv,text/csv"
                   class="form" required>
            <p class="form-hint">Maximum 2 MB. Include at least the <code>name</code> and <code>email</code> header columns.</p>
          </div>
        </div>
      </div>

      <div class="composer-footer">
        <button type="submit" class="btn btn-primary">Upload &amp; Import</button>
        <a href="<?= $b ?>/admin/members/<?= $id ?>/import/template"
           class="btn btn-quaternary">Download CSV Template</a>
      </div>
    </div>
  </form>

  <div class="card">
    <div class="card-head">
      <div class="card-head-text">
        <div class="card-title">Expected CSV Format</div>
        <div class="card-head-sub">The first row must be a header row, and column names are case-insensitive.</div>
      </div>
    </div>
    <div class="card-body">
      <div class="table-wrap">
        <table class="data-table">
          <thead>
            <tr><th>Column</th><th>Required</th><th>Notes</th></tr>
          </thead>
          <tbody>
            <tr><td><code>name</code></td>   <td>Yes</td><td>Member's display name</td></tr>
            <tr><td><code>email</code></td>  <td>Yes</td><td>Must be a valid email address; rows with duplicate emails are skipped</td></tr>
            <tr><td><code>url</code></td>    <td>No</td> <td>Member's website URL</td></tr>
            <tr><td><code>country</code></td><td>No</td> <td>Country name or code</td></tr>
            <tr><td><code>password</code></td><td>No</td><td>Plain text; it will be hashed, and a random password is generated if omitted</td></tr>
            <tr><td><em>field name</em></td> <td>No</td> <td>Any additional field defined for this listing. Use the field's internal name as the column header.</td></tr>
          </tbody>
        </table>
      </div>
      <p class="form-hint member-import-template-note">
        Use the "Download CSV Template" button above to get a starter file with the correct headers for this listing.
      </p>
    </div>
  </div>

<?php endif; ?>
