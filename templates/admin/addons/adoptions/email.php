<?php
/*
 Luster — https://luster.nu | https://github.com/starceress/luster
 Copyright (c) 2026 Star (https://dreamesque.net)

 Adoptions admin email composer for a single applicant. Uses the standard
 composer grid layout with subject/body fields, saved template loading,
 and a recipient summary showing the applicant name and listing context.
*/

/**
 * Variables:
 *   $application   array   Application row from lstr_adoption_applications
 *   $listing       ?Listing
 *   $from_name     string
 *   $from_email    string
 *   $owner_name    string
 *   $owner_email   string
 *   $site_name     string
 *   $saved_emails  object[]
 *   $old           array{subject: string, body: string}
 *   $error         string
 *   $base_url      string
 *   $csrf_token    string
 */

declare(strict_types=1);

use Luster\Core\View;

$b          = rtrim(View::e($base_url), '/');
$appId      = (int) $application['id'];
$flSubject  = View::e($listing->subject ?? $listing->title ?? '');
$oldSubject = View::e($old['subject'] ?? '');
$oldBody    = htmlspecialchars($old['body'] ?? '', ENT_QUOTES, 'UTF-8');
?>

<div class="toolbar">
  <a href="<?= $b ?>/admin/adoptions/<?= $appId ?>/edit" class="link">&larr; Back to Application</a>
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
          <select id="em-template-select">
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

      <div class="filter-panel is-open token-search-wrap">
    <div class="search-field-wrap">
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
    </div></div>

    <p data-token-search-empty class="form-hint token-search-empty" hidden>No tokens found.</p>

    <details class="card chevron" data-token-card>
      <summary class="chevron-head">Available Tokens</summary>
      <div class="chevron-body">
        <table class="et-token-table et-token-table--compact">
          <tbody>
            <tr class="tok-row" tabindex="0" role="button" title="Applicant's name"            aria-label="Insert {app_name}. Applicant's name"             data-textarea="body" data-token="{app_name}">       <td class="token-cell" data-desc="Applicant's name">            <code>{app_name}</code></td></tr>
            <tr class="tok-row" tabindex="0" role="button" title="Applicant's email address"   aria-label="Insert {app_email}. Applicant's email address"    data-textarea="body" data-token="{app_email}">      <td class="token-cell" data-desc="Applicant's email address">   <code>{app_email}</code></td></tr>
            <tr class="tok-row" tabindex="0" role="button" title="Applicant's website URL"     aria-label="Insert {app_url}. Applicant's website URL"        data-textarea="body" data-token="{app_url}">        <td class="token-cell" data-desc="Applicant's website URL">     <code>{app_url}</code></td></tr>
            <tr class="tok-row" tabindex="0" role="button" title="Message they submitted"      aria-label="Insert {app_message}. Message they submitted"     data-textarea="body" data-token="{app_message}">    <td class="token-cell" data-desc="Message they submitted">      <code>{app_message}</code></td></tr>
            <tr class="tok-row" tabindex="0" role="button" title="Current application status"  aria-label="Insert {app_status}. Current application status"  data-textarea="body" data-token="{app_status}">     <td class="token-cell" data-desc="Current application status">  <code>{app_status}</code></td></tr>
            <tr class="tok-row" tabindex="0" role="button" title="Name of the listing"         aria-label="Insert {listing_title}. Name of the listing"      data-textarea="body" data-token="{listing_title}">  <td class="token-cell" data-desc="Name of the listing">         <code>{listing_title}</code></td></tr>
            <tr class="tok-row" tabindex="0" role="button" title="URL of the listing"          aria-label="Insert {listing_url}. URL of the listing"         data-textarea="body" data-token="{listing_url}">    <td class="token-cell" data-desc="URL of the listing">          <code>{listing_url}</code></td></tr>
            <tr class="tok-row" tabindex="0" role="button" title="Collective or site name"     aria-label="Insert {site_name}. Collective or site name"      data-textarea="body" data-token="{site_name}">      <td class="token-cell" data-desc="Collective or site name">     <code>{site_name}</code></td></tr>
            <tr class="tok-row" tabindex="0" role="button" title="Owner's display name"        aria-label="Insert {owner_name}. Owner's display name"        data-textarea="body" data-token="{owner_name}">     <td class="token-cell" data-desc="Owner's display name">        <code>{owner_name}</code></td></tr>
            <tr class="tok-row" tabindex="0" role="button" title="Owner's email address"       aria-label="Insert {owner_email}. Owner's email address"      data-textarea="body" data-token="{owner_email}">    <td class="token-cell" data-desc="Owner's email address">       <code>{owner_email}</code></td></tr>
          </tbody>
        </table>
      </div>
    </details>

  </div><!-- /.composer-side -->

  <div class="card card--gradient">
    <form method="POST" action="<?= $b ?>/admin/adoptions/<?= $appId ?>/email" id="email-form">
      <input type="hidden" name="_csrf" value="<?= View::e($csrf_token) ?>">

      <div class="panel-header">
        <div class="panel-header-icon panel-header-icon-filled"><?= View::icon('email') ?></div>
        <div class="panel-header-title">Compose New Email</div>
        <?php if ($flSubject !== ''): ?>
          <span class="badge badge-info2 panel-header-badge"><?= $flSubject ?></span>
        <?php endif; ?>
      </div>

      <div class="composer-block">
        <div class="composer-row">
          <span class="composer-label">From</span>
          <div>
            <?php if ($from_name !== ''): ?>
              <span class="composer-name"><?= View::e($from_name) ?></span>
            <?php endif; ?>
            <span class="composer-email">&lt;<?= View::e($from_email) ?>&gt;</span>
          </div>
        </div>
        <div class="composer-row">
          <span class="composer-label">To</span>
          <div>
            <span class="composer-name"><?= View::e($application['name']) ?></span>
            <span class="composer-email">&lt;<?= View::e($application['email']) ?>&gt;</span>
          </div>
        </div>
        <div class="composer-row">
          <span class="composer-label">Subject</span>
          <div class="flex-fill">
            <input class="subj-input" type="text" name="subject" id="subject"
                   maxlength="255" required placeholder="Enter subject&hellip;"
                   value="<?= $oldSubject ?>">
            <p class="form-hint">Tokens work in the subject line too.</p>
          </div>
        </div>
      </div>

      <div>
        <textarea class="email" name="body" id="body"
                  maxlength="50000" required
                  placeholder="Write your message&hellip;"><?= $oldBody ?></textarea>
      </div>

      <div class="composer-footer">
        <button type="button" class="btn btn-primary" id="em-send-btn"><?= View::icon('send') ?> Send</button>
        <button type="button" class="btn btn-preview" id="preview-email-btn"><?= View::icon('eye-on') ?> Preview</button>
        <button type="button" class="btn btn-ghost js-form-reset">Clear</button>
        <div class="composer-footer-spacer"></div>
        <a href="<?= $b ?>/admin/adoptions/<?= $appId ?>/edit" class="btn btn-danger">Cancel</a>
      </div>
    </form>
  </div><!-- /.card--gradient -->

</div><!-- /.composer-grid -->

<div class="modal-backdrop is-hidden" id="preview-modal"
     data-listing-name="<?= View::e($listing->title ?? '') ?>"
     data-listing-subject="<?= $flSubject ?>"
     data-listing-url="<?= View::e($listing->url ?? '') ?>"
     data-fan-name="<?= View::e($application['name']) ?>"
     data-fan-email="<?= View::e($application['email']) ?>"
     data-fan-url="<?= View::e($application['url'] ?? '') ?>"
     data-fan-country=""
     data-app-status="<?= View::e($application['status'] ?? '') ?>"
     data-app-message="<?= htmlspecialchars((string) ($application['message'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
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
          <span class="composer-label">From</span>
          <div>
            <span class="composer-name" id="em-preview-from-name"></span>
            <span class="composer-email" id="em-preview-from-email"></span>
          </div>
        </div>
        <div class="composer-row">
          <span class="composer-label">To</span>
          <div>
            <span class="composer-name" id="em-preview-to-name"></span>
            <span class="composer-email" id="em-preview-to-email"></span>
          </div>
        </div>
        <div class="composer-row">
          <span class="composer-label">Subject</span>
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
