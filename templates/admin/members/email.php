<?php declare(strict_types=1);
use Luster\Core\View;
$b          = rtrim(View::e($base_url ?? ''), '/');
$flName     = View::e($listing->name ?? '');
$oldSubject = View::e($old['subject'] ?? '');
$oldBody    = htmlspecialchars($old['body'] ?? '', ENT_QUOTES, 'UTF-8');
$listingId  = (int) ($listing->id ?? 0);
?>

<?php if (($error ?? '') !== ''): ?>
  <div class="alert alert-error"><?= View::e($error ?? '') ?></div>
<?php endif; ?>
<?php if (($success ?? '') !== ''): ?>
  <div class="alert alert-success"><?= View::e($success ?? '') ?></div>
<?php endif; ?>

<div class="composer-grid">

  <!-- ── Sidebar ── -->
  <div class="composer-side">

    <?php if (!empty($saved_emails)): ?>
    <div class="card side-card">
      <div class="side-card-head">
        <svg class="side-card-icon" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5">
          <rect x="2" y="3" width="12" height="10" rx="1.5"/><path d="M2 6.5h12"/>
        </svg>
        <span class="side-card-title">Load Template</span>
      </div>
      <div class="side-card-body">
        <div class="select-wrap">
          <select id="em-template-select" class="select">
            <option value="">— Select a template —</option>
            <?php foreach ($saved_emails as $t): ?>
              <option value="<?= View::e($t->subject) ?>"
                      data-body="<?= htmlspecialchars($t->body, ENT_QUOTES, 'UTF-8') ?>">
                <?= View::e($t->name) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <p class="form-hint">Loading a template will replace your current subject and message.</p>
      </div>
    </div>
    <?php endif; ?>

    <div class="card side-card">
      <div class="side-card-head">
        <svg class="side-card-icon" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5">
          <path d="M3 14V3l3-1h6l3 3v9M6 14V9h4v5"/>
        </svg>
        <span class="side-card-title">Save as Template</span>
      </div>
      <div class="side-card-body">
        <form method="POST" action="<?= $b ?>/admin/new-email-template/save" id="em-save-tpl-form">
          <input type="hidden" name="_csrf"       value="<?= View::e($csrf_token ?? '') ?>">
          <input type="hidden" name="referer"     id="em-save-referer" value="<?= View::e($_SERVER['REQUEST_URI'] ?? '') ?>">
          <input type="hidden" name="tpl_subject" id="em-save-subject">
          <input type="hidden" name="tpl_body"    id="em-save-body">
          <input type="text"   name="tpl_name"    id="em-save-name"
                 class="input" placeholder="Template name&hellip;" maxlength="150">
          <button type="submit" class="btn-save-tpl" id="em-save-btn">
            <svg width="12" height="12" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.75">
              <path d="M3 14V3l3-1h6l3 3v9M6 14V9h4v5"/>
            </svg>
            Save template
          </button>
        </form>
      </div>
    </div>

    <details class="card side-card">
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
            <tr class="tok-row" data-textarea="body" data-token="{fan_name}"       title="Click to insert"><td><code>{fan_name}</code></td><td>Fan's display name</td></tr>
            <tr class="tok-row" data-textarea="body" data-token="{fan_email}"      title="Click to insert"><td><code>{fan_email}</code></td><td>Fan's email address</td></tr>
            <tr class="tok-row" data-textarea="body" data-token="{fan_url}"        title="Click to insert"><td><code>{fan_url}</code></td><td>Fan's website URL</td></tr>
            <tr class="tok-row" data-textarea="body" data-token="{fan_country}"    title="Click to insert"><td><code>{fan_country}</code></td><td>Fan's country</td></tr>
            <tr class="tok-row" data-textarea="body" data-token="{fan_password}"   title="Click to insert"><td><code>{fan_password}</code></td><td>Plain-text password (sign-up only)</td></tr>
            <tr class="tok-row" data-textarea="body" data-token="{listing_name}"   title="Click to insert"><td><code>{listing_name}</code></td><td>Name of the listing</td></tr>
            <tr class="tok-row" data-textarea="body" data-token="{listing_subject}" title="Click to insert"><td><code>{listing_subject}</code></td><td>Subject or topic</td></tr>
            <tr class="tok-row" data-textarea="body" data-token="{listing_url}"    title="Click to insert"><td><code>{listing_url}</code></td><td>URL of the listing</td></tr>
            <tr class="tok-row" data-textarea="body" data-token="{site_name}"      title="Click to insert"><td><code>{site_name}</code></td><td>Collective or site name</td></tr>
            <tr class="tok-row" data-textarea="body" data-token="{owner_name}"     title="Click to insert"><td><code>{owner_name}</code></td><td>Owner's display name</td></tr>
            <tr class="tok-row" data-textarea="body" data-token="{owner_email}"    title="Click to insert"><td><code>{owner_email}</code></td><td>Owner's email address</td></tr>
          </tbody>
        </table>
      </div>
    </details>

  </div><!-- /.composer-side -->

  <!-- ── Main composer ── -->
  <div class="card composer-card">
    <form method="POST" id="email-form">
      <input type="hidden" name="_csrf"        value="<?= View::e($csrf_token ?? '') ?>">
      <input type="hidden" name="member_email" value="<?= View::e($member_email ?? '') ?>">

      <div class="composer-header">
        <span class="composer-header-label">New Message</span>
        <span class="listing-chip">
          <span class="listing-chip-dot"></span><?= $flName ?>
        </span>
      </div>

      <div class="addr-block">
        <div class="addr-row">
          <span class="addr-lbl">From</span>
          <div>
            <?php if (($from_name ?? '') !== ''): ?>
              <span class="addr-name"><?= View::e($from_name) ?></span>
            <?php endif; ?>
            <span class="addr-email">&lt;<?= View::e($from_email ?? '') ?>&gt;</span>
          </div>
        </div>
        <div class="addr-row">
          <span class="addr-lbl">To</span>
          <div>
            <span class="addr-name"><?= View::e($member_name ?? '') ?></span><span class="addr-email">&lt;<?= View::e($member_email ?? '') ?>&gt;</span>
          </div>
        </div>
      </div>

      <div class="subj-row">
        <span class="subj-prefix"><?= $flName ?>:</span>
        <input class="subj-input" type="text" name="subject_suffix" id="subject"
               value="<?= $oldSubject ?>" placeholder="Enter subject&hellip;">
      </div>

      <div class="body-area">
        <textarea class="body-textarea" name="body" id="body"
                  placeholder="Write your message&hellip;"><?= $oldBody ?></textarea>
        <span class="char-count" id="em-char-count">0 chars</span>
      </div>

      <div class="composer-footer">
        <button type="button" class="btn btn-primary" id="em-send-btn">
          <svg width="14" height="14" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.75">
            <path d="M14 2L7 9M14 2L9.5 14 7 9 2 6.5 14 2z"/>
          </svg>
          Send &rarr;
        </button>
        <button type="button" class="btn btn-ghost" id="preview-email-btn">Preview</button>
        <button type="button" class="btn btn-ghost js-form-reset">Clear</button>
        <div style="flex:1"></div>
        <a href="<?= View::e($referer ?? $b . '/admin/members/' . $listingId) ?>"
           class="btn btn-ghost btn-sm">Cancel</a>
      </div>
    </form>
  </div><!-- /.composer-card -->

