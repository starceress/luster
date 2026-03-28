<?php

declare(strict_types=1);

use Luster\Core\View;
use Luster\Repositories\EmailTemplateRepo;

/**
 * Unified Emails page - four tabs.
 *
 * @var string                            $active_tab      'templates'|'bulk'|'custom'|'smtp'
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
 *
 * SMTP tab:
 * @var array                             $cfg
 * @var \Luster\Models\AdminUser|null     $admin_user
 * @var string                            $admin_date_format
 */

$b = rtrim(View::e($base_url), '/');

//  Email Templates tab helpers 
$typeLabels = [
    EmailTemplateRepo::TYPE_JOIN        => 'Join Confirmation',
    EmailTemplateRepo::TYPE_APPROVE     => 'Membership Approved',
    EmailTemplateRepo::TYPE_UPDATE      => 'Update Confirmation',
    EmailTemplateRepo::TYPE_REMOVE      => 'Removal Confirmation',
    EmailTemplateRepo::TYPE_RESET       => 'Password Reset',
    EmailTemplateRepo::TYPE_NOTIFY_JOIN => 'Admin - New Member Notification',
];

$navLabels = [
    EmailTemplateRepo::TYPE_JOIN        => 'Join',
    EmailTemplateRepo::TYPE_APPROVE     => 'Approved',
    EmailTemplateRepo::TYPE_UPDATE      => 'Update',
    EmailTemplateRepo::TYPE_REMOVE      => 'Remove',
    EmailTemplateRepo::TYPE_RESET       => 'Password Reset',
    EmailTemplateRepo::TYPE_NOTIFY_JOIN => 'Admin Notify',
];

$typeHints = [
    EmailTemplateRepo::TYPE_JOIN        => 'Sent to the fan after they successfully join.',
    EmailTemplateRepo::TYPE_APPROVE     => 'Sent when an admin approves a pending membership.',
    EmailTemplateRepo::TYPE_UPDATE      => 'Sent to the fan after they update their details.',
    EmailTemplateRepo::TYPE_REMOVE      => 'Sent to the fan after their membership is removed.',
    EmailTemplateRepo::TYPE_RESET       => 'Sent when a fan requests a password reset. Must include {reset_url}.',
    EmailTemplateRepo::TYPE_NOTIFY_JOIN => 'Sent to this listing\'s admin email when a new fan joins.',
];

$allTokenDesc = [
    '{fan_name}'        => "Fan's display name",
    '{fan_email}'       => "Fan's email address",
    '{fan_url}'         => "Fan's website URL (may be empty)",
    '{fan_country}'     => "Fan's country",
    '{fan_password}'    => "Plain-text password - included once at sign-up only",
    '{reset_url}'       => "Password reset link - required in Reset emails",
    '{listing_name}'    => "Name of the fanlisting",
    '{listing_subject}' => "Subject or topic of the fanlisting",
    '{listing_url}'     => "URL of the fanlisting",
    '{site_name}'       => "Collective or site name",
    '{owner_name}'      => "Admin's display name",
    '{owner_email}'     => "Admin's email address",
];

$commonTokens = ['{fan_name}', '{fan_email}', '{fan_url}', '{fan_country}', '{fan_password}', '{listing_name}', '{listing_subject}', '{listing_url}', '{site_name}', '{owner_name}', '{owner_email}'];
$typeTokens   = array_fill_keys(EmailTemplateRepo::TYPES, $commonTokens);
$typeTokens[EmailTemplateRepo::TYPE_RESET] = array_merge(
    ['{fan_name}', '{fan_email}', '{reset_url}'],
    array_slice($commonTokens, 2)
);

$isGlobal     = ($scope === 'global');
$resetConfirm = $isGlobal
    ? 'Reset this template to the built-in default? Your edits will be lost.'
    : 'Reset this template to the global version? Your per-listing customisation will be removed.';

//  Bulk email tab helpers 
$oldSubjectE = View::e($old_subject ?? '');
$oldBodyE    = htmlspecialchars($old_body ?? '', ENT_QUOTES, 'UTF-8');

//  SMTP tab helper 
if (!function_exists('smtpVal')) {
    function smtpVal(array $cfg, string $key, string $default = ''): string {
        return (string) ($cfg[$key] ?? $default);
    }
}
?>

<?php if ($success !== ''): ?>
  <div class="alert alert-success"><?= View::e($success) ?></div>
<?php endif; ?>
<?php if ($error !== ''): ?>
  <div class="alert alert-error"><?= View::e($error) ?></div>
<?php endif; ?>

