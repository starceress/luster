<?php

declare(strict_types=1);

use Luster\Core\View;
use Luster\Repositories\EmailTemplateRepo;

/**
 * Unified Emails page - four tabs.
 *
 * @var string                            $active_tab      'templates'|'bulk'|'custom'
 * @var string                            $success
 * @var string                            $error
 * @var string                            $base_url
 * @var string                            $csrf_token
 *
 * Templates tab:
 * @var \Luster\Models\Listing[]         $all_listings
 * @var string                            $scope            'global' or listing ID as string
 * @var \Luster\Models\Listing|null       $listing
 * @var array<string, array>              $cards
 * @var string                            $owner_name
 * @var string                            $owner_email
 * @var string                            $site_name_val
 *
 * Bulk email tab:
 * @var \Luster\Models\Listing[]         $listings
 * @var array<int, int>                   $listing_counts
 * @var int                               $deduplicated_total
 * @var object[]                          $saved_emails
 * @var object[]                          $global_email_templates
 * @var int                               $old_listing_id
 * @var string                            $old_subject
 * @var string                            $old_body
 * @var string                            $from_name_val
 * @var string                            $from_email_val
 */

$b = rtrim(View::e($base_url), '/');

//  Email Templates tab helpers 
$typeLabels = [
    EmailTemplateRepo::TYPE_JOIN               => 'Join Confirmation',
    EmailTemplateRepo::TYPE_APPROVE            => 'Membership Approved',
    EmailTemplateRepo::TYPE_UPDATE             => 'Update Confirmation',
    EmailTemplateRepo::TYPE_REMOVE             => 'Removal Confirmation',
    EmailTemplateRepo::TYPE_RESET              => 'Password Reset',
    EmailTemplateRepo::TYPE_NOTIFY_JOIN        => 'Admin - New Member Notification',
    EmailTemplateRepo::TYPE_AFFILIATE_APPLIED  => 'Affiliate Application Notification',
    EmailTemplateRepo::TYPE_AFFILIATE_APPROVED => 'Affiliate Approved',
    EmailTemplateRepo::TYPE_AFFILIATE_REJECTED => 'Affiliate Rejected',
    EmailTemplateRepo::TYPE_CODE_APPROVED      => 'Code Donation Approved',
];

$navLabels = [
    EmailTemplateRepo::TYPE_JOIN               => 'Join',
    EmailTemplateRepo::TYPE_APPROVE            => 'Approved',
    EmailTemplateRepo::TYPE_UPDATE             => 'Update',
    EmailTemplateRepo::TYPE_REMOVE             => 'Remove',
    EmailTemplateRepo::TYPE_RESET              => 'Password Reset',
    EmailTemplateRepo::TYPE_NOTIFY_JOIN        => 'Admin Notify',
    EmailTemplateRepo::TYPE_AFFILIATE_APPLIED  => 'Affiliate Applied',
    EmailTemplateRepo::TYPE_AFFILIATE_APPROVED => 'Affiliate Approved',
    EmailTemplateRepo::TYPE_AFFILIATE_REJECTED => 'Affiliate Rejected',
    EmailTemplateRepo::TYPE_CODE_APPROVED      => 'Code Approved',
];

$typeHints = [
    EmailTemplateRepo::TYPE_JOIN               => 'Sent to the fan after they successfully join.',
    EmailTemplateRepo::TYPE_APPROVE            => 'Sent when an admin approves a pending membership.',
    EmailTemplateRepo::TYPE_UPDATE             => 'Sent to the fan after they update their details.',
    EmailTemplateRepo::TYPE_REMOVE             => 'Sent to the fan after their membership is removed.',
    EmailTemplateRepo::TYPE_RESET              => 'Sent when a fan requests a password reset. Must include {reset_url}.',
    EmailTemplateRepo::TYPE_NOTIFY_JOIN        => 'Sent to this listing\'s admin email when a new fan joins.',
    EmailTemplateRepo::TYPE_AFFILIATE_APPLIED  => 'Sent to the listing admin when a new affiliate application is submitted.',
    EmailTemplateRepo::TYPE_AFFILIATE_APPROVED => 'Sent to the applicant when an affiliate application is approved.',
    EmailTemplateRepo::TYPE_AFFILIATE_REJECTED => 'Sent to the applicant when an affiliate application is rejected.',
];

