<?php

declare(strict_types=1);

use Luster\Core\View;
use Luster\Repositories\EmailTemplateRepo;

/**
 * @var \Luster\Models\Listing[]         $all_listings
 * @var string                            $scope          'global' or listing ID as string
 * @var \Luster\Models\Listing|null       $listing        null when scope = global
 * @var array<string, array>              $cards          Pre-computed per-type display data
 * @var string                            $owner_name
 * @var string                            $owner_email
 * @var string                            $site_name
 * @var string                            $success
 * @var string                            $error
 * @var string                            $base_url
 * @var string                            $csrf_token
 */

$b = rtrim(View::e($base_url), '/');

$typeLabels = [
    EmailTemplateRepo::TYPE_JOIN        => 'Join Confirmation',
    EmailTemplateRepo::TYPE_APPROVE     => 'Membership Approved',
    EmailTemplateRepo::TYPE_UPDATE      => 'Update Confirmation',
    EmailTemplateRepo::TYPE_REMOVE      => 'Removal Confirmation',
    EmailTemplateRepo::TYPE_RESET       => 'Password Reset',
    EmailTemplateRepo::TYPE_NOTIFY_JOIN => 'Admin — New Member Notification',
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

// All token descriptions
$allTokenDesc = [
    '{fan_name}'        => "Fan's display name",
    '{fan_email}'       => "Fan's email address",
    '{fan_url}'         => "Fan's website URL (may be empty)",
    '{fan_country}'     => "Fan's country",
    '{fan_password}'    => "Plain-text password — included once at sign-up only",
    '{reset_url}'       => "Password reset link — required in Reset emails",
    '{listing_name}'    => "Name of the fanlisting",
    '{listing_subject}' => "Subject or topic of the fanlisting",
    '{listing_url}'     => "URL of the fanlisting",
    '{site_name}'       => "Collective or site name",
    '{owner_name}'      => "Admin's display name",
    '{owner_email}'     => "Admin's email address",
];

// Tokens available per type (ordered)
$typeTokens = [
    EmailTemplateRepo::TYPE_JOIN        => ['{fan_name}', '{fan_email}', '{fan_url}', '{fan_country}', '{fan_password}', '{listing_name}', '{listing_subject}', '{listing_url}', '{site_name}', '{owner_name}', '{owner_email}'],
    EmailTemplateRepo::TYPE_APPROVE     => ['{fan_name}', '{fan_email}', '{listing_name}', '{listing_subject}', '{listing_url}', '{site_name}', '{owner_name}', '{owner_email}'],
    EmailTemplateRepo::TYPE_UPDATE      => ['{fan_name}', '{fan_email}', '{fan_url}', '{fan_country}', '{listing_name}', '{listing_subject}', '{listing_url}', '{site_name}', '{owner_name}', '{owner_email}'],
    EmailTemplateRepo::TYPE_REMOVE      => ['{fan_name}', '{fan_email}', '{listing_name}', '{listing_subject}', '{listing_url}', '{site_name}', '{owner_name}', '{owner_email}'],
    EmailTemplateRepo::TYPE_RESET       => ['{fan_name}', '{fan_email}', '{reset_url}', '{listing_name}', '{listing_subject}', '{listing_url}', '{site_name}', '{owner_name}', '{owner_email}'],
    EmailTemplateRepo::TYPE_NOTIFY_JOIN => ['{fan_name}', '{fan_email}', '{fan_url}', '{fan_country}', '{listing_name}', '{listing_subject}', '{listing_url}', '{site_name}', '{owner_name}', '{owner_email}'],
];

$isGlobal     = ($scope === 'global');
$resetConfirm = $isGlobal
    ? 'Reset this template to the built-in default? Your edits will be lost.'
    : 'Reset this template to the global version? Your per-listing customisation will be removed.';

$firstType = EmailTemplateRepo::TYPES[0];
?>

<div class="neb-tabs" style="margin-bottom:1.5rem">
  <a href="<?= $b ?>/admin/email-templates"      class="neb-tab-btn active">Email Templates</a>
  <a href="<?= $b ?>/admin/emails"               class="neb-tab-btn">Compose Email</a>
  <a href="<?= $b ?>/admin/new-email-template"   class="neb-tab-btn">New Template</a>
  <a href="<?= $b ?>/admin/emails/smtp-settings" class="neb-tab-btn">Email/SMTP Settings</a>
</div>

<?php if ($success !== ''): ?>
  <div class="alert alert-success"><?= View::e($success) ?></div>
<?php endif; ?>
<?php if ($error !== ''): ?>
  <div class="alert alert-error"><?= View::e($error) ?></div>
<?php endif; ?>

<!-- ── Scope selector ─────────────────────────────────────────────── -->
<div style="display:flex;align-items:center;gap:.75rem;margin-bottom:1.5rem;flex-wrap:wrap">
  <label style="font-size:.72rem;font-weight:600;color:var(--text-muted);text-transform:uppercase;letter-spacing:.04em;white-space:nowrap">
    Scope
  </label>
  <form method="GET" action="<?= $b ?>/admin/email-templates" style="display:contents">
    <div class="select-wrap">
      <select name="listing" class="form-select js-autosubmit" style="min-width:220px">
        <option value="global"<?= $isGlobal ? ' selected' : '' ?>>
          Global (default for all listings)
        </option>
        <?php if (!empty($all_listings)): ?>
          <option disabled>─────────────────</option>
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
    <span style="font-size:.8rem;color:var(--text-muted)">
      Cards showing <strong style="color:var(--text)">Customised</strong> override the global template.
      Cards showing <strong style="color:var(--text)">Using global</strong> inherit from Global scope.
    </span>
  <?php else: ?>
    <span style="font-size:.8rem;color:var(--text-muted)">
      Global templates apply to all listings unless a per-listing override exists.
      Leave a field at its built-in default by clicking <strong style="color:var(--text)">Reset</strong>.
    </span>
  <?php endif; ?>
</div>

<!-- ── Preview token data (read by admin.js) ─────────────────────── -->
<div id="et-preview-data"
     data-site-name="<?= View::e($site_name) ?>"
     data-owner-name="<?= View::e($owner_name) ?>"
     data-owner-email="<?= View::e($owner_email) ?>"
     data-listing-name="<?= View::e($listing?->name ?? 'My Fanlisting') ?>"
     data-listing-subject="<?= View::e($listing?->subject ?? 'Music') ?>"
     data-listing-url="<?= View::e($listing?->url ?? 'https://myfanlisting.com') ?>"
     data-is-global="<?= $isGlobal ? '1' : '0' ?>"
     style="display:none"></div>

<!-- ── Left-nav + panels layout ──────────────────────────────────── -->
<form id="et-save-form" method="POST" action="<?= $b ?>/admin/email-templates/save">
  <input type="hidden" name="_csrf"  value="<?= View::e($csrf_token) ?>">
  <input type="hidden" name="scope"  value="<?= View::e($scope) ?>">

  <div class="et-layout">

    <!-- Left nav -->
    <nav class="et-nav">
      <?php foreach (EmailTemplateRepo::TYPES as $i => $type): ?>
        <button type="button"
                class="et-nav-item<?= $i === 0 ? ' active' : '' ?>"
                data-et-tab="<?= View::e($type) ?>">
          <?= View::e($navLabels[$type]) ?>
          <?php if (!$isGlobal && isset($cards[$type]) && $cards[$type]['isCustomised']): ?>
            <span class="neb-badge badge-info" style="margin-left:auto;font-size:.65rem;padding:.1rem .35rem">•</span>
          <?php endif; ?>
        </button>
      <?php endforeach; ?>
    </nav>

    <!-- Template panels -->
    <div class="et-content">
      <?php foreach (EmailTemplateRepo::TYPES as $i => $type):
        $card = $cards[$type];
      ?>
      <div class="et-panel<?= $i === 0 ? ' active' : '' ?>"
           id="et-tab-<?= View::e($type) ?>">

        <div class="neb-card<?= !$card['isEnabled'] ? ' et-card-disabled' : '' ?>" style="overflow:hidden">

          <!-- Card header -->
          <div style="padding:.85rem 1.25rem;border-bottom:1px solid var(--border);display:flex;justify-content:space-between;align-items:center;gap:.75rem;flex-wrap:wrap">
            <div style="flex:1;min-width:0">
              <div style="font-weight:700;color:var(--text)"><?= View::e($typeLabels[$type]) ?></div>
              <div style="font-size:.8rem;color:var(--text-dim);margin-top:.2rem"><?= View::e($typeHints[$type]) ?></div>
            </div>
            <div style="display:flex;align-items:center;gap:.6rem;flex-shrink:0">

              <?php if (!$isGlobal): ?>
                <?php if ($card['isCustomised']): ?>
                  <span class="neb-badge badge-info">Customised</span>
                <?php else: ?>
                  <span class="neb-badge badge-inactive">Using global</span>
                <?php endif; ?>
              <?php endif; ?>

              <!-- Preview toggle -->
              <button type="button" class="btn btn-ghost btn-sm et-preview-toggle"
                      data-type="<?= View::e($type) ?>">Preview</button>

              <!-- Reset button -->
              <button type="submit"
                      form="et-reset-<?= View::e($type) ?>"
                      class="btn btn-ghost btn-sm"
                      style="color:var(--text-dim)">
                <?= $isGlobal ? 'Reset to default' : 'Reset to global' ?>
              </button>

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

          <!-- Disabled warning banner (shown when toggle is off) -->
          <div class="et-disabled-banner"<?= $card['isEnabled'] ? ' style="display:none"' : '' ?>>
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
            This template is <strong>disabled</strong> — no email will be sent for this event.
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
            <div class="form-group" style="margin-bottom:.875rem">
              <label class="form-label" for="et-body-<?= View::e($type) ?>">Body</label>
              <textarea class="form-textarea et-body"
                        id="et-body-<?= View::e($type) ?>"
                        name="email_body_<?= View::e($type) ?>"
                        rows="9"
                        style="font-family:'DM Mono',monospace;font-size:.8125rem"
                        data-type="<?= View::e($type) ?>"><?= View::e($card['fillBody']) ?></textarea>
            </div>

            <!-- Live preview panel (hidden until toggled) -->
            <div class="et-preview-panel" id="et-preview-<?= View::e($type) ?>"
                 style="display:none;margin-bottom:1rem;border:1px solid var(--border);border-radius:6px;overflow:hidden">
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

            <div style="display:flex;justify-content:flex-end;margin-bottom:1.5rem">
              <button type="submit" class="btn btn-primary">Save Template</button>
            </div>

            <!-- Token table — below save button -->
            <div style="border-top:1px solid var(--border);padding-top:1.25rem">
              <p style="font-size:.72rem;font-weight:600;color:var(--text-muted);text-transform:uppercase;letter-spacing:.04em;margin:0 0 .5rem">Available Tokens</p>
              <table class="et-token-table">
                <thead>
                  <tr>
                    <th>Token</th>
                    <th>Description</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($typeTokens[$type] as $token): ?>
                    <tr>
                      <td><code><?= View::e($token) ?></code></td>
                      <td><?= View::e($allTokenDesc[$token] ?? '') ?></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          </div>

        </div><!-- /.neb-card -->
      </div><!-- /.et-panel -->
      <?php endforeach; ?>
    </div><!-- /.et-content -->

  </div><!-- /.et-layout -->
</form>

<!-- ── Reset forms (one per type, out-of-flow; targeted by form= attribute above) ── -->
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
