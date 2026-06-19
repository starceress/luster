<?php
/**
 * Adoptions admin — bulk email selected applicants (composer layout).
 *
 * Variables:
 *   $applications    array[]               Selected application rows
 *   $applicant_names array<string,string>  email => name
 *   $listing_map     array<int,Listing>    fanlisting_id => Listing
 *   $recipient_count int
 *   $from_name       string
 *   $from_email      string
 *   $owner_name      string
 *   $owner_email     string
 *   $site_name       string
 *   $saved_emails    object[]
 *   $return_url      string
 *   $old             array{subject: string, body: string}
 *   $error           string
 *   $base_url        string
 *   $csrf_token      string
 */

declare(strict_types=1);

use Luster\Core\View;

$b          = rtrim(View::e($base_url), '/');
$count      = (int) $recipient_count;
$oldSubject = View::e($old['subject'] ?? '');
$oldBody    = htmlspecialchars($old['body'] ?? '', ENT_QUOTES, 'UTF-8');
?>

<div class="page-topbar">
  <a href="<?= View::e($return_url) ?>" class="link">&larr; Back to Adoptions</a>
</div>

<?php if (($error ?? '') !== ''): ?>
  <div class="alert alert-error"><?= View::e($error) ?></div>
<?php endif; ?>

<div class="composer-grid">
  <div class="composer-side">

    <?php if (!empty($saved_emails)): ?>
    <div class="card">
      <div class="card-head">Load Template</div>
      <div class="card-body">
        <div class="select-wrap">
          <select id="es-tpl-select">
            <option value="">&mdash; Select a Template &mdash;</option>
            <?php foreach ($saved_emails as $t): ?>
              <option value="<?= View::e($t->subject) ?>"
                      data-body="<?= htmlspecialchars($t->body, ENT_QUOTES, 'UTF-8') ?>">
                <?= View::e($t->name) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <p class="form-hint">Selecting fills the subject and message fields. You can edit before sending.</p>
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
          <p class="form-hint">This saves the message as a reusable starting point. It does not send anything.</p>
          <button type="submit" class="btn btn-secondary btn-sm btn-block-center">Save Template</button>
        </form>
      </div>
    </div>

    <div class="search-field-wrap token-search-wrap">
      <?= View::icon('search', ['class' => 'search-field-icon', 'aria-hidden' => 'true']) ?>
      <input type="search"
             data-token-search
             class="form"
             placeholder="Search email tokens"
             autocomplete="off"
             aria-label="Search email tokens">
      <button type="button"
              data-token-search-clear
              class="token-search-clear"
              aria-label="Clear search"
              title="Clear search"
              hidden><?= View::icon('x', ['aria-hidden' => 'true']) ?></button>
    </div>

    <p data-token-search-empty class="form-hint token-search-empty" hidden>No tokens found.</p>

    <details class="card chevron" data-token-card>
      <summary class="chevron-head">Available Tokens</summary>
      <div class="chevron-body">
        <p class="form-hint mb-sm" data-token-search-help>Tokens are replaced per recipient using each applicant's own data.</p>
        <table class="et-token-table et-token-table--compact">
          <tbody>
            <tr class="tok-row" tabindex="0" role="button" title="Applicant's name"            aria-label="Insert {app_name}. Applicant's name"             data-textarea="es-body" data-token="{app_name}">       <td class="token-cell" data-desc="Applicant's name">            <code>{app_name}</code></td></tr>
            <tr class="tok-row" tabindex="0" role="button" title="Applicant's email address"   aria-label="Insert {app_email}. Applicant's email address"    data-textarea="es-body" data-token="{app_email}">      <td class="token-cell" data-desc="Applicant's email address">   <code>{app_email}</code></td></tr>
            <tr class="tok-row" tabindex="0" role="button" title="Applicant's website URL"     aria-label="Insert {app_url}. Applicant's website URL"        data-textarea="es-body" data-token="{app_url}">        <td class="token-cell" data-desc="Applicant's website URL">     <code>{app_url}</code></td></tr>
            <tr class="tok-row" tabindex="0" role="button" title="Message they submitted"      aria-label="Insert {app_message}. Message they submitted"     data-textarea="es-body" data-token="{app_message}">    <td class="token-cell" data-desc="Message they submitted">      <code>{app_message}</code></td></tr>
            <tr class="tok-row" tabindex="0" role="button" title="Current application status"  aria-label="Insert {app_status}. Current application status"  data-textarea="es-body" data-token="{app_status}">     <td class="token-cell" data-desc="Current application status">  <code>{app_status}</code></td></tr>
            <tr class="tok-row" tabindex="0" role="button" title="Name of the listing applied for"  aria-label="Insert {listing_title}. Name of the listing applied for"  data-textarea="es-body" data-token="{listing_title}">  <td class="token-cell" data-desc="Name of the listing applied for">  <code>{listing_title}</code></td></tr>
            <tr class="tok-row" tabindex="0" role="button" title="URL of the listing"          aria-label="Insert {listing_url}. URL of the listing"         data-textarea="es-body" data-token="{listing_url}">    <td class="token-cell" data-desc="URL of the listing">          <code>{listing_url}</code></td></tr>
            <tr class="tok-row" tabindex="0" role="button" title="Collective or site name"     aria-label="Insert {site_name}. Collective or site name"      data-textarea="es-body" data-token="{site_name}">      <td class="token-cell" data-desc="Collective or site name">     <code>{site_name}</code></td></tr>
            <tr class="tok-row" tabindex="0" role="button" title="Owner's display name"        aria-label="Insert {owner_name}. Owner's display name"        data-textarea="es-body" data-token="{owner_name}">     <td class="token-cell" data-desc="Owner's display name">        <code>{owner_name}</code></td></tr>
            <tr class="tok-row" tabindex="0" role="button" title="Owner's email address"       aria-label="Insert {owner_email}. Owner's email address"      data-textarea="es-body" data-token="{owner_email}">    <td class="token-cell" data-desc="Owner's email address">       <code>{owner_email}</code></td></tr>
          </tbody>
        </table>
      </div>
    </details>

  </div><!-- /.composer-side -->

  <div class="gradient-card">
    <form method="POST" action="<?= $b ?>/admin/adoptions/email" id="es-form">
      <input type="hidden" name="_csrf"    value="<?= View::e($csrf_token) ?>">
      <input type="hidden" name="_return"  value="<?= View::e($return_url) ?>">
      <?php if (isset($source_listing_id) && $source_listing_id > 0): ?>
        <input type="hidden" name="source_listing_id" value="<?= (int) $source_listing_id ?>">
        <input type="hidden" name="source_statuses"   value="<?= View::e($source_statuses ?? '') ?>">
      <?php endif; ?>
      <div id="es-id-inputs">
        <?php foreach ($applications as $app): ?>
          <input type="hidden" name="ids[]" value="<?= (int) $app['id'] ?>">
        <?php endforeach; ?>
      </div>

      <div class="pc-head">
        <div class="pc-head-icon pc-head-icon-bg"><?= View::icon('email') ?></div>
        <div class="pc-head-title">Email Selected Applicants</div>
        <span class="badge badge-info-pink pc-head-badge">
          <span id="es-count-label"><?= $count ?> applicant<?= $count !== 1 ? 's' : '' ?></span>
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
            <?php foreach ($applications as $app):
              $appName = (string) $app['name'];
              $appEmail = (string) $app['email'];
            ?>
              <span class="es-chip-item" data-id="<?= (int) $app['id'] ?>">
                <?php if ($appName !== ''): ?>
                  <strong><?= View::e($appName) ?></strong>
                  <span>&lt;<?= View::e($appEmail) ?>&gt;</span>
                <?php else: ?>
                  <span><?= View::e($appEmail) ?></span>
                <?php endif; ?>
                <button type="button" class="es-chip-remove"
                        aria-label="Remove <?= View::e($appEmail) ?>"
                        title="Remove recipient">&times;</button>
              </span>
            <?php endforeach; ?>
          </div>
        </div>
        <div class="composer-row">
          <span class="composer-lbl">Subject</span>
          <div class="flex-fill">
            <input class="subj-input" type="text" name="subject" id="es-subject"
                   maxlength="255" required placeholder="Enter subject&hellip;"
                   value="<?= $oldSubject ?>">
            <p class="form-hint">Tokens work in the subject line too.</p>
          </div>
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
          applicant<?= $count !== 1 ? 's' : '' ?> &rarr;
        </button>
        <button type="button" class="btn btn-preview" id="es-preview-btn"><?= View::icon('eye-on') ?> Preview</button>
        <button type="button" class="btn btn-ghost js-form-reset">Clear</button>
        <div class="composer-footer-spacer"></div>
        <a href="<?= View::e($return_url) ?>" class="btn btn-danger">Cancel</a>
      </div>
    </form>
  </div><!-- /.gradient-card -->

