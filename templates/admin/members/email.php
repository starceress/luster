<?php declare(strict_types=1);
$b          = rtrim(\Luster\Core\View::e($base_url ?? ''), '/');
$flName     = \Luster\Core\View::e($listing->name ?? '');
$oldSubject = \Luster\Core\View::e($old['subject'] ?? '');
$oldBody    = htmlspecialchars($old['body'] ?? '', ENT_QUOTES, 'UTF-8');
$listingId  = (int) ($listing->id ?? 0);
?>

<?php if (($error ?? '') !== ''): ?>
  <div class="alert alert-error"><?= \Luster\Core\View::e($error ?? '') ?></div>
<?php endif; ?>
<?php if (($success ?? '') !== ''): ?>
  <div class="alert alert-success"><?= \Luster\Core\View::e($success ?? '') ?></div>
<?php endif; ?>

<div class="email-card">
  <div class="email-header">Email Member &mdash; <?= $flName ?></div>
  <form method="POST" id="email-form">
    <input type="hidden" name="_csrf"        value="<?= \Luster\Core\View::e($csrf_token ?? '') ?>">
    <input type="hidden" name="member_email" value="<?= \Luster\Core\View::e($member_email ?? '') ?>">

    <div class="email-row">
      <div class="email-label">From</div>
      <div class="email-value">&ldquo;<?= \Luster\Core\View::e($from_name ?? '') ?>&rdquo; &lt;<?= \Luster\Core\View::e($from_email ?? '') ?>&gt;</div>
    </div>

    <div class="email-row">
      <div class="email-label">To</div>
      <div class="email-value"><?= \Luster\Core\View::e($member_name ?? '') ?> &lt;<?= \Luster\Core\View::e($member_email ?? '') ?>&gt;</div>
    </div>

    <div class="email-row">
      <div class="email-label">Subject</div>
      <div class="email-value">
        <span style="font-weight:500;margin-right:.4rem;color:var(--text-muted)"><?= $flName ?>:</span>
        <input type="text" name="subject_suffix" id="subject"
               value="<?= $oldSubject ?>"
               style="width:calc(100% - 160px)">
      </div>
    </div>

    <div class="email-row">
      <div class="email-label">Message</div>
      <div class="email-value">
        <textarea name="body" id="body"><?= $oldBody ?></textarea>
      </div>
    </div>

    <?php if (!empty($saved_emails)): ?>
    <div class="email-row">
      <div class="email-label">Load template</div>
      <div class="email-value">
        <div class="select-wrap">
        <select id="em-template-select">
          <option value="">— Select a saved template —</option>
          <?php foreach ($saved_emails as $t): ?>
            <option value="<?= \Luster\Core\View::e($t->subject) ?>"
                    data-body="<?= htmlspecialchars($t->body, ENT_QUOTES, 'UTF-8') ?>">
              <?= \Luster\Core\View::e($t->name) ?>
            </option>
          <?php endforeach; ?>
        </select>
        </div>
        <p class="form-hint" style="margin-top:.35rem">Selecting a template will overwrite the subject and message fields.</p>
      </div>
    </div>
    <?php endif; ?>

    <div class="email-btn-row">
      <button type="submit" class="btn btn-primary">Send</button>
      <button type="button" class="btn btn-ghost" id="preview-email-btn">Preview</button>
      <button type="button" class="btn btn-ghost js-form-reset">Clear</button>
      <a href="<?= \Luster\Core\View::e($referer ?? $b . '/admin/members/' . $listingId) ?>"
         class="btn btn-ghost">Cancel</a>
    </div>
  </form>

  <!-- Save as template -->
  <div style="margin-top:1.5rem;padding-top:1.25rem;border-top:1px solid var(--border)">
    <p style="margin:0 0 .75rem;font-size:.85rem;color:var(--text-muted)">Save the current subject &amp; message as a reusable template:</p>
    <form method="POST" action="<?= $b ?>/admin/new-email-template/save" class="save-tpl-row" id="em-save-tpl-form">
      <input type="hidden" name="_csrf"       value="<?= \Luster\Core\View::e($csrf_token ?? '') ?>">
      <input type="hidden" name="referer"     id="em-save-referer" value="<?= \Luster\Core\View::e($_SERVER['REQUEST_URI'] ?? '') ?>">
      <input type="hidden" name="tpl_subject" id="em-save-subject">
      <input type="hidden" name="tpl_body"    id="em-save-body">
      <input type="text"   name="tpl_name"    id="em-save-name"
             class="neb-input" placeholder="Template name&hellip;" style="flex:1;min-width:160px" maxlength="150">
      <button type="submit" class="btn btn-secondary btn-sm" id="em-save-btn">Save template</button>
    </form>
  </div>
</div>


<!-- Preview modal -->
<div class="modal-overlay" id="preview-modal" data-listing-name="<?= \Luster\Core\View::e($flName) ?>">
  <div class="modal-box">
    <h3>Email Preview</h3>
    <p><strong>To:</strong> <?= \Luster\Core\View::e($member_name ?? '') ?> &lt;<?= \Luster\Core\View::e($member_email ?? '') ?>&gt;</p>
    <p><strong>Subject:</strong> <span id="preview-subject"></span></p>
    <hr>
    <div id="preview-body" style="white-space:pre-wrap;font-size:.875rem;color:var(--text)"></div>
    <br>
    <button class="btn btn-ghost" id="close-preview-btn">Close</button>
  </div>
</div>