$allTokenDesc = [
    '{fan_name}'        => "Fan's display name",
    '{fan_email}'       => "Fan's email address",
    '{fan_url}'         => "Fan's website URL (may be empty)",
    '{fan_country}'     => "Fan's country",
    '{reset_url}'       => "Password reset link - required in Reset emails",
    '{listing_title}'    => "Name of the fanlisting",
    '{listing_subject}' => "Subject or topic of the fanlisting",
    '{listing_url}'     => "URL of the fanlisting",
    '{site_name}'       => "Collective or site name",
    '{owner_name}'      => "Admin's display name",
    '{owner_email}'     => "Admin's email address",
    '{aff_name}'        => "Affiliate applicant's site name",
    '{aff_url}'         => "Affiliate applicant's site URL",
    '{aff_email}'       => "Affiliate applicant's email address",
    '{aff_message}'     => "Affiliate applicant's message, if provided",
];

$commonTokens = ['{fan_name}', '{fan_email}', '{fan_url}', '{fan_country}', '{listing_title}', '{listing_subject}', '{listing_url}', '{site_name}', '{owner_name}', '{owner_email}'];
$typeTokens   = array_fill_keys(EmailTemplateRepo::TYPES, $commonTokens);
$typeTokens[EmailTemplateRepo::TYPE_RESET] = array_merge(
    ['{fan_name}', '{fan_email}', '{reset_url}'],
    array_slice($commonTokens, 2)
);
$affiliateTokens = ['{aff_name}', '{aff_url}', '{aff_email}', '{aff_message}', '{listing_title}', '{listing_url}', '{site_name}', '{owner_name}', '{owner_email}'];
$typeTokens[EmailTemplateRepo::TYPE_AFFILIATE_APPLIED]  = $affiliateTokens;
$typeTokens[EmailTemplateRepo::TYPE_AFFILIATE_APPROVED] = $affiliateTokens;
$typeTokens[EmailTemplateRepo::TYPE_AFFILIATE_REJECTED] = $affiliateTokens;

$isGlobal     = ($scope === 'global');
$resetConfirm = $isGlobal
    ? 'Reset this template to the built-in default? Your edits will be lost.'
    : 'Reset this template to the global version? Your per-listing customisation will be removed.';

//  Bulk email tab helpers 
$oldSubjectE = View::e($old_subject ?? '');
$oldBodyE    = htmlspecialchars($old_body ?? '', ENT_QUOTES, 'UTF-8');

?>

<?php if ($success !== ''): ?>
  <div class="alert alert-success"><?= View::e($success) ?></div>
<?php endif; ?>
<?php if ($error !== ''): ?>
  <div class="alert alert-error"><?= View::e($error) ?></div>
<?php endif; ?>

<!-- ──── TAB BAR ────────────────────────────────────────────────────── -->
<div class="tabs-wrap">
  <div class="tabs">
    <button class="tabs-btn<?= $active_tab === 'bulk'      ? ' active' : '' ?>" data-tab="bulk">Bulk Email</button>
    <button class="tabs-btn<?= $active_tab === 'templates' ? ' active' : '' ?>" data-tab="templates">Email Templates</button>
    <button class="tabs-btn<?= $active_tab === 'custom'    ? ' active' : '' ?>" data-tab="custom">Custom Templates</button>
  </div>
  <div class="select-wrap tabs-select-wrap">
    <select class="tabs-select">
      <option value="bulk"<?=      $active_tab === 'bulk'      ? ' selected' : '' ?>>Bulk Email</option>
      <option value="templates"<?= $active_tab === 'templates' ? ' selected' : '' ?>>Email Templates</option>
      <option value="custom"<?=    $active_tab === 'custom'    ? ' selected' : '' ?>>Custom Templates</option>
    </select>
  </div>
</div>

