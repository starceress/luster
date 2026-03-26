<?php declare(strict_types=1);
use Luster\Core\View;

/**
 * @var \Luster\Models\Listing  $listing
 * @var string[]                $selected_emails
 * @var array<string,string>    $member_names      email → name
 * @var object[]                $saved_emails
 * @var string                  $from_name
 * @var string                  $from_email
 * @var string                  $site_name
 * @var string                  $error
 * @var string                  $success
 * @var array                   $old               ['subject'=>..., 'body'=>...]
 * @var string                  $base_url
 * @var string                  $csrf_token
 */

$b          = rtrim(View::e($base_url), '/');
$listingId  = (int) ($listing->id ?? 0);
$count      = count($selected_emails);
$oldSubject = htmlspecialchars($old['subject'] ?? '', ENT_QUOTES, 'UTF-8');
$oldBody    = htmlspecialchars($old['body']    ?? '', ENT_QUOTES, 'UTF-8');
?>

<?php if (($error ?? '') !== ''): ?>
  <div class="alert alert-error"><?= View::e($error) ?></div>
<?php endif; ?>
<?php if (($success ?? '') !== ''): ?>
  <div class="alert alert-success"><?= View::e($success) ?></div>
<?php endif; ?>

<p style="margin-bottom:1.25rem">
  <a href="<?= $b ?>/admin/members/<?= $listingId ?>" class="link">&larr; Back to Members</a>
</p>