<!--  Tab bar  -->
<div class="neb-tabs-wrap">
  <div class="neb-tabs">
    <button class="neb-tab-btn<?= $active_tab === 'templates' ? ' active' : '' ?>" data-tab="templates">Email Templates</button>
    <button class="neb-tab-btn<?= $active_tab === 'bulk'      ? ' active' : '' ?>" data-tab="bulk">Bulk Email</button>
    <button class="neb-tab-btn<?= $active_tab === 'custom'    ? ' active' : '' ?>" data-tab="custom">Custom Templates</button>
    <button class="neb-tab-btn<?= $active_tab === 'smtp'      ? ' active' : '' ?>" data-tab="smtp">SMTP Settings</button>
  </div>
  <div class="select-wrap neb-tabs-select-wrap">
    <select class="neb-tabs-select select">
      <option value="templates"<?= $active_tab === 'templates' ? ' selected' : '' ?>>Email Templates</option>
      <option value="bulk"<?=      $active_tab === 'bulk'      ? ' selected' : '' ?>>Bulk Email</option>
      <option value="custom"<?=    $active_tab === 'custom'    ? ' selected' : '' ?>>Custom Templates</option>
      <option value="smtp"<?=      $active_tab === 'smtp'      ? ' selected' : '' ?>>SMTP Settings</option>
    </select>
  </div>
</div>