<div id="tab-templates" class="tabs-panel<?= $active_tab === 'templates' ? ' active' : '' ?>">
  <div class="email-scope-row">
    <form method="GET" action="<?= $b ?>/admin/emails" class="action-form-inline">
      <input type="hidden" name="tab" value="templates">
      <div class="select-wrap email-scope-select">
        <select name="listing" class="js-autosubmit" >
          <option value="global"<?= $isGlobal ? ' selected' : '' ?>>Global Templates (default for all listings)</option>
          <?php if (!empty($all_listings)): ?>
            <option disabled></option>
            <?php foreach ($all_listings as $l): ?>
              <?php $sel = (!$isGlobal && (int) $scope === $l->id); ?>
              <option value="<?= (int) $l->id ?>"<?= $sel ? ' selected' : '' ?>>
                <?= View::e($l->title) ?>
              </option>
            <?php endforeach; ?>
          <?php endif; ?>
        </select>
      </div>
    </form>

    <?php if (!$isGlobal && $listing !== null): ?>
      <span class="text-dim">
        Cards showing <span class="badge badge-info">Custom</span> override the global template.
        Cards showing <span class="badge badge-info-gold">Global</span> inherit global templates.
      </span>
    <?php else: ?>
      <span class="text-dim">
        <span class="badge badge-info-gold">Global</span> templates apply to all listings unless a per-listing override exists.
      </span>
    <?php endif; ?>
  </div>

  <div id="et-preview-data"
       data-site-name="<?= View::e($site_name_val) ?>"
       data-owner-name="<?= View::e($owner_name) ?>"
       data-owner-email="<?= View::e($owner_email) ?>"
       data-listing-name="<?= View::e($listing?->title ?? 'My Fanlisting') ?>"
       data-listing-subject="<?= View::e($listing?->subject ?? 'Music') ?>"
       data-listing-url="<?= View::e($listing?->url ?? 'https://myfanlisting.com') ?>"
       data-is-global="<?= $isGlobal ? '1' : '0' ?>"
       class="is-hidden"></div>

  <form id="et-save-form" method="POST" action="<?= $b ?>/admin/email-templates/save">
    <input type="hidden" name="_csrf"  value="<?= View::e($csrf_token) ?>">
    <input type="hidden" name="scope"  value="<?= View::e($scope) ?>">

    <div class="mini-nav-layout">
      <nav class="panel-card">
        <div class="card-head">Template Name</div>
        <div class="panel-body">
          <?php foreach (EmailTemplateRepo::TYPES as $i => $type): ?>
            <button type="button"
                    class="mini-nav-item<?= $i === 0 ? ' active' : '' ?>"
                    data-et-tab="<?= View::e($type) ?>">
              <?= View::e($navLabels[$type]) ?>
              <?php if (!$isGlobal && isset($cards[$type]) && $cards[$type]['isCustomised']): ?>
                <span class="pc-head-badge">&#x2726;</span>
              <?php endif; ?>
            </button>
          <?php endforeach; ?>
        </div>
      </nav>

      <div class="settings-content">
        <?php foreach (EmailTemplateRepo::TYPES as $i => $type):
          $card = $cards[$type];
        ?>
        <div class="et-panel<?= $i === 0 ? ' active' : '' ?>"
             id="et-tab-<?= View::e($type) ?>">

          <div class="gradient-card<?= !$card['isEnabled'] ? ' et-card-disabled' : '' ?>">

            <!-- Card header -->
            <div class="pc-head">
              <div class="pc-head-meta">
                <div class="pc-head-title"><?= View::e($typeLabels[$type]) ?></div>
                <div class="pc-head-sub"><?= View::e($typeHints[$type]) ?></div>
              </div>
              <div class="pc-head-actions">
                <?php if (!$isGlobal): ?>
                  <?php if ($card['isCustomised']): ?>
                    <span class="badge badge-info">Custom</span>
                  <?php else: ?>
                    <span class="badge badge-info-gold">Global</span>
                  <?php endif; ?>
                <?php endif; ?>
                <label class="et-toggle-wrap" title="Toggle whether this email is sent">
                  <input type="checkbox"
                         class="et-toggle-input et-enabled-cb"
                         name="email_enabled_<?= View::e($type) ?>"
                         value="1"
                         <?= $card['isEnabled'] ? 'checked' : '' ?>>
                  <span class="et-toggle-track"></span><span class="et-toggle-label"><?= $card['isEnabled'] ? 'Enabled' : 'Disabled' ?></span>
                </label>
              </div>
            </div>

            <div class="modal-warning<?= $card['isEnabled'] ? ' is-hidden' : '' ?>"><?= View::icon('alert') ?> This template is <b>disabled</b> - no email(s) will be sent for this event.
