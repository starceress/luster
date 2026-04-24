<?php
/**
 * KIM List admin index — all fanlistings with KIM counts.
 *
 * Variables:
 *   $fanlistings_data  array   [{listing: Listing, pending: int, approved: int}]
 *   $base_url          string
 *   $csrf_token        string
 *   $success           string
 *   $error             string
 */

declare(strict_types=1);

use Luster\Core\View;

$b = rtrim(View::e($base_url), '/');
?>

<?php if ($success !== ''): ?>
  <div class="alert alert-success"><?= View::e($success) ?></div>
<?php endif; ?>
<?php if ($error !== ''): ?>
  <div class="alert alert-error"><?= View::e($error) ?></div>
<?php endif; ?>

<div class="toolbar">
  <div class="toolbar-left"></div>
  <div class="actions-row">
    <a href="<?= $b ?>/admin/kim/settings" class="btn btn-quaternary btn-sm">Display Settings</a>
  </div>
</div>

<?php if (empty($fanlistings_data)): ?>
  <div class="card">
    <div class="empty"><span class="empty-icon"><?= View::icon('kim') ?></span>
      No fanlistings found &mdash; add fanlistings first to use KIM List.
    </div>
  </div>
<?php else: ?>
  <div class="card">
    <div class="card-head">Keep In Mind List</div>
    <div class="table-wrap">
    <table class="data-table mem-table">
      <thead>
        <tr>
          <th>Listing</th>
          <th>Pending</th>
          <th>Approved</th>
          <th class="act-col"></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($fanlistings_data as $entry):
          /** @var \Luster\Models\Listing $listing */
          $listing  = $entry['listing'];
          $pending  = (int) $entry['pending'];
          $approved = (int) $entry['approved'];
        ?>
        <tr>
          <td data-label="Listing"><?= View::e($listing->subject) ?></td>
          <td data-label="Pending">
            <?php if ($pending > 0): ?>
              <span class="badge badge-upcoming"><?= (int) $pending ?></span>
            <?php else: ?>
              <span class="text-dim">0</span>
            <?php endif; ?>
          </td>
          <td data-label="Approved">
            <?php if ($approved > 0): ?>
              <span class="badge badge-current"><?= (int) $approved ?></span>
            <?php else: ?>
              <span class="text-dim">0</span>
            <?php endif; ?>
          </td>
          <td class="act-col" data-label="">
            <a href="<?= $b ?>/admin/kim/<?= (int) $listing->id ?>/show"
               class="btn btn-primary btn-sm">View</a>
            <?php if ($approved > 0): ?>
              <a href="<?= $b ?>/admin/kim/<?= (int) $listing->id ?>/mass-email"
                 class="btn btn-quaternary btn-sm">Bulk Email</a>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  </div>
<?php endif; ?>
