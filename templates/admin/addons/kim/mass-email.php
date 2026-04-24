<?php
/**
 * KIM List admin - mass email / email-selected compose form.
 *
 * Variables:
 *   $listing          Listing
 *   $recipients       array<int, array{name: string, email: string}>
 *   $recipient_count  int
 *   $selected_only    bool
 *   $selected_emails  string[]
 *   $member_names     array<string, string>   email â†’ name
 *   $from_name        string
 *   $from_email       string
 *   $owner_name       string
 *   $owner_email      string
 *   $site_name        string
 *   $saved_emails     object[]
 *   $old              array{subject: string, body: string}
 *   $base_url         string
 *   $csrf_token       string
 *   $error            string
 *   $success          string
 */

declare(strict_types=1);

use Luster\Core\View;

$b          = rtrim(View::e($base_url), '/');
$fid        = (int) $listing->id;
$flSubject  = View::e($listing->subject ?? '');
$count      = (int) $recipient_count;
$oldSubject = View::e($old['subject'] ?? '');
$oldBody    = htmlspecialchars($old['body'] ?? '', ENT_QUOTES, 'UTF-8');
?>

<div class="page-topbar">
  <a href="<?= $b ?>/admin/kim/<?= $fid ?>/show" class="link">&larr; Back to <?= $flSubject ?></a>
</div>

<?php if ($error !== ''): ?>
  <div class="alert alert-error"><?= View::e($error) ?></div>
<?php endif; ?>
<?php if ($success !== ''): ?>
  <div class="alert alert-success"><?= View::e($success) ?></div>
<?php endif; ?>

<div class="composer-grid">
  <div class="composer-side">

    <?php if (!empty($saved_emails)): ?>
    <div class="card">
      <div class="card-head">Load Template</div>
      <div class="card-body">
        <div class="select-wrap">
          <select id="es-tpl-select" >
            <option value="">&mdash; Select a Template &mdash;</option>
            <?php foreach ($saved_emails as $t): ?>
              <option value="<?= View::e($t->subject) ?>"
                      data-body="<?= htmlspecialchars($t->body, ENT_QUOTES, 'UTF-8') ?>">
                <?= View::e($t->name) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <p class="form-hint">Loading a template will replace your current subject and message.</p>
        <a href="<?= $b ?>/admin/emails?tab=custom" class="btn btn-tertiary btn-sm btn-block-center">Manage Templates</a>
      </div>
    </div>
    <?php endif; ?>

    <div class="card">
      <div class="card-head">Save as Template</div>
      <div class="card-body">
        <form method="POST" action="<?= $b ?>/admin/new-email-template/save" id="es-save-tpl-form">
          <input type="hidden" name="_csrf"       value="<?= View::e($csrf_token) ?>">
          <input type="hidden" name="referer"     value="<?= View::e($_SERVER['REQUEST_URI'] ?? '') ?>">
          <input type="hidden" name="tpl_subject" id="es-save-subject">
          <input type="hidden" name="tpl_body"    id="es-save-body">
          <div id="es-save-emails"></div>
          <input type="text" name="tpl_name" id="es-save-name"
                 class="form" placeholder="Template name&hellip;" maxlength="150">
          <button type="submit" class="btn btn-secondary btn-sm btn-block-center">Save Template</button>
        </form>
      </div>
    </div>

    <details class="card chevron" open>
      <summary class="card-head">Available Tokens</summary>
      <div class="card-body">
        <table class="et-token-table">
          <tbody>
            <tr class="tok-row" data-textarea="es-body" data-token="{name}"            title="Click to insert"><td><code>{name}</code></td><td>Member's display name</td></tr>
            <tr class="tok-row" data-textarea="es-body" data-token="{email}"           title="Click to insert"><td><code>{email}</code></td><td>Member's email address</td></tr>
            <tr class="tok-row" data-textarea="es-body" data-token="{url}"             title="Click to insert"><td><code>{url}</code></td><td>Member's website URL</td></tr>
            <tr class="tok-row" data-textarea="es-body" data-token="{added_at}"        title="Click to insert"><td><code>{added_at}</code></td><td>Sign-up date</td></tr>
            <tr class="tok-row" data-textarea="es-body" data-token="{listing_subject}" title="Click to insert"><td><code>{listing_subject}</code></td><td>Subject or topic</td></tr>
            <tr class="tok-row" data-textarea="es-body" data-token="{listing_url}"     title="Click to insert"><td><code>{listing_url}</code></td><td>URL of the listing</td></tr>
            <tr class="tok-row" data-textarea="es-body" data-token="{site_name}"       title="Click to insert"><td><code>{site_name}</code></td><td>Collective or site name</td></tr>
            <tr class="tok-row" data-textarea="es-body" data-token="{owner_name}"      title="Click to insert"><td><code>{owner_name}</code></td><td>Owner's display name</td></tr>
            <tr class="tok-row" data-textarea="es-body" data-token="{owner_email}"     title="Click to insert"><td><code>{owner_email}</code></td><td>Owner's email address</td></tr>
          </tbody>
        </table>
      </div>
    </details>

  </div><!-- /.composer-side -->

  <div class="gradient-card">
    <form method="POST" action="<?= $b ?>/admin/kim/<?= $fid ?>/mass-email" id="es-form">
      <input type="hidden" name="_csrf" value="<?= View::e($csrf_token) ?>">
      <div id="es-email-inputs">
        <?php if ($selected_only): ?>
          <?php foreach ($selected_emails as $email): ?>
            <input type="hidden" name="emails[]" value="<?= View::e($email) ?>">
          <?php endforeach; ?>
        <?php endif; ?>
      </div>

      <div class="pc-head">
        <div class="pc-head-icon pc-head-icon-bg"><?= View::icon('email') ?></div>
        <div class="pc-head-title"><?= $selected_only ? 'Email Selected Members' : 'Mass Email' ?></div>
        <span class="badge badge-info-pink pc-head-badge">
          <?= $flSubject ?> &middot; <span id="es-count-label"><?= $count ?> member<?= $count !== 1 ? 's' : '' ?></span>
        </span>
      </div>

      <div class="composer-block">
        <div class="composer-row">
          <span class="composer-lbl">From</span>
          <div>
            <?php if ($from_name !== ''): ?>
              <span class="composer-name"><?= View::e($from_name) ?></span>
            <?php endif; ?>
            <span class="composer-email">&lt;<?= View::e($from_email) ?>&gt;</span>
          </div>
        </div>
        <div class="composer-row">
          <span class="composer-lbl">To</span>
          <div id="es-chip" class="es-chip">
            <?php foreach ($selected_emails as $email):
              $name = $member_names[$email] ?? '';
            ?>
              <span class="es-chip-item" data-email="<?= View::e($email) ?>">
                <?php if ($name !== ''): ?>
                  <strong><?= View::e($name) ?></strong>
                  <span>&lt;<?= View::e($email) ?>&gt;</span>
                <?php else: ?>
                  <span><?= View::e($email) ?></span>
                <?php endif; ?>
                <?php if ($selected_only): ?>
                  <button type="button" class="es-chip-remove"
                          aria-label="Remove <?= View::e($email) ?>"
                          title="Remove recipient">&times;</button>
                <?php endif; ?>
              </span>
            <?php endforeach; ?>
          </div>
        </div>
        <div class="composer-row">
          <span class="composer-lbl">Subject</span>
          <input class="subj-input" type="text" name="subject" id="es-subject"
                 maxlength="255" required placeholder="Enter subject&hellip;"
                 value="<?= $oldSubject ?>">
        </div>
      </div>

      <div>
        <textarea class="email" id="es-body" name="body"
                  maxlength="50000" required
                  placeholder="Write your message&hellip;"><?= $oldBody ?></textarea>
      </div>

      <div class="composer-footer">
        <button type="button" class="btn btn-primary" id="es-send-btn">
          <?= View::icon('send') ?>
          Send to <span id="es-send-count"><?= $count ?></span>
          member<?= $count !== 1 ? 's' : '' ?> &rarr;
        </button>
        <button type="button" class="btn btn-preview" id="es-preview-btn">Preview</button>
        <button type="button" class="btn btn-ghost js-form-reset">Clear</button>
        <div class="composer-footer-spacer"></div>
        <a href="<?= $b ?>/admin/kim/<?= $fid ?>/show" class="btn btn-danger">Cancel</a>
      </div>
    </form>
  </div><!-- /.gradient-card -->