<div class="composer-grid">

  <!-- ── Main compose card ──────────────────────────────────────────────── -->
  <div class="card composer-card">
    <form method="POST" action="<?= $b ?>/admin/members/<?= $listingId ?>/email-selected/send"
          id="es-form">
      <input type="hidden" name="_csrf" value="<?= View::e($csrf_token) ?>">
      <div id="es-email-inputs">
        <?php foreach ($selected_emails as $email): ?>
          <input type="hidden" name="emails[]" value="<?= View::e($email) ?>">
        <?php endforeach; ?>
      </div>

      <!-- Header -->
      <div class="composer-header">
        <span class="composer-header-label">
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor"
               stroke-width="2" style="vertical-align:-.15em;margin-right:.35rem">
            <path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/>
            <polyline points="22,6 12,13 2,6"/>
          </svg>
          Selected Email
        </span>
        <span class="listing-chip">
          <span class="listing-chip-dot"></span>
          <span id="es-count-label"><?= $count ?> member<?= $count !== 1 ? 's' : '' ?> selected</span>
        </span>
      </div>

      <!-- To / From address block -->
      <div class="addr-block">
        <div class="addr-row" style="align-items:flex-start">
          <span class="addr-lbl" style="padding-top:.3rem">To</span>
          <div id="es-chips" class="es-chips">
            <?php foreach ($selected_emails as $email):
              $name = $member_names[$email] ?? '';
            ?>
              <span class="es-chip" data-email="<?= View::e($email) ?>">
                <span class="es-chip-text">
                  <?php if ($name !== ''): ?>
                    <strong style="font-weight:600"><?= View::e($name) ?></strong>
                    <span style="color:var(--text-muted);font-family:'DM Mono',monospace;font-size:.72rem">
                      &lt;<?= View::e($email) ?>&gt;
                    </span>
                  <?php else: ?>
                    <span style="font-family:'DM Mono',monospace;font-size:.78rem"><?= View::e($email) ?></span>
                  <?php endif; ?>
                </span>
                <button type="button" class="es-chip-remove"
                        aria-label="Remove <?= View::e($email) ?>"
                        title="Remove recipient">&times;</button>
              </span>
            <?php endforeach; ?>
          </div>
        </div>
        <div class="addr-row">
          <span class="addr-lbl">From</span>
          <?php if ($from_name !== '' && $from_email !== ''): ?>
            <div>
              <span class="addr-name"><?= View::e($from_name) ?></span>
              <span class="addr-email">&lt;<?= View::e($from_email) ?>&gt;</span>
            </div>
          <?php elseif ($from_email !== ''): ?>
            <span class="addr-email"><?= View::e($from_email) ?></span>
          <?php else: ?>
            <span style="color:var(--danger);font-size:.82rem">
              <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                   stroke-width="2" style="vertical-align:-.1em;margin-right:3px">
                <circle cx="12" cy="12" r="10"/>
                <line x1="12" y1="8" x2="12" y2="12"/>
                <line x1="12" y1="16" x2="12.01" y2="16"/>
              </svg>
              Not configured &mdash;
              <a href="<?= $b ?>/admin/emails?tab=smtp" class="link">check SMTP settings</a>
            </span>
          <?php endif; ?>
        </div>
      </div>

      <!-- Subject -->
      <div class="composer-section--inline">
        <label class="composer-section-label" for="es-subject">
          Subject <span class="req">*</span>
        </label>
        <input id="es-subject" type="text" name="subject" class="input"
               maxlength="255" required value="<?= $oldSubject ?>">
      </div>

      <!-- Body -->
      <div class="body-area">
        <label class="body-area-label" for="es-body">
          Message Body <span style="color:var(--danger)">*</span>
        </label>
        <textarea class="body-textarea" id="es-body" name="body"
                  maxlength="50000" required
                  style="font-family:'DM Mono',monospace;min-height:240px"><?= $oldBody ?></textarea>
        <span class="char-count" id="es-char-count">0 chars</span>
      </div>

      <!-- Footer -->
      <div class="composer-footer">
        <button type="button" class="btn btn-primary" id="es-send-btn">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <line x1="22" y1="2" x2="11" y2="13"/>
            <polygon points="22 2 15 22 11 13 2 9 22 2"/>
          </svg>
          Send to <span id="es-send-count"><?= $count ?></span>
          member<?= $count !== 1 ? 's' : '' ?> &rarr;
        </button>
        <button type="button" class="btn btn-ghost" id="es-preview-btn">Preview</button>
        <div style="flex:1"></div>
        <a href="<?= $b ?>/admin/members/<?= $listingId ?>" class="btn btn-ghost btn-sm">Cancel</a>
      </div>

    </form>
  </div><!-- /.composer-card -->

  <!-- ── Sidebar ────────────────────────────────────────────────────────── -->
  <div class="composer-side">

    <?php if (!empty($saved_emails)): ?>
    <div class="card side-card">
      <div class="side-card-head">
        <svg class="side-card-icon" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5">
          <rect x="2" y="3" width="12" height="10" rx="1.5"/><path d="M2 6.5h12"/>
        </svg>
        <span class="side-card-title">Load Template</span>
        <a href="<?= $b ?>/admin/emails?tab=custom"
           class="btn btn-ghost btn-sm"
           style="margin-left:auto;padding:.2rem .6rem;font-size:.72rem">Manage &rarr;</a>
      </div>
      <div class="side-card-body">
        <div class="select-wrap">
          <select id="es-tpl-select" class="select">
            <option value="">— Select a template —</option>
            <?php foreach ($saved_emails as $t): ?>
              <option value="<?= View::e($t->subject) ?>"
                      data-body="<?= htmlspecialchars($t->body, ENT_QUOTES, 'UTF-8') ?>">
                <?= View::e($t->name) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <p class="form-hint">Selecting fills the subject and body. You can edit before sending.</p>
      </div>
    </div>
    <?php endif; ?>

    <!-- Save as Template -->
    <div class="card side-card">
      <div class="side-card-head">
        <svg class="side-card-icon" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5">
          <path d="M3 14V3l3-1h6l3 3v9M6 14V9h4v5"/>
        </svg>
        <span class="side-card-title">Save as Template</span>
      </div>
      <div class="side-card-body">
        <form method="POST"
              action="<?= $b ?>/admin/members/<?= $listingId ?>/email-selected/save-template"
              id="es-save-tpl-form">
          <input type="hidden" name="_csrf"       value="<?= View::e($csrf_token) ?>">
          <input type="hidden" name="tpl_subject" id="es-save-subject">
          <input type="hidden" name="tpl_body"    id="es-save-body">
          <div id="es-save-emails"></div>
          <input type="text" name="tpl_name" id="es-save-name"
                 class="input" placeholder="Template name&hellip;" maxlength="150">
          <button type="submit" class="btn-save-tpl">
            <svg width="12" height="12" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.75">
              <path d="M3 14V3l3-1h6l3 3v9M6 14V9h4v5"/>
            </svg>
            Save template
          </button>
        </form>
      </div>
    </div>

    <!-- Available Tokens -->
    <details class="card side-card" open>
      <summary class="side-card-head">
        <svg class="side-card-icon" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5">
          <path d="M4 4h8M4 8h5M4 12h3"/>
        </svg>
        <span class="side-card-title">Available Tokens</span>
        <svg class="side-card-chevron" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="2">
          <polyline points="4 6 8 10 12 6"/>
        </svg>
      </summary>
      <div class="side-card-body">
        <table class="et-token-table">
          <thead>
            <tr><th>Token</th><th>Description</th></tr>
          </thead>
          <tbody>
            <tr class="tok-row" data-textarea="es-body" data-token="{name}"
                title="Click to insert"><td><code>{name}</code></td><td>Member's display name</td></tr>
            <tr class="tok-row" data-textarea="es-body" data-token="{email}"
                title="Click to insert"><td><code>{email}</code></td><td>Member's email address</td></tr>
            <tr class="tok-row" data-textarea="es-body" data-token="{listing_name}"
                title="Click to insert"><td><code>{listing_name}</code></td><td>Name of the listing</td></tr>
            <tr class="tok-row" data-textarea="es-body" data-token="{site_name}"
                title="Click to insert"><td><code>{site_name}</code></td><td>Collective or site name</td></tr>
          </tbody>
        </table>
      </div>
    </details>

  </div><!-- /.composer-side -->