<!--  -->
<!-- TAB: EMAIL TEMPLATES                                                  -->
<!--  -->
<div id="tab-templates" class="neb-tab-panel<?= $active_tab === 'templates' ? ' active' : '' ?>">

  <!-- Scope selector -->
  <div class="et-scope">
    <form method="GET" action="<?= $b ?>/admin/emails" style="display:contents">
      <input type="hidden" name="tab" value="templates">
      <div class="select-wrap">
        <select name="listing" class="form-select js-autosubmit" style="min-width:220px">
          <option value="global"<?= $isGlobal ? ' selected' : '' ?>>
            Global (default for all listings)
          </option>
          <?php if (!empty($all_listings)): ?>
            <option disabled></option>
            <?php foreach ($all_listings as $l): ?>
              <?php $sel = (!$isGlobal && (int) $scope === $l->id); ?>
              <option value="<?= (int) $l->id ?>"<?= $sel ? ' selected' : '' ?>>
                <?= View::e($l->name) ?>
              </option>
            <?php endforeach; ?>
          <?php endif; ?>
        </select>
      </div>
    </form>

    <?php if (!$isGlobal && $listing !== null): ?>
      <span class="overview-section-sub2">
        Cards showing <span class="badge badge-info">Customised</span> override the global template.
        Cards showing <span class="badge badge-subtle">Using Global</span> inherit global templates.
      </span>
    <?php else: ?>
      <span class="overview-section-sub2">
        Global templates apply to all listings unless a per-listing override exists.
      </span>
    <?php endif; ?>
  </div>

  <!-- Preview token data (read by admin.js) -->
  <div id="et-preview-data"
       data-site-name="<?= View::e($site_name_val) ?>"
       data-owner-name="<?= View::e($owner_name) ?>"
       data-owner-email="<?= View::e($owner_email) ?>"
       data-listing-name="<?= View::e($listing?->name ?? 'My Fanlisting') ?>"
       data-listing-subject="<?= View::e($listing?->subject ?? 'Music') ?>"
       data-listing-url="<?= View::e($listing?->url ?? 'https://myfanlisting.com') ?>"
       data-is-global="<?= $isGlobal ? '1' : '0' ?>"
       style="display:none"></div>

  <!-- Left-nav + panels layout -->
  <form id="et-save-form" method="POST" action="<?= $b ?>/admin/email-templates/save">
    <input type="hidden" name="_csrf"  value="<?= View::e($csrf_token) ?>">
    <input type="hidden" name="scope"  value="<?= View::e($scope) ?>">

    <div class="et-layout">

      <!-- Left nav -->
      <nav class="panel-card">
        <div class="card-head"><div class="card-head-title">Template Name</div></div>
        <div class="panel-body">
          <?php foreach (EmailTemplateRepo::TYPES as $i => $type): ?>
            <button type="button"
                    class="mini-nav-item<?= $i === 0 ? ' active' : '' ?>"
                    data-et-tab="<?= View::e($type) ?>">
              <?= View::e($navLabels[$type]) ?>
              <?php if (!$isGlobal && isset($cards[$type]) && $cards[$type]['isCustomised']): ?>
                <span class="badge badge-info" style="margin-left:auto;font-size:.65rem;padding:.1rem .35rem">€¢</span>
              <?php endif; ?>
            </button>
          <?php endforeach; ?>
        </div>
      </nav>

      <!-- Template panels -->
      <div class="et-content">
        <?php foreach (EmailTemplateRepo::TYPES as $i => $type):
          $card = $cards[$type];
        ?>
        <div class="et-panel<?= $i === 0 ? ' active' : '' ?>"
             id="et-tab-<?= View::e($type) ?>">

          <div class="card<?= !$card['isEnabled'] ? ' et-card-disabled' : '' ?>" style="overflow:hidden">

            <!-- Card header -->
            <div class="card-head">
              <div>
                <div class="card-title"><?= View::e($typeLabels[$type]) ?></div>
                <div class="card-subtitle"><?= View::e($typeHints[$type]) ?></div>
              </div>
              <div style="display:flex;align-items:center;gap:.6rem;flex-shrink:0">
                <?php if (!$isGlobal): ?>
                  <?php if ($card['isCustomised']): ?>
                    <span class="badge badge-info">Customised</span>
                  <?php else: ?>
                    <span class="badge badge-subtle">Using global</span>
                  <?php endif; ?>
                <?php endif; ?>
                <!-- Enabled toggle switch -->
                <label class="et-toggle-wrap" title="Toggle whether this email is sent">
                  <input type="checkbox"
                         class="et-toggle-input et-enabled-cb"
                         name="email_enabled_<?= View::e($type) ?>"
                         value="1"
                         <?= $card['isEnabled'] ? 'checked' : '' ?>>
                  <span class="et-toggle-track"></span>
                  <span class="et-toggle-label"><?= $card['isEnabled'] ? 'Enabled' : 'Disabled' ?></span>
                </label>
              </div>
            </div>

            <!-- Disabled warning banner -->
            <div class="et-disabled-banner"<?= $card['isEnabled'] ? ' style="display:none"' : '' ?>>
              <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><rect x="11" y="8.5" width="2" height="5.5" rx="1" fill="currentColor" stroke="none"/><circle cx="12" cy="16.5" r="1.1" fill="currentColor" stroke="none"/></svg>
              This template is <strong>disabled</strong> - no email(s) will be sent for this event.
            </div>

            <!-- Fields -->
            <div class="et-fields" style="padding:1.25rem">
              <div class="form-group" style="margin-bottom:.875rem">
                <label class="form-label" for="et-subject-<?= View::e($type) ?>">Subject</label>
                <input class="form-input et-subject"
                       type="text"
                       id="et-subject-<?= View::e($type) ?>"
                       name="email_subject_<?= View::e($type) ?>"
                       value="<?= View::e($card['fillSubject']) ?>"
                       maxlength="255"
                       data-type="<?= View::e($type) ?>">
              </div>
              <div class="form-group">
                <label class="form-label" for="et-body-<?= View::e($type) ?>">Body</label>
                <textarea class="form-textarea et-body"
                          id="et-body-<?= View::e($type) ?>"
                          name="email_body_<?= View::e($type) ?>"
                          rows="9"
                          style="font-family:'DM Mono',monospace;font-size:.8125rem"
                          data-type="<?= View::e($type) ?>"><?= View::e($card['fillBody']) ?></textarea>
              </div>

              <!-- Live preview panel -->
              <div class="et-preview-panel" id="et-preview-<?= View::e($type) ?>"
                   style="display:none;margin-top:1rem;border:1px solid var(--border);border-radius:6px;overflow:hidden">
                <div style="padding:.5rem .875rem;background:var(--surface-alt,var(--sidebar));border-bottom:1px solid var(--border);font-size:.72rem;font-weight:600;color:var(--text-muted);text-transform:uppercase;letter-spacing:.04em">
                  Preview <span style="font-weight:400;text-transform:none;letter-spacing:0">(example values)</span>
                  <?php if ($isGlobal): ?>
                    <span style="color:var(--text-dim);margin-left:.5rem">· listing tokens show generic placeholders</span>
                  <?php endif; ?>
                </div>
                <div style="padding:.875rem 1rem">
                  <div style="font-size:.8rem;color:var(--text-muted);margin-bottom:.35rem;font-weight:600">Subject</div>
                  <div class="et-preview-subject" style="font-family:'DM Mono',monospace;font-size:.82rem;color:var(--text);margin-bottom:.875rem;padding:.4rem .6rem;background:var(--surface-alt,var(--sidebar));border-radius:4px"></div>
                  <div style="font-size:.8rem;color:var(--text-muted);margin-bottom:.35rem;font-weight:600">Body</div>
                  <pre class="et-preview-body" style="font-family:'DM Mono',monospace;font-size:.8rem;color:var(--text);white-space:pre-wrap;word-break:break-word;margin:0;padding:.6rem;background:var(--surface-alt,var(--sidebar));border-radius:4px;line-height:1.6"></pre>
                </div>
              </div>
            </div>

            <!-- Card footer: action buttons right-aligned -->
            <div class="card-footer">
              <button type="submit" class="btn btn-primary">Save Template</button>
              <button type="button" class="btn btn-quaternary btn-sm et-preview-toggle"
                      data-type="<?= View::e($type) ?>">Preview</button>
              <button type="submit"
                      form="et-reset-<?= View::e($type) ?>"
                      class="btn btn-quaternary btn-sm"
                      style="color:var(--text-dim)">
                <?= $isGlobal ? 'Reset to Default' : 'Reset to Global' ?>
              </button>
            </div>

          </div><!-- /.card -->

          <!-- Available Tokens - outside the card -->
          <details class="form-details" style="margin-top:.75rem">
            <summary>Available Tokens</summary>
            <div class="form-details-body" style="padding-bottom:.75rem">
              <table class="et-token-table">
                <thead>
                  <tr>
                    <th>Token</th>
                    <th>Description</th>
                  </tr>
                </thead>
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
        </div><!-- /.et-panel -->
        <?php endforeach; ?>
      </div><!-- /.et-content -->

    </div><!-- /.et-layout -->
  </form>

  <!-- Reset forms (one per type, out-of-flow; targeted by form= attribute above) -->
  <?php foreach (EmailTemplateRepo::TYPES as $type): ?>
  <form id="et-reset-<?= View::e($type) ?>"
        method="POST"
        action="<?= $b ?>/admin/email-templates/reset"
        data-confirm="<?= View::e($resetConfirm) ?>"
        style="display:none">
    <input type="hidden" name="_csrf"  value="<?= View::e($csrf_token) ?>">
    <input type="hidden" name="scope"  value="<?= View::e($scope) ?>">
    <input type="hidden" name="type"   value="<?= View::e($type) ?>">
  </form>
  <?php endforeach; ?>

