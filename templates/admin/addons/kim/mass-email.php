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
  <h1>Mass Email &mdash; <?= View::e($listing->subject) ?></h1>
</div>
<p style="margin:-0.5rem 0 1.25rem">
  <a href="<?= $b ?>/admin/kim/<?= (int) $listing->id ?>/show" class="neb-link">
    &larr; Back to <?= View::e($listing->subject) ?>
  </a>
</p>

<div class="neb-card" style="padding:1.25rem">
  <p style="margin-bottom:1.25rem;color:var(--text-muted);font-size:.875rem">
    This will send an email to
    <strong style="color:var(--text)"><?= (int) $recipient_count ?></strong>
    approved KIM member<?= $recipient_count === 1 ? '' : 's' ?> for
    <strong style="color:var(--text)"><?= View::e($listing->subject) ?></strong>.
  </p>

  <form method="POST"
        action="<?= $b ?>/admin/kim/<?= (int) $listing->id ?>/mass-email">
    <input type="hidden" name="_csrf" value="<?= View::e($csrf_token) ?>">

    <div class="form-group">
      <label class="form-label" for="mass-subject">Subject <span>*</span></label>
      <input class="form-input" type="text" id="mass-subject" name="subject"
             required maxlength="255">
    </div>

    <div class="form-group">
      <label class="form-label" for="mass-body">Message <span>*</span></label>
      <textarea class="form-textarea" id="mass-body" name="body"
                rows="10" required style="font-family:var(--font-mono)"></textarea>
    </div>

    <div class="btn-row">
      <button type="submit" class="btn btn-primary"
              onclick="return confirm('Send to <?= (int) $recipient_count ?> member(s)?')">
        Send to <?= (int) $recipient_count ?> member<?= $recipient_count === 1 ? '' : 's' ?>
      </button>
      <a href="<?= $b ?>/admin/kim/<?= (int) $listing->id ?>/show"
         class="btn btn-secondary">Cancel</a>
    </div>
  </form>
</div>