</div><!-- /.composer-grid -->

<!-- ── Preview / Confirm modal ────────────────────────────────────────── -->
<div class="modal-backdrop" id="es-preview-modal" style="display:none">
  <div class="modal modal--wide">
    <div class="modal-header">
      <h3 class="modal-title" id="es-modal-title">Preview</h3>
      <button type="button" class="modal-close" id="es-modal-close" aria-label="Close">&times;</button>
    </div>
    <div class="modal-body">
      <div class="addr-block" style="margin:-.25rem -1.5rem .875rem;border-top:1px solid var(--border-soft)">
        <div class="addr-row">
          <span class="addr-lbl">To</span>
          <span class="addr-val" id="es-modal-to"></span>
        </div>
        <div class="addr-row">
          <span class="addr-lbl">From</span>
          <?php if ($from_name !== '' || $from_email !== ''): ?>
            <div>
              <?php if ($from_name !== ''): ?>
                <span class="addr-name"><?= View::e($from_name) ?></span>
              <?php endif; ?>
              <?php if ($from_email !== ''): ?>
                <span class="addr-email">
                  <?= $from_name !== '' ? '&lt;' : '' ?><?= View::e($from_email) ?><?= $from_name !== '' ? '&gt;' : '' ?>
                </span>
              <?php endif; ?>
            </div>
          <?php else: ?>
            <span style="color:var(--danger);font-size:.82rem;font-style:italic">Not configured</span>
          <?php endif; ?>
        </div>
        <div class="addr-row">
          <span class="addr-lbl">Subject</span>
          <span class="addr-val" id="es-modal-subject" style="color:var(--text)"></span>
        </div>
      </div>
      <div style="margin-bottom:.5rem;font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--text-dim)">
        Message
        <span style="font-weight:400;text-transform:none;letter-spacing:0;color:var(--text-dim)">
          (first recipient &mdash; tokens replaced with sample data)
        </span>
      </div>
      <pre id="es-modal-body"
           style="white-space:pre-wrap;font-size:.875rem;color:var(--text);font-family:'DM Mono',monospace;background:var(--bg-subtle);border:1px solid var(--border);border-radius:8px;padding:.75rem 1rem;line-height:1.65;margin:0;max-height:260px;overflow-y:auto"></pre>
    </div>
    <!-- Confirm warning (shown only in confirm mode) -->
    <div class="me-confirm-warning" id="es-confirm-warning" style="display:none">
      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
        <path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/>
        <rect x="11" y="8.5" width="2" height="5.5" rx="1" fill="currentColor" stroke="none"/>
        <circle cx="12" cy="16.5" r="1.1" fill="currentColor" stroke="none"/>
      </svg>
      This will send to all listed recipients. This action cannot be undone.
    </div>
    <!-- Footer: preview mode -->
    <div class="modal-footer" id="es-footer-preview">
      <button type="button" class="btn btn-ghost" id="es-modal-close-preview">Close</button>
    </div>
    <!-- Footer: confirm mode -->
    <div id="es-footer-confirm" style="display:none">
      <div class="modal-footer">
        <button type="button" class="btn btn-ghost" id="es-modal-cancel">Cancel</button>
        <button type="button" class="btn btn-primary" id="es-modal-send">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <line x1="22" y1="2" x2="11" y2="13"/>
            <polygon points="22 2 15 22 11 13 2 9 22 2"/>
          </svg>
          Confirm &amp; Send
        </button>
      </div>
    </div>
  </div>