</div><!-- /#tab-templates -->

<!--  -->
<!-- TAB: BULK EMAIL                                                       -->
<!--  -->
<div id="tab-bulk" class="neb-tab-panel<?= $active_tab === 'bulk' ? ' active' : '' ?>">

  <div class="composer-grid">

    <!-- Main compose area -->
    <div class="card composer-card">
      <form method="POST" action="<?= $b ?>/admin/emails/preview" id="compose-form">
        <input type="hidden" name="_csrf" value="<?= View::e($csrf_token) ?>">

        <div class="composer-header">
          <span class="composer-header-label">Bulk Message</span>
          <span class="listing-chip" id="me-listing-chip">
            <span class="listing-chip-dot"></span>
            <span id="me-chip-label">All Listings</span>
          </span>
        </div>

        <div class="composer-section">
          <p class="ec-intro" style="margin:0">
            Send a personalised email to all approved members of one listing, or every listing at once.
            Recipients are deduplicated &mdash; each address receives at most one message.
          </p>
        </div>

        <div class="composer-section composer-section--inline">
          <label class="composer-section-label" for="me-listing">Send To</label>
          <div class="select-wrap">
            <select name="listing_id" id="me-listing" class="select">
              <option value="0"
                      data-count="<?= (int) ($deduplicated_total ?? 0) ?>"
                      data-label="All Listings"
                      <?= ($old_listing_id ?? 0) === 0 ? ' selected' : '' ?>>
                All listings (deduplicated)
              </option>
              <?php foreach ($listings as $lst): ?>
                <option value="<?= (int) $lst->id ?>"
                        data-count="<?= (int) ($listing_counts[$lst->id] ?? 0) ?>"
                        data-label="<?= View::e($lst->name) ?>"
                        data-subject="<?= View::e($lst->subject) ?>"
                        data-url="<?= View::e($lst->url) ?>"
                        <?= ($old_listing_id ?? 0) === (int) $lst->id ? ' selected' : '' ?>>
                  <?= View::e($lst->name) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>

        <div class="composer-section composer-section--inline">
          <label class="composer-section-label" for="me-subject">
            Subject <span class="req">*</span>
          </label>
          <input id="me-subject" type="text" name="subject" class="input"
                 maxlength="255" required value="<?= $oldSubjectE ?>">
        </div>

        <div class="body-area">
          <label class="body-area-label" for="me-body">
            Message Body <span class="req">*</span>
          </label>
          <textarea class="body-textarea" id="me-body" name="body"
                    maxlength="50000" required
                    style="font-family:'DM Mono',monospace"><?= $oldBodyE ?></textarea>
          <span class="char-count" id="me-char-count">0 chars</span>
        </div>

        <div class="composer-footer">
          <button type="button" class="btn btn-primary" id="me-send-btn">Send &rarr;</button>
          <button type="button" class="btn btn-ghost" id="me-preview-btn">Preview</button>
          <button type="button" class="btn btn-ghost js-form-reset">Clear</button>
          <div style="flex:1"></div>
          <a href="<?= $b ?>/admin/members" class="btn btn-ghost btn-sm">Cancel</a>
        </div>

      </form>
    </div><!-- /.composer-card -->

    <!-- Sidebar -->
    <div class="composer-side">

      <?php if (!empty($saved_emails) || !empty($global_email_templates)): ?>
      <div class="card side-card">
        <div class="side-card-head">
          <svg class="side-card-icon" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5">
            <rect x="2" y="3" width="12" height="10" rx="1.5"/><path d="M2 6.5h12"/>
          </svg>
          <span class="side-card-title">Load Template</span>
          <a href="<?= $b ?>/admin/emails?tab=custom" class="btn btn-ghost btn-sm" style="margin-left:auto;padding:.2rem .6rem;font-size:.72rem">Manage &rarr;</a>
        </div>
        <div class="side-card-body">
          <div class="select-wrap">
            <select id="me-tpl-select" class="select">
              <option value="">- Select a template -</option>
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
          <form method="POST" action="<?= $b ?>/admin/new-email-template/save" id="me-save-tpl-form">
            <input type="hidden" name="_csrf"       value="<?= View::e($csrf_token) ?>">
            <input type="hidden" name="referer"     value="<?= View::e($b . '/admin/emails?tab=bulk') ?>">
            <input type="hidden" name="tpl_subject" id="me-save-subject">
            <input type="hidden" name="tpl_body"    id="me-save-body">
            <input type="text"   name="tpl_name"    id="me-save-name"
                   class="input" placeholder="Template name&hellip;" maxlength="150">
            <button type="submit" class="btn-save-tpl" id="me-save-btn">
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
              <tr class="tok-row" data-textarea="me-body" data-token="{fan_name}"        title="Click to insert"><td><code>{fan_name}</code></td><td>Fan's display name</td></tr>
              <tr class="tok-row" data-textarea="me-body" data-token="{fan_email}"       title="Click to insert"><td><code>{fan_email}</code></td><td>Fan's email address</td></tr>
              <tr class="tok-row" data-textarea="me-body" data-token="{fan_url}"         title="Click to insert"><td><code>{fan_url}</code></td><td>Fan's website URL</td></tr>
              <tr class="tok-row" data-textarea="me-body" data-token="{fan_country}"     title="Click to insert"><td><code>{fan_country}</code></td><td>Fan's country</td></tr>
              <tr class="tok-row" data-textarea="me-body" data-token="{listing_name}"    title="Click to insert"><td><code>{listing_name}</code></td><td>Name of the listing</td></tr>
              <tr class="tok-row" data-textarea="me-body" data-token="{listing_subject}" title="Click to insert"><td><code>{listing_subject}</code></td><td>Subject or topic of the listing</td></tr>
              <tr class="tok-row" data-textarea="me-body" data-token="{listing_url}"     title="Click to insert"><td><code>{listing_url}</code></td><td>URL of the listing</td></tr>
              <tr class="tok-row" data-textarea="me-body" data-token="{site_name}"       title="Click to insert"><td><code>{site_name}</code></td><td>Collective or site name</td></tr>
              <tr class="tok-row" data-textarea="me-body" data-token="{owner_name}"      title="Click to insert"><td><code>{owner_name}</code></td><td>Owner's display name</td></tr>
              <tr class="tok-row" data-textarea="me-body" data-token="{owner_email}"     title="Click to insert"><td><code>{owner_email}</code></td><td>Owner's email address</td></tr>
            </tbody>
          </table>
        </div>
      </details>

    </div><!-- /.composer-side -->

  </div><!-- /.composer-grid -->

  <!-- Preview modal -->
  <div class="modal-backdrop" id="me-preview-modal" style="display:none"
       data-site-name="<?= View::e($site_name_val ?? '') ?>"
       data-from-name="<?= View::e($from_name_val  ?? '') ?>"
       data-from-email="<?= View::e($from_email_val ?? '') ?>">
    <div class="modal modal--wide">
      <div class="modal-header">
        <h3 class="modal-title">Bulk Email Preview</h3>
      </div>
      <div class="modal-body">
        <div class="addr-block" style="margin: -.25rem -1.5rem .75rem;border-top:1px solid var(--border-soft)">
          <div class="addr-row">
            <span class="addr-lbl">To</span>
            <div>
              <span class="addr-name" id="me-preview-to-label"></span>
              <span class="addr-email" id="me-preview-to-count"></span>
            </div>
          </div>
          <div class="addr-row">
            <span class="addr-lbl">From</span>
            <div>
              <span class="addr-name" id="me-preview-from-name"></span>
              <span class="addr-email" id="me-preview-from-email"></span>
            </div>
          </div>
          <div class="addr-row">
            <span class="addr-lbl">Subject</span>
            <span class="addr-val" id="me-preview-subject"></span>
          </div>
        </div>
        <div id="me-preview-body" style="white-space:pre-wrap;font-size:.875rem;color:var(--text)"></div>
      </div>
      <div class="modal-footer" id="me-footer-preview">
        <button class="btn btn-ghost" id="me-close-preview-btn">Close</button>
      </div>
      <div id="me-footer-confirm" style="display:none">
        <div class="me-confirm-warning">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><rect x="11" y="8.5" width="2" height="5.5" rx="1" fill="currentColor" stroke="none"/><circle cx="12" cy="16.5" r="1.1" fill="currentColor" stroke="none"/></svg>
          Are you sure you want to send this email to all recipients? This action cannot be undone.
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-ghost" id="me-confirm-cancel-btn">Cancel</button>
          <button type="button" class="btn btn-primary" id="me-confirm-send-btn">Send &rarr;</button>
        </div>
      </div>
    </div>
  </div>

