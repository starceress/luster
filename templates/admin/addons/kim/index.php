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

<p style="margin-bottom:1.25rem"><a href="<?= $b ?>/admin/addons" class="neb-link">&larr; Back to Add-ons</a></p>

<?php if (empty($fanlistings_data)): ?>
  <div class="neb-card">
    <div class="neb-empty">
      <svg class="empty-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01z"/></svg>
      No fanlistings found — add fanlistings first to use KIM List.
    </div>
  </div>
<?php else: ?>
  <div class="neb-card">
    <div class="neb-table-wrap">
    <table class="data-table">
      <thead>
        <tr>
          <th>Fanlisting</th>
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
          <td class="text-strong"><?= View::e($listing->subject) ?></td>
          <td>
            <?php if ($pending > 0): ?>
              <span class="neb-badge badge-upcoming"><?= (int) $pending ?></span>
            <?php else: ?>
              <span class="text-dim">0</span>
            <?php endif; ?>
          </td>
          <td>
            <?php if ($approved > 0): ?>
              <span class="neb-badge badge-current"><?= (int) $approved ?></span>
            <?php else: ?>
              <span class="text-dim">0</span>
            <?php endif; ?>
          </td>
          <td class="act-col">
            <a href="<?= $b ?>/admin/kim/<?= (int) $listing->id ?>/show"
               class="btn btn-tertiary btn-sm">View</a>
            <?php if ($approved > 0): ?>
              <a href="<?= $b ?>/admin/kim/<?= (int) $listing->id ?>/mass-email"
                 class="btn btn-tertiary btn-sm">Mass Email</a>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  </div>
<?php endif; ?>
