<?php

declare(strict_types=1);

use Luster\Core\View;

/**
 * @var \Luster\Models\Listing              $listing
 * @var int                                  $listing_id
 * @var \Luster\Addons\Rules\RuleSet|null    $rules      null = no rules yet
 * @var string                               $error
 * @var string                               $base_url
 * @var string                               $csrf_token
 */

$b = rtrim(View::e($base_url), '/');
?>

<?php if ($error !== ''): ?>
<div class="alert alert-error" style="margin-bottom:1.25rem"><?= View::e($error) ?></div>
<?php endif; ?>

<?php if (isset($success) && $success !== ''): ?>
<div class="alert alert-success" style="margin-bottom:1.25rem"><?= View::e($success) ?></div>
<?php endif; ?>

<div class="main-card">
  <div class="composer-grid">

    <!-- ── Main form ──────────────────────────────────────────────────── -->
    <div class="composer-main">
      <form method="POST" action="<?= $b ?>/admin/rules/<?= (int) $listing_id ?>/edit">
        <input type="hidden" name="_csrf" value="<?= View::e($csrf_token) ?>">

        <div class="gradient-card">
          <div class="listing-field-section">

            <div class="listing-field-row listing-field-row--top">
              <label class="listing-field-label" for="rules-title">Heading</label>
              <div>
                <input class="form" type="text" id="rules-title" name="title"
                       maxlength="255"
                       value="<?= View::e($rules?->title ?? 'Join Rules') ?>"
                       placeholder="Join Rules">
                <p class="form-hint">Shown above the rules text on your site. Defaults to "Join Rules".</p>
              </div>
            </div>

            <div class="listing-field-row">
              <label class="listing-field-label" for="rules-body">
                Rules Text <span class="req">*</span>
              </label>
              <div>
                <textarea class="form form-textarea" id="rules-body" name="body"
                          rows="14"
                          maxlength="10000"
                          placeholder="Enter your join rules here&#x0a;&#x0a;You can use line breaks to separate rule items."><?= View::e($rules?->body ?? '') ?></textarea>
                <p class="form-hint">Plain text only. Line breaks are preserved when displayed. Editing this text will invalidate all existing fan agreements and require re-acceptance.</p>
              </div>
            </div>

            <div class="listing-field-row">
              <label class="listing-field-label" for="rules-enabled">Enabled</label>
              <div>
                <label class="toggle-label">
                  <input type="checkbox" id="rules-enabled" name="enabled" value="1"
                         <?= ($rules === null || $rules->enabled) ? 'checked' : '' ?>>
                  <span class="toggle-switch"></span>
                  <span class="toggle-text">Show rules gate on this listing</span>
                </label>
                <p class="form-hint">When disabled, the gate is hidden and fans can join without agreeing. The rules text is preserved.</p>
              </div>
            </div>

          </div><!-- /.listing-field-section -->

          <div class="composer-footer">
            <button type="submit" class="btn btn-primary">Save Rules</button>
            <a href="<?= $b ?>/admin/rules" class="btn btn-ghost">Cancel</a>
          </div>
        </div><!-- /.gradient-card -->
      </form>

      <?php if ($rules !== null): ?>
      <div class="gradient-card" style="margin-top:1.25rem">
        <div class="pc-head">Danger Zone</div>
        <p style="margin:0 0 .875rem;font-size:var(--fs-sm);color:var(--text-muted)">
          Deleting rules removes the rules text entirely for this listing. Fans will no longer be shown a rules gate.
        </p>
        <form method="POST"
              action="<?= $b ?>/admin/rules/<?= (int) $listing_id ?>/delete"
              id="rules-delete-form">
          <input type="hidden" name="_csrf" value="<?= View::e($csrf_token) ?>">
          <button type="button" class="btn btn-danger btn-sm js-rules-del-trigger">
            Delete Rules for <?= View::e($listing->title) ?>
          </button>
        </form>
      </div>
      <?php endif; ?>
    </div><!-- /.composer-main -->

    <!-- ── Sidebar ────────────────────────────────────────────────────── -->
    <div class="composer-side">

      <?php if ($rules !== null): ?>
      <div class="panel-card">
        <div class="pc-head">Status</div>
        <div class="pc-section">
          <div class="listing-field-row listing-field-row--compact">
            <span class="listing-field-label text-muted">Listing</span>
            <span><?= View::e($listing->title) ?></span>
          </div>
          <div class="listing-field-row listing-field-row--compact">
            <span class="listing-field-label text-muted">State</span>
            <span>
              <?= $rules->enabled
                ? '<span class="badge badge-current">Enabled</span>'
                : '<span class="badge badge-pending">Disabled</span>' ?>
            </span>
          </div>
          <div class="listing-field-row listing-field-row--compact">
            <span class="listing-field-label text-muted">Version</span>
            <span>v<?= (int) $rules->version ?></span>
          </div>
          <div class="listing-field-row listing-field-row--compact">
            <span class="listing-field-label text-muted">Updated</span>
            <span class="text-muted"><?= View::e(date('Y-m-d', strtotime($rules->updatedAt))) ?></span>
          </div>
        </div>
      </div>
      <?php endif; ?>

      <div class="panel-card">
        <div class="pc-head">How to Use</div>
        <div class="pc-section">
          <p style="font-size:var(--fs-sm);color:var(--text-muted);margin:0 0 .75rem">
            Add the rules gate to your join page using the embed file. Place it <em>before</em> your join embed:
          </p>
          <pre style="font-size:.7rem;background:var(--bg-input);border:1px solid var(--border);border-radius:6px;padding:.625rem;overflow:auto;white-space:pre-wrap;word-break:break-all;margin:0 0 .75rem;color:var(--text-muted)">$luster_fanlisting_id = <?= (int) $listing_id ?>;
