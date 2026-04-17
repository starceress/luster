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
  <div class="composer-side">

    <?php if (!empty($saved_emails)): ?>
    <div class="card">
      <div class="card-head">Load Template</div>
      <div class="card-body">
        <div class="select-wrap">
          <select id="em-template-select" class="form-select">
            <option value="">— Select a Template —</option>
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
        <form method="POST" action="<?= $b ?>/admin/new-email-template/save" id="em-save-tpl-form">
          <input type="hidden" name="_csrf"       value="<?= View::e($csrf_token ?? '') ?>">
          <input type="hidden" name="referer"     id="em-save-referer" value="<?= View::e($_SERVER['REQUEST_URI'] ?? '') ?>">
          <input type="hidden" name="tpl_subject" id="em-save-subject">
          <input type="hidden" name="tpl_body"    id="em-save-body">
          <input type="text"   name="tpl_name"    id="em-save-name"
                 class="form-input" placeholder="Template name&hellip;" maxlength="150">
          <button type="submit" class="btn btn-secondary btn-sm btn-block-center" id="em-save-btn">Save Template</button>
        </form>
      </div>
    </div>

    <details class="card chevron" open>
      <summary class="card-head">Available Tokens</summary>
      <div class="card-body">
        <table class="et-token-table">
          <tbody>
            <tr class="tok-row" data-textarea="body" data-token="{fan_name}"       title="Click to insert"><td><code>{fan_name}</code></td><td>Fan's display name</td></tr>
            <tr class="tok-row" data-textarea="body" data-token="{fan_email}"      title="Click to insert"><td><code>{fan_email}</code></td><td>Fan's email address</td></tr>
            <tr class="tok-row" data-textarea="body" data-token="{fan_url}"        title="Click to insert"><td><code>{fan_url}</code></td><td>Fan's website URL</td></tr>
            <tr class="tok-row" data-textarea="body" data-token="{fan_country}"    title="Click to insert"><td><code>{fan_country}</code></td><td>Fan's country</td></tr>
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
  </div>

  <div class="gradient-card">
    <form method="POST" id="email-form">
      <input type="hidden" name="_csrf" value="<?= View::e($csrf_token ?? '') ?>">
      <input type="hidden" name="member_email" value="<?= View::e($member_email ?? '') ?>">

      <div class="pc-head">
        <div class="pc-head-icon pc-head-icon-bg"><?= View::icon('email') ?></div>
        <div class="pc-head-title">Compose New Email</div>
        <span class="badge badge-info-pink pc-head-badge"><?= $flName ?></span>
      </div>

      <div class="composer-block">
        <div class="composer-row">
          <span class="composer-lbl">From</span>
          <div>
            <?php if (($from_name ?? '') !== ''): ?>
              <span class="composer-name"><?= View::e($from_name) ?></span>
            <?php endif; ?>
            <span class="composer-email">&lt;<?= View::e($from_email ?? '') ?>&gt;</span>
          </div>
        </div>
        <div class="composer-row">
          <span class="composer-lbl">To</span>
          <div>
            <span class="composer-name"><?= View::e($member_name ?? '') ?></span><span class="composer-email">&lt;<?= View::e($member_email ?? '') ?>&gt;</span>
          </div>
        </div>

        <div class="composer-row">
          <span class="composer-lbl">Subject</span>
        <span class="badge badge-info-pink"><?= $flName ?>:</span>
        <input class="subj-input" type="text" name="subject_suffix" id="subject"
               value="<?= $oldSubject ?>" placeholder="Enter subject&hellip;">
        </div>
      </div>

      <div>
        <textarea class="email-textarea" name="body" id="body" placeholder="Write your message&hellip;"><?= $oldBody ?></textarea>
      </div>

      <div class="composer-footer">
        <button type="button" class="btn btn-primary" id="em-send-btn"><?= View::icon('send') ?> Send</button>
        <button type="button" class="btn btn-preview" id="preview-email-btn">Preview</button>
        <button type="button" class="btn btn-ghost js-form-reset">Clear</button>
        <div class="composer-footer-spacer"></div>
        <a href="<?= View::e($referer ?? $b . '/admin/members/' . $listingId) ?>"
           class="btn btn-danger">Cancel</a>
      </div>
    </form>
  </div>
</div>

<div class="modal-backdrop is-hidden" id="preview-modal"
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
      <div class="composer-block">
        <div class="composer-row">
          <span class="composer-lbl">From</span>
          <div>
            <span class="composer-name" id="em-preview-from-name"></span>
            <span class="composer-email" id="em-preview-from-email"></span>
          </div>
        </div>
        <div class="composer-row">
          <span class="composer-lbl">To</span>
          <div>
            <span class="composer-name" id="em-preview-to-name"></span>
            <span class="composer-email" id="em-preview-to-email"></span>
          </div>
        </div>
        <div class="composer-row">
          <span class="composer-lbl">Subject</span>
          <span class="composer-val" id="preview-subject"></span>
        </div>
      </div>
      <div id="preview-body" class="body-area"></div>
    </div>
    <div class="modal-footer" id="em-footer-preview">
      <button class="btn btn-ghost" id="close-preview-btn">Close</button>
    </div>
    <div id="em-footer-confirm" class="is-hidden">
      <div class="me-confirm-warning"><?= View::icon('caution') ?>Are you sure you want to send this email? This action cannot be undone.</div>
      <div class="modal-footer">
        <button type="button" class="btn btn-primary" id="em-confirm-send-btn"><?= View::icon('send') ?> Send</button>
        <button type="button" class="btn btn-danger" id="em-confirm-cancel-btn">Cancel</button>
      </div>
    </div>
  </div>
</div>