</div>

<!-- Preview sample data -->
<div id="es-preview-data"
     data-first-name="<?= View::e(reset($member_names) ?: '') ?>"
     data-first-email="<?= View::e(array_key_first($member_names) ?? '') ?>"
     data-listing-name="<?= View::e($listing->name ?? '') ?>"
     data-site-name="<?= View::e($site_name ?? '') ?>"
     style="display:none"></div>

<script>
(function () {
  var form        = document.getElementById('es-form');
  var chips       = document.getElementById('es-chips');
  var emailInputs = document.getElementById('es-email-inputs');
  var subjectEl   = document.getElementById('es-subject');
  var bodyEl      = document.getElementById('es-body');
  var charCount   = document.getElementById('es-char-count');
  var sendBtn     = document.getElementById('es-send-btn');
  var sendCount   = document.getElementById('es-send-count');
  var countLabel  = document.getElementById('es-count-label');
  var modal       = document.getElementById('es-preview-modal');
  var previewData = document.getElementById('es-preview-data');

  // ── Char counter ──────────────────────────────────────────────────────────
  function updateCharCount() {
    var n = subjectEl.value.length + bodyEl.value.length;
    charCount.textContent = n.toLocaleString() + ' char' + (n !== 1 ? 's' : '');
  }
  subjectEl.addEventListener('input', updateCharCount);
  bodyEl.addEventListener('input', updateCharCount);
  updateCharCount();

  // ── Recipient chips ───────────────────────────────────────────────────────
  function getRecipientCount() {
    return chips.querySelectorAll('.es-chip:not(.es-chip-removed)').length;
  }

  function syncRecipientCount() {
    var n = getRecipientCount();
    if (sendCount)  sendCount.textContent  = n;
    if (countLabel) countLabel.textContent = n + ' member' + (n !== 1 ? 's' : '') + ' selected';
    if (sendBtn)    sendBtn.disabled       = (n === 0);
  }

  chips.addEventListener('click', function (e) {
    var btn = e.target.closest('.es-chip-remove');
    if (!btn) return;
    var chip  = btn.closest('.es-chip');
    var email = chip.dataset.email;
    chip.classList.add('es-chip-removed');
    chip.style.display = 'none';
    emailInputs.querySelectorAll('input[name="emails[]"]').forEach(function (inp) {
      if (inp.value === email) inp.remove();
    });
    syncRecipientCount();
  });

  // ── Template selector ─────────────────────────────────────────────────────
  var tplSelect = document.getElementById('es-tpl-select');
  if (tplSelect) {
    tplSelect.addEventListener('change', function () {
      var opt = tplSelect.options[tplSelect.selectedIndex];
      if (!opt || !opt.value) return;
      subjectEl.value = opt.value;
      bodyEl.value    = opt.dataset.body || '';
      updateCharCount();
      tplSelect.value = '';
    });
  }

  // ── Save-as-template form ─────────────────────────────────────────────────
  var saveTplForm = document.getElementById('es-save-tpl-form');
  var saveEmails  = document.getElementById('es-save-emails');
  saveTplForm.addEventListener('submit', function () {
    document.getElementById('es-save-subject').value = subjectEl.value;
    document.getElementById('es-save-body').value    = bodyEl.value;
    saveEmails.innerHTML = '';
    emailInputs.querySelectorAll('input[name="emails[]"]').forEach(function (inp) {
      var copy   = document.createElement('input');
      copy.type  = 'hidden';
      copy.name  = 'emails[]';
      copy.value = inp.value;
      saveEmails.appendChild(copy);
    });
  });

  // ── Preview / Confirm modal ───────────────────────────────────────────────
  var sampleName    = previewData.dataset.firstName   || 'Alex';
  var sampleEmail   = previewData.dataset.firstEmail  || 'member@example.com';
  var sampleListing = previewData.dataset.listingName || '';
  var sampleSite    = previewData.dataset.siteName    || '';

  function substituteTokens(str) {
    return str
      .replace(/\{name\}/g,         sampleName)
      .replace(/\{email\}/g,        sampleEmail)
      .replace(/\{listing_name\}/g, sampleListing)
      .replace(/\{site_name\}/g,    sampleSite);
  }

  function setModalMode(mode) {
    var isConfirm = (mode === 'confirm');
    document.getElementById('es-modal-title').textContent     = isConfirm ? 'Preview & Confirm' : 'Preview';
    document.getElementById('es-confirm-warning').style.display = isConfirm ? 'flex'  : 'none';
    document.getElementById('es-footer-preview').style.display  = isConfirm ? 'none'  : 'flex';
    document.getElementById('es-footer-confirm').style.display  = isConfirm ? 'block' : 'none';
  }

  function openModal(mode) {
    var n = getRecipientCount();
    document.getElementById('es-modal-to').textContent =
      n + ' member' + (n !== 1 ? 's' : '') + ' selected';
    document.getElementById('es-modal-subject').textContent =
      substituteTokens(subjectEl.value) || '(no subject)';
    document.getElementById('es-modal-body').textContent =
      substituteTokens(bodyEl.value) || '(no message)';
    setModalMode(mode);
    modal.style.display = 'flex';
  }

  function closeModal() {
    modal.style.display = 'none';
  }

  // "Send →" requires valid form, then opens confirm mode
  sendBtn.addEventListener('click', function () {
    if (!form.reportValidity()) return;
    openModal('confirm');
  });

  // "Preview" opens preview-only mode
  document.getElementById('es-preview-btn').addEventListener('click', function () {
    openModal('preview');
  });

  document.getElementById('es-modal-close').addEventListener('click', closeModal);
  document.getElementById('es-modal-close-preview').addEventListener('click', closeModal);
  document.getElementById('es-modal-cancel').addEventListener('click', closeModal);
  modal.addEventListener('click', function (e) { if (e.target === modal) closeModal(); });

  document.getElementById('es-modal-send').addEventListener('click', function () {
    form.submit();
  });
}());
</script>
