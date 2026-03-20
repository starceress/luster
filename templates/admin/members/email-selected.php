<?php declare(strict_types=1);
use Luster\Core\View;

/**
 * @var \Luster\Models\Listing  $listing
 * @var string[]                $selected_emails
 * @var array<string,string>    $member_names      email → name
 * @var object[]                $saved_emails
 * @var string                  $from_name
 * @var string                  $from_email
 * @var string                  $error
 * @var string                  $success
 * @var array                   $old               ['subject'=>..., 'body'=>...]
 * @var string                  $base_url
 * @var string                  $csrf_token
 */

$b         = rtrim(View::e($base_url), '/');
$listingId = (int) ($listing->id ?? 0);
$flName    = View::e($listing->name ?? '');
$oldSubject = $old['subject'] ?? '';
$oldBody    = $old['body']    ?? '';
?>

<?php if (($error ?? '') !== ''): ?>
  <div class="alert alert-error"><?= View::e($error) ?></div>
<?php endif; ?>
<?php if (($success ?? '') !== ''): ?>
  <div class="alert alert-success"><?= View::e($success) ?></div>
<?php endif; ?>

<p style="margin-bottom:1rem">
  <a href="<?= $b ?>/admin/members/<?= $listingId ?>" class="neb-link">&larr; Back to Members</a>
</p>

