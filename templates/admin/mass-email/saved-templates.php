<?php

declare(strict_types=1);

use Luster\Core\View;

/**
 * @var object[] $saved_emails
 * @var string   $error
 * @var string   $success
 * @var string   $base_url
 * @var string   $csrf_token
 */

$b = rtrim(View::e($base_url ?? ''), '/');
?>

<div style="display:flex;align-items:center;justify-content:space-between;gap:1rem;margin-bottom:1.5rem">
  <div class="neb-tabs" style="margin-bottom:0">
    <a href="<?= $b ?>/admin/mass-email"      class="neb-tab-btn">Compose</a>
    <a href="<?= $b ?>/admin/saved-emails"    class="neb-tab-btn active">Saved Templates</a>
    <a href="<?= $b ?>/admin/email-templates" class="neb-tab-btn">Email Templates</a>
  </div>
  <a href="<?= $b ?>/admin/members" class="btn btn-ghost btn-sm">&larr; Members</a>
</div>

<?php if (($error ?? '') !== ''): ?>
  <div class="alert alert-error"><?= View::e($error) ?></div>
<?php endif; ?>
<?php if (($success ?? '') !== ''): ?>
  <div class="alert alert-success"><?= View::e($success) ?></div>
<?php endif; ?>

<!-- New template form -->
<div class="neb-card" style="padding:1.5rem 2rem;margin-bottom:1.5rem">
  <h3 style="margin:0 0 1rem;font-size:1rem;font-weight:600">Add new template</h3>
  <form method="POST" action="<?= $b ?>/admin/saved-emails/save">
    <input type="hidden" name="_csrf"   value="<?= View::e($csrf_token ?? '') ?>">
    <input type="hidden" name="referer" value="<?= View::e($b . '/admin/saved-emails') ?>">

    <div class="form-group">
      <label class="form-label" for="new-tpl-name">Template name <span>*</span></label>
      <input id="new-tpl-name" type="text" name="tpl_name" class="form-input"
             maxlength="150" required placeholder="e.g. Welcome note, Monthly update&hellip;">
    </div>

    <div class="form-group">
      <label class="form-label" for="new-tpl-subject">Subject</label>
      <input id="new-tpl-subject" type="text" name="tpl_subject" class="form-input"
             maxlength="255" placeholder="(optional — leave blank to fill in when composing)">
    </div>

    <div class="form-group">
      <label class="form-label" for="new-tpl-body">Body <span>*</span></label>
      <textarea id="new-tpl-body" name="tpl_body" class="form-textarea"
                rows="8" maxlength="50000" required
                style="font-family:'DM Mono',monospace"></textarea>
      <p class="form-hint">
        Available tokens:
        <code>{name}</code> &mdash; member's name &nbsp;|&nbsp;
        <code>{email}</code> &mdash; member's email &nbsp;|&nbsp;
        <code>{listing_name}</code> &mdash; listing name &nbsp;|&nbsp;
        <code>{site_name}</code> &mdash; site name
      </p>
    </div>

    <div class="btn-row">
      <button type="submit" class="btn btn-primary">Save template</button>
    </div>
  </form>
</div>

<!-- Existing templates -->
<?php if (empty($saved_emails)): ?>
  <div class="neb-card">
    <div class="neb-empty">
      <svg class="empty-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
        <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>
      </svg>
      No saved templates yet. Use the form above to create one, or save a draft while composing.
    </div>
  </div>
<?php else: ?>
  <div class="neb-card" style="overflow:hidden">
    <table class="data-table">
      <thead>
        <tr>
          <th>Name</th>
          <th>Subject</th>
          <th style="width:220px">Body preview</th>
          <th>Saved</th>
          <th class="act-col">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($saved_emails as $t): ?>
        <tr>
          <td class="text-strong"><?= View::e($t->name) ?></td>
          <td class="text-muted"><?= $t->subject !== '' ? View::e($t->subject) : '<span class="text-dim">—</span>' ?></td>
          <td class="text-muted" style="max-width:220px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">
            <?= View::e(mb_substr($t->body, 0, 80)) ?><?= mb_strlen($t->body) > 80 ? '…' : '' ?>
          </td>
          <td class="text-muted" style="white-space:nowrap">
            <?= View::e(date('j M Y', strtotime($t->created_at))) ?>
          </td>
          <td class="act-col">
            <div class="row-actions">
              <a href="<?= $b ?>/admin/mass-email?load=<?= (int) $t->id ?>"
                 class="btn btn-secondary btn-sm">Use</a>
              <form method="POST" action="<?= $b ?>/admin/saved-emails/<?= (int) $t->id ?>/delete"
                    style="display:contents" data-confirm="Delete this template?">
                <input type="hidden" name="_csrf" value="<?= View::e($csrf_token ?? '') ?>">
                <button type="submit" class="btn btn-danger btn-sm">Delete</button>
              </form>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
<?php endif; ?>
