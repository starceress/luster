<?php declare(strict_types=1);
use Luster\Core\View;

/**
 * @var \Luster\Models\Listing  $listing
 * @var string[]                $selected_emails
 * @var array<string,string>    $member_names      email → name
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
$flName     = View::e($listing->subject ?? '');
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
        <p class="form-hint">Selecting fills the subject and message fields. You can edit before sending.</p>
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
            <tr class="tok-row" tabindex="0" role="button" title="Fan's display name"      aria-label="Insert {fan_name}. Fan's display name"          data-textarea="es-body" data-token="{fan_name}">       <td class="token-cell" data-desc="Fan's display name">      <code>{fan_name}</code></td></tr>
            <tr class="tok-row" tabindex="0" role="button" title="Fan's email address"     aria-label="Insert {fan_email}. Fan's email address"         data-textarea="es-body" data-token="{fan_email}">      <td class="token-cell" data-desc="Fan's email address">     <code>{fan_email}</code></td></tr>
            <tr class="tok-row" tabindex="0" role="button" title="Fan's website URL"       aria-label="Insert {fan_url}. Fan's website URL"             data-textarea="es-body" data-token="{fan_url}">        <td class="token-cell" data-desc="Fan's website URL">       <code>{fan_url}</code></td></tr>
            <tr class="tok-row" tabindex="0" role="button" title="Fan's country"           aria-label="Insert {fan_country}. Fan's country"             data-textarea="es-body" data-token="{fan_country}">    <td class="token-cell" data-desc="Fan's country">           <code>{fan_country}</code></td></tr>
            <tr class="tok-row" tabindex="0" role="button" title="Name of the listing"     aria-label="Insert {listing_title}. Name of the listing"     data-textarea="es-body" data-token="{listing_title}">   <td class="token-cell" data-desc="Name of the listing">     <code>{listing_title}</code></td></tr>
            <tr class="tok-row" tabindex="0" role="button" title="Subject or topic"        aria-label="Insert {listing_subject}. Subject or topic"      data-textarea="es-body" data-token="{listing_subject}"><td class="token-cell" data-desc="Subject or topic">        <code>{listing_subject}</code></td></tr>
            <tr class="tok-row" tabindex="0" role="button" title="URL of the listing"      aria-label="Insert {listing_url}. URL of the listing"        data-textarea="es-body" data-token="{listing_url}">    <td class="token-cell" data-desc="URL of the listing">      <code>{listing_url}</code></td></tr>
            <tr class="tok-row" tabindex="0" role="button" title="Collective or site name" aria-label="Insert {site_name}. Collective or site name"     data-textarea="es-body" data-token="{site_name}">      <td class="token-cell" data-desc="Collective or site name"> <code>{site_name}</code></td></tr>
            <tr class="tok-row" tabindex="0" role="button" title="Owner's display name"   aria-label="Insert {owner_name}. Owner's display name"       data-textarea="es-body" data-token="{owner_name}">     <td class="token-cell" data-desc="Owner's display name">    <code>{owner_name}</code></td></tr>
            <tr class="tok-row" tabindex="0" role="button" title="Owner's email address"  aria-label="Insert {owner_email}. Owner's email address"     data-textarea="es-body" data-token="{owner_email}">    <td class="token-cell" data-desc="Owner's email address">   <code>{owner_email}</code></td></tr>
          </tbody>
        </table>
      </div>
    </details>
  </div>

<!-- ──── MAIN COMPOSE AREA ──────────────────────────────────────────── -->
  <div class="card card--gradient">
    <form method="POST" action="<?= $b ?>/admin/members/<?= $listingId ?>/email-selected/send"
          id="es-form">
      <input type="hidden" name="_csrf" value="<?= View::e($csrf_token) ?>">
      <div id="es-email-inputs">
        <?php foreach ($selected_emails as $email): ?>
          <input type="hidden" name="emails[]" value="<?= View::e($email) ?>">
        <?php endforeach; ?>
      </div>

      <div class="panel-header">
        <div class="panel-header-icon panel-header-icon--filled"><?= View::icon('email') ?></div>
        <div class="panel-header-title">Email Selected Members</div>
        <span class="badge badge-info2 panel-header-badge">
          <?= $flName ?> &middot; <span id="es-count-label"><?= $count ?> member<?= $count !== 1 ? 's' : '' ?></span>
        </span>
      </div>

      <div class="composer-block">
        <div class="composer-row">
          <span class="composer-label">From</span>
          <div>
            <?php if (($from_name ?? '') !== ''): ?>
              <span class="composer-name"><?= View::e($from_name) ?></span>
            <?php endif; ?>
            <span class="composer-email">&lt;<?= View::e($from_email ?? '') ?>&gt;</span>
          </div>
        </div>
        <div class="composer-row">
          <span class="composer-label">To</span>
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
          <span class="composer-label">Subject</span>
          <div class="flex-fill">
            <input class="subj-input" type="text" name="subject" id="es-subject"
                   maxlength="255" required placeholder="Enter subject&hellip;"
                   value="<?= $oldSubject ?>">
            <p class="form-hint">Tokens work in the subject line too.</p>
          </div>
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
        <button type="button" class="btn btn-preview" id="es-preview-btn"><?= View::icon('eye-on') ?> Preview</button>
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
          <span class="composer-label">From</span>
          <div>
            <span class="composer-name" id="es-preview-from-name"></span>
            <span class="composer-email" id="es-preview-from-email"></span>
          </div>
        </div>
        <div class="composer-row">
          <span class="composer-label">To</span>
          <span class="composer-val" id="es-preview-to"></span>
        </div>
        <div class="composer-row">
          <span class="composer-label">Subject</span>
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
     data-listing-name="<?= View::e($listing->title ?? '') ?>"
     data-listing-subject="<?= View::e($listing->subject ?? '') ?>"
     data-listing-url="<?= View::e($listing->url ?? '') ?>"
     data-site-name="<?= View::e($site_name ?? '') ?>"
     data-owner-name="<?= View::e($owner_name ?? '') ?>"
     data-owner-email="<?= View::e($owner_email ?? '') ?>"
     class="is-hidden"></div>