<!-- Main compose card -->
<div class="neb-card" style="overflow:hidden">

  <!-- ── To / From header ─────────────────────────────────────────────── -->
  <div style="padding:1.1rem 1.5rem;border-bottom:1px solid var(--border);display:flex;flex-direction:column;gap:.75rem">

    <!-- To line -->
    <div class="es-header-row">
      <span class="es-header-label">To</span>
      <div id="es-chips" class="es-chips">
        <?php foreach ($selected_emails as $email):
          $name = $member_names[$email] ?? '';
          $displayText = $name !== '' ? $name . ' <' . $email . '>' : $email;
        ?>
          <span class="es-chip" data-email="<?= View::e($email) ?>">
            <span class="es-chip-text"><?= View::e($displayText) ?></span>
            <button type="button" class="es-chip-remove" aria-label="Remove <?= View::e($email) ?>"
                    title="Remove from recipients">&times;</button>
          </span>
        <?php endforeach; ?>
      </div>
    </div>

    <!-- From line -->
    <div class="es-header-row">
      <span class="es-header-label">From</span>
      <span class="es-from-value">
        <?php if ($from_name !== '' && $from_email !== ''): ?>
          <?= View::e($from_name) ?> &lt;<?= View::e($from_email) ?>&gt;
        <?php elseif ($from_email !== ''): ?>
          <?= View::e($from_email) ?>
        <?php else: ?>
          <em style="color:var(--text-muted)">Not configured — check SMTP settings</em>
        <?php endif; ?>
      </span>
    </div>
  </div>

  <!-- ── Compose form ──────────────────────────────────────────────────── -->
  <div style="padding:1.5rem">

    <!-- Template loader -->
    <?php if (!empty($saved_emails)): ?>
    <div class="form-group" style="margin-bottom:1.25rem;padding-bottom:1.25rem;border-bottom:1px solid var(--border)">
      <label class="form-label">Load saved template</label>
      <div style="display:flex;align-items:center;gap:.75rem;flex-wrap:wrap">
        <div class="select-wrap" style="flex:1;min-width:200px">
          <select id="em-template-select">
            <option value="">— Select a template —</option>
            <?php foreach ($saved_emails as $t): ?>
              <option value="<?= View::e($t->subject) ?>"
                      data-body="<?= htmlspecialchars($t->body, ENT_QUOTES, 'UTF-8') ?>">
                <?= View::e($t->name) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
      <p class="form-hint" style="margin-top:.3rem">Selecting fills the subject and message fields. You can edit before sending.</p>
    </div>
    <?php endif; ?>

    <!-- Send form -->
    <form method="POST" action="<?= $b ?>/admin/members/<?= $listingId ?>/email-selected/send" id="email-form">
      <input type="hidden" name="_csrf" value="<?= View::e($csrf_token) ?>">
      <!-- Hidden email inputs — managed by chip JS; initially populated from server -->
      <div id="es-email-inputs">
        <?php foreach ($selected_emails as $email): ?>
          <input type="hidden" name="emails[]" value="<?= View::e($email) ?>">
        <?php endforeach; ?>
      </div>

      <div class="form-group">
        <label class="form-label" for="subject">Subject <span style="color:var(--accent)">*</span></label>
        <input id="subject" type="text" name="subject" class="form-input"
               maxlength="255" required value="<?= View::e($oldSubject) ?>">
      </div>

      <div class="form-group">
        <label class="form-label" for="body">Message <span style="color:var(--accent)">*</span></label>
        <textarea id="body" name="body" class="form-textarea"
                  rows="12" maxlength="50000" required
                  style="font-family:'DM Mono',monospace;font-size:.82rem"><?= htmlspecialchars($oldBody, ENT_QUOTES, 'UTF-8') ?></textarea>
      </div>

      <!-- Token table -->
      <div style="margin-bottom:1.25rem">
        <p style="font-size:.72rem;font-weight:600;color:var(--text-muted);text-transform:uppercase;letter-spacing:.04em;margin:0 0 .4rem">Available Tokens</p>
        <table class="et-token-table">
          <thead><tr><th>Token</th><th>Description</th></tr></thead>
          <tbody>
            <tr><td><code>{name}</code></td><td>Member's display name</td></tr>
            <tr><td><code>{email}</code></td><td>Member's email address</td></tr>
            <tr><td><code>{listing_name}</code></td><td>Name of the fanlisting</td></tr>
            <tr><td><code>{site_name}</code></td><td>Collective or site name</td></tr>
          </tbody>
        </table>
      </div>

      <!-- Preview panel (hidden until toggled) -->
      <div id="es-preview-panel"
           style="display:none;margin-bottom:1.25rem;border:1px solid var(--border);border-radius:6px;overflow:hidden">
        <div style="padding:.45rem .875rem;background:var(--surface-alt,var(--sidebar));border-bottom:1px solid var(--border);font-size:.72rem;font-weight:600;color:var(--text-muted);text-transform:uppercase;letter-spacing:.04em">
          Preview <span style="font-weight:400;text-transform:none;letter-spacing:0">(first recipient, example tokens substituted)</span>
        </div>
        <div style="padding:.875rem 1rem">
          <div style="font-size:.8rem;font-weight:600;color:var(--text-muted);margin-bottom:.3rem">Subject</div>
          <div id="es-preview-subject" style="font-family:'DM Mono',monospace;font-size:.82rem;color:var(--text);padding:.4rem .6rem;background:var(--surface-alt,var(--sidebar));border-radius:4px;margin-bottom:.875rem"></div>
          <div style="font-size:.8rem;font-weight:600;color:var(--text-muted);margin-bottom:.3rem">Body</div>
          <pre id="es-preview-body" style="font-family:'DM Mono',monospace;font-size:.8rem;color:var(--text);white-space:pre-wrap;word-break:break-word;margin:0;padding:.6rem;background:var(--surface-alt,var(--sidebar));border-radius:4px;line-height:1.6"></pre>
        </div>
      </div>

      <!-- Action row -->
      <div class="btn-row">
        <button type="button" id="es-preview-btn" class="btn btn-ghost">Preview</button>
        <button type="submit" id="es-send-btn" class="btn btn-primary">
          Send to <?= count($selected_emails) ?> member<?= count($selected_emails) !== 1 ? 's' : '' ?> &rarr;
        </button>
        <a href="<?= $b ?>/admin/members/<?= $listingId ?>" class="btn btn-ghost">Cancel</a>
      </div>
    </form>

    <!-- Save as template -->
    <div style="margin-top:1.5rem;padding-top:1.25rem;border-top:1px solid var(--border)">
      <p style="margin:0 0 .65rem;font-size:.85rem;color:var(--text-muted)">Save the current subject &amp; message as a reusable template:</p>
      <form method="POST" action="<?= $b ?>/admin/members/<?= $listingId ?>/email-selected/save-template"
            id="es-save-tpl-form"
            style="display:flex;align-items:center;gap:.5rem;flex-wrap:wrap">
        <input type="hidden" name="_csrf"       value="<?= View::e($csrf_token) ?>">
        <input type="hidden" name="tpl_subject" id="es-save-subject">
        <input type="hidden" name="tpl_body"    id="es-save-body">
        <!-- Email inputs copied here by JS before submit so recipients survive the redirect -->
        <div id="es-save-emails"></div>
        <input type="text"   name="tpl_name"
               class="neb-input" placeholder="Template name&hellip;" style="flex:1;min-width:160px" maxlength="150">
        <button type="submit" class="btn btn-secondary btn-sm">Save template</button>
      </form>
    </div>

  </div><!-- /padding -->
</div><!-- /neb-card -->