</div><!-- /.composer-grid -->

<!-- Preview modal -->
<div class="modal-backdrop" id="preview-modal" style="display:none"
     data-listing-name="<?= $flName ?>"
     data-listing-subject="<?= View::e($listing->subject ?? '') ?>"
     data-listing-url="<?= View::e($listing->url ?? '') ?>"
     data-fan-name="<?= View::e($member_name ?? '') ?>"
     data-fan-email="<?= View::e($member_email ?? '') ?>"
     data-fan-url="<?= View::e($member_url ?? '') ?>"
     data-fan-country="<?= View::e($member_country ?? '') ?>"
     data-site-name="<?= View::e($site_name ?? '') ?>"
     data-owner-name="<?= View::e($from_name ?? '') ?>"
     data-owner-email="<?= View::e($from_email ?? '') ?>">
  <div class="modal modal--wide">
    <div class="modal-header">
      <h3 class="modal-title">Email Preview</h3>
    </div>
    <div class="modal-body">
      <div class="addr-block" style="margin:-.25rem -1.5rem .75rem;border-top:1px solid var(--border-soft)">
        <div class="addr-row">
          <span class="addr-lbl">From</span>
          <div>
            <span class="addr-name" id="em-preview-from-name"></span>
            <span class="addr-email" id="em-preview-from-email"></span>
          </div>
        </div>
        <div class="addr-row">
          <span class="addr-lbl">To</span>
          <div>
            <span class="addr-name" id="em-preview-to-name"></span>
            <span class="addr-email" id="em-preview-to-email"></span>
          </div>
        </div>
        <div class="addr-row">
          <span class="addr-lbl">Subject</span>
          <span class="addr-val" id="preview-subject"></span>
        </div>
      </div>
      <div id="preview-body" style="white-space:pre-wrap;font-size:.875rem;color:var(--text)"></div>
    </div>
    <div class="modal-footer" id="em-footer-preview">
      <button class="btn btn-ghost" id="close-preview-btn">Close</button>
    </div>
    <div id="em-footer-confirm" style="display:none">
      <div class="me-confirm-warning">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><rect x="11" y="8.5" width="2" height="5.5" rx="1" fill="currentColor" stroke="none"/><circle cx="12" cy="16.5" r="1.1" fill="currentColor" stroke="none"/></svg>
        Are you sure you want to send this email? This action cannot be undone.
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-ghost" id="em-confirm-cancel-btn">Cancel</button>
        <button type="button" class="btn btn-primary" id="em-confirm-send-btn">Send &rarr;</button>
      </div>
    </div>
  </div>
</div>