</div><!-- /#tab-bulk -->

<!--  -->
<!-- TAB: CUSTOM TEMPLATES                                                 -->
<!--  -->
<div id="tab-custom" class="neb-tab-panel<?= $active_tab === 'custom' ? ' active' : '' ?>">

  <div class="composer-grid">

    <!-- Main: Add New Template form -->
    <div class="card composer-card" style="overflow:hidden">

      <div class="card-head">
        <div>
          <div class="card-title">Add New Template</div>
          <div class="card-subtitle">Reusable email starting points for bulk messages.</div>
        </div>
      </div>

      <form method="POST" action="<?= $b ?>/admin/new-email-template/save" id="ct-new-form">
        <input type="hidden" name="_csrf"   value="<?= View::e($csrf_token) ?>">
        <input type="hidden" name="referer" value="<?= View::e($b . '/admin/emails?tab=custom') ?>">

        <div style="padding:1.25rem 1.5rem">

          <div class="form-group">
            <label class="form-label" for="new-tpl-name">Template Name <span class="req">*</span></label>
            <input id="new-tpl-name" type="text" name="tpl_name" class="form-input"
                   maxlength="150" required placeholder="e.g. Welcome note, Monthly update&hellip;">
          </div>

          <div class="form-group">
            <label class="form-label" for="new-tpl-subject">Subject</label>
            <input id="new-tpl-subject" type="text" name="tpl_subject" class="form-input"
                   maxlength="255" placeholder="(optional - leave blank to fill in when composing)">
          </div>

          <div class="form-group" style="margin-bottom:0">
            <label class="form-label" for="new-tpl-body">Body <span class="req">*</span></label>
            <textarea id="new-tpl-body" name="tpl_body" class="form-textarea"
                      rows="9" maxlength="50000" required
                      style="font-family:'DM Mono',monospace;font-size:.8125rem"></textarea>
          </div>

          <!-- Live preview panel -->
          <div id="ct-new-preview"
               style="display:none;margin-top:1rem;border:1px solid var(--border);border-radius:6px;overflow:hidden">
            <div style="padding:.5rem .875rem;background:var(--surface-alt,var(--sidebar));border-bottom:1px solid var(--border);font-size:.72rem;font-weight:600;color:var(--text-muted);text-transform:uppercase;letter-spacing:.04em">
              Preview <span style="font-weight:400;text-transform:none;letter-spacing:0">(example values)</span>
            </div>
            <div style="padding:.875rem 1rem">
              <div style="font-size:.8rem;color:var(--text-muted);margin-bottom:.35rem;font-weight:600">Subject</div>
              <div id="ct-preview-subject" style="font-family:'DM Mono',monospace;font-size:.82rem;color:var(--text);margin-bottom:.875rem;padding:.4rem .6rem;background:var(--surface-alt,var(--sidebar));border-radius:4px"></div>
              <div style="font-size:.8rem;color:var(--text-muted);margin-bottom:.35rem;font-weight:600">Body</div>
              <pre id="ct-preview-body" style="font-family:'DM Mono',monospace;font-size:.8rem;color:var(--text);white-space:pre-wrap;word-break:break-word;margin:0;padding:.6rem;background:var(--surface-alt,var(--sidebar));border-radius:4px;line-height:1.6"></pre>
            </div>
          </div>

        </div>

        <div class="card-footer">
          <button type="submit" class="btn btn-primary">Save Template</button>
          <button type="button" class="btn btn-quaternary btn-sm" id="ct-preview-btn">Preview</button>
          <button type="button" class="btn btn-quaternary btn-sm js-form-reset"
                  style="color:var(--text-dim)">Clear</button>
        </div>

      </form>
    </div><!-- /.composer-card -->

    <!-- Sidebar: Available Tokens -->
    <div class="composer-side">
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
              <tr class="tok-row" data-textarea="new-tpl-body" data-token="{fan_name}"        title="Click to insert"><td><code>{fan_name}</code></td><td>Fan's display name</td></tr>
              <tr class="tok-row" data-textarea="new-tpl-body" data-token="{fan_email}"       title="Click to insert"><td><code>{fan_email}</code></td><td>Fan's email address</td></tr>
              <tr class="tok-row" data-textarea="new-tpl-body" data-token="{fan_url}"         title="Click to insert"><td><code>{fan_url}</code></td><td>Fan's website URL</td></tr>
              <tr class="tok-row" data-textarea="new-tpl-body" data-token="{fan_country}"     title="Click to insert"><td><code>{fan_country}</code></td><td>Fan's country</td></tr>
              <tr class="tok-row" data-textarea="new-tpl-body" data-token="{listing_name}"    title="Click to insert"><td><code>{listing_name}</code></td><td>Name of the listing</td></tr>
              <tr class="tok-row" data-textarea="new-tpl-body" data-token="{listing_subject}" title="Click to insert"><td><code>{listing_subject}</code></td><td>Subject / topic of the listing</td></tr>
              <tr class="tok-row" data-textarea="new-tpl-body" data-token="{listing_url}"     title="Click to insert"><td><code>{listing_url}</code></td><td>URL of the listing</td></tr>
              <tr class="tok-row" data-textarea="new-tpl-body" data-token="{site_name}"       title="Click to insert"><td><code>{site_name}</code></td><td>Collective or site name</td></tr>
              <tr class="tok-row" data-textarea="new-tpl-body" data-token="{owner_name}"      title="Click to insert"><td><code>{owner_name}</code></td><td>Owner's display name</td></tr>
              <tr class="tok-row" data-textarea="new-tpl-body" data-token="{owner_email}"     title="Click to insert"><td><code>{owner_email}</code></td><td>Owner's email address</td></tr>
            </tbody>
          </table>
        </div>
      </details>
    </div><!-- /.composer-side -->

  </div><!-- /.composer-grid -->

  <!-- Divider -->
  <div class="section-divider">Saved Templates</div>

  <!-- Saved Templates table card -->
  <div class="card" style="overflow:hidden">

    <div class="card-head">
      <div class="card-title">Saved Templates</div>
    </div>

    <?php if (empty($saved_emails)): ?>
      <div class="empty">
        <svg class="empty-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
          <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>
        </svg>
        No saved templates yet. Use the form above to create one, or save a draft while composing.
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
              <td class="text-muted" style="white-space:nowrap">
                <?= View::e(date($admin_date_format, strtotime($t->created_at))) ?>
              </td>
              <td class="act-col">
                <div class="row-actions">
                  <a href="<?= $b ?>/admin/emails?tab=bulk&load=<?= (int) $t->id ?>"
                     class="btn btn-quaternary btn-sm">Use</a>
                  <button type="button" class="btn btn-quaternary btn-sm ct-edit-btn"
                          data-id="<?= (int) $t->id ?>"
                          data-name="<?= View::e($t->name) ?>"
                          data-subject="<?= View::e($t->subject) ?>"
                          data-body="<?= htmlspecialchars($t->body, ENT_QUOTES, 'UTF-8') ?>">Edit</button>
                  <form method="POST"
                        action="<?= $b ?>/admin/new-email-template/<?= (int) $t->id ?>/delete"
                        style="display:contents"
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

  </div><!-- /.card saved templates -->

  <!-- Edit Template modal -->
  <div class="modal-backdrop" id="ct-edit-modal" style="display:none">
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
            <input type="text" id="ct-edit-name" name="tpl_name" class="form-input"
                   maxlength="150" required>
          </div>
          <div class="form-group">
            <label class="form-label" for="ct-edit-subject">Subject</label>
            <input type="text" id="ct-edit-subject" name="tpl_subject" class="form-input"
                   maxlength="255">
          </div>
          <div class="form-group" style="margin-bottom:0">
            <label class="form-label" for="ct-edit-body">Body <span class="req">*</span></label>
            <textarea id="ct-edit-body" name="tpl_body" class="form-textarea"
                      rows="9" maxlength="50000" required
                      style="font-family:'DM Mono',monospace;font-size:.8125rem"></textarea>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-ghost" id="ct-edit-cancel">Cancel</button>
          <button type="submit" class="btn btn-primary">Save Changes</button>
        </div>
      </form>
    </div>
  </div>