</div>

            <!-- Fields -->
            <div class="pc-section">
              <div class="form-group form-group-preview-gap">
                <label class="form-label" for="et-subject-<?= View::e($type) ?>">Subject</label>
                <input class="form et-subject"
                       type="text"
                       id="et-subject-<?= View::e($type) ?>"
                       name="email_subject_<?= View::e($type) ?>"
                       value="<?= View::e($card['fillSubject']) ?>"
                       maxlength="255"
                       data-type="<?= View::e($type) ?>">
              </div>
              <div class="form-group">
                <label class="form-label" for="et-body-<?= View::e($type) ?>">Body</label>
                <textarea class="form et-body"
                          id="et-body-<?= View::e($type) ?>"
                          name="email_body_<?= View::e($type) ?>"
                          rows="9"
                          data-type="<?= View::e($type) ?>"><?= View::e($card['fillBody']) ?></textarea>
              </div>
              <div class="et-preview-panel email-preview-panel is-hidden" id="et-preview-<?= View::e($type) ?>">
                <div class="email-preview-panel-head">
                  Preview <span>(example values)</span>
                  <?php if ($isGlobal): ?>
                    <span class="email-preview-note">&middot; listing tokens show generic placeholders</span>
                  <?php endif; ?>
                </div>
                <div class="email-preview-panel-body">
                  <div class="email-preview-label">Subject</div>
                  <div class="et-preview-subject email-preview-subject"></div>
                  <div class="email-preview-label">Body</div>
                  <pre class="et-preview-body email-preview-pre"></pre>
                </div>
              </div>
            </div>

            <div class="composer-footer">
              <button type="submit" class="btn btn-primary">Save Template</button>
              <button type="button" class="btn btn-preview et-preview-toggle"
                      data-type="<?= View::e($type) ?>"><?= View::icon('eye-on') ?> Preview</button>
              <button type="submit"
                      form="et-reset-<?= View::e($type) ?>"
                      class="btn btn-ghost">
                <?= $isGlobal ? 'Reset to Default' : 'Reset to Global' ?>
              </button>
            </div>

          </div>

          <details class="card chevron" open>
            <summary class="chevron-head">Available Tokens</summary>
            <div class="card-body">
              <table class="et-token-table">
                <tbody>
                  <?php foreach ($typeTokens[$type] as $token): ?>
                    <tr class="tok-row"
                        data-textarea="et-body-<?= View::e($type) ?>"
                        data-token="<?= View::e($token) ?>"
                        title="Click to insert">
                      <td><code><?= View::e($token) ?></code></td>
                      <td><?= View::e($allTokenDesc[$token] ?? '') ?></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          </details>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
  </form>
  <?php foreach (EmailTemplateRepo::TYPES as $type): ?>
  <form id="et-reset-<?= View::e($type) ?>"
        method="POST"
        action="<?= $b ?>/admin/email-templates/reset"
        data-confirm="<?= View::e($resetConfirm) ?>"
        class="is-hidden">
    <input type="hidden" name="_csrf"  value="<?= View::e($csrf_token) ?>">
    <input type="hidden" name="scope"  value="<?= View::e($scope) ?>">
    <input type="hidden" name="type"   value="<?= View::e($type) ?>">
  </form>
  <?php endforeach; ?>
</div>

<div id="tab-bulk" class="tabs-panel<?= $active_tab === 'bulk' ? ' active' : '' ?>">
  <div class="composer-grid">
