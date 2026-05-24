<?php declare(strict_types=1);
use Luster\Core\View;

/**
 * @var \Luster\Models\Listing  $listing
 * @var string[]                $selected_emails
 * @var array<string,string>    $member_names      email â†’ name
 * @var object[]                $saved_emails
 * @var string                  $from_name
 * @var string                  $from_email
 * @var string                  $owner_name
 * @var string                  $owner_email
 * @var string                  $site_name
 * @var string                  $error
 * @var string                  $success
 * @var array                   $old               ['subject'=>..., 'body'=>...]
 * @var string                  $base_url
 * @var string                  $csrf_token
 */

$b          = rtrim(View::e($base_url ?? ''), '/');
$listingId  = (int) ($listing->id ?? 0);
$count      = count($selected_emails);
$oldSubject = View::e($old['subject'] ?? '');
$oldBody    = htmlspecialchars($old['body'] ?? '', ENT_QUOTES, 'UTF-8');
$flName     = View::e($listing->name ?? '');
?>

<?php if (($error ?? '') !== ''): ?>
  <div class="alert alert-error"><?= View::e($error) ?></div>
<?php endif; ?>
<?php if (($success ?? '') !== ''): ?>
  <div class="alert alert-success"><?= View::e($success) ?></div>
<?php endif; ?>

<div class="composer-grid">
  <div class="composer-side">

    <?php if (!empty($saved_emails)): ?>
    <div class="card">
      <div class="card-head">Load Template</div>
      <div class="card-body">
        <div class="select-wrap">
          <select id="es-tpl-select" >
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
        <form method="POST"
              action="<?= $b ?>/admin/members/<?= $listingId ?>/email-selected/save-template"
              id="es-save-tpl-form">
          <input type="hidden" name="_csrf"       value="<?= View::e($csrf_token) ?>">
          <input type="hidden" name="tpl_subject" id="es-save-subject">
          <input type="hidden" name="tpl_body"    id="es-save-body">
          <div id="es-save-emails"></div>
          <input type="text" name="tpl_name" id="es-save-name"
                 class="form" placeholder="Template name&hellip;" maxlength="150">
          <button type="submit" class="btn btn-secondary btn-sm btn-block-center">Save Template</button>
        </form>
      </div>
    </div>

    <details class="card chevron" open>
      <summary class="card-head">Available Tokens</summary>
      <div class="card-body">
        <table class="et-token-table">
          <tbody>
            <tr class="tok-row" data-textarea="es-body" data-token="{fan_name}"        title="Click to insert"><td><code>{fan_name}</code></td><td>Fan's display name</td></tr>
            <tr class="tok-row" data-textarea="es-body" data-token="{fan_email}"       title="Click to insert"><td><code>{fan_email}</code></td><td>Fan's email address</td></tr>
            <tr class="tok-row" data-textarea="es-body" data-token="{fan_url}"         title="Click to insert"><td><code>{fan_url}</code></td><td>Fan's website URL</td></tr>
            <tr class="tok-row" data-textarea="es-body" data-token="{fan_country}"     title="Click to insert"><td><code>{fan_country}</code></td><td>Fan's country</td></tr>
            <tr class="tok-row" data-textarea="es-body" data-token="{listing_name}"    title="Click to insert"><td><code>{listing_name}</code></td><td>Name of the listing</td></tr>
            <tr class="tok-row" data-textarea="es-body" data-token="{listing_subject}" title="Click to insert"><td><code>{listing_subject}</code></td><td>Subject or topic</td></tr>
            <tr class="tok-row" data-textarea="es-body" data-token="{listing_url}"     title="Click to insert"><td><code>{listing_url}</code></td><td>URL of the listing</td></tr>
            <tr class="tok-row" data-textarea="es-body" data-token="{site_name}"       title="Click to insert"><td><code>{site_name}</code></td><td>Collective or site name</td></tr>
            <tr class="tok-row" data-textarea="es-body" data-token="{owner_name}"      title="Click to insert"><td><code>{owner_name}</code></td><td>Owner's display name</td></tr>
            <tr class="tok-row" data-textarea="es-body" data-token="{owner_email}"     title="Click to insert"><td><code>{owner_email}</code></td><td>Owner's email address</td></tr>
          </tbody>
        </table>
      </div>
    </details>
  </div>

