<?php

declare(strict_types=1);

use Luster\Core\View;

/**
 * @var array  $listings        Listing[]
 * @var string $error
 * @var int    $old_listing_id
 * @var string $old_subject
 * @var string $old_body
 * @var string $base_url
 * @var string $csrf_token
 */

$b = rtrim(View::e($base_url ?? ''), '/');
?>

<?php if ($error !== ''): ?>
  <div class="alert alert-error"><?= View::e($error) ?></div>
<?php endif; ?>

<div class="neb-card" style="padding:1.75rem 2rem">
  <p style="margin:0 0 1.5rem;color:var(--text-muted);font-size:.9rem;line-height:1.6">
    Send a personalised email to all approved members of one listing, or every listing at once.
    Recipients are deduplicated — each address receives at most one message.
  </p>

  <form method="POST" action="<?= $b ?>/admin/mass-email/preview" id="compose-form">
    <input type="hidden" name="_csrf" value="<?= View::e($csrf_token ?? '') ?>">

    <div class="form-group">
      <label class="form-label" for="me-listing">Send to</label>
      <div class="select-wrap">
        <select name="listing_id" id="me-listing" class="form-select">
          <option value="0"<?= ($old_listing_id ?? 0) === 0 ? ' selected' : '' ?>>
            All listings (deduplicated)
          </option>
          <?php foreach ($listings as $lst): ?>
            <option value="<?= (int) $lst->id ?>"
              <?= ($old_listing_id ?? 0) === (int) $lst->id ? ' selected' : '' ?>>
              <?= View::e($lst->name) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>

    <div class="form-group">
      <label class="form-label" for="me-subject">Subject <span>*</span></label>
      <input id="me-subject" type="text" name="subject" class="form-input"
             maxlength="255" required
             value="<?= View::e($old_subject ?? '') ?>">
    </div>

    <div class="form-group">
      <label class="form-label" for="me-body">Message body <span>*</span></label>
      <textarea id="me-body" name="body" class="form-textarea"
                rows="14" maxlength="50000"
                required
                style="font-family:'DM Mono',monospace"><?= htmlspecialchars($old_body ?? '', ENT_QUOTES, 'UTF-8') ?></textarea>
      <p class="form-hint">
        Available tokens:
        <code>{name}</code> &mdash; member's name &nbsp;|&nbsp;
        <code>{email}</code> &mdash; member's email &nbsp;|&nbsp;
        <code>{listing_name}</code> &mdash; listing name &nbsp;|&nbsp;
        <code>{app_name}</code> &mdash; site name
      </p>
    </div>

    <div class="btn-row">
      <button type="submit" class="btn btn-primary">Preview &amp; confirm &rarr;</button>
    </div>
  </form>
</div>
