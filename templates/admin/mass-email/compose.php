<?php

declare(strict_types=1);

use Luster\Core\View;

/**
 * @var array  $listings        Listing[]
 * @var array  $saved_emails    object[]
 * @var string $error
 * @var string $success
 * @var int    $old_listing_id
 * @var string $old_subject
 * @var string $old_body
 * @var string $base_url
 * @var string $csrf_token
 */

$b = rtrim(View::e($base_url ?? ''), '/');
?>

<div style="display:flex;align-items:center;justify-content:space-between;gap:1rem;margin-bottom:1.5rem">
  <div class="neb-tabs" style="margin-bottom:0">
    <a href="<?= $b ?>/admin/email-templates"      class="neb-tab-btn">Email Templates</a>
    <a href="<?= $b ?>/admin/emails"               class="neb-tab-btn active">Compose Email</a>
    <a href="<?= $b ?>/admin/new-email-template"   class="neb-tab-btn">New Template</a>
    <a href="<?= $b ?>/admin/emails/smtp-settings" class="neb-tab-btn">Email/SMTP Settings</a>
  </div>
  <a href="<?= $b ?>/admin/members" class="btn btn-ghost btn-sm">&larr; Members</a>
</div>

<?php if (($error ?? '') !== ''): ?>
  <div class="alert alert-error"><?= View::e($error) ?></div>
<?php endif; ?>
<?php if (($success ?? '') !== ''): ?>
  <div class="alert alert-success"><?= View::e($success) ?></div>
<?php endif; ?>

<div class="neb-card" style="padding:1.75rem 2rem">
  <p style="margin:0 0 1.5rem;color:var(--text-muted);font-size:.9rem;line-height:1.6">
    Send a personalised email to all approved members of one listing, or every listing at once.
    Recipients are deduplicated — each address receives at most one message.
  </p>

  <?php if (!empty($saved_emails)): ?>
  <div class="form-group" style="margin-bottom:1.5rem;padding-bottom:1.5rem;border-bottom:1px solid var(--border)">
    <label class="form-label">Load saved template</label>
    <div style="display:flex;align-items:center;gap:.75rem;flex-wrap:wrap">
      <div class="select-wrap" style="flex:1;min-width:200px">
        <select id="me-tpl-select">
          <option value="">— Select a template —</option>
          <?php foreach ($saved_emails as $t): ?>
            <option value="<?= View::e($t->subject) ?>"
                    data-body="<?= htmlspecialchars($t->body, ENT_QUOTES, 'UTF-8') ?>">
              <?= View::e($t->name) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <a href="<?= $b ?>/admin/new-email-template" class="btn btn-ghost btn-sm">Manage templates</a>
    </div>
    <p class="form-hint" style="margin-top:.35rem">Selecting fills the subject and message fields. You can edit before sending.</p>
  </div>
  <?php endif; ?>

  <form method="POST" action="<?= $b ?>/admin/emails/preview" id="compose-form">
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
        <code>{site_name}</code> &mdash; site name
      </p>
    </div>

    <div class="btn-row">
      <button type="submit" class="btn btn-primary">Preview &amp; confirm &rarr;</button>
    </div>
  </form>

  <!-- Save as template -->
  <div style="margin-top:1.5rem;padding-top:1.25rem;border-top:1px solid var(--border)">
    <p style="margin:0 0 .75rem;font-size:.85rem;color:var(--text-muted)">Save the current subject &amp; message as a reusable template:</p>
    <form method="POST" action="<?= $b ?>/admin/new-email-template/save" id="me-save-tpl-form"
          style="display:flex;align-items:center;gap:.5rem;flex-wrap:wrap">
      <input type="hidden" name="_csrf"       value="<?= View::e($csrf_token ?? '') ?>">
      <input type="hidden" name="referer"     value="<?= View::e($b . '/admin/new-email-template') ?>">
      <input type="hidden" name="tpl_subject" id="me-save-subject">
      <input type="hidden" name="tpl_body"    id="me-save-body">
      <input type="text"   name="tpl_name"    id="me-save-name"
             class="neb-input" placeholder="Template name&hellip;" style="flex:1;min-width:160px" maxlength="150">
      <button type="submit" class="btn btn-secondary btn-sm" id="me-save-btn">Save template</button>
    </form>
  </div>
</div>

