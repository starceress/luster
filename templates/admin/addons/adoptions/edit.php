<?php

declare(strict_types=1);

use Luster\Core\View;

/**
 * Adoptions admin — review / edit an application.
 *
 * @var array                         $application   Row from lstr_adoption_applications
 * @var \Luster\Models\Listing|null   $listing
 * @var bool                          $is_review_mode  True when status is pending/new
 * @var string                        $error
 * @var string                        $base_url
 * @var string                        $csrf_token
 */

$b              = rtrim(View::e($base_url), '/');
$listingId      = (int) $application['fanlisting_id'];
$backUrl        = $b . '/admin/adoptions?listing_id=' . $listingId;
$isReviewMode   = $is_review_mode ?? false;
$currentStatus  = $application['status'] ?? 'pending';

// In review mode, pre-select 'reviewed' so "Mark as Reviewed" does the right thing by default
$selectedStatus = $isReviewMode ? 'reviewed' : $currentStatus;

$statusLabels = [
    'pending'  => 'Pending',
    'reviewed' => 'Reviewed',
    'approved' => 'Approved',
    'rejected' => 'Rejected',
    'granted'  => 'Granted',
];
?>

<div class="page-topbar">
  <a href="<?= View::e($backUrl) ?>" class="link">
    &larr; Back to <?= $listing !== null ? View::e($listing->title) : ('Listing #' . $listingId) ?>
  </a>
</div>

<?php if (($success ?? '') !== ''): ?>
  <div class="alert alert-success"><?= View::e($success) ?></div>
<?php endif; ?>
<?php if ($error !== ''): ?>
  <div class="alert alert-error"><?= View::e($error) ?></div>
<?php endif; ?>

<form method="POST" action="<?= $b ?>/admin/adoptions/<?= (int) $application['id'] ?>/edit"
      id="adopt-edit-form">
  <input type="hidden" name="_csrf" value="<?= View::e($csrf_token) ?>">

  <div class="gradient-card">
    <div class="pc-head">
      <div class="pc-head-icon pc-head-icon-bg"><?= View::icon('adoptions') ?></div>
      <div class="pc-head-meta">
        <div class="pc-head-title"><?= View::e($application['name']) ?></div>
        <div class="pc-head-sub">
          <?= $isReviewMode ? 'Reviewing' : 'Editing' ?> adoption application
          <?php if ($listing !== null): ?>
            &mdash; <?= View::e($listing->title) ?>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <div class="pc-section">
      <div class="pc-section-label">Applicant</div>

      <div class="listing-field-row">
        <label class="listing-field-label" for="adopt-name">Name <span class="req">*</span></label>
        <input id="adopt-name" type="text" name="name" class="form"
               value="<?= View::e($application['name']) ?>" required maxlength="255">
      </div>

      <div class="listing-field-row">
        <label class="listing-field-label" for="adopt-email">Email <span class="req">*</span></label>
        <input id="adopt-email" type="email" name="email" class="form"
               value="<?= View::e($application['email']) ?>" required maxlength="255">
      </div>

      <div class="listing-field-row">
        <label class="listing-field-label" for="adopt-url">URL</label>
        <input id="adopt-url" type="url" name="url" class="form"
               value="<?= View::e($application['url']) ?>" maxlength="500" placeholder="https://">
      </div>

      <div class="listing-field-row">
        <label class="listing-field-label">Listing</label>
        <div>
          <?php if ($listing !== null): ?>
            <a href="<?= View::e($listing->url) ?>" target="_blank" rel="noopener" class="link">
              <?= View::e($listing->title) ?>
            </a>
            <?php if ($listing->subject !== ''): ?>
              <span class="text-dim"> &mdash; <?= View::e($listing->subject) ?></span>
            <?php endif; ?>
          <?php else: ?>
            <span class="text-dim">Listing #<?= $listingId ?></span>
          <?php endif; ?>
        </div>
      </div>

      <div class="listing-field-row listing-field-row--top">
        <label class="listing-field-label" for="adopt-message">Message</label>
        <textarea id="adopt-message" name="message" class="form" rows="4"
                  maxlength="5000"><?= View::e((string) ($application['message'] ?? '')) ?></textarea>
      </div>

      <div class="listing-field-row listing-field-row--top">
        <label class="listing-field-label" for="adopt-notes">Personal Notes</label>
        <div>
          <textarea id="adopt-notes" name="admin_notes" class="form" rows="3"
                    maxlength="5000"
                    placeholder="Internal notes about this application&hellip;"><?= View::e((string) ($application['admin_notes'] ?? '')) ?></textarea>
          <p class="form-hint">Only visible to admins. Not shared with the applicant.</p>
        </div>
      </div>
    </div>

    <div class="pc-section">
      <div class="pc-section-label">Status</div>

      <div class="listing-field-row listing-field-row--top">
        <label class="listing-field-label">Status</label>
        <div>
          <?php foreach ($statusLabels as $value => $label): ?>
          <label class="radio-label" style="display:block;margin-bottom:.35rem">
            <input type="radio" name="status" value="<?= View::e($value) ?>"
                   <?= $selectedStatus === $value ? 'checked' : '' ?>>
            <?= View::e($label) ?>
          </label>
          <?php endforeach; ?>
        </div>
      </div>
    </div>

    <div class="composer-footer">
      <?php if ($isReviewMode): ?>
        <button type="submit" class="btn btn-primary">Mark as Reviewed</button>
      <?php else: ?>
        <button type="submit" class="btn btn-primary">Save Changes</button>
      <?php endif; ?>
      <button type="reset" class="btn btn-quaternary js-form-reset">Reset to Original</button>
      <button type="button" class="btn btn-ghost" id="adopt-clear-btn">Clear</button>
      <a href="<?= $b ?>/admin/adoptions" class="btn btn-ghost">Cancel</a>
    </div>
  </div>
</form>

<script>
(function () {
  var btn = document.getElementById('adopt-clear-btn');
  if (!btn) return;
  btn.addEventListener('click', function () {
    var form = document.getElementById('adopt-edit-form');
    if (!form) return;
    form.querySelectorAll('input[type="text"], input[type="email"], input[type="url"], textarea')
        .forEach(function (el) { el.value = ''; });
  });
}());
</script>