</div><!-- /#tab-custom -->

<!--  -->
<!-- TAB: SMTP SETTINGS                                                    -->
<!--  -->
<div id="tab-smtp" class="neb-tab-panel<?= $active_tab === 'smtp' ? ' active' : '' ?>">

  <!-- Informational callout -->
  <div style="display:flex;align-items:flex-start;gap:.75rem;padding:.875rem 1.1rem;margin-bottom:1.25rem;
              background:rgba(120,232,216,.06);border:1px solid rgba(120,232,216,.18);
              border-left:3px solid var(--accent);border-radius:0 10px 10px 0">
    <svg style="flex-shrink:0;margin-top:.1rem;color:var(--accent)" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
      <circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/>
    </svg>
    <span style="font-size:.78rem;color:var(--text-muted);line-height:1.6">
      Leave <strong style="color:var(--text-muted);font-family:'DM Mono',monospace;font-size:.75rem">SMTP Host</strong> blank to send via PHP&rsquo;s native <code>mail()</code> function.
      All emails use the From Address below; if left blank, the Owner Email from General settings is used as a fallback.
    </span>
  </div>

  <?php if (smtpVal($cfg, 'smtp_from') === '' && smtpVal($cfg, 'owner_email') === ''): ?>
  <div class="alert alert-warning" style="margin-bottom:1.25rem">
    <strong>From address not set.</strong>
    Outgoing emails have no sender address and will likely be rejected by your mail server.
    Set a From Address below, or set the Owner Email in <a href="<?= $b ?>/admin/settings" class="link">General settings</a>.
  </div>
  <?php endif; ?>

  <form method="POST" action="<?= $b ?>/admin/emails/smtp-settings/save">
  <input type="hidden" name="_csrf" value="<?= View::e($csrf_token) ?>">

  <div class="card listing-field-card" style="margin-bottom:1.25rem">

    <!-- Server -->
    <div class="listing-field-section">
      <div class="section-divider" style="margin-top:0;margin-bottom:1.125rem">Server</div>

      <div class="listing-field-row listing-field-row--top">
        <label class="listing-field-label" for="s-smtp-host">SMTP Host</label>
        <div>
          <input id="s-smtp-host" type="text" name="smtp_host" class="form-input"
                 maxlength="255" placeholder="smtp.example.com"
                 value="<?= View::e(smtpVal($cfg, 'smtp_host')) ?>">
          <p class="form-hint">Leave blank to use PHP&rsquo;s native <code>mail()</code> function.</p>
        </div>
      </div>

      <div class="listing-field-row listing-field-row--top">
        <label class="listing-field-label" for="s-smtp-port">SMTP Port</label>
        <div>
          <input id="s-smtp-port" type="number" name="smtp_port" class="form-input"
                 min="1" max="65535" style="max-width:140px"
                 value="<?= (int) ($cfg['smtp_port'] ?? 587) ?>">
          <p class="form-hint">Common ports: 25 (plain), 465 (SSL), 587 (STARTTLS).</p>
        </div>
      </div>

      <div class="listing-field-row listing-field-row--top">
        <label class="listing-field-label" for="s-smtp-secure">Encryption</label>
        <div>
          <div class="select-wrap" style="max-width:260px">
            <select id="s-smtp-secure" name="smtp_secure" class="form-select">
              <?php foreach (['tls' => 'TLS (STARTTLS)', 'ssl' => 'SSL', '' => 'None'] as $val => $lbl): ?>
                <option value="<?= View::e($val) ?>"
                  <?= smtpVal($cfg, 'smtp_secure', 'tls') === $val ? 'selected' : '' ?>>
                  <?= View::e($lbl) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <p class="form-hint">Use TLS (STARTTLS) on port 587, SSL on port 465, or None on port 25.</p>
        </div>
      </div>
    </div><!-- /.listing-field-section (server) -->

    <!-- Authentication -->
    <div class="listing-field-section" style="border-top:1px solid var(--border)">
      <div class="section-divider" style="margin-top:0;margin-bottom:1.125rem">Authentication</div>

      <div class="listing-field-row">
        <label class="listing-field-label" for="s-smtp-user">Username</label>
        <input id="s-smtp-user" type="text" name="smtp_user" class="form-input"
               maxlength="255" autocomplete="off" placeholder="your@email.com"
               value="<?= View::e(smtpVal($cfg, 'smtp_user')) ?>">
      </div>

      <div class="listing-field-row listing-field-row--top">
        <label class="listing-field-label" for="s-smtp-pass">Password</label>
        <div>
          <div class="input-icon-wrap">
            <input id="s-smtp-pass" type="password" name="smtp_pass" class="form-input"
                   maxlength="500" autocomplete="new-password"
                   placeholder="Leave blank to keep current">
            <button type="button" class="input-icon-btn" aria-label="Show password" tabindex="-1">
              <svg class="eye-on" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
              <svg class="eye-off" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" style="display:none"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
            </button>
          </div>
          <p class="form-hint">Only fill in to change the stored password.</p>
        </div>
      </div>
    </div><!-- /.listing-field-section (auth) -->

    <!-- Sender -->
    <div class="listing-field-section" style="border-top:1px solid var(--border)">
      <div class="section-divider" style="margin-top:0;margin-bottom:1.125rem">Sender</div>

      <div class="listing-field-row listing-field-row--top">
        <label class="listing-field-label" for="s-smtp-from">From Address</label>
        <div>
          <input id="s-smtp-from" type="email" name="smtp_from" class="form-input"
                 maxlength="255" placeholder="noreply@yoursite.com"
                 value="<?= View::e(smtpVal($cfg, 'smtp_from')) ?>">
          <p class="form-hint">The &ldquo;From&rdquo; email on all outgoing messages. Defaults to Owner Email if left blank.</p>
        </div>
      </div>

      <div class="listing-field-row listing-field-row--top">
        <label class="listing-field-label" for="s-smtp-from-name">From Name</label>
        <div>
          <input id="s-smtp-from-name" type="text" name="smtp_from_name" class="form-input"
                 maxlength="255" placeholder="Your Site Name"
                 value="<?= View::e(smtpVal($cfg, 'smtp_from_name')) ?>">
          <p class="form-hint">The display name shown alongside the From Address in mail clients.</p>
        </div>
      </div>
    </div><!-- /.listing-field-section (sender) -->

    <div class="composer-footer">
      <!-- Validate + feedback (left) -->
      <button type="button" class="btn btn-quaternary btn-sm" id="smtp-validate-btn"
              data-url="<?= $b ?>/admin/settings/validate-smtp">
        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>
        Validate connection
      </button>
      <span id="smtp-validate-result" style="font-size:.78rem;display:none"></span>

      <div style="flex:1"></div>

      <!-- Test email + Save (right) -->
      <div class="test-email-row">
        <button type="button" form="smtp-test-form-trigger" class="btn btn-ghost btn-sm"
                onclick="document.getElementById('smtp-test-form').submit()">Send test email</button>
        <?php if (!empty($admin_user->email)): ?>
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><polyline points="9 18 15 12 9 6"/></svg>
          <span class="test-email-addr"><?= View::e($admin_user->email) ?></span>
        <?php endif; ?>
      </div>

      <button type="submit" class="btn btn-primary">Save Settings</button>
    </div>

  </div><!-- /.listing-field-card -->

  </form>

  <form id="smtp-test-form" method="POST" action="<?= $b ?>/admin/settings/test-email">
    <input type="hidden" name="_csrf" value="<?= View::e($csrf_token) ?>">
  </form>

</div><!-- /#tab-smtp -->
