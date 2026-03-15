<?php
/**
 * KIM List admin — mass email compose form.
 *
 * Variables:
 *   $listing          Listing
 *   $recipient_count  int
 *   $base_url         string
 *   $csrf_token       string
 *   $error            string
 */

declare(strict_types=1);

use Luster\Core\View;

$b = rtrim(View::e($base_url), '/');
?>

<?php if ($error !== ''): ?>
  <div class="alert alert-error"><?= View::e($error) ?></div>
<?php endif; ?>

<div class="page-header">
  <div class="page-header-left">
    <nav class="breadcrumb">
      <a href="<?= $b ?>/admin/addons">Add-ons</a>
      <span class="breadcrumb-sep">/</span>
      <a href="<?= $b ?>/admin/kim">KIM List</a>
      <span class="breadcrumb-sep">/</span>
      <a href="<?= $b ?>/admin/kim/<?= View::e((string) $listing->id) ?>/show">
        <?= View::e($listing->subject) ?>
      </a>
      <span class="breadcrumb-sep">/</span>
      <span>Mass Email</span>
    </nav>
    <h1>Mass Email — KIM members for <?= View::e($listing->subject) ?></h1>
  </div>
</div>

<div class="neb-card">
  <p>
    This will send an email to
    <strong><?= View::e((string) $recipient_count) ?></strong>
    approved KIM member<?= $recipient_count === 1 ? '' : 's' ?> for
    <strong><?= View::e($listing->subject) ?></strong>.
  </p>

  <form method="POST"
        action="<?= $b ?>/admin/kim/<?= View::e((string) $listing->id) ?>/mass-email">
    <input type="hidden" name="_csrf" value="<?= View::e($csrf_token) ?>">

    <div class="form-group">
      <label class="form-label" for="mass-subject">Subject <span class="text-danger">*</span></label>
      <input class="form-input" type="text" id="mass-subject" name="subject"
             required maxlength="255">
    </div>

    <div class="form-group">
      <label class="form-label" for="mass-body">Message <span class="text-danger">*</span></label>
      <textarea class="form-input" id="mass-body" name="body"
                rows="10" required style="font-family:monospace"></textarea>
    </div>

    <div class="form-actions">
      <button type="submit" class="btn btn-primary"
              onclick="return confirm('Send to <?= View::e((string) $recipient_count) ?> member(s)?')">
        Send to <?= View::e((string) $recipient_count) ?> member<?= $recipient_count === 1 ? '' : 's' ?>
      </button>
      <a href="<?= $b ?>/admin/kim/<?= View::e((string) $listing->id) ?>/show"
         class="btn btn-secondary">Cancel</a>
    </div>
  </form>
</div>
