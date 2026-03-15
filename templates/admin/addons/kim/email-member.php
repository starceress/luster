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

<div class="page-header">
  <div class="page-header-left">
    <nav class="breadcrumb">
      <a href="<?= $b ?>/admin/addons">Add-ons</a>
      <span class="breadcrumb-sep">/</span>
      <a href="<?= $b ?>/admin/kim">KIM List</a>
      <?php if ($listing !== null): ?>
        <span class="breadcrumb-sep">/</span>
        <a href="<?= $b ?>/admin/kim/<?= View::e((string) $listing->id) ?>/show">
          <?= View::e($listing->subject) ?>
        </a>
      <?php endif; ?>
      <span class="breadcrumb-sep">/</span>
      <span>Email <?= View::e($member->name) ?></span>
    </nav>
    <h1>Email <?= View::e($member->name) ?></h1>
  </div>
</div>

<div class="neb-card">
  <p>
    Sending to: <strong><?= View::e($member->name) ?></strong>
    &lt;<a href="mailto:<?= View::e($member->email) ?>"><?= View::e($member->email) ?></a>&gt;
  </p>

  <form method="POST"
        action="<?= $b ?>/admin/kim/<?= View::e((string) $member->id) ?>/email-member">
    <input type="hidden" name="_csrf" value="<?= View::e($csrf_token) ?>">

    <div class="form-group">
      <label class="form-label" for="em-subject">Subject <span class="text-danger">*</span></label>
      <input class="form-input" type="text" id="em-subject" name="subject"
             required maxlength="255">
    </div>

    <div class="form-group">
      <label class="form-label" for="em-body">Message <span class="text-danger">*</span></label>
      <textarea class="form-input" id="em-body" name="body"
                rows="10" required style="font-family:monospace"></textarea>
    </div>

    <div class="form-actions">
      <button type="submit" class="btn btn-primary">Send Email</button>
      <?php if ($listing !== null): ?>
        <a href="<?= $b ?>/admin/kim/<?= View::e((string) $listing->id) ?>/show"
           class="btn btn-secondary">Cancel</a>
      <?php else: ?>
        <a href="<?= $b ?>/admin/kim" class="btn btn-secondary">Cancel</a>
      <?php endif; ?>
    </div>
  </form>
</div>
