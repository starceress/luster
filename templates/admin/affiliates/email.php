<?php
/**
 * Affiliate bulk email compose form.
 *
 * Variables:
 *   $listings       Listing[]
 *   $scope_counts   array<string, int>  'all'|'collective'|'{id}' → count (empty when selected_only)
 *   $selected_scope string              pre-selected scope value
 *   $selected_only  bool                true when opened from bulk "Email Selected"
 *   $id_recip_map   array<int, array{id: int, name: string, email: string, url: string, listing_id: ?int}>
 *                   ID-keyed map; populated only when selected_only = true
 *   $aff_ids        int[]               IDs of selected affiliates (empty when not selected_only)
 *   $saved_emails   object[]
 *   $from_name      string
 *   $from_email     string
 *   $owner_name     string
 *   $owner_email    string
 *   $site_name      string
 *   $site_url       string
 *   $old            array{subject: string, body: string, scope: string}
 *   $base_url       string
 *   $csrf_token     string
 *   $error          string
 *   $success        string
 */

declare(strict_types=1);

use Luster\Core\View;

$b            = rtrim(View::e($base_url), '/');
$oldSubject   = View::e($old['subject'] ?? '');
$oldBody      = htmlspecialchars($old['body'] ?? '', ENT_QUOTES, 'UTF-8');
$oldScope     = $old['scope'] ?? $selected_scope ?? 'all';
$selectedOnly = (bool) ($selected_only ?? false);
$idRecipMap   = (array) ($id_recip_map ?? []);
$affIds       = (array) ($aff_ids ?? []);
$totalCount   = $selectedOnly ? count($idRecipMap) : (int) ($scope_counts['all'] ?? 0);
$displayCount = $selectedOnly ? $totalCount : (int) ($scope_counts[$oldScope] ?? $totalCount);
?>

