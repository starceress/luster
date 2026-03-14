<?php declare(strict_types=1);
$b         = rtrim(\Luster\Core\View::e($base_url ?? ''), '/');
$flName    = \Luster\Core\View::e($listing->name ?? '');
$oldSubject = \Luster\Core\View::e($old['subject'] ?? '');
$oldBody   = htmlspecialchars($old['body'] ?? '', ENT_QUOTES, 'UTF-8');
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

    <div class="email-row">
      <div class="email-label">Use Template?</div>
      <div class="email-value">
        <div class="select-wrap">
        <select id="template-select">
          <option value="">No, write manually</option>
          <?php foreach ($templates ?? [] as $t): ?>
            <option value="<?= \Luster\Core\View::e($t['subject'] ?? '') ?>"
                    data-body="<?= htmlspecialchars($t['content'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
              <?= \Luster\Core\View::e($t['templatename'] ?? '') ?>
            </option>
          <?php endforeach; ?>
        </select>
        </div>
      </div>
    </div>

    <div class="email-btn-row">
      <button type="submit" class="btn btn-primary">Send</button>
      <button type="button" class="btn btn-ghost" onclick="previewEmail()">Preview</button>
      <button type="button" class="btn btn-ghost" onclick="document.getElementById('email-form').reset()">Clear</button>
      <a href="<?= \Luster\Core\View::e($referer ?? $b . '/admin/members/' . (int) ($listing->id ?? 0)) ?>"
         class="btn btn-ghost">Cancel</a>
    </div>
  </form>
</div>

<!-- Preview modal -->
<div class="modal-overlay" id="preview-modal" onclick="if(event.target===this)closePreview()">
  <div class="modal-box">
    <h3>Email Preview</h3>
    <p><strong>To:</strong> <?= \Luster\Core\View::e($member_name ?? '') ?> &lt;<?= \Luster\Core\View::e($member_email ?? '') ?>&gt;</p>
    <p><strong>Subject:</strong> <span id="preview-subject"></span></p>
    <hr>
    <div id="preview-body" style="white-space:pre-wrap;font-size:.875rem;color:var(--text)"></div>
    <br>
    <button class="btn btn-ghost" onclick="closePreview()">Close</button>
  </div>
</div>

<script>
document.getElementById('template-select').addEventListener('change', function () {
  var o = this.options[this.selectedIndex];
  if (o.value) {
    document.getElementById('subject').value = o.value;
    document.getElementById('body').value = o.dataset.body || '';
  }
});

function previewEmail() {
  document.getElementById('preview-subject').textContent = '<?= addslashes($flName) ?>: ' + document.getElementById('subject').value;
  document.getElementById('preview-body').textContent    = document.getElementById('body').value;
  var m = document.getElementById('preview-modal');
  m.style.display = 'flex';
}

function closePreview() {
  document.getElementById('preview-modal').style.display = 'none';
}
</script>