<!-- ──── MAIN COMPOSE AREA ──────────────────────────────────────────── -->
  <div class="gradient-card">
    <form method="POST" action="<?= $b ?>/admin/members/<?= $listingId ?>/email-selected/send"
          id="es-form">
      <input type="hidden" name="_csrf" value="<?= View::e($csrf_token) ?>">
      <div id="es-email-inputs">
        <?php foreach ($selected_emails as $email): ?>
          <input type="hidden" name="emails[]" value="<?= View::e($email) ?>">
        <?php endforeach; ?>
      </div>

      <div class="pc-head">
        <div class="pc-head-icon pc-head-icon-bg"><?= View::icon('email') ?></div>
        <div class="pc-head-title">Email Selected Members</div>
        <span class="badge badge-info-pink pc-head-badge">
          <?= $flName ?> &middot; <span id="es-count-label"><?= $count ?> member<?= $count !== 1 ? 's' : '' ?></span>
        </span>
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
          <div id="es-chip" class="es-chip">
            <?php foreach ($selected_emails as $email):
              $name = $member_names[$email] ?? '';
            ?>
              <span class="es-chip-item" data-email="<?= View::e($email) ?>">
                <?php if ($name !== ''): ?>
                  <strong><?= View::e($name) ?></strong>
                  <span>&lt;<?= View::e($email) ?>&gt;</span>
                <?php else: ?>
                  <span><?= View::e($email) ?></span>
                <?php endif; ?>
                <button type="button" class="es-chip-remove"
                        aria-label="Remove <?= View::e($email) ?>"
                        title="Remove recipient">&times;</button>
              </span>
            <?php endforeach; ?>
          </div>
        </div>
        <div class="composer-row">
          <span class="composer-lbl">Subject</span>
          <input class="subj-input" type="text" name="subject" id="es-subject"
                 maxlength="255" required placeholder="Enter subject&hellip;"
                 value="<?= $oldSubject ?>">
        </div>
      </div>

      <div>
        <textarea class="email" id="es-body" name="body"
                  maxlength="50000" required
                  placeholder="Write your message&hellip;"><?= $oldBody ?></textarea>
      </div>

      <div class="composer-footer">
        <button type="button" class="btn btn-primary" id="es-send-btn">
          <?= View::icon('send') ?>
          Send to <span id="es-send-count"><?= $count ?></span>
          member<?= $count !== 1 ? 's' : '' ?> &rarr;
        </button>
        <button type="button" class="btn btn-preview" id="es-preview-btn">Preview</button>
        <button type="button" class="btn btn-ghost js-form-reset">Clear</button>
        <div class="composer-footer-spacer"></div>
        <a href="<?= $b ?>/admin/members?listing_id=<?= (int) $listingId ?>&status=all" class="btn btn-danger">Cancel</a>
      </div>
    </form>
  </div>
</div>

<!-- ──── PREVIEW / CONFIRM MODAL ────────────────────────────────────── -->
<div class="modal-backdrop is-hidden" id="es-preview-modal">
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
            <span class="composer-name" id="es-preview-from-name"></span>
            <span class="composer-email" id="es-preview-from-email"></span>
          </div>
        </div>
        <div class="composer-row">
          <span class="composer-lbl">To</span>
          <span class="composer-val" id="es-preview-to"></span>
        </div>
        <div class="composer-row">
          <span class="composer-lbl">Subject</span>
          <span class="composer-val" id="es-modal-subject"></span>
        </div>
      </div>
      <div id="es-modal-body" class="body-area"></div>
    </div>
    <div id="es-footer-confirm" class="is-hidden">
      <div class="me-confirm-warning"><?= View::icon('caution') ?>This will send to all listed recipients. This action cannot be undone.</div>
      <div class="modal-footer">
        <button type="button" class="btn btn-primary" id="es-modal-send">
          <?= View::icon('send') ?> Confirm &amp; Send
        </button>
        <button type="button" class="btn btn-quaternary" id="es-modal-cancel">Cancel</button>
      </div>
    </div>
    <div class="modal-footer" id="es-footer-preview">
      <button type="button" class="btn btn-quaternary" id="es-modal-close-preview">Close</button>
    </div>
  </div>
</div>

<div id="es-preview-data"
     data-first-name="<?= View::e(reset($member_names) ?: '') ?>"
     data-first-email="<?= View::e(array_key_first($member_names) ?? '') ?>"
     data-listing-name="<?= View::e($listing->name ?? '') ?>"
     data-listing-subject="<?= View::e($listing->subject ?? '') ?>"
     data-listing-url="<?= View::e($listing->url ?? '') ?>"
     data-site-name="<?= View::e($site_name ?? '') ?>"
     data-owner-name="<?= View::e($owner_name ?? '') ?>"
     data-owner-email="<?= View::e($owner_email ?? '') ?>"
     class="is-hidden"></div>