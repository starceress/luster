<?php

declare(strict_types=1);

use Luster\Core\View;
use Luster\Repositories\EmailTemplateRepo;

/**
 * @var \Luster\Models\Fanlisting      $fanlisting
 * @var array<string, object|null>     $templates   Keyed by type; null = no custom template saved
 * @var string                         $success
 * @var string                         $error
 * @var string                         $base_url
 * @var string                         $csrf_token
 */

$b = rtrim(View::e($base_url), '/');

$typeLabels = [
    EmailTemplateRepo::TYPE_JOIN    => 'Join Confirmation',
    EmailTemplateRepo::TYPE_APPROVE => 'Membership Approved',
    EmailTemplateRepo::TYPE_UPDATE  => 'Update Confirmation',
    EmailTemplateRepo::TYPE_REMOVE  => 'Removal Confirmation',
];

$typeHints = [
    EmailTemplateRepo::TYPE_JOIN    => 'Sent to the member after they successfully join. Leave blank to use the built-in default.',
    EmailTemplateRepo::TYPE_APPROVE => 'Sent when an admin approves a pending membership. Leave blank to use the built-in default.',
    EmailTemplateRepo::TYPE_UPDATE  => 'Sent to the member after they update their details. Leave blank to use the built-in default.',
    EmailTemplateRepo::TYPE_REMOVE  => 'Sent to the member after their membership is removed. Leave blank to use the built-in default.',
];

$tokenHints = [
    EmailTemplateRepo::TYPE_JOIN    => '$fan_name$ &bull; $fan_email$ &bull; $fan_url$ &bull; $fan_country$ &bull; $fan_password$ &bull; $fanlisting_name$ &bull; $fanlisting_url$ &bull; $app_name$',
    EmailTemplateRepo::TYPE_APPROVE => '$fan_name$ &bull; $fan_email$ &bull; $fanlisting_name$ &bull; $fanlisting_url$ &bull; $app_name$',
    EmailTemplateRepo::TYPE_UPDATE  => '$fan_name$ &bull; $fan_email$ &bull; $fan_url$ &bull; $fan_country$ &bull; $fanlisting_name$ &bull; $fanlisting_url$ &bull; $app_name$',
    EmailTemplateRepo::TYPE_REMOVE  => '$fan_name$ &bull; $fan_email$ &bull; $fanlisting_name$ &bull; $fanlisting_url$ &bull; $app_name$',
];
?>

<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1rem">
  <a href="<?= $b ?>/admin/fanlistings/<?= (int) $fanlisting->id ?>/edit" class="neb-link">
    &larr; Back to <?= View::e($fanlisting->name) ?>
  </a>
</div>

<?php if ($success !== ''): ?>
  <div class="alert alert-success"><?= View::e($success) ?></div>
<?php endif; ?>
<?php if ($error !== ''): ?>
  <div class="alert alert-error"><?= View::e($error) ?></div>
<?php endif; ?>

<div class="neb-card" style="padding:1rem 1.5rem;margin-bottom:1.25rem">
  <p style="font-size:.85rem;color:var(--text-dim);margin:0">
    Customise the subject and body of each transactional email sent to members.
    Leave the subject blank to use the built-in default for that email type.
    Tokens are replaced with live data when the email is sent.
  </p>
</div>

<form method="POST" action="<?= $b ?>/admin/fanlistings/<?= (int) $fanlisting->id ?>/email-templates">
  <input type="hidden" name="_csrf" value="<?= View::e($csrf_token) ?>">

  <?php foreach (EmailTemplateRepo::TYPES as $type): ?>
  <?php
    $tpl            = $templates[$type] ?? null;
    $savedSubject   = $tpl?->subject ?? '';
    $savedBody      = $tpl?->body    ?? '';
    $savedEnabled   = $tpl === null || $tpl->enabled; // default to enabled
    $defaultSubject = EmailTemplateRepo::defaultSubject($type);
    $defaultBody    = EmailTemplateRepo::defaultBody($type);
  ?>

  <div class="neb-card" style="margin-bottom:1.25rem;overflow:hidden">
    <!-- Header -->
    <div style="padding:.85rem 1.25rem;border-bottom:1px solid var(--border);display:flex;justify-content:space-between;align-items:center">
      <div>
        <div style="font-weight:700;color:var(--text)"><?= View::e($typeLabels[$type]) ?></div>
        <div style="font-size:.8rem;color:var(--text-dim);margin-top:.2rem"><?= View::e($typeHints[$type]) ?></div>
      </div>
      <label style="display:flex;align-items:center;gap:.4rem;font-size:.85rem;cursor:pointer;white-space:nowrap">
        <input type="checkbox" name="email_enabled_<?= View::e($type) ?>" value="1"
               <?= $savedEnabled ? 'checked' : '' ?>>
        Enabled
      </label>
    </div>

    <div style="padding:1.25rem">
      <!-- Token reference -->
      <p class="form-hint" style="font-family:'DM Mono',monospace;font-size:.78rem;line-height:1.9;margin-bottom:1rem">
        <?= $tokenHints[$type] ?>
      </p>

      <!-- Subject -->
      <div class="form-group" style="margin-bottom:1rem">
        <label class="form-label" for="email-subject-<?= View::e($type) ?>">
          Subject <span style="font-weight:400;color:var(--text-dim)">(leave blank for default)</span>
        </label>
        <input class="form-input" type="text"
               id="email-subject-<?= View::e($type) ?>"
               name="email_subject_<?= View::e($type) ?>"
               value="<?= View::e($savedSubject) ?>"
               maxlength="255"
               placeholder="<?= View::e($defaultSubject) ?>">
      </div>

      <!-- Body -->
      <div class="form-group">
        <label class="form-label" for="email-body-<?= View::e($type) ?>">
          Body <span style="font-weight:400;color:var(--text-dim)">(leave blank for default)</span>
        </label>
        <textarea class="form-textarea" rows="8"
                  id="email-body-<?= View::e($type) ?>"
                  name="email_body_<?= View::e($type) ?>"
                  style="font-family:'DM Mono',monospace;font-size:.8125rem"
                  placeholder="<?= View::e($defaultBody) ?>"><?= View::e($savedBody) ?></textarea>
        <p class="form-hint" style="margin-top:.35rem">Default preview shown as placeholder above.</p>
      </div>
    </div>
  </div>

  <?php endforeach; ?>

  <div style="display:flex;justify-content:flex-end;margin-bottom:2rem">
    <button type="submit" class="btn btn-primary">Save Email Templates</button>
  </div>
</form>