</div><!-- /.composer-grid -->

<!-- â”€â”€ Preview / Confirm modal â”€â”€ -->
<div class="modal-backdrop is-hidden" id="es-preview-modal">
  <div class="modal modal--wide">
    <div class="modal-header">
      <h3 class="modal-title">Email Preview</h3>
    </div>
    <div class="modal-body">
      <div class="composer-block">
        <div class="composer-row">
          <span class="composer-lbl">From</span>
          <div>
            <span class="composer-name" id="es-preview-from-name"></span>
            <span class="composer-email" id="es-preview-from-email"></span>
          </div>
        </div>
        <div class="composer-row">
          <span class="composer-lbl">To</span>
          <span class="composer-val" id="es-preview-to"></span>
        </div>
        <div class="composer-row">
          <span class="composer-lbl">Subject</span>
          <span class="composer-val" id="es-modal-subject"></span>
        </div>
      </div>
      <div id="es-modal-body" class="body-area"></div>
    </div>
    <div id="es-footer-confirm" class="is-hidden">
      <div class="me-confirm-warning"><?= View::icon('caution') ?>This will send to all listed recipients. This action cannot be undone.</div>
      <div class="modal-footer">
        <button type="button" class="btn btn-primary" id="es-modal-send">
          <?= View::icon('send') ?> Confirm &amp; Send
        </button>
        <button type="button" class="btn btn-danger" id="es-modal-cancel">Cancel</button>
      </div>
    </div>
    <div class="modal-footer" id="es-footer-preview">
      <button type="button" class="btn btn-ghost" id="es-modal-close-preview">Close</button>
    </div>
  </div>
</div>

<div id="es-preview-data"
     data-first-name="<?= View::e(reset($member_names) ?: '') ?>"
     data-first-email="<?= View::e(array_key_first($member_names) ?? '') ?>"
     data-listing-name="<?= $flSubject ?>"
     data-listing-subject="<?= $flSubject ?>"
     data-listing-url="<?= View::e($listing->url ?? '') ?>"
     data-site-name="<?= View::e($site_name) ?>"
     data-owner-name="<?= View::e($owner_name) ?>"
     data-owner-email="<?= View::e($owner_email) ?>"
     class="is-hidden"></div>