<!-- ──── SIDEBAR ────────────────────────────────────────────────────── -->
    <div class="composer-side">

      <?php if (!empty($saved_emails) || !empty($global_email_templates)): ?>
      <div class="card">
        <div class="card-head">
          Load Template
        </div>
        <div class="card-body">
          <div class="select-wrap">
            <select id="me-tpl-select" >
              <option value="">&mdash; Select a Template &mdash;</option>
              <?php if (!empty($global_email_templates)): ?>
                <optgroup label="Email Templates">
                  <?php foreach ($global_email_templates as $t): ?>
                    <option value="<?= View::e($t->subject) ?>"
                            data-body="<?= htmlspecialchars($t->body, ENT_QUOTES, 'UTF-8') ?>">
                      <?= View::e($t->label) ?>
                    </option>
                  <?php endforeach; ?>
                </optgroup>
              <?php endif; ?>
              <?php if (!empty($saved_emails)): ?>
                <optgroup label="Saved Templates">
                  <?php foreach ($saved_emails as $t): ?>
                    <option value="<?= View::e($t->subject) ?>"
                            data-body="<?= htmlspecialchars($t->body, ENT_QUOTES, 'UTF-8') ?>">
                      <?= View::e($t->name) ?>
                    </option>
                  <?php endforeach; ?>
                </optgroup>
              <?php endif; ?>
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
          <form method="POST" action="<?= $b ?>/admin/new-email-template/save" id="me-save-tpl-form">
            <input type="hidden" name="_csrf"       value="<?= View::e($csrf_token) ?>">
            <input type="hidden" name="referer"     value="<?= View::e($b . '/admin/emails?tab=bulk') ?>">
            <input type="hidden" name="tpl_subject" id="me-save-subject">
            <input type="hidden" name="tpl_body"    id="me-save-body">
            <input type="text"   name="tpl_name"    id="me-save-name"
                   class="form" placeholder="Template name&hellip;" maxlength="150">
            <button type="submit" class="btn btn-secondary btn-sm btn-block-center" id="me-save-btn">Save Template</button>
          </form>
        </div>
      </div>

      <details class="card chevron" open>
        <summary class="card-head">Available Tokens</summary>
        <div class="card-body">
          <table class="et-token-table">
            <tbody>
              <tr class="tok-row" data-textarea="me-body" data-token="{fan_name}"        title="Click to insert"><td><code>{fan_name}</code></td><td>Fan's display name</td></tr>
              <tr class="tok-row" data-textarea="me-body" data-token="{fan_email}"       title="Click to insert"><td><code>{fan_email}</code></td><td>Fan's email address</td></tr>
              <tr class="tok-row" data-textarea="me-body" data-token="{fan_url}"         title="Click to insert"><td><code>{fan_url}</code></td><td>Fan's website URL</td></tr>
              <tr class="tok-row" data-textarea="me-body" data-token="{fan_country}"     title="Click to insert"><td><code>{fan_country}</code></td><td>Fan's country</td></tr>
              <tr class="tok-row" data-textarea="me-body" data-token="{listing_title}"    title="Click to insert"><td><code>{listing_title}</code></td><td>Name of the listing</td></tr>
              <tr class="tok-row" data-textarea="me-body" data-token="{listing_subject}" title="Click to insert"><td><code>{listing_subject}</code></td><td>Subject or topic of the listing</td></tr>
              <tr class="tok-row" data-textarea="me-body" data-token="{listing_url}"     title="Click to insert"><td><code>{listing_url}</code></td><td>URL of the listing</td></tr>
              <tr class="tok-row" data-textarea="me-body" data-token="{site_name}"       title="Click to insert"><td><code>{site_name}</code></td><td>Collective or site name</td></tr>
              <tr class="tok-row" data-textarea="me-body" data-token="{owner_name}"      title="Click to insert"><td><code>{owner_name}</code></td><td>Owner's display name</td></tr>
              <tr class="tok-row" data-textarea="me-body" data-token="{owner_email}"     title="Click to insert"><td><code>{owner_email}</code></td><td>Owner's email address</td></tr>
            </tbody>
          </table>
        </div>
      </details>
    </div>
    