<div class="toolbar">
  <a href="<?= $b ?>/admin/affiliates" class="link">&larr; Back to Affiliates</a>
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
          <select id="es-tpl-select">
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
        <form method="POST" action="<?= $b ?>/admin/new-email-template/save" id="es-save-tpl-form">
          <input type="hidden" name="_csrf"       value="<?= View::e($csrf_token) ?>">
          <input type="hidden" name="referer"     value="<?= View::e($_SERVER['REQUEST_URI'] ?? '') ?>">
          <input type="hidden" name="tpl_subject" id="es-save-subject">
          <input type="hidden" name="tpl_body"    id="es-save-body">
          <div id="es-save-emails"></div>
          <?php if ($selectedOnly): ?>
            <?php foreach ($affIds as $id): ?>
              <input type="hidden" name="aff_ids[]" value="<?= (int) $id ?>">
            <?php endforeach; ?>
          <?php endif; ?>
          <input type="text" name="tpl_name" id="es-save-name"
                 class="form" placeholder="Template name&hellip;" maxlength="150">
          <p class="form-hint">This saves the message as a reusable starting point. It does not send anything.</p>
          <button type="submit" class="btn btn-secondary btn-sm btn-block-center">Save Template</button>
        </form>
      </div>
    </div>

      <div class="filter-panel is-open">
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
    </div></div>

    <p data-token-search-empty class="form-hint token-search-empty" hidden>No tokens found.</p>

    <details class="card chevron" data-token-card>
      <summary class="chevron-head">Available Tokens</summary>
      <div class="chevron-body">
        <table class="et-token-table et-token-table--compact">
          <tbody>
            <tr class="tok-row" tabindex="0" role="button" title="Affiliate site name"               aria-label="Insert {aff_name}. Affiliate site name"               data-textarea="es-body" data-token="{aff_name}">    <td class="token-cell" data-desc="Affiliate site name">               <code>{aff_name}</code></td></tr>
            <tr class="tok-row" tabindex="0" role="button" title="Affiliate email address"            aria-label="Insert {aff_email}. Affiliate email address"            data-textarea="es-body" data-token="{aff_email}">   <td class="token-cell" data-desc="Affiliate email address">            <code>{aff_email}</code></td></tr>
            <tr class="tok-row" tabindex="0" role="button" title="Affiliate site URL"                 aria-label="Insert {aff_url}. Affiliate site URL"                   data-textarea="es-body" data-token="{aff_url}">     <td class="token-cell" data-desc="Affiliate site URL">                 <code>{aff_url}</code></td></tr>
            <tr class="tok-row" tabindex="0" role="button" title="Linked listing name (or site name)" aria-label="Insert {listing_title}. Linked listing name (or site name)" data-textarea="es-body" data-token="{listing_title}"><td class="token-cell" data-desc="Linked listing name (or site name)"><code>{listing_title}</code></td></tr>
            <tr class="tok-row" tabindex="0" role="button" title="Linked listing URL (or site URL)"   aria-label="Insert {listing_url}. Linked listing URL (or site URL)"   data-textarea="es-body" data-token="{listing_url}"> <td class="token-cell" data-desc="Linked listing URL (or site URL)">  <code>{listing_url}</code></td></tr>
            <tr class="tok-row" tabindex="0" role="button" title="Collective or site name"            aria-label="Insert {site_name}. Collective or site name"            data-textarea="es-body" data-token="{site_name}">   <td class="token-cell" data-desc="Collective or site name">            <code>{site_name}</code></td></tr>
            <tr class="tok-row" tabindex="0" role="button" title="Owner's display name"              aria-label="Insert {owner_name}. Owner's display name"             data-textarea="es-body" data-token="{owner_name}">  <td class="token-cell" data-desc="Owner's display name">              <code>{owner_name}</code></td></tr>
            <tr class="tok-row" tabindex="0" role="button" title="Owner's email address"             aria-label="Insert {owner_email}. Owner's email address"            data-textarea="es-body" data-token="{owner_email}"> <td class="token-cell" data-desc="Owner's email address">             <code>{owner_email}</code></td></tr>
          </tbody>
        </table>
      </div>
    </details>

  </div><!-- /.composer-side -->

  <div class="gradient-card">
    <form method="POST" action="<?= $b ?>/admin/affiliates/email" id="es-form">
      <input type="hidden" name="_csrf" value="<?= View::e($csrf_token) ?>">
      <?php if ($selectedOnly): ?>
        <input type="hidden" name="scope" value="all">
        <?php foreach ($affIds as $id): ?>
          <input type="hidden" name="aff_ids[]" value="<?= (int) $id ?>">
        <?php endforeach; ?>
      <?php endif; ?>

      <div class="pc-head">
        <div class="pc-head-icon pc-head-icon-bg"><?= View::icon('email') ?></div>
        <div class="pc-head-title"><?= $selectedOnly ? 'Email Selected Affiliates' : 'Email Affiliates' ?></div>
        <span class="badge badge-info2 pc-head-badge" id="es-count-badge">
          <span id="es-count-label"><?= $displayCount ?> affiliate<?= $displayCount !== 1 ? 's' : '' ?></span>
        </span>
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
          <?php if ($selectedOnly): ?>
            <div id="es-chip" class="es-chip">
              <?php foreach ($idRecipMap as $r): ?>
                <span class="es-chip-item">
                  <?php if ($r['name'] !== ''): ?>
                    <strong><?= View::e($r['name']) ?></strong>
                    <span>&lt;<?= View::e($r['email']) ?>&gt;</span>
                  <?php else: ?>
                    <span><?= View::e($r['email']) ?></span>
                  <?php endif; ?>
                </span>
              <?php endforeach; ?>
            </div>
          <?php else: ?>
            <div>
              <div class="select-wrap">
                <select name="scope" id="es-scope">
                  <option value="all"<?= $oldScope === 'all' ? ' selected' : '' ?>>
                    All Affiliates (<?= (int) ($scope_counts['all'] ?? 0) ?>)
                  </option>
                  <option value="collective"<?= $oldScope === 'collective' ? ' selected' : '' ?>>
                    Collective only (<?= (int) ($scope_counts['collective'] ?? 0) ?>)
                  </option>
                  <?php foreach ($listings as $fl): ?>
                    <option value="<?= (int) $fl->id ?>"
                            data-listing-name="<?= View::e($fl->title) ?>"
                            data-listing-url="<?= View::e($fl->url) ?>"
                            <?= $oldScope === (string) $fl->id ? 'selected' : '' ?>>
                      <?= View::e($fl->subject) ?> (<?= (int) ($scope_counts[(string) $fl->id] ?? 0) ?>)
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>
            </div>
          <?php endif; ?>
        </div>
        <div class="composer-row">
          <span class="composer-lbl">Subject</span>
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
          Send to <span id="es-send-count"><?= $displayCount ?></span>
          affiliate<?= $displayCount !== 1 ? 's' : '' ?> &rarr;
        </button>
        <button type="button" class="btn btn-preview" id="es-preview-btn"><?= View::icon('eye-on') ?> Preview</button>
        <button type="button" class="btn btn-ghost js-form-reset">Clear</button>
        <div class="composer-footer-spacer"></div>
        <a href="<?= $b ?>/admin/affiliates" class="btn btn-danger">Cancel</a>
      </div>
    </form>
  </div><!-- /.gradient-card -->

</div><!-- /.composer-grid -->

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

<?php
  $previewFirstRecip = !empty($idRecipMap) ? reset($idRecipMap) : null;
  $previewFirstName  = $previewFirstRecip !== null ? $previewFirstRecip['name']  : '';
  $previewFirstEmail = $previewFirstRecip !== null ? $previewFirstRecip['email'] : '';
?>
<div id="es-preview-data"
     data-context="affiliate"
     data-scope-counts="<?= $selectedOnly ? '{}' : htmlspecialchars(json_encode($scope_counts, JSON_THROW_ON_ERROR), ENT_QUOTES, 'UTF-8') ?>"
     data-first-name="<?= View::e($previewFirstName) ?>"
     data-first-email="<?= View::e($previewFirstEmail) ?>"
     data-from-name="<?= View::e($from_name) ?>"
     data-from-email="<?= View::e($from_email) ?>"
     data-site-name="<?= View::e($site_name) ?>"
     data-site-url="<?= View::e($site_url) ?>"
     data-owner-name="<?= View::e($owner_name) ?>"
     data-owner-email="<?= View::e($owner_email) ?>"
     class="is-hidden"></div>
