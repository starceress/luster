<?php
/**
 * KIM List admin - email individual member (composer layout).
 *
 * Variables:
 *   $member        KimMember
 *   $listing       Listing|null
 *   $from_name     string
 *   $from_email    string
 *   $owner_name    string
 *   $owner_email   string
 *   $site_name     string
 *   $saved_emails  object[]
 *   $old           array{subject: string, body: string}
 *   $referer       string
 *   $base_url      string
 *   $csrf_token    string
 *   $error         string
 *   $success       string
 */

declare(strict_types=1);

use Luster\Core\View;

$b          = rtrim(View::e($base_url), '/');
$flSubject  = View::e($listing->subject ?? '');
$oldSubject = View::e($old['subject'] ?? '');
$oldBody    = htmlspecialchars($old['body'] ?? '', ENT_QUOTES, 'UTF-8');
$backUrl    = $listing !== null
    ? $b . '/admin/kim/' . (int) $listing->id . '/show'
    : $b . '/admin/kim';
?>

<div class="page-topbar">
  <a href="<?= View::e($backUrl) ?>" class="link">&larr; Back to <?= $listing !== null ? View::e($listing->subject) : 'KIM List' ?></a>
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
        <p class="form-hint">Selecting fills the subject and message fields. You can edit before sending.</p>
        <a href="<?= $b ?>/admin/emails?tab=custom" class="btn btn-tertiary btn-sm btn-block-center">Manage Templates</a>
      </div>
    </div>
    <?php endif; ?>

    <div class="card">
      <div class="card-head">Save as Template</div>
      <div class="card-body">
        <form method="POST" action="<?= $b ?>/admin/new-email-template/save" id="em-save-tpl-form">
          <input type="hidden" name="_csrf"       value="<?= View::e($csrf_token) ?>">
          <input type="hidden" name="referer"     id="em-save-referer" value="<?= View::e($_SERVER['REQUEST_URI'] ?? '') ?>">
          <input type="hidden" name="tpl_subject" id="em-save-subject">
          <input type="hidden" name="tpl_body"    id="em-save-body">
          <input type="text"   name="tpl_name"    id="em-save-name"
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
        <table class="et-token-table et-token-table--compact">
          <tbody>
            <tr class="tok-row" tabindex="0" role="button" title="Member's display name"   aria-label="Insert {name}. Member's display name"            data-textarea="body" data-token="{name}">           <td class="token-cell" data-desc="Member's display name">   <code>{name}</code></td></tr>
            <tr class="tok-row" tabindex="0" role="button" title="Member's email address"  aria-label="Insert {email}. Member's email address"          data-textarea="body" data-token="{email}">          <td class="token-cell" data-desc="Member's email address">  <code>{email}</code></td></tr>
            <tr class="tok-row" tabindex="0" role="button" title="Member's website URL"    aria-label="Insert {url}. Member's website URL"              data-textarea="body" data-token="{url}">            <td class="token-cell" data-desc="Member's website URL">    <code>{url}</code></td></tr>
            <tr class="tok-row" tabindex="0" role="button" title="Sign-up date"            aria-label="Insert {added_at}. Sign-up date"                 data-textarea="body" data-token="{added_at}">       <td class="token-cell" data-desc="Sign-up date">            <code>{added_at}</code></td></tr>
            <tr class="tok-row" tabindex="0" role="button" title="Subject or topic"        aria-label="Insert {listing_subject}. Subject or topic"      data-textarea="body" data-token="{listing_subject}"><td class="token-cell" data-desc="Subject or topic">        <code>{listing_subject}</code></td></tr>
            <tr class="tok-row" tabindex="0" role="button" title="URL of the listing"      aria-label="Insert {listing_url}. URL of the listing"        data-textarea="body" data-token="{listing_url}">    <td class="token-cell" data-desc="URL of the listing">      <code>{listing_url}</code></td></tr>
            <tr class="tok-row" tabindex="0" role="button" title="Collective or site name" aria-label="Insert {site_name}. Collective or site name"     data-textarea="body" data-token="{site_name}">      <td class="token-cell" data-desc="Collective or site name"> <code>{site_name}</code></td></tr>
            <tr class="tok-row" tabindex="0" role="button" title="Owner's display name"   aria-label="Insert {owner_name}. Owner's display name"       data-textarea="body" data-token="{owner_name}">     <td class="token-cell" data-desc="Owner's display name">    <code>{owner_name}</code></td></tr>
            <tr class="tok-row" tabindex="0" role="button" title="Owner's email address"  aria-label="Insert {owner_email}. Owner's email address"     data-textarea="body" data-token="{owner_email}">    <td class="token-cell" data-desc="Owner's email address">   <code>{owner_email}</code></td></tr>
          </tbody>
        </table>
      </div>
    </details>

  </div><!-- /.composer-side -->

  <div class="gradient-card">
    <form method="POST" id="email-form">
      <input type="hidden" name="_csrf"          value="<?= View::e($csrf_token) ?>">
      <input type="hidden" name="member_email"   value="<?= View::e($member->email) ?>">

      <div class="pc-head">
        <div class="pc-head-icon pc-head-icon-bg"><?= View::icon('email') ?></div>
        <div class="pc-head-title">Compose New Email</div>
        <?php if ($flSubject !== ''): ?>
          <span class="badge badge-info-pink pc-head-badge"><?= $flSubject ?></span>
        <?php endif; ?>
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
          <div>
            <span class="composer-name"><?= View::e($member->name) ?></span>
            <span class="composer-email">&lt;<?= View::e($member->email) ?>&gt;</span>
          </div>
        </div>
        <div class="composer-row">
          <span class="composer-lbl">Subject</span>
          <?php if ($flSubject !== ''): ?>
            <span class="badge badge-info-pink"><?= $flSubject ?>:</span>
          <?php endif; ?>
          <input class="subj-input" type="text" name="subject_suffix" id="subject"
                 maxlength="255" required value="<?= $oldSubject ?>" placeholder="Enter subject&hellip;">
        </div>
      </div>

      <div>
        <textarea class="email" name="body" id="body"
                  placeholder="Write your message&hellip;"><?= $oldBody ?></textarea>
      </div>

      <div class="composer-footer">
        <button type="button" class="btn btn-primary" id="em-send-btn"><?= View::icon('send') ?> Send</button>
        <button type="button" class="btn btn-preview" id="preview-email-btn"><?= View::icon('eye-on') ?> Preview</button>
        <button type="button" class="btn btn-ghost js-form-reset">Clear</button>
        <div class="composer-footer-spacer"></div>
        <a href="<?= View::e($referer) ?>" class="btn btn-danger">Cancel</a>
      </div>
    </form>
  </div><!-- /.gradient-card -->

</div><!-- /.composer-grid -->

<div class="modal-backdrop is-hidden" id="preview-modal"
     data-listing-name="<?= $flSubject ?>"
     data-listing-subject="<?= $flSubject ?>"
     data-listing-url="<?= View::e($listing->url ?? '') ?>"
     data-fan-name="<?= View::e($member->name) ?>"
     data-fan-email="<?= View::e($member->email) ?>"
     data-fan-url="<?= View::e($member->url) ?>"
     data-fan-country=""
     data-site-name="<?= View::e($site_name) ?>"
     data-owner-name="<?= View::e($owner_name) ?>"
     data-owner-email="<?= View::e($owner_email) ?>">
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