<!-- Preview data for JS (first recipient) -->
<div id="es-preview-data"
     data-first-name="<?= View::e(reset($member_names) ?: '') ?>"
     data-first-email="<?= View::e(array_key_first($member_names) ?? '') ?>"
     data-listing-name="<?= View::e($listing->name ?? '') ?>"
     data-site-name="<?= View::e($site_name ?? '') ?>"
     style="display:none"></div>

<script>
(function () {
  // ── Chip removal ───────────────────────────────────────────────────────
  var chips      = document.getElementById('es-chips');
  var emailInputs = document.getElementById('es-email-inputs');
  var sendBtn    = document.getElementById('es-send-btn');

  function updateSendBtn() {
    var remaining = chips.querySelectorAll('.es-chip:not(.es-chip-removed)').length;
    sendBtn.textContent = 'Send to ' + remaining + ' member' + (remaining !== 1 ? 's' : '') + ' \u2192';
    sendBtn.disabled = (remaining === 0);
  }

  chips.addEventListener('click', function (e) {
    var removeBtn = e.target.closest('.es-chip-remove');
    if (!removeBtn) return;
    var chip  = removeBtn.closest('.es-chip');
    var email = chip.dataset.email;

    // Visually remove chip
    chip.classList.add('es-chip-removed');
    chip.style.display = 'none';

    // Remove matching hidden input from send form
    emailInputs.querySelectorAll('input[name="emails[]"]').forEach(function (inp) {
      if (inp.value === email) inp.remove();
    });

    updateSendBtn();
  });

  // ── Save-template form: copy current recipients + fill fields ─────────
  var saveTplForm    = document.getElementById('es-save-tpl-form');
  var saveEmailsDiv  = document.getElementById('es-save-emails');
  var saveTplSubject = document.getElementById('es-save-subject');
  var saveTplBody    = document.getElementById('es-save-body');
  var subjectInput   = document.getElementById('subject');
  var bodyTextarea   = document.getElementById('body');

  saveTplForm.addEventListener('submit', function () {
    // Fill subject & body from current compose state
    saveTplSubject.value = subjectInput.value;
    saveTplBody.value    = bodyTextarea.value;

    // Copy remaining email hidden inputs so controller can restore session
    saveEmailsDiv.innerHTML = '';
    emailInputs.querySelectorAll('input[name="emails[]"]').forEach(function (inp) {
      var copy = document.createElement('input');
      copy.type  = 'hidden';
      copy.name  = 'emails[]';
      copy.value = inp.value;
      saveEmailsDiv.appendChild(copy);
    });
  });

  // ── Preview ────────────────────────────────────────────────────────────
  var previewBtn   = document.getElementById('es-preview-btn');
  var previewPanel = document.getElementById('es-preview-panel');
  var previewSubEl = document.getElementById('es-preview-subject');
  var previewBdyEl = document.getElementById('es-preview-body');
  var previewData  = document.getElementById('es-preview-data');

  var sampleName    = previewData.dataset.firstName  || 'Alex';
  var sampleEmail   = previewData.dataset.firstEmail || 'member@example.com';
  var sampleListing = previewData.dataset.listingName || '<?= View::e(addslashes($listing->name ?? '')) ?>';
  var sampleSite    = previewData.dataset.siteName    || '<?= View::e(addslashes($site_name ?? 'My Site')) ?>';

  function substituteTokens(str) {
    return str
      .replace(/\{name\}/g,         sampleName)
      .replace(/\{email\}/g,        sampleEmail)
      .replace(/\{listing_name\}/g, sampleListing)
      .replace(/\{site_name\}/g,    sampleSite);
  }

  previewBtn.addEventListener('click', function () {
    var visible = previewPanel.style.display !== 'none';
    if (visible) {
      previewPanel.style.display = 'none';
      previewBtn.textContent = 'Preview';
    } else {
      previewSubEl.textContent = substituteTokens(subjectInput.value) || '(no subject)';
      previewBdyEl.textContent = substituteTokens(bodyTextarea.value) || '(no message)';
      previewPanel.style.display = 'block';
      previewBtn.textContent = 'Hide Preview';
    }
  });

  // Keep preview in sync while visible
  [subjectInput, bodyTextarea].forEach(function (el) {
    el.addEventListener('input', function () {
      if (previewPanel.style.display === 'none') return;
      previewSubEl.textContent = substituteTokens(subjectInput.value) || '(no subject)';
      previewBdyEl.textContent = substituteTokens(bodyTextarea.value) || '(no message)';
    });
  });

  // ── Template selector (reuse section-29 JS target IDs) ───────────────
  // #em-template-select → fills #subject and #body (handled by admin.js section 29)
}());
</script>
