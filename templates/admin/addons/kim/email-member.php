<?php
/**
 * KIM List admin — email individual member form.
 *
 * Variables:
 *   $member      KimMember
 *   $listing     Listing|null
 *   $base_url    string
 *   $csrf_token  string
 *   $error       string
 */

declare(strict_types=1);

use Luster\Core\View;

$b = rtrim(View::e($base_url), '/');
?>

<?php if ($error !== ''): ?>
  <div class="alert alert-error"><?= View::e($error) ?></div>
<?php endif; ?>

<p class="page-back">
  <?php if ($listing !== null): ?>
    <a href="<?= $b ?>/admin/kim/<?= (int) $listing->id ?>/show" class="neb-link">
      &larr; Back to <?= View::e($listing->subject) ?>
    </a>
  <?php else: ?>
    <a href="<?= $b ?>/admin/kim" class="neb-link">&larr; Back to KIM List</a>
  <?php endif; ?>
</p>

<div class="neb-card" style="padding:1.25rem">
  <p style="margin-bottom:1.25rem;color:var(--text-muted);font-size:.875rem">
    Sending to: <strong style="color:var(--text)"><?= View::e($member->name) ?></strong>
    &lt;<a href="mailto:<?= View::e($member->email) ?>" class="neb-link"><?= View::e($member->email) ?></a>&gt;
  </p>

  <form method="POST"
        action="<?= $b ?>/admin/kim/<?= (int) $member->id ?>/email-member">
    <input type="hidden" name="_csrf" value="<?= View::e($csrf_token) ?>">

    <div class="form-group">
      <label class="form-label" for="em-subject">Subject <span>*</span></label>
      <input class="form-input" type="text" id="em-subject" name="subject"
             required maxlength="255">
    </div>

    <div class="form-group">
      <label class="form-label" for="em-body">Message <span>*</span></label>
      <textarea class="form-textarea" id="em-body" name="body"
                rows="10" required style="font-family:'DM Mono',monospace"></textarea>
    </div>

    <div class="btn-row">
      <button type="submit" class="btn btn-primary">Send Email</button>
      <?php if ($listing !== null): ?>
        <a href="<?= $b ?>/admin/kim/<?= (int) $listing->id ?>/show"
           class="btn btn-secondary">Cancel</a>
      <?php else: ?>
        <a href="<?= $b ?>/admin/kim" class="btn btn-secondary">Cancel</a>
      <?php endif; ?>
    </div>
  </form>
</div>