</div><!-- /.composer-grid -->

<!-- ──── PREVIEW / CONFIRM MODAL ────────────────────────────────────── -->
<div class="modal-backdrop is-hidden" id="es-preview-modal">
  <div class="modal modal--wide">
    <div class="modal-header">
      <h3 class="modal-title">Email Preview</h3>
      <button type="button" class="modal-close" aria-label="Close">&times;</button>
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
        <button type="button" class="btn btn-quaternary" id="es-modal-cancel">Cancel</button>
      </div>
    </div>
    <div class="modal-footer" id="es-footer-preview">
      <button type="button" class="btn btn-quaternary" id="es-modal-close-preview">Close</button>
    </div>
  </div>
</div>

<div id="es-preview-data"
     data-context="adoption"
     data-first-name="<?= View::e(reset($applicant_names) ?: '') ?>"
     data-first-email="<?= View::e((string) array_key_first($applicant_names)) ?>"
     data-listing-name="<?= View::e($listing_map[reset($applications)['fanlisting_id'] ?? 0]?->title ?? '') ?>"
     data-listing-subject=""
     data-listing-url=""
     data-app-message="<?= htmlspecialchars((string) (reset($applications)['message'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
     data-site-name="<?= View::e($site_name) ?>"
     data-owner-name="<?= View::e($owner_name) ?>"
     data-owner-email="<?= View::e($owner_email) ?>"
     class="is-hidden"></div>

<script>
(function () {
  // Chip removal: remove the chip AND the matching hidden id input, update send count
  var chip = document.getElementById('es-chip');
  var idInputs = document.getElementById('es-id-inputs');
  var sendCount = document.getElementById('es-send-count');
  var countLabel = document.getElementById('es-count-label');

  if (!chip || !idInputs) return;

  chip.addEventListener('click', function (e) {
    var btn = e.target.closest('.es-chip-remove');
    if (!btn) return;
    var item = btn.closest('.es-chip-item');
    if (!item) return;
    var id = item.getAttribute('data-id');
    item.remove();
    var hidden = idInputs.querySelector('input[name="ids[]"][value="' + id + '"]');
    if (hidden) hidden.remove();
    var remaining = idInputs.querySelectorAll('input[name="ids[]"]').length;
    if (sendCount)  sendCount.textContent  = remaining;
    if (countLabel) countLabel.textContent = remaining + ' applicant' + (remaining !== 1 ? 's' : '');
  });
}());
</script>
