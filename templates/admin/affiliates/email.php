<?php
/**
 * Affiliate bulk email compose form.
 *
 * Variables:
 *   $listings       Listing[]
 *   $scope_counts   array<string, int>  'all'|'collective'|'{id}' → count
 *   $selected_scope string              pre-selected scope value
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

$b          = rtrim(View::e($base_url), '/');
$oldSubject = View::e($old['subject'] ?? '');
$oldBody    = htmlspecialchars($old['body'] ?? '', ENT_QUOTES, 'UTF-8');
$oldScope   = $old['scope'] ?? $selected_scope ?? 'all';
$totalCount = (int) ($scope_counts['all'] ?? 0);
?>

<div class="page-topbar">
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
        <p class="form-hint">Loading a template will replace your current subject and message.</p>
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
            <tr class="tok-row" data-textarea="es-body" data-token="{aff_name}"    title="Click to insert"><td><code>{aff_name}</code></td><td>Affiliate site name</td></tr>
            <tr class="tok-row" data-textarea="es-body" data-token="{aff_email}"   title="Click to insert"><td><code>{aff_email}</code></td><td>Affiliate email address</td></tr>
            <tr class="tok-row" data-textarea="es-body" data-token="{aff_url}"     title="Click to insert"><td><code>{aff_url}</code></td><td>Affiliate site URL</td></tr>
            <tr class="tok-row" data-textarea="es-body" data-token="{listing_title}"title="Click to insert"><td><code>{listing_title}</code></td><td>Linked listing name (or site name)</td></tr>
            <tr class="tok-row" data-textarea="es-body" data-token="{listing_url}" title="Click to insert"><td><code>{listing_url}</code></td><td>Linked listing URL (or site URL)</td></tr>
            <tr class="tok-row" data-textarea="es-body" data-token="{site_name}"   title="Click to insert"><td><code>{site_name}</code></td><td>Collective or site name</td></tr>
            <tr class="tok-row" data-textarea="es-body" data-token="{owner_name}"  title="Click to insert"><td><code>{owner_name}</code></td><td>Owner's display name</td></tr>
            <tr class="tok-row" data-textarea="es-body" data-token="{owner_email}" title="Click to insert"><td><code>{owner_email}</code></td><td>Owner's email address</td></tr>
          </tbody>
        </table>
      </div>
    </details>

  </div><!-- /.composer-side -->

  <div class="gradient-card">
    <form method="POST" action="<?= $b ?>/admin/affiliates/email" id="es-form">
      <input type="hidden" name="_csrf" value="<?= View::e($csrf_token) ?>">

      <div class="pc-head">
        <div class="pc-head-icon pc-head-icon-bg"><?= View::icon('email') ?></div>
        <div class="pc-head-title">Email Affiliates</div>
        <span class="badge badge-info-pink pc-head-badge" id="es-count-badge">
          <span id="es-count-label"><?= (int) ($scope_counts[$oldScope] ?? $totalCount) ?></span>
          affiliate<?= ((int) ($scope_counts[$oldScope] ?? $totalCount)) !== 1 ? 's' : '' ?>
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
          Send to <span id="es-send-count"><?= (int) ($scope_counts[$oldScope] ?? $totalCount) ?></span>
          affiliate<?= ((int) ($scope_counts[$oldScope] ?? $totalCount)) !== 1 ? 's' : '' ?> &rarr;
        </button>
        <button type="button" class="btn btn-preview" id="es-preview-btn">Preview</button>
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

<div id="es-preview-data"
     data-scope-counts="<?= htmlspecialchars(json_encode($scope_counts, JSON_THROW_ON_ERROR), ENT_QUOTES, 'UTF-8') ?>"
     data-from-name="<?= View::e($from_name) ?>"
     data-from-email="<?= View::e($from_email) ?>"
     data-site-name="<?= View::e($site_name) ?>"
     data-site-url="<?= View::e($site_url) ?>"
     data-owner-name="<?= View::e($owner_name) ?>"
     data-owner-email="<?= View::e($owner_email) ?>"
     class="is-hidden"></div>