<!-- ──── MAIN COMPOSE AREA ──────────────────────────────────────────── -->
    <div class="gradient-card ">
      <form method="POST" action="<?= $b ?>/admin/emails/preview" id="compose-form">
        <input type="hidden" name="_csrf" value="<?= View::e($csrf_token) ?>">

        <div class="pc-head">
          <div class="pc-head-icon pc-head-icon-bg"><?= View::icon('email') ?></div>
          <div class="pc-head-title">Bulk Email</div>
          <span class="badge badge-info-pink pc-head-badge" id="me-listing-chip">
            <span id="me-chip-label">All Listings</span>
          </span>
        </div>

        <div class="composer-block">
          <div class="composer-row">
            <span class="composer-lbl">From</span>
            <div>
              <?php if (($from_name_val ?? '') !== ''): ?>
                <span class="composer-name"><?= View::e($from_name_val) ?></span>
              <?php endif; ?>
              <?php if (($from_email_val ?? '') !== ''): ?>
                <span class="composer-email">&lt;<?= View::e($from_email_val) ?>&gt;</span>
              <?php else: ?>
                <span><a href="<?= $b ?>/admin/settings#panel-general" class="link">Set Owner Email in Settings</a></span>
              <?php endif; ?>
            </div>
          </div>
          <div class="composer-row">
            <label class="composer-lbl" for="me-listing">Send To</label>
            <div class="select-wrap">
              <select name="listing_id" id="me-listing" >
                <option value="0"
                        data-count="<?= (int) ($deduplicated_total ?? 0) ?>"
                        data-label="All Listings"
                        <?= ($old_listing_id ?? 0) === 0 ? ' selected' : '' ?>>
                  All listings
                </option>
                <?php foreach ($listings as $lst): ?>
                  <option value="<?= (int) $lst->id ?>"
                          data-count="<?= (int) ($listing_counts[$lst->id] ?? 0) ?>"
                          data-label="<?= View::e($lst->title) ?>"
                          data-subject="<?= View::e($lst->subject) ?>"
                          data-url="<?= View::e($lst->url) ?>"
                          <?= ($old_listing_id ?? 0) === (int) $lst->id ? ' selected' : '' ?>>
                    <?= View::e($lst->title) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>
          <div class="composer-row">
            <label class="composer-lbl" for="me-subject">Subject</label>
            <input class="subj-input" id="me-subject" type="text" name="subject"
                   maxlength="255" required placeholder="Enter subject&hellip;"
                   value="<?= $oldSubjectE ?>">
          </div>
        </div>

        <div>
          <textarea class="email" id="me-body" name="body"
                    maxlength="50000" required
                    placeholder="Write your message&hellip;"><?= $oldBodyE ?></textarea>
        </div>

        <div class="composer-footer">
          <button type="button" class="btn btn-primary" id="me-send-btn"><?= View::icon('send') ?> Send</button>
          <button type="button" class="btn btn-preview" id="me-preview-btn">Preview</button>
          <button type="button" class="btn btn-ghost js-form-reset">Clear</button>
          <div class="composer-footer-spacer"></div>
          <a href="<?= $b ?>/admin/members" class="btn btn-danger">Cancel</a>
        </div>

      </form>
    </div>
  </div>
  
<!-- ──── PREVIEW MODAL ──────────────────────────────────────────────── -->
  <div class="modal-backdrop is-hidden" id="me-preview-modal"
       data-site-name="<?= View::e($site_name_val ?? '') ?>"
       data-from-name="<?= View::e($from_name_val  ?? '') ?>"
       data-from-email="<?= View::e($from_email_val ?? '') ?>">
    <div class="modal modal--wide">
      <div class="modal-header">
        <h3 class="modal-title">Bulk Email Preview</h3>
        <button type="button" class="modal-close" aria-label="Close">&times;</button>
      </div>
      <div class="modal-body">
        <div class="composer-block">
          <div class="composer-row">
            <span class="composer-lbl">To</span>
            <div>
              <span class="composer-name" id="me-preview-to-label"></span>
              <span class="composer-email" id="me-preview-to-count"></span>
            </div>
          </div>
          <div class="composer-row">
            <span class="composer-lbl">From</span>
            <div>
              <span class="composer-name" id="me-preview-from-name"></span>
              <span class="composer-email" id="me-preview-from-email"></span>
            </div>
          </div>
          <div class="composer-row">
            <span class="composer-lbl">Subject</span>
            <span class="composer-val" id="me-preview-subject"></span>
          </div>
        </div>
        <div id="me-preview-body" class="body-area"></div>
      </div>
      <div class="modal-footer" id="me-footer-preview">
        <button class="btn btn-quaternary" id="me-close-preview-btn">Close</button>
      </div>
      <div id="me-footer-confirm" class="is-hidden">
        <div class="me-confirm-warning"><?= View::icon('caution') ?>Are you sure you want to send this email? This action cannot be undone.</div>
        <div class="modal-footer">
          <button type="button" class="btn btn-primary" id="me-confirm-send-btn"><?= View::icon('send') ?> Send</button>
          <button type="button" class="btn btn-quaternary" id="me-confirm-cancel-btn">Cancel</button>
        </div>
      </div>
    </div>
  </div>
</div>

