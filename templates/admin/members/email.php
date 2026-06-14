<?php declare(strict_types=1);
use Luster\Core\View;
$b          = rtrim(View::e($base_url ?? ''), '/');
$flName     = View::e($listing->title ?? '');
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
          <select id="em-template-select" >
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
        <form method="POST" action="<?= $b ?>/admin/new-email-template/save" id="em-save-tpl-form">
          <input type="hidden" name="_csrf"       value="<?= View::e($csrf_token ?? '') ?>">
          <input type="hidden" name="referer"     id="em-save-referer" value="<?= View::e($_SERVER['REQUEST_URI'] ?? '') ?>">
          <input type="hidden" name="tpl_subject" id="em-save-subject">
          <input type="hidden" name="tpl_body"    id="em-save-body">
          <input type="text"   name="tpl_name"    id="em-save-name"
                 class="form" placeholder="Template name&hellip;" maxlength="150">
          <button type="submit" class="btn btn-secondary btn-sm btn-block-center" id="em-save-btn">Save Template</button>
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
        <table class="et-token-table et-token-table--compact">
          <tbody>
            <tr class="tok-row" tabindex="0" role="button" title="Fan's display name"      aria-label="Insert {fan_name}. Fan's display name"          data-textarea="body" data-token="{fan_name}">       <td class="token-cell" data-desc="Fan's display name">      <code>{fan_name}</code></td></tr>
            <tr class="tok-row" tabindex="0" role="button" title="Fan's email address"     aria-label="Insert {fan_email}. Fan's email address"         data-textarea="body" data-token="{fan_email}">      <td class="token-cell" data-desc="Fan's email address">     <code>{fan_email}</code></td></tr>
            <tr class="tok-row" tabindex="0" role="button" title="Fan's website URL"       aria-label="Insert {fan_url}. Fan's website URL"             data-textarea="body" data-token="{fan_url}">        <td class="token-cell" data-desc="Fan's website URL">       <code>{fan_url}</code></td></tr>
            <tr class="tok-row" tabindex="0" role="button" title="Fan's country"           aria-label="Insert {fan_country}. Fan's country"             data-textarea="body" data-token="{fan_country}">    <td class="token-cell" data-desc="Fan's country">           <code>{fan_country}</code></td></tr>
            <tr class="tok-row" tabindex="0" role="button" title="Name of the listing"     aria-label="Insert {listing_title}. Name of the listing"     data-textarea="body" data-token="{listing_title}">   <td class="token-cell" data-desc="Name of the listing">     <code>{listing_title}</code></td></tr>
            <tr class="tok-row" tabindex="0" role="button" title="Subject or topic"        aria-label="Insert {listing_subject}. Subject or topic"      data-textarea="body" data-token="{listing_subject}"><td class="token-cell" data-desc="Subject or topic">        <code>{listing_subject}</code></td></tr>
            <tr class="tok-row" tabindex="0" role="button" title="URL of the listing"      aria-label="Insert {listing_url}. URL of the listing"        data-textarea="body" data-token="{listing_url}">    <td class="token-cell" data-desc="URL of the listing">      <code>{listing_url}</code></td></tr>
            <tr class="tok-row" tabindex="0" role="button" title="Collective or site name" aria-label="Insert {site_name}. Collective or site name"     data-textarea="body" data-token="{site_name}">      <td class="token-cell" data-desc="Collective or site name"> <code>{site_name}</code></td></tr>
            <tr class="tok-row" tabindex="0" role="button" title="Owner's display name"   aria-label="Insert {owner_name}. Owner's display name"       data-textarea="body" data-token="{owner_name}">     <td class="token-cell" data-desc="Owner's display name">    <code>{owner_name}</code></td></tr>
            <tr class="tok-row" tabindex="0" role="button" title="Owner's email address"  aria-label="Insert {owner_email}. Owner's email address"     data-textarea="body" data-token="{owner_email}">    <td class="token-cell" data-desc="Owner's email address">   <code>{owner_email}</code></td></tr>
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
        <textarea class="email" name="body" id="body" placeholder="Write your message&hellip;"><?= $oldBody ?></textarea>
      </div>

      <div class="composer-footer">
        <button type="button" class="btn btn-primary" id="em-send-btn"><?= View::icon('send') ?> Send</button>
        <button type="button" class="btn btn-preview" id="preview-email-btn"><?= View::icon('eye-on') ?> Preview</button>
        <button type="button" class="btn btn-ghost js-form-reset">Clear</button>
        <div class="composer-footer-spacer"></div>
        <a href="<?= View::e($referer ?? $b . '/admin/members?listing_id=' . (int) ($listingId ?? 0) . '&status=all') ?>"
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
      <button type="button" class="modal-close" aria-label="Close">&times;</button>
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
      <button class="btn btn-quaternary" id="close-preview-btn">Close</button>
    </div>
    <div id="em-footer-confirm" class="is-hidden">
      <div class="me-confirm-warning"><?= View::icon('caution') ?>Are you sure you want to send this email? This action cannot be undone.</div>
      <div class="modal-footer">
        <button type="button" class="btn btn-primary" id="em-confirm-send-btn"><?= View::icon('send') ?> Send</button>
        <button type="button" class="btn btn-quaternary" id="em-confirm-cancel-btn">Cancel</button>
      </div>
    </div>
  </div>
</div>