$luster_return = true;

$gate = require '/path/to/luster/public/embed/rules-gate.php';
if ($gate !== '') {
    echo $gate;
    return; // stop rendering — fan must agree first
}

// Show join form here
require '/path/to/luster/public/embed/join.php';</pre>
          <p style="font-size:var(--fs-sm);color:var(--text-muted);margin:0">
            The gate returns an empty string once the fan has agreed, so your join form renders normally.
          </p>
        </div>
      </div>

      <div class="panel-card">
        <div class="pc-head">Agreement Reset</div>
        <div class="pc-section">
          <p style="font-size:var(--fs-sm);color:var(--text-muted);margin:0">
            Each time you save a new rules text, the version number increments and all fan agreements in active sessions become invalid. Fans will see the rules gate again on their next visit.
          </p>
        </div>
      </div>

    </div><!-- /.composer-side -->
  </div><!-- /.composer-grid -->
</div><!-- /.main-card -->

<?php if ($rules !== null): ?>
<div id="rules-del-confirm-modal" class="modal-backdrop is-hidden" role="dialog" aria-modal="true" aria-labelledby="rules-del-modal-title">
  <div class="modal">
    <div class="modal-header">
      <h3 class="modal-title" id="rules-del-modal-title">Delete Rules</h3>
      <button type="button" class="modal-close" aria-label="Close">&times;</button>
    </div>
    <div class="modal-body">
      <p>Permanently delete the rules for <strong><?= View::e($listing->title) ?></strong>?</p>
      <div class="modal-warning">
        <strong>Warning:</strong> The rules text will be removed and fans will no longer see a rules gate for this listing. This cannot be undone.
      </div>
    </div>
    <div class="modal-footer">
      <button type="button" class="btn btn-danger" id="rules-del-modal-confirm">Delete</button>
      <button type="button" class="btn btn-quaternary" id="rules-del-modal-cancel">Cancel</button>
    </div>
  </div>
</div>
<script>
(function () {
  var modal   = document.getElementById('rules-del-confirm-modal');
  var confirm = document.getElementById('rules-del-modal-confirm');
  var cancel  = document.getElementById('rules-del-modal-cancel');
  var close   = modal.querySelector('.modal-close');
  var form    = document.getElementById('rules-delete-form');
  var trigger = document.querySelector('.js-rules-del-trigger');
  if (!modal || !trigger || !form) return;
  function openModal()  { modal.classList.remove('is-hidden'); confirm.focus(); }
  function closeModal() { modal.classList.add('is-hidden'); }
  trigger.addEventListener('click', openModal);
  confirm.addEventListener('click', function () { form.submit(); });
  cancel.addEventListener('click', closeModal);
  close.addEventListener('click', closeModal);
  modal.addEventListener('click', function (e) { if (e.target === modal) closeModal(); });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape') closeModal(); });
}());
</script>
<?php endif; ?>