<div id="tab-custom" class="tabs-panel<?= $active_tab === 'custom' ? ' active' : '' ?>">
  <div class="composer-grid">
    <div class="gradient-card">
      <div class="pc-head">
        <div class="pc-head-icon pc-head-icon-bg"><?= View::icon('email') ?></div>
        
    <div class="pc-head-meta">
        <div class="pc-head-title">Add New Template</div>
        <div class="pc-head-sub">Reusable email starting points for bulk messages.</div>
    </div>
      </div>

      <form method="POST" action="<?= $b ?>/admin/new-email-template/save" id="ct-new-form">
        <input type="hidden" name="_csrf"   value="<?= View::e($csrf_token) ?>">
        <input type="hidden" name="referer" value="<?= View::e($b . '/admin/emails?tab=custom') ?>">

        <div class="custom-template-body">

          <div class="form-group custom-template-row">
            <label class="form-label" for="new-tpl-name">Template Name <span class="req">*</span></label>
            <input id="new-tpl-name" type="text" name="tpl_name" class="form"
                   maxlength="150" required placeholder="e.g. Welcome note, Monthly update&hellip;">
          </div>

          <div class="form-group custom-template-row">
            <label class="form-label" for="new-tpl-subject">Subject</label>
            <input id="new-tpl-subject" type="text" name="tpl_subject" class="form"
                   maxlength="255" placeholder="(optional - leave blank to fill in when composing)">
          </div>

          <div class="form-group form-group--compact">
            <label class="form-label" for="new-tpl-body">Body <span class="req">*</span></label>
            <textarea id="new-tpl-body" name="tpl_body" class="form"
                      rows="9" maxlength="50000" required></textarea>
          </div>

          <!-- Live preview panel -->
          <div id="ct-new-preview"
               class="email-preview-panel is-hidden">
            <div class="email-preview-panel-head">
              Preview <span>(example values)</span>
            </div>
            <div class="email-preview-panel-body">
              <div class="email-preview-label">Subject</div>
              <div id="ct-preview-subject" class="email-preview-subject"></div>
              <div class="email-preview-label">Body</div>
              <pre id="ct-preview-body" class="email-preview-pre"></pre>
            </div>
          </div>

        </div>

        <div class="composer-footer">
          <button type="submit" class="btn btn-primary">Save Template</button>
          <button type="button" class="btn btn-preview" id="ct-preview-btn"><?= View::icon('eye-on') ?> Preview</button>
          <button type="button" class="btn btn-ghost js-form-reset">Clear</button>
        </div>

      </form>
    </div>
    
<!-- ──── SIDEBAR: AVAILABLE TOKENS ──────────────────────────────────── -->
    <div class="composer-side">
      <details class="card chevron" open>
        <summary class="chevron-head">Available Tokens</summary>
        <div class="card-body">
          <table class="et-token-table">
            <tbody>
              <tr class="tok-row" data-textarea="new-tpl-body" data-token="{fan_name}"        title="Click to insert"><td><code>{fan_name}</code></td><td>Fan's display name</td></tr>
              <tr class="tok-row" data-textarea="new-tpl-body" data-token="{fan_email}"       title="Click to insert"><td><code>{fan_email}</code></td><td>Fan's email address</td></tr>
              <tr class="tok-row" data-textarea="new-tpl-body" data-token="{fan_url}"         title="Click to insert"><td><code>{fan_url}</code></td><td>Fan's website URL</td></tr>
              <tr class="tok-row" data-textarea="new-tpl-body" data-token="{fan_country}"     title="Click to insert"><td><code>{fan_country}</code></td><td>Fan's country</td></tr>
              <tr class="tok-row" data-textarea="new-tpl-body" data-token="{listing_title}"    title="Click to insert"><td><code>{listing_title}</code></td><td>Name of the listing</td></tr>
              <tr class="tok-row" data-textarea="new-tpl-body" data-token="{listing_subject}" title="Click to insert"><td><code>{listing_subject}</code></td><td>Subject / topic of the listing</td></tr>
              <tr class="tok-row" data-textarea="new-tpl-body" data-token="{listing_url}"     title="Click to insert"><td><code>{listing_url}</code></td><td>URL of the listing</td></tr>
              <tr class="tok-row" data-textarea="new-tpl-body" data-token="{site_name}"       title="Click to insert"><td><code>{site_name}</code></td><td>Collective or site name</td></tr>
              <tr class="tok-row" data-textarea="new-tpl-body" data-token="{owner_name}"      title="Click to insert"><td><code>{owner_name}</code></td><td>Owner's display name</td></tr>
              <tr class="tok-row" data-textarea="new-tpl-body" data-token="{owner_email}"     title="Click to insert"><td><code>{owner_email}</code></td><td>Owner's email address</td></tr>
            </tbody>
          </table>
        </div>
      </details>
    </div>
  </div>
  
<!-- ──── SAVED TEMPLATES TABLE CARD ─────────────────────────────────── -->
  <div class="card">

    <div class="card-head">Saved Templates</div>

    <?php if (empty($saved_emails)): ?>
      <div class="empty">
        <div class="empty-icon"><?= View::icon('email', ['width' => 32, 'height' => 32]) ?></div>
        <div class="empty-title">No saved templates yet.</div>
        <div>Use the form above to create one.</div>
      </div>
    <?php else: ?>
      <div class="table-wrap">
        <table class="data-table">
          <thead>
            <tr>
              <th>Template Name</th>
              <th>Subject</th>
              <th>Created On</th>
              <th class="act-col">Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($saved_emails as $t): ?>
            <tr>
              <td class="text-strong"><?= View::e($t->name) ?></td>
              <td class="text-muted"><?= $t->subject !== '' ? View::e($t->subject) : '<span class="text-dim">-</span>' ?></td>
              <td class="text-muted cell-meta-nowrap">
                <?= View::e(date($admin_date_format, strtotime($t->created_at))) ?>
              </td>
              <td class="act-col">
                <div class="data-actions">
                  <a href="<?= $b ?>/admin/emails?tab=bulk&load=<?= (int) $t->id ?>"
                     class="btn btn-secondary btn-sm">Use</a>
                  <button type="button" class="btn btn-tertiary btn-sm ct-edit-btn"
                          data-id="<?= (int) $t->id ?>"
                          data-name="<?= View::e($t->name) ?>"
                          data-subject="<?= View::e($t->subject) ?>"
                          data-body="<?= htmlspecialchars($t->body, ENT_QUOTES, 'UTF-8') ?>">Edit</button>
                  <form method="POST"
                        action="<?= $b ?>/admin/new-email-template/<?= (int) $t->id ?>/delete"
                        class="action-form-inline"
                        data-del-modal data-del-entity="email_template"
                        data-del-name="<?= View::e($t->name) ?>">
                    <input type="hidden" name="_csrf" value="<?= View::e($csrf_token) ?>">
                    <button type="submit" class="btn btn-danger btn-sm">Delete</button>
                  </form>
                </div>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>

  </div>
  
<!-- ──── EDIT TEMPLATE MODAL ────────────────────────────────────────── -->
  <div class="modal-backdrop is-hidden" id="ct-edit-modal">
    <div class="modal">
      <div class="modal-header">
        <h3 class="modal-title">Edit Template</h3>
        <button type="button" class="modal-close" id="ct-edit-close" aria-label="Close">&times;</button>
      </div>
      <form method="POST" id="ct-edit-form"
            data-base-action="<?= $b ?>/admin/new-email-template/__ID__/update">
        <input type="hidden" name="_csrf" value="<?= View::e($csrf_token) ?>">
        <div class="modal-body">
          <div class="form-group">
            <label class="form-label" for="ct-edit-name">Template Name <span class="req">*</span></label>
            <input type="text" id="ct-edit-name" name="tpl_name" class="form"
                   maxlength="150" required>
          </div>
          <div class="form-group">
            <label class="form-label" for="ct-edit-subject">Subject</label>
            <input type="text" id="ct-edit-subject" name="tpl_subject" class="form"
                   maxlength="255">
          </div>
          <div class="form-group form-group--compact">
            <label class="form-label" for="ct-edit-body">Body <span class="req">*</span></label>
            <textarea id="ct-edit-body" name="tpl_body" class="form"
                      rows="9" maxlength="50000" required></textarea>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-quaternary" id="ct-edit-cancel">Cancel</button>
          <button type="submit" class="btn btn-primary">Save Changes</button>
        </div>
      </form>
    </div>
  </div>

</div>
